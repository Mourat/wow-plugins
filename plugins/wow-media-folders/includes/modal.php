<?php
/**
 * The folder filter inside the media modal.
 *
 * The panel on upload.php cannot be reused here: the modal is a Backbone
 * application that redraws its own toolbar, and anything dropped into its
 * markup from outside disappears the next time it does. So the modal gets a
 * dropdown built the way core builds its own "Images / Audio / Video" one,
 * added to the same toolbar. Filtering itself rides on the collection's
 * props, exactly as the grid does.
 *
 * @package WOW\MediaFolders
 */

namespace WOW\MediaFolders\Modal;

use function WOW\MediaFolders\AdminSidebar\folders;
use function WOW\MediaFolders\AdminSidebar\on_library;

use const WOW\MediaFolders\Query\NONE_PARAM;
use const WOW\MediaFolders\Taxonomy\TAXONOMY;

defined( 'ABSPATH' ) || exit;

/**
 * The folders as the dropdown needs them.
 *
 * Counts come from the term rows, which WordPress keeps up to date. The two
 * fixed entries carry no count on purpose: "no folder" can only be counted
 * with a NOT EXISTS over the whole library, and that is not worth a query on
 * every editor screen for a number nobody filters by.
 *
 * @return array<int,array<string,string>>
 */
function options(): array {
	$options = array();

	foreach ( folders() as $term ) {
		$options[] = array(
			'slug' => $term->slug,
			'text' => sprintf( '%s (%s)', $term->name, number_format_i18n( $term->count ) ),
		);
	}

	return $options;
}

/**
 * Adds the dropdown wherever the media modal can be opened.
 *
 * Hooked on wp_enqueue_media rather than on a screen: the modal turns up in
 * the post editor, the site editor, the widgets screen and the customiser,
 * and that action fires on all of them and nowhere else.
 *
 * The media library screen is left out — it has the panel, and two ways to
 * filter the same list side by side would only disagree with each other.
 */
function enqueue(): void {
	if ( on_library() || ! current_user_can( 'upload_files' ) ) {
		return;
	}

	wp_enqueue_style(
		'wow-media-folders-modal',
		WOW_MEDIA_FOLDERS_URL . 'assets/modal.css',
		array( 'media-views' ),
		(string) filemtime( WOW_MEDIA_FOLDERS_DIR . 'assets/modal.css' )
	);

	wp_enqueue_script(
		'wow-media-folders-modal',
		WOW_MEDIA_FOLDERS_URL . 'assets/modal.js',
		array( 'media-views' ),
		(string) filemtime( WOW_MEDIA_FOLDERS_DIR . 'assets/modal.js' ),
		true
	);

	wp_localize_script(
		'wow-media-folders-modal',
		'wowMediaFoldersModal',
		array(
			'taxonomy'  => TAXONOMY,
			'noneParam' => NONE_PARAM,
			'folders'   => options(),
			'strings'   => array(
				'label' => __( 'Filter by folder', 'wow-media-folders' ),
				'all'   => __( 'All folders', 'wow-media-folders' ),
				'none'  => __( 'No folder', 'wow-media-folders' ),
			),
		)
	);
}
add_action( 'wp_enqueue_media', __NAMESPACE__ . '\\enqueue' );
