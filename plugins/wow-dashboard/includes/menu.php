<?php
/**
 * The collapsed admin menu, opened by hovering it.
 *
 * A folded menu is a column of unlabelled icons. WordPress answers that with
 * fly-out submenus, which tell you where a section leads but never what the
 * section itself is called — the name only comes back by unfolding the menu
 * for good and giving up the width again.
 *
 * So the menu opens while the pointer is on it and closes the moment it
 * leaves. It opens over the page rather than beside it: the content keeps the
 * width it was laid out with, and nothing reflows for a glance.
 *
 * Two asymmetries make it feel right rather than twitchy. Opening waits, so
 * crossing the strip on the way somewhere else does nothing; closing does
 * not, because once the pointer has left, the menu is in the way.
 *
 * @package WOW\Dashboard
 */

namespace WOW\Dashboard\Menu;

defined( 'ABSPATH' ) || exit;

/** How long the pointer must rest on the menu, in milliseconds. */
const WAIT = 280;

/**
 * Whether to do any of this.
 *
 * @return bool true when the hover-to-open menu should load.
 */
function wanted(): bool {
	/**
	 * Filters whether the collapsed admin menu opens on hover.
	 *
	 * @param bool $wanted Whether to load the behaviour.
	 */
	return (bool) apply_filters( 'wow_dashboard_hover_menu', true );
}

/**
 * Loads the style and the few lines of script behind it.
 *
 * Loaded on every admin page, because the menu is on every admin page. The
 * two files together are under three kilobytes, and both are inert until the
 * menu is actually folded.
 */
function assets(): void {
	if ( ! wanted() ) {
		return;
	}

	$css = WOW_DASHBOARD_DIR . 'assets/menu.css';
	$js  = WOW_DASHBOARD_DIR . 'assets/menu.js';

	wp_enqueue_style( 'wow-dashboard-menu', WOW_DASHBOARD_URL . 'assets/menu.css', array(), (string) filemtime( $css ) );
	wp_enqueue_script( 'wow-dashboard-menu', WOW_DASHBOARD_URL . 'assets/menu.js', array(), (string) filemtime( $js ), true );

	/*
	 * The delay is the one number worth changing from a theme, and it belongs
	 * to both halves: the script waits it out, the stylesheet has nothing to
	 * do with it. Passed as data rather than written into the file so the
	 * file stays cacheable.
	 */
	wp_add_inline_script(
		'wow-dashboard-menu',
		sprintf( 'window.wowDashboardMenuWait = %d;', wait() ),
		'before'
	);
}
add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\assets' );

/**
 * How long the pointer must rest on the menu before it opens.
 *
 * @return int Milliseconds.
 */
function wait(): int {
	/**
	 * Filters the pause before the collapsed menu opens.
	 *
	 * @param int $milliseconds Time the pointer must rest on the menu.
	 */
	return max( 0, (int) apply_filters( 'wow_dashboard_hover_wait', WAIT ) );
}
