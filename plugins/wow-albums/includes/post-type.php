<?php
/**
 * Registration of the Albums post type.
 *
 * @package WOW\Albums
 */

namespace WOW\Albums\PostType;

defined( 'ABSPATH' ) || exit;

/** The post type key. At most 20 characters, a WordPress limit. */
const POST_TYPE = 'wow_album';

/** Base permalink segment. */
const SLUG = 'albums';

/**
 * Hooked on init.
 */
function register(): void {
	$labels = array(
		'name'                  => _x( 'Albums', 'post type general name', 'wow-albums' ),
		'singular_name'         => _x( 'Album', 'post type singular name', 'wow-albums' ),
		'menu_name'             => _x( 'Albums', 'admin menu', 'wow-albums' ),
		'add_new'               => __( 'Add New', 'wow-albums' ),
		'add_new_item'          => __( 'Add New Album', 'wow-albums' ),
		'edit_item'             => __( 'Edit Album', 'wow-albums' ),
		'new_item'              => __( 'New Album', 'wow-albums' ),
		'view_item'             => __( 'View Album', 'wow-albums' ),
		'view_items'            => __( 'View Albums', 'wow-albums' ),
		'search_items'          => __( 'Search Albums', 'wow-albums' ),
		'not_found'             => __( 'No albums found', 'wow-albums' ),
		'not_found_in_trash'    => __( 'No albums found in Trash', 'wow-albums' ),
		'all_items'             => __( 'Albums', 'wow-albums' ),
		'archives'              => __( 'Album Archives', 'wow-albums' ),
		'featured_image'        => __( 'Album cover', 'wow-albums' ),
		'set_featured_image'    => __( 'Set cover', 'wow-albums' ),
		'remove_featured_image' => __( 'Remove cover', 'wow-albums' ),
		'use_featured_image'    => __( 'Use as cover', 'wow-albums' ),
	);

	register_post_type(
		POST_TYPE,
		array(
			'labels'        => $labels,
			'public'        => true,
			'has_archive'   => true,
			'hierarchical'  => false,
			'menu_position' => 5,
			'menu_icon'     => 'dashicons-format-gallery',
			'show_in_rest'  => true,
			'rewrite'       => array(
				'slug'       => SLUG,
				'with_front' => false,
			),
			'supports'      => array(
				'title',
				'editor',
				'thumbnail',
				'excerpt',
				'revisions',
				// Needed for menu_order, which the manual ordering runs on.
				'page-attributes',
			),
		)
	);
}
add_action( 'init', __NAMESPACE__ . '\\register' );

/**
 * The post type uses featured images, which the theme may not support.
 */
function support_thumbnails(): void {
	if ( ! current_theme_supports( 'post-thumbnails' ) ) {
		add_theme_support( 'post-thumbnails', array( POST_TYPE ) );

		return;
	}

	add_post_type_support( POST_TYPE, 'thumbnail' );
}
add_action( 'after_setup_theme', __NAMESPACE__ . '\\support_thumbnails', 20 );
