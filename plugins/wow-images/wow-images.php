<?php
/**
 * Plugin Name:       WOW Images
 * Description:       Keeps a WebP and an AVIF copy of every picture on the site and offers them to browsers that want them, so a gallery page arrives at half the weight without anybody choosing a format by hand.
 * Version:           0.1.2
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Author:            Wow-arts
 * Update URI:        https://github.com/Mourat/wow-plugins
 * Text Domain:       wow-images
 * Domain Path:       /languages
 *
 * @package WOW\Images
 */

namespace WOW\Images;

defined( 'ABSPATH' ) || exit;

define( 'WOW_IMAGES_VERSION', '0.1.2' );
define( 'WOW_IMAGES_FILE', __FILE__ );
define( 'WOW_IMAGES_DIR', plugin_dir_path( __FILE__ ) );
define( 'WOW_IMAGES_URL', plugin_dir_url( __FILE__ ) );

require_once WOW_IMAGES_DIR . 'includes/formats.php';
require_once WOW_IMAGES_DIR . 'includes/options.php';
require_once WOW_IMAGES_DIR . 'includes/record.php';
require_once WOW_IMAGES_DIR . 'includes/later.php';
require_once WOW_IMAGES_DIR . 'includes/convert.php';
require_once WOW_IMAGES_DIR . 'includes/retina.php';
require_once WOW_IMAGES_DIR . 'includes/store.php';
require_once WOW_IMAGES_DIR . 'includes/references.php';
require_once WOW_IMAGES_DIR . 'includes/queue.php';
require_once WOW_IMAGES_DIR . 'includes/output.php';
require_once WOW_IMAGES_DIR . 'includes/cleanup.php';
require_once WOW_IMAGES_DIR . 'includes/admin.php';
require_once WOW_IMAGES_DIR . 'includes/job.php';
require_once WOW_IMAGES_DIR . 'includes/bulk.php';
require_once WOW_IMAGES_DIR . 'includes/widget.php';
require_once WOW_IMAGES_DIR . 'includes/cli.php';

/**
 * Loads the plugin translations.
 *
 * On init rather than plugins_loaded: since WordPress 6.7 a text domain
 * loaded before init draws a _doing_it_wrong() notice.
 */
function load_textdomain(): void {
	load_plugin_textdomain( 'wow-images', false, dirname( plugin_basename( WOW_IMAGES_FILE ) ) . '/languages' );
}
add_action( 'init', __NAMESPACE__ . '\\load_textdomain', 5 );

/**
 * Forgets the settings when the plugin is deleted.
 *
 * The generated files and what the attachments remember about them are left
 * alone on purpose. Deleting a plugin is not a request to throw away an
 * afternoon of encoding, and `wp wow-images clean` is there for when it is.
 */
function uninstall(): void {
	delete_option( Options\OPTION );
	delete_option( Job\OPTION );
	delete_option( Job\SECRET );
	delete_option( Job\LOCK );
}
register_uninstall_hook( __FILE__, __NAMESPACE__ . '\\uninstall' );

/**
 * Stops the watchdog when the plugin is switched off.
 *
 * A job left half done is kept rather than cleared: switching the plugin off
 * to look at something is not a decision to throw the work away, and turning
 * it back on carries straight on.
 */
function deactivate(): void {
	wp_clear_scheduled_hook( Job\WATCH );
	Job\release();
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );
