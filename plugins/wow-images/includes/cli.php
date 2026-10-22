<?php
/**
 * WP-CLI commands.
 *
 * The screen in Tools does the same work through a browser, which is the
 * right place for a handful of pictures. A whole library is better done here:
 * nothing times out, nothing depends on a tab staying open, and the output
 * says what happened to every file rather than how far along it is.
 *
 * @package WOW\Images
 */

namespace WOW\Images\CLI;

use WOW\Images\Cleanup;
use WOW\Images\Convert;
use WOW\Images\Formats;
use WOW\Images\Options;
use WOW\Images\Queue;
use WOW\Images\References;
use WOW\Images\Store;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * The attachments a command should work on.
 *
 * @param array<int,string>    $args       Positional arguments: attachment IDs.
 * @param array<string,string> $assoc_args Options.
 * @param bool                 $everything Ignore what is already done.
 * @return int[]
 */
function chosen( array $args, array $assoc_args, bool $everything = false ): array {
	$ids = array_filter( array_map( 'intval', $args ) );

	if ( empty( $ids ) ) {
		$ids = Queue\candidates();

		if ( ! $everything ) {
			_prime_post_caches( $ids, false, true );
			$ids = array_values( array_filter( $ids, __NAMESPACE__ . '\\has_work' ) );
		}
	}

	$limit = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0;

	return $limit > 0 ? array_slice( $ids, 0, $limit ) : $ids;
}

/**
 * Whether there is anything to do for an attachment.
 *
 * @param int $id Attachment ID.
 */
function has_work( int $id ): bool {
	return Queue\needs_work( $id );
}

/**
 * Says where the site stands.
 *
 * ## EXAMPLES
 *
 *     wp wow-images status
 */
function status(): void {
	$summary = Queue\summary();
	$store   = Options\store();

	\WP_CLI::log( 'Image library:  ' . ( Formats\engine() ?: 'none — nothing can be converted' ) );
	\WP_CLI::log( 'Stored as:      ' . ( '' === $store ? 'whatever was uploaded' : Formats\label( $store ) ) );

	foreach ( Formats\all() as $format ) {
		\WP_CLI::log(
			sprintf(
				'%-15s %s, %s here',
				Formats\label( $format ) . ':',
				Options\wanted( $format ) ? 'on' : 'off',
				Formats\supported( $format ) ? 'can be written' : 'cannot be written'
			)
		);
	}

	\WP_CLI::log( '' );
	\WP_CLI::log( sprintf( 'Pictures:       %d', $summary['total'] ) );
	\WP_CLI::log( sprintf( 'Still to do:    %d', $summary['waiting'] ) );

	if ( $summary['source'] > 0 ) {
		\WP_CLI::log(
			sprintf(
				'Best format:    %s instead of %s — %d%% lighter',
				size_format( $summary['source'] - $summary['saved'], 1 ),
				size_format( $summary['source'], 1 ),
				round( $summary['saved'] / $summary['source'] * 100 )
			)
		);
	}
}
\WP_CLI::add_command( 'wow-images status', __NAMESPACE__ . '\\status' );

/**
 * Converts pictures: stores them in the chosen format, and makes the copies.
 *
 * With no IDs it works through everything that has anything left to do, which
 * is what a first run on an existing site wants. Naming IDs does those and
 * nothing else.
 *
 * ## OPTIONS
 *
 * [<id>...]
 * : Attachment IDs. All of them when none are given.
 *
 * [--limit=<number>]
 * : Stop after this many pictures.
 *
 * [--force]
 * : Make every copy again, including the ones already there.
 *
 * [--skip-store]
 * : Only make the copies; leave the pictures in the format they are in.
 *
 * ## EXAMPLES
 *
 *     wp wow-images convert
 *     wp wow-images convert --limit=20
 *     wp wow-images convert 1432 1433
 *     wp wow-images convert --force --skip-store
 *
 * @param array<int,string>    $args       Positional arguments.
 * @param array<string,string> $assoc_args Options.
 */
