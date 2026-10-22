<?php
/**
 * Fetching tiles and stitching them into one picture.
 *
 * This is the only file in the plugin that talks to the network, and it is
 * only ever reached from the admin or from WP-CLI. A visitor reading a page
 * never gets here: by then the picture is a file on this server like any
 * other.
 *
 * @package WOW\Map
 */

namespace WOW\Map\Tiles;

use WOW\Map\Geo;
use WOW\Map\Storage;

defined( 'ABSPATH' ) || exit;

/** Where the tiles come from. */
const TILE_URL = 'https://tile.openstreetmap.org/%1$d/%2$d/%3$d.png';

/** How long a failed attempt is remembered, in seconds. */
const FAILURE_TTL = 600;

/** How long one drawing may hold the lock, in seconds. */
const LOCK_TTL = 60;

/**
 * The most tiles one picture may ask for.
 *
 * A guard on somebody else's server rather than on ours. A wide picture at a
 * fractional zoom quietly needs several times the tiles of a small one, and
 * the point at which that stops being a reasonable thing to ask a free tile
 * service for should be stated, not discovered.
 */
const MAX_TILES = 48;

/**
 * How this plugin introduces itself to the tile server.
 *
 * Not a nicety — the single most important line in the file. Asked with a
 * faceless user agent, the tile server answers **HTTP 200 with a picture**
 * that is not a map at all but a "blocked" placeholder, marked only by an
 * x-blocked header. Send something that identifies the site, and the same
 * request returns the real tile.
 *
 * The site's address goes in, the administrator's email does not: the usage
 * policy asks for a way to recognise the caller, and a public URL does that
 * without handing over somebody's personal address. A filter is there for
 * anyone who would rather add contact details.
 *
 * @return string User agent.
 */
function user_agent(): string {
	$agent = sprintf( 'wow-map/%s (+%s)', WOW_MAP_VERSION, home_url( '/' ) );

	/**
	 * Filters how the plugin identifies itself to the tile server.
	 *
	 * @param string $agent User agent string.
	 */
	return (string) apply_filters( 'wow_map_user_agent', $agent );
}

/**
 * Fetches one tile.
 *
 * Note what counts as success here, because the obvious test is the wrong
 * one. A refused request comes back as 200 with a valid PNG body; the thing
 * that gives it away is the x-blocked header. So the status code is checked,
 * and then the header is checked, and only a response with neither problem
 * is believed.
 *
 * @param int $zoom   Zoom level.
 * @param int $column Tile column.
 * @param int $row    Tile row.
 * @return \GdImage|\WP_Error The tile, or why it could not be had.
 */
