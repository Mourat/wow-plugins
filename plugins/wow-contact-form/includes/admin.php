<?php
/**
 * Reading the messages in the admin.
 *
 * Everything here is read-only on purpose. A message is a record of something
 * somebody said; editing it would only ever make it less true.
 *
 * @package WOW\ContactForm
 */

namespace WOW\ContactForm\Admin;

use WOW\ContactForm\Fields;
use WOW\ContactForm\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * The columns of the message list.
 *
 * @param array<string,string> $columns Existing columns.
 * @return array<string,string> Columns to show.
 */
function columns( array $columns ): array {
	/*
	 * The spam tab answers a different question. Nothing there was emailed,
	 * so a Notification column would read "—" down the whole page; what is
	 * worth knowing is which rule caught each one, since that is what tells
	 * a wrongly caught message from the rest at a glance.
	 */
	$last = showing_spam()
		? array( 'reason' => __( 'Caught by', 'wow-contact-form' ) )
		: array( 'delivery' => __( 'Notification', 'wow-contact-form' ) );

	return array_merge(
		array(
			'cb'      => $columns['cb'] ?? '',
			'title'   => __( 'From', 'wow-contact-form' ),
			'email'   => __( 'Email', 'wow-contact-form' ),
			'excerpt' => __( 'Message', 'wow-contact-form' ),
		),
		$last,
		array( 'date' => __( 'Received', 'wow-contact-form' ) )
	);
}

/**
 * Whether the message list is showing the spam tab.
 *
 * @return bool true when it is.
 */
function showing_spam(): bool {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading which tab of a list screen is open; it decides a column heading.
	return isset( $_GET['post_status'] ) && Storage\SPAM === sanitize_key( wp_unslash( $_GET['post_status'] ) );
}
add_filter( 'manage_' . Storage\POST_TYPE . '_posts_columns', __NAMESPACE__ . '\\columns' );

/**
 * Fills one cell of the message list.
 *
 * @param string $column  Column key.
 * @param int    $post_id The message.
 */
function column( string $column, int $post_id ): void {
	if ( 'email' === $column ) {
		$email = (string) get_post_meta( $post_id, Storage\meta_key( 'email' ), true );

		if ( is_email( $email ) ) {
			printf( '<a href="%1$s">%2$s</a>', esc_url( 'mailto:' . $email ), esc_html( $email ) );
		}

		return;
	}

	if ( 'excerpt' === $column ) {
		echo esc_html( wp_trim_words( (string) get_post_field( 'post_content', $post_id ), 18 ) );

		return;
	}

	if ( 'reason' === $column ) {
		echo esc_html( (string) get_post_meta( $post_id, Storage\WHY, true ) );

		return;
	}

	if ( 'delivery' === $column ) {
		delivery( $post_id );
	}
}
add_action( 'manage_' . Storage\POST_TYPE . '_posts_custom_column', __NAMESPACE__ . '\\column', 10, 2 );

/**
 * Says what became of the email for one message.
 *
 * Worth a column of its own. On a site whose server cannot send mail this is
 * the only place the failure is visible at all, and the difference between
 * "nobody wrote to us" and "nobody told us they had" is the whole point of
 * keeping the messages here.
 *
 * @param int $post_id The message.
 */
function delivery( int $post_id ): void {
	$status = (string) get_post_meta( $post_id, Storage\meta_key( 'mail_status' ), true );
	$error  = (string) get_post_meta( $post_id, Storage\meta_key( 'mail_error' ), true );

	if ( 'sent' === $status ) {
		esc_html_e( 'Sent', 'wow-contact-form' );

		return;
	}

	if ( '' === $status ) {
		echo '&mdash;';

		return;
	}

	printf(
		'<strong>%1$s</strong>%2$s',
		esc_html__( 'Not sent', 'wow-contact-form' ),
		'' === $error ? '' : '<br><span class="description">' . esc_html( $error ) . '</span>'
	);
}

/**
 * Adds the box holding the message itself.
 */
function meta_box(): void {
	add_meta_box(
		'wow-contact-message',
		__( 'Message', 'wow-contact-form' ),
		__NAMESPACE__ . '\\render_meta_box',
		Storage\POST_TYPE,
		'normal',
		'high'
	);
}
add_action( 'add_meta_boxes', __NAMESPACE__ . '\\meta_box' );

