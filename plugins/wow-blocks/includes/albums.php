<?php
/**
 * Album data for the front-page grid.
 *
 * The post type lives in the wow-albums plugin, but only this plugin can
 * count the images, because it knows how the blocks are built. The post
 * type key goes through a filter so the two are not hard-wired together.
 *
 * @package WOW\Blocks
 */

namespace WOW\Blocks\Albums;

defined( 'ABSPATH' ) || exit;

/** Where the counted number of images is stored. */
const COUNT_META = '_wow_album_image_count';

/**
 * The album post type.
 */
function post_type(): string {
	return (string) apply_filters( 'wow_blocks_album_post_type', 'wow_album' );
}

/**
 * How many images an album holds.
 *
 * The value is cached in post meta and recounted when the post is saved:
 * parsing post_content on every front-page view would be expensive.
 */
function image_count( int $post_id ): int {
	$cached = get_post_meta( $post_id, COUNT_META, true );

	if ( '' !== $cached ) {
		return (int) $cached;
	}

	return refresh_image_count( $post_id );
}

/**
 * Recounts the images and stores the result.
 */
function refresh_image_count( int $post_id ): int {
	$post = get_post( $post_id );

	if ( ! $post instanceof \WP_Post ) {
		return 0;
	}

	$count = count_images_in_blocks( parse_blocks( $post->post_content ) );

	update_post_meta( $post_id, COUNT_META, $count );

	return $count;
}

/**
 * Attachment IDs of the images an album holds.
 *
 * Zeros are stripped, so the result is usable directly for assigning terms
 * or anything else that needs real attachments.
 *
 * @param int  $post_id          Album ID.
 * @param bool $include_dividers Whether narrow strips count as content.
 * @return int[] Unique attachment IDs, in the order they appear.
 */
function image_ids( int $post_id, bool $include_dividers = false ): array {
	$post = get_post( $post_id );

	if ( ! $post instanceof \WP_Post ) {
		return array();
	}

	$ids = collect_image_ids( parse_blocks( $post->post_content ), $include_dividers );

	return array_values( array_unique( array_filter( $ids ) ) );
}

/**
 * Counts the images in a block tree.
 *
 * The narrow divider strip does not count: it splits the gallery into
 * parts rather than showing work. Solid colour fills are not images either.
 *
 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
 */
function count_images_in_blocks( array $blocks ): int {
	return count( collect_image_ids( $blocks, false ) );
}

/**
 * Walks a block tree and lists the images it holds.
 *
 * An image pasted in as a bare URL has no attachment behind it, yet it is
 * still an image on the page. Those come back as 0 so that counting stays
 * honest; callers that need real attachments filter the zeros out.
 *
 * @param array<int,array<string,mixed>> $blocks           Parsed blocks.
 * @param bool                           $include_dividers Whether narrow strips count.
 * @return int[] One entry per image, 0 where there is no attachment.
 */
function collect_image_ids( array $blocks, bool $include_dividers ): array {
	$ids = array();

	foreach ( $blocks as $block ) {
		$name = (string) ( $block['blockName'] ?? '' );

		// The divider is skipped entirely, image and all, unless asked for.
		if ( 'wow/media-band' === $name && ! $include_dividers ) {
			continue;
		}

		if ( 'wow/media-band' === $name ) {
			$image = $block['attrs']['image'] ?? array();

			if ( is_array( $image ) && ( ! empty( $image['id'] ) || ! empty( $image['url'] ) ) ) {
				$ids[] = (int) ( $image['id'] ?? 0 );
			}
		}

		if ( 'wow/media-row' === $name ) {
			$ids = array_merge( $ids, cell_image_ids( $block['attrs']['cells'] ?? array() ) );
		}

		if ( 'core/image' === $name ) {
			$attrs = $block['attrs'] ?? array();

			if ( ! empty( $attrs['id'] ) || ! empty( $attrs['url'] ) ) {
				$ids[] = (int) ( $attrs['id'] ?? 0 );
			}
		}

		// A gallery keeps its images as inner blocks, so the recursion below
		// picks them up. The legacy format stores only a list of IDs, and
		// there is nothing else to read there.
		if ( 'core/gallery' === $name && empty( $block['innerBlocks'] ) ) {
			foreach ( (array) ( $block['attrs']['ids'] ?? array() ) as $id ) {
				$ids[] = (int) $id;
			}
		}

		if ( ! empty( $block['innerBlocks'] ) ) {
			$ids = array_merge( $ids, collect_image_ids( $block['innerBlocks'], $include_dividers ) );
		}
	}

	return $ids;
}

/**
 * Images held by the cells of a row.
 *
 * @param mixed $cells Value of the cells attribute.
 * @return int[] One entry per filled cell, 0 where there is no attachment.
 */
function cell_image_ids( $cells ): array {
	if ( ! is_array( $cells ) ) {
		return array();
	}

	$ids = array();

	foreach ( $cells as $cell ) {
		if ( ! is_array( $cell ) || 'color' === ( $cell['kind'] ?? '' ) ) {
			continue;
		}

		if ( ! empty( $cell['id'] ) || ! empty( $cell['url'] ) ) {
			$ids[] = (int) ( $cell['id'] ?? 0 );
		}
	}

	return $ids;
}

/**
 * Recounts when an album is saved.
 *
 * @param int      $post_id Post ID.
 * @param \WP_Post $post    The post.
 */
function refresh_on_save( int $post_id, \WP_Post $post ): void {
	if ( post_type() !== $post->post_type ) {
		return;
	}

	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	refresh_image_count( $post_id );
}
add_action( 'save_post', __NAMESPACE__ . '\\refresh_on_save', 20, 2 );
