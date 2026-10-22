<?php
/**
 * Plugin Name:       WOW Blocks
 * Description:       Fixed-grid gallery rows: one, two or three columns, each holding an image or a solid colour fill, plus a narrow divider strip.
 * Version:           0.1.14
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Wow-arts
 * Update URI:        https://github.com/Mourat/wow-plugins
 * Text Domain:       wow-blocks
 * Domain Path:       /languages
 *
 * @package WOW\Blocks
 */

namespace WOW\Blocks;

defined( 'ABSPATH' ) || exit;

define( 'WOW_BLOCKS_VERSION', '0.1.14' );
define( 'WOW_BLOCKS_FILE', __FILE__ );
define( 'WOW_BLOCKS_DIR', plugin_dir_path( __FILE__ ) );
define( 'WOW_BLOCKS_URL', plugin_dir_url( __FILE__ ) );

require_once WOW_BLOCKS_DIR . 'includes/config.php';
require_once WOW_BLOCKS_DIR . 'includes/image-sizes.php';
require_once WOW_BLOCKS_DIR . 'includes/render-helpers.php';
require_once WOW_BLOCKS_DIR . 'includes/albums.php';
require_once WOW_BLOCKS_DIR . 'includes/duplicate.php';
require_once WOW_BLOCKS_DIR . 'includes/blocks.php';

/**
 * Loads the plugin translations.
 *
 * Runs on init rather than plugins_loaded: since WordPress 6.7 a text domain
 * loaded before init triggers a _doing_it_wrong() notice.
 */
function load_textdomain(): void {
	load_plugin_textdomain( 'wow-blocks', false, dirname( plugin_basename( WOW_BLOCKS_FILE ) ) . '/languages' );
}
add_action( 'init', __NAMESPACE__ . '\\load_textdomain' );

/**
 * Registers a dedicated inserter category so the WOW blocks sit together.
 *
 * @param array<int,array<string,mixed>> $categories Block categories.
 * @return array<int,array<string,mixed>>
 */
function block_category( array $categories ): array {
	array_unshift(
		$categories,
		array(
			'slug'  => 'wow',
			'title' => __( 'WOW', 'wow-blocks' ),
			'icon'  => null,
		)
	);

	return $categories;
}
add_filter( 'block_categories_all', __NAMESPACE__ . '\\block_category' );

/**
 * Warns when the plugin has not been built.
 */
function build_notice(): void {
	if ( file_exists( WOW_BLOCKS_DIR . 'build/media-row/block.json' ) ) {
		return;
	}

	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-warning"><p>%s <code>npm install &amp;&amp; npm run build</code></p></div>',
		esc_html__( 'WOW Blocks: the blocks are not built. Run this in the plugin folder:', 'wow-blocks' )
	);
}
add_action( 'admin_notices', __NAMESPACE__ . '\\build_notice' );
