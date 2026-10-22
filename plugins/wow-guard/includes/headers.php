<?php
/**
 * A few things the browser should be told.
 *
 * None of these stop somebody getting into the site; they limit what can be
 * done to a visitor who is already looking at it. They cost nothing, they
 * break nothing here, and they are absent from almost every WordPress site
 * because nothing adds them by default.
 *
 * Deliberately not here: a content security policy. A wrong one breaks a page
 * silently and in ways that are hard to trace, and a right one has to be
 * written against the particular scripts a site loads rather than switched on.
 *
 * @package WOW\Guard
 */

namespace WOW\Guard\Headers;

use WOW\Guard\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The headers to send.
 *
 * @return array<string,string>
 */
function wanted(): array {
	$headers = array(
		// Stops a browser deciding for itself that a file is something other
		// than what it was sent as — the trick behind most "upload a picture,
		// have it run as a script" attacks.
		'X-Content-Type-Options' => 'nosniff',

		// Another site cannot put this one in a frame and collect clicks
		// meant for it.
		'X-Frame-Options'        => 'SAMEORIGIN',

		// The address of the page somebody came from stops being handed to
		// other sites in full.
		'Referrer-Policy'        => 'strict-origin-when-cross-origin',

		// Nothing here needs a camera, a microphone or a location, so nothing
		// embedded in a page may ask for them.
		'Permissions-Policy'     => 'camera=(), microphone=(), geolocation=(), interest-cohort=()',
	);

	/*
	 * Only over HTTPS, and only when asked for: a browser remembers this for
	 * as long as it is told to, so a site that sends it and later loses its
	 * certificate becomes unreachable rather than insecure.
	 */
	if ( Options\get( 'hsts' ) && is_ssl() ) {
		$headers['Strict-Transport-Security'] = 'max-age=31536000';
	}

	/**
	 * Filters the headers this plugin sends.
	 *
	 * @param array<string,string> $headers Header name => value.
	 */
	return (array) apply_filters( 'wow_guard_headers', $headers );
}

/**
 * Sends them.
 */
function send(): void {
	if ( ! Options\get( 'headers' ) || headers_sent() ) {
		return;
	}

	foreach ( wanted() as $name => $value ) {
		header( sprintf( '%s: %s', $name, $value ), true );
	}
}
add_action( 'send_headers', __NAMESPACE__ . '\\send' );
add_action( 'admin_init', __NAMESPACE__ . '\\send' );
add_action( 'login_init', __NAMESPACE__ . '\\send' );
