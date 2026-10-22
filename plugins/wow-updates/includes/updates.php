<?php
/**
 * Telling WordPress that a newer one exists.
 *
 * No invention here: WordPress has had a way to do this since 5.8 and it is
 * two things. A plugin names where it comes from in an `Update URI` header,
 * and whoever understands that address answers a filter named after its
 * hostname. Everything after the answer — comparing versions, downloading,
 * unpacking, the automatic updates, the list on the Plugins screen — is
 * WordPress's own and needs nothing from us.
 *
 * Which is the point of doing it this way rather than with a library. There
 * is no second updater running beside the first.
 *
 * @package WOW\Updates
 */

namespace WOW\Updates\Plugins;

use WOW\Updates\Manifest;

defined( 'ABSPATH' ) || exit;

/**
 * Whether a plugin says it comes from our repository.
 *
 * The filter fires for every plugin on the site whose address is on
 * github.com, ours or not, so this is the line between them.
 *
 * @param string $uri The Update URI header.
 */
function ours( string $uri ): bool {
	$uri  = untrailingslashit( strtolower( trim( $uri ) ) );
	$repo = untrailingslashit( strtolower( Manifest\REPO ) );

	return $uri === $repo || $uri === $repo . '.git';
}

/**
 * What the repository has for this plugin.
 *
 * The version is returned whether it is newer or not, because the comparing
 * is WordPress's job (wp-includes/update.php) and it does it with
 * version_compare against what is installed. Saying "there is an update"
 * ourselves would be a second opinion nobody asked for.
 *
 * @param mixed  $update      What another listener has already decided.
 * @param array  $plugin_data Plugin headers.
 * @param string $plugin_file Plugin file, as `folder/file.php`.
 * @return mixed
 */
function answer( $update, $plugin_data = array(), $plugin_file = '' ) {
	// Somebody got here first; two answers are worse than one.
	if ( false !== $update ) {
		return $update;
	}

	if ( ! is_array( $plugin_data ) || ! ours( (string) ( $plugin_data['UpdateURI'] ?? '' ) ) ) {
		return $update;
	}

	$slug = dirname( (string) $plugin_file );

	if ( '' === $slug || '.' === $slug ) {
		return $update;
	}

	$known = Manifest\plugins()[ $slug ] ?? null;

	if ( ! is_array( $known ) ) {
		return $update;
	}

	$answer = array(
		'slug'    => $slug,
		'version' => $known['version'],
		'url'     => Manifest\REPO,
		'package' => $known['package'],
	);

	foreach ( array( 'requires', 'requires_php', 'tested' ) as $field ) {
		if ( '' !== $known[ $field ] ) {
			$answer[ $field ] = $known[ $field ];
		}
	}

	return $answer;
}
add_filter( 'update_plugins_github.com', __NAMESPACE__ . '\\answer', 10, 3 );

/**
 * Throws away what we think we know when an update has just been installed.
 *
 * Without this the list keeps offering the version that was just put in
 * place, for as long as the answer is kept.
 *
 * @param mixed $upgrader The upgrader at work.
 * @param mixed $extra    What it did.
 */
function forget_after_update( $upgrader = null, $extra = array() ): void {
	if ( is_array( $extra ) && 'plugin' === ( $extra['type'] ?? '' ) ) {
		Manifest\forget();
	}
}
add_action( 'upgrader_process_complete', __NAMESPACE__ . '\\forget_after_update', 10, 2 );
