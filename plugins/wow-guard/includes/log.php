<?php
/**
 * A short record of who has been trying to get in.
 *
 * Not an audit trail and not meant to be: the last few dozen failures, so
 * that somebody looking at the settings screen can see whether anything is
 * happening and can tell a forgetful colleague from a machine in another
 * country working through a word list.
 *
 * It is deliberately small and deliberately short-lived. An address is
 * personal data, and a security plugin that quietly accumulates a year of
 * them has solved one problem by creating another.
 *
 * @package WOW\Guard
 */

namespace WOW\Guard\Log;

defined( 'ABSPATH' ) || exit;

/** Where the record is kept. */
const OPTION = 'wow_guard_log';

/** How many entries are kept at most. */
const KEEP = 50;

/** How old an entry may be, in days. */
const DAYS = 7;

/**
 * The record, newest first.
 *
 * @return array<int,array{time:int,ip:string,user:string,event:string}>
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
 * @param string $event What happened: 'failed' or 'locked'.
 * @param string $ip    The address it came from.
 * @param string $user  The name that was tried.
 */
function add( string $event, string $ip, string $user ): void {
	$log = entries();

	array_unshift(
		$log,
		array(
			'time'  => time(),
			'ip'    => $ip,
			'user'  => mb_substr( $user, 0, 60 ),
			'event' => $event,
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
