<?php
/**
 * Keeping the picture itself in another format.
 *
 * Different from the copies: this one replaces. A JPEG arrives, a WebP is
 * what the site ends up holding, and the JPEG is not kept. On a site that is
 * almost entirely photographs that is the difference between renting more
 * disk and not — but it is also irreversible, and every crop made afterwards
 * comes out of the WebP rather than out of the camera's file. It is a setting
 * for that reason, and it is off on a site that has not asked for it.
 *
 * For a new upload the swap happens at the one moment when nothing has been
 * written down yet. wp_handle_upload hands over the file after it has landed
 * in the uploads folder and before any attachment exists, so changing it
 * there means WordPress goes on to cut every crop out of the new file, name
 * everything after it, and record only the new name. Nothing in the database
 * ever mentions the original, so nothing has to be corrected afterwards.
 *
 * A picture already on the site is a different matter, and migrate() below is
 * the long way round: convert the master, then let WordPress rebuild every
 * crop out of it. Correcting the pages that name the old files is not done
 * here — References\repoint_all() does the whole site in one pass, which is
 * both faster and more thorough than doing it a picture at a time.
 *
 * @package WOW\Images
 */

namespace WOW\Images\Store;

use WOW\Images\Convert;
use WOW\Images\Formats;
use WOW\Images\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The quality WordPress encodes at when it makes the crops itself.
 *
 * Once pictures are stored as WebP, every crop on the site is written by
 * WordPress out of a WebP master, through code this plugin never touches.
 * This is the one place to say how hard to squeeze them.
 *
 * @param mixed $quality   What WordPress was going to use.
 * @param mixed $mime_type The format being written.
 * @return mixed
 */
function crop_quality( $quality, $mime_type = '' ) {
	$format = Formats\of_mime( (string) $mime_type );

	return '' === $format ? $quality : Options\quality( $format );
}
add_filter( 'wp_editor_set_quality', __NAMESPACE__ . '\\crop_quality', 10, 2 );

/**
 * Whether a file can be swapped for another format.
 *
 * An animated GIF cannot: WordPress writes one frame and the animation is
 * gone, which is a loss no saving excuses. When there is no Imagick to ask,
 * the question goes unanswered and the GIF is left as it is.
 *
 * @param string $path Absolute path.
 * @param string $mime Media type.
 */
function may_replace( string $path, string $mime ): bool {
	if ( ! Formats\replaceable( $mime ) ) {
		return false;
	}

	if ( 'image/gif' !== $mime ) {
		return true;
	}

	if ( ! class_exists( 'Imagick' ) ) {
		return false;
	}

	try {
		$probe  = new \Imagick( $path );
		$frames = $probe->getNumberImages();
		$probe->destroy();
	} catch ( \Throwable $e ) {
		return false;
	}

	return $frames <= 1;
}

/**
 * Writes a picture out in another format, under a name nothing else uses.
 *
 * @param string $path    Absolute path of the source.
 * @param string $format  Format slug.
 * @param int    $quality Quality to encode at.
 * @return string|\WP_Error Absolute path of the new file.
 */
function rewrite_file( string $path, string $format, int $quality ) {
	$dir  = dirname( $path );
	$stem = (string) preg_replace( '/\.[^.]+$/', '', wp_basename( $path ) );
	$name = wp_unique_filename( $dir, $stem . '.' . $format );

	$editor = wp_get_image_editor( $path );

	if ( is_wp_error( $editor ) ) {
		return $editor;
	}

	$mime = Formats\mime( $format );

	$set_quality = static function ( $value, $for ) use ( $mime, $quality ) {
		return $for === $mime ? $quality : $value;
	};

	// Late, so that a quality named outright outranks the rule in retina.php.
	add_filter( 'wp_editor_set_quality', $set_quality, 30, 2 );
	$saved = $editor->save( $dir . '/' . $name, $mime );
	remove_filter( 'wp_editor_set_quality', $set_quality, 30 );

	if ( is_wp_error( $saved ) ) {
		return $saved;
	}

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

	return (string) $saved['path'];
}

/**
 * Swaps a freshly uploaded picture for its converted self.
 *
 * Anything that goes wrong leaves the upload exactly as it arrived. A failed
 * conversion is a disappointment; a failed upload is a lost photograph.
 *
 * @param mixed $upload  {file, url, type} as WordPress has it.
 * @param mixed $context 'upload' or 'sideload'.
 * @return mixed The same array, or one describing the new file.
 */
