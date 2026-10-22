<?php
/**
 * The folder panel on the media library screen.
 *
 * upload.php carries no action hook anywhere inside its page body, so the
 * panel is printed on all_admin_notices — the last hook that still fires
 * inside #wpbody-content — and the script lifts it into place next to the
 * library. With JavaScript off it stays where it was printed: a plain list of
 * links above the library, which still filters, just without the layout.
 *
 * @package WOW\MediaFolders
 */

namespace WOW\MediaFolders\AdminSidebar;

use function WOW\MediaFolders\Query\wants_none;

use const WOW\MediaFolders\Query\NONE_PARAM;
use const WOW\MediaFolders\Taxonomy\TAXONOMY;

defined( 'ABSPATH' ) || exit;

/** AJAX action behind every change the panel makes. */
const AJAX_ACTION = 'wow_media_folders_update';

/** Nonce guarding that action. */
const NONCE = 'wow-media-folders';

/**
 * Whether the current screen is the media library.
 */
function on_library(): bool {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	return $screen instanceof \WP_Screen && 'upload' === $screen->id;
}

/**
 * The library view in use, grid or list.
 */
function current_mode(): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading the view mode.
	$mode = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : '';

	if ( 'list' === $mode || 'grid' === $mode ) {
		return $mode;
	}

	return (string) get_user_option( 'media_library_mode' ) === 'list' ? 'list' : 'grid';
}

/**
 * The folder shown right now, by slug, or an empty string for all of them.
 */
function current_slug(): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading a list filter.
	return isset( $_GET[ TAXONOMY ] ) ? sanitize_title( wp_unslash( $_GET[ TAXONOMY ] ) ) : '';
}

/**
 * How many attachments sit in no folder at all.
 *
 * Counted rather than cached: one NOT EXISTS over a library of a few thousand
 * rows costs less than the staleness a transient would introduce right after
 * something is filed.
 */
function unfiled_count(): int {
	$ids = get_posts(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'tax_query'      => \WOW\MediaFolders\Query\none_clause(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		)
	);

	return count( $ids );
}

/**
 * Every folder, empty ones included.
 *
 * @return \WP_Term[]
 */
function folders(): array {
	$terms = get_terms(
		array(
			'taxonomy'   => TAXONOMY,
			'hide_empty' => false,
			'orderby'    => 'name',
		)
	);

	return is_wp_error( $terms ) ? array() : $terms;
}

/**
 * A library URL with the folder filter applied.
 */
function folder_url( string $slug, bool $none = false ): string {
	$args = array( 'mode' => current_mode() );

	if ( $none ) {
		$args[ NONE_PARAM ] = 1;
	} elseif ( '' !== $slug ) {
		$args[ TAXONOMY ] = $slug;
	}

	return add_query_arg( $args, admin_url( 'upload.php' ) );
}

/**
 * Prints the panel.
 */
