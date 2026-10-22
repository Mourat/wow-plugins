<?php
/**
 * Where somebody turns a second factor on.
 *
 * On their own profile page, because that is where it belongs: a second
 * factor is a thing a person sets up with something they are holding, and
 * nobody can do it on their behalf. An administrator may switch somebody
 * else's off — there has to be a way back when a phone is lost — but may not
 * switch it on, because they would have nowhere to send the secret.
 *
 * @package WOW\Guard
 */

namespace WOW\Guard\TwoFactorUI;

use WOW\Guard\Totp;
use WOW\Guard\Trusted;
use WOW\Guard\TwoFactor;

defined( 'ABSPATH' ) || exit;

/** Where a secret waits while somebody is still proving they can read it. */
const CANDIDATE = 'wow_2fa_candidate_';

/** Where freshly made recovery codes wait to be shown exactly once. */
const FRESH_CODES = 'wow_2fa_fresh_';

/** Where a code sent from this page waits for the person to type it back. */
const MAILED = 'wow_2fa_mailed_';

/** Where something to tell the person waits until the next page is drawn. */
const NOTE = 'wow_2fa_note_';

/** How long a code sent from this page is good for. */
const MAIL_GOOD_FOR = 15 * MINUTE_IN_SECONDS;

/** How many times it may be got wrong before it is thrown away. */
const MAIL_TRIES = 5;

/**
 * The secret somebody is in the middle of setting up.
 *
 * Kept aside rather than saved, so that a person who scans the code and then
 * closes the tab is left exactly as they were, rather than locked behind a
 * secret no application has.
 *
 * @param int $user_id Whose.
 */
function candidate( int $user_id ): string {
	return (string) get_transient( CANDIDATE . $user_id );
}

/**
 * The code sent to somebody's own address, while it is still worth anything.
 *
 * Only ever the hash of it: this is a second factor, and the server has no
 * more business holding a usable copy of it than it does a password.
 *
 * @param int $user_id Whose.
 * @return array<string,mixed> Empty when there is none.
 */
function mailed( int $user_id ): array {
	$state = get_transient( MAILED . $user_id );

	return is_array( $state ) ? $state : array();
}

/**
 * Throws away whatever setup was half-done.
 *
 * @param int $user_id Whose.
 */
function clear( int $user_id ): void {
	delete_transient( CANDIDATE . $user_id );
	delete_transient( MAILED . $user_id );
}

/**
 * Leaves a line to show on the next page.
 *
 * @param int    $user_id Whose.
 * @param string $what    Which line.
 */
function note( int $user_id, string $what ): void {
	set_transient( NOTE . $user_id, $what, MINUTE_IN_SECONDS );
}

/**
 * Sends a code to the address on the account.
 *
 * The same proof the login asks for, asked for before anything is switched
 * on: a mailbox nobody can read is a locked door, and the only honest way to
 * find that out is to put a code in it and wait to be told what it said.
 *
 * A code that could not be sent is dropped again, so that the screen never
 * asks for one that is not coming.
 *
 * @param \WP_User $user Whose account.
 * @param string   $why  'on' while switching on, 'off' while switching off.
 * @return bool Whether it went.
 */
function send_mail_code( \WP_User $user, string $why ): bool {
	if ( ! is_email( $user->user_email ) ) {
		return false;
	}

	$code = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );

	set_transient(
		MAILED . $user->ID,
		array(
			'code'  => wp_hash_password( $code ),
			'sent'  => time(),
			'tries' => 0,
			'why'   => 'off' === $why ? 'off' : 'on',
		),
		MAIL_GOOD_FOR
	);

	$site    = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	$minutes = (int) ( MAIL_GOOD_FOR / MINUTE_IN_SECONDS );

	$body = 'off' === $why
		? sprintf(
			/* translators: 1: the code, 2: how many minutes it lasts. */
			__( "Your code is %1\$s\n\nType it on your profile page to turn two-step login off. It is good for %2\$d minutes and can be used once.\n\nIf you did not ask for this, somebody is signed in as you: change your password and do not type this code anywhere.", 'wow-guard' ),
			$code,
			$minutes
		)
		: sprintf(
			/* translators: 1: the code, 2: how many minutes it lasts. */
			__( "Your code is %1\$s\n\nType it on your profile page to turn two-step login on. It is good for %2\$d minutes and can be used once.\n\nThis is also the proof that codes sent here actually reach you. If it did not, do not turn it on.", 'wow-guard' ),
			$code,
			$minutes
		);

	$sent = wp_mail(
		$user->user_email,
		sprintf(
			/* translators: %s: the name of the site. */
			__( 'Your code for %s', 'wow-guard' ),
			$site
		),
		$body
	);

	if ( ! $sent ) {
		delete_transient( MAILED . $user->ID );
	}

	return (bool) $sent;
}

