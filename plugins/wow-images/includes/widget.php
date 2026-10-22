<?php
/**
 * What the pictures weigh, on the dashboard.
 *
 * The first thing an owner wants to know about a gallery site is whether
 * it has got heavy, and the second is whether anything is being done about
 * it. Both are already known here — the conversion keeps a note against
 * every attachment of what the copies saved — so the numbers cost nothing
 * but the reading.
 *
 * The reading is not free, mind: it walks every attachment and every file
 * in the uploads folder, which on this site is twenty-seven thousand of
 * them. So it is done once every six hours and kept, and the dashboard
 * draws what was found rather than counting again for every visit.
 *
 * @package WOW\Images
 */

namespace WOW\Images\Widget;

use WOW\Images\Queue;

defined( 'ABSPATH' ) || exit;

/** Where the tally is kept between readings. */
const CACHE = 'wow_images_tally';

/** How long a reading stands. */
const KEEPS = 6 * HOUR_IN_SECONDS;

/**
 * The numbers, read afresh.
 *
 * @return array{pictures:int,waiting:int,saved:int,source:int,files:int,weight:int}
 */
function read(): array {
	$summary = Queue\summary();
	$uploads = wp_get_upload_dir();
	$files   = 0;
	$weight  = 0;

	if ( empty( $uploads['error'] ) && is_dir( $uploads['basedir'] ) ) {
		$walk = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $uploads['basedir'], \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $walk as $file ) {
			if ( $file->isFile() ) {
				++$files;
				$weight += (int) $file->getSize();
			}
		}
	}

	return array(
		'pictures' => (int) $summary['total'],
		'waiting'  => (int) $summary['waiting'],
		'saved'    => (int) $summary['saved'],
		'source'   => (int) $summary['source'],
		'files'    => $files,
		'weight'   => $weight,
	);
}

/**
 * The numbers, from the last reading unless it has gone stale.
 *
 * @param bool $fresh True to read again whatever is kept.
 * @return array<string,int>
 */
function tally( bool $fresh = false ): array {
	$kept = $fresh ? false : get_transient( CACHE );

	if ( is_array( $kept ) ) {
		return $kept;
	}

	$now = read();

	set_transient( CACHE, $now, KEEPS );

	return $now;
}

/**
 * Throws the kept reading away, so that the next look counts again.
 */
function forget(): void {
	delete_transient( CACHE );
}
add_action( 'wow_images_job_finished', __NAMESPACE__ . '\\forget' );

/**
 * Puts the widget on the dashboard.
 */
function add_widget(): void {
	if ( ! current_user_can( 'upload_files' ) ) {
		return;
	}

	wp_add_dashboard_widget(
		'wow-images-weight',
		__( 'Pictures and weight', 'wow-images' ),
		__NAMESPACE__ . '\\draw'
	);
}
add_action( 'wp_dashboard_setup', __NAMESPACE__ . '\\add_widget' );

/**
 * Draws it.
 */
function draw(): void {
	$tally = tally();
	$share = $tally['source'] > 0
		? min( 100, (int) round( $tally['saved'] / $tally['source'] * 100 ) )
		: 0;

	?>
	<ul class="wow-images-figures">
		<li>
			<strong><?php echo esc_html( number_format_i18n( $tally['pictures'] ) ); ?></strong>
			<span><?php esc_html_e( 'pictures', 'wow-images' ); ?></span>
		</li>
		<li>
			<strong><?php echo esc_html( size_format( $tally['weight'] ) ); ?></strong>
			<span>
			<?php
			printf(
				/* translators: %s: number of files. */
				esc_html__( 'in %s files', 'wow-images' ),
				esc_html( number_format_i18n( $tally['files'] ) )
			);
			?>
			</span>
		</li>
		<li>
			<strong><?php echo esc_html( size_format( $tally['saved'] ) ); ?></strong>
			<span><?php esc_html_e( 'saved by WebP and AVIF', 'wow-images' ); ?></span>
		</li>
	</ul>

	<div class="wow-images-tally-bar" role="img"
		aria-label="
		<?php
		printf(
			/* translators: %s: percentage. */
			esc_attr__( '%s per cent lighter', 'wow-images' ),
			esc_attr( number_format_i18n( $share ) )
		);
		?>
		">
		<span style="width:<?php echo esc_attr( (string) $share ); ?>%"></span>
	</div>

	<p class="wow-images-note">
		<?php
		printf(
			/* translators: 1: percentage, 2: weight of the originals. */
			esc_html__( 'The copies are %1$s%% lighter than the %2$s of originals they were made from.', 'wow-images' ),
			esc_html( number_format_i18n( $share ) ),
			esc_html( size_format( $tally['source'] ) )
		);
		?>
	</p>

	<?php if ( $tally['waiting'] > 0 ) : ?>
		<p class="wow-images-note">
			<?php
			printf(
				/* translators: 1: number of pictures, 2: opening link tag, 3: closing link tag. */
				esc_html( _n( '%1$s picture is still to convert. %2$sConvert it%3$s.', '%1$s pictures are still to convert. %2$sConvert them%3$s.', $tally['waiting'], 'wow-images' ) ),
				esc_html( number_format_i18n( $tally['waiting'] ) ),
				'<a href="' . esc_url( admin_url( 'tools.php?page=wow-images-bulk' ) ) . '">',
				'</a>'
			);
			?>
		</p>
	<?php endif; ?>
	<?php
}
