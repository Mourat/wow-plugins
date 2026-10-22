<?php
/**
 * Who the site's mail says it is from.
 *
 * WordPress sends as wordpress@ the site's domain, which is almost never a
 * mailbox that exists. A receiving server that bounces a reply and finds
 * nothing there has learnt something about the sender, and a spam filter
 * reads an unroutable From address as exactly the sort of thing it is.
 *
 * So the From address becomes one that exists, and — this is the part that
 * matters for the inbox — one at the same domain the mail server is
 * authenticated for. A message sent through amen.fr's server claiming to come
 * from gmail.com is the shape of a forgery, and is treated as one.
 *
 * @package WOW\Mail
 */

namespace WOW\Mail\Sender;

use WOW\Mail\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The address mail goes out as.
 *
 * @param mixed $from What WordPress was going to use.
 * @return mixed
 */
function address( $from ) {
	if ( ! Options\get( 'force_from' ) ) {
		return $from;
	}

	$ours = Options\from();

	return is_email( $ours ) ? $ours : $from;
}
add_filter( 'wp_mail_from', __NAMESPACE__ . '\\address', 20 );

/**
 * The name beside it.
 *
 * @param mixed $name What WordPress was going to use.
 * @return mixed
 */
function name( $name ) {
	if ( ! Options\get( 'force_from' ) ) {
		return $name;
	}

	$ours = Options\from_name();

	return '' === $ours ? $name : $ours;
}
add_filter( 'wp_mail_from_name', __NAMESPACE__ . '\\name', 20 );