/**
 * What a typed code is worth.
 *
 * Counted rather than merely checked: five guesses and the code is gone,
 * because six digits are not many and nothing else here slows a guesser down.
 * The count is kept for exactly as long as the code had left, so that getting
 * it wrong cannot be used to keep it alive.
 *
 * @param int    $user_id Whose.
 * @param string $given   What was typed.
 * @return string 'ok', 'expired', 'too-many' or 'wrong'.
 */
function check_mail_code( int $user_id, string $given ): string {
	$state = mailed( $user_id );

	if ( array() === $state ) {
		return 'expired';
	}

	$left = MAIL_GOOD_FOR - ( time() - (int) ( $state['sent'] ?? 0 ) );

	if ( $left <= 0 ) {
		delete_transient( MAILED . $user_id );

		return 'expired';
	}

	$state['tries'] = (int) ( $state['tries'] ?? 0 ) + 1;

	if ( $state['tries'] > MAIL_TRIES ) {
		delete_transient( MAILED . $user_id );

		return 'too-many';
	}

	$digits = (string) preg_replace( '/\D/', '', $given );

	if ( '' === $digits || ! wp_check_password( $digits, (string) ( $state['code'] ?? '' ) ) ) {
		set_transient( MAILED . $user_id, $state, $left );

		return 'wrong';
	}

	delete_transient( MAILED . $user_id );

	return 'ok';
}

/**
 * Whether somebody has just proved they still hold their second factor.
 *
 * @param int    $user_id Whose.
 * @param string $given   What was typed.
 * @return string 'ok', 'expired', 'too-many' or 'wrong'.
 */
function verdict( int $user_id, string $given ): string {
	if ( TwoFactor\confirms( $user_id, $given ) ) {
		return 'ok';
	}

	/*
	 * A recovery code that did not work is simply wrong — spent, mistyped or
	 * never ours. Letting it fall through to the emailed code would answer a
	 * question nobody asked, and say "that code has run out" about a code
	 * that was never sent.
	 */
	if ( str_contains( trim( $given ), '-' ) ) {
		return 'wrong';
	}

	if ( 'email' === TwoFactor\method( $user_id ) ) {
		return check_mail_code( $user_id, $given );
	}

	return 'wrong';
}

/**
 * The recovery codes as a file somebody can keep.
 *
 * Built into the page rather than fetched from anywhere: the codes exist in
 * one browser window for one minute of someone's life, and an address that
 * hands them out again would be a way to ask the site for them later.
 *
 * Lines end the way Windows expects. Most of the people who will open this
 * will open it in Notepad, and a file of bare newlines arrives there as one
 * long line.
 *
 * @param \WP_User $user  Whose account.
 * @param string[] $codes The codes.
 */
function codes_text( \WP_User $user, array $codes ): string {
	$lines = array(
		sprintf(
			/* translators: %s: the name of the site. */
			__( 'Recovery codes for two-step login on %s', 'wow-guard' ),
			wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES )
		),
		home_url( '/' ),
		'',
		sprintf(
			/* translators: %s: a user name. */
			__( 'Account: %s', 'wow-guard' ),
			$user->user_login
		),
		sprintf(
			/* translators: %s: a date. */
			__( 'Made on %s', 'wow-guard' ),
			date_i18n( (string) get_option( 'date_format' ) )
		),
		'',
		__( 'Each code gets you in once, when the application or the mailbox is out of reach. Keep this file somewhere that is not this site.', 'wow-guard' ),
		'',
	);

	return implode( "\r\n", array_merge( $lines, array_values( $codes ) ) ) . "\r\n";
}

/**
 * What that file should be called.
 */
function codes_filename(): string {
	$name = sanitize_title( (string) get_bloginfo( 'name' ) );

	return ( '' === $name ? 'site' : $name ) . '-recovery-codes.txt';
}

/**
 * Draws the section.
 *
 * @param mixed $user The account being looked at.
 */
