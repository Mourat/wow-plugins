<?php
/**
 * Registering the block.
 *
 * There is no JavaScript here, and none in the plugin at all. WordPress 7.0
 * added the autoRegister flag: a block that declares it and has a render file
 * is registered in the editor by core itself, with a server-rendered preview
 * that core wraps in useDisabled() so the previewed form cannot be typed into
 * or submitted from the canvas. That is exactly the editor experience this
 * block wants, so writing it again by hand would be work spent to arrive at
 * the same place.
 *
 * @package WOW\ContactForm
 */

namespace WOW\ContactForm\Block;

defined( 'ABSPATH' ) || exit;

/** Handle of the front-end stylesheet. */
const STYLE = 'wow-contact-form';

/**
 * Registers the stylesheet and the block.
 *
 * The stylesheet is registered here by hand and named in block.json as a
 * handle rather than as a file path. Left as a path, core would version it
 * from the static string in block.json — filemtime only enters into it when
 * SCRIPT_DEBUG is on — and the version would have to be remembered and bumped
 * by hand after every edit. A handle registered here gets filemtime always.
 *
 * Priority 20, so the translations loaded on init at 5 are in place: the
 * block title and description are translated out of them as it registers.
 */
function register(): void {
	$style = WOW_CONTACT_FORM_DIR . 'assets/contact-form.css';

	wp_register_style(
		STYLE,
		WOW_CONTACT_FORM_URL . 'assets/contact-form.css',
		array(),
		(string) filemtime( $style )
	);

	register_block_type( WOW_CONTACT_FORM_DIR . 'blocks/contact-form' );
}
add_action( 'init', __NAMESPACE__ . '\\register', 20 );

/**
 * Makes sure the inserter category the block asks for exists.
 *
 * The category belongs to wow-blocks, which is where the rest of the WOW
 * blocks live and where this one belongs beside them. But a block naming a
 * category nobody registered lands in "Uncategorized", so rather than depend
 * on another plugin being active, the category is added here too when it is
 * missing.
 *
 * @param array<int,array<string,mixed>> $categories Block categories.
 * @return array<int,array<string,mixed>>
 */
function block_category( array $categories ): array {
	foreach ( $categories as $category ) {
		if ( 'wow' === ( $category['slug'] ?? '' ) ) {
			return $categories;
		}
	}

	array_unshift(
		$categories,
		array(
			'slug'  => 'wow',
			'title' => __( 'WOW', 'wow-contact-form' ),
			'icon'  => null,
		)
	);

	return $categories;
}
add_filter( 'block_categories_all', __NAMESPACE__ . '\\block_category', 20 );

/**
 * A number that differs for every copy of the block on the page.
 *
 * Only ever needed for element ids. A label reaches its field through for and
 * id, so two forms on one page with the same ids would leave the second one's
 * labels pointing at the first one's fields.
 *
 * @return int 1 for the first copy rendered on this request, then 2, 3, …
 */
function next_instance(): int {
	static $instance = 0;

	return ++$instance;
}
