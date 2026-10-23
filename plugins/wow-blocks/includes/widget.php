<?php
/**
 * The albums, on the dashboard.
 *
 * A gallery site is its albums, and the first screen after logging in is
 * the one place nobody has to be taught to look at. So it says how much
 * work is on the site, which albums hold the most of it, what arrived this
 * month — and the two things that quietly go wrong on a site made of
 * pictures: photographs nobody has described, and pages nobody has given a
 * description to.
 *
 * Counting is cheap here because the albums already keep a tally of their
 * own pictures, refreshed as they are saved. What is not cheap is reading
 * the alternative text of sixteen hundred attachments, so that number is
 * read once an hour and kept.
 *
 * @package WOW\Blocks
 */

namespace WOW\Blocks\Widget;

use function WOW\Blocks\Albums\image_ids;

defined( 'ABSPATH' ) || exit;

/** Where the tally is kept between readings. */
const CACHE = 'wow_blocks_tally';

/** How long a reading stands. */
const KEEPS = HOUR_IN_SECONDS;

/**
 * The numbers behind the widget.
 *
 * @return array{albums:int,pictures:int,fresh:int,described:int,total:int,fullest:array<string,int>,quiet:int}
 */
function read(): array {
	global $wpdb;

	$albums  = get_posts(
		array(
			'post_type'   => 'wow_album',
			'numberposts' => -1,
			'post_status' => 'publish',
		)
	);
	$fullest = array();
	$unique  = array();

	foreach ( $albums as $album ) {
		$held = (int) get_post_meta( $album->ID, '_wow_album_image_count', true );

		$fullest[ $album->post_title ] = $held;

		/*
		 * Counted by id rather than added up from the albums' own tallies.
		 * A photograph placed in two albums is one photograph, and the
		 * tallies, summed, said sixteen hundred and eighty-six where the
		 * library holds fifteen hundred and ninety-eight — the owner
		 * reuses photographs in the narrow strips between the rows.
		 *
		 * Without the strips, which is how an album counts its own: a
		 * strip divides the gallery rather than showing work, and the
		 * figure above the bars has to mean what the bars mean.
		 */
		foreach ( image_ids( $album->ID, false ) as $id ) {
			$unique[ $id ] = true;
		}
	}

	$count = count( $unique );

	arsort( $fullest );

	$month = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%%' AND post_date > %s",
			gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS )
		)
	);

	$total = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'"
	);

	$described = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->postmeta} m
		INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_mime_type LIKE 'image/%'
		WHERE m.meta_key = '_wp_attachment_image_alt' AND m.meta_value <> ''"
	);

	$quiet = 0;

	foreach ( get_posts( array( 'post_type' => 'page', 'numberposts' => -1, 'post_status' => 'publish' ) ) as $page ) {
		if ( '' === trim( $page->post_excerpt ) ) {
			++$quiet;
		}
	}

	return array(
		'albums'    => count( $albums ),
		'pictures'  => $count,
		'fresh'     => (int) $month,
		'described' => $described,
		'total'     => $total,
		'fullest'   => array_slice( $fullest, 0, 3, true ),
		'quiet'     => $quiet,
	);
}

/**
 * The numbers, from the last reading unless it has gone stale.
 *
 * @return array<string,mixed>
 */
function tally(): array {
	$kept = get_transient( CACHE );

	if ( is_array( $kept ) ) {
		return $kept;
	}

	$now = read();

	set_transient( CACHE, $now, KEEPS );

	return $now;
}

/**
 * Throws the kept reading away when an album changes.
 */
function forget(): void {
	delete_transient( CACHE );
}
add_action( 'save_post_wow_album', __NAMESPACE__ . '\\forget' );
add_action( 'deleted_post', __NAMESPACE__ . '\\forget' );

/**
 * Puts the widget on the dashboard.
 */
function add_widget(): void {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	wp_add_dashboard_widget(
		'wow-blocks-albums',
		__( 'Albums and photographs', 'wow-blocks' ),
		__NAMESPACE__ . '\\draw'
	);
}
add_action( 'wp_dashboard_setup', __NAMESPACE__ . '\\add_widget' );

/**
 * Loads the few lines of style the widget needs.
 *
 * @param string $hook Current admin page.
 */
function assets( string $hook ): void {
	if ( 'index.php' !== $hook ) {
		return;
	}

	$css = WOW_BLOCKS_DIR . 'assets/widget.css';

	wp_enqueue_style( 'wow-blocks-widget', WOW_BLOCKS_URL . 'assets/widget.css', array(), (string) filemtime( $css ) );
}
add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\assets' );

/**
 * Draws it.
 */
function draw(): void {
	$tally    = tally();
	$share    = $tally['total'] > 0
		? (int) round( $tally['described'] / $tally['total'] * 100 )
		: 100;
	$biggest  = max( 1, (int) ( array_values( $tally['fullest'] )[0] ?? 1 ) );

	?>
	<ul class="wow-blocks-figures">
		<li>
			<strong><?php echo esc_html( number_format_i18n( $tally['albums'] ) ); ?></strong>
			<span><?php esc_html_e( 'albums', 'wow-blocks' ); ?></span>
		</li>
		<li>
			<strong><?php echo esc_html( number_format_i18n( $tally['pictures'] ) ); ?></strong>
			<span><?php esc_html_e( 'photographs in them', 'wow-blocks' ); ?></span>
		</li>
		<li>
			<strong><?php echo esc_html( number_format_i18n( $tally['fresh'] ) ); ?></strong>
			<span><?php esc_html_e( 'added this month', 'wow-blocks' ); ?></span>
		</li>
	</ul>

	<?php if ( ! empty( $tally['fullest'] ) ) : ?>
		<ul class="wow-blocks-fullest">
			<?php foreach ( $tally['fullest'] as $title => $held ) : ?>
				<li>
					<span class="wow-blocks-name"><?php echo esc_html( $title ); ?></span>
					<span class="wow-blocks-rail">
						<span style="width:<?php echo esc_attr( (string) round( $held / $biggest * 100 ) ); ?>%"></span>
					</span>
					<span class="wow-blocks-held"><?php echo esc_html( number_format_i18n( $held ) ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<p class="wow-blocks-note">
		<?php if ( $share >= 100 ) : ?>
			<?php esc_html_e( 'Every photograph has alternative text.', 'wow-blocks' ); ?>
		<?php else : ?>
			<?php
			printf(
				/* translators: 1: percentage, 2: opening link tag, 3: closing link tag. */
				esc_html__( '%1$s%% of the photographs have alternative text. %2$sDescribe the rest%3$s.', 'wow-blocks' ),
				esc_html( number_format_i18n( $share ) ),
				'<a href="' . esc_url( admin_url( 'tools.php?page=wow-blocks-alt' ) ) . '">',
				'</a>'
			);
			?>
		<?php endif; ?>
	</p>

	<?php
}
