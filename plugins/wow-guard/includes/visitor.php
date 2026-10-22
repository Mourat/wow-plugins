<?php
/**
 * Which address a request came from.
 *
 * Harder than it looks, and getting it wrong breaks the lock in one of two
 * ways. Trust a forwarded header that nothing in front of the site sets, and
 * anybody can send a different one with every guess and never be locked out.
 * Refuse to read it when there really is a proxy in front, and every visitor
 * shares the proxy's address, so the first person to mistype a password locks
 * out the whole world.
 *
 * So the only address trusted by default is the one the web server saw, and
 * reading a header instead is something somebody has to turn on, knowing
 * what sits in front of their site.
 *
 * @package WOW\Guard
 */

namespace WOW\Guard\Visitor;

use WOW\Guard\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The address this request appears to come from.
 *
 * @return string An IP address, or an empty string when there is none to be
 *                had — a command line run, for instance.
 */
function address(): string {
	if ( Options\get( 'behind_proxy' ) ) {
		$header = (string) Options\get( 'proxy_header' );
		$sent   = isset( $_SERVER[ $header ] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER[ $header ] ) ) : '';

		// A forwarded header is a list: the client first, then each proxy it
		// passed through. The client's own entry is the one that matters.
		foreach ( explode( ',', $sent ) as $candidate ) {
			$candidate = trim( $candidate );

			if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				return $candidate;
			}
		}
	}

	$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';

	return filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '';
}

/**
 * A short, stable name for an address, safe to use as a key.
 *
 * Hashed so that the counters this plugin keeps are not a list of everybody
 * who has ever visited, sitting in the options table under their own names.
 * The salt is the site's own, so the hashes mean nothing anywhere else.
 *
 * @param string $ip An address; the current one when empty.
 */
function key( string $ip = '' ): string {
	$ip = '' === $ip ? address() : $ip;

	return '' === $ip ? '' : substr( wp_hash( 'wow-guard|' . $ip ), 0, 20 );
}
