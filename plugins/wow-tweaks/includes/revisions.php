<?php
/**
 * How many drafts of a page the site keeps.
 *
 * WordPress keeps every version of every post for ever. On a site where the
 * pages are galleries that is not a few paragraphs of text per revision — it
 * is the whole arrangement of every picture on the page, a hundred of them
 * with their addresses and their framing, written out again each time anybody
 * nudges anything. Four album pages on this site had accumulated 58, 54, 42
 * and 22 versions between them: five megabytes of content, in a database of
 * fifteen.
 *
 * They are not only weight. Every one of them is a copy of the page as it was
 * when it was saved, naming pictures under the names those pictures had then
 * — so a search for an old address finds dozens of hits that no visitor will
 * ever see, and anything that walks the posts walks them all.
 *
 * Three is enough to undo a mistake. Beyond that nobody has ever gone back.
 *
 * @package WOW\Tweaks
 */

namespace WOW\Tweaks\Revisions;

use WOW\Tweaks\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Tells WordPress how many to keep from now on.
 *
 * This is the whole of the tweak as far as new saves are concerned: WordPress
 * asks before writing a revision and deletes the oldest itself. It overrides
 * WP_POST_REVISIONS, which is how the same thing is usually said in
 * wp-config.php — a setting in the admin is easier to find and easier to
 * change than a line in a file nobody opens.
 *
 * @param mixed $keep What WordPress was going to keep.
 * @param mixed $post The post being saved.
 * @return mixed
 */
function how_many( $keep, $post = null ) {
	$wanted = Options\revisions();

	return Options\UNLIMITED === $wanted ? $keep : $wanted;
}
add_filter( 'wp_revisions_to_keep', __NAMESPACE__ . '\\how_many', 10, 2 );

/**
 * Posts that have more revisions than they are allowed.
 *
 * Asked of the database rather than of WordPress: wp_get_post_revisions()
 * would have to be called for every post on the site to find the few that
 * have too many.
 *
 * @param int $keep How many each post may have.
 * @return array<int,int> Post ID => how many it has.
 */
function over_the_limit( int $keep ): array {
	global $wpdb;

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT post_parent, COUNT(*) AS kept
			 FROM {$wpdb->posts}
			 WHERE post_type = 'revision'
			   AND post_name NOT LIKE %s
			 GROUP BY post_parent
			 HAVING kept > %d",
			'%' . $wpdb->esc_like( 'autosave' ) . '%',
			$keep
		),
		ARRAY_A
	);

	$found = array();

	foreach ( $rows as $row ) {
		$found[ (int) $row['post_parent'] ] = (int) $row['kept'];
	}

	return $found;
}

/**
 * Deletes the revisions a post is no longer allowed to keep.
 *
 * The newest survive, which is the only sensible reading of "keep three".
 * Autosaves are left alone entirely: there is at most one per person per
 * post, WordPress needs it to offer "restore the backup of this post", and
 * it is not a revision in the sense anybody means.
 *
 * wp_delete_post_revision() rather than a DELETE: a revision owns meta rows
 * and term relationships of its own, and the function is what knows about
 * them.
 *
 * @param bool $dry_run Count what would go, delete nothing.
 * @return array{posts:int,deleted:int,bytes:int} What happened, or would.
 */
function prune( bool $dry_run = false ): array {
	$keep = Options\revisions();

	if ( Options\UNLIMITED === $keep ) {
		return array(
			'posts'   => 0,
			'deleted' => 0,
			'bytes'   => 0,
		);
	}

	$posts   = 0;
	$deleted = 0;
	$bytes   = 0;

	foreach ( array_keys( over_the_limit( $keep ) ) as $post_id ) {
		$revisions = wp_get_post_revisions( $post_id, array( 'order' => 'DESC' ) );
		$survivors = $keep;
		$went      = 0;

		foreach ( $revisions as $revision ) {
			if ( str_contains( (string) $revision->post_name, 'autosave' ) ) {
				continue;
			}

			if ( $survivors > 0 ) {
				--$survivors;

				continue;
			}

			$bytes += strlen( (string) $revision->post_content );
			++$went;

			if ( ! $dry_run ) {
				wp_delete_post_revision( $revision->ID );
			}
		}

		if ( $went > 0 ) {
			++$posts;
			$deleted += $went;
		}
	}

	return array(
		'posts'   => $posts,
		'deleted' => $deleted,
		'bytes'   => $bytes,
	);
}

/**
 * Applies a lowered limit to what is already there.
 *
 * Changing the setting is how somebody says what the site should hold, not
 * merely what it should hold in future — so the old ones go at that moment
 * rather than lingering until each post happens to be edited again.
 *
 * @param mixed $before The settings as they were.
 * @param mixed $after  The settings as they now are.
 */
function on_settings_saved( $before, $after ): void {
	$was = is_array( $before ) ? (int) ( $before['revisions'] ?? Options\UNLIMITED ) : Options\UNLIMITED;
	$now = is_array( $after ) ? (int) ( $after['revisions'] ?? Options\UNLIMITED ) : Options\UNLIMITED;

	if ( Options\UNLIMITED === $now || ( Options\UNLIMITED !== $was && $now >= $was ) ) {
		return;
	}

	$result = prune();

	if ( $result['deleted'] > 0 ) {
		set_transient( 'wow_tweaks_pruned', $result, MINUTE_IN_SECONDS );
	}
}
add_action( 'update_option_' . Options\OPTION, __NAMESPACE__ . '\\on_settings_saved', 10, 2 );
