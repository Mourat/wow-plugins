<?php
/**
 * Who makes the thumbnails: this browser, or the server.
 *
 * WordPress 7.1 asks the browser to do it. The picture never goes up whole
 * and once: the browser cuts it into every registered size itself and sends
 * each one as its own request, then asks the server to put them together.
 * It is meant for sites whose servers are the bottleneck, where handing the
 * work to a thousand visitors' laptops is a saving.
 *
 * This site is not that. It has sixteen registered sizes — six of
 * WordPress's own and ten of the album theme's, each of those in a second
 * copy for sharp screens — so one photograph became twenty-six separate
 * uploads. Measured here on a 447 KB photograph: 26 requests, 3.6 seconds
 * between them, and 1.6 seconds more to finish; against 1.6 to 2.5 seconds
 * for the server doing the whole lot in one pass. Every one of those
 * requests loads WordPress from scratch to receive one thumbnail.
 *
 * What was felt, before any of it was measured, was that the button for
 * saving a page stayed grey for a long time after a photograph had plainly
 * already arrived: the editor has nothing to save until the upload is
 * finished, and the upload was not finished for as long as the thumbnails
 * were still going up one at a time.
 *
 * @package WOW\Tweaks
 */

namespace WOW\Tweaks\Uploads;

defined( 'ABSPATH' ) || exit;

add_filter( 'wp_client_side_media_processing_enabled', '__return_false' );
