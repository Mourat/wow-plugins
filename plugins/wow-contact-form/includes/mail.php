<?php
/**
 * Passing the message on by email.
 *
 * Deliberately the second thing that happens to a submission, never the
 * first: by the time this runs the message is already in the database, so
 * whatever the mail server does or fails to do, nothing is lost.
 *
 * @package WOW\ContactForm
 */

namespace WOW\ContactForm\Mail;

use WOW\ContactForm\Fields;
use WOW\ContactForm\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Who receives the messages.
 *
 * @return string An email address.
 */
function recipient(): string {
	/**
	 * Filters the address the contact form writes to.
	 *
	 * @param string $recipient Email address.
	 */
	$recipient = (string) apply_filters( 'wow_contact_form_recipient', (string) get_option( 'admin_email' ) );

	return is_email( $recipient ) ? $recipient : '';
}

/**
 * The subject line.
 *
 * @param array<string,string> $values Cleaned values.
 * @return string Subject.
 */
function subject( array $values ): string {
	$name = trim( ( $values['first_name'] ?? '' ) . ' ' . ( $values['last_name'] ?? '' ) );

	$subject = sprintf(
		/* translators: 1: site name, 2: sender's name. */
		__( '[%1$s] Message from %2$s', 'wow-contact-form' ),
		wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ),
		$name
	);

	/**
	 * Filters the subject line of the notification.
	 *
	 * @param string               $subject Subject line.
	 * @param array<string,string> $values  Cleaned values.
	 */
	return (string) apply_filters( 'wow_contact_form_subject', $subject, $values );
}

/**
 * The body of the notification.
 *
 * Plain text, one field per paragraph, in the order of the form. Nothing here
 * is escaped for HTML on purpose — the mail goes out as text, and escaping it
 * would only put &amp; in front of the reader.
 *
 * @param array<string,string> $values  Cleaned values.
 * @param int                  $post_id The stored message.
 * @return string Body.
 */
function body( array $values, int $post_id ): string {
	$lines = array();

	foreach ( Fields\fields() as $key => $field ) {
		$value = $values[ $key ] ?? '';

		if ( '' === $value ) {
			continue;
		}

		$lines[] = $field['label'] . ':';
		$lines[] = $value;
		$lines[] = '';
	}

	/*
	 * Built by hand rather than with get_edit_post_link(): that one asks
	 * whether the current user may edit the post, and the current user here
	 * is the anonymous visitor who just wrote in. It would return nothing.
	 */
	$lines[] = '---';
	$lines[] = __( 'Read it in the site admin:', 'wow-contact-form' );
	$lines[] = admin_url( 'post.php?post=' . $post_id . '&action=edit' );

	$body = implode( "\n", $lines );

	/**
	 * Filters the body of the notification.
	 *
	 * @param string               $body    Message body.
	 * @param array<string,string> $values  Cleaned values.
	 * @param int                  $post_id The stored message.
	 */
	return (string) apply_filters( 'wow_contact_form_body', $body, $values, $post_id );
}

/**
 * Makes a value safe to put inside an email header.
 *
 * sanitize_text_field() strips tags but leaves angle brackets that were never
 * part of one, and leaves nothing to stop a line break. Either is enough to
 * append a header of the sender's choosing — a Bcc, say — to our Reply-To.
 * This is the one place in the plugin where that matters.
 *
 * @param string $value Value to put in a header.
 * @return string The value with anything structural removed.
 */
function header_safe( string $value ): string {
	return trim( str_replace( array( "\r", "\n", "\t", '<', '>', ',', ';' ), ' ', $value ) );
}

/**
 * Sends the notification and records what came of it.
 *
 * The From address stays the site's own. Putting the visitor's address there
 * would be the obvious thing and the wrong one: the message would be sent
 * from a domain our server has no right to send for, and SPF or DMARC would
 * see to it that the owner never received a single one. The visitor's address
 * belongs in Reply-To, where hitting "reply" finds it anyway.
 *
 * @param int                  $post_id The stored message.
 * @param array<string,string> $values  Cleaned values.
 */
function send( int $post_id, array $values ): void {
	$to = recipient();

	if ( '' === $to ) {
		record( $post_id, 'skipped', __( 'No valid recipient address is configured.', 'wow-contact-form' ) );

		return;
	}

	$from    = header_safe( trim( ( $values['first_name'] ?? '' ) . ' ' . ( $values['last_name'] ?? '' ) ) );
	$address = $values['email'] ?? '';
	$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

	if ( is_email( $address ) ) {
		$headers[] = '' === $from
			? 'Reply-To: ' . $address
			: sprintf( 'Reply-To: %s <%s>', $from, $address );
	}

	/*
	 * The listener is added for the length of this one call and taken off
	 * again immediately. wp_mail_failed is a site-wide action: left in place
	 * it would also catch password resets and update notices and pin their
	 * errors onto whichever message happened to arrive last.
	 */
	$error   = null;
	$capture = static function ( $wp_error ) use ( &$error ): void {
		$error = $wp_error;
	};

	add_action( 'wp_mail_failed', $capture );
	$sent = wp_mail( $to, subject( $values ), body( $values, $post_id ), $headers );
	remove_action( 'wp_mail_failed', $capture );

	if ( $sent ) {
		record( $post_id, 'sent', '' );

		return;
	}

	/*
	 * A false return without the action having fired means something short-
	 * circuited pre_wp_mail — a mail plugin, usually. Worth saying so rather
	 * than leaving an empty reason behind.
	 */
	$reason = $error instanceof \WP_Error
		? $error->get_error_message()
		: __( 'Sending was stopped before it began.', 'wow-contact-form' );

	record( $post_id, 'failed', $reason );
}

/**
 * Notes on the message what became of its notification.
 *
 * @param int    $post_id The stored message.
 * @param string $status  One of sent, failed, skipped.
 * @param string $reason  Why, when it did not go out.
 */
function record( int $post_id, string $status, string $reason ): void {
	update_post_meta( $post_id, Storage\meta_key( 'mail_status' ), $status );

	if ( '' === $reason ) {
		delete_post_meta( $post_id, Storage\meta_key( 'mail_error' ) );

		return;
	}

	update_post_meta( $post_id, Storage\meta_key( 'mail_error' ), wp_slash( $reason ) );
}
