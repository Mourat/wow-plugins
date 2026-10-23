<?php
/**
 * Keeping robots out, with nothing but what WordPress already ships.
 *
 * Four independent checks, none of which asks the visitor to prove anything:
 * a signed timestamp, a field no human sees, the site's own disallowed-words
 * list, and a limit on how often one address may write.
 *
 * @package WOW\ContactForm
 */

namespace WOW\ContactForm\Security;

defined( 'ABSPATH' ) || exit;

/** Name of the field that only a robot fills in. */
const HONEYPOT = 'wow_contact_website';

/**
 * Name of the trap a visitor can see.
 *
 * The invisible one is skipped by the robots that matter here. They drive
 * a real browser, read what is on the screen and fill what they find
 * there — so a field pushed off the edge of the page is a field they never
 * meet, while a field standing in the form among the others is one they
 * cannot miss.
 *
 * It costs a line of reading. The label says to leave it empty, which
 * every person can do and a machine filling in forms by their shape will
 * not. And because that is a guess about behaviour rather than a fact, a
 * message caught by it is filed as spam and kept, never discarded.
 *
 * The name is deliberately nothing a browser recognises: anything like
 * "website", "company" or "address" invites the browser's own autofill to
 * put something there on the visitor's behalf.
 */
const SEEN_TRAP = 'wow_contact_confirm';

/** Name of the field carrying the signed timestamp. */
const STAMP = 'wow_contact_stamp';

/** Nonce action, used as a hint rather than as a gate. See verify_nonce(). */
const NONCE = 'wow-contact-form';

/**
 * Mints the signed timestamp that goes into the form.
 *
 * This, and not the nonce, is the plugin's real token. wp_hash() signs it
 * with the site's own salts, so the moment the form was drawn cannot be
 * forged, and the lifetime is ours to choose rather than WordPress's.
 *
 * @return string "timestamp|signature".
 */
function stamp(): string {
	$now = (string) time();

	return $now . '|' . wp_hash( $now, 'nonce' );
}

/**
 * Whether a submitted timestamp is both genuine and plausible.
 *
 * Too fast means a robot: a person cannot read five labels and type a message
 * in three seconds. Too slow means the page sat open for hours, and the form
 * is better refused than accepted with a stale token.
 *
 * @param string $value The value as it arrived.
 * @return bool true when the stamp is signed by us and within the window.
 */
function check_stamp( string $value ): bool {
	$parts = explode( '|', $value, 2 );

	if ( 2 !== count( $parts ) || '' === $parts[0] || ! ctype_digit( $parts[0] ) ) {
		return false;
	}

	// hash_equals, not ===: the comparison must not leak timing.
	if ( ! hash_equals( wp_hash( $parts[0], 'nonce' ), $parts[1] ) ) {
		return false;
	}

	$elapsed = time() - (int) $parts[0];

	/**
	 * Filters how long a visitor must spend on the form before it is accepted.
	 *
	 * @param int $seconds Minimum seconds between drawing and submitting.
	 */
	$minimum = max( 0, (int) apply_filters( 'wow_contact_form_min_seconds', 3 ) );

	/**
	 * Filters how long a drawn form stays valid.
	 *
	 * @param int $seconds Maximum seconds between drawing and submitting.
	 */
	$maximum = max( 60, (int) apply_filters( 'wow_contact_form_max_seconds', 2 * HOUR_IN_SECONDS ) );

	return $elapsed >= $minimum && $elapsed <= $maximum;
}

/**
 * Whether the invisible field was filled in.
 *
 * A caller that finds it filled should accept the submission outwardly and
 * throw it away. Saying "you look like a robot" would tell the robot exactly
 * which field to leave alone next time.
 *
 * @param array<string,mixed> $raw Raw request data.
 * @return bool true when something typed into the honeypot.
 */
function honeypot_filled( array $raw ): bool {
	return '' !== trim( (string) ( $raw[ HONEYPOT ] ?? '' ) );
}

/**
 * Whether either trap was filled, and which.
 *
 * @param array<string,mixed> $raw Raw request data.
 * @return string Why it looks automated, or an empty string.
 */
function trap_filled( array $raw ): string {
	if ( honeypot_filled( $raw ) ) {
		return __( 'the hidden field was filled in', 'wow-contact-form' );
	}

	if ( '' !== trim( (string) ( $raw[ SEEN_TRAP ] ?? '' ) ) ) {
		return __( 'the field asking to be left empty was filled in', 'wow-contact-form' );
	}

	return '';
}

/**
 * Whether the nonce that came back is current.
 *
 * Worth knowing what this is and is not. For a logged-out visitor
 * wp_create_nonce() has no user and no session to mix in, so every anonymous
 * visitor is handed the same value and a robot can simply read it off the
 * page. It proves the form was drawn by this site in the last day or so, no
 * more — which is why a failure here is reported to the visitor as an expired
 * page rather than counted as spam.
 *
 * @param array<string,mixed> $raw Raw request data.
 * @return bool true when the nonce verifies.
 */
function verify_nonce( array $raw ): bool {
	$nonce = isset( $raw['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $raw['_wpnonce'] ) ) : '';

	return (bool) wp_verify_nonce( $nonce, NONCE );
}

/**
 * Whether the site's own disallowed-words list objects to this message.
 *
 * Settings → Discussion → "Disallowed Comment Keys" is already there, already
 * translated and already editable by the owner. Reusing it gives the contact
 * form a blocklist for nothing, and one the owner can adjust without touching
 * this plugin.
 *
 * @param array<string,string> $values Cleaned values.
 * @return bool true when the message matches something on the list.
 */
function disallowed( array $values ): bool {
	$author = trim( ( $values['first_name'] ?? '' ) . ' ' . ( $values['last_name'] ?? '' ) );

	return wp_check_comment_disallowed_list(
		$author,
		$values['email'] ?? '',
		'',
		$values['message'] ?? '',
		client_ip(),
		isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : ''
	);
}

/**
 * The address the request came from.
 *
 * Read straight from REMOTE_ADDR and from nowhere else. Forwarded-for headers
 * are written by whoever sent the request unless a proxy is known to rewrite
 * them, and trusting one here would hand every robot an unlimited supply of
 * fresh identities to slip past the rate limit.
 *
 * @return string The address, or an empty string when there is none.
 */
function client_ip(): string {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

	return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
}

/**
 * The transient name standing for one sender.
 *
 * Hashed with the site's salt rather than stored plainly: a list of addresses
 * of everyone who has written to the site is personal data, and this way the
 * options table never holds one.
 *
 * @return string Transient name, empty when the address is unknown.
 */
function throttle_key(): string {
	$ip = client_ip();

	return '' === $ip ? '' : 'wow_contact_' . md5( $ip . wp_salt() );
}

/**
 * Whether this sender has written too recently.
 *
 * @return bool true when the sender is inside the quiet period.
 */
function throttled(): bool {
	$key = throttle_key();

	return '' !== $key && false !== get_transient( $key );
}

/**
 * Starts the quiet period for this sender.
 */
function mark_sent(): void {
	$key = throttle_key();

	if ( '' === $key ) {
		return;
	}

	/**
	 * Filters how long a sender must wait between two messages.
	 *
	 * @param int $seconds Quiet period in seconds.
	 */
	$wait = max( 0, (int) apply_filters( 'wow_contact_form_throttle', MINUTE_IN_SECONDS ) );

	if ( $wait > 0 ) {
		set_transient( $key, time(), $wait );
	}
}