function section( $user ): void {
	if ( ! $user instanceof \WP_User ) {
		return;
	}

	$own   = get_current_user_id() === $user->ID;
	$how   = TwoFactor\method( $user->ID );
	$fresh = get_transient( FRESH_CODES . $user->ID );

	if ( is_array( $fresh ) ) {
		delete_transient( FRESH_CODES . $user->ID );
	}

	if ( ! $own && ! current_user_can( 'edit_user', $user->ID ) ) {
		return;
	}
	?>
	<h2 id="wow-2fa"><?php esc_html_e( 'Two-step login', 'wow-guard' ); ?></h2>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Status', 'wow-guard' ); ?></th>
			<td>
				<?php if ( '' === $how ) : ?>
					<p><strong><?php esc_html_e( 'Off.', 'wow-guard' ); ?></strong> <?php esc_html_e( 'Knowing the password is enough to get into this account.', 'wow-guard' ); ?></p>
				<?php else : ?>
					<p>
						<strong><?php esc_html_e( 'On.', 'wow-guard' ); ?></strong>
						<?php
						echo 'totp' === $how
							? esc_html__( 'A code from an authenticator application is asked for after the password.', 'wow-guard' )
							: esc_html__( 'A code sent by email is asked for after the password.', 'wow-guard' );
						?>
					</p>
					<p class="description">
						<?php
						printf(
							/* translators: %d: how many recovery codes are left. */
							esc_html( _n( '%d recovery code left.', '%d recovery codes left.', TwoFactor\recovery_left( $user->ID ), 'wow-guard' ) ),
							(int) TwoFactor\recovery_left( $user->ID )
						);
						?>
					</p>
				<?php endif; ?>
			</td>
		</tr>

		<?php if ( is_array( $fresh ) && ! empty( $fresh ) ) : ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Recovery codes', 'wow-guard' ); ?></th>
				<td>
					<div class="notice notice-warning inline" style="margin:0 0 1em">
						<p><strong><?php esc_html_e( 'Write these down now. They are not shown again.', 'wow-guard' ); ?></strong></p>
						<p><?php esc_html_e( 'Each one gets you in once, and they are the way back if the phone is lost or the mailbox unreachable. Keep them somewhere that is not this site.', 'wow-guard' ); ?></p>
					</div>
					<pre style="background:#f6f7f7;padding:1em;font-size:14px;line-height:1.8"><?php echo esc_html( implode( "\n", $fresh ) ); ?></pre>
					<p>
						<?php
						/*
						 * esc_attr and not esc_url: esc_url refuses data:,
						 * and rightly so for anything that came from
						 * outside. This one is built here, line by line,
						 * out of codes this page has just made.
						 */
						?>
						<a class="button" download="<?php echo esc_attr( codes_filename() ); ?>" href="<?php echo esc_attr( 'data:text/plain;charset=utf-8,' . rawurlencode( codes_text( $user, $fresh ) ) ); ?>">
							<?php esc_html_e( 'Download them as a file', 'wow-guard' ); ?>
						</a>
					</p>
					<p class="description"><?php esc_html_e( 'A plain text file, saved straight from this page — it is not fetched from the site and cannot be asked for again.', 'wow-guard' ); ?></p>
				</td>
			</tr>
		<?php endif; ?>

		<?php if ( $own && '' === $how ) : ?>
			<?php draw_setup( $user ); ?>
		<?php endif; ?>

		<?php if ( '' !== $how && Trusted\allowed() ) : ?>
			<?php draw_trusted( $user ); ?>
		<?php endif; ?>

		<?php if ( '' !== $how ) : ?>
			<?php draw_disable( $user, $own, $how ); ?>
		<?php endif; ?>
	</table>
	<?php
}
add_action( 'show_user_profile', __NAMESPACE__ . '\\section' );
add_action( 'edit_user_profile', __NAMESPACE__ . '\\section' );

/**
 * The part where somebody chooses a way and proves it works.
 *
 * No <form> of its own, and that is not a style choice. This is drawn inside
 * the profile page's own form, and HTML has no nested forms — a browser
 * quietly throws the inner one away and hands its fields to the outer, so a
 * form written here looks right, submits to the wrong place, and is never
 * seen by the code meant to read it. These fields therefore belong to the
 * profile form, and are read when it is saved.
 *
 * @param \WP_User $user Whose account.
 */
