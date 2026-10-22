<?php
/**
 * Plugin Name:       WOW Media Folders
 * Description:       Folders for the media library: a flat taxonomy on attachments, filled in automatically from albums, filterable from a sidebar in both library views and from a dropdown in the editor's media modal.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Wow-arts
 * Update URI:        https://github.com/Mourat/wow-plugins
 * Text Domain:       wow-media-folders
 * Domain Path:       /languages
 *
 * @package WOW\MediaFolders
 */

namespace WOW\MediaFolders;

defined( 'ABSPATH' ) || exit;

define( 'WOW_MEDIA_FOLDERS_VERSION', '0.1.0' );
define( 'WOW_MEDIA_FOLDERS_FILE', __FILE__ );
define( 'WOW_MEDIA_FOLDERS_DIR', plugin_dir_path( __FILE__ ) );
define( 'WOW_MEDIA_FOLDERS_URL', plugin_dir_url( __FILE__ ) );

require_once WOW_MEDIA_FOLDERS_DIR . 'includes/taxonomy.php';
require_once WOW_MEDIA_FOLDERS_DIR . 'includes/sync.php';
require_once WOW_MEDIA_FOLDERS_DIR . 'includes/query.php';
require_once WOW_MEDIA_FOLDERS_DIR . 'includes/admin-sidebar.php';
require_once WOW_MEDIA_FOLDERS_DIR . 'includes/admin-list.php';
require_once WOW_MEDIA_FOLDERS_DIR . 'includes/modal.php';
require_once WOW_MEDIA_FOLDERS_DIR . 'includes/ajax.php';
require_once WOW_MEDIA_FOLDERS_DIR . 'includes/cli.php';

/**
 * Loads the plugin translations.
 *
 * Runs on init rather than plugins_loaded: since WordPress 6.7 a text domain
 * loaded before init triggers a _doing_it_wrong() notice.
 */
function load_textdomain(): void {
	load_plugin_textdomain( 'wow-media-folders', false, dirname( plugin_basename( WOW_MEDIA_FOLDERS_FILE ) ) . '/languages' );
}
add_action( 'init', __NAMESPACE__ . '\\load_textdomain' );

/**
 * The taxonomy registers on init, so on activation it has to be registered by
 * hand before the term counts are recalculated.
 *
 * No rewrite rules are flushed: the taxonomy is admin-only and carries none.
 */
function activate(): void {
	Taxonomy\register();
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );
