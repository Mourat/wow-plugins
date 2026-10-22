<?php
/**
 * Pointing the pages at the pictures as they are now.
 *
 * Converting a picture renames every file it has. The pages that show it keep
 * the name it had when the block was saved, and some of them keep a name it
 * had even before that: cropping in the media editor already rewrote the file
 * once, under a name ending in -e and a timestamp, and a block saved before
 * that edit still carries the name from before. So a single photograph can be
 * named three different ways across the site, only one of which is a file
 * that exists.
 *
 * All of them resolve to the same picture, and all of them can be recognised
 * the same way: take a file name, strip the -e suffix and the -WxH suffix,
 * and what is left identifies the attachment. That is the whole idea here.
 *
 * It is done in one pass over the posts rather than one pass per picture.
 * The difference is not academic: this site holds dozens of revisions of
 * every page, so a photograph is named in eighty or more rows, and converting
 * a thousand photographs one at a time meant a hundred thousand row updates
 * where a single pass needs a few thousand.
 *
 * @package WOW\Images
 */

namespace WOW\Images\References;

defined( 'ABSPATH' ) || exit;

/**
 * How many posts are read at a time.
 *
 * Post content on a gallery site is long, and all of it has to be in memory
 * at once to be searched.
 */
const CHUNK = 100;

/**
 * Strips what WordPress adds to a file name, leaving what identifies it.
 *
 * picture-e1699999999-754x350.jpg and picture.jpg both come back as
 * "picture", which is what makes a name from before an edit resolvable.
 *
 * @param string $name File name, with or without a path.
 * @return string Lower-cased, without extension or suffixes.
 */
function root_of( string $name ): string {
	$stem = (string) preg_replace( '/\.[^.]+$/', '', wp_basename( $name ) );
	$stem = (string) preg_replace( '/-\d+x\d+$/', '', $stem );
	$stem = (string) preg_replace( '/-e\d+$/', '', $stem );

	return strtolower( $stem );
}

/**
 * Every picture on the site, by the root of its name.
 *
 * A root claimed by two attachments is dropped rather than guessed at: there
 * is no way to tell which of them a stale name was meant for, and rewriting
 * it to the wrong picture is worse than leaving it pointing at nothing.
 *
 * @return array<string,array{url:string,sizes:array<string,string>,names:array<string,true>}>
 */
function index(): array {
	$uploads = wp_get_upload_dir();
	$found   = array();
	$clashes = array();

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
		$meta = (array) wp_get_attachment_metadata( (int) $id );

		if ( empty( $meta['file'] ) ) {
			continue;
		}

		$root = root_of( (string) $meta['file'] );

		if ( '' === $root ) {
			continue;
		}

		if ( isset( $found[ $root ] ) ) {
			$clashes[ $root ] = true;

			continue;
		}

		$dir   = ltrim( dirname( (string) $meta['file'] ), '.' );
		$dir   = '' === $dir ? '' : trailingslashit( $dir );
		$where = trailingslashit( $uploads['baseurl'] ) . $dir;

		$entry = array(
			'url'   => $where . wp_basename( (string) $meta['file'] ),
			'sizes' => array(),
			'names' => array( strtolower( wp_basename( (string) $meta['file'] ) ) => true ),
		);

		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
			$name = wp_basename( (string) ( $size['file'] ?? '' ) );

			if ( '' === $name ) {
				continue;
			}

			$entry['names'][ strtolower( $name ) ] = true;

			if ( ! empty( $size['width'] ) && ! empty( $size['height'] ) ) {
				$entry['sizes'][ $size['width'] . 'x' . $size['height'] ] = $where . $name;
			}
		}

		$found[ $root ] = $entry;
	}

	foreach ( array_keys( $clashes ) as $root ) {
		unset( $found[ $root ] );
	}

	return $found;
}

/**
 * The address a name found in a post should point at, or null to leave it.
 *
 * @param string $name  File name as it appears in the post.
 * @param array  $index Output of index().
 */
function resolve( string $name, array $index ): ?string {
	$root  = root_of( $name );
	$entry = $index[ $root ] ?? null;

	if ( null === $entry ) {
		return null;
	}

	// Already the name of a file this attachment has: nothing to do.
	if ( isset( $entry['names'][ strtolower( $name ) ] ) ) {
		return null;
	}

	// A name carrying dimensions asked for that crop; send it to the same one
	// if the picture still has it, and to the picture itself if it does not.
	if ( preg_match( '/-(\d+x\d+)\.[^.]+$/', $name, $found ) && isset( $entry['sizes'][ $found[1] ] ) ) {
		return $entry['sizes'][ $found[1] ];
	}

	return $entry['url'];
}

/**
 * Rewrites every stale picture address on the site, in one pass.
 *
 * Only file names are touched, never the address around them: a URL inside a
 * block comment has its slashes escaped and the one in an img tag does not,
 * and replacing the name alone leaves whichever form the post uses intact.
 *
 * @param bool $dry_run Count what would change, change nothing.
 * @return array{posts:int,names:int,examples:array<string,string>}
 */
function repoint_all( bool $dry_run = false ): array {
	global $wpdb;

	$index = index();

	if ( empty( $index ) ) {
		return array(
			'posts'    => 0,
			'names'    => 0,
			'examples' => array(),
		);
	}

	$uploads   = wp_get_upload_dir();
	$directory = wp_basename( (string) $uploads['basedir'] );

	// A file name in an uploads address: everything after the last slash, up
	// to the quote, backslash or whitespace that ends the address.
	$pattern = '#(?<=/)[^/"\'\\\\\s<>]+\.(?:jpe?g|png|gif|webp|avif)#i';

	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_content LIKE %s
			   AND post_status NOT IN ( 'auto-draft' )",
			'%' . $wpdb->esc_like( '/' . $directory . '/' ) . '%'
		)
	);

	$posts    = 0;
	$names    = 0;
	$examples = array();

	foreach ( array_chunk( $ids, CHUNK ) as $batch ) {
		foreach ( $batch as $post_id ) {
			$content = (string) get_post_field( 'post_content', (int) $post_id, 'raw' );
			$hits    = 0;

			$updated = (string) preg_replace_callback(
				$pattern,
				static function ( array $found ) use ( $index, &$hits, &$examples, &$names ): string {
					$to = resolve( $found[0], $index );

					if ( null === $to ) {
						return $found[0];
					}

					++$hits;
					++$names;

					if ( count( $examples ) < 8 ) {
						$examples[ $found[0] ] = wp_basename( $to );
					}

					return wp_basename( $to );
				},
				$content
			);

			if ( 0 === $hits || $updated === $content ) {
				continue;
			}

			++$posts;

			if ( ! $dry_run ) {
				$wpdb->update( $wpdb->posts, array( 'post_content' => $updated ), array( 'ID' => (int) $post_id ) );
				clean_post_cache( (int) $post_id );
			}
		}
	}

	return array(
		'posts'    => $posts,
		'names'    => $names,
		'examples' => $examples,
	);
}
