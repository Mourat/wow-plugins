<?php
/**
 * The sizes meant for screens that draw two pixels where there is one.
 *
 * A theme that wants to look right on a phone registers each crop twice: the
 * size it occupies on the page, and the same shape at double the pixels.
 * WordPress puts both in the srcset and the browser takes whichever suits the
 * screen in front of it — which, on almost any phone made in the last decade,
 * is the larger one.
 *
 * That makes the 2x file the one most visitors actually download, and it is
 * two to three times the weight of the other. It is also the one that can
 * afford to be compressed hardest: it is never drawn at its own size, so four
 * of its pixels are averaged into every pixel the eye receives, and an
 * encoder's artefacts live precisely in the detail that averaging destroys.
 *
 * WordPress cannot be asked for this directly. It sets the encoding quality
 * once, when the file is opened, and the path it takes to cut a hard crop
 * never revisits that decision — which is why the quality filter cannot tell
 * one crop from another. The same fact is what makes this possible: a quality
 * set before the crop survives it. So the 2x sizes are simply cut again here,
 * out of the same master WordPress used, at the quality they deserve. One
 * generation from the master, exactly as before — nothing is re-compressed
 * twice.
 *
 * @package WOW\Images
 */

namespace WOW\Images\Retina;

use WOW\Images\Formats;
use WOW\Images\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The registered sizes that are double-density copies of another size.
 *
 * Worked out from the naming convention the theme uses — wow-col-full and
 * wow-col-full-2x — rather than from a list that would have to be kept in
 * step with it by hand.
 *
 * @return array<string,array{width:int,height:int,crop:bool}> Keyed by slug.
 */
function sizes(): array {
	$found = array();

	foreach ( wp_get_registered_image_subsizes() as $slug => $size ) {
		if ( str_ends_with( $slug, '-2x' ) ) {
			$found[ $slug ] = $size;
		}
	}

	/**
	 * Filters which registered sizes count as double-density.
	 *
	 * @param array<string,array<string,mixed>> $found Sizes, keyed by slug.
	 */
	return (array) apply_filters( 'wow_images_retina_sizes', $found );
}

/**
 * The file names of an attachment's double-density sizes.
 *
 * @param array $meta Attachment metadata.
 * @return array<string,string> File name => size slug.
 */
function files( array $meta ): array {
	$found = array();

	foreach ( array_keys( sizes() ) as $slug ) {
		$name = wp_basename( (string) ( $meta['sizes'][ $slug ]['file'] ?? '' ) );

		if ( '' !== $name ) {
			$found[ $name ] = $slug;
		}
	}

	return $found;
}

/**
 * The quality a particular file should be encoded at.
 *
 * @param array  $meta   Attachment metadata.
 * @param string $name   File name.
 * @param string $format Format slug.
 */
function quality_for( array $meta, string $name, string $format ): int {
	return isset( files( $meta )[ $name ] )
		? Options\retina_quality( $format )
		: Options\quality( $format );
}

/**
 * Cuts the double-density sizes again, at their own quality.
 *
 * Only the ones WordPress has already made: a size missing from the metadata
 * is missing because the picture was too small for it, and cutting it here
 * would mean inventing pixels.
 *
 * @param int        $id   Attachment ID.
 * @param array|null $meta Metadata to work from; read from the attachment when
 *                         not given.
 * @return array{recut:int,saved:int,meta:array}|\WP_Error
 */
