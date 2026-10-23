<?php
/**
 * Installing our own updates without being asked twice.
 *
 * WordPress offers an update and waits. Automatic installation is a choice
 * made per plugin, in a link nobody finds, and off until it is found — so
 * a site can sit for months showing a tidy row of "update available" and
 * running last spring's code.
 *
 * For plugins published from somebody else's repository that caution is
 * right. For these it is not: they are ours, they are released from one
 * place, and the person who would have to tick eleven boxes is the person
 * who asked for them to update themselves.
 *
 * So anything carrying our own Update URI updates itself, plugin and theme
 * alike. Everything else on the site is left exactly as WordPress left it,
 * including the plugins that came from wordpress.org.
 *
 * Two things this does not change, said here because they are what makes
 * "it did not install itself" look like a fault:
 *
 *   - WordPress installs on its own schedule, twice a day, not the moment
 *     a release appears. "Update now" in the admin is still the fast way.
 *   - A host that forbids changing files (DISALLOW_FILE_MODS) forbids this
 *     too, and nothing here can or should get around that.
 *
 * @package WOW\Updates
 */

namespace WOW\Updates\Automatic;

use WOW\Updates\Plugins;

defined( 'ABSPATH' ) || exit;

/**
 * Whether one of ours should install itself.
 *
 * @param bool|null $update Whether WordPress was going to.
 * @param string    $uri    The Update URI it carries.
 * @return bool|null True for ours, and whatever was decided for the rest.
 */
function decide( $update, string $uri ) {
	if ( ! Plugins\ours( $uri ) ) {
		return $update;
	}

	/**
	 * Filters whether the plugins and the theme released from our own
	 * repository install their updates by themselves.
	 *
	 * Here so that a site that wants to watch each one go by can say so in
	 * a line, rather than by unticking eleven boxes.
	 *
	 * @param bool $yes Whether they do. Default true.
	 */
	return (bool) apply_filters( 'wow_updates_automatic', true );
}

/**
 * For plugins.
 *
 * @param bool|null $update Whether WordPress was going to.
 * @param object    $item   What it is deciding about.
 * @return bool|null
 */
function plugin( $update, $item ) {
	$file = is_object( $item ) ? (string) ( $item->plugin ?? '' ) : '';

	if ( '' === $file ) {
		return $update;
	}

	$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false );

	return decide( $update, (string) ( $data['UpdateURI'] ?? '' ) );
}
add_filter( 'auto_update_plugin', __NAMESPACE__ . '\\plugin', 10, 2 );

/**
 * For themes.
 *
 * @param bool|null $update Whether WordPress was going to.
 * @param object    $item   What it is deciding about.
 * @return bool|null
 */
function theme( $update, $item ) {
	$slug = is_object( $item ) ? (string) ( $item->theme ?? '' ) : '';

	if ( '' === $slug ) {
		return $update;
	}

	return decide( $update, (string) wp_get_theme( $slug )->get( 'UpdateURI' ) );
}
add_filter( 'auto_update_theme', __NAMESPACE__ . '\\theme', 10, 2 );

/**
 * Makes sure the plugin reader is there when the filter fires.
 *
 * auto_update_plugin can run during cron, where wp-admin is not loaded and
 * get_plugin_data() does not exist.
 */
function ready(): void {
	if ( ! function_exists( 'get_plugin_data' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
}
add_action( 'wp_maybe_auto_update', __NAMESPACE__ . '\\ready' );
add_action( 'automatic_updates_complete', __NAMESPACE__ . '\\ready' );
