<?php
/**
 * Browsers that have already shown a code, and are not asked again for a
 * while.
 *
 * The second factor's cost is paid at every login, and a person who pays it
 * ten times a day on their own desk will eventually turn it off. So a
 * browser can be remembered: one code, and then thirty days of not being
 * asked on that machine.
 *
 * What is being traded away is worth stating plainly. For those thirty days,
 * on that one browser, the password is again the only thing in the way — and
 * a cookie stolen off that machine is as good as the second factor. That is
 * why it is a choice made each time rather than the default, why it is bound
 * to a browser rather than to an account, and why changing the second factor
 * or the password tears every one of them up.
 *
 * @package WOW\Guard
 */

namespace WOW\Guard\Trusted;

use WOW\Guard\Secret;

defined( 'ABSPATH' ) || exit;

/** Where the token lives in the browser. */
const COOKIE = 'wow_guard_trusted';

/** Where the hashes of the handed-out tokens live. */
const META = 'wow_2fa_trusted';

/** How long a browser is remembered for. */
const FOR_LONG = 30 * DAY_IN_SECONDS;

/**
 * How many browsers one account may have remembered at once.
 *
 * Not a round number out of the air: it is the point past which a list stops
 * being something a person can look at and recognise. The oldest goes when
 * the eleventh arrives.
 */
const LIMIT = 10;

/**
 * Whether remembering a browser is offered at all.
 *
 * A site that would rather pay the cost every time turns this off:
 *
 *     add_filter( 'wow_guard_remember_browser', '__return_false' );
 */
function allowed(): bool {
	/**
	 * Filters whether a browser may be remembered after a code.
	 *
	 * @param bool $allowed Whether to offer it.
	 */
	return (bool) apply_filters( 'wow_guard_remember_browser', true );
}

/**
 * The name of the cookie on this particular site.
 *
 * COOKIEHASH keeps two installations under one host name apart, the same way
 * WordPress keeps its own cookies apart.
 */
function cookie_name(): string {
	return COOKIE . '_' . COOKIEHASH;
}

/**
 * What is stored in place of a token.
 *
 * Keyed on the site's salts rather than plainly hashed: a database that
 * leaks then gives up nothing that can be put back into a cookie, since the
 * key is in wp-config.php and not in the table next to it.
 *
 * @param string $token The token.
 */
function fingerprint( string $token ): string {
	return hash_hmac( 'sha256', $token, Secret\key() );
}

/**
 * Something recognisable to show beside a remembered browser.
 *
 * Deliberately coarse — a family and a system, no versions. It is a label to
 * be read by the person who made it, not a check: anybody who can send a
 * stolen cookie can send a user agent to match it, so treating this as
 * evidence would be a comfort rather than a defence.
 */
function label(): string {
	$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ) : '';

	$browsers = array(
		'Edg'     => 'Edge',
		'OPR'     => 'Opera',
		'Chrome'  => 'Chrome',
		'Safari'  => 'Safari',
		'Firefox' => 'Firefox',
	);

	$systems = array(
		'Windows'  => 'Windows',
		'Android'  => 'Android',
		'iPhone'   => 'iPhone',
		'iPad'     => 'iPad',
		'Mac OS X' => 'macOS',
		'Linux'    => 'Linux',
	);

	$browser = '';
	$system  = '';

	foreach ( $browsers as $needle => $name ) {
		if ( '' === $browser && str_contains( $agent, $needle ) ) {
			$browser = $name;
		}
	}

	foreach ( $systems as $needle => $name ) {
		if ( '' === $system && str_contains( $agent, $needle ) ) {
			$system = $name;
		}
	}

	if ( '' === $browser && '' === $system ) {
		return __( 'an unknown browser', 'wow-guard' );
	}

	if ( '' === $system ) {
		return $browser;
	}

	return '' === $browser
		? $system
		: sprintf(
			/* translators: 1: a browser name, 2: the name of an operating system. */
			__( '%1$s on %2$s', 'wow-guard' ),
			$browser,
			$system
		);
}

