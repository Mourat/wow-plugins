<?php
/**
 * A limit on how often a password may be guessed.
 *
 * The one thing on this list that stops an attack rather than making it
 * quieter. A machine working through a word list is not deterred by a long
 * password; it is deterred by being allowed five tries an hour.
 *
 * It hangs on `authenticate` rather than on the login form, and that is the
 * whole point: the login form is not the only way in. XML-RPC authenticates
 * through the same function, and its system.multicall can carry hundreds of
 * guesses in a single request — which is exactly how sites with a guarded
 * login page still get broken into. Guard the function everything calls and
 * there is no second door to remember.
 *
 * @package WOW\Guard
 */

namespace WOW\Guard\Attempts;

use WOW\Guard\Log;
use WOW\Guard\Options;
use WOW\Guard\Visitor;

defined( 'ABSPATH' ) || exit;

/** Prefix for the counter kept against an address. */
const COUNT = 'wow_guard_tries_';

/** Prefix for the lock kept against an address. */
const LOCK = 'wow_guard_lock_';

/**
 * How long an address is locked out for, in seconds.
 */
function lockout(): int {
	return max( 60, (int) Options\get( 'lockout' ) * MINUTE_IN_SECONDS );
}

/**
 * Whether an address is locked out, and until when.
 *
 * @param string $ip An address; the current one when empty.
 * @return int Unix time the lock lifts, or 0 when there is none.
 */
function locked_until( string $ip = '' ): int {
	$key = Visitor\key( $ip );

	if ( '' === $key ) {
		return 0;
	}

	$until = (int) get_transient( LOCK . $key );

	return $until > time() ? $until : 0;
}

/**
 * Counts one failure, and locks the address when it has had enough.
 *
 * @param string $user The name that was tried.
 */
function failed( string $user = '' ): void {
	$ip  = Visitor\address();
	$key = Visitor\key( $ip );

	if ( '' === $key ) {
		return;
	}

	$tries  = (int) get_transient( COUNT . $key ) + 1;
	$allows = max( 1, (int) Options\get( 'attempts' ) );

	Log\add( 'failed', $ip, $user );

	if ( $tries >= $allows ) {
		set_transient( LOCK . $key, time() + lockout(), lockout() );
		delete_transient( COUNT . $key );

		Log\add( 'locked', $ip, $user );

		return;
	}

	set_transient( COUNT . $key, $tries, max( 300, (int) Options\get( 'window' ) * MINUTE_IN_SECONDS ) );
}

/**
 * Forgets everything known about an address.
 *
 * @param string $ip An address; the current one when empty.
 */
function forget( string $ip = '' ): void {
	$key = Visitor\key( $ip );

	if ( '' !== $key ) {
		delete_transient( COUNT . $key );
		delete_transient( LOCK . $key );
	}
}

/**
 * Lifts every lock there is.
 *
 * Transients have no index to search by name, so this goes to the table they
 * live in. It runs when the plugin is switched off and when somebody asks for
 * it, never on an ordinary request.
 */
function forget_all(): void {
	global $wpdb;

	$names = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_' . COUNT ) . '%',
			$wpdb->esc_like( '_transient_' . LOCK ) . '%'
		)
	);

	foreach ( $names as $name ) {
		delete_transient( substr( (string) $name, strlen( '_transient_' ) ) );
	}
}

/**
 * Refuses an attempt from an address that has had its turns.
 *
 * At priority 30, which is after WordPress has decided whether the password
 * was right. Refusing earlier would be refusing the person who has just
 * remembered their password as readily as the machine that has not.
 *
 * @param mixed $user     What WordPress has concluded so far.
 * @param mixed $username The name being tried.
 * @return mixed
 */
function check( $user, $username = '' ) {
	$until = locked_until();

	if ( 0 === $until ) {
		return $user;
	}

	$minutes = max( 1, (int) ceil( ( $until - time() ) / MINUTE_IN_SECONDS ) );

	return new \WP_Error(
		'wow_guard_locked',
		sprintf(
			/* translators: %d: number of minutes. */
			_n(
				'<strong>Too many attempts.</strong> Try again in %d minute.',
				'<strong>Too many attempts.</strong> Try again in %d minutes.',
				$minutes,
				'wow-guard'
			),
			$minutes
		)
	);
}
add_filter( 'authenticate', __NAMESPACE__ . '\\check', 30, 2 );

/**
 * Notes a failure.
 *
 * @param mixed $username The name that was tried.
 * @param mixed $error    What went wrong.
 */
function on_failure( $username = '', $error = null ): void {
	// A refusal of ours is not a fresh attempt and must not count twice.
	if ( is_wp_error( $error ) && 'wow_guard_locked' === $error->get_error_code() ) {
		return;
	}

	failed( sanitize_user( (string) $username, true ) );
}
add_action( 'wp_login_failed', __NAMESPACE__ . '\\on_failure', 10, 2 );

/**
 * Forgets an address the moment somebody on it logs in successfully.
 */
function on_success(): void {
	forget();
}
add_action( 'wp_login', __NAMESPACE__ . '\\on_success' );

/**
 * Says the same thing whichever half of the credentials was wrong.
 *
 * WordPress is helpful about it: unknown name and wrong password are
 * different messages. To somebody working through a list of names that is the
 * answer to the only question worth asking, and it is free.
 *
 * @param mixed $errors What WordPress was going to say.
 * @return mixed
 */
function same_error( $errors ) {
	if ( ! Options\get( 'same_error' ) ) {
		return $errors;
	}

	$telling = array( 'invalid_username', 'invalid_email', 'incorrect_password', 'empty_password', 'empty_username' );

	if ( is_wp_error( $errors ) ) {
		foreach ( $errors->get_error_codes() as $code ) {
			if ( in_array( $code, $telling, true ) ) {
				return new \WP_Error( 'denied', __( '<strong>Error:</strong> Those details are not right.', 'wow-guard' ) );
			}
		}
	}

	return $errors;
}
add_filter( 'wp_login_errors', __NAMESPACE__ . '\\same_error' );

/**
 * The same, for the form's own rendering of the error.
 *
 * @param mixed $message What WordPress was going to print.
 * @return mixed
 */
function same_message( $message ) {
	global $errors;

	if ( ! Options\get( 'same_error' ) || ! is_wp_error( $errors ) ) {
		return $message;
	}

	$telling = array( 'invalid_username', 'invalid_email', 'incorrect_password', 'empty_password', 'empty_username' );

	foreach ( $errors->get_error_codes() as $code ) {
		if ( in_array( $code, $telling, true ) ) {
			return '<strong>' . esc_html__( 'Error:', 'wow-guard' ) . '</strong> ' . esc_html__( 'Those details are not right.', 'wow-guard' );
		}
	}

	return $message;
}
add_filter( 'login_errors', __NAMESPACE__ . '\\same_message' );
