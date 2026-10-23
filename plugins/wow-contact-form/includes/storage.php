<?php
/**
 * Where the messages live.
 *
 * The database is the plugin's real delivery channel, not a backup of the
 * email. Mail is the part of any contact form most likely to fail quietly —
 * a host without a mail transport, a recipient's spam filter, an expired
 * relay — and a message that only ever existed in a failed email is simply
 * lost. Stored first, it is never lost.
 *
 * @package WOW\ContactForm
 */

namespace WOW\ContactForm\Storage;

use WOW\ContactForm\Fields;

defined( 'ABSPATH' ) || exit;

/** The post type holding the messages. */
const POST_TYPE = 'wow_message';

/** Prefix for every meta key this plugin writes. */
const META_PREFIX = '_wow_contact_';

/** The status a message gets when something about it looks automated. */
const SPAM = 'wow_spam';

/** Where the reason for that is written down. */
const WHY = '_wow_contact_spam_reason';

/**
 * The meta key a field is stored under.
 *
 * @param string $field Field key.
 * @return string Meta key.
 */
function meta_key( string $field ): string {
	return META_PREFIX . $field;
}

/**
 * Registers the post type and the meta behind it.
 *
 * Every primitive capability is named explicitly, and all of them point at
 * manage_options. Left at the default the post type would borrow the ones
 * for ordinary posts, and any contributor or author could then read the
 * names, addresses and messages of everyone who has written to the site.
 * A contact inbox is for whoever runs the site.
 *
 * The three meta capabilities — edit_post, read_post, delete_post — are
 * deliberately not in the list. They are resolved per record by
 * map_meta_cap(), which lands on the primitives above; and renaming one of
 * them registers the new name in a global table that rewrites every later
 * check of that name, site-wide. Pointing delete_post at manage_options
 * would turn each current_user_can( 'manage_options' ) in the admin into a
 * delete_post check with no post to check against, and WordPress says so
 * on every screen.
 *
 * create_posts is the exception: do_not_allow is WordPress's own never-granted
 * sentinel, and it removes the "Add New" button, the submenu entry and the
 * direct URL alike. wp_insert_post() checks no capabilities at all, so the
 * form still writes freely.
 */
function register(): void {
	$labels = array(
		'name'               => _x( 'Messages', 'post type general name', 'wow-contact-form' ),
		'singular_name'      => _x( 'Message', 'post type singular name', 'wow-contact-form' ),
		'menu_name'          => _x( 'Messages', 'admin menu', 'wow-contact-form' ),
		'all_items'          => __( 'All Messages', 'wow-contact-form' ),
		'edit_item'          => __( 'Message', 'wow-contact-form' ),
		'view_item'          => __( 'View Message', 'wow-contact-form' ),
		'search_items'       => __( 'Search Messages', 'wow-contact-form' ),
		'not_found'          => __( 'No messages yet', 'wow-contact-form' ),
		'not_found_in_trash' => __( 'No messages in the bin', 'wow-contact-form' ),
	);

	$capabilities = array(
		'create_posts'           => 'do_not_allow',
		'edit_posts'             => 'manage_options',
		'edit_others_posts'      => 'manage_options',
		'edit_published_posts'   => 'manage_options',
		'edit_private_posts'     => 'manage_options',
		'publish_posts'          => 'manage_options',
		'read_private_posts'     => 'manage_options',
		'delete_posts'           => 'manage_options',
		'delete_others_posts'    => 'manage_options',
		'delete_published_posts' => 'manage_options',
		'delete_private_posts'   => 'manage_options',
	);

	/*
	 * Suspected spam is kept rather than thrown away.
	 *
	 * Every rule that decides a message is automated can be wrong, and the
	 * two kinds of wrong are not worth the same: a spam message read and
	 * deleted costs a few seconds, a real enquiry silently discarded costs
	 * the job. So nothing is discarded. A message that trips a rule is
	 * filed under a status of its own, which gives the list its own tab and
	 * leaves the message one click from being put back.
	 */
	register_post_status(
		SPAM,
		array(
			'label'                     => _x( 'Spam', 'message status', 'wow-contact-form' ),
			'public'                    => false,
			'internal'                  => false,
			'protected'                 => true,
			'exclude_from_search'       => true,
			'show_in_admin_all_list'    => false,
			'show_in_admin_status_list' => true,
			/* translators: %s: number of messages. */
			'label_count'               => _n_noop( 'Spam <span class="count">(%s)</span>', 'Spam <span class="count">(%s)</span>', 'wow-contact-form' ),
		)
	);

	register_post_type(
		POST_TYPE,
		array(
			'labels'           => $labels,
			'public'           => false,
			'show_ui'          => true,
			'show_in_menu'     => true,
			'show_in_rest'     => false,
			'menu_icon'        => 'dashicons-email-alt',
			'menu_position'    => 26,
			'supports'         => array( 'title' ),
			'capabilities'     => $capabilities,
			'map_meta_cap'     => true,
			'has_archive'      => false,
			'rewrite'          => false,
			'query_var'        => false,
			'can_export'       => true,
			'delete_with_user' => false,
		)
	);

	foreach ( Fields\keys() as $field ) {
		if ( 'message' === $field ) {
			continue;
		}

		register_post_meta(
			POST_TYPE,
			meta_key( $field ),
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => static fn( $value ): string => Fields\sanitize( $field, (string) $value ),
			)
		);
	}

	foreach ( array( 'mail_status', 'mail_error' ) as $field ) {
		register_post_meta(
			POST_TYPE,
			meta_key( $field ),
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => 'sanitize_text_field',
			)
		);
	}
}
add_action( 'init', __NAMESPACE__ . '\\register', 10 );

