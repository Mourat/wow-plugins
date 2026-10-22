<?php
/**
 * Registering the block.
 *
 * No JavaScript, here or anywhere in the plugin. WordPress 7.0 added the
 * autoRegister flag: a block that declares it and has a render file is
 * registered in the editor by core itself, which also builds inspector
 * controls for the block's own string, number and boolean attributes. For a
 * block whose settings are five numbers and a caption, that is the whole
 * editor interface, written by nobody.
 *
 * @package WOW\Map
 */

namespace WOW\Map\Block;

use WOW\Map\Geo;

defined( 'ABSPATH' ) || exit;

/** Handle of the front-end stylesheet. */
const STYLE = 'wow-map';

/**
 * Registers the stylesheet and the block.
 *
 * The stylesheet is registered here and named in block.json as a handle
 * rather than a file path, so that its version comes from filemtime. Left as
 * a path, core versions it from the static string in block.json and the
 * browser holds on to a stale copy after every edit.
 *
 * Priority 20, so the translations loaded on init at 5 are in place: the
 * block title and description are translated as it registers.
 */
function register(): void {
	$style = WOW_MAP_DIR . 'assets/map.css';

	wp_register_style(
		STYLE,
		WOW_MAP_URL . 'assets/map.css',
		array(),
		(string) filemtime( $style )
	);

	register_block_type( WOW_MAP_DIR . 'blocks/map' );
}
add_action( 'init', __NAMESPACE__ . '\\register', 20 );

/**
 * Makes sure the inserter category the block asks for exists.
 *
 * The category belongs to wow-blocks, which is where this block belongs
 * beside the others. But a block naming a category nobody registered lands in
 * "Uncategorized", so rather than depend on another plugin being active, the
 * category is added here too when it is missing.
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
			'title' => __( 'WOW', 'wow-map' ),
			'icon'  => null,
		)
	);

	return $categories;
}
add_filter( 'block_categories_all', __NAMESPACE__ . '\\block_category', 20 );

/**
 * The block's attributes, checked and turned into numbers.
 *
 * Every value the rest of the plugin works with passes through here first, so
 * that nothing further on has to wonder whether a latitude is a string, a
 * nonsense number, or missing altogether.
 *
 * @param array<string,mixed> $attributes Raw block attributes.
 * @return array{lat:float,lon:float,zoom:float,width:int,height:int,alt:string}
 */
function settings( array $attributes ): array {
	return array(
		'lat'    => Geo\latitude( $attributes['latitude'] ?? 0 ),
		'lon'    => Geo\longitude( $attributes['longitude'] ?? 0 ),
		'zoom'   => Geo\zoom( $attributes['zoom'] ?? 17.5 ),
		'width'  => Geo\side( $attributes['width'] ?? 416 ),
		'height' => Geo\side( $attributes['height'] ?? 200 ),
		'alt'    => sanitize_text_field( (string) ( $attributes['alt'] ?? '' ) ),
	);
}
