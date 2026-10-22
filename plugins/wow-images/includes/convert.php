<?php
/**
 * Making the copies.
 *
 * Everything goes through wp_get_image_editor(), never through Imagick
 * directly. WordPress already knows which library this server has, which of
 * them can write the format being asked for, and the handful of settings each
 * encoder wants — including the one that is easy to get wrong: AVIF ignores
 * Imagick::setImageCompressionQuality() entirely and reads only
 * setCompressionQuality(), so a plugin that sets the first and not the second
 * silently encodes every AVIF at whatever quality the library felt like.
 * WordPress sets both. Going through it also means a server with no Imagick
 * falls back to GD without a line of code here knowing about it.
 *
 * The quality is handed over through the wp_editor_set_quality filter rather
 * than set_quality(), because WordPress calls set_quality() itself the moment
 * it works out that the output format differs from the input, and would
 * overwrite anything set beforehand.
 *
 * @package WOW\Images
 */

namespace WOW\Images\Convert;

use WOW\Images\Formats;
use WOW\Images\Later;
use WOW\Images\Options;
use WOW\Images\Record;

defined( 'ABSPATH' ) || exit;

/**
 * What the copy of a file is called.
 *
 * Appending is the default and the safe one: picture.jpg becomes
 * picture.jpg.webp, which cannot collide with anything, because the name it
 * is built from was already unique. Replacing the extension gives the tidier
 * picture.webp, which a server-side rewrite rule can find without being told,
 * and which collides the day somebody uploads both picture.jpg and
 * picture.png. Nothing here needs the rewrite rule — the browser is told
 * about the copies in the page itself — so the tidier name is offered rather
 * than taken.
 *
 * @param string $name   Source file name.
 * @param string $format Format slug.
 */
function target_name( string $name, string $format ): string {
	if ( Options\NAMING_REPLACE === Options\get( 'naming' ) ) {
		$stem = (string) preg_replace( '/\.[^.]+$/', '', $name );

		if ( '' !== $stem ) {
			return $stem . '.' . $format;
		}
	}

	return $name . '.' . $format;
}

/**
 * Writes one copy of one file.
 *
 * @param string   $path    Absolute path of the source.
 * @param string   $target  Absolute path to write.
 * @param string   $format  Format slug.
 * @param int|null $quality Quality to encode at; the format's own when null.
 * @return int|\WP_Error Bytes written.
 */
function file( string $path, string $target, string $format, ?int $quality = null ) {
	$editor = wp_get_image_editor( $path );

	if ( is_wp_error( $editor ) ) {
		return $editor;
	}

	$mime    = Formats\mime( $format );
	$quality = $quality ?? Options\quality( $format );

	$set_quality = static function ( $value, $for ) use ( $mime, $quality ) {
		return $for === $mime ? $quality : $value;
	};

	/*
	 * Late on purpose. The double-density rule in retina.php answers the same
	 * filter by looking at the picture's width, and a master that happens to
	 * be exactly as wide as a 2x crop would be mistaken for one. A quality
	 * named outright here is not a guess and must outrank every rule.
	 */
	add_filter( 'wp_editor_set_quality', $set_quality, 30, 2 );
	$saved = $editor->save( $target, $mime );
	remove_filter( 'wp_editor_set_quality', $set_quality, 30 );

	if ( is_wp_error( $saved ) ) {
		return $saved;
	}

	/*
	 * Asked for a format it cannot write, WordPress does not refuse: it falls
	 * back to its default type and writes that instead, which would leave a
	 * JPEG sitting at a path ending in .avif and a <source type="image/avif">
	 * pointing at it. Every browser that took the offer would show nothing.
	 */
	if ( ( $saved['mime-type'] ?? '' ) !== $mime ) {
		if ( ! empty( $saved['path'] ) ) {
			wp_delete_file( (string) $saved['path'] );
		}

		return new \WP_Error(
			'wow_images_wrong_format',
			sprintf(
				/* translators: %s: image format name, e.g. AVIF. */
				__( 'This server cannot write %s.', 'wow-images' ),
				Formats\label( $format )
			)
		);
	}

	return (int) ( $saved['filesize'] ?? wp_filesize( $target ) );
}

