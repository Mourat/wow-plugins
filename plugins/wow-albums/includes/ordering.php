<?php
/**
 * Manual album ordering: drag and drop in the list, sorted by menu_order.
 *
 * @package WOW\Albums
 */

namespace WOW\Albums\Ordering;

use WP_Query;

use const WOW\Albums\PostType\POST_TYPE;

defined( 'ABSPATH' ) || exit;

const AJAX_ACTION = 'wow_albums_reorder';
const NONCE       = 'wow-albums-reorder';

/**
 * The default order is the manual one.
 *
 * Applies to the main query and to any query that asks for albums explicitly
 * without setting an order of its own. When orderby is set, we stay out.
 */
function default_order( WP_Query $query ): void {
	// Filter out other queries first: the hook is site-wide.
	$requested = (array) $query->get( 'post_type' );

	if ( ! in_array( POST_TYPE, $requested, true ) && ! $query->is_post_type_archive( POST_TYPE ) ) {
		return;
	}

	// orderby is not always a string; it can be an array such as
	// [ 'menu_order' => 'ASC' ], which is exactly what WordPress builds for
	// menus. So we test for emptiness rather than casting to a string.
	if ( ! empty( $query->get( 'orderby' ) ) ) {
		return;
	}

	$query->set( 'orderby', array( 'menu_order' => 'ASC', 'date' => 'DESC' ) );
}
add_action( 'pre_get_posts', __NAMESPACE__ . '\\default_order' );

/**
 * Whether dragging is allowed right now.
 *
 * The order is written from row positions, so it only means anything when
 * the list is shown in full and in manual order. Under a search, a status or
 * date filter, or a sort by another column, dragging is disabled.
 */
function can_sort(): bool {
	foreach ( array( 's', 'orderby', 'post_status', 'm' ) as $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading list parameters.
		if ( isset( $_GET[ $key ] ) && ! is_string( $_GET[ $key ] ) ) {
			// A parameter of an unexpected shape: the list may be filtered in
			// a way we do not expect, so the order is better left alone.
			return false;
		}
	}

	if ( '' !== request_string( 's' ) ) {
		return false;
	}

	$orderby = request_string( 'orderby' );

	if ( '' !== $orderby && 'menu_order' !== $orderby ) {
		return false;
	}

	$status = request_string( 'post_status' );

	if ( '' !== $status && 'all' !== $status ) {
		return false;
	}

	$month = request_string( 'm' );

	if ( '' !== $month && '0' !== $month ) {
		return false;
	}

	return current_user_can( 'edit_others_posts' );
}

/**
 * A string parameter of the list. Anything that is not a string counts as
 * absent.
 *
 * @param string $key Parameter name.
 */
function request_string( string $key ): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading list parameters.
	$value = $_GET[ $key ] ?? '';

	return is_string( $value ) ? sanitize_text_field( wp_unslash( $value ) ) : '';
}

/**
 * How many posts sit on the earlier pages of the list.
 *
 * Without this, dragging on page two would restart the order at zero and
 * tangle it with page one.
 */
function page_offset(): int {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading the page number.
	$paged = max( 1, (int) ( $_GET['paged'] ?? 1 ) );

	$per_page = (int) get_user_option( 'edit_' . POST_TYPE . '_per_page' );

	if ( $per_page < 1 ) {
		$per_page = 20;
	}

	return ( $paged - 1 ) * $per_page;
}

/**
 * @param string $hook Current admin page.
 */
function enqueue_assets( string $hook ): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- identifying the screen.
	if ( 'edit.php' !== $hook || POST_TYPE !== (string) ( $_GET['post_type'] ?? '' ) ) {
		return;
	}

	wp_enqueue_style(
		'wow-albums-admin',
		WOW_ALBUMS_URL . 'assets/admin.css',
		array(),
		(string) filemtime( WOW_ALBUMS_DIR . 'assets/admin.css' )
	);

	$sortable = can_sort();

	wp_enqueue_script(
		'wow-albums-order',
		WOW_ALBUMS_URL . 'assets/admin-order.js',
		array( 'jquery', 'jquery-ui-sortable' ),
		(string) filemtime( WOW_ALBUMS_DIR . 'assets/admin-order.js' ),
		true
	);

	wp_localize_script(
		'wow-albums-order',
		'wowAlbumsOrder',
		array(
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'action'   => AJAX_ACTION,
			'nonce'    => wp_create_nonce( NONCE ),
			'offset'   => page_offset(),
			'sortable' => $sortable,
			'lockedNotice' => __(
				'The order can only be changed in the full list, with no search, filters or column sorting.',
				'wow-albums'
			),
			'errorNotice'  => __( 'The order could not be saved. Reload the page and try again.', 'wow-albums' ),
		)
	);
}
add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_assets' );

/**
 * Takes in the new row order for the current page of the list.
 */
function ajax_reorder(): void {
	check_ajax_referer( NONCE, 'nonce' );

	if ( ! current_user_can( 'edit_others_posts' ) ) {
		wp_send_json_error( array( 'message' => __( 'You do not have permission to do that.', 'wow-albums' ) ), 403 );
	}

	$raw = isset( $_POST['order'] ) && is_array( $_POST['order'] ) ? wp_unslash( $_POST['order'] ) : array();
	$ids = array_values( array_filter( array_map( 'absint', $raw ) ) );

	if ( array() === $ids ) {
		wp_send_json_error( array( 'message' => __( 'The order is empty.', 'wow-albums' ) ), 400 );
	}

	$offset  = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;
	$updated = 0;

	foreach ( $ids as $position => $id ) {
		if ( POST_TYPE !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			continue;
		}

		$order = $offset + $position;

		if ( (int) get_post_field( 'menu_order', $id ) === $order ) {
			continue;
		}

		wp_update_post(
			array(
				'ID'         => $id,
				'menu_order' => $order,
			)
		);

		++$updated;
	}

	wp_send_json_success( array( 'updated' => $updated ) );
}
add_action( 'wp_ajax_' . AJAX_ACTION, __NAMESPACE__ . '\\ajax_reorder' );