function on_upload( $upload, $context = 'upload' ) {
	$format = Options\store();

	if ( '' === $format || ! is_array( $upload ) || empty( $upload['file'] ) ) {
		return $upload;
	}

	$source = (string) $upload['file'];

	if ( ! may_replace( $source, (string) ( $upload['type'] ?? '' ) ) ) {
		return $upload;
	}

	$target = rewrite_file( $source, $format, Options\master_quality() );

	if ( is_wp_error( $target ) ) {
		return $upload;
	}

	// A picture that comes out larger in the new format has nothing to offer,
	// and swapping it would cost both weight and the original. Phone
	// photographs that have been through a messaging app are the common case:
	// they arrive already compressed to about quality 70, and asking any
	// encoder to reproduce that faithfully costs more than the file saves.
	if ( wp_filesize( $target ) >= wp_filesize( $source ) ) {
		wp_delete_file( $target );

		// There is no attachment yet to write this down on — this runs before
		// one exists — so it is remembered until the metadata is built, a
		// moment later in the same request.
		weighed( wp_basename( $source ) );

		return $upload;
	}

	wp_delete_file( $source );

	return array(
		'file' => $target,
		'url'  => dirname( (string) $upload['url'] ) . '/' . wp_basename( $target ),
		'type' => Formats\mime( $format ),
	);
}
add_filter( 'wp_handle_upload', __NAMESPACE__ . '\\on_upload', 10, 2 );
add_filter( 'wp_handle_sideload', __NAMESPACE__ . '\\on_upload', 10, 2 );

/**
 * Remembers, within this request, that a file was weighed and left alone.
 *
 * @param string $name File name, or empty to ask what has been remembered.
 * @return array<string,true> What has been remembered so far.
 */
function weighed( string $name = '' ): array {
	static $names = array();

	if ( '' !== $name ) {
		$names[ $name ] = true;
	}

	return $names;
}

/**
 * Writes that verdict onto the attachment, once there is one.
 *
 * Without this the picture would be offered as outstanding work for ever:
 * every screen would list it, every run would convert it again, and every
 * time the answer would be the same one nobody wrote down.
 *
 * @param mixed $meta Attachment metadata.
 * @param int   $id   Attachment ID.
 * @return mixed
 */
function remember_verdict( $meta, $id ) {
	if ( ! is_array( $meta ) || empty( $meta['file'] ) ) {
		return $meta;
	}

	if ( isset( weighed()[ wp_basename( (string) $meta['file'] ) ] ) ) {
		$record           = \WOW\Images\Record\read( $meta );
		$record['stored'] = false;

		$meta = \WOW\Images\Record\stamp( $meta, $record );
	}

	return $meta;
}
add_filter( 'wp_generate_attachment_metadata', __NAMESPACE__ . '\\remember_verdict', 12, 2 );

/**
 * Moves a picture already on the site to the stored format.
 *
 * The order matters. The master is converted first and only then is the
 * attachment pointed at it, so that a failure anywhere leaves the original
 * on disk and the change undoable by hand. The old crops go next, and
 * WordPress cuts them again out of the new file, under names ending in the
 * new extension.
 *
 * What is left — the pages that still name the old files — is somebody
 * else's job, and deliberately so. See the note at the end of this function.
 *
 * @param int  $id      Attachment ID.
 * @param bool $dry_run Work out what would happen, change nothing.
 * @return array{from:string,to:string,before:int,after:int}|\WP_Error
 */
