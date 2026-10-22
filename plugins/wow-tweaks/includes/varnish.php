<?php
/**
 * Emptying the cache in front of the site, from inside the site.
 *
 * The host puts a Varnish in front of arleamh.com and a button in its own
 * control panel. Leaving a page and then going to another website to make
 * the change appear is the kind of errand that gets forgotten, so the
 * button is brought here, into the bar at the top of every admin screen.
 *
 * What Varnish answers to is not standardised and the host documents
 * nothing, so this does not guess once and hope: it tries the three ways
 * that exist in the wild, in order, and stops at the first that is accepted.
 *
 *     PURGE  /          with X-Purge-Method: regex and a pattern of .*
 *     BAN    /          with X-Ban-Url: .*
 *     PURGE  /          plainly, which clears the front page alone on some
 *                       setups and everything on others
 *
 * Every answer is reported, not only the one that worked. A cache button
 * that says nothing is worse than none: the whole point is to know whether
 * the thing was emptied, and the first press on a new host is also how we
 * find out which of the three this host speaks.
 *
 * The request goes to the site's own address, so it arrives at Varnish from
 * the server behind it — which is the side such requests are usually
 * allowed from, and never from a stranger's browser.
 *
 * @package WOW\Tweaks
 */

namespace WOW\Tweaks\Varnish;

defined( 'ABSPATH' ) || exit;

/** The action the button posts to. */
const ACTION = 'wow_tweaks_purge';

/** Where the last report is kept until the next screen is drawn. */
const REPORT = 'wow_tweaks_purge_report';

/**
 * The ways of asking, in the order they are tried.
 *
 * @return array<int,array{label:string,method:string,path:string,headers:array<string,string>}>
 */
function ways(): array {
	$ways = array(
		array(
			'label'   => 'PURGE regex',
			'method'  => 'PURGE',
			'path'    => '/',
			'headers' => array(
				'X-Purge-Method' => 'regex',
				'X-Purge-Regex'  => '.*',
			),
		),
		array(
			'label'   => 'BAN',
			'method'  => 'BAN',
			'path'    => '/',
			'headers' => array( 'X-Ban-Url' => '.*' ),
		),
		array(
			'label'   => 'PURGE',
			'method'  => 'PURGE',
			'path'    => '/',
			'headers' => array(),
		),
	);

	/**
	 * Filters the ways of emptying the cache.
	 *
	 * Here so that the day the host says what it speaks, the answer is one
	 * line in a theme or a snippet rather than a change to this plugin.
	 *
	 * @param array $ways Each with a label, a method, a path and headers.
	 */
	return (array) apply_filters( 'wow_tweaks_varnish_ways', $ways );
}

/**
 * Whether an answer came from a cache rather than from the site itself.
 *
 * This matters more than the status code. Apache, asked to PURGE, answers
 * 200 and serves the page: taking that for success would mean a button
 * that always says "emptied" and never empties anything, which is the
 * worst of all the things it could be. Varnish signs what it touches —
 * X-Varnish on every answer, "varnish" in Via — and says so in the body of
 * a purge. One of those has to be there.
 *
 * @param array|\WP_Error $answer What came back.
 * @return bool True when a cache is speaking.
 */
function from_cache( $answer ): bool {
	if ( is_wp_error( $answer ) ) {
		return false;
	}

	$headers = wp_remote_retrieve_headers( $answer );
	$via     = strtolower( (string) ( $headers['via'] ?? '' ) );
	$body    = strtolower( substr( (string) wp_remote_retrieve_body( $answer ), 0, 400 ) );

	return isset( $headers['x-varnish'] )
		|| false !== strpos( $via, 'varnish' )
		|| false !== strpos( $body, 'purge' )
		|| false !== strpos( $body, 'banned' );
}

/**
 * Whether there is a cache in front of the site at all.
 *
 * Asked plainly, with an ordinary request, so that the answer to "nothing
 * happened" can say which of the two kinds of nothing it was: no cache
 * there, or a cache that would not listen.
 */
