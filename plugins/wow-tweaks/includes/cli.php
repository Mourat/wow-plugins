<?php
/**
 * WP-CLI commands.
 *
 * @package WOW\Tweaks
 */

namespace WOW\Tweaks\CLI;

use WOW\Tweaks\Options;
use WOW\Tweaks\Revisions;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Deletes the revisions that are over the limit.
 *
 * The limit itself is on the settings screen; this only applies it to what is
 * already in the database, which the screen also does when the number is
 * lowered. It is here for the times the setting has not changed but the
 * revisions have — after an import, say.
 *
 * ## OPTIONS
 *
 * [--dry-run]
 * : Say what would go, delete nothing.
 *
 * ## EXAMPLES
 *
 *     wp wow-tweaks prune-revisions --dry-run
 *     wp wow-tweaks prune-revisions
 *
 * @param array<int,string>    $args       Positional arguments.
 * @param array<string,string> $assoc_args Options.
 */
function prune_revisions( array $args, array $assoc_args ): void {
	$keep = Options\revisions();

	if ( Options\UNLIMITED === $keep ) {
		\WP_CLI::error( 'No limit is set, so there is nothing over it.' );
	}

	$dry_run = isset( $assoc_args['dry-run'] );
	$result  = Revisions\prune( $dry_run );

	if ( 0 === $result['deleted'] ) {
		\WP_CLI::success( sprintf( 'No page keeps more than %d versions.', $keep ) );

		return;
	}

	\WP_CLI::success(
		sprintf(
			'%d versions across %d pages%s — %s.',
			$result['deleted'],
			$result['posts'],
			$dry_run ? ' would be deleted' : ' deleted',
			size_format( $result['bytes'], 1 )
		)
	);
}
\WP_CLI::add_command( 'wow-tweaks prune-revisions', __NAMESPACE__ . '\\prune_revisions' );
