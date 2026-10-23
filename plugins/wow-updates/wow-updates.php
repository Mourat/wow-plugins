<?php
/**
 * Plugin Name:       WOW Updates
 * Description:       Keeps the plugins written for this site up to date from the repository they live in.
 * Version:           0.1.3
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Wow-arts
 * Update URI:        https://github.com/Mourat/wow-plugins
 * Text Domain:       wow-updates
 * Domain Path:       /languages
 *
 * @package WOW\Updates
 */

namespace WOW\Updates;

defined( 'ABSPATH' ) || exit;

define( 'WOW_UPDATES_VERSION', '0.1.3' );
define( 'WOW_UPDATES_FILE', __FILE__ );
define( 'WOW_UPDATES_DIR', plugin_dir_path( __FILE__ ) );
define( 'WOW_UPDATES_URL', plugin_dir_url( __FILE__ ) );

require_once WOW_UPDATES_DIR . 'includes/manifest.php';
require_once WOW_UPDATES_DIR . 'includes/updates.php';
require_once WOW_UPDATES_DIR . 'includes/automatic.php';
require_once WOW_UPDATES_DIR . 'includes/cli.php';

/*
 * No translations and no languages folder, unlike its neighbours. This
 * plugin has no screen and says nothing to anybody: it answers a filter
 * WordPress asks, and WordPress writes everything that is read. The few
 * lines in it are for whoever typed a WP-CLI command.
 */
