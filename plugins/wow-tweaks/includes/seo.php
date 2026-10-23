<?php
/**
 * What a search engine and a messenger see, on the dashboard.
 *
 * Not a score out of a hundred and not advice: a short list of things that
 * are either in place or not, each checked on this site rather than
 * recited from a guide. Where something is missing the widget says what
 * follows from it — a page shared on WhatsApp with no picture, a listing
 * with no sentence under the title — because that is the part an owner can
 * judge.
 *
 * Two of the checks are made by fetching the site's own front page and
 * reading the head, once a day. Asking the page is the only honest way to
 * know what is in it: a theme, a plugin or a snippet may put a description
 * there, and reading the code to guess would be reading the wrong half.
 *
 * @package WOW\Tweaks
 */

namespace WOW\Tweaks\Seo;

defined( 'ABSPATH' ) || exit;

/** Where the reading of the front page is kept. */
const CACHE = 'wow_tweaks_seo_head';

/** How long it stands. */
const KEEPS = DAY_IN_SECONDS;

/**
 * What the front page carries in its head.
 *
 * @return array{description:bool,og:bool,image:bool,read:bool}
 */
function head(): array {
	$kept = get_transient( CACHE );

	if ( is_array( $kept ) ) {
		return $kept;
	}

	$answer = wp_remote_get(
		home_url( '/' ),
		array(
			'timeout'   => 8,
			'sslverify' => false,
		)
	);

	$found = array(
		'description' => false,
		'og'          => false,
		'image'       => false,
		'read'        => false,
	);

	if ( ! is_wp_error( $answer ) ) {
		$html  = (string) wp_remote_retrieve_body( $answer );
		$top   = substr( $html, 0, 60000 );
		$found = array(
			'description' => (bool) preg_match( '/<meta[^>]+name=["\']description["\']/i', $top ),
			'og'          => (bool) preg_match( '/<meta[^>]+property=["\']og:title["\']/i', $top ),
			'image'       => (bool) preg_match( '/<meta[^>]+property=["\']og:image["\']/i', $top ),
			'read'        => '' !== $top,
		);
	}

	set_transient( CACHE, $found, KEEPS );

	return $found;
}

/**
 * Throws the reading away, so the next look asks the page again.
 */
function forget(): void {
	delete_transient( CACHE );
}
add_action( 'switch_theme', __NAMESPACE__ . '\\forget' );
add_action( 'activated_plugin', __NAMESPACE__ . '\\forget' );
add_action( 'deactivated_plugin', __NAMESPACE__ . '\\forget' );

/**
 * The checks, each with its verdict and what follows from it.
 *
 * @return array<int,array{good:bool,label:string,note:string}>
 */
function checks(): array {
	$head  = head();
	$lines = array();

	$lines[] = array(
		'good'  => (bool) get_option( 'blog_public' ),
		'label' => __( 'Open to search engines', 'wow-tweaks' ),
		'note'  => __( 'Reading is discouraged in Settings → Reading, so the site asks not to be listed at all.', 'wow-tweaks' ),
	);

	$lines[] = array(
		'good'  => '' !== trim( (string) get_option( 'blogdescription' ) ),
		'label' => __( 'The site says what it is', 'wow-tweaks' ),
		'note'  => __( 'The tagline is empty, so the one line describing the business is missing from the title of every page.', 'wow-tweaks' ),
	);

	$lines[] = array(
		'good'  => $head['description'],
		'label' => __( 'A sentence under the title in search results', 'wow-tweaks' ),
		'note'  => __( 'No description is written into the pages, so a search engine prints whatever words it finds first.', 'wow-tweaks' ),
	);

	$lines[] = array(
		'good'  => $head['og'] && $head['image'],
		'label' => __( 'A picture when a link is shared', 'wow-tweaks' ),
		'note'  => __( 'No Open Graph tags, so a link sent on WhatsApp or posted on Facebook arrives as a bare address.', 'wow-tweaks' ),
	);

	$lines[] = array(
		'good'  => '' !== (string) get_option( 'permalink_structure' ),
		'label' => __( 'Addresses made of words', 'wow-tweaks' ),
		'note'  => __( 'Permalinks are the plain kind, so every page is a number rather than its name.', 'wow-tweaks' ),
	);

	$sitemap = function_exists( 'wp_sitemaps_get_server' ) && wp_sitemaps_get_server()
		&& wp_sitemaps_get_server()->sitemaps_enabled();

	$lines[] = array(
		'good'  => (bool) $sitemap,
		'label' => __( 'A map of the site for the crawlers', 'wow-tweaks' ),
		'note'  => __( 'The sitemap is switched off, so new albums are found only by following links.', 'wow-tweaks' ),
	);

	$lines[] = array(
		'good'  => 0 === strpos( (string) home_url(), 'https://' ),
		'label' => __( 'Served over a secure connection', 'wow-tweaks' ),
		'note'  => __( 'The site address is http, which browsers mark as not secure and search engines rank below https.', 'wow-tweaks' ),
	);

	return $lines;
}

/**
 * Puts the widget on the dashboard.
 */
function add_widget(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	wp_add_dashboard_widget(
		'wow-tweaks-seo',
		__( 'Found and shared', 'wow-tweaks' ),
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

	$css = WOW_TWEAKS_DIR . 'assets/seo.css';

	wp_enqueue_style( 'wow-tweaks-seo', WOW_TWEAKS_URL . 'assets/seo.css', array(), (string) filemtime( $css ) );
}
add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\assets' );

/**
 * Draws it.
 */
function draw(): void {
	$lines = checks();
	$good  = count( array_filter( $lines, static fn ( array $one ): bool => $one['good'] ) );
	$all   = count( $lines );

	?>
	<p class="wow-seo-score">
		<strong><?php echo esc_html( sprintf( '%d / %d', $good, $all ) ); ?></strong>
		<span><?php esc_html_e( 'in place', 'wow-tweaks' ); ?></span>
	</p>

	<ul class="wow-seo-list">
		<?php foreach ( $lines as $one ) : ?>
			<li class="<?php echo $one['good'] ? 'is-good' : 'is-missing'; ?>">
				<span class="wow-seo-mark" aria-hidden="true"><?php echo $one['good'] ? '&#10003;' : '&#8212;'; ?></span>
				<span>
					<?php echo esc_html( $one['label'] ); ?>
					<?php if ( ! $one['good'] ) : ?>
						<em><?php echo esc_html( $one['note'] ); ?></em>
					<?php endif; ?>
				</span>
			</li>
		<?php endforeach; ?>
	</ul>
	<?php
}
