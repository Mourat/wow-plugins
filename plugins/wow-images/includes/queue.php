<?php
/**
 * Which pictures still have no copies.
 *
 * The question is asked by walking the attachments and reading what each one
 * remembers, not by searching the database for a note that is missing. The
 * note lives inside a serialised array, and the only way to search inside one
 * is a LIKE over every row of the meta table — which is both slow and a lie,
 * because it cannot tell a note that is absent from one that is merely out of
 * date. Reading them is two queries and an honest answer.
 *
 * @package WOW\Images
 */

namespace WOW\Images\Queue;

use WOW\Images\Convert;
use WOW\Images\Formats;
use WOW\Images\Options;
use WOW\Images\Record;

defined( 'ABSPATH' ) || exit;

/**
 * Every picture on the site that could have copies made of it.
 *
 * @return int[] Attachment IDs, newest first.
 */
function candidates(): array {
	$ids = get_posts(
		array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'post_mime_type'         => Formats\SOURCES,
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'orderby'                => 'ID',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
		)
	);

	return array_map( 'intval', $ids );
}

/**
 * Whether a picture is still in a format the site no longer keeps.
 *
 * @param int $id Attachment ID.
 */
function needs_store( int $id ): bool {
	$format = Options\store();

	if ( '' === $format ) {
		return false;
	}

	$mime = (string) get_post_mime_type( $id );

	if ( ! Formats\replaceable( $mime ) || Formats\of_mime( $mime ) === $format ) {
		return false;
	}

	return ! Record\store_refused( Record\read( (array) wp_get_attachment_metadata( $id ) ) );
}

/**
 * Whether an attachment has anything left to do.
 *
 * @param int $id Attachment ID.
 */
function needs_work( int $id ): bool {
	if ( needs_store( $id ) ) {
		return true;
	}

	// The picture's own format is not worth making a second time.
	$formats = array_diff( Options\active(), array( Formats\of_mime( (string) get_post_mime_type( $id ) ) ) );

	if ( empty( $formats ) ) {
		return false;
	}

	$meta = wp_get_attachment_metadata( $id );

	if ( ! is_array( $meta ) || empty( $meta['file'] ) ) {
		return false;
	}

	$uploads = wp_get_upload_dir();
	$base    = trailingslashit( $uploads['basedir'] );
	$record  = Record\read( $meta );

	foreach ( Record\sources( $meta ) as $name => $relative ) {
		$path = $base . $relative;

		if ( ! file_exists( $path ) ) {
			continue;
		}

		foreach ( $formats as $format ) {
			if ( ! Record\settled( $record, $name, $format ) ) {
				return true;
			}

			$target = dirname( $path ) . '/' . Convert\target_name( $name, $format );

			if ( ! Convert\done( $record, $name, $format, $path, $target ) ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * The pictures waiting to be converted, ready to hand to the bulk screen.
 *
 * @param int[]|null $ids Look at these attachments only; all of them when null.
 * @return array<int,array{id:int,title:string,mime:string}>
 */
function pending( ?array $ids = null ): array {
	$ids = null === $ids ? candidates() : array_values( array_map( 'intval', $ids ) );

	if ( empty( $ids ) ) {
		return array();
	}

	// One query for the posts and one for their meta, instead of two per
	// picture: without this a library of a thousand photographs is three
	// thousand queries before the first file is encoded.
	_prime_post_caches( $ids, false, true );

	$waiting = array();

	foreach ( $ids as $id ) {
		if ( ! needs_work( $id ) ) {
			continue;
		}

		$waiting[] = array(
			'id'    => $id,
			'title' => (string) get_the_title( $id ),
			'mime'  => (string) get_post_mime_type( $id ),
		);
	}

	return $waiting;
}

/**
 * Where the site as a whole stands, for the settings screen.
 *
 * @return array{total:int,waiting:int,saved:int,source:int}
 */
function summary(): array {
	$ids = candidates();

	if ( empty( $ids ) ) {
		return array(
			'total'   => 0,
			'waiting' => 0,
			'saved'   => 0,
			'source'  => 0,
		);
	}

	_prime_post_caches( $ids, false, true );

	$waiting = 0;
	$saved   = 0;
	$source  = 0;

	foreach ( $ids as $id ) {
		if ( needs_work( $id ) ) {
			++$waiting;
		}

		$record = Record\read( (array) wp_get_attachment_metadata( $id ) );
		$totals = Record\totals( $record );

		// The best format is the one a visitor is offered first and almost
		// always takes, so it is the one the figure should be about.
		$best = null;

		foreach ( $totals as $total ) {
			if ( null === $best || $total['copy'] < $best['copy'] ) {
				$best = $total;
			}
		}

		if ( null !== $best ) {
			$source += $best['source'];
			$saved  += max( 0, $best['source'] - $best['copy'] );
		}
	}

	return array(
		'total'   => count( $ids ),
		'waiting' => $waiting,
		'saved'   => $saved,
		'source'  => $source,
	);
}
