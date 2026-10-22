<?php
/**
 * Asking for something besides the password.
 *
 * A password is one secret, and the whole of this plugin's other work is
 * about making it hard to guess. A second factor makes guessing it beside the
 * point: knowing the password is no longer enough to get in.
 *
 * Two ways of proving it, and deliberately one at a time rather than both.
 *
 *   An authenticator application — the six digits that change every half
 *   minute. The strong one: the secret never travels, so there is nothing to
 *   intercept and nothing to phish except the code itself, which is worth
 *   thirty seconds.
 *
 *   A code sent by email. Weaker, and it is worth saying why rather than
 *   letting the checkbox imply otherwise: email is usually also where the
 *   password reset goes, so somebody holding the mailbox holds both factors.
 *   It is here because it needs no application, no phone and no setup, and a
 *   second factor somebody actually uses beats a better one they do not.
 *
 * The order of events on a login is the part that has to be right. WordPress
 * sets the cookie first and announces the login afterwards, so by the time
 * this hears about it the person is already in. The cookie is therefore
 * thrown away again immediately, and only handed back once the code is right.
 *
 * @package WOW\Guard
 */

namespace WOW\Guard\TwoFactor;

use WOW\Guard\Log;
use WOW\Guard\Options;
use WOW\Guard\Secret;
use WOW\Guard\Totp;
use WOW\Guard\Trusted;
use WOW\Guard\Visitor;

defined( 'ABSPATH' ) || exit;

/** Which way somebody proves themselves. */
const METHOD = 'wow_2fa_method';

/** The authenticator secret, encrypted. */
const SECRET = 'wow_2fa_secret';

/** The last half-minute whose code was accepted. */
const USED = 'wow_2fa_used';

/** The codes to fall back on. */
const RECOVERY = 'wow_2fa_recovery';

/** When it was switched on. */
const SINCE = 'wow_2fa_since';

/** The login that is half finished. */
const PENDING = 'wow_2fa_pending_';

/** How long somebody has to finish logging in. */
const PATIENCE = 10 * MINUTE_IN_SECONDS;

/** How many codes may be wrong before the half-finished login is abandoned. */
const TRIES = 5;

/** How long an emailed code lasts. */
const EMAILED_FOR = 10 * MINUTE_IN_SECONDS;

/** How many recovery codes somebody gets. */
const RECOVERY_COUNT = 10;

/**
 * Which way a person proves themselves, or an empty string for not at all.
 *
 * @param int $user_id Whose.
 */
function method( int $user_id ): string {
	$method = (string) get_user_meta( $user_id, METHOD, true );

	return in_array( $method, array( 'totp', 'email' ), true ) ? $method : '';
}

/**
 * Whether a person has a second factor at all.
 *
 * @param int $user_id Whose.
 */
function enabled( int $user_id ): bool {
	return '' !== method( $user_id );
}

/**
 * The authenticator secret of a person, as a person would write it.
 *
 * @param int $user_id Whose.
 */
function secret( int $user_id ): string {
	return Secret\open( (string) get_user_meta( $user_id, SECRET, true ) );
}

/**
 * Switches a second factor on for somebody.
 *
 * @param int    $user_id Whose.
 * @param string $how     'totp' or 'email'.
 * @param string $secret  The authenticator secret, for 'totp'.
 */
function enable( int $user_id, string $how, string $secret = '' ): void {
	update_user_meta( $user_id, METHOD, $how );
	update_user_meta( $user_id, SINCE, time() );

	if ( 'totp' === $how ) {
		update_user_meta( $user_id, SECRET, Secret\seal( $secret ) );
	} else {
		delete_user_meta( $user_id, SECRET );
	}

	delete_user_meta( $user_id, USED );

	// A browser allowed to skip the old second factor must not go on
	// skipping the new one.
	Trusted\forget_all( $user_id );
}

/**
 * Switches it off again.
 *
 * Everything goes, recovery codes included: leaving those behind would mean a
 * person who turns it off and on again still has codes they were shown months
 * ago and have long since lost.
 *
 * @param int $user_id Whose.
 */
function disable( int $user_id ): void {
	foreach ( array( METHOD, SECRET, USED, RECOVERY, SINCE ) as $key ) {
		delete_user_meta( $user_id, $key );
	}

	Trusted\forget_all( $user_id );
}

