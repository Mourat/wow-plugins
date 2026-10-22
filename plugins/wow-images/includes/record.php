<?php
/**
 * What an attachment remembers about its generated copies.
 *
 * It is kept inside the attachment's own metadata rather than in a table or
 * a separate meta key, so that it travels with the picture: export the site,
 * move the attachment, delete it, and the note goes along. WordPress hands
 * the whole array to wp_get_attachment_metadata() in one go, which is also
 * what the front end needs — one read per picture, no extra query.
 *
 * The note says, for each file on disk that belongs to this attachment, how
 * big it is and what was made from it. A format recorded as false was tried
 * and came out no smaller than the original, which is worth remembering: the
 * bulk screen would otherwise re-encode it on every run, for ever.
 *
 * @package WOW\Images
 */

namespace WOW\Images\Record;

defined( 'ABSPATH' ) || exit;

/** Where inside the attachment metadata the note lives. */
const KEY = 'wow_images';

/** The shape of the note, so a later version can recognise an older one. */
const VERSION = 1;

/**
 * The note an attachment carries, empty when it has none.
 *
 * @param array $meta Attachment metadata.
 * @return array{version:int,time:int,files:array<string,array<string,mixed>>}
 */
function read( array $meta ): array {
	$record = $meta[ KEY ] ?? null;

	if ( ! is_array( $record ) || VERSION !== ( $record['version'] ?? 0 ) ) {
		return array(
			'version' => VERSION,
			'time'    => 0,
			'files'   => array(),
		);
	}

	$record['files'] = is_array( $record['files'] ?? null ) ? $record['files'] : array();

	return $record;
}

/**
 * Puts the note into a metadata array, stamped with the moment and the shape.
 *
 * Separate from write() because the note is sometimes folded into metadata
 * that WordPress is still in the middle of building and has not saved yet.
 *
 * @param array $meta   Attachment metadata.
 * @param array $record The note.
 * @return array The metadata, with the note in it.
 */
function stamp( array $meta, array $record ): array {
	$record['version'] = VERSION;
	$record['time']    = time();

	$meta[ KEY ] = $record;

	return $meta;
}

/**
 * Saves the note back onto the attachment.
 *
 * @param int   $id     Attachment ID.
 * @param array $meta   Attachment metadata to write it into.
 * @param array $record The note.
 * @return array The metadata as saved.
 */
function write( int $id, array $meta, array $record ): array {
	$meta = stamp( $meta, $record );

	wp_update_attachment_metadata( $id, $meta );

	return $meta;
}

/**
 * Takes the note off an attachment.
 *
 * @param int   $id   Attachment ID.
 * @param array $meta Attachment metadata.
 */
function forget( int $id, array $meta ): void {
	unset( $meta[ KEY ] );

	wp_update_attachment_metadata( $id, $meta );
}

/**
 * Every file on disk that belongs to an attachment and is worth converting.
 *
 * Keyed by file name, because that is what the front end has to go on: it is
 * handed a URL and has to find out whether there is a WebP beside it. Two
 * registered sizes of identical dimensions share one file, and keying by name
 * collapses them into the one entry rather than encoding it twice.
 *
 * $meta['original_image'] is deliberately absent. WordPress keeps that file
 * only so a picture can be re-cropped from the untouched upload; no visitor
 * is ever sent it, and a copy of it would be weight on disk for nobody.
 *
 * @param array $meta Attachment metadata.
 * @return array<string,string> File name => path relative to the uploads folder.
 */
function sources( array $meta ): array {
	$file = (string) ( $meta['file'] ?? '' );

	if ( '' === $file ) {
		return array();
	}

	$dir   = ltrim( dirname( $file ), '.' );
	$dir   = '' === $dir ? '' : trailingslashit( $dir );
	$found = array( wp_basename( $file ) => $file );

	foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
		$name = wp_basename( (string) ( $size['file'] ?? '' ) );

		if ( '' !== $name ) {
			$found[ $name ] = $dir . $name;
		}
	}

	return $found;
}

/**
 * The name of the copy made from a file, or null when there is none.
 *
 * @param array  $record Note read from the metadata.
 * @param string $name   Source file name.
 * @param string $format Format slug.
 */
function copy_of( array $record, string $name, string $format ): ?string {
	$entry = $record['files'][ $name ][ $format ] ?? null;

	return is_array( $entry ) && ! empty( $entry['file'] ) ? (string) $entry['file'] : null;
}

/**
 * Whether storing this picture anew has been tried and found not worth it.
 *
 * A picture whose converted self comes out larger than the original is left
 * alone — and the fact that it was weighed is written down, because otherwise
 * every run would weigh it again and every screen would go on listing it as
 * outstanding work that can never be finished.
 *
 * @param array $record Note read from the metadata.
 */
function store_refused( array $record ): bool {
	return array_key_exists( 'stored', $record ) && false === $record['stored'];
}

/**
 * Remembers that storing a picture anew was tried and came to nothing.
 *
 * @param int   $id   Attachment ID.
 * @param array $meta Attachment metadata.
 */
function refuse_store( int $id, array $meta ): void {
	$record           = read( $meta );
	$record['stored'] = false;

	write( $id, $meta, $record );
}

/**
 * Whether a file has already been answered for, one way or the other.
 *
 * A recorded false counts as an answer: it means the copy was made, measured,
 * found no smaller than the original and thrown away.
 *
 * @param array  $record Note read from the metadata.
 * @param string $name   Source file name.
 * @param string $format Format slug.
 */
function settled( array $record, string $name, string $format ): bool {
	return array_key_exists( $format, (array) ( $record['files'][ $name ] ?? array() ) );
}

/**
 * How many bytes the originals weigh, and how many each format weighs.
 *
 * Only files that have a copy in a format count towards that format's total,
 * and their originals are the only ones counted against it, so the two
 * numbers are always about the same pictures.
 *
 * @param array $record Note read from the metadata.
 * @return array<string,array{source:int,copy:int}> Keyed by format slug.
 */
function totals( array $record ): array {
	$totals = array();

	foreach ( $record['files'] as $entry ) {
		$source = (int) ( $entry['size'] ?? 0 );

		foreach ( $entry as $format => $copy ) {
			if ( ! is_array( $copy ) || empty( $copy['size'] ) ) {
				continue;
			}

			$totals[ $format ] ??= array(
				'source' => 0,
				'copy'   => 0,
			);

			$totals[ $format ]['source'] += $source;
			$totals[ $format ]['copy']   += (int) $copy['size'];
		}
	}

	return $totals;
}

/**
 * How much lighter the attachment is in its best format, as a percentage.
 *
 * "Best" is the smallest total, which on every photograph measured on this
 * site is AVIF. It is the number a person actually wants: what a visitor
 * whose browser takes the best on offer saves over the original.
 *
 * @param array $record Note read from the metadata.
 * @return int 0 when nothing has been generated.
 */
function saving( array $record ): int {
	$best = 0;

	foreach ( totals( $record ) as $total ) {
		if ( $total['source'] <= 0 ) {
			continue;
		}

		$best = max( $best, (int) round( ( 1 - $total['copy'] / $total['source'] ) * 100 ) );
	}

	return max( 0, $best );
}
