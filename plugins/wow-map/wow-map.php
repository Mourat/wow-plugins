<?php
/**
 * Plugin Name:       WOW Map
 * Description:       A small OpenStreetMap map as a block. The picture is drawn once on this server and stored here, so a visitor never makes a request to anybody else.
 * Version:           0.1.0
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Author:            Wow-arts
 * Update URI:        https://github.com/Mourat/wow-plugins
 * Text Domain:       wow-map
 * Domain Path:       /languages
 *
 * @package WOW\Map
 */

namespace WOW\Map;

defined( 'ABSPATH' ) || exit;

define( 'WOW_MAP_VERSION', '0.1.0' );
define( 'WOW_MAP_FILE', __FILE__ );
define( 'WOW_MAP_DIR', plugin_dir_path( __FILE__ ) );
define( 'WOW_MAP_URL', plugin_dir_url( __FILE__ ) );

require_once WOW_MAP_DIR . 'includes/geo.php';
require_once WOW_MAP_DIR . 'includes/storage.php';
require_once WOW_MAP_DIR . 'includes/tiles.php';
require_once WOW_MAP_DIR . 'includes/block.php';
require_once WOW_MAP_DIR . 'includes/cli.php';

/**
 * Loads the plugin translations.
 *
 * Runs on init rather than plugins_loaded: since WordPress 6.7 a text domain
 * loaded before init triggers a _doing_it_wrong() notice.
 *
 * Priority 5, because the block title and description are translated out of
 * this catalogue as the block type registers, and that happens on init at 20.
 */
function load_textdomain(): void {
	load_plugin_textdomain( 'wow-map', false, dirname( plugin_basename( WOW_MAP_FILE ) ) . '/languages' );
}
add_action( 'init', __NAMESPACE__ . '\\load_textdomain', 5 );
