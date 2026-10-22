<?php
/**
 * Turning a place on the globe into pixels on a canvas.
 *
 * The Web Mercator projection every slippy map uses: the world is one square
 * picture, 256 pixels across at zoom 0 and twice that at every zoom after,
 * cut into 256-pixel tiles. Everything here is arithmetic — nothing in this
 * file touches the network or the disk.
 *
 * @package WOW\Map
 */

namespace WOW\Map\Geo;

defined( 'ABSPATH' ) || exit;

/** Side of one tile, in pixels. Fixed by the tile server, not by us. */
const TILE_SIZE = 256;

/**
 * The deepest zoom the standard OpenStreetMap layer renders.
 *
 * Checked against the server rather than assumed: a tile at zoom 19 comes
 * back normally, and the same place at zoom 20 answers HTTP 400. Asking for
 * more than this cannot be made to work by trying harder.
 */
const MAX_ZOOM = 19;

/**
 * The latitude where the projection gives up.
 *
 * Mercator stretches towards the poles without limit, so every slippy map
 * cuts the world off at this parallel to keep it square.
 */
const MAX_LATITUDE = 85.05112878;

/**
 * Reads a latitude, whatever shape it arrived in.
 *
 * @param mixed $value Latitude as typed.
 * @return float Latitude within the range the projection can draw.
 */
function latitude( $value ): float {
	return max( -MAX_LATITUDE, min( MAX_LATITUDE, (float) $value ) );
}

/**
 * Reads a longitude, whatever shape it arrived in.
 *
 * @param mixed $value Longitude as typed.
 * @return float Longitude between -180 and 180.
 */
function longitude( $value ): float {
	return max( -180.0, min( 180.0, (float) $value ) );
}

/**
 * Reads a zoom level.
 *
 * Fractions are allowed and are worth having: whole zoom levels step by a
 * factor of two, which is a big jump when the difference between "the street"
 * and "the district" falls somewhere in between.
 *
 * @param mixed $value Zoom as typed.
 * @return float Zoom between 0 and MAX_ZOOM.
 */
function zoom( $value ): float {
	return max( 0.0, min( (float) MAX_ZOOM, (float) $value ) );
}

/**
 * The whole zoom level the tiles are actually fetched at.
 *
 * Tiles exist at whole zoom levels only. A fractional zoom is drawn by taking
 * the level **above** it and shrinking the result — rounding up rather than
 * down on purpose, because shrinking a larger picture stays sharp while
 * blowing up a smaller one only makes it soft.
 *
 * @param float $zoom Requested zoom.
 * @return int Zoom level to fetch tiles at.
 */
function source_zoom( float $zoom ): int {
	return (int) min( MAX_ZOOM, (int) ceil( $zoom - 0.000001 ) );
}

/**
 * How much the fetched picture has to shrink.
 *
 * Always greater than a half and at most one: the level above a fraction is
 * never more than one step away.
 *
 * @param float $zoom   Requested zoom.
 * @param int   $source Zoom the tiles come from.
 * @return float Factor to scale the fetched picture by.
 */
function scale( float $zoom, int $source ): float {
	return 2 ** ( $zoom - $source );
}

/**
 * Reads one side of the picture.
 *
 * The ceiling is not arbitrary: a picture is stitched out of tiles fetched
 * one by one from somebody else's server, and the wider it is the more of
 * their bandwidth one block costs.
 *
 * @param mixed $value Width or height as typed.
 * @return int A side between 16 and 1280 pixels.
 */
function side( $value ): int {
	return max( 16, min( 1280, (int) $value ) );
}

/**
 * Where a place falls on the world picture, in pixels.
 *
 * @param float $lat  Latitude.
 * @param float $lon  Longitude.
 * @param int   $zoom Zoom level.
 * @return array{x:float,y:float} Pixel position on the whole world at this zoom.
 */
function point( float $lat, float $lon, int $zoom ): array {
	$world = (float) TILE_SIZE * 2 ** $zoom;
	$rad   = deg2rad( $lat );

	return array(
		'x' => ( $lon + 180.0 ) / 360.0 * $world,
		'y' => ( 1.0 - log( tan( $rad ) + 1.0 / cos( $rad ) ) / M_PI ) / 2.0 * $world,
	);
}

/**
 * Everything needed to stitch one picture.
 *
 * Returns the tiles to fetch and where the first of them has to be pasted so
 * that the requested place lands exactly in the middle. The offsets come back
 * negative, because the first tile almost always starts a little outside the
 * left-hand and top edges of the picture.
 *
 * @param float $lat    Latitude of the centre.
 * @param float $lon    Longitude of the centre.
 * @param int   $zoom   Zoom level.
 * @param int   $width  Width of the picture, in pixels.
 * @param int   $height Height of the picture, in pixels.
 * @return array{tiles:array<int,array{z:int,x:int,y:int,dx:int,dy:int}>,columns:int,rows:int}
 */
function frame( float $lat, float $lon, int $zoom, int $width, int $height ): array {
	$centre = point( $lat, $lon, $zoom );
	$count  = 1 << $zoom;

	$left = $centre['x'] - $width / 2.0;
	$top  = $centre['y'] - $height / 2.0;

	$first_column = (int) floor( $left / TILE_SIZE );
	$last_column  = (int) floor( ( $left + $width - 1 ) / TILE_SIZE );
	$first_row    = (int) floor( $top / TILE_SIZE );
	$last_row     = (int) floor( ( $top + $height - 1 ) / TILE_SIZE );

	$tiles = array();

	for ( $column = $first_column; $column <= $last_column; $column++ ) {
		for ( $row = $first_row; $row <= $last_row; $row++ ) {
			/*
			 * East and west meet, so a column outside the world wraps round to
			 * the other side. North and south do not: a row outside the world
			 * is simply not there, and is skipped rather than wrapped.
			 */
			if ( $row < 0 || $row >= $count ) {
				continue;
			}

			$tiles[] = array(
				'z'  => $zoom,
				'x'  => ( ( $column % $count ) + $count ) % $count,
				'y'  => $row,
				'dx' => (int) round( $column * TILE_SIZE - $left ),
				'dy' => (int) round( $row * TILE_SIZE - $top ),
			);
		}
	}

	return array(
		'tiles'   => $tiles,
		'columns' => $last_column - $first_column + 1,
		'rows'    => $last_row - $first_row + 1,
	);
}