/**
 * The browsers this account has remembered, the expired ones dropped.
 *
 * @param int $user_id Whose.
 * @return array<int,array<string,mixed>>
 */
function records( int $user_id ): array {
	$stored = get_user_meta( $user_id, META, true );
	$stored = is_array( $stored ) ? $stored : array();
	$now    = time();
	$live   = array();

	foreach ( $stored as $record ) {
		if ( is_array( $record ) && ! empty( $record['hash'] ) && (int) ( $record['until'] ?? 0 ) > $now ) {
			$live[] = $record;
		}
	}

	if ( count( $live ) !== count( $stored ) ) {
		write( $user_id, $live );
	}

	return $live;
}

/**
 * Writes the list back, or takes it away when there is nothing left.
 *
 * @param int                             $user_id Whose.
 * @param array<int,array<string,mixed>>  $records The list.
 */
function write( int $user_id, array $records ): void {
	if ( array() === $records ) {
		delete_user_meta( $user_id, META );

		return;
	}

	update_user_meta( $user_id, META, array_values( $records ) );
}

/**
 * Whether the browser asking has already shown a code for this account.
 *
 * @param int $user_id Whose account is being logged into.
 */
function trusted( int $user_id ): bool {
	if ( ! allowed() ) {
		return false;
	}

	$name = cookie_name();

	if ( empty( $_COOKIE[ $name ] ) ) {
		return false;
	}

	$token = sanitize_text_field( wp_unslash( (string) $_COOKIE[ $name ] ) );

	if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
		return false;
	}

	$wanted = fingerprint( $token );

	foreach ( records( $user_id ) as $record ) {
		if ( hash_equals( (string) $record['hash'], $wanted ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Remembers the browser that is asking.
 *
 * @param int $user_id Whose account.
 */
function remember( int $user_id ): void {
	if ( ! allowed() || headers_sent() ) {
		return;
	}

	$token = bin2hex( random_bytes( 32 ) );
	$until = time() + FOR_LONG;

	$records   = records( $user_id );
	$records[] = array(
		'hash'  => fingerprint( $token ),
		'since' => time(),
		'until' => $until,
		'label' => label(),
	);

	// The oldest go when there are too many to recognise at a glance.
	while ( count( $records ) > LIMIT ) {
		array_shift( $records );
	}

	write( $user_id, $records );

	setcookie(
		cookie_name(),
		$token,
		array(
			'expires'  => $until,
			'path'     => COOKIEPATH ? COOKIEPATH : '/',
			'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		)
	);
}

/**
 * Forgets every remembered browser, this one included.
 *
 * Called whenever the second factor itself changes, because a list of
 * browsers that may skip a factor outlives the factor it was skipping
 * otherwise.
 *
 * @param int $user_id Whose.
 */
function forget_all( int $user_id ): void {
	delete_user_meta( $user_id, META );

	if ( headers_sent() || get_current_user_id() !== $user_id ) {
		return;
	}

	setcookie(
		cookie_name(),
		'',
		array(
			'expires'  => time() - DAY_IN_SECONDS,
			'path'     => COOKIEPATH ? COOKIEPATH : '/',
			'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		)
	);
}

/**
 * A password that has just been changed takes the remembered browsers with
 * it: the usual reason for changing one is that somebody else may have had
 * it, and leaving a browser able to skip the second factor would leave that
 * person exactly where they were.
 *
 * Hooked at the bottom of WordPress rather than at any of the places a
 * password can be changed from. wp_set_password() is what the reset link,
 * the profile page and WP-CLI all end up calling, so one listener here
 * covers every road in — including the ones added later.
 *
 * @param mixed $password The new password, which is none of our business.
 * @param mixed $user_id  Whose account.
 */
function on_new_password( $password = '', $user_id = 0 ): void {
	$user_id = (int) $user_id;

	if ( $user_id > 0 ) {
		forget_all( $user_id );
	}
}
add_action( 'wp_set_password', __NAMESPACE__ . '\\on_new_password', 10, 2 );