function draw_setup( \WP_User $user ): void {
	$secret = candidate( $user->ID );
	$mailed = mailed( $user->ID );
	?>
	<tr>
		<th scope="row"><?php esc_html_e( 'Turn it on', 'wow-guard' ); ?></th>
		<td>
			<?php if ( '' !== $secret ) : ?>
				<?php draw_totp_setup( $user, $secret ); ?>
			<?php elseif ( array() !== $mailed ) : ?>
				<?php draw_email_setup( $user ); ?>
			<?php else : ?>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( add_query_arg( 'wow_2fa', 'totp' ) ); ?>#wow-2fa"><?php esc_html_e( 'Use an authenticator application', 'wow-guard' ); ?></a>
					<button type="submit" name="wow_2fa_do" value="send-email" class="button"><?php esc_html_e( 'Send me a code by email', 'wow-guard' ); ?></button>
				</p>
				<p class="description" style="max-width:46em">
					<?php esc_html_e( 'The application is the stronger of the two: the secret never leaves your phone, so there is nothing to intercept. Email needs nothing installed, but the mailbox is usually also where a password reset would go — somebody holding it holds both halves. Either is a great deal better than neither.', 'wow-guard' ); ?>
				</p>
				<p class="description" style="max-width:46em">
					<?php esc_html_e( 'Whichever you choose, a code comes first and nothing is switched on until it comes back right. That is the only way to know the application has the secret, or that mail from this site arrives at all.', 'wow-guard' ); ?>
				</p>
			<?php endif; ?>
		</td>
	</tr>
	<?php
}

/**
 * The QR code, the secret in writing, and the field that proves it took.
 *
 * @param \WP_User $user   Whose account.
 * @param string   $secret The secret being set up.
 */
function draw_totp_setup( \WP_User $user, string $secret ): void {
	$uri = Totp\uri( $secret, $user->user_email ?: $user->user_login, (string) get_bloginfo( 'name' ) );
	?>
	<p><?php esc_html_e( 'Scan this with your authenticator application:', 'wow-guard' ); ?></p>

	<div id="wow-2fa-qr" data-uri="<?php echo esc_attr( $uri ); ?>" style="margin:1em 0"></div>

	<p>
		<?php esc_html_e( 'Or type the secret in by hand:', 'wow-guard' ); ?><br />
		<code style="font-size:15px;letter-spacing:.1em"><?php echo esc_html( implode( ' ', str_split( $secret, 4 ) ) ); ?></code>
	</p>

	<p>
		<label for="wow_2fa_confirm"><?php esc_html_e( 'Now enter the code it shows:', 'wow-guard' ); ?></label><br />
		<input type="text" name="wow_2fa_code" id="wow_2fa_confirm" class="regular-text" inputmode="numeric" autocomplete="one-time-code" />
	</p>

	<p>
		<button type="submit" name="wow_2fa_do" value="confirm-totp" class="button button-primary">
			<?php esc_html_e( 'Turn two-step login on', 'wow-guard' ); ?>
		</button>
		<a class="button" href="<?php echo esc_url( remove_query_arg( 'wow_2fa' ) ); ?>#wow-2fa"><?php esc_html_e( 'Cancel', 'wow-guard' ); ?></a>
	</p>

	<p class="description" style="max-width:46em">
		<?php esc_html_e( 'Nothing is saved until that code is right. Scan it, close the page, and this account is exactly as it was.', 'wow-guard' ); ?>
	</p>
	<?php
}

/**
 * The same, for a code sent by email.
 *
 * @param \WP_User $user Whose account.
 */
function draw_email_setup( \WP_User $user ): void {
	?>
	<p>
		<?php
		printf(
			/* translators: %s: an email address. */
			esc_html__( 'A code has gone to %s. It is the same code that will be asked for after the password from now on.', 'wow-guard' ),
			'<code>' . esc_html( $user->user_email ) . '</code>'
		);
		?>
	</p>

	<p>
		<label for="wow_2fa_confirm"><?php esc_html_e( 'Now enter the code it carries:', 'wow-guard' ); ?></label><br />
		<input type="text" name="wow_2fa_code" id="wow_2fa_confirm" class="regular-text" inputmode="numeric" autocomplete="one-time-code" />
	</p>

	<p>
		<button type="submit" name="wow_2fa_do" value="confirm-email" class="button button-primary">
			<?php esc_html_e( 'Turn two-step login on', 'wow-guard' ); ?>
		</button>
		<button type="submit" name="wow_2fa_do" value="send-email" class="button"><?php esc_html_e( 'Send another code', 'wow-guard' ); ?></button>
		<a class="button" href="<?php echo esc_url( add_query_arg( 'wow_2fa', 'cancel' ) ); ?>#wow-2fa"><?php esc_html_e( 'Cancel', 'wow-guard' ); ?></a>
	</p>

	<p class="description" style="max-width:46em">
		<?php esc_html_e( 'Nothing is saved until that code comes back right. If it never arrives, this site cannot send mail and a code after the password would be a locked door — Settings → Mail has a test.', 'wow-guard' ); ?>
	</p>
	<?php
}

