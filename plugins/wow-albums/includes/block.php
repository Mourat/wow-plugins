<?php
/**
 * The blocks this plugin registers.
 *
 * One fills a navigation submenu with the albums; the other prints an
 * album's description above its gallery.
 *
 * It exists because core has nothing that lists a custom post type in a
 * menu: core/page-list is pages only, by way of get_pages(). Writing the
 * seventeen links out by hand would work today and be wrong by the next
 * album, so the list is generated instead and can never fall behind.
 *
 * No JavaScript. WordPress 7.0's autoRegister support has core register the
 * block in the editor itself and preview it by rendering it on the server,
 * which for a block with no settings at all is the whole editor interface.
 *
 * @package WOW\Albums
 */

namespace WOW\Albums\Block;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the block.
 *
 * Priority 20, so the translations loaded on init at 5 are already there:
 * the block title and description are translated as it registers.
 *
 * There is no stylesheet. The markup is core's own navigation item markup,
 * so the navigation block's styles apply to it as they do to a hand-placed
 * link — which is the point of matching those class names exactly.
 */
function register(): void {
	register_block_type( WOW_ALBUMS_DIR . 'blocks/album-links' );
	register_block_type( WOW_ALBUMS_DIR . 'blocks/album-description' );
}
add_action( 'init', __NAMESPACE__ . '\\register', 20 );
