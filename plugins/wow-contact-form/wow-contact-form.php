<?php
/**
 * Plugin Name:       WOW Contact Form
 * Description:       A contact form block: the visitor's message is kept in the site's own database and sent on by email, with no third-party service involved.
 * Version:           0.1.2
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Author:            Wow-arts
 * Update URI:        https://github.com/Mourat/wow-plugins
 * Text Domain:       wow-contact-form
 * Domain Path:       /languages
 *
 * @package WOW\ContactForm
 */

namespace WOW\ContactForm;

defined( 'ABSPATH' ) || exit;

define( 'WOW_CONTACT_FORM_VERSION', '0.1.2' );
define( 'WOW_CONTACT_FORM_FILE', __FILE__ );
define( 'WOW_CONTACT_FORM_DIR', plugin_dir_path( __FILE__ ) );
define( 'WOW_CONTACT_FORM_URL', plugin_dir_url( __FILE__ ) );

require_once WOW_CONTACT_FORM_DIR . 'includes/fields.php';
require_once WOW_CONTACT_FORM_DIR . 'includes/security.php';
require_once WOW_CONTACT_FORM_DIR . 'includes/storage.php';
require_once WOW_CONTACT_FORM_DIR . 'includes/mail.php';
require_once WOW_CONTACT_FORM_DIR . 'includes/submission.php';
require_once WOW_CONTACT_FORM_DIR . 'includes/block.php';
require_once WOW_CONTACT_FORM_DIR . 'includes/patterns.php';
require_once WOW_CONTACT_FORM_DIR . 'includes/admin.php';
require_once WOW_CONTACT_FORM_DIR . 'includes/widget.php';

/**
 * Loads the plugin translations.
 *
 * Runs on init rather than plugins_loaded: since WordPress 6.7 a text domain
 * loaded before init triggers a _doing_it_wrong() notice.
 *
 * Priority 5 is deliberate. The block title and description are translated
 * server-side out of this catalogue while the block type is registered, and
 * that happens on init as well — at priority 20, so the catalogue is always
 * in place first.
 */
function load_textdomain(): void {
	load_plugin_textdomain( 'wow-contact-form', false, dirname( plugin_basename( WOW_CONTACT_FORM_FILE ) ) . '/languages' );
}
add_action( 'init', __NAMESPACE__ . '\\load_textdomain', 5 );
