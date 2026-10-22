<?php
/**
 * Moving the login page somewhere nobody has guessed.
 *
 * This is not security and the settings screen says so. Anybody who means to
 * break into this site in particular will find the page. What it does is make
 * the site invisible to the machines that are not looking for it in
 * particular — the ones that work through address lists trying wp-login.php
 * on everything — and those are very nearly all of the traffic. A site that
 * was being hit a thousand times a night stops being hit at all, which means
 * a quieter server, a readable log, and a lockout list that only ever
 * contains somebody who really did forget their password.
 *
 * Two things make the difference between this working and being a nuisance.
 *
 * The first is that every address WordPress builds for itself has to move
 * too: the form it posts to, the link in a password-reset email, the little
 * window that appears when a session expires mid-edit. Miss one and either
 * something breaks or the new address is printed on a page.
 *
 * The second is that wp-admin must stop redirecting. A logged-out visitor
 * asking for /wp-admin/ is normally sent to the login page — which, once it
 * has moved, means being handed the new address by the site itself. So that
 * request is answered with "not found" instead.
 *
 * The way back in, for somebody who has forgotten where they put it, is a
 * line in wp-config.php:
 *
 *     define( 'WOW_GUARD_LOGIN_SLUG', 'whatever-you-like' );
 *
 * or, with no line at all, `wp wow-guard login-url --off`.
 *
 * @package WOW\Guard
 */

namespace WOW\Guard\LoginUrl;

use WOW\Guard\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The slug the login page answers on, empty when it has not been moved.
 */
function slug(): string {
	return Options\login_slug();
}

/**
 * Whether the page has been moved at all.
 */
function moved(): bool {
	return '' !== slug();
}

/**
 * The address of the login page as it now is.
 *
 * @param string $query Anything to put after it.
 */
function address( string $query = '' ): string {
	$url = home_url( '/' . slug() . '/' );

	return '' === $query ? $url : $url . '?' . ltrim( $query, '?' );
}

/**
 * The part of this request's path that belongs to the site.
 *
 * Written so that it is right on a site installed in a subdirectory, where
 * everything is one level down and a naive comparison never matches.
 */
function asked_for(): string {
	$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '', PHP_URL_PATH );
	$home = (string) wp_parse_url( home_url(), PHP_URL_PATH );

	if ( '' !== $home && 0 === strpos( $path, $home ) ) {
		$path = substr( $path, strlen( $home ) );
	}

	return trim( (string) $path, '/' );
}

/**
 * Files under wp-admin that must answer for everybody.
 *
 * admin-ajax and admin-post are how the front end talks to WordPress, logged
 * in or not; the other two are the stylesheets and scripts the login page
 * itself is made of, and refusing them would move the page to an address
 * where it has no styling.
 *
 * @return string[]
 */
function open_to_all(): array {
	return array( 'admin-ajax.php', 'admin-post.php', 'load-styles.php', 'load-scripts.php' );
}

/**
 * Answers "not found", and says nothing else.
 */
function hide(): void {
	status_header( 404 );
	nocache_headers();

	// Short and plain on purpose: a page explaining that the login has moved
	// would be the one thing this is for not saying.
	exit;
}

/**
 * Works out what this request is, before WordPress decides for itself.
 *
 * On plugins_loaded, which is late enough that $pagenow and the pluggable
 * functions exist and early enough that nothing has been printed.
 */
function handle(): void {
	if ( ! moved() ) {
		return;
	}

	global $pagenow;

	$asked = asked_for();

	if ( slug() === $asked ) {
		// It is ours. Tell the rest of WordPress what kind of page this is,
		// and serve the real one once the site has finished loading.
		$pagenow = 'wp-login.php';

		add_action( 'wp_loaded', __NAMESPACE__ . '\\serve', 0 );

		return;
	}

	if ( 'wp-login.php' === $pagenow || 'wp-register.php' === $pagenow ) {
		hide();
	}

	if ( 0 === strpos( $asked, 'wp-admin' ) && ! is_user_logged_in() ) {
		$file = wp_basename( $asked );

		if ( ! in_array( $file, open_to_all(), true ) ) {
			hide();
		}
	}
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\handle', 1 );

/**
 * Hands the request to the real login page.
 *
 * On wp_loaded rather than straight away, so that everything a login page may
 * reasonably expect — init having run, translations, other plugins' hooks —
 * is in place, exactly as it would be at the old address.
 */
function serve(): void {
	global $pagenow, $error, $interim_login, $action, $user_login;

	$pagenow = 'wp-login.php';

	require_once ABSPATH . 'wp-login.php';

	exit;
}

/**
 * Rewrites the addresses WordPress builds for its own login page.
 *
 * @param mixed $url    The address WordPress built.
 * @param mixed $path   What it was asked for.
 * @param mixed $scheme Which kind of address.
 * @return mixed
 */
function rewrite( $url, $path = '', $scheme = null ) {
	if ( ! moved() || ! is_string( $url ) || false === strpos( $url, 'wp-login.php' ) ) {
		return $url;
	}

	$query = (string) wp_parse_url( $url, PHP_URL_QUERY );

	return address( $query );
}
add_filter( 'site_url', __NAMESPACE__ . '\\rewrite', 20, 3 );
add_filter( 'network_site_url', __NAMESPACE__ . '\\rewrite', 20, 3 );
add_filter( 'login_url', __NAMESPACE__ . '\\rewrite', 20, 3 );
add_filter( 'logout_url', __NAMESPACE__ . '\\rewrite', 20, 2 );
add_filter( 'lostpassword_url', __NAMESPACE__ . '\\rewrite', 20, 2 );
add_filter( 'register_url', __NAMESPACE__ . '\\rewrite', 20 );

/**
 * Catches a redirect that was about to send somebody to the old address.
 *
 * auth_redirect() and a few others build their destination without going
 * through any of the filters above.
 *
 * @param mixed $location Where WordPress was about to send them.
 * @return mixed
 */
function rewrite_redirect( $location ) {
	if ( ! moved() || ! is_string( $location ) || false === strpos( $location, 'wp-login.php' ) ) {
		return $location;
	}

	return address( (string) wp_parse_url( $location, PHP_URL_QUERY ) );
}
add_filter( 'wp_redirect', __NAMESPACE__ . '\\rewrite_redirect', 20 );

/*
 * There is deliberately nothing here about robots.txt.
 *
 * Telling crawlers to stay away from the login page is the obvious thing to
 * do and it is exactly wrong: robots.txt is a public file, so a line asking
 * them not to visit an address publishes that address to anybody who reads
 * it. This plugin did that for one afternoon.
 *
 * Nothing is needed instead. WordPress already sends noindex on the login
 * page, through login_header(), and it does so wherever that page is served
 * from — including here.
 */
