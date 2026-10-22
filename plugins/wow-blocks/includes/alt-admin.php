<?php
/**
 * The screen that fills in alternative text.
 *
 * The command exists and is the honest way to do it, but a command is a
 * thing one has to remember and a terminal is a thing one has to open. The
 * work takes under a second for sixteen hundred pictures, so a button is
 * enough: it says how many are without text, shows what it intends to write
 * for each album, and writes it.
 *
 * Nothing destructive happens here — text already written is never touched
 * — so the button needs no confirmation beyond the preview above it.
 *
 * @package WOW\Blocks
 */

namespace WOW\Blocks\AltAdmin;

use function WOW\Blocks\AltText\fill;

defined( 'ABSPATH' ) || exit;

/** Where the screen lives. */
const PAGE = 'wow-blocks-alt';

/** The action the button posts to. */
const ACTION = 'wow_blocks_alt';

/** How many pictures one batch describes. */
const BATCH = 200;

/**
 * Puts the screen in the Tools menu.
 */
function add_page(): void {
	add_management_page(
		__( 'Alternative text', 'wow-blocks' ),
		__( 'Alternative text', 'wow-blocks' ),
		'upload_files',
		PAGE,
		__NAMESPACE__ . '\\page'
	);
}
add_action( 'admin_menu', __NAMESPACE__ . '\\add_page' );

/**
 * Writes the text, then sends the browser back to the screen.
 */
function handle(): void {
	if ( ! current_user_can( 'upload_files' ) ) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'wow-blocks' ) );
	}

	check_admin_referer( ACTION );

	$done = fill( true );

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'    => PAGE,
				'written' => (int) $done['written'],
			),
			admin_url( 'tools.php' )
		)
	);

	exit;
}
add_action( 'admin_post_' . ACTION, __NAMESPACE__ . '\\handle' );

/**
 * Describes one batch and says how much is left.
 *
 * Answers the script above. A batch rather than the lot, so that the bar
 * has something to draw and the request never sits long enough for anybody
 * to wonder whether the site has stopped.
 */
function step(): void {
	if ( ! current_user_can( 'upload_files' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'wow-blocks' ) ), 403 );
	}

	check_ajax_referer( ACTION );

	$done = fill( true, BATCH );

	wp_send_json_success(
		array(
			'written' => (int) $done['written'],
			'left'    => (int) $done['left'],
		)
	);
}
add_action( 'wp_ajax_' . ACTION, __NAMESPACE__ . '\\step' );

/**
 * Hands the screen its script and its stylesheet.
 *
 * @param string $hook The screen being drawn.
 */
function assets( string $hook ): void {
	if ( 'tools_page_' . PAGE !== $hook ) {
		return;
	}

	$js  = WOW_BLOCKS_DIR . 'assets/alt.js';
	$css = WOW_BLOCKS_DIR . 'assets/alt.css';

	wp_enqueue_style( 'wow-blocks-alt', WOW_BLOCKS_URL . 'assets/alt.css', array(), (string) filemtime( $css ) );
	wp_enqueue_script( 'wow-blocks-alt', WOW_BLOCKS_URL . 'assets/alt.js', array(), (string) filemtime( $js ), true );

	wp_localize_script(
		'wow-blocks-alt',
		'wowBlocksAlt',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'action'  => ACTION,
			'nonce'   => wp_create_nonce( ACTION ),
			'doneUrl' => add_query_arg(
				array(
					'page'    => PAGE,
					'written' => '%d',
				),
				admin_url( 'tools.php' )
			),
			'text'    => array(
				/* translators: 1: pictures described so far, 2: pictures in all. */
				'counting' => __( '%1$s of %2$s', 'wow-blocks' ),
				'failed'   => __( 'Something went wrong. Nothing is lost — try again.', 'wow-blocks' ),
			),
		)
	);
}
add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\assets' );

/**
 * Draws the screen.
 */
function page(): void {
	if ( ! current_user_can( 'upload_files' ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a count to show, not an instruction to act on.
	$written = isset( $_GET['written'] ) ? max( 0, (int) $_GET['written'] ) : null;
	$found   = fill( false );

	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Alternative text', 'wow-blocks' ); ?></h1>

		<?php if ( null !== $written ) : ?>
			<div class="notice notice-success is-dismissible">
				<p>
				<?php
				printf(
					/* translators: %s: number of pictures. */
					esc_html( _n( '%s picture described.', '%s pictures described.', $written, 'wow-blocks' ) ),
					esc_html( number_format_i18n( $written ) )
				);
				?>
				</p>
			</div>
		<?php endif; ?>

		<p>
			<?php
			esc_html_e(
				'A picture without alternative text is announced as "image" by a screen reader and read as nothing by a search engine. The text is built from the album a picture sits in and, where it adds anything, from the name of its file. It says where the picture belongs rather than what is in the frame, which is better than silence and no substitute for a sentence written by somebody who looked at it.',
				'wow-blocks'
			);
			?>
		</p>

		<p>
			<strong>
			<?php
			printf(
				/* translators: 1: pictures without text, 2: pictures already described. */
				esc_html__( 'Without text: %1$s. Already described: %2$s.', 'wow-blocks' ),
				esc_html( number_format_i18n( $found['written'] ) ),
				esc_html( number_format_i18n( $found['skipped'] ) )
			);
			?>
			</strong>
		</p>

		<?php if ( ! empty( $found['samples'] ) ) : ?>
			<table class="widefat striped" style="max-width:900px">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Picture', 'wow-blocks' ); ?></th>
						<th><?php esc_html_e( 'Text it would be given', 'wow-blocks' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $found['samples'] as $file => $text ) : ?>
						<tr>
							<td><code><?php echo esc_html( $file ); ?></code></td>
							<td><?php echo esc_html( $text ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description">
				<?php esc_html_e( 'One example from each album.', 'wow-blocks' ); ?>
			</p>
		<?php endif; ?>

		<?php if ( $found['written'] > 0 ) : ?>
			<form id="wow-blocks-alt-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( ACTION ); ?>">
				<?php wp_nonce_field( ACTION ); ?>
				<?php submit_button( __( 'Describe them', 'wow-blocks' ) ); ?>
			</form>

			<div id="wow-blocks-alt-progress" hidden
				data-total="<?php echo esc_attr( (string) $found['written'] ); ?>">
				<div class="wow-blocks-bar"><span></span></div>
				<p class="wow-blocks-tally"></p>
			</div>
		<?php else : ?>
			<p><?php esc_html_e( 'Every picture in an album has text. New ones are described as the album is saved.', 'wow-blocks' ); ?></p>
		<?php endif; ?>
	</div>
	<?php
}
