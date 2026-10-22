<?php
/**
 * The letters, on the dashboard.
 *
 * A contact form that nobody looks at is a contact form that loses work.
 * The messages are already kept in the site's own database, so the first
 * screen after logging in can say how many came, when, and who wrote last
 * — and from there it is one click to the letter itself.
 *
 * Twelve months of bars rather than a number alone: a month with five
 * letters means nothing until it is seen beside a month with one. The
 * bars are plain markup, no chart library and no script; there are twelve
 * of them and they are drawn by the server.
 *
 * @package WOW\ContactForm
 */

namespace WOW\ContactForm\Widget;

use WOW\ContactForm\Storage;

defined( 'ABSPATH' ) || exit;

/** How many months of history the bars show. */
const MONTHS = 12;

/** How many of the latest letters are named. */
const LATEST = 3;

/**
 * The numbers behind the widget.
 *
 * @return array{total:int,month:int,week:int,months:array<string,int>,latest:\WP_Post[]}
 */
function read(): array {
	global $wpdb;

	$type = Storage\POST_TYPE;

	$total = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
			$type
		)
	);

	$since = static function ( int $days ) use ( $wpdb, $type ): int {
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts}
				WHERE post_type = %s AND post_status = 'publish' AND post_date > %s",
				$type,
				gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS )
			)
		);
	};

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT DATE_FORMAT(post_date, '%%Y-%%m') AS month, COUNT(*) AS n
			FROM {$wpdb->posts}
			WHERE post_type = %s AND post_status = 'publish' AND post_date > %s
			GROUP BY month",
			$type,
			gmdate( 'Y-m-01 00:00:00', strtotime( '-' . ( MONTHS - 1 ) . ' months' ) )
		),
		OBJECT_K
	);

	// Every month in the window, including the empty ones: a gap is part
	// of the shape and a chart that leaves it out is drawing a lie.
	$months = array();

	for ( $back = MONTHS - 1; $back >= 0; $back-- ) {
		$key            = gmdate( 'Y-m', strtotime( "-{$back} months" ) );
		$months[ $key ] = isset( $rows[ $key ] ) ? (int) $rows[ $key ]->n : 0;
	}

	return array(
		'total'  => $total,
		'month'  => $since( 30 ),
		'week'   => $since( 7 ),
		'months' => $months,
		'latest' => get_posts(
			array(
				'post_type'   => $type,
				'numberposts' => LATEST,
				'post_status' => 'publish',
			)
		),
	);
}

/**
 * Puts the widget on the dashboard.
 */
function add_widget(): void {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	wp_add_dashboard_widget(
		'wow-contact-form-messages',
		__( 'Messages', 'wow-contact-form' ),
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

	$css = WOW_CONTACT_FORM_DIR . 'assets/widget.css';

	wp_enqueue_style(
		'wow-contact-form-widget',
		WOW_CONTACT_FORM_URL . 'assets/widget.css',
		array(),
		(string) filemtime( $css )
	);
}
add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\assets' );

/**
 * Draws it.
 */
function draw(): void {
	$tally   = read();
	$tallest = max( 1, max( $tally['months'] ) );

	?>
	<ul class="wow-messages-figures">
		<li>
			<strong><?php echo esc_html( number_format_i18n( $tally['week'] ) ); ?></strong>
			<span><?php esc_html_e( 'in the last seven days', 'wow-contact-form' ); ?></span>
		</li>
		<li>
			<strong><?php echo esc_html( number_format_i18n( $tally['month'] ) ); ?></strong>
			<span><?php esc_html_e( 'in the last thirty', 'wow-contact-form' ); ?></span>
		</li>
		<li>
			<strong><?php echo esc_html( number_format_i18n( $tally['total'] ) ); ?></strong>
			<span><?php esc_html_e( 'in all', 'wow-contact-form' ); ?></span>
		</li>
	</ul>

	<ul class="wow-messages-months">
		<?php foreach ( $tally['months'] as $month => $n ) : ?>
			<li title="<?php echo esc_attr( $month . ' — ' . number_format_i18n( $n ) ); ?>">
				<span class="wow-messages-column">
					<span style="height:<?php echo esc_attr( (string) max( 2, (int) round( $n / $tallest * 100 ) ) ); ?>%"></span>
				</span>
				<span class="wow-messages-month">
					<?php echo esc_html( mb_substr( date_i18n( 'M', (int) strtotime( $month . '-01' ) ), 0, 1 ) ); ?>
				</span>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php if ( ! empty( $tally['latest'] ) ) : ?>
		<ul class="wow-messages-latest">
			<?php foreach ( $tally['latest'] as $letter ) : ?>
				<li>
					<a href="<?php echo esc_url( (string) get_edit_post_link( $letter->ID ) ); ?>">
						<?php echo esc_html( $letter->post_title ); ?>
					</a>
					<span>
					<?php
					printf(
						/* translators: %s: how long ago, such as "2 days". */
						esc_html__( '%s ago', 'wow-contact-form' ),
						esc_html( human_time_diff( (int) get_post_timestamp( $letter ) ) )
					);
					?>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>

		<p class="wow-messages-note">
			<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Storage\POST_TYPE ) ); ?>">
				<?php esc_html_e( 'All messages', 'wow-contact-form' ); ?>
			</a>
		</p>
	<?php else : ?>
		<p class="wow-messages-note"><?php esc_html_e( 'Nobody has written yet.', 'wow-contact-form' ); ?></p>
	<?php endif; ?>
	<?php
}