/**
 * The browsers that are not being asked for a code at the moment.
 *
 * Worth a list rather than a count: the only way to notice a browser that
 * should not be there is to see what it calls itself and when it was let in.
 *
 * @param \WP_User $user Whose account.
 */
function draw_trusted( \WP_User $user ): void {
	$records = Trusted\records( $user->ID );
	$format  = (string) get_option( 'date_format' );
	?>
	<tr>
		<th scope="row"><?php esc_html_e( 'Remembered browsers', 'wow-guard' ); ?></th>
		<td>
			<?php if ( array() === $records ) : ?>
				<p><?php esc_html_e( 'None. A code is asked for at every login.', 'wow-guard' ); ?></p>
			<?php else : ?>
				<ul style="margin:0 0 1em;list-style:disc;padding-left:1.4em">
					<?php foreach ( $records as $record ) : ?>
						<li>
							<?php
							printf(
								/* translators: 1: something like "Chrome on Windows", 2: a date. */
								esc_html__( '%1$s — no code asked for until %2$s', 'wow-guard' ),
								esc_html( (string) ( $record['label'] ?? '' ) ),
								esc_html( date_i18n( $format, (int) ( $record['until'] ?? 0 ) ) )
							);
							?>
						</li>
					<?php endforeach; ?>
				</ul>
				<button type="submit" name="wow_2fa_do" value="forget-browsers" class="button">
					<?php esc_html_e( 'Ask for a code everywhere again', 'wow-guard' ); ?>
				</button>
				<p class="description"><?php esc_html_e( 'For when one of those machines is no longer yours, or no longer only yours. Takes effect when the profile is saved.', 'wow-guard' ); ?></p>
			<?php endif; ?>
		</td>
	</tr>
	<?php
}

/**
 * The row for switching it off again.
 *
 * Switching it off asks for the same proof as switching it on, which is the
 * practice everywhere else and the reason is plain: a second factor that a
 * borrowed, still-signed-in browser can simply remove was never a second
 * factor. An administrator on somebody else's profile is the exception —
 * that is the documented way back in when the phone is gone, and asking them
 * for a code they cannot have would close it.
 *
 * @param \WP_User $user Whose account.
 * @param bool     $own  Whether it is their own.
 * @param string   $how  'totp' or 'email'.
 */
function draw_disable( \WP_User $user, bool $own, string $how ): void {
	$mailed = mailed( $user->ID );
	?>
	<tr>
		<th scope="row"><?php esc_html_e( 'Switch it off', 'wow-guard' ); ?></th>
		<td>
			<?php if ( ! $own ) : ?>
				<button type="submit" name="wow_2fa_do" value="disable" class="button button-link-delete">
					<?php esc_html_e( 'Turn two-step login off', 'wow-guard' ); ?>
				</button>
				<p class="description"><?php esc_html_e( 'Takes effect when the profile is saved. Switching somebody else\'s off is the way back in when a phone is lost; switching it on is theirs to do.', 'wow-guard' ); ?></p>
			<?php elseif ( 'email' === $how && array() === $mailed ) : ?>
				<button type="submit" name="wow_2fa_do" value="send-off-code" class="button">
					<?php esc_html_e( 'Send me a code to confirm', 'wow-guard' ); ?>
				</button>
				<p class="description"><?php esc_html_e( 'Turning it off asks for the same proof as turning it on.', 'wow-guard' ); ?></p>
			<?php else : ?>
				<p>
					<label for="wow_2fa_off">
						<?php
						echo 'totp' === $how
							? esc_html__( 'A code from your authenticator application, or one of the recovery codes:', 'wow-guard' )
							: esc_html__( 'The code just sent to you, or one of the recovery codes:', 'wow-guard' );
						?>
					</label><br />
					<input type="text" name="wow_2fa_code" id="wow_2fa_off" class="regular-text" inputmode="numeric" autocomplete="one-time-code" />
				</p>
				<p>
					<button type="submit" name="wow_2fa_do" value="disable" class="button button-link-delete">
						<?php esc_html_e( 'Turn two-step login off', 'wow-guard' ); ?>
					</button>
					<?php if ( 'email' === $how ) : ?>
						<button type="submit" name="wow_2fa_do" value="send-off-code" class="button"><?php esc_html_e( 'Send another code', 'wow-guard' ); ?></button>
					<?php endif; ?>
				</p>
				<p class="description"><?php esc_html_e( 'Takes effect when the profile is saved.', 'wow-guard' ); ?></p>
			<?php endif; ?>

			<?php if ( $own ) : ?>
				<p class="description"><?php esc_html_e( 'If you have lost both the application and the recovery codes, somebody with access to the server can run: wp wow-guard 2fa disable <your user name>', 'wow-guard' ); ?></p>
			<?php endif; ?>
		</td>
	</tr>
	<?php
}