function migrate( int $id, bool $dry_run = false ) {
	$format = Options\store();

	if ( '' === $format ) {
		return new \WP_Error( 'wow_images_no_store', __( 'No stored format is chosen.', 'wow-images' ) );
	}

	$meta = wp_get_attachment_metadata( $id );
	$mime = (string) get_post_mime_type( $id );

	if ( ! is_array( $meta ) || empty( $meta['file'] ) ) {
		return new \WP_Error( 'wow_images_no_metadata', __( 'This attachment has no image metadata.', 'wow-images' ) );
	}

	if ( Formats\of_mime( $mime ) === $format ) {
		return new \WP_Error( 'wow_images_already_stored', __( 'This picture is already in the stored format.', 'wow-images' ) );
	}

	$uploads = wp_get_upload_dir();
	$base    = trailingslashit( $uploads['basedir'] );
	$source  = $base . $meta['file'];

	if ( ! file_exists( $source ) ) {
		return new \WP_Error( 'wow_images_missing_file', __( 'The picture itself is not on disk.', 'wow-images' ) );
	}

	if ( ! may_replace( $source, $mime ) ) {
		return new \WP_Error( 'wow_images_may_not_replace', __( 'This picture must not be replaced — an animated GIF would lose its animation.', 'wow-images' ) );
	}

	$before = weight_of( $meta, $base );

	if ( $dry_run ) {
		return array(
			'from'   => (string) $meta['file'],
			'to'     => (string) preg_replace( '/\.[^.]+$/', '.' . $format, (string) $meta['file'] ),
			'before' => $before,
			'after'  => 0,
		);
	}

	wp_raise_memory_limit( 'image' );

	/*
	 * wp_generate_attachment_metadata() lives in the admin half of WordPress,
	 * which is not loaded on the front end, inside a cron run, or in a plain
	 * bootstrap. Without this the call below is a fatal error — and a quiet
	 * one, because by then the attachment has already been pointed at its new
	 * file and the old crops have already gone.
	 */
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$target = rewrite_file( $source, $format, Options\master_quality() );

	if ( is_wp_error( $target ) ) {
		return $target;
	}

	if ( wp_filesize( $target ) >= wp_filesize( $source ) ) {
		wp_delete_file( $target );

		// Written down so that nothing weighs this picture again. A JPEG
		// saved at a low quality has already thrown away its detail and put
		// its own artefacts in its place, and artefacts are the most
		// expensive thing an encoder can be asked to reproduce faithfully.
		\WOW\Images\Record\refuse_store( $id, $meta );

		return new \WP_Error( 'wow_images_no_gain', __( 'Converting this picture would make it larger.', 'wow-images' ) );
	}

	// The old crops go now. wp_delete_file() carries the copies away with
	// them, so nothing is left pointing at a picture that no longer exists.
	foreach ( old_files( $meta, $base ) as $path ) {
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}

	$relative = _wp_relative_upload_path( $target );

	update_attached_file( $id, $target );
	wp_update_post(
		array(
			'ID'             => $id,
			'post_mime_type' => Formats\mime( $format ),
			'guid'           => trailingslashit( $uploads['baseurl'] ) . $relative,
		)
	);

	// Cuts every crop again, out of the new master, and — through the filter
	// in convert.php — makes the copies of each of them.
	$fresh = wp_generate_attachment_metadata( $id, $target );

	if ( ! is_array( $fresh ) || empty( $fresh['file'] ) ) {
		return new \WP_Error( 'wow_images_no_subsizes', __( 'The crops could not be cut from the converted picture.', 'wow-images' ) );
	}

	wp_update_attachment_metadata( $id, $fresh );

	/*
	 * And now the picture it was made from. Last of all, so that everything
	 * which could still have failed has already succeeded: until this line
	 * the original is on disk and the change can be undone by hand.
	 *
	 * The general cleaner is stood down for these few deletions. What has to
	 * go with the original is known exactly here — its own copies, named
	 * after it — whereas the cleaner works the names out from a rule, and a
	 * rule that looks for "the same name with a different extension" finds
	 * the new master, which is named precisely that way. It did, once.
	 */
	if ( $source !== $target && file_exists( $source ) ) {
		remove_filter( 'wp_delete_file', '\\WOW\\Images\\Cleanup\\on_delete_file' );

		foreach ( Formats\all() as $format ) {
			if ( file_exists( $source . '.' . $format ) ) {
				wp_delete_file( $source . '.' . $format );
			}
		}

		wp_delete_file( $source );

		add_filter( 'wp_delete_file', '\\WOW\\Images\\Cleanup\\on_delete_file' );
	}

	// Nothing above should be able to touch it, but the cost of being wrong
	// here is somebody's photograph, so it is checked rather than assumed.
	if ( ! file_exists( $target ) ) {
		return new \WP_Error(
			'wow_images_master_vanished',
			__( 'The converted picture disappeared while the old one was being removed. Nothing further was changed.', 'wow-images' )
		);
	}

	/*
	 * The pages that name this picture are not corrected here. Converting a
	 * thousand pictures one at a time meant a search of every post for each
	 * one of them, and this site keeps dozens of revisions per page, so that
	 * was a hundred thousand row updates where one pass needs a few thousand
	 * — eighteen minutes against half a second. References\repoint_all()
	 * does the whole site at once, and the callers run it when they have
	 * finished converting.
	 *
	 * It needs nothing remembered from here, which is the other reason it is
	 * not done per picture: it works out the right address for every name
	 * from the attachments as they stand, so it can be run at any time, as
	 * often as you like, and a run interrupted halfway is put right by
	 * running it again rather than by anything written down in advance.
	 */
	return array(
		'from'   => (string) $meta['file'],
		'to'     => (string) $relative,
		'before' => $before,
		'after'  => weight_of( (array) wp_get_attachment_metadata( $id ), $base ),
	);
}

/**
 * What an attachment weighs on disk, master and crops together.
 *
 * @param array  $meta Attachment metadata.
 * @param string $base Uploads folder, with a trailing slash.
 */
function weight_of( array $meta, string $base ): int {
	$bytes = 0;

	foreach ( \WOW\Images\Record\sources( $meta ) as $relative ) {
		$path = $base . $relative;

		if ( file_exists( $path ) ) {
			$bytes += (int) wp_filesize( $path );
		}
	}

	return $bytes;
}

/**
 * Every file of an attachment, by size name.
 *
 * @param array  $meta Attachment metadata.
 * @param string $base Uploads folder, with a trailing slash.
 * @return string[] Absolute paths.
 */
function old_files( array $meta, string $base ): array {
	$dir   = dirname( $base . $meta['file'] );
	$files = array();

	foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
		if ( ! empty( $size['file'] ) ) {
			$files[] = $dir . '/' . wp_basename( (string) $size['file'] );
		}
	}

	return array_values( array_unique( $files ) );
}
