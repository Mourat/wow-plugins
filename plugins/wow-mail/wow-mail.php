<?php
/**
 * Plugin Name:       WOW Mail
 * Description:       Sends the site's mail through a real mail server instead of out of the web server's back door, and says plainly which of the three things that decide whether it lands in the inbox are actually in place.
 * Version:           0.1.0
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Author:            Wow-arts
 * Update URI:        https://github.com/Mourat/wow-plugins
 * Text Domain:       wow-mail
 * Domain Path:       /languages
 *
 * @package WOW\Mail
 */

namespace WOW\Mail;

defined( 'ABSPATH' ) || exit;

define( 'WOW_MAIL_VERSION', '0.1.0' );
define( 'WOW_MAIL_FILE', __FILE__ );
define( 'WOW_MAIL_DIR', plugin_dir_path( __FILE__ ) );

require_once WOW_MAIL_DIR . 'includes/secret.php';
require_once WOW_MAIL_DIR . 'includes/options.php';
require_once WOW_MAIL_DIR . 'includes/log.php';
require_once WOW_MAIL_DIR . 'includes/sender.php';
require_once WOW_MAIL_DIR . 'includes/mailer.php';
require_once WOW_MAIL_DIR . 'includes/dns.php';
require_once WOW_MAIL_DIR . 'includes/admin.php';
require_once WOW_MAIL_DIR . 'includes/cli.php';

/**
 * Loads the plugin translations.
 */
function load_textdomain(): void {
	load_plugin_textdomain( 'wow-mail', false, dirname( plugin_basename( WOW_MAIL_FILE ) ) . '/languages' );
}
add_action( 'init', __NAMESPACE__ . '\\load_textdomain', 5 );

/**
 * Forgets everything when the plugin is deleted.
 */
function uninstall(): void {
	delete_option( Options\OPTION );
	delete_option( Log\OPTION );
}
register_uninstall_hook( __FILE__, __NAMESPACE__ . '\\uninstall' );
