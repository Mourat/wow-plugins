<?php
/**
 * Filling folders in from albums.
 *
 * An album already knows which images it holds, and wow-blocks already knows
 * how to read that out of the block tree. This file only turns that list into
 * term assignments, and takes care never to undo work done by hand.
 *
 * The dependency on wow-blocks is soft: without it the automation simply
 * stays off and every other part of the plugin keeps working.
 *
 * @package WOW\MediaFolders
 */

namespace WOW\MediaFolders\Sync;

use function WOW\MediaFolders\Taxonomy\album_folder;

use const WOW\MediaFolders\Taxonomy\TAXONOMY;

defined( 'ABSPATH' ) || exit;

/**
 * Attachment meta listing the folders this plugin assigned by itself.
 *
 * Anything not in this list was put there by a person, and the automation
 * must leave it alone.
 */
const AUTO_META = '_wow_media_folder_auto';

/**
 * The album post type.
 *
 * Read through the filter wow-blocks publishes, so neither plugin has to
 * hard-wire the other's key.
 */
function album_post_type(): string {
	return (string) apply_filters( 'wow_blocks_album_post_type', 'wow_album' );
}

/**
 * Whether wow-blocks is present and new enough to list an album's images.
 */
function can_sync(): bool {
	return function_exists( 'WOW\\Blocks\\Albums\\image_ids' );
}

/**
 * Attachments that belong in an album's folder.
 *
 * Divider strips count here, unlike in the image counter: the strip is not
 * work on display, but its picture still belongs to the album.
 *
 * @return int[]
 */
function album_attachments( int $post_id ): array {
	if ( ! can_sync() ) {
		return array();
	}

	$ids   = \WOW\Blocks\Albums\image_ids( $post_id, true );
	$cover = (int) get_post_thumbnail_id( $post_id );

	if ( $cover > 0 ) {
		$ids[] = $cover;
	}

	return array_values( array_unique( array_filter( $ids ) ) );
}

/**
 * Brings an album's folder in line with its contents.
 *
 * @param int $post_id Album ID.
 * @return array{term:int,added:int,removed:int} What changed, term 0 when nothing was done.
 */
function sync_album( int $post_id ): array {
	$none = array(
		'term'    => 0,
		'added'   => 0,
		'removed' => 0,
	);

	if ( ! can_sync() ) {
		return $none;
	}

	$post = get_post( $post_id );

	if ( ! $post instanceof \WP_Post || album_post_type() !== $post->post_type ) {
		return $none;
	}

	$term = album_folder( $post_id, get_the_title( $post ), true );

	if ( ! $term instanceof \WP_Term ) {
		return $none;
	}

	$wanted  = album_attachments( $post_id );
	$current = array_map( 'intval', (array) get_objects_in_term( $term->term_id, TAXONOMY ) );

	$added = 0;

	foreach ( array_diff( $wanted, $current ) as $id ) {
		if ( 'attachment' !== get_post_type( $id ) ) {
			continue;
		}

		wp_set_object_terms( $id, array( $term->term_id ), TAXONOMY, true );
		remember( $id, $term->term_id );
		++$added;
	}

	$removed = 0;

	foreach ( array_diff( $current, $wanted ) as $id ) {
		// Only ever take back what this plugin put there.
		if ( ! is_ours( $id, $term->term_id ) ) {
			continue;
		}

		wp_remove_object_terms( $id, array( $term->term_id ), TAXONOMY );
		forget( $id, $term->term_id );
		++$removed;
	}

	return array(
		'term'    => $term->term_id,
		'added'   => $added,
		'removed' => $removed,
	);
}

/**
 * Folders this plugin assigned to an attachment.
 *
 * The array check is not belt and braces: get_post_meta() answers an absent
 * key with an empty string, and casting that to an array yields array( '' ),
 * which would go on to be stored as a folder numbered zero.
 *
 * @return int[]
 */
function auto_terms( int $attachment_id ): array {
	$stored = get_post_meta( $attachment_id, AUTO_META, true );

	if ( ! is_array( $stored ) ) {
		return array();
	}

	return array_values( array_filter( array_map( 'intval', $stored ) ) );
}

/**
 * Whether a folder sits on an attachment because the automation put it there.
 */
function is_ours( int $attachment_id, int $term_id ): bool {
	return in_array( $term_id, auto_terms( $attachment_id ), true );
}

/**
 * Records a folder as automatically assigned.
 */
function remember( int $attachment_id, int $term_id ): void {
	$terms = auto_terms( $attachment_id );

	if ( in_array( $term_id, $terms, true ) ) {
		return;
	}

	$terms[] = $term_id;

	update_post_meta( $attachment_id, AUTO_META, $terms );
}

/**
 * Drops a folder from the automatically assigned list.
 */
function forget( int $attachment_id, int $term_id ): void {
	$terms = array_values( array_diff( auto_terms( $attachment_id ), array( $term_id ) ) );

	if ( array() === $terms ) {
		delete_post_meta( $attachment_id, AUTO_META );

		return;
	}

	update_post_meta( $attachment_id, AUTO_META, $terms );
}

/**
 * Marks a folder as manual, so the automation stops managing it.
 *
 * Called when a person assigns a folder by hand to something the automation
 * had also claimed: from then on the person's choice wins.
 */
function release( int $attachment_id, int $term_id ): void {
	forget( $attachment_id, $term_id );
}

/**
 * Re-syncs when an album is saved.
 *
 * @param int      $post_id Post ID.
 * @param \WP_Post $post    The post.
 */
function refresh_on_save( int $post_id, \WP_Post $post ): void {
	if ( album_post_type() !== $post->post_type ) {
		return;
	}

	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	/*
	 * An auto-draft is created by merely opening "Add New Album", carries the
	 * placeholder title WordPress gives it, and is thrown away a week later
	 * if nothing is written into it. A folder named after one is noise that
	 * outlives the post it was named for.
	 */
	if ( 'auto-draft' === $post->post_status ) {
		return;
	}

	sync_album( $post_id );
}
add_action( 'save_post', __NAMESPACE__ . '\\refresh_on_save', 20, 2 );

/**
 * Removes the folder when its album is deleted for good.
 *
 * Trashing is deliberately not enough: a trashed album can be restored, and
 * its folder holds the filing that would have to be done again by hand. This
 * runs on the permanent deletion only — emptying the trash, or WordPress
 * clearing out stale auto-drafts.
 *
 * The files themselves stay in the library. A folder is a label, and removing
 * the label removes nothing else.
 *
 * @param int $post_id Post about to be deleted.
 */
function remove_on_delete( int $post_id ): void {
	$post = get_post( $post_id );

	if ( ! $post instanceof \WP_Post || album_post_type() !== $post->post_type ) {
		return;
	}

	// An empty title asks album_folder() to look the folder up without renaming it.
	$term = album_folder( $post_id, '', false );

	if ( $term instanceof \WP_Term ) {
		wp_delete_term( $term->term_id, TAXONOMY );
	}
}
add_action( 'before_delete_post', __NAMESPACE__ . '\\remove_on_delete' );
