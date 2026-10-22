<?php
/**
 * The albums as menu items — in one of two shapes.
 *
 * Plain: bare <li> elements and no wrapper, meant to sit inside a navigation
 * submenu that supplies the <ul>. This is the desktop shape; the theme turns
 * that submenu into a wide panel of tiles.
 *
 * Disclosure: one <li> holding a <details> with the albums inside, collapsed
 * until tapped. This is the mobile shape, and it exists for a reason worth
 * knowing: inside the navigation overlay core reports every submenu as
 * expanded — its isSubmenuOpen reads the overlay's own open state — so a
 * core submenu there can never be collapsed, whatever the styles say.
 * <details> is collapsible by itself, needs no script, and tells a screen
 * reader the truth.
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block default content.
 * @var WP_Block $block      Block instance.
 *
 * @package WOW\Albums
 */

use const WOW\Albums\PostType\POST_TYPE;

defined( 'ABSPATH' ) || exit;

$wow_disclosure = ! empty( $attributes['disclosure'] );
$wow_label      = sanitize_text_field( (string) ( $attributes['label'] ?? '' ) );

if ( '' === $wow_label ) {
	$wow_label = _x( 'Albums', 'disclosure label', 'wow-albums' );
}

/*
 * orderby is spelled out because get_posts() imposes a default of date, so
 * the manual drag-and-drop order this plugin exists for has to be asked for
 * by name. Same order as the front-page grid and the sidebar list.
 */
$wow_albums = get_posts(
	array(
		'post_type'        => POST_TYPE,
		'post_status'      => 'publish',
		'numberposts'      => -1,
		'orderby'          => array(
			'menu_order' => 'ASC',
			'date'       => 'DESC',
		),
		'suppress_filters' => false,
	)
);

if ( array() === $wow_albums ) {
	return '';
}

$wow_current = get_queried_object_id();
$wow_items   = '';

foreach ( $wow_albums as $wow_album ) {
	$wow_is_current = $wow_album->ID === $wow_current;
	$wow_cover      = '';

	/*
	 * Covers belong to the tiles of the desktop panel. The disclosure list is
	 * text: on a phone seventeen pictures are a wall, not a menu.
	 *
	 * An empty alt on purpose: the album's name is written right beside the
	 * picture, and a screen reader would otherwise read it twice.
	 */
	if ( ! $wow_disclosure ) {
		$wow_cover = get_the_post_thumbnail(
			$wow_album,
			'medium',
			array(
				'class'   => 'wow-album-link__cover',
				'alt'     => '',
				'loading' => 'lazy',
			)
		);
	}

	$wow_items .= sprintf(
		'<li class="wp-block-navigation-item wp-block-navigation-link wow-album-link%1$s">' .
			'<a class="wp-block-navigation-item__content wow-album-link__content" href="%2$s"%3$s>' .
				'%4$s<span class="wp-block-navigation-item__label">%5$s</span>' .
			'</a>' .
		'</li>',
		$wow_is_current ? ' current-menu-item' : '',
		esc_url( (string) get_permalink( $wow_album ) ),
		// The same pair core sets on the link to the page being viewed.
		$wow_is_current ? ' aria-current="page"' : '',
		// Escaped by get_the_post_thumbnail(); empty when an album has no cover.
		$wow_cover,
		esc_html( get_the_title( $wow_album ) )
	);
}

if ( ! $wow_disclosure ) {
	echo $wow_items; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every piece was escaped as it was built.

	return;
}

printf(
	'<li class="wp-block-navigation-item wow-album-links wow-album-links--disclosure">' .
		'<details class="wow-album-links__details">' .
			'<summary class="wp-block-navigation-item__content wow-album-links__summary">' .
				'<span class="wp-block-navigation-item__label">%1$s</span>' .
			'</summary>' .
			'<ul class="wow-album-links__list">%2$s</ul>' .
		'</details>' .
	'</li>',
	esc_html( $wow_label ),
	$wow_items // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every piece was escaped as it was built.
);
