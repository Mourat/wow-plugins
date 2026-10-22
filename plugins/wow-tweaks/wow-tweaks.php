<?php
/**
 * Plugin Name:       WOW Tweaks
 * Description:       Small adjustments to how WordPress behaves on this site — the kind that would otherwise live as half-remembered lines in wp-config.php.
 * Version:           0.1.0
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Author:            Wow-arts
 * Update URI:        https://github.com/Mourat/wow-plugins
 * Text Domain:       wow-tweaks
 * Domain Path:       /languages
 *
 * @package WOW\Tweaks
 */

namespace WOW\Tweaks;

defined( 'ABSPATH' ) || exit;

define( 'WOW_TWEAKS_VERSION', '0.1.0' );
define( 'WOW_TWEAKS_FILE', __FILE__ );
define( 'WOW_TWEAKS_DIR', plugin_dir_path( __FILE__ ) );

require_once WOW_TWEAKS_DIR . 'includes/options.php';
require_once WOW_TWEAKS_DIR . 'includes/revisions.php';
require_once WOW_TWEAKS_DIR . 'includes/admin.php';
require_once WOW_TWEAKS_DIR . 'includes/cli.php';

/**
 * Loads the plugin translations.
 *
 * On init rather than plugins_loaded: since WordPress 6.7 a text domain
 * loaded before init draws a _doing_it_wrong() notice.
 */
function load_textdomain(): void {
	load_plugin_textdomain( 'wow-tweaks', false, dirname( plugin_basename( WOW_TWEAKS_FILE ) ) . '/languages' );
}
add_action( 'init', __NAMESPACE__ . '\\load_textdomain', 5 );

/**
 * Forgets the settings when the plugin is deleted.
 *
 * Revisions already removed stay removed; there is nothing to put back.
 */
function uninstall(): void {
	delete_option( Options\OPTION );
}
register_uninstall_hook( __FILE__, __NAMESPACE__ . '\\uninstall' );
