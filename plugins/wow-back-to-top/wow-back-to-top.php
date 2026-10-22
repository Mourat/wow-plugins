<?php
/**
 * Plugin Name:       WOW Back To Top
 * Description:       A round button in the bottom corner of the front end that takes the visitor back to the top of the page.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Wow-arts
 * Update URI:        https://github.com/Mourat/wow-plugins
 * Text Domain:       wow-back-to-top
 * Domain Path:       /languages
 *
 * @package WOW\BackToTop
 */

namespace WOW\BackToTop;

defined( 'ABSPATH' ) || exit;

define( 'WOW_BACK_TO_TOP_VERSION', '0.1.0' );
define( 'WOW_BACK_TO_TOP_FILE', __FILE__ );
define( 'WOW_BACK_TO_TOP_DIR', plugin_dir_path( __FILE__ ) );
define( 'WOW_BACK_TO_TOP_URL', plugin_dir_url( __FILE__ ) );

require_once WOW_BACK_TO_TOP_DIR . 'includes/button.php';

/**
 * Loads the plugin translations.
 *
 * Runs on init rather than plugins_loaded: since WordPress 6.7 a text domain
 * loaded before init triggers a _doing_it_wrong() notice.
 */
function load_textdomain(): void {
	load_plugin_textdomain( 'wow-back-to-top', false, dirname( plugin_basename( WOW_BACK_TO_TOP_FILE ) ) . '/languages' );
}
add_action( 'init', __NAMESPACE__ . '\\load_textdomain' );
