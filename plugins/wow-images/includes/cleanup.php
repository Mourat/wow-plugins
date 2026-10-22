<?php
/**
 * Taking the copies away again.
 *
 * Every file WordPress deletes passes through the wp_delete_file filter —
 * the original when an attachment is deleted, each crop when the sizes are
 * rebuilt, the lot when a picture is restored to how it was before an edit.
 * Listening there means the copies go whenever their picture does, without
 * this plugin having to know about any of those occasions separately.
 *
 * @package WOW\Images
 */

namespace WOW\Images\Cleanup;

use WOW\Images\Formats;
use WOW\Images\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The paths a file's copies could be at.
 *
 * The appended form — picture.jpg.webp — can only ever be ours: it is the
 * whole of the original name with one more extension on the end, which is not
 * a name anything else produces.
 *
 * The replaced form — picture.webp — is a different matter, because it is
 * indistinguishable from a picture somebody uploaded under that name. It is
 * therefore only considered when this site is actually making copies that
 * way, and never for the format the pictures themselves are kept in: a stored
 * master is named by replacing the extension, so sweeping that form would
 * mean deleting the picture while tidying up after it. That is not a
 * hypothetical — it is what this function did before the exception existed.
 *
 * @param string $path Absolute path of the file being deleted.
 * @return string[] Absolute paths.
 */
function copies_of( string $path ): array {
	$name = wp_basename( $path );
	$type = wp_check_filetype( $name );

	if ( ! Formats\convertible( (string) ( $type['type'] ?? '' ) ) ) {
		return array();
	}

	$dir       = dirname( $path );
	$found     = array();
	$replacing = Options\NAMING_REPLACE === Options\get( 'naming' );
	$stored    = Options\store();

	foreach ( Formats\all() as $format ) {
		$candidates = array( $name . '.' . $format );

		if ( $replacing && $format !== $stored ) {
			$candidates[] = (string) preg_replace( '/\.[^.]+$/', '.' . $format, $name );
		}

		foreach ( $candidates as $candidate ) {
			$candidate = $dir . '/' . $candidate;

			if ( $candidate !== $path && file_exists( $candidate ) ) {
				$found[ $candidate ] = $candidate;
			}
		}
	}

	return array_values( $found );
}

/**
 * Deletes the copies of a file WordPress is deleting.
 *
 * The path is handed straight back: this is a filter over what is about to be
 * deleted, and changing it would send WordPress after the wrong file.
 *
 * @param mixed $file Path WordPress is about to delete.
 * @return mixed The same path.
 */
function on_delete_file( $file ) {
	if ( ! is_string( $file ) || '' === $file ) {
		return $file;
	}

	foreach ( copies_of( $file ) as $copy ) {
		wp_delete_file( $copy );
	}

	return $file;
}
add_filter( 'wp_delete_file', __NAMESPACE__ . '\\on_delete_file' );

/**
 * Every copy on the site that no attachment claims any more.
 *
 * Built by listing what the attachments say they have and comparing it with
 * what is on disk beside them. A size that was removed from the theme, a
 * picture re-cropped under a new name, a naming setting changed halfway
 * through — all of them leave files that nothing will ever ask for.
 *
 * @return string[] Absolute paths.
 */
function orphans(): array {
	$uploads = wp_get_upload_dir();

	if ( ! empty( $uploads['error'] ) || ! is_dir( $uploads['basedir'] ) ) {
		return array();
	}

	$claimed = claimed();
	$orphans = array();

	$files = new \RecursiveIteratorIterator(
		new \RecursiveDirectoryIterator( $uploads['basedir'], \FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $files as $file ) {
		$path = $file->getPathname();

		if ( ! in_array( strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) ), Formats\all(), true ) ) {
			continue;
		}

		// A WebP somebody uploaded is an attachment in its own right and has
		// nothing to do with this plugin; only files that look like a copy of
		// something are considered, and only then if nobody claims them.
		if ( ! isset( $claimed[ $path ] ) && looks_generated( $path ) ) {
			$orphans[] = $path;
		}
	}

	sort( $orphans );

	return $orphans;
}

/**
 * Every file in the uploads folder that somebody has a right to.
 *
 * Two kinds. The copies the attachments say they have, which is the point of
 * the list — and every file that belongs to an attachment in its own right,
 * which is the safety catch. Somebody can upload a WebP; WordPress will then
 * crop it into more WebP files; and all of them sit in the same folders under
 * the same kind of name as a copy made here. Without this, a picture.webp
 * uploaded next to an unrelated picture.jpg would be read as a leftover and
 * deleted. Deleting somebody's photograph is not a tidy-up.
 *
 * @return array<string,true> Absolute paths, as keys.
 */
