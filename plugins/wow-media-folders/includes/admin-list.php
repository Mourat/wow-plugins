<?php
/**
 * Folders in the list view and in the attachment details.
 *
 * The panel already filters both views, but it does so with plain links that
 * carry nothing else. The dropdown here sits in the native filter bar, so a
 * folder can be combined with the type and date filters and applied in one
 * go. That is the only reason it exists next to the panel.
 *
 * @package WOW\MediaFolders
 */

namespace WOW\MediaFolders\AdminList;

use function WOW\MediaFolders\AdminSidebar\current_slug;
use function WOW\MediaFolders\AdminSidebar\folders;

use const WOW\MediaFolders\Taxonomy\TAXONOMY;

defined( 'ABSPATH' ) || exit;

/** Prefix of the bulk action values. */
const BULK_PREFIX = 'wow_folder_';

/**
 * The folder dropdown in the filter bar.
 *
 * WP_Media_List_Table::extra_tablenav() fires this once with 'bar', never
 * with 'top' or 'bottom' as the posts table does, so the post type is what
 * has to be checked here.
 *
 * @param string $post_type Post type of the screen.
 */
function dropdown( string $post_type ): void {
	if ( 'attachment' !== $post_type || ! current_user_can( 'upload_files' ) ) {
		return;
	}

	$terms = folders();

	if ( array() === $terms ) {
		return;
	}

	$current = current_slug();
	?>
	<label class="screen-reader-text" for="<?php echo esc_attr( TAXONOMY ); ?>">
		<?php esc_html_e( 'Filter by folder', 'wow-media-folders' ); ?>
	</label>
	<select name="<?php echo esc_attr( TAXONOMY ); ?>" id="<?php echo esc_attr( TAXONOMY ); ?>">
		<option value=""><?php esc_html_e( 'All folders', 'wow-media-folders' ); ?></option>
		<?php foreach ( $terms as $term ) : ?>
			<option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( $current, $term->slug ); ?>>
				<?php echo esc_html( sprintf( '%s (%d)', $term->name, $term->count ) ); ?>
			</option>
		<?php endforeach; ?>
	</select>
	<?php
}
add_action( 'restrict_manage_posts', __NAMESPACE__ . '\\dropdown' );

/**
 * Adds a "move to folder" entry per folder to the bulk actions.
 *
 * @param array<string,string> $actions Bulk actions.
 * @return array<string,string>
 */
