<?php
/**
 * Making sure nothing in the uploads folder can run.
 *
 * The uploads folder is the one place on a WordPress site that the outside
 * world can put files into. Everything that is supposed to land there is a
 * picture. If anything else ever does — through a hole in some plugin, or a
 * password that got out — the difference between a nuisance and a disaster is
 * whether the web server will execute it.
 *
 * This is for Apache, which is what this site runs on and what most shared
 * hosting runs on. On nginx the same thing has to be said in the server
 * configuration, and the settings screen says so rather than pretending.
 *
 * @package WOW\Guard
 */

namespace WOW\Guard\Uploads;

use WOW\Guard\Options;

defined( 'ABSPATH' ) || exit;

/** Put at the top of the file so that it can be recognised as ours later. */
const MARKER = '# WOW Guard — nothing in here is meant to be executed.';

/**
 * What the file should contain.
 */
function rules(): string {
	return MARKER . "\n"
		. "<FilesMatch \"\\.(?i:php|phtml|phps|phar|php[0-9]*|cgi|pl|py|sh|htaccess)$\">\n"
		. "\tRequire all denied\n"
		. "</FilesMatch>\n";
}

/**
 * Where it goes.
 */
function path(): string {
	$uploads = wp_get_upload_dir();

	return empty( $uploads['error'] ) ? trailingslashit( $uploads['basedir'] ) . '.htaccess' : '';
}

/**
 * Whether the file is there and is the one this plugin writes.
 *
 * A file put there by somebody else is left alone and reported as such: it
 * may be doing something this plugin knows nothing about.
 *
 * @return string 'ours', 'theirs' or 'missing'.
 */
function state(): string {
	$file = path();

	if ( '' === $file || ! file_exists( $file ) ) {
		return 'missing';
	}

	$held = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file this plugin wrote.

	return str_contains( $held, MARKER ) ? 'ours' : 'theirs';
}

/**
 * Writes the file.
 *
 * @return bool Whether it is now in place.
 */
function protect(): bool {
	if ( ! Options\get( 'no_php_uploads' ) ) {
		return false;
	}

	$file = path();

	if ( '' === $file || 'theirs' === state() ) {
		return false;
	}

	return false !== file_put_contents( $file, rules() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents -- a local file in the uploads folder.
}

/**
 * Takes it away again, if it is ours.
 */
function unprotect(): void {
	if ( 'ours' === state() ) {
		wp_delete_file( path() );
	}
}

/**
 * Keeps the file in step with the setting.
 */
function keep_in_step(): void {
	if ( Options\get( 'no_php_uploads' ) ) {
		if ( 'missing' === state() ) {
			protect();
		}

		return;
	}

	unprotect();
}
add_action( 'admin_init', __NAMESPACE__ . '\\keep_in_step' );