/**
 * A fresh set of codes to fall back on, returned once and never again.
 *
 * Stored the way a password is stored, because that is what they are: ten
 * things that get somebody in. A database that leaks gives up nothing usable.
 *
 * @param int $user_id Whose.
 * @return string[] The codes, in the only form anybody will ever see them.
 */
function make_recovery_codes( int $user_id ): array {
	$plain  = array();
	$hashed = array();

	for ( $i = 0; $i < RECOVERY_COUNT; $i++ ) {
		// Twelve characters in three groups: long enough not to be guessed,
		// short enough to be read off paper without losing one's place.
		$code = strtolower( implode( '-', str_split( wp_generate_password( 12, false, false ), 4 ) ) );

		$plain[]  = $code;
		$hashed[] = wp_hash_password( $code );
	}

	update_user_meta( $user_id, RECOVERY, $hashed );

	return $plain;
}

/**
 * The codes a person has left, as an array and never as anything else.
 *
 * get_user_meta() answers with an empty string when there is nothing stored,
 * and casting that to an array gives an array containing an empty string —
 * one code that is not a code. It made a freshly cleared account report one
 * recovery code remaining, which is exactly the sort of reassuring lie a
 * security plugin must not tell.
 *
 * @param int $user_id Whose.
 * @return string[] Hashes.
 */
function recovery_codes( int $user_id ): array {
	$stored = get_user_meta( $user_id, RECOVERY, true );

	return is_array( $stored ) ? array_values( array_filter( $stored, 'is_string' ) ) : array();
}

/**
 * How many codes a person has left.
 *
 * @param int $user_id Whose.
 */
function recovery_left( int $user_id ): int {
	return count( recovery_codes( $user_id ) );
}

/**
 * Spends one of them, if it is real.
 *
 * @param int    $user_id Whose.
 * @param string $given   What was typed.
 */
function spend_recovery_code( int $user_id, string $given ): bool {
	$given = strtolower( trim( $given ) );
	$codes = recovery_codes( $user_id );

	foreach ( $codes as $index => $hash ) {
		if ( wp_check_password( $given, (string) $hash ) ) {
			unset( $codes[ $index ] );
			update_user_meta( $user_id, RECOVERY, array_values( $codes ) );

			return true;
		}
	}

	return false;
}

/**
 * Starts a half-finished login and hands back the ticket for it.
 *
 * The ticket is the only thing tying the form to the person who got the
 * password right, and it lives in a transient rather than in the form, so
 * that nothing about who is logging in can be altered on its way back.
 *
 * @param int    $user_id  Who it is.
 * @param bool   $remember Whether they asked to be remembered.
 * @param string $redirect Where they were going.
 * @return string The ticket.
 */
function begin( int $user_id, bool $remember, string $redirect ): string {
	$ticket = bin2hex( random_bytes( 16 ) );

	set_transient(
		PENDING . $ticket,
		array(
			'user'     => $user_id,
			'remember' => $remember,
			'redirect' => $redirect,
			'tries'    => 0,
			'code'     => '',
			'sent'     => 0,
		),
		PATIENCE
	);

	return $ticket;
}

/**
 * What is known about a half-finished login.
 *
 * @param string $ticket The ticket.
 * @return array<string,mixed>|null
 */
function pending( string $ticket ): ?array {
	if ( ! preg_match( '/^[a-f0-9]{32}$/', $ticket ) ) {
		return null;
	}

	$found = get_transient( PENDING . $ticket );

	return is_array( $found ) && ! empty( $found['user'] ) ? $found : null;
}

/**
 * Writes down what is known about one.
 *
 * @param string              $ticket The ticket.
 * @param array<string,mixed> $state  What is known.
 */
function remember( string $ticket, array $state ): void {
	set_transient( PENDING . $ticket, $state, PATIENCE );
}

/**
 * Tears one up.
 *
 * @param string $ticket The ticket.
 */
function forget( string $ticket ): void {
	delete_transient( PENDING . $ticket );
}

/**
 * Sends somebody a code by email.
 *
 * @param int    $user_id Who to.
 * @param string $ticket  Which half-finished login it belongs to.
 * @return bool Whether it went.
 */
