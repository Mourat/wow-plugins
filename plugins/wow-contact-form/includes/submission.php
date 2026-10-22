<?php
/**
 * What happens when somebody presses Send.
 *
 * The form posts to the address of the page it sits on and is answered on
 * template_redirect, rather than going to admin-post.php. That file defines
 * WP_ADMIN and runs the whole admin_init bootstrap; asking every visitor's
 * submission to do that, only to come back to a page we then have to find
 * our way to again, buys nothing.
 *
 * @package WOW\ContactForm
 */

namespace WOW\ContactForm\Submission;

use WOW\ContactForm\Fields;
use WOW\ContactForm\Mail;
use WOW\ContactForm\Security;
use WOW\ContactForm\Storage;

defined( 'ABSPATH' ) || exit;

/** Hidden field marking a request as ours. */
const FIELD_ACTION = 'wow_contact_action';

/** Hidden field naming the page to come back to. */
const FIELD_POST = 'wow_contact_post';

/** Query argument added to the address once a message is through. */
const SENT_ARG = 'wow-contact';

/**
 * What the form should show on this request.
 *
 * A plain static rather than a transient or a cookie. A rejected submission
 * is answered by rendering the very page the POST arrived at, so the values
 * only have to survive from this hook to the block a moment later, inside one
 * request. Anything persistent would have to be keyed, expired and cleaned up
 * for no gain.
 *
 * @param array<string,mixed>|null $set Fields to overwrite, or null to read.
 * @return array{values:array<string,string>,errors:array<string,string>,notice:string} Current state.
 */
function state( ?array $set = null ): array {
	static $state = array(
		'values' => array(),
		'errors' => array(),
		'notice' => '',
	);

	if ( null !== $set ) {
		$state = array_merge( $state, $set );
	}

	return $state;
}

/**
 * Whether this request is a submission of our form.
 *
 * @return bool true when it is.
 */
function is_submission(): bool {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Identifying the request; everything it carries is checked below.
	return isset( $_POST[ FIELD_ACTION ] ) && 'send' === sanitize_key( wp_unslash( $_POST[ FIELD_ACTION ] ) );
}

/**
 * Answers a submission.
 *
 * The order is the whole design. Cheap checks that tell a robot from a person
 * come first, because they cost nothing and must not be reachable around.
 * Then validation, then storage, and only then the email — so that a mail
 * server having a bad day can no longer cost anybody their message.
 */
function handle(): void {
	if ( ! is_submission() ) {
		return;
	}

	/*
	 * Left slashed on purpose. WordPress adds those slashes to every request
	 * and each reader below takes them off for itself — Fields\clean() field
	 * by field, verify_nonce() for the nonce. Stripping them here as well
	 * would take them off twice, and a message containing a backslash would
	 * quietly lose it.
	 */
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The checks below read this same array.
	$raw  = $_POST;
	$post = get_post( isset( $raw[ FIELD_POST ] ) ? (int) $raw[ FIELD_POST ] : 0 );

	/*
	 * Where to go afterwards is taken from a post ID and turned into a URL by
	 * WordPress, not read out of the referer. wp_get_referer() consults
	 * $_REQUEST['_wp_http_referer'] first — a value the sender chose — and
	 * comes back empty whenever a browser has been told not to send one. An
	 * ID either names a post on this site or it does not.
	 */
	if ( ! $post instanceof \WP_Post ) {
		return;
	}

	$page = (string) get_permalink( $post );

	/*
	 * A robot that filled the invisible field, or a message the site's own
	 * disallowed list objects to, is answered exactly as a good one would be.
	 * Telling either apart from success is how a sender learns which rule to
	 * work around next time.
	 */
	if ( Security\honeypot_filled( $raw ) ) {
		finish( $page );
	}

	if ( Security\throttled() ) {
		state(
			array(
				'values' => Fields\clean( $raw ),
				'notice' => __( 'You have just sent a message. Please give it a moment before sending another.', 'wow-contact-form' ),
			)
		);

		return;
	}

	/*
	 * A stale form is not a mistake the visitor made, so it is reported as
	 * what it is and everything they typed is handed back. The nonce is read
	 * the same way: for a logged-out visitor it proves only that the page came
	 * from this site within the last day, which makes it a freshness check and
	 * not a defence.
	 */
	$stamp = isset( $raw[ Security\STAMP ] ) ? sanitize_text_field( wp_unslash( $raw[ Security\STAMP ] ) ) : '';

	if ( ! Security\check_stamp( $stamp ) || ! Security\verify_nonce( $raw ) ) {
		state(
			array(
				'values' => Fields\clean( $raw ),
				'notice' => __( 'This page had been open for a while and the form expired. Nothing was lost — please send it again.', 'wow-contact-form' ),
			)
		);

		return;
	}

	$values = Fields\clean( $raw );
	$errors = Fields\validate( $values );

	if ( ! empty( $errors ) ) {
		state(
			array(
				'values' => $values,
				'errors' => $errors,
				'notice' => __( 'The message was not sent. Please look at the fields marked below.', 'wow-contact-form' ),
			)
		);

		return;
	}

	if ( Security\disallowed( $values ) ) {
		finish( $page );
	}

	$post_id = Storage\store( $values );

	/*
	 * The one failure the visitor is told about plainly. Everything else can
	 * be recovered from later; a message that never reached the database
	 * cannot, so it must not be dressed up as success.
	 */
	if ( is_wp_error( $post_id ) ) {
		state(
			array(
				'values' => $values,
				'notice' => __( 'Something went wrong on our side and the message was not saved. Please try again in a moment.', 'wow-contact-form' ),
			)
		);

		return;
	}

	Mail\send( (int) $post_id, $values );
	Security\mark_sent();

	finish( $page );
}
add_action( 'template_redirect', __NAMESPACE__ . '\\handle' );

/**
 * Sends the visitor back to the page with the good news, and stops.
 *
 * 303 rather than the usual 302: it is the code that means "your POST was
 * accepted, now go and GET this", and it is what stops a browser offering to
 * send the message a second time when the page is reloaded.
 *
 * @param string $page Address of the page holding the form.
 */
function finish( string $page ): void {
	wp_safe_redirect( add_query_arg( SENT_ARG, 'sent', $page ) . '#wow-contact-form', 303 );

	exit;
}

/**
 * Whether the page is being shown just after a message went through.
 *
 * @return bool true when the form should say thank you instead of appearing.
 */
function was_sent(): bool {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a flag we put in our own redirect; it decides wording, nothing else.
	return isset( $_GET[ SENT_ARG ] ) && 'sent' === sanitize_key( wp_unslash( $_GET[ SENT_ARG ] ) );
}
