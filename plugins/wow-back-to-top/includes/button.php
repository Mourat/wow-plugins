<?php
/**
 * The button itself: markup in the footer and the two assets behind it.
 *
 * @package WOW\BackToTop
 */

namespace WOW\BackToTop\Button;

defined( 'ABSPATH' ) || exit;

/**
 * Whether the button belongs on the page being rendered.
 *
 * Asked once per request and reused by both the enqueue and the markup, so
 * a filter can never switch one on without the other.
 *
 * @return bool true when the button should be printed.
 */
function is_enabled(): bool {
	$enabled = ! is_admin() && ! is_feed() && ! is_embed();

	/**
	 * Filters whether the back-to-top button appears on this page.
	 *
	 * @param bool $enabled Default decision.
	 */
	return (bool) apply_filters( 'wow_back_to_top_enabled', $enabled );
}

/**
 * How far the visitor has to scroll before the button shows up.
 *
 * @return int Distance in pixels, never below zero.
 */
function offset(): int {
	/**
	 * Filters the scroll distance that reveals the button.
	 *
	 * @param int $offset Distance in pixels.
	 */
	return max( 0, (int) apply_filters( 'wow_back_to_top_offset', 300 ) );
}

/**
 * Registers the stylesheet and the script.
 *
 * Versions come from filemtime: the files are not built, so during work on
 * the site the browser has nothing stale to hold on to.
 */
function enqueue(): void {
	if ( ! is_enabled() ) {
		return;
	}

	wp_enqueue_style(
		'wow-back-to-top',
		WOW_BACK_TO_TOP_URL . 'assets/back-to-top.css',
		array(),
		(string) filemtime( WOW_BACK_TO_TOP_DIR . 'assets/back-to-top.css' )
	);

	/*
	 * Deferred: the script only reacts to scrolling and to a click, so there
	 * is nothing to gain from holding up the page for it.
	 */
	wp_enqueue_script(
		'wow-back-to-top',
		WOW_BACK_TO_TOP_URL . 'assets/back-to-top.js',
		array(),
		(string) filemtime( WOW_BACK_TO_TOP_DIR . 'assets/back-to-top.js' ),
		array(
			'strategy'  => 'defer',
			'in_footer' => true,
		)
	);
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue' );

/**
 * Prints the button at the end of the page.
 *
 * A <button>, not a link to #top: this scrolls the page the visitor is
 * already on, it does not navigate anywhere, and a link would add a stray
 * entry to the browser history.
 *
 * The markup carries no visible state. The button is hidden in CSS and the
 * script reveals it, so with JavaScript off nothing floats over the page
 * that would not work when pressed.
 */
function render(): void {
	if ( ! is_enabled() ) {
		return;
	}

	$label = __( 'Back to top', 'wow-back-to-top' );

	printf(
		'<button type="button" class="wow-back-to-top" data-offset="%1$d" aria-label="%2$s" title="%2$s">%3$s</button>',
		esc_attr( (string) offset() ),
		esc_attr( $label ),
		// The chevron is decorative: the accessible name is on the button.
		'<svg class="wow-back-to-top__icon" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true" focusable="false"><path d="m3.5 10.25 4.5-4.5 4.5 4.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'
	);
}
add_action( 'wp_footer', __NAMESPACE__ . '\\render' );