function convert( array $args, array $assoc_args ): void {
	$force = isset( $assoc_args['force'] );
	$ids   = chosen( $args, $assoc_args, $force );

	if ( empty( $ids ) ) {
		\WP_CLI::success( 'Nothing to do.' );

		return;
	}

	$store  = isset( $assoc_args['skip-store'] ) ? '' : Options\store();
	$bar    = \WP_CLI\Utils\make_progress_bar( sprintf( 'Converting %d', count( $ids ) ), count( $ids ) );
	$moved  = 0;
	$made   = 0;
	$failed = 0;
	$before = 0;
	$after  = 0;

	foreach ( $ids as $id ) {
		if ( '' !== $store && Queue\needs_store( (int) $id ) ) {
			$result = Store\migrate( (int) $id );

			if ( is_wp_error( $result ) ) {
				\WP_CLI::warning( sprintf( '#%d: %s', $id, $result->get_error_message() ) );
				++$failed;
				$bar->tick();

				continue;
			}

			$before += $result['before'];
			$after   += $result['after'];
			++$moved;
		}

		$copies = Convert\attachment( (int) $id, $force );

		if ( is_wp_error( $copies ) ) {
			if ( 'wow_images_no_formats' !== $copies->get_error_code() ) {
				\WP_CLI::warning( sprintf( '#%d: %s', $id, $copies->get_error_message() ) );
				++$failed;
			}
		} else {
			$made += $copies['made'];

			foreach ( $copies['errors'] as $message ) {
				\WP_CLI::warning( sprintf( '#%d: %s', $id, $message ) );
			}
		}

		$bar->tick();
	}

	$bar->finish();

	if ( $moved > 0 ) {
		\WP_CLI::log(
			sprintf(
				'Stored anew: %d pictures, %s instead of %s.',
				$moved,
				size_format( $after, 1 ),
				size_format( $before, 1 )
			)
		);

		$pointed = References\repoint_all();

		\WP_CLI::log( sprintf( 'Corrected %d names across %d posts.', $pointed['names'], $pointed['posts'] ) );
	}

	\WP_CLI::success( sprintf( 'Copies made: %d. Failed: %d.', $made, $failed ) );
}
\WP_CLI::add_command( 'wow-images convert', __NAMESPACE__ . '\\convert' );

/**
 * Moves pictures into the stored format, without making any copies.
 *
 * ## OPTIONS
 *
 * [<id>...]
 * : Attachment IDs. All of them when none are given.
 *
 * [--limit=<number>]
 * : Stop after this many pictures.
 *
 * [--dry-run]
 * : Say what would happen and change nothing.
 *
 * [--force]
 * : Try again even the pictures already weighed and found not worth converting.
 *
 * ## EXAMPLES
 *
 *     wp wow-images store --dry-run --limit=10
 *     wp wow-images store
 *     wp wow-images store --force
 *
 * @param array<int,string>    $args       Positional arguments.
 * @param array<string,string> $assoc_args Options.
 */
function store( array $args, array $assoc_args ): void {
	$format = Options\store();

	if ( '' === $format ) {
		\WP_CLI::error( 'No stored format is chosen on the settings screen.' );
	}

	$dry_run = isset( $assoc_args['dry-run'] );
	$ids     = chosen( $args, $assoc_args, true );

	// A picture weighed once and found not worth converting is skipped from
	// then on, unless somebody says otherwise — which they would after
	// lowering the quality it was weighed at.
	if ( ! isset( $assoc_args['force'] ) ) {
		$ids = array_values( array_filter( $ids, '\\WOW\\Images\\Queue\\needs_store' ) );
	}

	if ( empty( $ids ) ) {
		\WP_CLI::success( 'Every picture is already stored as ' . Formats\label( $format ) . '.' );

		return;
	}

	$before = 0;
	$after  = 0;
	$done   = 0;

	foreach ( $ids as $id ) {
		$result = Store\migrate( (int) $id, $dry_run );

		if ( is_wp_error( $result ) ) {
			\WP_CLI::warning( sprintf( '#%d: %s', $id, $result->get_error_message() ) );

			continue;
		}

		$before += $result['before'];
		$after   += $result['after'];
		++$done;

		\WP_CLI::log( sprintf( '#%d  %s → %s', $id, $result['from'], $result['to'] ) );
	}

	if ( $dry_run ) {
		\WP_CLI::success( sprintf( '%d pictures would be converted, %s of them as they stand.', $done, size_format( $before, 1 ) ) );

		return;
	}

	$pointed = References\repoint_all();

	\WP_CLI::success(
		sprintf(
			'%d pictures converted: %s instead of %s. %d names corrected across %d posts.',
			$done,
			size_format( $after, 1 ),
			size_format( $before, 1 ),
			$pointed['names'],
			$pointed['posts']
		)
	);
}
\WP_CLI::add_command( 'wow-images store', __NAMESPACE__ . '\\store' );

