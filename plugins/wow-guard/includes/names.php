<?php
/**
 * Keeping the list of who may log in out of public view.
 *
 * Guessing a password takes two things, and WordPress gives one of them away.
 * Ask for /?author=1 and it answers with a redirect to /author/<name>/; ask
 * the REST API for its users and it answers with a list. A machine that knows
 * the name has only one thing left to guess, and the limit on attempts is
 * then the only thing between it and the site.
 *
 * None of this hides anything that is genuinely published. An author archive
 * on a site whose pages are galleries lists nothing anybody came for.
 *
 * @package WOW\Guard
 */

namespace WOW\Guard\Names;

use WOW\Guard\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Whether this is switched on.
 */
function wanted(): bool {
	return (bool) Options\get( 'hide_names' );
}

/**
 * Turns /?author=1 and /author/<name>/ into a plain "not found".
 *
 * Not a redirect home: a redirect still answers the question, because where
 * it sends you tells you whether the number belonged to anybody.
 */
function hide_archives(): void {
	if ( ! wanted() || is_admin() ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading a public query argument.
	$asked = isset( $_GET['author'] ) || is_author();

	if ( ! $asked ) {
		return;
	}

	global $wp_query;

	$wp_query->set_404();
	status_header( 404 );
	nocache_headers();
}
add_action( 'template_redirect', __NAMESPACE__ . '\\hide_archives', 1 );

/**
 * Stops the author archive having a web address at all.
 *
 * Without this the link is still printed in places WordPress builds by
 * itself, which is an invitation to try it.
 *
 * @param mixed $link The address WordPress built.
 * @return mixed
 */
function no_author_link( $link ) {
	return wanted() ? home_url( '/' ) : $link;
}
add_filter( 'author_link', __NAMESPACE__ . '\\no_author_link', 20 );

/**
 * Refuses the REST list of users to anybody who is not already inside.
 *
 * Only the list, and only to strangers. The block editor asks the same
 * endpoint while somebody is writing a page, and breaking that to hide a name
 * would be a poor trade.
 *
 * @param mixed $result   What has been decided so far.
 * @param mixed $server   The REST server.
 * @param mixed $request  The request.
 * @return mixed
 */
function guard_rest( $result, $server = null, $request = null ) {
	if ( ! wanted() || ! is_null( $result ) || ! $request instanceof \WP_REST_Request ) {
		return $result;
	}

	if ( current_user_can( 'list_users' ) || is_user_logged_in() ) {
		return $result;
	}

	if ( 0 !== strpos( (string) $request->get_route(), '/wp/v2/users' ) ) {
		return $result;
	}

	return new \WP_Error(
		'rest_user_cannot_view',
		__( 'Sorry, you are not allowed to do that.', 'wow-guard' ),
		array( 'status' => rest_authorization_required_code() )
	);
}
add_filter( 'rest_pre_dispatch', __NAMESPACE__ . '\\guard_rest', 10, 3 );

/**
 * Takes the author's name back out of an oEmbed answer.
 *
 * @param mixed $data What WordPress was going to send.
 * @return mixed
 */
function quiet_oembed( $data ) {
	if ( wanted() && is_array( $data ) ) {
		unset( $data['author_name'], $data['author_url'] );
	}

	return $data;
}
add_filter( 'oembed_response_data', __NAMESPACE__ . '\\quiet_oembed', 20 );

/**
 * Keeps users out of the sitemap, where they would be a list of names again.
 *
 * @param mixed $provider The provider WordPress was going to use.
 * @param mixed $name     Which one it is.
 * @return mixed
 */
function quiet_sitemap( $provider, $name = '' ) {
	return wanted() && 'users' === $name ? false : $provider;
}
add_filter( 'wp_sitemaps_add_provider', __NAMESPACE__ . '\\quiet_sitemap', 10, 2 );