function recut( int $id, ?array $meta = null ) {
	$given = null !== $meta;
	$meta  = $given ? $meta : wp_get_attachment_metadata( $id );

	if ( ! is_array( $meta ) || empty( $meta['file'] ) ) {
		return new \WP_Error( 'wow_images_no_metadata', __( 'This attachment has no image metadata.', 'wow-images' ) );
	}

	$wanted = sizes();

	if ( empty( $wanted ) ) {
		return array(
			'recut' => 0,
			'saved' => 0,
			'meta'  => $meta,
		);
	}

	$mime   = (string) get_post_mime_type( $id );
	$format = Formats\of_mime( $mime );

	if ( '' === $format ) {
		// A JPEG or PNG master: WordPress's own quality setting governs its
		// crops and there is nothing here worth overriding.
		return array(
			'recut' => 0,
			'saved' => 0,
			'meta'  => $meta,
		);
	}

	$uploads = wp_get_upload_dir();

	if ( ! empty( $uploads['error'] ) ) {
		return new \WP_Error( 'wow_images_no_uploads', (string) $uploads['error'] );
	}

	$master = trailingslashit( $uploads['basedir'] ) . $meta['file'];

	if ( ! file_exists( $master ) ) {
		return new \WP_Error( 'wow_images_missing_file', __( 'The picture itself is not on disk.', 'wow-images' ) );
	}

	wp_raise_memory_limit( 'image' );

	$dir     = dirname( $master );
	$quality = Options\retina_quality( $format );
	$recut   = 0;
	$saved   = 0;

	foreach ( $wanted as $slug => $size ) {
		$entry = $meta['sizes'][ $slug ] ?? null;

		if ( ! is_array( $entry ) || empty( $entry['file'] ) ) {
			continue;
		}

		$path = $dir . '/' . wp_basename( (string) $entry['file'] );

		if ( ! file_exists( $path ) ) {
			continue;
		}

		$was    = (int) wp_filesize( $path );
		$editor = wp_get_image_editor( $master );

		if ( is_wp_error( $editor ) ) {
			return $editor;
		}

		/*
		 * Set before the cut, not after: make_subsize() leaves the quality
		 * alone on the hard-crop path, so this is what the file is written
		 * with. On the soft path — a size with no fixed height — the resize
		 * does ask again, and the filter below answers.
		 */
		$editor->set_quality( $quality );

		$result = $editor->make_subsize(
			array(
				'width'  => (int) $size['width'],
				'height' => (int) $size['height'],
				'crop'   => (bool) $size['crop'],
			)
		);

		if ( is_wp_error( $result ) || empty( $result['file'] ) ) {
			continue;
		}

		$written = $dir . '/' . wp_basename( (string) $result['file'] );

		// make_subsize() names the file itself, and the name it arrives at is
		// the same one WordPress used — but if it is not, the old file would
		// be left behind and the metadata would point at a stranger.
		if ( $written !== $path ) {
			if ( file_exists( $written ) ) {
				wp_delete_file( $written );
			}

			continue;
		}

		$now = (int) wp_filesize( $path );

		if ( $now < $was ) {
			$saved += $was - $now;
		}

		$meta['sizes'][ $slug ]['filesize'] = $now;
		++$recut;
	}

	if ( $recut > 0 && ! $given ) {
		wp_update_attachment_metadata( $id, $meta );
	}

	return array(
		'recut' => $recut,
		'saved' => $saved,
		'meta'  => $meta,
	);
}

/**
 * Answers the quality question for a size being resized rather than cropped.
 *
 * WordPress hands the dimensions over on that path, which is the only way of
 * telling a double-density size from an ordinary one from inside the filter.
 *
 * @param mixed $quality What WordPress was going to use.
 * @param mixed $mime    The format being written.
 * @param mixed $dims    Width and height of what is being written.
 * @return mixed
 */
function by_dimensions( $quality, $mime = '', $dims = array() ) {
	$format = Formats\of_mime( (string) $mime );

	if ( '' === $format || ! is_array( $dims ) || empty( $dims['width'] ) ) {
		return $quality;
	}

	foreach ( sizes() as $size ) {
		if ( (int) $size['width'] === (int) $dims['width'] ) {
			return Options\retina_quality( $format );
		}
	}

	return $quality;
}
add_filter( 'wp_editor_set_quality', __NAMESPACE__ . '\\by_dimensions', 20, 3 );

/**
 * Re-cuts the double-density sizes of a picture as it is uploaded.
 *
 * Before the copies are made, so that the AVIF beside each 2x file is made
 * from the file as it will finally be.
 *
 * @param mixed $meta Attachment metadata.
 * @param int   $id   Attachment ID.
 * @return mixed
 */
function on_upload( $meta, $id ) {
	if ( ! is_array( $meta ) || ! Options\get( 'on_upload' ) ) {
		return $meta;
	}

	$result = recut( (int) $id, $meta );

	return is_wp_error( $result ) ? $meta : $result['meta'];
}
add_filter( 'wp_generate_attachment_metadata', __NAMESPACE__ . '\\on_upload', 15, 2 );
