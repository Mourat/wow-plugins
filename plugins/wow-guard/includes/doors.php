<?php
/**
 * Shutting the doors this site does not use.
 *
 * Every way in has to be guarded for as long as it is open, and most of these
 * are open only because WordPress has to work for everybody. This site has no
 * mobile app, no desktop blogging client and no third party posting to it, so
 * the interfaces that exist for those are pure surface.
 *
 * @package WOW\Guard
 */

namespace WOW\Guard\Doors;

use WOW\Guard\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Turns XML-RPC off.
 *
 * The one that matters most. Its system.multicall carries any number of calls
 * in a single request, so a machine can try two hundred passwords in one go —
 * and because it never touches the login form, a guard that only watches the
 * login form never sees it. This plugin's limit does catch it, since both go
 * through the same authentication; switching the interface off as well means
 * there is nothing to catch.
 */
function xmlrpc(): void {
	if ( Options\get( 'xmlrpc' ) ) {
		return;
	}

	add_filter( 'xmlrpc_enabled', '__return_false' );
	add_filter( 'pre_option_enable_xmlrpc', '__return_zero' );

	// Pingbacks announce themselves in every page's head and are the other
	// half of what XML-RPC is used for by anybody who is not the owner.
	add_filter(
		'wp_headers',
		static function ( $headers ) {
			unset( $headers['X-Pingback'] );

			return $headers;
		}
	);

	add_filter(
		'xmlrpc_methods',
		static function ( $methods ) {
			unset( $methods['pingback.ping'], $methods['pingback.extensions.getPingbacks'], $methods['system.multicall'] );

			return $methods;
		}
	);
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\xmlrpc' );

/**
 * Answers the XML-RPC endpoint with a refusal instead of a conversation.
 *
 * The filters above are enough to stop a password being guessed — every
 * method that takes one answers "disabled" — but the endpoint itself goes on
 * replying, and system.listMethods happily reads out everything else it
 * offers. Those methods belong to the XML-RPC library rather than to
 * WordPress, so no WordPress hook reaches them.
 *
 * The request is therefore turned away before any of it runs. xmlrpc.php
 * announces itself with a constant before it loads WordPress at all, which
 * makes this both early and certain.
 */
function block_endpoint(): void {
	if ( Options\get( 'xmlrpc' ) || ! defined( 'XMLRPC_REQUEST' ) ) {
		return;
	}

	status_header( 403 );
	nocache_headers();
	header( 'Content-Type: text/plain; charset=utf-8' );

	exit( 'XML-RPC is disabled on this site.' );
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\block_endpoint', 0 );

/**
 * Turns application passwords off.
 *
 * A second kind of credential that most owners never knowingly create and
 * never think to look at. Nothing on this site uses them.
 *
 * @param mixed $available Whether WordPress was going to allow them.
 * @return mixed
 */
function application_passwords( $available ) {
	return Options\get( 'app_passwords' ) ? $available : false;
}
add_filter( 'wp_is_application_passwords_available', __NAMESPACE__ . '\\application_passwords', 20 );

/**
 * Takes the theme and plugin file editors out of the admin.
 *
 * Without this, somebody who gets hold of an administrator session does not
 * have to find a vulnerability to run their own code: the admin has a text
 * editor for exactly that, and a Save button.
 */
function file_editor(): void {
	if ( Options\get( 'file_editor' ) || defined( 'DISALLOW_FILE_EDIT' ) ) {
		return;
	}

	define( 'DISALLOW_FILE_EDIT', true );
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\file_editor', 1 );

/**
 * Stops the site announcing which versions it is running.
 *
 * Worth very little on its own — anybody determined will find out — but it is
 * what the indiscriminate scanners read, and they are most of the traffic.
 */
function hide_versions(): void {
	if ( ! Options\get( 'hide_versions' ) ) {
		return;
	}

	remove_action( 'wp_head', 'wp_generator' );
	remove_action( 'wp_head', 'wlwmanifest_link' );
	remove_action( 'wp_head', 'rsd_link' );

	add_filter( 'the_generator', '__return_empty_string' );

	// PHP adds this one itself, before any of WordPress has run.
	if ( ! headers_sent() ) {
		header_remove( 'X-Powered-By' );
	}
}
add_action( 'init', __NAMESPACE__ . '\\hide_versions' );
