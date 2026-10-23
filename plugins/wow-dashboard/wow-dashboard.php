<?php
/**
 * Plugin Name:       WOW Dashboard
 * Description:       Visual adjustments to the WordPress admin — how it looks and moves, never what it does.
 * Version:           0.1.1
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Author:            Wow-arts
 * Update URI:        https://github.com/Mourat/wow-plugins
 * Text Domain:       wow-dashboard
 * Domain Path:       /languages
 *
 * @package WOW\Dashboard
 */

namespace WOW\Dashboard;

defined( 'ABSPATH' ) || exit;

define( 'WOW_DASHBOARD_VERSION', '0.1.1' );
define( 'WOW_DASHBOARD_FILE', __FILE__ );
define( 'WOW_DASHBOARD_DIR', plugin_dir_path( __FILE__ ) );
define( 'WOW_DASHBOARD_URL', plugin_dir_url( __FILE__ ) );

require_once WOW_DASHBOARD_DIR . 'includes/menu.php';

/**
 * Loads the plugin translations.
 *
 * On init rather than plugins_loaded: since WordPress 6.7 a text domain
 * loaded before init draws a _doing_it_wrong() notice.
 */
function load_textdomain(): void {
	load_plugin_textdomain( 'wow-dashboard', false, dirname( plugin_basename( WOW_DASHBOARD_FILE ) ) . '/languages' );
}
add_action( 'init', __NAMESPACE__ . '\\load_textdomain', 5 );
