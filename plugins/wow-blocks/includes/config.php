<?php
/**
 * The single source of truth for WOW block geometry.
 *
 * Change the numbers here and the registered image sizes, the CSS custom
 * properties and the editor hints all follow.
 *
 * @package WOW\Blocks
 */

namespace WOW\Blocks\Config;

defined( 'ABSPATH' ) || exit;

/** Maximum width of any row, in px. */
const MAX_WIDTH = 754;

/** Height of an image row, in px. */
const ROW_HEIGHT = 350;

/** Height of the narrow strip, in px. */
const BAND_HEIGHT = 97;

/**
 * Base gap, in px.
 *
 * The same value between columns horizontally and between rows vertically,
 * so the grid keeps a single rhythm.
 *
 * Measuring the live site gave roughly 32; halved at the client's request.
 */
const DEFAULT_GAP = 16;

/**
 * Space above the narrow strip, in px.
 *
 * The narrow strip separates topics, so it is set off by a larger gap
 * rather than the base one.
 */
const DEFAULT_BAND_LEAD_GAP = 32;

/** Default image width inside the narrow strip, in px. */
const DEFAULT_BAND_IMAGE_WIDTH = 105;

/**
 * Reference gap the hard crops are computed against.
 *
 * Column widths on the front end are computed in CSS via calc() and depend
 * on the chosen gap, while the files are cropped once against this
 * reference gap. The mismatch is a couple of percent at most and is covered
 * by the 2x variants.
 */
const CROP_GAP = DEFAULT_GAP;

/** Width of an album tile on the front page, in px. */
const ALBUM_TILE_WIDTH = 240;

/** How many tiles fit in a row on a wide screen. */
const ALBUM_MAX_COLUMNS = 5;

/** Side of the album tile crop, in px. The tile is square. */
const ALBUM_TILE_CROP = 320;

/**
 * Column widths in px at the reference gap.
 *
 * narrow = (754 - 2*16) / 3 = 240 (rounded down)
 * wide   = 754 - 16 - 240   = 498
 *
 * A third does not divide evenly at every gap. We round down: WordPress
 * simply does not create a crop larger than the source, and the image is
 * then silently replaced by something small. Losing a fraction of a pixel
 * goes unnoticed; a missing crop does not.
 *
 * @return array<string,int>
 */
function column_widths(): array {
	$narrow = (int) ( ( MAX_WIDTH - 2 * CROP_GAP ) / 3 );

	return array(
		'full'   => MAX_WIDTH,
		'wide'   => MAX_WIDTH - CROP_GAP - $narrow,
		'narrow' => $narrow,
	);
}

/**
 * Row layouts: which columns, in which order.
 *
 * @return array<string,array{columns:string[]}>
 */
function layouts(): array {
	return array(
		'single'           => array( 'columns' => array( 'full' ) ),
		'two-narrow-left'  => array( 'columns' => array( 'narrow', 'wide' ) ),
		'two-narrow-right' => array( 'columns' => array( 'wide', 'narrow' ) ),
		'three'            => array( 'columns' => array( 'narrow', 'narrow', 'narrow' ) ),
	);
}

/**
 * How many cells a layout has.
 */
function cell_count( string $layout ): int {
	$layouts = layouts();

	return isset( $layouts[ $layout ] ) ? count( $layouts[ $layout ]['columns'] ) : 0;
}

/**
 * Slug of the registered image size for a column type.
 */
function image_size_for_column( string $column ): string {
	return 'wow-col-' . $column;
}