function send_code( int $user_id, string $ticket ): bool {
	$state = pending( $ticket );
	$user  = get_userdata( $user_id );

	if ( null === $state || ! $user || ! is_email( $user->user_email ) ) {
		return false;
	}

	$code = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );

	$state['code'] = wp_hash_password( $code );
	$state['sent'] = time();

	remember( $ticket, $state );

	$sent = wp_mail(
		$user->user_email,
		sprintf(
			/* translators: %s: the name of the site. */
			__( 'Your code for %s', 'wow-guard' ),
			wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES )
		),
		sprintf(
			/* translators: 1: the code, 2: how many minutes it lasts, 3: the address it was requested from. */
			__( "Your code is %1\$s\n\nIt is good for %2\$d minutes and can be used once.\n\nSomebody entered the right password for your account from %3\$s. If that was not you, your password is known to somebody else and should be changed.", 'wow-guard' ),
			$code,
			(int) ( EMAILED_FOR / MINUTE_IN_SECONDS ),
			Visitor\address() ?: __( 'an unknown address', 'wow-guard' )
		)
	);

	return (bool) $sent;
}

/**
 * Whether what was typed proves who they are.
 *
 * Recovery codes are accepted whichever way the person normally proves
 * themselves: they exist precisely for when the usual way is unavailable.
 *
 * @param int                 $user_id Whose.
 * @param string              $given   What was typed.
 * @param array<string,mixed> $state   The half-finished login.
 * @return bool
 */
function proves( int $user_id, string $given, array $state ): bool {
	$given = trim( $given );

	if ( '' === $given ) {
		return false;
	}

	if ( str_contains( $given, '-' ) && spend_recovery_code( $user_id, $given ) ) {
		return true;
	}

	if ( 'email' === method( $user_id ) ) {
		$hash = (string) ( $state['code'] ?? '' );

		if ( '' === $hash || time() - (int) ( $state['sent'] ?? 0 ) > EMAILED_FOR ) {
			return false;
		}

		return wp_check_password( preg_replace( '/\D/', '', $given ) ?? '', $hash );
	}

	$step = Totp\verify( secret( $user_id ), $given );

	if ( false === $step ) {
		return false;
	}

	/*
	 * A code is good once. Without this, one glanced at over a shoulder or
	 * left on a screen is good for its whole half-minute and the two either
	 * side of it — which is long enough for somebody standing behind you.
	 */
	if ( (int) get_user_meta( $user_id, USED, true ) >= $step ) {
		return false;
	}

	update_user_meta( $user_id, USED, $step );

	return true;
}

/**
 * Whether a code proves the person still holds what they set up.
 *
 * For the places that are not a login: switching a second factor on, and
 * switching it off again. There is no half-finished login there to carry an
 * emailed code, so this covers the two that stand on their own — the
 * application and the recovery codes — and whoever sent an email code checks
 * that one themselves.
 *
 * A code is spent here exactly as it is at a login: once. Somebody who reads
 * one over a shoulder must not be able to use it a second time to take the
 * second factor off.
 *
 * @param int    $user_id Whose.
 * @param string $given   What was typed.
 */
function confirms( int $user_id, string $given ): bool {
	$given = trim( $given );

	if ( '' === $given ) {
		return false;
	}

	if ( str_contains( $given, '-' ) ) {
		return spend_recovery_code( $user_id, $given );
	}

	$secret = secret( $user_id );

	if ( '' === $secret ) {
		return false;
	}

	$step = Totp\verify( $secret, $given );

	if ( false === $step || (int) get_user_meta( $user_id, USED, true ) >= $step ) {
		return false;
	}

	update_user_meta( $user_id, USED, $step );

	return true;
}

/**
 * Whether this request is in the act of finishing a login.
 *
 * A flag and not a static inside on_login(), because the two halves happen in
 * different requests: the first takes the cookie away, the second gives it
 * back and announces the login again for everything that was listening. That
 * second announcement reaches on_login() like any other, and without
 * something to recognise it the cookie would be taken away once more — the
 * same form, for ever.
 *
 * @param bool|null $set Set it, or leave it alone and just ask.
 */
function finishing( ?bool $set = null ): bool {
	static $flag = false;

	if ( null !== $set ) {
		$flag = $set;
	}

	return $flag;
}

/**
 * Catches a login that is not finished yet.
 *
 * WordPress hands out the cookie before it announces the login, so the first
 * thing to do is take it back. Everything after that is a second request as
 * far as the browser is concerned: a form, a ticket, and a code.
 *
 * @param mixed $user_login Who logged in.
 * @param mixed $user       Their account.
 */