/**
 * Makes every copy an attachment is missing.
 *
 * Runs over the original and every crop WordPress made from it. A copy that
 * is already on disk and no older than the file it came from is left alone,
 * so running this twice costs almost nothing and running it after a crop in
 * the media editor redoes exactly the files that changed.
 *
 * @param int        $id    Attachment ID.
 * @param bool       $force Make every copy again, even the ones already there.
 * @param array|null $meta  Metadata to work from, for the one caller that has
 *                          it before WordPress has saved it: the filter that
 *                          builds it as a picture is uploaded. Read from the
 *                          attachment when not given.
 * @return array{made:int,kept:int,rejected:int,errors:string[],saving:int,meta:array}|\WP_Error
 */
function attachment( int $id, bool $force = false, ?array $meta = null, ?array $only = null ) {
	$given = null !== $meta;
	$meta  = $given ? $meta : wp_get_attachment_metadata( $id );

	if ( ! is_array( $meta ) || empty( $meta['file'] ) ) {
		return new \WP_Error( 'wow_images_no_metadata', __( 'This attachment has no image metadata.', 'wow-images' ) );
	}

	$mime = (string) get_post_mime_type( $id );

	if ( ! Formats\convertible( $mime ) ) {
		return new \WP_Error( 'wow_images_not_convertible', __( 'This is not a picture that can be converted.', 'wow-images' ) );
	}

	/*
	 * A picture stored as WebP is still worth an AVIF, and is not worth a
	 * second WebP. Which of the two it is depends on the picture, so the
	 * format it already is gets taken off the list here rather than being
	 * decided once in the settings.
	 */
	$own     = Formats\of_mime( $mime );
	$formats = array_values( array_diff( Options\active(), array( $own ) ) );

	// A caller may ask for some of them and leave the rest for later.
	if ( null !== $only ) {
		$formats = array_values( array_intersect( $formats, $only ) );
	}

	if ( empty( $formats ) ) {
		return new \WP_Error( 'wow_images_no_formats', __( 'No format is switched on that this server can write and this picture is not already in.', 'wow-images' ) );
	}

	$uploads = wp_get_upload_dir();

	if ( ! empty( $uploads['error'] ) ) {
		return new \WP_Error( 'wow_images_no_uploads', (string) $uploads['error'] );
	}

	// A full-size photograph decoded into memory is tens of megabytes, and
	// this walks through every size of it one after another.
	wp_raise_memory_limit( 'image' );

	$base     = trailingslashit( $uploads['basedir'] );
	$record   = Record\read( $meta );
	$made     = 0;
	$kept     = 0;
	$rejected = 0;
	$errors   = array();
	$floor    = 1 - Options\min_gain();

	foreach ( Record\sources( $meta ) as $name => $relative ) {
		$path = $base . $relative;

		if ( ! file_exists( $path ) ) {
			continue;
		}

		$source_size = (int) wp_filesize( $path );
		$entry       = is_array( $record['files'][ $name ] ?? null ) ? $record['files'][ $name ] : array();

		$entry['size'] = $source_size;

		foreach ( $formats as $format ) {
			$copy_name = target_name( $name, $format );
			$target    = dirname( $path ) . '/' . $copy_name;

			if ( ! $force && Record\settled( $record, $name, $format ) && done( $record, $name, $format, $path, $target ) ) {
				++$kept;

				continue;
			}

			$written = file( $path, $target, $format, \WOW\Images\Retina\quality_for( $meta, $name, $format ) );

			if ( is_wp_error( $written ) ) {
				$errors[] = $name . ': ' . $written->get_error_message();

				continue;
			}

			// Not every picture compresses. A flat drawing saved as a PNG can
			// come out of a photographic encoder larger than it went in, and
			// a JPEG already squeezed by whoever made it has little left to
			// give. A copy that does not earn its place is thrown away, and
			// the fact that it was tried is written down so that the next run
			// does not try it again.
			if ( $written >= $source_size * $floor ) {
				wp_delete_file( $target );
				$entry[ $format ] = false;
				++$rejected;

				continue;
			}

			$entry[ $format ] = array(
				'file' => $copy_name,
				'size' => $written,
			);
			++$made;
		}

		$record['files'][ $name ] = $entry;
	}

	/*
	 * Given metadata, the note is only folded into it and handed back: the
	 * caller is inside the filter that produces it, and WordPress saves the
	 * filter's return value a moment later, over anything written here.
	 */
	$meta = $given ? Record\stamp( $meta, $record ) : Record\write( $id, $meta, $record );

	return array(
		'made'     => $made,
		'kept'     => $kept,
		'rejected' => $rejected,
		'errors'   => $errors,
		'saving'   => Record\saving( $record ),
		'meta'     => $meta,
	);
}

