<?php
/**
 * Plugin Name:       WOW Albums
 * Description:       Albums post type for image catalogues: featured image cover and manual drag-and-drop ordering.
 * Version:           0.1.0
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Author:            Wow-arts
 * Update URI:        https://github.com/Mourat/wow-plugins
 * Text Domain:       wow-albums
 * Domain Path:       /languages
 *
 * @package WOW\Albums
 */

namespace WOW\Albums;

defined( 'ABSPATH' ) || exit;

define( 'WOW_ALBUMS_VERSION', '0.1.0' );
define( 'WOW_ALBUMS_FILE', __FILE__ );
define( 'WOW_ALBUMS_DIR', plugin_dir_path( __FILE__ ) );
define( 'WOW_ALBUMS_URL', plugin_dir_url( __FILE__ ) );

require_once WOW_ALBUMS_DIR . 'includes/post-type.php';
require_once WOW_ALBUMS_DIR . 'includes/admin-columns.php';
require_once WOW_ALBUMS_DIR . 'includes/ordering.php';
require_once WOW_ALBUMS_DIR . 'includes/block.php';
require_once WOW_ALBUMS_DIR . 'includes/seo.php';

/**
 * Loads the plugin translations.
 *
 * Runs on init rather than plugins_loaded: since WordPress 6.7 a text domain
 * loaded before init triggers a _doing_it_wrong() notice.
 */
function load_textdomain(): void {
	load_plugin_textdomain( 'wow-albums', false, dirname( plugin_basename( WOW_ALBUMS_FILE ) ) . '/languages' );
}
add_action( 'init', __NAMESPACE__ . '\\load_textdomain' );

/**
 * The post type registers on init, so on activation it has to be registered
 * by hand, or the rewrite rules would be written without it.
 */
function activate(): void {
	PostType\register();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );

/**
 * Cleans up the rewrite rules, so /albums/ does not answer 404 once the
 * plugin is switched off.
 */
function deactivate(): void {
	unregister_post_type( PostType\POST_TYPE );
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );
