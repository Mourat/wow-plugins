<?php
/**
 * WP-CLI commands.
 *
 * The automation only runs when an album is saved, so a library filled in
 * before this plugin existed needs one pass to catch up.
 *
 * @package WOW\MediaFolders
 */

namespace WOW\MediaFolders\CLI;

use function WOW\MediaFolders\Sync\album_post_type;
use function WOW\MediaFolders\Sync\can_sync;
use function WOW\MediaFolders\Sync\sync_album;

use const WOW\MediaFolders\Taxonomy\ALBUM_META;
use const WOW\MediaFolders\Taxonomy\TAXONOMY;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Files every album's images into its folder.
 *
 * ## EXAMPLES
 *
 *     wp wow-folders sync
 */
function sync(): void {
	if ( ! can_sync() ) {
		\WP_CLI::error( 'wow-blocks is not active, so albums cannot be read.' );
	}

	$albums = get_posts(
		array(
			'post_type'      => album_post_type(),
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);

	if ( array() === $albums ) {
		\WP_CLI::warning( 'No albums found.' );

		return;
	}

	$added = 0;

	foreach ( $albums as $id ) {
		$result = sync_album( (int) $id );
		$added += $result['added'];

		\WP_CLI::log(
			sprintf(
				'%s — %d added, %d removed',
				get_the_title( $id ),
				$result['added'],
				$result['removed']
			)
		);
	}

	\WP_CLI::success( sprintf( '%d album(s) processed, %d file(s) filed.', count( $albums ), $added ) );
}

/**
 * Lists folders whose album is gone, and deletes them only when told to.
 *
 * A folder outlives its album on purpose: it may hold files put there by
 * hand. So this never deletes anything unless --delete is passed, and it
 * always prints what it is about to touch first.
 *
 * ## OPTIONS
 *
 * [--delete]
 * : Actually remove the folders that were listed.
 *
 * ## EXAMPLES
 *
 *     wp wow-folders prune
 *     wp wow-folders prune --delete
 *
 * @param array<int,string>    $args       Positional arguments.
 * @param array<string,string> $assoc_args Flags.
 */
function prune( array $args, array $assoc_args ): void {
	$orphans = array();

	foreach ( get_terms( array( 'taxonomy' => TAXONOMY, 'hide_empty' => false ) ) as $term ) {
		$album = (int) get_term_meta( $term->term_id, ALBUM_META, true );

		if ( 0 === $album || get_post( $album ) instanceof \WP_Post ) {
			continue;
		}

		$orphans[] = $term;
	}

	if ( array() === $orphans ) {
		\WP_CLI::success( 'No folder has lost its album.' );

		return;
	}

	foreach ( $orphans as $term ) {
		\WP_CLI::log( sprintf( '#%d %s — %d file(s)', $term->term_id, $term->name, $term->count ) );
	}

	if ( empty( $assoc_args['delete'] ) ) {
		\WP_CLI::success( sprintf( '%d folder(s) listed. Pass --delete to remove them.', count( $orphans ) ) );

		return;
	}

	foreach ( $orphans as $term ) {
		wp_delete_term( $term->term_id, TAXONOMY );
	}

	\WP_CLI::success( sprintf( '%d folder(s) deleted. The files stay in the library.', count( $orphans ) ) );
}

\WP_CLI::add_command( 'wow-folders sync', __NAMESPACE__ . '\\sync' );
\WP_CLI::add_command( 'wow-folders prune', __NAMESPACE__ . '\\prune' );