function fetch( int $zoom, int $column, int $row ) {
	$url = sprintf( TILE_URL, $zoom, $column, $row );

	$response = wp_remote_get(
		$url,
		array(
			'timeout'    => 15,
			'user-agent' => user_agent(),
			'headers'    => array( 'Accept' => 'image/png' ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return new \WP_Error(
			'wow_map_unreachable',
			sprintf(
				/* translators: 1: tile address, 2: error message. */
				__( 'Could not reach %1$s: %2$s', 'wow-map' ),
				$url,
				$response->get_error_message()
			)
		);
	}

	$code = (int) wp_remote_retrieve_response_code( $response );

	if ( 200 !== $code ) {
		return new \WP_Error(
			'wow_map_http',
			sprintf(
				/* translators: 1: tile address, 2: HTTP status code. */
				__( '%1$s answered with status %2$d.', 'wow-map' ),
				$url,
				$code
			)
		);
	}

	if ( '' !== (string) wp_remote_retrieve_header( $response, 'x-blocked' ) ) {
		return new \WP_Error(
			'wow_map_blocked',
			__( 'The tile server refused the request and sent a placeholder picture instead. This happens when it does not recognise who is asking — see the wow_map_user_agent filter, and the tile usage policy at https://operations.osmfoundation.org/policies/tiles/', 'wow-map' )
		);
	}

	$type = (string) wp_remote_retrieve_header( $response, 'content-type' );

	if ( ! str_starts_with( $type, 'image/' ) ) {
		return new \WP_Error(
			'wow_map_not_an_image',
			sprintf(
				/* translators: 1: tile address, 2: content type received. */
				__( '%1$s returned %2$s instead of a picture.', 'wow-map' ),
				$url,
				'' === $type ? 'nothing' : $type
			)
		);
	}

	$tile = @imagecreatefromstring( wp_remote_retrieve_body( $response ) );

	if ( ! $tile instanceof \GdImage ) {
		return new \WP_Error(
			'wow_map_unreadable',
			sprintf(
				/* translators: %s: tile address. */
				__( 'The picture at %s could not be read.', 'wow-map' ),
				$url
			)
		);
	}

	return $tile;
}

/**
 * The colour of the marker, as three numbers.
 *
 * @return array{0:int,1:int,2:int} Red, green, blue.
 */
function marker_colour(): array {
	/**
	 * Filters the colour of the marker.
	 *
	 * @param string $colour Six-digit hex colour, with or without the hash.
	 */
	$hex = (string) apply_filters( 'wow_map_marker_colour', '#d93025' );
	$hex = ltrim( $hex, '#' );

	if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
		$hex = 'd93025';
	}

	return array(
		(int) hexdec( substr( $hex, 0, 2 ) ),
		(int) hexdec( substr( $hex, 2, 2 ) ),
		(int) hexdec( substr( $hex, 4, 2 ) ),
	);
}

/**
 * Draws one teardrop: a circle with a cone hanging off the bottom of it.
 *
 * The cone is not a triangle drawn to the edges of the circle, which would
 * leave two corners sticking out sideways. Its two upper corners are the
 * points where a line from the tip just grazes the circle, so the straight
 * sides run into the curve without a seam.
 *
 * @param \GdImage $image  Canvas to draw on.
 * @param float    $centre_x Middle of the head.
 * @param float    $centre_y Middle of the head.
 * @param float    $radius   Radius of the head.
 * @param float    $drop     Distance from the middle of the head down to the tip.
 * @param int      $colour   Colour to fill with.
 */
function teardrop( \GdImage $image, float $centre_x, float $centre_y, float $radius, float $drop, int $colour ): void {
	imagefilledellipse(
		$image,
		(int) round( $centre_x ),
		(int) round( $centre_y ),
		(int) round( $radius * 2 ),
		(int) round( $radius * 2 ),
		$colour
	);

	// Where the tangent from the tip touches the head.
	$reach = sqrt( $drop * $drop - $radius * $radius );
	$touch_x = $radius * $reach / $drop;
	$touch_y = $radius * $radius / $drop;

	imagefilledpolygon(
		$image,
		array(
			(int) round( $centre_x - $touch_x ), (int) round( $centre_y + $touch_y ),
			(int) round( $centre_x + $touch_x ), (int) round( $centre_y + $touch_y ),
			(int) round( $centre_x ),            (int) round( $centre_y + $drop ),
		),
		$colour
	);
}

/**
 * Drops a pin on the picture.
 *
 * The coordinates given are where the **tip** goes, not the middle of the
 * pin. That is the whole point of this shape rather than a dot: a pin says
 * "this exact spot, down here", and the body hangs above it out of the way
 * of what it is pointing at.
 *
 * Drawn four times larger on a layer of its own and then shrunk onto the map.
 * GD fills shapes with hard pixel edges and no antialiasing setting changes
 * that; shrinking a large one is what turns the staircase into a smooth edge.
 *
 * @param \GdImage $map  The picture to draw on.
 * @param int      $x    Horizontal position of the tip.
 * @param int      $y    Vertical position of the tip.
 */
function marker( \GdImage $map, int $x, int $y ): void {
	$scale  = 4;
	$radius = 10;
	$drop   = 21;
	$edge   = 1;

	$width  = ( $radius + $edge ) * 2;
	$height = $radius + $edge + $drop + $edge;

	$layer = imagecreatetruecolor( $width * $scale, $height * $scale );
	imagealphablending( $layer, false );
	imagesavealpha( $layer, true );
	imagefill( $layer, 0, 0, imagecolorallocatealpha( $layer, 0, 0, 0, 127 ) );
	imagealphablending( $layer, true );

	list( $red, $green, $blue ) = marker_colour();

	$body = imagecolorallocate( $layer, $red, $green, $blue );
	$hole = imagecolorallocate( $layer, 255, 255, 255 );

	/*
	 * A darker edge of the same hue, drawn underneath and one pixel wider.
	 * Without it the pin dissolves into anything reddish it happens to land
	 * on, and map tiles are full of reddish roads.
	 */
	$rim = imagecolorallocate(
		$layer,
		(int) round( $red * 0.7 ),
		(int) round( $green * 0.7 ),
		(int) round( $blue * 0.7 )
	);

	$head_x = $width * $scale / 2;
	$head_y = ( $radius + $edge ) * $scale;

	teardrop( $layer, $head_x, $head_y, ( $radius + $edge ) * $scale, ( $drop + $edge ) * $scale, $rim );
	teardrop( $layer, $head_x, $head_y, $radius * $scale, $drop * $scale, $body );

	imagefilledellipse(
		$layer,
		(int) round( $head_x ),
		(int) round( $head_y ),
		(int) round( 8.4 * $scale ),
		(int) round( 8.4 * $scale ),
		$hole
	);

	imagealphablending( $map, true );
	imagecopyresampled(
		$map,
		$layer,
		(int) ( $x - $width / 2 ),
		(int) ( $y - ( $radius + $edge + $drop ) ),
		0,
		0,
		$width,
		$height,
		$width * $scale,
		$height * $scale
	);

	imagedestroy( $layer );
}

/**
 * Draws one map and writes it to disk.
 *
 * @param float $lat    Latitude.
 * @param float $lon    Longitude.
 * @param float $zoom   Zoom level.
 * @param int   $width  Width in pixels.
 * @param int   $height Height in pixels.
 * @return true|\WP_Error true once the file is written.
 */
function render( float $lat, float $lon, float $zoom, int $width, int $height ) {
	if ( ! function_exists( 'imagecreatetruecolor' ) ) {
		return new \WP_Error( 'wow_map_no_gd', __( 'This server has no GD, so no picture can be drawn.', 'wow-map' ) );
	}

	if ( ! Storage\ensure_dir() ) {
		return new \WP_Error(
			'wow_map_unwritable',
			sprintf(
				/* translators: %s: folder path. */
				__( 'The folder %s could not be created or written to.', 'wow-map' ),
				Storage\dir()
			)
		);
	}

	/*
	 * Tiles exist at whole zoom levels only. For a fractional zoom the tiles
	 * of the level above are fetched over a correspondingly larger area, and
	 * the result is shrunk to the size asked for. At a whole zoom the scale
	 * is exactly one and the sheet is the picture, untouched.
	 */
	$source = Geo\source_zoom( $zoom );
	$scale  = Geo\scale( $zoom, $source );

	$sheet_width  = (int) round( $width / $scale );
	$sheet_height = (int) round( $height / $scale );

	$frame = Geo\frame( $lat, $lon, $source, $sheet_width, $sheet_height );

	if ( count( $frame['tiles'] ) > MAX_TILES ) {
		return new \WP_Error(
			'wow_map_too_many_tiles',
			sprintf(
				/* translators: 1: number of tiles needed, 2: the most allowed. */
				__( 'This map would need %1$d tiles and the limit is %2$d. Ask for a smaller picture or a lower zoom.', 'wow-map' ),
				count( $frame['tiles'] ),
				MAX_TILES
			)
		);
	}

	$sheet = imagecreatetruecolor( $sheet_width, $sheet_height );

	// The background OpenStreetMap itself uses, so a missing tile is not a hole.
	imagefilledrectangle( $sheet, 0, 0, $sheet_width - 1, $sheet_height - 1, imagecolorallocate( $sheet, 242, 239, 233 ) );

	foreach ( $frame['tiles'] as $tile ) {
		$image = fetch( $tile['z'], $tile['x'], $tile['y'] );

		if ( is_wp_error( $image ) ) {
			imagedestroy( $sheet );

			return $image;
		}

		imagecopy( $sheet, $image, $tile['dx'], $tile['dy'], 0, 0, Geo\TILE_SIZE, Geo\TILE_SIZE );
		imagedestroy( $image );
	}

	if ( $sheet_width === $width && $sheet_height === $height ) {
		$canvas = $sheet;
	} else {
		$canvas = imagecreatetruecolor( $width, $height );

		imagecopyresampled( $canvas, $sheet, 0, 0, 0, 0, $width, $height, $sheet_width, $sheet_height );
		imagedestroy( $sheet );
	}

	// After the shrinking, so the pin is the same size whatever the zoom.
	marker( $canvas, (int) ( $width / 2 ), (int) ( $height / 2 ) );

	$path    = Storage\path( $lat, $lon, $zoom, $width, $height );
	$written = imagepng( $canvas, $path );

	imagedestroy( $canvas );

	if ( ! $written ) {
		return new \WP_Error(
			'wow_map_not_written',
			sprintf(
				/* translators: %s: file path. */
				__( 'The picture could not be written to %s.', 'wow-map' ),
				$path
			)
		);
	}

	return true;
}

/**
 * Name of the note remembering that one map refused to be drawn.
 *
 * @param string $file File name of the map.
 * @return string Transient name.
 */
function failure_key( string $file ): string {
	return 'wow_map_failed_' . md5( $file );
}

/**
 * Makes sure one map exists, drawing it if it does not.
 *
 * Two guards, both about not being a nuisance. A failure is remembered for a
 * few minutes, so a server that is down does not get a fresh round of
 * requests every time the editor redraws the block; and a lock keeps two
 * requests from drawing the same picture at the same time.
 *
 * @param float $lat    Latitude.
 * @param float $lon    Longitude.
 * @param float $zoom   Zoom level.
 * @param int   $width  Width in pixels.
 * @param int   $height Height in pixels.
 * @param bool  $force  Draw again even if the file is already there.
 * @return true|\WP_Error true when the file is in place.
 */
function ensure( float $lat, float $lon, float $zoom, int $width, int $height, bool $force = false ) {
	if ( ! $force && Storage\exists( $lat, $lon, $zoom, $width, $height ) ) {
		return true;
	}

	$file    = Storage\name( $lat, $lon, $zoom, $width, $height );
	$failure = get_transient( failure_key( $file ) );

	if ( ! $force && is_string( $failure ) ) {
		return new \WP_Error( 'wow_map_recently_failed', $failure );
	}

	$lock = 'wow_map_lock_' . md5( $file );

	if ( false !== get_transient( $lock ) ) {
		return new \WP_Error( 'wow_map_busy', __( 'This map is being drawn right now. Reload in a moment.', 'wow-map' ) );
	}

	set_transient( $lock, time(), LOCK_TTL );

	$result = render( $lat, $lon, $zoom, $width, $height );

	delete_transient( $lock );

	if ( is_wp_error( $result ) ) {
		set_transient( failure_key( $file ), $result->get_error_message(), FAILURE_TTL );

		return $result;
	}

	delete_transient( failure_key( $file ) );

	return true;
}