function bulk_actions( array $actions ): array {
	if ( ! current_user_can( 'upload_files' ) ) {
		return $actions;
	}

	foreach ( folders() as $term ) {
		/* translators: %s: folder name. */
		$actions[ BULK_PREFIX . $term->term_id ] = sprintf( __( 'Move to “%s”', 'wow-media-folders' ), $term->name );
	}

	$actions[ BULK_PREFIX . 'none' ] = __( 'Take out of every folder', 'wow-media-folders' );

	return $actions;
}
add_filter( 'bulk_actions-upload', __NAMESPACE__ . '\\bulk_actions' ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores

/**
 * Carries out the bulk move.
 *
 * @param string $location Redirect target.
 * @param string $action   Chosen bulk action.
 * @param int[]  $post_ids Selected attachments.
 * @return string
 */
function handle_bulk( string $location, string $action, array $post_ids ): string {
	if ( ! str_starts_with( $action, BULK_PREFIX ) || ! current_user_can( 'upload_files' ) ) {
		return $location;
	}

	$target  = substr( $action, strlen( BULK_PREFIX ) );
	$term_id = 'none' === $target ? 0 : absint( $target );

	if ( 0 !== $term_id && ! get_term( $term_id, TAXONOMY ) instanceof \WP_Term ) {
		return $location;
	}

	$moved = 0;

	foreach ( $post_ids as $id ) {
		$id = (int) $id;

		if ( 'attachment' !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			continue;
		}

		wp_set_object_terms( $id, 0 === $term_id ? array() : array( $term_id ), TAXONOMY );
		delete_post_meta( $id, \WOW\MediaFolders\Sync\AUTO_META );
		++$moved;
	}

	return add_query_arg( 'wow_folders_moved', $moved, $location );
}
add_filter( 'handle_bulk_actions-upload', __NAMESPACE__ . '\\handle_bulk', 10, 3 ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores

/**
 * Says how many files the bulk action moved.
 */
function bulk_notice(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading a redirect flag.
	if ( ! isset( $_GET['wow_folders_moved'] ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading a redirect flag.
	$moved = absint( wp_unslash( $_GET['wow_folders_moved'] ) );

	wp_admin_notice(
		sprintf(
			/* translators: %s: number of files. */
			_n( '%s file moved.', '%s files moved.', $moved, 'wow-media-folders' ),
			number_format_i18n( $moved )
		),
		array(
			'type'        => 'success',
			'dismissible' => true,
		)
	);
}
add_action( 'admin_notices', __NAMESPACE__ . '\\bulk_notice' );

/** Field key of the folder picker. */
const FIELD = 'wow_folders';

/**
 * The folder picker in the attachment details.
 *
 * Core only builds a taxonomy field for public taxonomies, and this one is
 * deliberately not public. Writing it here is no loss: the field core would
 * have produced is a text input holding comma-separated slugs, which is no
 * way to pick from a list.
 *
 * A file lives in one folder, the way it would in a file manager, so this is
 * a dropdown rather than a list of ticks. Everywhere else in the plugin the
 * folder is already single-valued — the bulk action and the panel both
 * replace it rather than add to it — and a control offering more than the
 * rest of the plugin honours only invites a state nothing else expects.
 *
 * The input is deliberately not named after the taxonomy. After running this
 * filter, wp_ajax_save_attachment_compat() walks every taxonomy on the
 * attachment and feeds whatever was submitted under that name straight into
 * preg_split() (ajax-actions.php:3286), which would break the moment a
 * folder name contains a comma — which album titles do. Under another name
 * core leaves the field alone and the saving below is the only thing that
 * touches it.
 *
 * @param array<string,mixed> $fields Attachment fields.
 * @param \WP_Post            $post   The attachment.
 * @return array<string,mixed>
 */
function attachment_field( array $fields, \WP_Post $post ): array {
	if ( ! current_user_can( 'upload_files' ) ) {
		return $fields;
	}

	$terms = folders();

	if ( array() === $terms ) {
		return $fields;
	}

	$current = wp_get_object_terms( $post->ID, TAXONOMY, array( 'fields' => 'ids' ) );
	$current = is_wp_error( $current ) ? array() : array_map( 'intval', $current );

	// Album sync appends, so a file used by two albums can hold two folders.
	// The dropdown shows the first of them and saving settles on it.
	$selected = array() === $current ? 0 : $current[0];

	/*
	 * The width is set here rather than in a stylesheet because this field
	 * turns up on screens with different assets behind them: the grid, the
	 * list and every media modal in the editor. Core sizes the text inputs
	 * inside .compat-item .field to the full column and leaves selects out
	 * (media-views.css:641), so an unconstrained one grows to its longest
	 * option — and album titles are long enough to push it out of the panel.
	 * Cutting the name off in the closed box costs nothing: the open list is
	 * drawn by the browser and shows every name in full.
	 */
	$html = sprintf(
		'<select name="attachments[%1$d][%2$s]" id="attachments-%1$d-%2$s" style="width:100%%;box-sizing:border-box"><option value="0">%3$s</option>',
		$post->ID,
		esc_attr( FIELD ),
		esc_html__( 'No folder', 'wow-media-folders' )
	);

	foreach ( $terms as $term ) {
		$html .= sprintf(
			'<option value="%1$d"%2$s>%3$s</option>',
			$term->term_id,
			selected( $selected, $term->term_id, false ),
			esc_html( $term->name )
		);
	}

	$html .= '</select>';

	$fields[ FIELD ] = array(
		'label' => __( 'Folder', 'wow-media-folders' ),
		'input' => 'html',
		'html'  => $html,
	);

	return $fields;
}
add_filter( 'attachment_fields_to_edit', __NAMESPACE__ . '\\attachment_field', 10, 2 );

/**
 * Saves the folder picker.
 *
 * A dropdown always submits something, so an empty choice is a choice: it is
 * the value that takes the file out of every folder. That is why there is no
 * marker input here — unlike a set of ticks, silence really does mean the
 * field was never shown.
 *
 * @param array<string,mixed> $post       Post data being saved.
 * @param array<string,mixed> $attachment Submitted attachment fields.
 * @return array<string,mixed>
 */
function save_attachment_field( array $post, array $attachment ): array {
	if ( ! isset( $attachment[ FIELD ] ) || is_array( $attachment[ FIELD ] ) ) {
		return $post;
	}

	$id = (int) ( $post['ID'] ?? 0 );

	if ( $id <= 0 || ! current_user_can( 'edit_post', $id ) ) {
		return $post;
	}

	$chosen = absint( $attachment[ FIELD ] );

	wp_set_object_terms( $id, 0 === $chosen ? array() : array( $chosen ), TAXONOMY );

	// A hand-picked folder is the last word; the album automation lets go.
	delete_post_meta( $id, \WOW\MediaFolders\Sync\AUTO_META );

	return $post;
}
add_filter( 'attachment_fields_to_save', __NAMESPACE__ . '\\save_attachment_field', 10, 2 );
