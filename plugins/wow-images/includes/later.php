<?php
/**
 * The copies that are made after the upload rather than during it.
 *
 * An upload is the one moment when somebody is watching. The editor has
 * nothing to save until the server answers, so every second spent making
 * copies is a second the button for saving a page stays grey — which was
 * noticed here long before anybody knew why.
 *
 * Measured on this site, on one 447 KB photograph, the whole upload:
 *
 *     with every copy made while waiting   3.5 s
 *     with the slow ones left for later    2.0 s
 *
 * The slow one is AVIF; WebP is too quick to be worth deferring, and the
 * picture is no worse off in the meantime because a browser that wants AVIF
 * is offered WebP until the AVIF appears.
 *
 * So the upload makes what is quick, and asks WordPress to come back for the
 * rest. The asking is a single event due immediately: the next request to
 * arrive — and in an open editor one arrives within seconds — spawns the
 * cron runner, which does the work in a request nobody is waiting on. The
 * attachment is named in the event, so two uploads make two pieces of work
 * rather than one that forgets the other.
 *
 * Nothing here is a queue of its own. If the event is lost — cron turned
 * off, the site left alone — the picture is simply one that still needs
 * work, which is what the bulk conversion on the settings screen is for and
 * what it already asks about.
 *
 * @package WOW\Images
 */

namespace WOW\Images\Later;

use WOW\Images\Convert;
use WOW\Images\Formats;
use WOW\Images\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The event this schedules.
 */
const HOOK = 'wow_images_later';

/**
 * Whether anything is being left until later at all.
 *
 * @return bool True when at least one switched-on format is a slow one.
 */
function wanted(): bool {
	foreach ( Options\active() as $format ) {
		if ( Formats\slow( $format ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Asks for an attachment's slow copies to be made shortly.
 *
 * @param int $id Attachment ID.
 */
function schedule( int $id ): void {
	if ( ! $id || ! wanted() ) {
		return;
	}

	$when = array( $id );

	if ( ! wp_next_scheduled( HOOK, $when ) ) {
		wp_schedule_single_event( time(), HOOK, $when );
	}
}

/**
 * Makes them, when the moment comes.
 *
 * Everything still missing is made, not only the slow formats: by now the
 * quick ones are there, so asking for all of them costs nothing and covers
 * the case where the upload failed to make one.
 *
 * @param mixed $id Attachment ID, as the event carries it.
 */
function run( $id ): void {
	$id = (int) $id;

	if ( $id ) {
		Convert\attachment( $id );
	}
}
add_action( HOOK, __NAMESPACE__ . '\\run' );
