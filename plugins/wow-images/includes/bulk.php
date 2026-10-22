<?php
/**
 * The screen that starts the work and watches it.
 *
 * It no longer does the work. Starting a job writes it down on the server and
 * sends a worker after it; this page then only asks, every couple of seconds,
 * how far along it is. Closing the tab, losing the connection or shutting the
 * laptop changes nothing — the job is not here.
 *
 * The one exception is a host that will not let a site send a request to
 * itself, which is commoner than it ought to be and is true of the containers
 * this site is developed in. There the screen notices that nobody has taken
 * the job on and quietly does a turn of the work itself inside the request
 * that asked about progress. It says so, because then the tab does matter.
 *
 * @package WOW\Images
 */

namespace WOW\Images\Bulk;

use WOW\Images\Job;
use WOW\Images\Options;
use WOW\Images\Queue;

defined( 'ABSPATH' ) || exit;

/** Where the screen lives. */
const PAGE = 'wow-images-bulk';

/** How long the screen may spend doing the work when nobody else will. */
const RESCUE = 10.0;

/**
 * Puts the screen in the Tools menu.
 */
function add_page(): void {
	add_management_page(
		__( 'Convert images', 'wow-images' ),
		__( 'Convert images', 'wow-images' ),
		'upload_files',
		PAGE,
		__NAMESPACE__ . '\\page'
	);
}
add_action( 'admin_menu', __NAMESPACE__ . '\\add_page' );

/**
 * The attachments a new job should cover.
 *
 * Normally everything with something left to do. Arriving from the media
 * library's bulk action, only what was selected — and then all of it,
 * finished or not, because somebody who picks six pictures and asks for them
 * to be converted means those six.
 *
 * Read from either method on purpose: the media library arrives here with the
 * selection in the query string, and the form that starts the job sends it
 * back in the body. Taking only the first meant the screen counted the right
 * pictures and then started a job over none of them.
 *
 * The caller has already checked the nonce.
 *
 * @return int[]|null Null for "everything outstanding".
 */
function chosen(): ?array {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked by the caller.
	$ids = isset( $_REQUEST['ids'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['ids'] ) ) : '';

	if ( '' === $ids ) {
		return null;
	}

	$chosen = array();

	foreach ( array_filter( array_map( 'intval', explode( ',', $ids ) ) ) as $id ) {
		if ( 'attachment' === get_post_type( $id ) ) {
			$chosen[] = $id;
		}
	}

	return $chosen;
}

/**
 * Starts or stops a job, then sends the browser back to the screen.
 */
function handle(): void {
	if ( ! current_user_can( 'upload_files' ) ) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'wow-images' ) );
	}

	check_admin_referer( 'wow-images-bulk' );

	if ( isset( $_POST['wow-images-stop'] ) ) {
		Job\clear();
	} else {
		Job\start( chosen() );
	}

	wp_safe_redirect( admin_url( 'tools.php?page=' . PAGE ) );
	exit;
}
add_action( 'admin_post_wow_images_bulk', __NAMESPACE__ . '\\handle' );

/**
 * Draws the screen.
 */
function page(): void {
	if ( ! current_user_can( 'upload_files' ) ) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'wow-images' ) );
	}

	$job   = Job\state();
	$store = Options\store();
	?>
	<div class="wrap wow-images" id="wow-images-bulk">
		<h1><?php esc_html_e( 'Convert images', 'wow-images' ); ?></h1>

		<?php if ( null !== $job ) : ?>
			<?php draw_job( $job ); ?>
		<?php else : ?>
			<?php draw_start( $store ); ?>
		<?php endif; ?>
	</div>
	<?php

	if ( null !== $job && empty( $job['finished'] ) ) {
		assets( $job );
	}
}

/**
 * The form that starts a job.
 *
 * @param string $store The format pictures are stored in, if any.
 */
function draw_start( string $store ): void {
	$ids     = chosen();
	$waiting = null === $ids ? count( Queue\pending() ) : count( $ids );

	if ( 0 === $waiting ) {
		?>
		<div class="notice notice-success inline"><p><?php esc_html_e( 'Every picture on the site is already done.', 'wow-images' ); ?></p></div>
		<p>
			<a class="button" href="<?php echo esc_url( admin_url( 'options-general.php?page=' . \WOW\Images\Admin\PAGE ) ); ?>">
				<?php esc_html_e( 'Settings', 'wow-images' ); ?>
			</a>
		</p>
		<?php

		return;
	}
	?>
	<p>
		<?php
		printf(
			/* translators: %s: number of pictures. */
			esc_html( _n( '%s picture has work waiting.', '%s pictures have work waiting.', $waiting, 'wow-images' ) ),
			'<strong>' . esc_html( number_format_i18n( $waiting ) ) . '</strong>'
		);
		?>
	</p>

	<?php if ( '' !== $store ) : ?>
		<div class="notice notice-warning inline">
			<p><strong><?php esc_html_e( 'This replaces the pictures, it does not only add to them.', 'wow-images' ); ?></strong></p>
			<p>
				<?php
				printf(
					/* translators: %s: format name, e.g. WebP. */
					esc_html__( 'Each picture is written out again as %s and the file it came from is deleted. The crops are cut afresh and the pages that point at them are corrected. There is no way back from here: take a copy of the uploads folder and of the database first.', 'wow-images' ),
					esc_html( \WOW\Images\Formats\label( $store ) )
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<p class="description">
		<?php esc_html_e( 'The work happens on the server, not in this tab. Once it has started you can close this page, and it will carry on without you.', 'wow-images' ); ?>
	</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'wow-images-bulk' ); ?>
		<input type="hidden" name="action" value="wow_images_bulk" />
		<?php if ( null !== $ids ) : ?>
			<input type="hidden" name="ids" value="<?php echo esc_attr( implode( ',', $ids ) ); ?>" />
		<?php endif; ?>
		<?php submit_button( __( 'Start', 'wow-images' ), 'primary' ); ?>
	</form>
	<?php
}

