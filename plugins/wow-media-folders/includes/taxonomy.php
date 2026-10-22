<?php
/**
 * Registration of the media folder taxonomy.
 *
 * Folders are terms, not directories. Files stay where WordPress put them in
 * uploads/<year>/<month>, so a folder can be renamed or deleted without
 * breaking a single URL.
 *
 * @package WOW\MediaFolders
 */

namespace WOW\MediaFolders\Taxonomy;

defined( 'ABSPATH' ) || exit;

/** The taxonomy key. At most 32 characters, a WordPress limit. */
const TAXONOMY = 'wow_media_folder';

/** Term meta linking a folder back to the album that owns it. */
const ALBUM_META = 'wow_album_id';

/**
 * Hooked on init.
 */
function register(): void {
	$labels = array(
		'name'                       => _x( 'Folders', 'taxonomy general name', 'wow-media-folders' ),
		'singular_name'              => _x( 'Folder', 'taxonomy singular name', 'wow-media-folders' ),
		'menu_name'                  => __( 'Folders', 'wow-media-folders' ),
		'all_items'                  => __( 'All Folders', 'wow-media-folders' ),
		'edit_item'                  => __( 'Edit Folder', 'wow-media-folders' ),
		'view_item'                  => __( 'View Folder', 'wow-media-folders' ),
		'update_item'                => __( 'Update Folder', 'wow-media-folders' ),
		'add_new_item'               => __( 'Add New Folder', 'wow-media-folders' ),
		'new_item_name'              => __( 'New folder name', 'wow-media-folders' ),
		'search_items'               => __( 'Search Folders', 'wow-media-folders' ),
		'not_found'                  => __( 'No folders found', 'wow-media-folders' ),
		'no_terms'                   => __( 'No folders', 'wow-media-folders' ),
		'separate_items_with_commas' => __( 'Separate folders with commas', 'wow-media-folders' ),
		'add_or_remove_items'        => __( 'Add or remove folders', 'wow-media-folders' ),
		'choose_from_most_used'      => __( 'Choose from the most used folders', 'wow-media-folders' ),
		'back_to_items'              => __( '&larr; Go to Folders', 'wow-media-folders' ),
	);

	register_taxonomy(
		TAXONOMY,
		'attachment',
		array(
			'labels'             => $labels,
			'hierarchical'       => false,
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'show_admin_column'  => true,
			'show_in_rest'       => true,
			'query_var'          => TAXONOMY,
			'rewrite'            => false,

			/*
			 * Without this WordPress picks _update_post_term_count(), which
			 * counts attachments under `post_status = 'inherit' AND
			 * post_parent > 0`. Files that were never attached to a post are
			 * the majority of any media library, so every folder would report
			 * zero while holding hundreds of images. The generic callback just
			 * counts rows in term_relationships.
			 */
			'update_count_callback' => '_update_generic_term_count',
		)
	);
}
add_action( 'init', __NAMESPACE__ . '\\register' );

/**
 * The folder that belongs to an album.
 *
 * Folders are tied to albums by term meta rather than by name, so renaming an
 * album renames its folder instead of leaving a second one behind. The slug is
 * deliberately left alone: filter links are built from it.
 *
 * @param int    $post_id Album ID.
 * @param string $title   Album title, used when the folder has to be created.
 * @param bool   $create  Whether to create the folder when it does not exist.
 * @return \WP_Term|null The folder, or null when there is none.
 */
function album_folder( int $post_id, string $title, bool $create = false ): ?\WP_Term {
	$found = get_terms(
		array(
			'taxonomy'   => TAXONOMY,
			'hide_empty' => false,
			'number'     => 1,
			'meta_key'   => ALBUM_META,   // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one album, one folder.
			'meta_value' => (string) $post_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		)
	);

	if ( ! is_wp_error( $found ) && array() !== $found ) {
		$term = $found[0];

		if ( '' !== $title && $term->name !== $title ) {
			wp_update_term( $term->term_id, TAXONOMY, array( 'name' => $title ) );

			$term = get_term( $term->term_id, TAXONOMY );
		}

		return $term instanceof \WP_Term ? $term : null;
	}

	if ( ! $create || '' === $title ) {
		return null;
	}

	return claim_folder( $post_id, $title );
}

/**
 * Creates the folder for an album, or adopts a matching loose one.
 *
 * A folder of the same name may already exist because it was made by hand
 * before the album was. Adopting it is friendlier than leaving a duplicate
 * next to it, but only when it is not already spoken for.
 *
 * @param int    $post_id Album ID.
 * @param string $title   Album title.
 * @return \WP_Term|null The folder, or null when it could not be created.
 */
function claim_folder( int $post_id, string $title ): ?\WP_Term {
	$existing = get_term_by( 'name', $title, TAXONOMY );

	if ( $existing instanceof \WP_Term ) {
		$owner = (int) get_term_meta( $existing->term_id, ALBUM_META, true );

		if ( 0 === $owner ) {
			update_term_meta( $existing->term_id, ALBUM_META, $post_id );

			return $existing;
		}

		if ( $owner === $post_id ) {
			return $existing;
		}

		/*
		 * Two albums carrying the same title. Names have to stay unique
		 * within a flat taxonomy, so the newcomer gets the album ID appended
		 * rather than silently joining the other album's folder.
		 */
		$title = sprintf( '%s (%d)', $title, $post_id );
	}

	$created = wp_insert_term( $title, TAXONOMY );

	if ( is_wp_error( $created ) ) {
		return null;
	}

	update_term_meta( (int) $created['term_id'], ALBUM_META, $post_id );

	$term = get_term( (int) $created['term_id'], TAXONOMY );

	return $term instanceof \WP_Term ? $term : null;
}
