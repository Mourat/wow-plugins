<?php
/**
 * The list of what the repository has, and how old our copy of it is.
 *
 * One file answers for every plugin here. The alternative — asking GitHub
 * about each of eleven plugins in turn — would be eleven requests where one
 * will do, and GitHub counts requests: sixty an hour from an address, shared
 * by everything else on that server.
 *
 * It is fetched from the release itself rather than through the API, at an
 * address GitHub keeps pointing at the newest release. That costs no API
 * allowance at all and needs no token for a public repository.
 *
 * @package WOW\Updates
 */

namespace WOW\Updates\Manifest;

defined( 'ABSPATH' ) || exit;

/** The repository the plugins are kept in. */
const REPO = 'https://github.com/Mourat/wow-plugins';

/** Where the list of what it holds is published. */
const URL = REPO . '/releases/latest/download/plugins.json';

/** Where the answer is kept between asks. */
const CACHE = 'wow_updates_manifest';

/**
 * How long a good answer is kept.
 *
 * WordPress looks for plugin updates twice a day, so anything under that is
 * spent on nothing. Half a day means a release is picked up within one
 * check of being made.
 */
const GOOD_FOR = 12 * HOUR_IN_SECONDS;

/** How long a failure is kept, so a repository that is down is not hammered. */
const BAD_FOR = HOUR_IN_SECONDS;

/** Where a package may be downloaded from, and nowhere else. */
const HOSTS = array( 'github.com', 'objects.githubusercontent.com' );

/**
 * What the repository says it has.
 *
 * @param bool $fresh Ask again even if there is a recent answer.
 * @return array<string,array<string,string>> Slug => what is known. Empty
 *         when the question could not be answered.
 */
function plugins( bool $fresh = false ): array {
	if ( ! $fresh ) {
		$kept = get_transient( CACHE );

		if ( is_array( $kept ) ) {
			return isset( $kept['plugins'] ) && is_array( $kept['plugins'] ) ? $kept['plugins'] : array();
		}
	}

	$answer = wp_remote_get(
		URL,
		array(
			'timeout'    => 10,
			'user-agent' => 'wow-updates/' . WOW_UPDATES_VERSION . '; ' . home_url(),
		)
	);

	$found = read( $answer );

	set_transient(
		CACHE,
		array(
			'plugins' => $found,
			'asked'   => time(),
		),
		array() === $found ? BAD_FOR : GOOD_FOR
	);

	return $found;
}

/**
 * What the repository says it has in the way of themes.
 *
 * The same file and the same cache; only the section differs. WordPress
 * has updated themes by the `Update URI` header since 7.1, exactly as it
 * does plugins, so there is nothing to invent here.
 *
 * @param bool $fresh Ask again even if there is a recent answer.
 * @return array<string,array<string,string>> Slug => what is known.
 */
function themes( bool $fresh = false ): array {
	if ( ! $fresh ) {
		$kept = get_transient( CACHE );

		if ( is_array( $kept ) ) {
			return isset( $kept['themes'] ) && is_array( $kept['themes'] ) ? $kept['themes'] : array();
		}
	}

	plugins( true );

	$kept = get_transient( CACHE );

	return is_array( $kept ) && isset( $kept['themes'] ) && is_array( $kept['themes'] )
		? $kept['themes']
		: array();
}

/**
 * Makes sense of what came back, and refuses anything that does not.
 *
 * Every field is checked rather than trusted. The file is published by us,
 * but it arrives over the network and ends with WordPress downloading and
 * unpacking whatever address it names, which is reason enough to be strict
 * about where that address may point.
 *
 * @param mixed $answer What wp_remote_get returned.
 * @return array<string,array<string,string>>
 */
function read( $answer ): array {
	if ( is_wp_error( $answer ) || 200 !== (int) wp_remote_retrieve_response_code( $answer ) ) {
		return array();
	}

	$body = json_decode( (string) wp_remote_retrieve_body( $answer ), true );

	if ( ! is_array( $body ) || empty( $body['plugins'] ) || ! is_array( $body['plugins'] ) ) {
		return array();
	}

	$found = array();

	foreach ( $body['plugins'] as $slug => $about ) {
		$slug = sanitize_key( (string) $slug );

		if ( '' === $slug || ! is_array( $about ) || empty( $about['version'] ) || empty( $about['package'] ) ) {
			continue;
		}

		$host = wp_parse_url( (string) $about['package'], PHP_URL_HOST );

		if ( ! in_array( (string) $host, HOSTS, true ) ) {
			continue;
		}

		$found[ $slug ] = array(
			'version'      => (string) $about['version'],
			'package'      => esc_url_raw( (string) $about['package'] ),
			'requires'     => (string) ( $about['requires'] ?? '' ),
			'requires_php' => (string) ( $about['requires_php'] ?? '' ),
			'tested'       => (string) ( $about['tested'] ?? '' ),
		);
	}

	return $found;
}

/**
 * Forgets the answer, so the next question goes to the repository.
 */
/**
 * Asked again whenever WordPress is asked again.
 *
 * The answer here is kept for twelve hours, which is right for the
 * background check that runs on its own and wrong for a person pressing
 * "Check again" after a release. Pressed, WordPress throws away its own
 * note and asks every plugin afresh — and every plugin but this one then
 * says something new, because this one answered out of a note of its own
 * that nobody had thrown away. An hour of that is confusing; twelve is a
 * bug report.
 *
 * So the note goes when WordPress's does.
 */
function forget(): void {
	delete_transient( CACHE );
}
add_action( 'delete_site_transient_update_plugins', __NAMESPACE__ . '\\forget' );
add_action( 'delete_site_transient_update_themes', __NAMESPACE__ . '\\forget' );

/**
 * When the repository was last asked, and what came of it.
 *
 * @return array{asked:int,count:int}
 */
function state(): array {
	$kept = get_transient( CACHE );

	return array(
		'asked' => is_array( $kept ) ? (int) ( $kept['asked'] ?? 0 ) : 0,
		'count' => is_array( $kept ) && isset( $kept['plugins'] ) ? count( (array) $kept['plugins'] ) : 0,
	);
}
