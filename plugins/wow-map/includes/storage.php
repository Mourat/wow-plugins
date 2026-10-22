<?php
/**
 * Where the drawn maps live.
 *
 * Their own folder under uploads, and deliberately not the media library.
 * These are pictures the plugin generates from the block's own settings, not
 * files anybody chose or manages; filing them among several hundred real
 * attachments — which this site sorts into folders by hand — would be
 * clutter, and nothing would ever pick them from a list.
 *
 * @package WOW\Map
 */

namespace WOW\Map\Storage;

defined( 'ABSPATH' ) || exit;

/** Name of the folder inside uploads. */
const FOLDER = 'wow-map';

/**
 * The folder the pictures are written to.
 *
 * @return string Absolute path, without a trailing slash.
 */
function dir(): string {
	$uploads = wp_get_upload_dir();

	return trailingslashit( $uploads['basedir'] ) . FOLDER;
}

/**
 * The address that folder is served from.
 *
 * @return string URL, without a trailing slash.
 */
function url(): string {
	$uploads = wp_get_upload_dir();

	return trailingslashit( $uploads['baseurl'] ) . FOLDER;
}

/**
 * Creates the folder if it is not there yet.
 *
 * @return bool true when the folder exists and can be written to.
 */
function ensure_dir(): bool {
	$dir = dir();

	return wp_mkdir_p( $dir ) && is_writable( $dir );
}

/**
 * The file name one particular map is filed under.
 *
 * Built only out of numbers that have already been through the checks in
 * Geo, and formatted with number_format so that a locale using a comma for
 * the decimal point cannot change the name. Nothing a visitor or an editor
 * types reaches this string unchecked, so there is no way to steer it at a
 * path of somebody's choosing.
 *
 * @param float $lat    Latitude.
 * @param float $lon    Longitude.
 * @param float $zoom   Zoom level.
 * @param int   $width  Width in pixels.
 * @param int   $height Height in pixels.
 * @return string File name, including the extension.
 */
function name( float $lat, float $lon, float $zoom, int $width, int $height ): string {
	/*
	 * Two decimals at most, with the trailing zeros taken off, so a whole
	 * zoom stays "16" and a fractional one reads as "17.5" rather than
	 * "17.50" or, worse, a long tail of floating-point noise.
	 */
	$level = rtrim( rtrim( number_format( $zoom, 2, '.', '' ), '0' ), '.' );

	return sprintf(
		'%s_%s_%s_%dx%d.png',
		number_format( $lat, 6, '.', '' ),
		number_format( $lon, 6, '.', '' ),
		$level,
		$width,
		$height
	);
}

/**
 * Full path of one map.
 *
 * @param float $lat    Latitude.
 * @param float $lon    Longitude.
 * @param float $zoom   Zoom level.
 * @param int   $width  Width in pixels.
 * @param int   $height Height in pixels.
 * @return string Absolute path.
 */
function path( float $lat, float $lon, float $zoom, int $width, int $height ): string {
	return dir() . '/' . name( $lat, $lon, $zoom, $width, $height );
}

/**
 * Address of one map, with a version on the end.
 *
 * The name of the file is fixed by where the map is and how big it is, so
 * redrawing the same map — a new marker, a corrected coordinate — writes new
 * content to the same name, and a browser that has been there before would go
 * on showing the old picture. The modification time settles it, the same way
 * the stylesheets here are versioned.
 *
 * @param float $lat    Latitude.
 * @param float $lon    Longitude.
 * @param float $zoom   Zoom level.
 * @param int   $width  Width in pixels.
 * @param int   $height Height in pixels.
 * @return string URL.
 */
function file_url( float $lat, float $lon, float $zoom, int $width, int $height ): string {
	$address = url() . '/' . name( $lat, $lon, $zoom, $width, $height );
	$drawn   = @filemtime( path( $lat, $lon, $zoom, $width, $height ) );

	return false === $drawn ? $address : add_query_arg( 'v', (string) $drawn, $address );
}

/**
 * Whether one map has already been drawn.
 *
 * @param float $lat    Latitude.
 * @param float $lon    Longitude.
 * @param float $zoom   Zoom level.
 * @param int   $width  Width in pixels.
 * @param int   $height Height in pixels.
 * @return bool true when the file is on disk and not empty.
 */
function exists( float $lat, float $lon, float $zoom, int $width, int $height ): bool {
	$path = path( $lat, $lon, $zoom, $width, $height );

	return is_readable( $path ) && filesize( $path ) > 0;
}

/**
 * Every map currently on disk.
 *
 * @return array<int,string> File names.
 */
function all(): array {
	$files = glob( dir() . '/*.png' );

	return false === $files ? array() : array_map( 'basename', $files );
}