/**
 * Makes a secret ready when somebody says they want to use an application.
 *
 * Put aside for a quarter of an hour, not saved. It becomes real only when a
 * code made from it comes back, which is the proof that the application has
 * it and that the clocks agree.
 */
function prepare(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- choosing what to draw on a page the person is already allowed to see, and clearing their own half-done setup.
	$what    = isset( $_GET['wow_2fa'] ) ? sanitize_key( wp_unslash( (string) $_GET['wow_2fa'] ) ) : '';
	$user_id = get_current_user_id();

	if ( $user_id <= 0 || '' === $what ) {
		return;
	}

	/*
	 * Cancel has to undo something, not merely stop drawing it. A secret put
	 * aside outlives the page it was drawn on, so without this the Cancel
	 * button reloaded into the very screen it was meant to leave.
	 */
	if ( 'cancel' === $what ) {
		clear( $user_id );

		return;
	}

	if ( 'totp' !== $what || TwoFactor\enabled( $user_id ) || '' !== candidate( $user_id ) ) {
		return;
	}

	delete_transient( MAILED . $user_id );
	set_transient( CANDIDATE . $user_id, Totp\secret(), 15 * MINUTE_IN_SECONDS );
}
add_action( 'load-profile.php', __NAMESPACE__ . '\\prepare' );
add_action( 'load-user-edit.php', __NAMESPACE__ . '\\prepare' );

/**
 * Brings in the QR drawing, and only where it is wanted.
 *
 * @param string $hook Which admin page this is.
 */
