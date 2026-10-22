<?php
/**
 * A short record of what the site has tried to send.
 *
 * The question people actually have about mail is never "how is it
 * configured" — it is "did that go out, and if not, why not". WordPress
 * answers neither: wp_mail() returns a boolean that most callers throw away,
 * and a failure leaves nothing behind.
 *
 * Kept small and short-lived, and without the body of any message: this is a
 * record of attempts, not a copy of the correspondence.
 *
 * @package WOW\Mail
 */

namespace WOW\Mail\Log;

use WOW\Mail\Options;

defined( 'ABSPATH' ) || exit;

/** Where the record is kept. */
const OPTION = 'wow_mail_log';

/** How many entries are kept at most. */
const KEEP = 40;

/** How old an entry may be, in days. */
const DAYS = 14;

/**
 * The record, newest first.
 *
 * @return array<int,array{time:int,to:string,subject:string,ok:bool,reason:string}>
 */
function entries(): array {
	$log = get_option( OPTION );

	if ( ! is_array( $log ) ) {
		return array();
	}

	$oldest = time() - DAYS * DAY_IN_SECONDS;

	return array_values(
		array_filter(
			$log,
			static fn ( $entry ): bool => is_array( $entry ) && (int) ( $entry['time'] ?? 0 ) >= $oldest
		)
	);
}

/**
 * Writes one line.
 *
 * @param string $to      Who it was for.
 * @param string $subject What it was about.
 * @param bool   $ok      Whether it went.
 * @param string $reason  Why not, when it did not.
 */
function add( string $to, string $subject, bool $ok, string $reason = '' ): void {
	if ( ! Options\get( 'keep_log' ) ) {
		return;
	}

	$log = entries();

	array_unshift(
		$log,
		array(
			'time'    => time(),
			'to'      => mb_substr( $to, 0, 120 ),
			'subject' => mb_substr( $subject, 0, 120 ),
			'ok'      => $ok,
			'reason'  => mb_substr( $reason, 0, 400 ),
		)
	);

	update_option( OPTION, array_slice( $log, 0, KEEP ), false );
}

/**
 * Empties the record.
 */
function clear(): void {
	delete_option( OPTION );
}

/**
 * Remembers who a message was for, so that the result can be filed under it.
 *
 * wp_mail_succeeded and wp_mail_failed both carry the details, so this is
 * only a convenience — but wp_mail_failed carries them inside a WP_Error's
 * data, where they are easy to get wrong.
 *
 * @param mixed $args What wp_mail() was called with.
 * @return mixed The same, untouched.
 */
function remember( $args ) {
	return $args;
}
add_filter( 'wp_mail', __NAMESPACE__ . '\\remember' );

/**
 * Notes a message that went.
 *
 * @param mixed $details What was sent.
 */
function on_success( $details ): void {
	$to = (array) ( $details['to'] ?? array() );

	add( implode( ', ', array_map( 'strval', $to ) ), (string) ( $details['subject'] ?? '' ), true );
}
add_action( 'wp_mail_succeeded', __NAMESPACE__ . '\\on_success' );

/**
 * Notes a message that did not.
 *
 * @param mixed $error What went wrong.
 */
function on_failure( $error ): void {
	if ( ! $error instanceof \WP_Error ) {
		return;
	}

	$data = (array) $error->get_error_data();
	$to   = (array) ( $data['to'] ?? array() );

	add(
		implode( ', ', array_map( 'strval', $to ) ),
		(string) ( $data['subject'] ?? '' ),
		false,
		$error->get_error_message()
	);
}
add_action( 'wp_mail_failed', __NAMESPACE__ . '\\on_failure' );