/**
 * Prints the message and the details that came with it.
 *
 * esc_html() and wpautop(), and nothing else. Passing the text through
 * the_content() would be the usual reflex and a serious mistake: that applies
 * shortcodes, and a visitor who writes [gallery] in their message would have
 * it run in the site owner's admin.
 *
 * @param \WP_Post $post The message.
 */
function render_meta_box( \WP_Post $post ): void {
	echo '<table class="widefat striped"><tbody>';

	foreach ( Fields\fields() as $key => $field ) {
		if ( 'message' === $key ) {
			continue;
		}

		$value = (string) get_post_meta( $post->ID, Storage\meta_key( $key ), true );

		if ( '' === $value ) {
			continue;
		}

		printf(
			'<tr><th scope="row" style="width:12em">%1$s</th><td>%2$s</td></tr>',
			esc_html( $field['label'] ),
			'email' === $key && is_email( $value )
				? '<a href="' . esc_url( 'mailto:' . $value ) . '">' . esc_html( $value ) . '</a>'
				: esc_html( $value )
		);
	}

	echo '</tbody></table>';

	echo '<div class="wow-contact-message">' . wp_kses_post( wpautop( esc_html( $post->post_content ) ) ) . '</div>';
}

/**
 * Takes Quick Edit off the message list.
 *
 * It offers to change the title, the slug and the date of something that was
 * written by somebody else. There is nothing there worth editing.
 *
 * @param array<string,string> $actions Row actions.
 * @param \WP_Post             $post    The row's post.
 * @return array<string,string> Row actions without the inline editor.
 */
function row_actions( array $actions, \WP_Post $post ): array {
	if ( Storage\POST_TYPE !== $post->post_type ) {
		return $actions;
	}

	unset( $actions['inline hide-if-no-js'] );

	$spam = Storage\SPAM === $post->post_status;

	/*
	 * Put in front of the rest. On the spam tab this is the one thing worth
	 * doing to a row, and a missed real enquiry should take one click to
	 * rescue, not a hunt through a menu.
	 */
	return array_merge(
		array(
			'wow-mark' => sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( mark_link( $post->ID, $spam ? 'publish' : Storage\SPAM ) ),
				$spam
					? esc_html__( 'Not spam', 'wow-contact-form' )
					: esc_html__( 'Spam', 'wow-contact-form' )
			),
		),
		$actions
	);
}
add_filter( 'post_row_actions', __NAMESPACE__ . '\\row_actions', 10, 2 );

/** The request that moves a message between the two statuses. */
const MARK = 'wow_contact_mark';

/**
 * The address that marks one message, or unmarks it.
 *
 * @param int    $post_id The message.
 * @param string $status  Status to move it to.
 * @return string A nonced admin URL.
 */
function mark_link( int $post_id, string $status ): string {
	return wp_nonce_url(
		add_query_arg(
			array(
				'action' => MARK,
				'post'   => $post_id,
				'status' => $status,
			),
			admin_url( 'admin.php' )
		),
		MARK . '-' . $post_id
	);
}

/**
 * Moves one message to or from the spam tab.
 *
 * Hung on admin_action_ rather than admin_post_: the link is pressed from a
 * list screen inside the admin, and this way the redirect can hand the
 * visitor back to the very page and tab they were looking at.
 */
function mark(): void {
	$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;

	check_admin_referer( MARK . '-' . $post_id );

	$post   = get_post( $post_id );
	$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

	if ( ! $post instanceof \WP_Post || Storage\POST_TYPE !== $post->post_type ) {
		wp_die( esc_html__( 'There is no such message.', 'wow-contact-form' ) );
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'wow-contact-form' ) );
	}

	if ( ! in_array( $status, array( 'publish', Storage\SPAM ), true ) ) {
		wp_die( esc_html__( 'There is no such status.', 'wow-contact-form' ) );
	}

	wp_update_post(
		array(
			'ID'          => $post_id,
			'post_status' => $status,
		)
	);

	/*
	 * The reason is left on the record even once it is back in the inbox. It
	 * is the only trace of which rule was wrong, and that is worth more than
	 * a tidy row of meta.
	 */
	wp_safe_redirect(
		add_query_arg(
			array(
				'post_type'   => Storage\POST_TYPE,
				'post_status' => Storage\SPAM === $status ? 'all' : Storage\SPAM,
			),
			admin_url( 'edit.php' )
		)
	);

	exit;
}
add_action( 'admin_action_' . MARK, __NAMESPACE__ . '\\mark' );