/**
 * How much older than its source a copy may be and still count as current.
 *
 * The comparison exists to catch a picture rewritten after its copies were
 * made — cropping in the media editor writes the crops again under the same
 * names, and the copy of the picture as it used to be would otherwise be
 * served for ever. What it also caught was the crop itself: sizes and copies
 * are written in one pass, and three pictures cropped this week ended up in
 * the queue with their AVIF exactly one second older than the file it had
 * just been made from. The copies were right; the clock disagreed.
 *
 * Five seconds, because the thing being guarded against — somebody opening
 * the editor and cropping — is minutes of work away from the last
 * conversion, never five seconds.
 */
const GRACE = 5;

/**
 * Whether a copy that was made earlier still stands.
 *
 * A copy recorded as rejected needs no file and is always settled. A copy
 * recorded as made has to still be on disk, and has to be no older than the
 * picture it came from, give or take the few seconds a rebuild takes.
 *
 * @param array  $record Note read from the metadata.
 * @param string $name   Source file name.
 * @param string $format Format slug.
 * @param string $path   Absolute path of the source.
 * @param string $target Absolute path of the copy.
 */
function done( array $record, string $name, string $format, string $path, string $target ): bool {
	if ( null === Record\copy_of( $record, $name, $format ) ) {
		return true;
	}

	return file_exists( $target ) && filemtime( $target ) + GRACE >= filemtime( $path );
}

/**
 * Makes the copies as a picture is uploaded.
 *
 * Hooked onto the end of the metadata WordPress builds for a new upload, so
 * that the crops exist by the time this runs. The metadata has to be passed
 * in rather than read: at this point it is the filter's argument and nothing
 * has saved it yet, so an attachment asked about itself answers with nothing.
 *
 * @param mixed $meta Attachment metadata.
 * @param int   $id   Attachment ID.
 * @return mixed The metadata, with the note added.
 */
function on_upload( $meta, $id ) {
	if ( ! is_array( $meta ) || ! Options\get( 'on_upload' ) ) {
		return $meta;
	}

	/*
	 * Only the quick formats while the upload is open; the slow ones are
	 * asked for again a moment later, from a request nobody is waiting on.
	 * What this buys is measured in later.php.
	 */
	$prompt = array_values(
		array_filter(
			Options\active(),
			static fn ( string $format ): bool => ! Formats\slow( $format )
		)
	);

	Later\schedule( (int) $id );

	$result = attachment( (int) $id, false, $meta, $prompt );

	return is_wp_error( $result ) ? $meta : $result['meta'];
}
add_filter( 'wp_generate_attachment_metadata', __NAMESPACE__ . '\\on_upload', 20, 2 );

/**
 * Whether this request is the media editor saving a change.
 *
 * Cropping, rotating and "restore original" all arrive the same way, and it
 * is the only way they arrive.
 */
function from_media_editor(): bool {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- identifying the request, not trusting it; core has already checked the nonce by the time this runs.
	return isset( $_REQUEST['action'] ) && 'image-editor' === $_REQUEST['action'];
}

/**
 * Makes the copies again after a picture is edited in the media editor.
 *
 * WordPress has no event for this. wp_save_image() cuts every size afresh and
 * then simply calls wp_update_attachment_metadata(), so the only way to know
 * an edit has happened is to watch that filter and recognise the request.
 *
 * It matters more than it sounds. A cropped picture gets new files under new
 * names and the old copies stay behind beside the old ones, so the page ends
 * up with an <img> and nothing else: no AVIF to offer, and a srcset whose
 * only remaining candidate points at the picture as it was before the edit —
 * which wow-blocks then drops, quite correctly. One lonely image where there
 * were four.
 *
 * The metadata is folded into what the filter returns rather than saved
 * separately, for the same reason as on upload: WordPress is about to write
 * the filter's return value over anything written here.
 *
 * @param mixed $data    Attachment metadata on its way to being saved.
 * @param mixed $post_id Attachment ID.
 * @return mixed
 */
function on_edited( $data, $post_id = 0 ) {
	static $busy = false;

	if ( $busy || ! is_array( $data ) || empty( $data['file'] ) || ! from_media_editor() ) {
		return $data;
	}

	$busy = true;

	$recut = \WOW\Images\Retina\recut( (int) $post_id, $data );

	if ( ! is_wp_error( $recut ) ) {
		$data = $recut['meta'];
	}

	$made = attachment( (int) $post_id, false, $data );

	if ( ! is_wp_error( $made ) ) {
		$data = $made['meta'];
	}

	$busy = false;

	return $data;
}
add_filter( 'wp_update_attachment_metadata', __NAMESPACE__ . '\\on_edited', 20, 2 );
