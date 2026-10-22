<?php
/**
 * Registration of the hard crops used by the columns.
 *
 * @package WOW\Blocks
 */

namespace WOW\Blocks\ImageSizes;

use function WOW\Blocks\Config\column_widths;
use function WOW\Blocks\Config\image_size_for_column;

use const WOW\Blocks\Config\ALBUM_TILE_CROP;
use const WOW\Blocks\Config\BAND_HEIGHT;
use const WOW\Blocks\Config\MAX_WIDTH;
use const WOW\Blocks\Config\ROW_HEIGHT;

defined( 'ABSPATH' ) || exit;

/**
 * Slug of the source size for the narrow strip.
 *
 * The image width inside the narrow strip is arbitrary, so a hard crop does
 * not fit here: we resize by width only and frame it in CSS through
 * object-fit and the focal point. The file is tiny either way.
 */
const BAND_SOURCE_SIZE = 'wow-band-src';

/** Square crop for an album tile. */
const ALBUM_TILE_SIZE = 'wow-album-tile';

/**
 * Every size the plugin registers.
 *
 * @return array<string,array{width:int,height:int,crop:bool}>
 */
function sizes(): array {
	$sizes = array();

	foreach ( column_widths() as $column => $width ) {
		$slug = image_size_for_column( $column );

		$sizes[ $slug ] = array(
			'width'  => $width,
			'height' => ROW_HEIGHT,
			'crop'   => true,
		);

		// 2x at the same ratio; WordPress puts it in the srcset by itself.
		$sizes[ $slug . '-2x' ] = array(
			'width'  => $width * 2,
			'height' => ROW_HEIGHT * 2,
			'crop'   => true,
		);
	}

	// Square album cover for the front-page grid.
	$sizes[ ALBUM_TILE_SIZE ]          = array(
		'width'  => ALBUM_TILE_CROP,
		'height' => ALBUM_TILE_CROP,
		'crop'   => true,
	);
	$sizes[ ALBUM_TILE_SIZE . '-2x' ] = array(
		'width'  => ALBUM_TILE_CROP * 2,
		'height' => ALBUM_TILE_CROP * 2,
		'crop'   => true,
	);

	$sizes[ BAND_SOURCE_SIZE ]          = array(
		'width'  => MAX_WIDTH,
		'height' => 9999,
		'crop'   => false,
	);
	$sizes[ BAND_SOURCE_SIZE . '-2x' ] = array(
		'width'  => MAX_WIDTH * 2,
		'height' => 9999,
		'crop'   => false,
	);

	return $sizes;
}

/**
 * Tells whether an intermediate file was cut from the given source file.
 *
 * WordPress names an intermediate file "<source>-<width>x<height>.<ext>", with
 * a number appended when that name is already taken. Editing a picture in the
 * media editor writes a new source file, usually smaller, and the crops that
 * no longer fit inside it cannot be rebuilt — WordPress then leaves their old
 * entries in the metadata, still pointing at files cut from the picture as it
 * was before the edit. The name is the only thing that gives them away.
 *
 * @param string $source Attachment file, as stored in the metadata.
 * @param string $file   File name of a single size entry.
 */
function belongs_to_source( string $source, string $file ): bool {
	$base = pathinfo( $source, PATHINFO_FILENAME );

	if ( '' === $base ) {
		return false;
	}

	// An oversized upload is stored as "<name>-scaled.<ext>" while its crops
	// keep the original name, so both spellings count as the same source.
	$bases = array_unique( array( $base, preg_replace( '/-scaled$/', '', $base ) ) );
	$name  = pathinfo( $file, PATHINFO_FILENAME );

	foreach ( $bases as $candidate ) {
		if ( preg_match( '#^' . preg_quote( $candidate, '#' ) . '-\\d+x\\d+(-\\d+)?$#', $name ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Drops srcset candidates left over from a previous version of the image.
 *
 * The stale entries described in belongs_to_source() have the aspect ratio
 * wp_calculate_image_srcset() looks for, so they end up in the srcset: a wide
 * screen is then served the picture as it was before the edit while a narrow
 * one gets the edited file.
 *
 * @param array<int,array<string,mixed>> $sources    Candidates keyed by width.
 * @param array<int,int>                 $size_array Requested width and height.
 * @param string                         $image_src  Source of the img tag.
 * @param array<string,mixed>            $image_meta Attachment metadata.
 * @return array<int,array<string,mixed>>
 */
function drop_stale_srcset( $sources, $size_array, $image_src, $image_meta ): array {
	if ( ! is_array( $sources ) || empty( $image_meta['file'] ) ) {
		return is_array( $sources ) ? $sources : array();
	}

	$full = wp_basename( (string) $image_meta['file'] );

	foreach ( $sources as $width => $source ) {
		$name = wp_basename( (string) ( $source['url'] ?? '' ) );

		// The source file itself carries no -WxH suffix, so it is let through
		// by name rather than by the rule.
		if ( $name === $full || belongs_to_source( (string) $image_meta['file'], $name ) ) {
			continue;
		}

		unset( $sources[ $width ] );
	}

	return $sources;
}
add_filter( 'wp_calculate_image_srcset', __NAMESPACE__ . '\\drop_stale_srcset', 10, 4 );

/**
 * Core sizes this site never serves.
 *
 * WordPress cuts these out of every upload whether or not anything asks for
 * them. On a site built out of hard crops nothing does: the galleries are
 * drawn from the wow- sizes above, the album covers from wow-album-tile, and
 * the admin from thumbnail and medium. The four below were a fifth of the
 * uploads folder and were sent to nobody.
 *
 * They are also absent from srcsets after this, which is the one visible
 * consequence. The sizes that mattered there — the full-width source and its
 * 2x — are among the ones kept.
 *
 * Already-cut files are not removed by this; it only stops more being made.
 *
 * @var string[]
 */
const UNUSED_CORE_SIZES = array( 'medium_large', 'large', '1536x1536', '2048x2048' );

/**
 * Stops WordPress cutting the sizes above.
 *
 * @param mixed $sizes Sizes WordPress means to create, keyed by slug.
 * @return mixed The same, less the ones nothing asks for.
 */
function drop_unused_core_sizes( $sizes ) {
	return is_array( $sizes ) ? array_diff_key( $sizes, array_flip( UNUSED_CORE_SIZES ) ) : $sizes;
}
add_filter( 'intermediate_image_sizes_advanced', __NAMESPACE__ . '\\drop_unused_core_sizes' );

/**
 * Hooked on after_setup_theme.
 */
function register(): void {
	foreach ( sizes() as $slug => $size ) {
		add_image_size( $slug, $size['width'], $size['height'], $size['crop'] );
	}
}
add_action( 'after_setup_theme', __NAMESPACE__ . '\\register' );

/**
 * The plugin sizes are internal, so they are hidden from the "Image size"
 * dropdown to stop an editor picking one by accident for a regular image.
 *
 * @param array<string,string> $names Human-readable size names.
 * @return array<string,string>
 */
function hide_from_size_dropdown( array $names ): array {
	return array_diff_key( $names, sizes() );
}
add_filter( 'image_size_names_choose', __NAMESPACE__ . '\\hide_from_size_dropdown' );

/**
 * Reminder: existing images do not get the new crops until they are
 * regenerated.
 *
 *   docker compose --profile tools run --rm wpcli media regenerate --yes
 */
