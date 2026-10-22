<?php
/**
 * Asking from the command line what the repository has.
 *
 * @package WOW\Updates
 */

namespace WOW\Updates\Cli;

use WOW\Updates\Manifest;
use WOW\Updates\Plugins;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * What the repository has, beside what is installed.
 *
 * ## OPTIONS
 *
 * [--refresh]
 * : Ask the repository again rather than using the kept answer.
 *
 * ## EXAMPLES
 *
 *     wp wow-updates list
 *     wp wow-updates list --refresh
 *
 * @param array<int,string>    $args    Positional arguments.
 * @param array<string,string> $options Options.
 */
function listing( array $args, array $options ): void {
	$fresh = isset( $options['refresh'] );
	$known = Manifest\plugins( $fresh );

	if ( array() === $known ) {
		\WP_CLI::warning( sprintf( 'Nothing came back from %s', Manifest\URL ) );

		return;
	}

	require_once ABSPATH . 'wp-admin/includes/plugin.php';

	$rows = array();

	foreach ( get_plugins() as $file => $data ) {
		if ( ! Plugins\ours( (string) ( $data['UpdateURI'] ?? '' ) ) ) {
			continue;
		}

		$slug = dirname( $file );
		$there = $known[ $slug ]['version'] ?? '';

		$rows[] = array(
			'plugin'    => $slug,
			'installed' => $data['Version'],
			'published' => '' === $there ? '—' : $there,
			'newer'     => '' !== $there && version_compare( $there, $data['Version'], '>' ) ? 'yes' : '',
		);
	}

	if ( array() === $rows ) {
		\WP_CLI::warning( 'No installed plugin names this repository in its Update URI header.' );

		return;
	}

	\WP_CLI\Utils\format_items( 'table', $rows, array( 'plugin', 'installed', 'published', 'newer' ) );

	$state = Manifest\state();

	\WP_CLI::log(
		sprintf(
			'Answer from %s, %s.',
			Manifest\URL,
			$state['asked'] ? human_time_diff( $state['asked'] ) . ' ago' : 'just now'
		)
	);
}

/**
 * Forgets the kept answer.
 *
 * ## EXAMPLES
 *
 *     wp wow-updates forget
 */
function forget(): void {
	Manifest\forget();
	delete_site_transient( 'update_plugins' );

	\WP_CLI::success( 'Forgotten. The next check asks the repository.' );
}

\WP_CLI::add_command( 'wow-updates list', __NAMESPACE__ . '\\listing' );
\WP_CLI::add_command( 'wow-updates forget', __NAMESPACE__ . '\\forget' );