/**
 * The tally of a job in progress, or of the one that has just ended.
 *
 * @param array<string,mixed> $job The job.
 */
function draw_job( array $job ): void {
	$done  = (int) $job['done'];
	$total = max( 1, (int) $job['total'] );
	?>
	<div id="wow-images-progress"
		data-done="<?php echo esc_attr( (string) $done ); ?>"
		data-total="<?php echo esc_attr( (string) $job['total'] ); ?>">

		<div class="wow-images-bar"><span style="width:<?php echo esc_attr( (string) round( $done / $total * 100 ) ); ?>%"></span></div>

		<p class="wow-images-tally">
			<?php
			printf(
				/* translators: 1: pictures done, 2: pictures in all. */
				esc_html__( '%1$s of %2$s', 'wow-images' ),
				'<strong class="wow-images-done">' . esc_html( number_format_i18n( $done ) ) . '</strong>',
				'<strong>' . esc_html( number_format_i18n( (int) $job['total'] ) ) . '</strong>'
			);
			?>
			<span class="wow-images-note"></span>
		</p>
	</div>

	<?php if ( ! empty( $job['finished'] ) ) : ?>
		<div class="notice notice-success inline">
			<p>
				<?php
				printf(
					/* translators: 1: pictures stored anew, 2: copies written, 3: addresses corrected. */
					esc_html__( 'Finished. %1$s pictures stored anew, %2$s copies written, %3$s addresses corrected.', 'wow-images' ),
					'<strong>' . esc_html( number_format_i18n( (int) $job['stored'] ) ) . '</strong>',
					'<strong>' . esc_html( number_format_i18n( (int) $job['copies'] ) ) . '</strong>',
					'<strong>' . esc_html( number_format_i18n( (int) $job['names'] ) ) . '</strong>'
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $job['errors'] ) ) : ?>
		<div class="notice notice-warning inline">
			<p><strong><?php esc_html_e( 'Some pictures could not be done:', 'wow-images' ); ?></strong></p>
			<ul class="wow-images-errors">
				<?php foreach ( (array) $job['errors'] as $error ) : ?>
					<li><?php echo esc_html( (string) $error ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'wow-images-bulk' ); ?>
		<input type="hidden" name="action" value="wow_images_bulk" />
		<input type="hidden" name="wow-images-stop" value="1" />
		<?php submit_button( empty( $job['finished'] ) ? __( 'Stop', 'wow-images' ) : __( 'Clear', 'wow-images' ), 'secondary' ); ?>
	</form>
	<?php
}

/**
 * Hands the page what it needs to follow the job.
 *
 * @param array<string,mixed> $job The job.
 */
function assets( array $job ): void {
	wp_enqueue_script( 'wow-images-bulk', WOW_IMAGES_URL . 'assets/bulk.js', array(), WOW_IMAGES_VERSION, true );
	wp_localize_script(
		'wow-images-bulk',
		'wowImagesJob',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'action'  => Job\PROGRESS,
			'nonce'   => wp_create_nonce( Job\PROGRESS ),
			'text'    => array(
				'waiting' => __( 'waiting for the server to pick the job up…', 'wow-images' ),
				'driving' => __( 'this server cannot call itself, so this tab is doing the work — leave it open', 'wow-images' ),
				'working' => __( 'working', 'wow-images' ),
			),
		)
	);
}

/**
 * Answers the screen, and lends a hand when nobody else will.
 */
function progress(): void {
	check_ajax_referer( Job\PROGRESS, 'nonce' );

	if ( ! current_user_can( 'upload_files' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'wow-images' ) ), 403 );
	}

	$job = Job\state();

	if ( null === $job ) {
		wp_send_json_success( array( 'gone' => true ) );
	}

	$driving = false;

	/*
	 * Nothing has happened for a while and no worker holds the lock, so no
	 * worker is coming: either the site cannot call itself or the first call
	 * was lost. Rather than leave the job sitting there, this request does a
	 * short turn of the work — which is the old browser-driven behaviour,
	 * reached only when the better way is unavailable.
	 */
	$quiet = max( (int) $job['heartbeat'], (int) $job['started'] );

	if ( empty( $job['finished'] ) && ! Job\working() && time() - $quiet > 20 && Job\claim() ) {
		ignore_user_abort( true );

		$driving = true;
		$job     = Job\step( RESCUE );

		Job\release();

		if ( null !== $job && empty( $job['finished'] ) ) {
			Job\dispatch();
		}
	}

	wp_send_json_success(
		array(
			'done'     => (int) ( $job['done'] ?? 0 ),
			'total'    => (int) ( $job['total'] ?? 0 ),
			'finished' => ! empty( $job['finished'] ),
			'working'  => Job\working(),
			'driving'  => $driving,
			'errors'   => count( (array) ( $job['errors'] ?? array() ) ),
		)
	);
}
add_action( 'wp_ajax_' . Job\PROGRESS, __NAMESPACE__ . '\\progress' );