function on_login( $user_login = '', $user = null ): void {
	if ( finishing() || ! $user instanceof \WP_User || ! enabled( $user->ID ) ) {
		return;
	}

	// Anything that is not a browser asking for a page — XML-RPC, the REST
	// API, a cron run — has no way to show a form and no way to answer one.
	if ( ! function_exists( 'login_header' ) ) {
		return;
	}

	/*
	 * A browser that showed a code within the last month is let through
	 * without being asked again. Checked here, after the password has been
	 * accepted and before the cookie is taken away: this skips the second
	 * step, never the first.
	 */
	if ( Trusted\trusted( $user->ID ) ) {
		return;
	}

	wp_clear_auth_cookie();
	wp_destroy_current_session();

	// And out of memory too, so that nothing drawing the form below still
	// believes somebody is logged in.
	wp_set_current_user( 0 );

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- wp-login.php has already checked the login form's own fields.
	$redirect = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( (string) $_REQUEST['redirect_to'] ) ) : admin_url();
	$remember = ! empty( $_POST['rememberme'] );
	// phpcs:enable

	$ticket = begin( $user->ID, $remember, $redirect );
	$notice = '';

	if ( 'email' === method( $user->ID ) && ! send_code( $user->ID, $ticket ) ) {
		$notice = __( 'The code could not be sent. Use one of your recovery codes.', 'wow-guard' );
	}

	render_form( $ticket, $notice );
}
add_action( 'wp_login', __NAMESPACE__ . '\\on_login', 5, 2 );

/**
 * Draws the second step and stops.
 *
 * @param string $ticket The half-finished login.
 * @param string $notice Anything that went wrong.
 * @param string $error  A refusal to show in red.
 */
function render_form( string $ticket, string $notice = '', string $error = '' ): void {
	$state = pending( $ticket );
	$user  = null === $state ? null : get_userdata( (int) $state['user'] );

	if ( ! $user ) {
		wp_safe_redirect( wp_login_url() );
		exit;
	}

	$by_email = 'email' === method( $user->ID );

	login_header( __( 'Two-step login', 'wow-guard' ), '' );

	if ( '' !== $error ) {
		printf( '<div id="login_error" class="notice notice-error"><p>%s</p></div>', esc_html( $error ) );
	}

	if ( '' !== $notice ) {
		printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html( $notice ) );
	}
	?>
	<form name="wow2fa" id="loginform" action="<?php echo esc_url( wp_login_url() ); ?>" method="post">
		<p>
			<?php
			echo $by_email
				? esc_html__( 'A code has been sent to your email address.', 'wow-guard' )
				: esc_html__( 'Open your authenticator application and enter the code it shows.', 'wow-guard' );
			?>
		</p>

		<p>
			<label for="wow_2fa_code"><?php esc_html_e( 'Code', 'wow-guard' ); ?></label>
			<input type="text" name="wow_2fa_code" id="wow_2fa_code" class="input" value="" size="20"
				autocomplete="one-time-code" inputmode="numeric" autocapitalize="off" autocorrect="off" spellcheck="false"
				autofocus required />
		</p>

		<?php if ( Trusted\allowed() ) : ?>
			<?php $days = (int) ( Trusted\FOR_LONG / DAY_IN_SECONDS ); ?>
			<p class="forgetmenot" style="margin-bottom:1em">
				<input name="wow_2fa_trust" type="checkbox" id="wow_2fa_trust" value="1" />
				<label for="wow_2fa_trust">
					<?php
					printf(
						/* translators: %d: a number of days. */
						esc_html( _n( 'Do not ask on this browser for %d day', 'Do not ask on this browser for %d days', $days, 'wow-guard' ) ),
						(int) $days
					);
					?>
				</label>
			</p>
			<p class="description" style="margin:-0.5em 0 1em">
				<?php esc_html_e( 'This browser only. Until then the password alone gets into this account from this machine, so leave it unticked on one you share.', 'wow-guard' ); ?>
			</p>
		<?php endif; ?>

		<input type="hidden" name="action" value="wow_2fa" />
		<input type="hidden" name="wow_2fa_ticket" value="<?php echo esc_attr( $ticket ); ?>" />
		<?php
		/*
		 * No nonce, and for a reason worth writing down. A WordPress nonce is
		 * tied to whoever is logged in, and at this point nobody is — the
		 * cookie was taken away a moment ago. One made while the form is
		 * drawn would be checked against a different user when the form comes
		 * back, and never match: the second step would refuse everybody with
		 * "the link you followed has expired". It did.
		 *
		 * The ticket does the same job better. It is sixteen random bytes,
		 * kept on the server, good for ten minutes and for one login, and
		 * shown only to somebody who has just given the right password. There
		 * is nothing for a forged request to guess.
		 */
		?>

		<p class="submit">
			<input type="submit" name="wp-submit" id="wp-submit" class="button button-primary button-large"
				value="<?php esc_attr_e( 'Continue', 'wow-guard' ); ?>" />
			<?php if ( $by_email ) : ?>
				<input type="submit" name="wow_2fa_resend" class="button button-large"
					value="<?php esc_attr_e( 'Send it again', 'wow-guard' ); ?>" />
			<?php endif; ?>
		</p>

		<p class="description" style="margin-top:1em">
			<?php esc_html_e( 'A recovery code works here too, dashes and all.', 'wow-guard' ); ?>
		</p>
	</form>

	<p id="nav"><a href="<?php echo esc_url( wp_login_url() ); ?>"><?php esc_html_e( '← Start again', 'wow-guard' ); ?></a></p>
	<?php

	login_footer( 'wow_2fa_code' );

	exit;
}