/**
 * Deletes copies nothing asks for any more.
 *
 * ## OPTIONS
 *
 * [--dry-run]
 * : List what would go, without deleting anything.
 *
 * ## EXAMPLES
 *
 *     wp wow-images clean --dry-run
 *     wp wow-images clean
 *
 * @param array<int,string>    $args       Positional arguments.
 * @param array<string,string> $assoc_args Options.
 */
function clean( array $args, array $assoc_args ): void {
	$dry_run = isset( $assoc_args['dry-run'] );
	$orphans = Cleanup\orphans();

	if ( empty( $orphans ) ) {
		\WP_CLI::success( 'Every copy on disk belongs to something.' );

		return;
	}

	$bytes = 0;

	foreach ( $orphans as $path ) {
		$bytes += (int) wp_filesize( $path );

		if ( $dry_run ) {
			\WP_CLI::log( 'Would delete: ' . $path );

			continue;
		}

		wp_delete_file( $path );
	}

	\WP_CLI::success(
		sprintf(
			'%d files, %s%s.',
			count( $orphans ),
			size_format( $bytes, 1 ),
			$dry_run ? ' would be freed' : ' freed'
		)
	);
}
\WP_CLI::add_command( 'wow-images clean', __NAMESPACE__ . '\\clean' );

/**
 * Removes every copy of some pictures and forgets they were made.
 *
 * Does not put a converted picture back: once the file it came from is gone,
 * it is gone. This only takes away the extra formats.
 *
 * ## OPTIONS
 *
 * [<id>...]
 * : Attachment IDs. All of them when none are given.
 *
 * ## EXAMPLES
 *
 *     wp wow-images forget 1432
 *
 * @param array<int,string>    $args       Positional arguments.
 * @param array<string,string> $assoc_args Options.
 */
function forget( array $args, array $assoc_args ): void {
	$ids     = chosen( $args, $assoc_args, true );
	$deleted = 0;

	foreach ( $ids as $id ) {
		$deleted += Cleanup\forget_attachment( (int) $id );
	}

	\WP_CLI::success( sprintf( '%d files deleted.', $deleted ) );
}
\WP_CLI::add_command( 'wow-images forget', __NAMESPACE__ . '\\forget' );

/**
 * Points every page at the pictures as they are now.
 *
 * Run by `convert` and `store` when they finish, so it is rarely needed by
 * hand — but it is the thing to reach for after a run that was interrupted,
 * after an import, or any time a page seems to be asking for a file that is
 * not there. It remembers nothing and can be run as often as you like.
 *
 * ## OPTIONS
 *
 * [--dry-run]
 * : Count what would change, change nothing.
 *
 * ## EXAMPLES
 *
 *     wp wow-images repoint --dry-run
 *     wp wow-images repoint
 *
 * @param array<int,string>    $args       Positional arguments.
 * @param array<string,string> $assoc_args Options.
 */
function repoint( array $args, array $assoc_args ): void {
	$dry_run = isset( $assoc_args['dry-run'] );
	$result  = References\repoint_all( $dry_run );

	foreach ( $result['examples'] as $from => $to ) {
		\WP_CLI::log( sprintf( '  %s → %s', $from, $to ) );
	}

	if ( 0 === $result['names'] ) {
		\WP_CLI::success( 'Every page already names the pictures as they are.' );

		return;
	}

	\WP_CLI::success(
		sprintf(
			'%d names across %d posts%s.',
			$result['names'],
			$result['posts'],
			$dry_run ? ' would be corrected' : ' corrected'
		)
	);
}
\WP_CLI::add_command( 'wow-images repoint', __NAMESPACE__ . '\\repoint' );
