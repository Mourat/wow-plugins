<?php
/**
 * Block registration and the hand-off of the geometry to CSS and JS.
 *
 * The numbers live in includes/config.php and nowhere else. From here they
 * travel into CSS custom properties and into window.wowBlocksConfig, so the
 * editor and the front end cannot drift apart.
 *
 * @package WOW\Blocks
 */

namespace WOW\Blocks\Blocks;

use function WOW\Blocks\Config\column_widths;
use function WOW\Blocks\Config\layouts;

use const WOW\Blocks\Config\ALBUM_MAX_COLUMNS;
use const WOW\Blocks\Config\ALBUM_TILE_WIDTH;
use const WOW\Blocks\Config\BAND_HEIGHT;
use const WOW\Blocks\Config\DEFAULT_BAND_IMAGE_WIDTH;
use const WOW\Blocks\Config\DEFAULT_BAND_LEAD_GAP;
use const WOW\Blocks\Config\DEFAULT_GAP;
use const WOW\Blocks\Config\MAX_WIDTH;
use const WOW\Blocks\Config\ROW_HEIGHT;

defined( 'ABSPATH' ) || exit;

/** The blocks this plugin registers. */
const BLOCKS = array( 'media-row', 'media-band', 'album-grid', 'album-list' );

/**
 * Hooked on init.
 */
function register(): void {
	$build_dir = WOW_BLOCKS_DIR . 'build/';

	foreach ( BLOCKS as $block ) {
		$metadata = $build_dir . $block;

		if ( ! file_exists( $metadata . '/block.json' ) ) {
			continue;
		}

		register_block_type( $metadata );

		// Translations for this block's editor interface. The handle is the
		// one register_block_type() derives from block.json.
		wp_set_script_translations( 'wow-' . $block . '-editor-script', 'wow-blocks', WOW_BLOCKS_DIR . 'languages' );
	}

	// Right here on init, so the carrier of the custom properties also lands
	// in the temporary WP_Styles copy the editor makes later for its iframe.
	register_tokens_style();
}
add_action( 'init', __NAMESPACE__ . '\\register' );

/**
 * The geometry in a shape usable from both CSS and JS.
 *
 * @return array<string,mixed>
 */
function geometry(): array {
	return array(
		'maxWidth'             => MAX_WIDTH,
		'rowHeight'            => ROW_HEIGHT,
		'bandHeight'           => BAND_HEIGHT,
		'defaultGap'           => DEFAULT_GAP,
		'defaultBandLeadGap'   => DEFAULT_BAND_LEAD_GAP,
		'defaultBandImageWidth' => DEFAULT_BAND_IMAGE_WIDTH,
		'albumTileWidth'       => ALBUM_TILE_WIDTH,
		'albumMaxColumns'      => ALBUM_MAX_COLUMNS,
		'columnWidths'         => column_widths(),
		'layouts'              => array_map(
			static fn( array $layout ): array => $layout['columns'],
			layouts()
		),
	);
}

/**
 * CSS custom properties carrying the geometry.
 */
function inline_custom_properties(): string {
	$geometry = geometry();

	return sprintf(
		':root{--wow-max-width:%dpx;--wow-row-height:%dpx;--wow-band-height:%dpx;'
		. '--wow-gap:%dpx;--wow-band-lead:%dpx;--wow-album-tile:%dpx;--wow-album-columns:%d;}',
		$geometry['maxWidth'],
		$geometry['rowHeight'],
		$geometry['bandHeight'],
		$geometry['defaultGap'],
		$geometry['defaultBandLeadGap'],
		$geometry['albumTileWidth'],
		$geometry['albumMaxColumns']
	);
}

/** Handle of the style that carries the custom properties. */
const TOKENS_HANDLE = 'wow-blocks-tokens';

/**
 * The custom properties live in a separate, file-less style that every
 * block style depends on.
 *
 * They used to be appended to the style of whichever block registered first,
 * so on a page without that block — the front page with nothing but the
 * album grid, say — the properties never arrived at all.
 *
 * The function is idempotent and looks at the current WP_Styles instance
 * rather than at a static flag. The iframed editor spins up a second
 * WP_Styles and copies the registered array into it, while the dependency
 * objects themselves stay shared. With a static flag the deps were appended
 * to those shared objects, yet the carrier style was registered only in the
 * temporary copy and vanished along with it. The main instance was left
 * holding a reference to nothing, and core silently dropped the block styles
 * from the queue.
 */
function register_tokens_style(): void {
	$styles = wp_styles();

	if ( ! isset( $styles->registered[ TOKENS_HANDLE ] ) ) {
		wp_register_style( TOKENS_HANDLE, false, array(), WOW_BLOCKS_VERSION );
		wp_add_inline_style( TOKENS_HANDLE, inline_custom_properties() );
	}

	foreach ( BLOCKS as $block ) {
		foreach ( array( '-style', '-editor-style' ) as $suffix ) {
			$handle = 'wow-' . $block . $suffix;

			if ( ! isset( $styles->registered[ $handle ] ) ) {
				continue;
			}

			if ( ! in_array( TOKENS_HANDLE, $styles->registered[ $handle ]->deps, true ) ) {
				$styles->registered[ $handle ]->deps[] = TOKENS_HANDLE;
			}
		}
	}
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\register_tokens_style', 20 );
add_action( 'enqueue_block_assets', __NAMESPACE__ . '\\register_tokens_style', 20 );

/**
 * Versions the block styles by file modification time.
 *
 * Core versions scripts by the content hash in index.asset.php, but styles
 * by the version field from block.json. That field is static, so edited CSS
 * would reach the browser under the old ?ver= and stay cached.
 *
 * @param string $src    Style URL.
 * @param string $handle Style handle.
 */
function version_block_styles( string $src, string $handle ): string {
	static $map = null;

	if ( null === $map ) {
		$map = array();

		foreach ( BLOCKS as $block ) {
			$map[ 'wow-' . $block . '-style' ]        = 'build/' . $block . '/style-index.css';
			$map[ 'wow-' . $block . '-editor-style' ] = 'build/' . $block . '/index.css';
		}
	}

	if ( ! isset( $map[ $handle ] ) ) {
		return $src;
	}

	$file = WOW_BLOCKS_DIR . $map[ $handle ];

	if ( ! file_exists( $file ) ) {
		return $src;
	}

	return add_query_arg( 'ver', (string) filemtime( $file ), remove_query_arg( 'ver', $src ) );
}
add_filter( 'style_loader_src', __NAMESPACE__ . '\\version_block_styles', 10, 2 );

/**
 * Hands the geometry to the editor.
 */
function enqueue_editor_config(): void {
	foreach ( BLOCKS as $block ) {
		$handle = 'wow-' . $block . '-editor-script';

		if ( wp_script_is( $handle, 'registered' ) ) {
			wp_add_inline_script(
				$handle,
				'window.wowBlocksConfig = ' . wp_json_encode( geometry() ) . ';',
				'before'
			);

			return;
		}
	}
}
add_action( 'enqueue_block_editor_assets', __NAMESPACE__ . '\\enqueue_editor_config', 5 );