/**
 * Reads the code back and decides.
 *
 * Registered as a login action of its own, which is also what makes
 * wp-login.php accept the name at all: without a listener it refuses an
 * action it does not recognise and quietly shows the ordinary form.
 */
function handle(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- the nonce is checked immediately below, against the ticket.
	$ticket = isset( $_POST['wow_2fa_ticket'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['wow_2fa_ticket'] ) ) : '';
	$given  = isset( $_POST['wow_2fa_code'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['wow_2fa_code'] ) ) : '';
	$resend = isset( $_POST['wow_2fa_resend'] );
	$trust  = ! empty( $_POST['wow_2fa_trust'] );
	// phpcs:enable

	$state = pending( $ticket );

	if ( null === $state ) {
		// Expired, or never existed. Back to the beginning, where the only
		// thing lost is the typing.
		wp_safe_redirect( wp_login_url() );
		exit;
	}

	$user_id = (int) $state['user'];

	if ( $resend ) {
		$sent = send_code( $user_id, $ticket );

		render_form( $ticket, $sent ? __( 'A new code is on its way.', 'wow-guard' ) : __( 'The code could not be sent. Use one of your recovery codes.', 'wow-guard' ) );
	}

	if ( proves( $user_id, $given, $state ) ) {
		$state['trust'] = $trust;

		finish( $ticket, $state );
	}

	++$state['tries'];

	$user = get_userdata( $user_id );

	Log\add( 'failed', Visitor\address(), $user ? (string) $user->user_login : (string) $user_id );

	if ( $state['tries'] >= TRIES ) {
		forget( $ticket );

		Log\add( 'locked', Visitor\address(), $user ? (string) $user->user_login : (string) $user_id );

		wp_safe_redirect( wp_login_url() );
		exit;
	}

	remember( $ticket, $state );

	render_form( $ticket, '', __( 'That code is not right.', 'wow-guard' ) );
}
add_action( 'login_form_wow_2fa', __NAMESPACE__ . '\\handle' );

/**
 * Lets somebody the rest of the way in.
 *
 * @param string              $ticket The half-finished login.
 * @param array<string,mixed> $state  What was known about it.
 */
function finish( string $ticket, array $state ): void {
	$user_id = (int) $state['user'];
	$user    = get_userdata( $user_id );

	forget( $ticket );

	if ( ! $user ) {
		wp_safe_redirect( wp_login_url() );
		exit;
	}

	finishing( true );

	// Before the cookie, because both are headers and both have to be sent
	// before anything else is.
	if ( ! empty( $state['trust'] ) ) {
		Trusted\remember( $user_id );
	}

	wp_set_auth_cookie( $user_id, ! empty( $state['remember'] ) );
	wp_set_current_user( $user_id );

	/*
	 * Announced again, because the first announcement was taken back along
	 * with the cookie and plugins that listen for a login have not had one
	 * yet. on_login() knows to stay out of its own way.
	 */
	do_action( 'wp_login', $user->user_login, $user );

	$redirect = (string) ( $state['redirect'] ?? '' );

	wp_safe_redirect( '' === $redirect ? admin_url() : $redirect );
	exit;
}