function render(): void {
	if ( ! on_library() || ! current_user_can( 'upload_files' ) ) {
		return;
	}

	$slug     = current_slug();
	$none     = wants_none();
	$all      = ! $none && '' === $slug;
	$may_edit = current_user_can( 'manage_categories' );

	$total = array_sum( (array) wp_count_attachments() );
	?>
	<div class="wow-folders" id="wow-folders">
		<div class="wow-folders__head">
			<strong class="wow-folders__title"><?php esc_html_e( 'Folders', 'wow-media-folders' ); ?></strong>
			<?php if ( $may_edit ) : ?>
				<button type="button" class="button button-primary wow-folders__new">
					<?php esc_html_e( 'New', 'wow-media-folders' ); ?>
				</button>
			<?php endif; ?>
		</div>

		<label class="screen-reader-text" for="wow-folders-search"><?php esc_html_e( 'Search folders', 'wow-media-folders' ); ?></label>
		<input type="search" id="wow-folders-search" class="wow-folders__search"
			placeholder="<?php esc_attr_e( 'Folder name…', 'wow-media-folders' ); ?>">

		<ul class="wow-folders__list">
			<li class="wow-folders__item<?php echo $all ? ' is-current' : ''; ?>" data-folder="">
				<a class="wow-folders__link" href="<?php echo esc_url( folder_url( '' ) ); ?>">
					<span class="wow-folders__name"><?php esc_html_e( 'All files', 'wow-media-folders' ); ?></span>
					<span class="wow-folders__count"><?php echo esc_html( number_format_i18n( $total ) ); ?></span>
				</a>
			</li>
			<li class="wow-folders__item<?php echo $none ? ' is-current' : ''; ?>" data-folder="none">
				<a class="wow-folders__link" href="<?php echo esc_url( folder_url( '', true ) ); ?>">
					<span class="wow-folders__name"><?php esc_html_e( 'No folder', 'wow-media-folders' ); ?></span>
					<span class="wow-folders__count"><?php echo esc_html( number_format_i18n( unfiled_count() ) ); ?></span>
				</a>
			</li>

			<?php foreach ( folders() as $term ) : ?>
				<li class="wow-folders__item<?php echo $slug === $term->slug ? ' is-current' : ''; ?>"
					data-folder="<?php echo esc_attr( $term->slug ); ?>"
					data-term="<?php echo esc_attr( (string) $term->term_id ); ?>">
					<a class="wow-folders__link" href="<?php echo esc_url( folder_url( $term->slug ) ); ?>">
						<span class="wow-folders__name"><?php echo esc_html( $term->name ); ?></span>
						<span class="wow-folders__count"><?php echo esc_html( number_format_i18n( $term->count ) ); ?></span>
					</a>
					<?php if ( $may_edit ) : ?>
						<span class="wow-folders__tools">
							<button type="button" class="wow-folders__rename" aria-label="<?php esc_attr_e( 'Rename folder', 'wow-media-folders' ); ?>">
								<span class="dashicons dashicons-edit"></span>
							</button>
							<button type="button" class="wow-folders__delete" aria-label="<?php esc_attr_e( 'Delete folder', 'wow-media-folders' ); ?>">
								<span class="dashicons dashicons-trash"></span>
							</button>
						</span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>

		<p class="wow-folders__empty" hidden><?php esc_html_e( 'No folder matches that name.', 'wow-media-folders' ); ?></p>
	</div>
	<?php
}
add_action( 'all_admin_notices', __NAMESPACE__ . '\\render' );

/**
 * @param string $hook Current admin page.
 */
function enqueue_assets( string $hook ): void {
	if ( 'upload.php' !== $hook || ! current_user_can( 'upload_files' ) ) {
		return;
	}

	wp_enqueue_style(
		'wow-media-folders',
		WOW_MEDIA_FOLDERS_URL . 'assets/folders.css',
		array( 'dashicons' ),
		(string) filemtime( WOW_MEDIA_FOLDERS_DIR . 'assets/folders.css' )
	);

	wp_enqueue_script(
		'wow-media-folders',
		WOW_MEDIA_FOLDERS_URL . 'assets/folders.js',
		array( 'jquery' ),
		(string) filemtime( WOW_MEDIA_FOLDERS_DIR . 'assets/folders.js' ),
		true
	);

	wp_localize_script(
		'wow-media-folders',
		'wowMediaFolders',
		array(
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'action'     => AJAX_ACTION,
			'nonce'      => wp_create_nonce( NONCE ),
			'taxonomy'   => TAXONOMY,
			'noneParam'  => NONE_PARAM,
			'mode'       => current_mode(),
			'canEdit'    => current_user_can( 'manage_categories' ),
			'strings'    => array(
				'newPrompt'    => __( 'Name for the new folder', 'wow-media-folders' ),
				'renamePrompt' => __( 'New name for the folder', 'wow-media-folders' ),
				/* translators: %s: folder name. */
				'deleteAsk'    => __( 'Delete the folder “%s”? The files inside stay in the library.', 'wow-media-folders' ),
				'failed'       => __( 'That did not work. Reload the page and try again.', 'wow-media-folders' ),
			),
		)
	);
}
add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_assets' );