function in_front(): bool {
	return from_cache(
		wp_remote_head(
			home_url( '/' ),
			array(
				'timeout'   => 5,
				'sslverify' => false,
			)
		)
	);
}

/**
 * Asks the cache to empty itself.
 *
 * @return array<int,array{label:string,answer:string,ok:bool}> What each way answered.
 */
function purge(): array {
	$report = array();

	if ( ! in_front() ) {
		return array(
			array(
				'label'  => __( 'No cache', 'wow-tweaks' ),
				'answer' => __( 'nothing in front of this site answers like one', 'wow-tweaks' ),
				'ok'     => false,
			),
		);
	}

	foreach ( ways() as $way ) {
		$answer = wp_remote_request(
			home_url( $way['path'] ),
			array(
				'method'    => $way['method'],
				'headers'   => $way['headers'],
				'timeout'   => 5,
				'sslverify' => false,
				'blocking'  => true,
			)
		);

		if ( is_wp_error( $answer ) ) {
			$report[] = array(
				'label'  => $way['label'],
				'answer' => $answer->get_error_message(),
				'ok'     => false,
			);

			continue;
		}

		$code  = (int) wp_remote_retrieve_response_code( $answer );
		$cache = from_cache( $answer );
		$ok    = $code >= 200 && $code < 300 && $cache;

		$report[] = array(
			'label'  => $way['label'],
			'answer' => $code . ' ' . wp_remote_retrieve_response_message( $answer )
				. ( $cache ? '' : ' (' . __( 'answered by the site, not by a cache', 'wow-tweaks' ) . ')' ),
			'ok'     => $ok,
		);

		if ( $ok ) {
			break;
		}
	}

	return $report;
}

/**
 * Puts the button in the bar at the top of the screen.
 *
 * @param \WP_Admin_Bar $bar The bar.
 */
function button( $bar ): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$bar->add_node(
		array(
			'id'    => 'wow-tweaks-purge',
			'title' => __( 'Empty the cache', 'wow-tweaks' ),
			'href'  => wp_nonce_url( admin_url( 'admin-post.php?action=' . ACTION ), ACTION ),
			'meta'  => array( 'title' => __( 'Asks the cache in front of the site to let the next visitor through to a fresh page.', 'wow-tweaks' ) ),
		)
	);
}
add_action( 'admin_bar_menu', __NAMESPACE__ . '\\button', 90 );

/**
 * Empties it, then sends the browser back where it came from.
 */
function handle(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'wow-tweaks' ) );
	}

	check_admin_referer( ACTION );

	set_transient( REPORT . '_' . get_current_user_id(), purge(), MINUTE_IN_SECONDS );

	$back = wp_get_referer();

	wp_safe_redirect( $back ? $back : admin_url() );
	exit;
}
add_action( 'admin_post_' . ACTION, __NAMESPACE__ . '\\handle' );

/**
 * Says what happened, on the screen the button was pressed from.
 */
function notice(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$key    = REPORT . '_' . get_current_user_id();
	$report = get_transient( $key );

	if ( ! is_array( $report ) || empty( $report ) ) {
		return;
	}

	delete_transient( $key );

	$worked = array_filter( $report, static fn ( array $one ): bool => ! empty( $one['ok'] ) );
	$lines  = array_map(
		static fn ( array $one ): string => $one['label'] . ' — ' . $one['answer'],
		$report
	);

	printf(
		'<div class="notice notice-%1$s is-dismissible"><p><strong>%2$s</strong><br>%3$s</p></div>',
		$worked ? 'success' : 'warning',
		esc_html(
			$worked
				? __( 'The cache was emptied.', 'wow-tweaks' )
				: __( 'The cache was not emptied.', 'wow-tweaks' )
		),
		esc_html( implode( ' · ', $lines ) )
	);
}
add_action( 'admin_notices', __NAMESPACE__ . '\\notice' );