/**
 * The title a message is filed under.
 *
 * Name and company, and no date: the list already has a date column, and
 * repeating it here would only make the title long enough to wrap.
 *
 * @param array<string,string> $values Cleaned values.
 * @return string A title, never empty.
 */
function title( array $values ): string {
	$name = trim( ( $values['first_name'] ?? '' ) . ' ' . ( $values['last_name'] ?? '' ) );

	if ( '' === $name ) {
		$name = $values['email'] ?? __( 'Message', 'wow-contact-form' );
	}

	if ( '' !== ( $values['company'] ?? '' ) ) {
		return sprintf(
			/* translators: 1: sender's name, 2: company name. */
			_x( '%1$s (%2$s)', 'message title', 'wow-contact-form' ),
			$name,
			$values['company']
		);
	}

	return $name;
}

/**
 * Files one message.
 *
 * wp_slash() on the way in is not optional. wp_insert_post() and the metadata
 * functions both expect their input escaped and unescape it themselves, so
 * text handed over raw comes back out with its backslashes eaten and its
 * quotes mangled — on a form whose whole purpose is to carry someone's words
 * accurately, that is the worst kind of bug: silent and unnoticed until
 * somebody writes an apostrophe.
 *
 * @param array<string,string> $values Cleaned values.
 * @return int|\WP_Error The new post ID, or the error that stopped it.
 */
function store( array $values, string $spam = '' ) {
	$post_id = wp_insert_post(
		array(
			'post_type'    => POST_TYPE,
			'post_status'  => '' === $spam ? 'publish' : SPAM,
			'post_author'  => 0,
			'post_title'   => wp_slash( title( $values ) ),
			'post_content' => wp_slash( $values['message'] ?? '' ),
		),
		true
	);

	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	foreach ( Fields\keys() as $field ) {
		if ( 'message' === $field ) {
			continue;
		}

		update_post_meta( $post_id, meta_key( $field ), wp_slash( $values[ $field ] ?? '' ) );
	}

	if ( '' !== $spam ) {
		update_post_meta( $post_id, WHY, wp_slash( $spam ) );
	}

	/**
	 * Fires once a message has been filed.
	 *
	 * @param int                  $post_id The stored message.
	 * @param array<string,string> $values  Cleaned values.
	 */
	do_action( 'wow_contact_form_stored', $post_id, $values );

	return $post_id;
}