function assets( string $hook ): void {
	if ( ! in_array( $hook, array( 'profile.php', 'user-edit.php' ), true ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- deciding whether a script is needed.
	if ( ! isset( $_GET['wow_2fa'] ) || 'totp' !== $_GET['wow_2fa'] ) {
		return;
	}

	wp_enqueue_script( 'wow-guard-qrcode', WOW_GUARD_URL . 'assets/vendor/qrcode.js', array(), '2.0.4', true );
	wp_enqueue_script( 'wow-guard-2fa', WOW_GUARD_URL . 'assets/two-factor.js', array( 'wow-guard-qrcode' ), WOW_GUARD_VERSION, true );
}
add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\assets' );

/**
 * Reads the fields back when the profile is saved.
 *
 * WordPress has already checked that the person may edit this account and
 * that the form is genuine by the time these hooks run; what is left is to
 * decide whether anything of ours was asked for.
 *
 * @param mixed $user_id Whose account was saved.
 */
function save( $user_id ): void {
	$user_id = (int) $user_id;

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- edit_user() has already checked it.
	$do    = isset( $_POST['wow_2fa_do'] ) ? sanitize_key( wp_unslash( (string) $_POST['wow_2fa_do'] ) ) : '';
	$given = isset( $_POST['wow_2fa_code'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['wow_2fa_code'] ) ) : '';
	// phpcs:enable

	if ( '' === $do ) {
		return;
	}

	$own  = get_current_user_id() === $user_id;
	$user = get_userdata( $user_id );

	if ( ! $user instanceof \WP_User ) {
		return;
	}

	/*
	 * Forgetting the remembered browsers only ever makes the account harder
	 * to get into, so anybody who may edit it may do it — and WordPress has
	 * already checked that much by the time this runs.
	 */
	if ( 'forget-browsers' === $do ) {
		Trusted\forget_all( $user_id );

		// To whoever is reading the screen, which is not always the owner
		// of the account being edited.
		note( get_current_user_id(), 'forgot-browsers' );

		return;
	}

	if ( 'disable' === $do ) {
		// An administrator taking somebody else's off is the way back in.
		if ( $own ) {
			if ( '' === trim( $given ) ) {
				note( $user_id, 'need-code' );

				return;
			}

			$verdict = verdict( $user_id, $given );

			if ( 'ok' !== $verdict ) {
				note( $user_id, 'wrong' === $verdict ? 'off-wrong' : $verdict );

				return;
			}
		}

		TwoFactor\disable( $user_id );
		clear( $user_id );

		if ( $own ) {
			note( $user_id, 'off' );
		}

		return;
	}

	/*
	 * Only the person themselves may switch it on. Anybody else would have to
	 * be handed the secret to do it, and a secret that has been handed over
	 * is not a second factor.
	 */
	if ( ! $own ) {
		return;
	}

	if ( 'send-email' === $do || 'send-off-code' === $do ) {
		delete_transient( CANDIDATE . $user_id );
		note( $user_id, send_mail_code( $user, 'send-off-code' === $do ? 'off' : 'on' ) ? 'sent' : 'mail-failed' );

		return;
	}

	if ( 'confirm-totp' === $do ) {
		$secret = candidate( $user_id );

		if ( '' === $secret || false === Totp\verify( $secret, $given ) ) {
			note( $user_id, 'wrong' );

			return;
		}

		TwoFactor\enable( $user_id, 'totp', $secret );
		clear( $user_id );
	} elseif ( 'confirm-email' === $do ) {
		$verdict = check_mail_code( $user_id, $given );

		if ( 'ok' !== $verdict ) {
			note( $user_id, 'wrong' === $verdict ? 'wrong-mail' : $verdict );

			return;
		}

		TwoFactor\enable( $user_id, 'email' );
		clear( $user_id );
	} else {
		return;
	}

	set_transient( FRESH_CODES . $user_id, TwoFactor\make_recovery_codes( $user_id ), 5 * MINUTE_IN_SECONDS );
}
add_action( 'personal_options_update', __NAMESPACE__ . '\\save' );
add_action( 'edit_user_profile_update', __NAMESPACE__ . '\\save' );

/**
 * Says so when a confirmation code was wrong.
 */
function notice(): void {
	$user_id = get_current_user_id();
	$what    = (string) get_transient( NOTE . $user_id );

	if ( '' === $what ) {
		return;
	}

	delete_transient( NOTE . $user_id );

	$bad = array(
		'wrong'       => __( 'That code was not right, so nothing was changed. Check that the phone\'s clock is correct and try again.', 'wow-guard' ),
		'wrong-mail'  => __( 'That code was not right, so nothing was changed. Look at the message again, or ask for another code.', 'wow-guard' ),
		'expired'     => __( 'That code has run out. Ask for another one.', 'wow-guard' ),
		'too-many'    => __( 'Five wrong codes, so that one has been thrown away. Ask for another.', 'wow-guard' ),
		'mail-failed' => __( 'The code could not be sent, so nothing was changed — this site cannot send mail at the moment. Settings → Mail has a test.', 'wow-guard' ),
		'need-code'   => __( 'Enter a current code first. Turning two-step login off asks for the same proof as turning it on.', 'wow-guard' ),
		'off-wrong'   => __( 'That code was not right, so two-step login is still on.', 'wow-guard' ),
	);

	$good = array(
		'forgot-browsers' => __( 'Every remembered browser has been forgotten. A code is asked for at the next login on each of them.', 'wow-guard' ),
		'sent' => __( 'A code is on its way. It is good for fifteen minutes.', 'wow-guard' ),
		'off'  => __( 'Two-step login is off. The password is now the only thing between this account and whoever knows it.', 'wow-guard' ),
	);

	if ( isset( $bad[ $what ] ) ) {
		printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( $bad[ $what ] ) );

		return;
	}

	if ( isset( $good[ $what ] ) ) {
		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $good[ $what ] ) );
	}
}
add_action( 'admin_notices', __NAMESPACE__ . '\\notice' );
