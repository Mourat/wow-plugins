<?php
/**
 * Plugin Name:       WOW Guard
 * Description:       The handful of things that actually keep a small WordPress site from being broken into: a limit on password guessing, no public list of who may log in, and the doors nobody uses kept shut.
 * Version:           0.1.1
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Author:            Wow-arts
 * Update URI:        https://github.com/Mourat/wow-plugins
 * Text Domain:       wow-guard
 * Domain Path:       /languages
 *
 * @package WOW\Guard
 */

namespace WOW\Guard;

defined( 'ABSPATH' ) || exit;

define( 'WOW_GUARD_VERSION', '0.1.1' );
define( 'WOW_GUARD_FILE', __FILE__ );
define( 'WOW_GUARD_DIR', plugin_dir_path( __FILE__ ) );
define( 'WOW_GUARD_URL', plugin_dir_url( __FILE__ ) );

require_once WOW_GUARD_DIR . 'includes/secret.php';
require_once WOW_GUARD_DIR . 'includes/options.php';
require_once WOW_GUARD_DIR . 'includes/visitor.php';
require_once WOW_GUARD_DIR . 'includes/log.php';
require_once WOW_GUARD_DIR . 'includes/attempts.php';
require_once WOW_GUARD_DIR . 'includes/names.php';
require_once WOW_GUARD_DIR . 'includes/doors.php';
require_once WOW_GUARD_DIR . 'includes/updates.php';
require_once WOW_GUARD_DIR . 'includes/headers.php';
require_once WOW_GUARD_DIR . 'includes/uploads.php';
require_once WOW_GUARD_DIR . 'includes/login-url.php';
require_once WOW_GUARD_DIR . 'includes/totp.php';
require_once WOW_GUARD_DIR . 'includes/trusted.php';
require_once WOW_GUARD_DIR . 'includes/two-factor.php';
require_once WOW_GUARD_DIR . 'includes/two-factor-ui.php';
require_once WOW_GUARD_DIR . 'includes/admin.php';
require_once WOW_GUARD_DIR . 'includes/cli.php';

/**
 * Loads the plugin translations.
 */
function load_textdomain(): void {
	load_plugin_textdomain( 'wow-guard', false, dirname( plugin_basename( WOW_GUARD_FILE ) ) . '/languages' );
}
add_action( 'init', __NAMESPACE__ . '\\load_textdomain', 5 );

/**
 * Sets the site up when the plugin is switched on.
 */
function activate(): void {
	Uploads\protect();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );

/**
 * Puts back what was changed outside the database.
 *
 * The file that stops PHP running in the uploads folder is this plugin's
 * doing and goes with it. Switching a security plugin off has to leave a site
 * that works, not one with rules nobody can find the source of.
 */
function deactivate(): void {
	Uploads\unprotect();
	Attempts\forget_all();
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );

/**
 * Forgets everything when the plugin is deleted.
 */
function uninstall(): void {
	delete_option( Options\OPTION );
	delete_option( Log\OPTION );
}
register_uninstall_hook( __FILE__, __NAMESPACE__ . '\\uninstall' );
