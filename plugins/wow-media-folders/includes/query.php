<?php
/**
 * The "no folder" filter.
 *
 * Every other filter rides on the taxonomy's query_var, which core threads
 * all the way through by itself. "No folder" cannot: it is a NOT EXISTS, and
 * a query_var only ever names a term. So it travels as its own parameter and
 * is turned into a tax_query on both roads into the library — the list view
 * through WP_Query, the grid and the modal through admin-ajax.
 *
 * @package WOW\MediaFolders
 */

namespace WOW\MediaFolders\Query;

use const WOW\MediaFolders\Taxonomy\TAXONOMY;

defined( 'ABSPATH' ) || exit;

/** Request parameter asking for attachments in no folder at all. */
const NONE_PARAM = 'wow_folder_none';

/**
 * The tax_query clause matching attachments without a single folder.
 *
 * @return array<int,array<string,mixed>>
 */
function none_clause(): array {
	return array(
		array(
			'taxonomy' => TAXONOMY,
			'operator' => 'NOT EXISTS',
		),
	);
}

/**
 * Whether the current request asked for unfiled attachments.
 */
function wants_none(): bool {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading a list filter.
	return ! empty( $_GET[ NONE_PARAM ] );
}

/**
 * Applies the filter to the list view.
 *
 * @param \WP_Query $query The query about to run.
 */
function filter_list( \WP_Query $query ): void {
	if ( ! is_admin() || ! $query->is_main_query() || ! wants_none() ) {
		return;
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	if ( ! $screen || 'upload' !== $screen->id ) {
		return;
	}

	$query->set( 'tax_query', none_clause() );
}
add_action( 'pre_get_posts', __NAMESPACE__ . '\\filter_list' );

/**
 * Applies the filter to the grid and to the media modal.
 *
 * The parameter is not a taxonomy query_var, so core strips it from the
 * request before it ever reaches WP_Query. This filter runs after that, which
 * is exactly what it is there for.
 *
 * @param array<string,mixed> $args Query arguments core assembled.
 * @return array<string,mixed>
 */
function filter_grid( array $args ): array {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- core checks capability in the handler.
	$query = isset( $_REQUEST['query'] ) && is_array( $_REQUEST['query'] ) ? wp_unslash( $_REQUEST['query'] ) : array();

	if ( empty( $query[ NONE_PARAM ] ) ) {
		return $args;
	}

	$args['tax_query'] = none_clause();

	return $args;
}
add_filter( 'ajax_query_attachments_args', __NAMESPACE__ . '\\filter_grid' );
