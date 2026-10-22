<?php
/**
 * Keeping up with the releases that fix things.
 *
 * Most WordPress sites that are broken into are not broken into cleverly.
 * They are running a version with a hole that was published, fixed and
 * written about months earlier, and nobody applied the fix. Installing those
 * fixes by itself is the single most useful thing a small site can do, and it
 * is the one piece of security that works while nobody is watching.
 *
 * The distinction that matters is between a release that fixes something and
 * a release that changes something. WordPress makes it for us: minor versions
 * are maintenance and security and are safe to take unattended, while a new
 * major version can change the editor the whole of this site is built on.
 * That one is better taken deliberately, with somebody looking at the result.
 *
 * @package WOW\Guard
 */

namespace WOW\Guard\Updates;

use WOW\Guard\Options;

defined( 'ABSPATH' ) || exit;

/**
 * How far behind the scheduler may fall before it is worth saying so.
 *
 * WP-Cron has no clock of its own: it runs when somebody asks the site for a
 * page, so a quiet few hours are normal and mean nothing. Three is late
 * enough that a site with any visitors at all has had its chance, and early
 * enough to notice on the same day.
 */
const LATE = 3 * HOUR_IN_SECONDS;

/** Where the answer to "can the site reach itself" is kept for a while. */
const LOOPBACK = 'wow_guard_loopback';

/**
 * How long the oldest overdue job has been waiting, in seconds.
 *
 * Nothing on this page installs itself without the scheduler, and the
 * scheduler fails silently: the request it makes to start a run is sent
 * without waiting for an answer, so a site whose loopback is broken looks
 * exactly like a site with nothing to do. The only visible difference is
 * work piling up in the past, which is what this counts.
 *
 * @return int Zero when it is keeping up.
 */
function behind(): int {
	$ready = wp_get_ready_cron_jobs();

	if ( ! is_array( $ready ) || array() === $ready ) {
		return 0;
	}

	$oldest = (int) array_key_first( $ready );
	$late   = time() - $oldest;

	return $late > 0 ? $late : 0;
}

/**
 * Why the site cannot reach itself, when it cannot.
 *
 * This is the request that starts a cron run, made the same way and through
 * the same filter, but waited for. Asked at most once a quarter of an hour,
 * and only when something is already overdue, because it is a round trip.
 *
 * The key is deliberately not a real one: wp-cron.php compares it against
 * the lock and stops when they differ, so this knocks on the door without
 * setting anything running.
 *
 * @return string Empty when the site answers itself.
 */
function loopback(): string {
	$cached = get_transient( LOOPBACK );

	if ( is_string( $cached ) ) {
		return $cached;
	}

	$request = apply_filters(
		'cron_request',
		array(
			'url'  => site_url( 'wp-cron.php?doing_wp_cron=' . sprintf( '%.22F', microtime( true ) ) ),
			'key'  => '',
			'args' => array(
				'timeout'   => 5,
				'blocking'  => true,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooklookup -- core's own filter.
			),
		),
		''
	);

	$answer = wp_remote_post( (string) $request['url'], (array) $request['args'] );
	$code   = is_wp_error( $answer ) ? 0 : (int) wp_remote_retrieve_response_code( $answer );

	if ( is_wp_error( $answer ) ) {
		$why = $answer->get_error_message();
	} elseif ( $code >= 400 ) {
		/* translators: %d: an HTTP status code. */
		$why = sprintf( __( 'it answered %d', 'wow-guard' ), $code );
	} else {
		$why = '';
	}

	set_transient( LOOPBACK, $why, 15 * MINUTE_IN_SECONDS );

	return $why;
}

/**
 * The address the scheduler calls, after any filtering.
 */
function loopback_url(): string {
	$request = apply_filters(
		'cron_request',
		array(
			'url'  => site_url( 'wp-cron.php' ),
			'key'  => '',
			'args' => array(),
		),
		''
	);

	return (string) ( $request['url'] ?? site_url( 'wp-cron.php' ) );
}

/**
 * Whether the updater is allowed to run at all.
 *
 * Only ever says yes. Saying no is left to the constants in wp-config.php
 * that exist for it, because a host that has turned updates off has usually
 * done so for a reason this plugin cannot know.
 *
 * @param mixed $disabled Whether WordPress had already decided against it.
 * @return mixed
 */
function enabled( $disabled ) {
	return 'off' === Options\get( 'auto_core' ) ? $disabled : $disabled;
}
add_filter( 'automatic_updater_disabled', __NAMESPACE__ . '\\enabled' );

/**
 * Security and maintenance releases.
 *
 * @param mixed $update Whether WordPress was going to.
 * @return bool
 */
function minor( $update ): bool {
	return 'off' !== Options\get( 'auto_core' );
}
add_filter( 'allow_minor_auto_core_updates', __NAMESPACE__ . '\\minor', 20 );

/**
 * A whole new version of WordPress.
 *
 * @param mixed $update Whether WordPress was going to.
 * @return bool
 */
function major( $update ): bool {
	return 'all' === Options\get( 'auto_core' );
}
add_filter( 'allow_major_auto_core_updates', __NAMESPACE__ . '\\major', 20 );

/**
 * Nightly builds, which no live site should ever take.
 *
 * @param mixed $update Whether WordPress was going to.
 * @return bool
 */
function development( $update ): bool {
	return false;
}
add_filter( 'allow_dev_auto_core_updates', __NAMESPACE__ . '\\development', 20 );

/**
 * Themes.
 *
 * Worth having on even for a site with one theme of its own: that theme is a
 * child of a WordPress one, which does get releases, and a child theme is no
 * safer than the parent it is standing on.
 *
 * @param mixed $update Whether WordPress was going to.
 * @param mixed $item   What is being considered.
 * @return mixed
 */
function themes( $update, $item = null ) {
	return Options\get( 'auto_themes' ) ? true : $update;
}
add_filter( 'auto_update_theme', __NAMESPACE__ . '\\themes', 20, 2 );

/**
 * Plugins.
 *
 * Only ones with somewhere to update from are affected; the plugins written
 * for this site have no such place and are untouched by this either way.
 *
 * @param mixed $update Whether WordPress was going to.
 * @param mixed $item   What is being considered.
 * @return mixed
 */
function plugins( $update, $item = null ) {
	return Options\get( 'auto_plugins' ) ? true : $update;
}
add_filter( 'auto_update_plugin', __NAMESPACE__ . '\\plugins', 20, 2 );

/**
 * Translations, which are text and cannot break anything.
 *
 * @param mixed $update Whether WordPress was going to.
 * @return bool
 */
function translations( $update ): bool {
	return true;
}
add_filter( 'auto_update_translation', __NAMESPACE__ . '\\translations', 20 );
