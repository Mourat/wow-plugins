<?php
/**
 * WP-CLI commands.
 *
 * Drawing a map normally happens by itself, the moment an editor puts the
 * block on a page. These are for the times it has to happen deliberately: a
 * site moved to a new server with an empty uploads folder, a map that has to
 * be redrawn because OpenStreetMap now knows about the building, or a tidy-up
 * after the coordinates changed.
 *
 * @package WOW\Map
 */

namespace WOW\Map\CLI;

use WOW\Map\Block;
use WOW\Map\Storage;
use WOW\Map\Tiles;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Every map asked for by a block anywhere on the site.
 *
 * Walks into nested blocks rather than only looking at the top level: on this
 * site the map sits inside a column inside a columns block, and a scan that
 * only read the outer layer would find nothing at all.
 *
 * @return array<string,array{lat:float,lon:float,zoom:float,width:int,height:int,alt:string}>
 *         Settings, keyed by the file name they resolve to.
 */
function wanted(): array {
	global $wpdb;

	$ids = $wpdb->get_col(
		"SELECT ID FROM {$wpdb->posts}
		 WHERE post_content LIKE '%wp:wow/map%'
		   AND post_status NOT IN ( 'trash', 'auto-draft' )"
	);

	$found = array();

	foreach ( $ids as $id ) {
		collect( parse_blocks( (string) get_post_field( 'post_content', (int) $id ) ), $found );
	}

	return $found;
}

/**
 * Picks the map blocks out of a tree of blocks.
 *
 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
 * @param array<string,array<string,mixed>> $found Collected settings, by file name.
 */
function collect( array $blocks, array &$found ): void {
	foreach ( $blocks as $block ) {
		if ( 'wow/map' === ( $block['blockName'] ?? '' ) ) {
			$settings = Block\settings( (array) ( $block['attrs'] ?? array() ) );

			$found[ Storage\name(
				$settings['lat'],
				$settings['lon'],
				$settings['zoom'],
				$settings['width'],
				$settings['height']
			) ] = $settings;
		}

		if ( ! empty( $block['innerBlocks'] ) ) {
			collect( $block['innerBlocks'], $found );
		}
	}
}

/**
 * Draws maps.
 *
 * With no coordinates it draws every map the site actually uses, which is the
 * usual case. With coordinates it draws exactly the one asked for, which is
 * useful for trying a zoom level before putting the block anywhere.
 *
 * ## OPTIONS
 *
 * [--lat=<latitude>]
 * : Latitude. Given together with --lon, draws only this map.
 *
 * [--lon=<longitude>]
 * : Longitude. Given together with --lat, draws only this map.
 *
 * [--zoom=<zoom>]
 * : Zoom level, 0 to 19. Fractions such as 17.5 are allowed. Default 17.5.
 *
 * [--width=<pixels>]
 * : Width of the picture. Default 416.
 *
 * [--height=<pixels>]
 * : Height of the picture. Default 200.
 *
 * [--force]
 * : Draw again even when the picture is already there.
 *
 * ## EXAMPLES
 *
 *     wp wow-map render
 *     wp wow-map render --lat=43.629528 --lon=6.958389 --zoom=17
 *     wp wow-map render --force
 *
 * @param array<int,string>    $args       Positional arguments.
 * @param array<string,string> $assoc_args Options.
 */
function render( array $args, array $assoc_args ): void {
	$force = isset( $assoc_args['force'] );

	if ( isset( $assoc_args['lat'], $assoc_args['lon'] ) ) {
		$maps = array(
			Block\settings(
				array(
					'latitude'  => $assoc_args['lat'],
					'longitude' => $assoc_args['lon'],
					'zoom'      => $assoc_args['zoom'] ?? 17.5,
					'width'     => $assoc_args['width'] ?? 416,
					'height'    => $assoc_args['height'] ?? 200,
				)
			),
		);
	} else {
		$maps = array_values( wanted() );

		if ( empty( $maps ) ) {
			\WP_CLI::success( 'No page uses a map block, so there is nothing to draw.' );

			return;
		}
	}

	$drawn   = 0;
	$skipped = 0;

	foreach ( $maps as $map ) {
		$file = Storage\name( $map['lat'], $map['lon'], $map['zoom'], $map['width'], $map['height'] );

		if ( ! $force && Storage\exists( $map['lat'], $map['lon'], $map['zoom'], $map['width'], $map['height'] ) ) {
			\WP_CLI::log( "Already drawn: {$file}" );
			++$skipped;

			continue;
		}

		$result = Tiles\ensure( $map['lat'], $map['lon'], $map['zoom'], $map['width'], $map['height'], $force );

		if ( is_wp_error( $result ) ) {
			\WP_CLI::warning( "{$file}: " . $result->get_error_message() );

			continue;
		}

		\WP_CLI::log( "Drawn: {$file}" );
		++$drawn;
	}

	\WP_CLI::success( "Drawn {$drawn}, already there {$skipped}." );
}
\WP_CLI::add_command( 'wow-map render', __NAMESPACE__ . '\\render' );

/**
 * Deletes drawn maps that no block asks for any more.
 *
 * Changing a zoom level or nudging a coordinate leaves the old picture behind,
 * since every combination is filed under its own name.
 *
 * ## OPTIONS
 *
 * [--dry-run]
 * : List what would go, without deleting anything.
 *
 * ## EXAMPLES
 *
 *     wp wow-map prune --dry-run
 *     wp wow-map prune
 *
 * @param array<int,string>    $args       Positional arguments.
 * @param array<string,string> $assoc_args Options.
 */
function prune( array $args, array $assoc_args ): void {
	$dry_run = isset( $assoc_args['dry-run'] );
	$keep    = wanted();
	$stale   = array_diff( Storage\all(), array_keys( $keep ) );

	if ( empty( $stale ) ) {
		\WP_CLI::success( 'Every drawn map is still in use.' );

		return;
	}

	foreach ( $stale as $file ) {
		if ( $dry_run ) {
			\WP_CLI::log( "Would delete: {$file}" );

			continue;
		}

		/*
		 * Not unlink(): wp_delete_file_from_directory() refuses a path that
		 * resolves outside the map cache, and goes on to wp_delete_file(),
		 * whose filter is how an offloader learns the file is gone. Both
		 * return bool since 6.7, so the check below is unchanged.
		 */
		if ( wp_delete_file_from_directory( Storage\dir() . '/' . $file, Storage\dir() ) ) {
			\WP_CLI::log( "Deleted: {$file}" );
		} else {
			\WP_CLI::warning( "Could not delete: {$file}" );
		}
	}

	$count = count( $stale );

	\WP_CLI::success( $dry_run ? "{$count} would be deleted." : "{$count} deleted." );
}
\WP_CLI::add_command( 'wow-map prune', __NAMESPACE__ . '\\prune' );
