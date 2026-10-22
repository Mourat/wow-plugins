<?php
/**
 * Everything the folder panel changes.
 *
 * One action with a task parameter rather than four registrations: the guards
 * are identical for all of them, and keeping them in one place means none can
 * be forgotten.
 *
 * @package WOW\MediaFolders
 */

namespace WOW\MediaFolders\Ajax;

use function WOW\MediaFolders\Sync\release;

use const WOW\MediaFolders\AdminSidebar\AJAX_ACTION;
use const WOW\MediaFolders\AdminSidebar\NONCE;
use const WOW\MediaFolders\Taxonomy\TAXONOMY;

defined( 'ABSPATH' ) || exit;

/**
 * Handles every change the panel can make.
 */
function handle(): void {
	check_ajax_referer( NONCE, 'nonce' );

	if ( ! current_user_can( 'upload_files' ) ) {
		wp_send_json_error( array( 'message' => __( 'You do not have permission to do that.', 'wow-media-folders' ) ), 403 );
	}

	$task = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( $_POST['task'] ) ) : '';

	switch ( $task ) {
		case 'assign':
			assign();
			break;
		case 'create':
			create();
			break;
		case 'rename':
			rename();
			break;
		case 'delete':
			remove();
			break;
		default:
			wp_send_json_error( array( 'message' => __( 'Unknown request.', 'wow-media-folders' ) ), 400 );
	}
}
add_action( 'wp_ajax_' . AJAX_ACTION, __NAMESPACE__ . '\\handle' );

/**
 * Only people who may manage terms get to reshape the folder list.
 */
function require_term_rights(): void {
	if ( ! current_user_can( 'manage_categories' ) ) {
		wp_send_json_error( array( 'message' => __( 'You do not have permission to do that.', 'wow-media-folders' ) ), 403 );
	}
}

/**
 * Files attachments into a folder, or takes them out of every folder.
 */
function assign(): void {
	$raw = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? wp_unslash( $_POST['ids'] ) : array();
	$ids = array_values( array_filter( array_map( 'absint', $raw ) ) );

	if ( array() === $ids ) {
		wp_send_json_error( array( 'message' => __( 'Nothing to move.', 'wow-media-folders' ) ), 400 );
	}

	$term_id = isset( $_POST['term'] ) ? absint( wp_unslash( $_POST['term'] ) ) : 0;
	$term    = $term_id > 0 ? get_term( $term_id, TAXONOMY ) : null;

	if ( $term_id > 0 && ! $term instanceof \WP_Term ) {
		wp_send_json_error( array( 'message' => __( 'No such folder.', 'wow-media-folders' ) ), 404 );
	}

	$moved = 0;

	foreach ( $ids as $id ) {
		if ( 'attachment' !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			continue;
		}

		if ( 0 === $term_id ) {
			wp_set_object_terms( $id, array(), TAXONOMY );
			delete_post_meta( $id, \WOW\MediaFolders\Sync\AUTO_META );
			++$moved;

			continue;
		}

		/*
		 * A flat set of folders still allows several per file, but dropping
		 * one onto a folder means "put it here", not "add it here as well".
		 * The file is moved, and the move is a human decision, so the album
		 * automation stops managing any of this file's folders.
		 */
		wp_set_object_terms( $id, array( $term->term_id ), TAXONOMY );
		delete_post_meta( $id, \WOW\MediaFolders\Sync\AUTO_META );
		++$moved;
	}

	wp_send_json_success(
		array(
			'moved'  => $moved,
			'counts' => counts(),
		)
	);
}

/**
 * Creates a folder.
 */
function create(): void {
	require_term_rights();

	$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

	if ( '' === $name ) {
		wp_send_json_error( array( 'message' => __( 'A folder needs a name.', 'wow-media-folders' ) ), 400 );
	}

	$created = wp_insert_term( $name, TAXONOMY );

	if ( is_wp_error( $created ) ) {
		wp_send_json_error( array( 'message' => $created->get_error_message() ), 400 );
	}

	$term = get_term( (int) $created['term_id'], TAXONOMY );

	wp_send_json_success(
		array(
			'term'   => array(
				'id'    => $term->term_id,
				'name'  => $term->name,
				'slug'  => $term->slug,
				'count' => $term->count,
			),
			'counts' => counts(),
		)
	);
}

/**
 * Renames a folder.
 *
 * The slug stays as it was: filter links are built from it, and a bookmarked
 * library view should survive a change of wording.
 */
function rename(): void {
	require_term_rights();

	$term_id = isset( $_POST['term'] ) ? absint( wp_unslash( $_POST['term'] ) ) : 0;
	$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
	$term    = $term_id > 0 ? get_term( $term_id, TAXONOMY ) : null;

	if ( ! $term instanceof \WP_Term ) {
		wp_send_json_error( array( 'message' => __( 'No such folder.', 'wow-media-folders' ) ), 404 );
	}

	if ( '' === $name ) {
		wp_send_json_error( array( 'message' => __( 'A folder needs a name.', 'wow-media-folders' ) ), 400 );
	}

	$updated = wp_update_term( $term->term_id, TAXONOMY, array( 'name' => $name ) );

	if ( is_wp_error( $updated ) ) {
		wp_send_json_error( array( 'message' => $updated->get_error_message() ), 400 );
	}

	wp_send_json_success( array( 'name' => $name ) );
}

/**
 * Deletes a folder.
 *
 * The attachments are untouched: a folder is a label, and losing the label
 * must never look like losing the files.
 */
function remove(): void {
	require_term_rights();

	$term_id = isset( $_POST['term'] ) ? absint( wp_unslash( $_POST['term'] ) ) : 0;
	$term    = $term_id > 0 ? get_term( $term_id, TAXONOMY ) : null;

	if ( ! $term instanceof \WP_Term ) {
		wp_send_json_error( array( 'message' => __( 'No such folder.', 'wow-media-folders' ) ), 404 );
	}

	foreach ( get_objects_in_term( $term->term_id, TAXONOMY ) as $id ) {
		release( (int) $id, $term->term_id );
	}

	wp_delete_term( $term->term_id, TAXONOMY );

	wp_send_json_success( array( 'counts' => counts() ) );
}

/**
 * Fresh counts for the panel, so it never shows a stale number.
 *
 * @return array<string,int>
 */
function counts(): array {
	$counts = array(
		'all'  => array_sum( (array) wp_count_attachments() ),
		'none' => \WOW\MediaFolders\AdminSidebar\unfiled_count(),
	);

	foreach ( get_terms( array( 'taxonomy' => TAXONOMY, 'hide_empty' => false ) ) as $term ) {
		$counts[ (string) $term->term_id ] = (int) $term->count;
	}

	return $counts;
}
