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
	return array(
		'cb'       => $columns['cb'] ?? '',
		'title'    => __( 'From', 'wow-contact-form' ),
		'email'    => __( 'Email', 'wow-contact-form' ),
		'excerpt'  => __( 'Message', 'wow-contact-form' ),
		'delivery' => __( 'Notification', 'wow-contact-form' ),
		'date'     => __( 'Received', 'wow-contact-form' ),
	);
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
	if ( Storage\POST_TYPE === $post->post_type ) {
		unset( $actions['inline hide-if-no-js'] );
	}

	return $actions;
}
add_filter( 'post_row_actions', __NAMESPACE__ . '\\row_actions', 10, 2 );