function claimed(): array {
	$uploads = wp_get_upload_dir();
	$base    = trailingslashit( $uploads['basedir'] );
	$claimed = array();

	$ids = get_posts(
		array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'post_mime_type'         => 'image',
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
		)
	);

	_prime_post_caches( $ids, false, true );

	foreach ( $ids as $id ) {
		$meta   = (array) wp_get_attachment_metadata( (int) $id );
		$record = \WOW\Images\Record\read( $meta );

		// The untouched upload WordPress keeps beside a scaled one is not in
		// sources(), and must not be swept up for being absent from it.
		if ( ! empty( $meta['original_image'] ) && ! empty( $meta['file'] ) ) {
			$claimed[ dirname( $base . $meta['file'] ) . '/' . wp_basename( (string) $meta['original_image'] ) ] = true;
		}

		/*
		 * The picture as it was before somebody cropped it, and every size
		 * cut from it. WordPress keeps these so that "restore original image"
		 * has something to restore, and they are not in sources() because
		 * they are not what the attachment is now.
		 *
		 * Their copies are deliberately not claimed. A backup is never shown
		 * to anybody — it exists only to be restored — so an AVIF beside one
		 * is weight that nothing will ever ask for. And should the picture be
		 * restored, the copies are made again on the spot, because a restore
		 * arrives through the media editor like any other edit.
		 */
		if ( ! empty( $meta['file'] ) ) {
			$dir = dirname( $base . $meta['file'] );

			$backups = get_post_meta( (int) $id, '_wp_attachment_backup_sizes', true );

			// Not (array) on the result: with nothing stored that is an empty
			// string, and casting a string to an array yields the string
			// inside one, which is then read as though it were a size.
			foreach ( is_array( $backups ) ? $backups : array() as $backup ) {
				$name = is_array( $backup ) ? wp_basename( (string) ( $backup['file'] ?? '' ) ) : '';

				if ( '' !== $name ) {
					$claimed[ $dir . '/' . $name ] = true;
				}
			}
		}

		foreach ( \WOW\Images\Record\sources( $meta ) as $name => $relative ) {
			$dir = dirname( $base . $relative );

			$claimed[ $base . $relative ] = true;

			foreach ( Formats\all() as $format ) {
				$copy = \WOW\Images\Record\copy_of( $record, $name, $format );

				if ( null !== $copy ) {
					$claimed[ $dir . '/' . $copy ] = true;
				}
			}
		}
	}

	return $claimed;
}

/**
 * Whether a file looks like something this plugin made.
 *
 * A copy made by appending still carries the name it came from, extension and
 * all, so it announces itself. A copy made by replacing does not, and the
 * only evidence is a picture of the same stem sitting beside it.
 *
 * @param string $path Absolute path.
 */
function looks_generated( string $path ): bool {
	$name = wp_basename( $path );
	$stem = (string) preg_replace( '/\.[^.]+$/', '', $name );

	if ( Formats\convertible( (string) ( wp_check_filetype( $stem )['type'] ?? '' ) ) ) {
		return true;
	}

	foreach ( array( 'jpg', 'jpeg', 'png', 'gif' ) as $extension ) {
		if ( file_exists( dirname( $path ) . '/' . $stem . '.' . $extension ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Removes every copy of an attachment, and forgets they were ever made.
 *
 * @param int $id Attachment ID.
 * @return int How many files were deleted.
 */
function forget_attachment( int $id ): int {
	$meta = wp_get_attachment_metadata( $id );

	if ( ! is_array( $meta ) ) {
		return 0;
	}

	$uploads = wp_get_upload_dir();
	$base    = trailingslashit( $uploads['basedir'] );
	$record  = \WOW\Images\Record\read( $meta );
	$deleted = 0;

	foreach ( \WOW\Images\Record\sources( $meta ) as $name => $relative ) {
		$dir = dirname( $base . $relative );

		foreach ( Formats\all() as $format ) {
			$copy = \WOW\Images\Record\copy_of( $record, $name, $format );

			if ( null === $copy ) {
				continue;
			}

			if ( file_exists( $dir . '/' . $copy ) ) {
				wp_delete_file( $dir . '/' . $copy );
				++$deleted;
			}
		}
	}

	\WOW\Images\Record\forget( $id, $meta );

	return $deleted;
}
