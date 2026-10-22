<?php
/**
 * Copying an image that is already in the library.
 *
 * Dragging a picture in from another tab normally means a link rather than a
 * file, and WordPress answers a link by fetching it: the REST media endpoint
 * hands the address to download_url() and the server downloads its own
 * picture over HTTP. When the picture belongs to this very site that is a
 * long way round, and two things go wrong at the end of it.
 *
 * The first is local and loud. Inside a container the site answers on one
 * port while its address names another, so the request never arrives and the
 * drop fails with "could not connect to server" — the site cannot reach
 * itself.
 *
 * The second is quiet and worse. What a browser hands over is the address of
 * the picture as that page displayed it, which here is a hard crop: 240 by
 * 350 pixels of a photograph that is much larger. Downloading that makes a
 * copy of the crop, and a crop cannot be cropped again.
 *
 * So a picture from this library is not fetched at all. The attachment is
 * found by its address, its file is copied on disk, and the copy becomes an
 * attachment of its own — a real second picture, which is the point: the
 * same photograph on two pages, croppable apart, and removing one leaves the
 * other alone.
 *
 * @package WOW\Blocks
 */

namespace WOW\Blocks\Duplicate;

defined( 'ABSPATH' ) || exit;

/** Where the route lives. */
const ROUTE = 'wow-blocks/v1';

/**
 * Hooked on rest_api_init.
 */
function register(): void {
	register_rest_route(
		ROUTE,
		'/duplicate',
		array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => __NAMESPACE__ . '\\handle',
			'permission_callback' => __NAMESPACE__ . '\\allowed',
			'args'                => array(
				'url' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'esc_url_raw',
				),
			),
		)
	);
}
add_action( 'rest_api_init', __NAMESPACE__ . '\\register' );

/**
 * Who may do this.
 *
 * The same right as uploading, because that is what it amounts to: one more
 * file in the library.
 */
function allowed(): bool {
	return current_user_can( 'upload_files' );
}

/**
 * The attachment an address belongs to, if any.
 *
 * An address found on a page is rarely the original. It is whichever size
 * that page asked for, so the name carries a -WxH that has to come off
 * before WordPress recognises it; and an oversized upload is stored under a
 * -scaled name that the sizes cut from it do not carry, so that has to go
 * back on.
 *
 * @param string $url The address dropped on the page.
 * @return int Zero when it belongs to no attachment here.
 */
function source_id( string $url ): int {
	$parts = wp_parse_url( $url );

	if ( ! is_array( $parts ) || empty( $parts['path'] ) ) {
		return 0;
	}

	// Query and fragment are no part of a file name. The port very much is:
	// without it the address stops matching the one the library knows, which
	// is exactly the case on a site published on a port of its own.
	$clean = sprintf(
		'%s://%s%s%s',
		$parts['scheme'] ?? 'http',
		$parts['host'] ?? '',
		empty( $parts['port'] ) ? '' : ':' . (int) $parts['port'],
		$parts['path']
	);

	$tries = array( $clean );

	$full = (string) preg_replace( '/-\d+x\d+(?=\.[A-Za-z0-9]+$)/', '', $clean );

	if ( $full !== $clean ) {
		$tries[] = $full;
		$tries[] = (string) preg_replace( '/(\.[A-Za-z0-9]+)$/', '-scaled$1', $full );
	}

	foreach ( $tries as $try ) {
		$id = (int) attachment_url_to_postid( $try );

		if ( $id > 0 ) {
			return $id;
		}
	}

	return 0;
}

/**
 * Makes the copy.
 *
 * @param \WP_REST_Request $request The request.
 * @return \WP_REST_Response|\WP_Error
 */
function handle( \WP_REST_Request $request ) {
	$id = source_id( (string) $request['url'] );

	if ( $id <= 0 || 'attachment' !== get_post_type( $id ) ) {
		return new \WP_Error(
			'wow_blocks_not_here',
			__( 'That picture is not in this library.', 'wow-blocks' ),
			array( 'status' => 404 )
		);
	}

	/*
	 * The file as it is now, which after an edit in the media editor is the
	 * edited one. A copy of what is on the page, not of what used to be.
	 */
	$path = get_attached_file( $id );

	if ( ! $path || ! file_exists( $path ) ) {
		return new \WP_Error(
			'wow_blocks_no_file',
			__( 'The picture is in the library but its file is missing.', 'wow-blocks' ),
			array( 'status' => 404 )
		);
	}

	$uploads = wp_upload_dir();

	if ( ! empty( $uploads['error'] ) ) {
		return new \WP_Error( 'wow_blocks_uploads', (string) $uploads['error'], array( 'status' => 500 ) );
	}

	$name = wp_unique_filename( $uploads['path'], wp_basename( $path ) );
	$copy = trailingslashit( $uploads['path'] ) . $name;

	if ( ! copy( $path, $copy ) ) {
		return new \WP_Error(
			'wow_blocks_copy',
			__( 'The picture could not be copied.', 'wow-blocks' ),
			array( 'status' => 500 )
		);
	}

	$source = get_post( $id );
	$type   = wp_check_filetype( $copy );

	$new = wp_insert_attachment(
		array(
			'post_mime_type' => $type['type'] ? $type['type'] : (string) get_post_mime_type( $id ),
			'post_title'     => $source instanceof \WP_Post && '' !== $source->post_title
				? $source->post_title
				: pathinfo( $name, PATHINFO_FILENAME ),
			'post_excerpt'   => $source instanceof \WP_Post ? $source->post_excerpt : '',
			'post_content'   => $source instanceof \WP_Post ? $source->post_content : '',
			'post_status'    => 'inherit',
		),
		$copy,
		0,
		true
	);

	if ( is_wp_error( $new ) ) {
		wp_delete_file( $copy );

		return $new;
	}

	require_once ABSPATH . 'wp-admin/includes/image.php';

	wp_update_attachment_metadata( $new, wp_generate_attachment_metadata( $new, $copy ) );

	$alt = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );

	if ( '' !== $alt ) {
		update_post_meta( $new, '_wp_attachment_image_alt', wp_slash( $alt ) );
	}

	return rest_ensure_response(
		array(
			'id'         => (int) $new,
			'source_url' => (string) wp_get_attachment_url( $new ),
			'alt_text'   => $alt,
			'copied'     => $id,
		)
	);
}
