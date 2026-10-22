<?php
/**
 * Album list columns: the cover and the drag handle.
 *
 * @package WOW\Albums
 */

namespace WOW\Albums\AdminColumns;

use const WOW\Albums\PostType\POST_TYPE;

defined( 'ABSPATH' ) || exit;

const COLUMN_HANDLE    = 'wow_album_handle';
const COLUMN_THUMBNAIL = 'wow_album_thumbnail';

/**
 * Puts the cover right after the checkbox, before the title.
 *
 * @param array<string,string> $columns List columns.
 * @return array<string,string>
 */
function columns( array $columns ): array {
	$result = array();

	foreach ( $columns as $key => $label ) {
		$result[ $key ] = $label;

		if ( 'cb' === $key ) {
			$result[ COLUMN_HANDLE ]    = '';
			$result[ COLUMN_THUMBNAIL ] = __( 'Cover', 'wow-albums' );
		}
	}

	// There may be no checkbox, in which case the columns simply go first.
	if ( ! isset( $result[ COLUMN_THUMBNAIL ] ) ) {
		$result = array( COLUMN_HANDLE => '', COLUMN_THUMBNAIL => __( 'Cover', 'wow-albums' ) ) + $result;
	}

	return $result;
}
add_filter( 'manage_' . POST_TYPE . '_posts_columns', __NAMESPACE__ . '\\columns' );

/**
 * @param string $column  Column key.
 * @param int    $post_id Post ID.
 */
function render( string $column, int $post_id ): void {
	if ( COLUMN_HANDLE === $column ) {
		printf(
			'<span class="wow-album-handle dashicons dashicons-menu" title="%s" aria-hidden="true"></span>',
			esc_attr__( 'Drag to reorder', 'wow-albums' )
		);

		return;
	}

	if ( COLUMN_THUMBNAIL !== $column ) {
		return;
	}

	$thumbnail = get_the_post_thumbnail( $post_id, 'thumbnail', array( 'class' => 'wow-album-thumb' ) );

	if ( '' === $thumbnail ) {
		printf(
			'<span class="wow-album-thumb wow-album-thumb--empty">%s</span>',
			esc_html__( 'no cover', 'wow-albums' )
		);

		return;
	}

	echo wp_kses_post( $thumbnail );
}
add_action( 'manage_' . POST_TYPE . '_posts_custom_column', __NAMESPACE__ . '\\render', 10, 2 );
