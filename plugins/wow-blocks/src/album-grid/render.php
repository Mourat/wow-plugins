<?php
/**
 * Renders the album grid.
 *
 * @package WOW\Blocks
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Saved markup; empty for a dynamic block.
 * @var WP_Block $block      Block instance.
 */

use function WOW\Blocks\Albums\image_count;
use function WOW\Blocks\Albums\post_type;

use const WOW\Blocks\Config\ALBUM_MAX_COLUMNS;
use const WOW\Blocks\Config\ALBUM_TILE_WIDTH;
use const WOW\Blocks\ImageSizes\ALBUM_TILE_SIZE;

defined( 'ABSPATH' ) || exit;

$wow_tile = isset( $attributes['tileWidth'] ) && is_numeric( $attributes['tileWidth'] )
	? max( 80, (int) $attributes['tileWidth'] )
	: ALBUM_TILE_WIDTH;

$wow_columns = isset( $attributes['maxColumns'] ) && is_numeric( $attributes['maxColumns'] )
	? max( 1, (int) $attributes['maxColumns'] )
	: ALBUM_MAX_COLUMNS;

$wow_badge = (string) sanitize_hex_color( (string) ( $attributes['badgeColor'] ?? '' ) );
$wow_limit = isset( $attributes['limit'] ) ? max( 0, (int) $attributes['limit'] ) : 0;

/*
 * orderby is set explicitly: get_posts() always imposes its own default of
 * date, so the manual album order has to be asked for by name.
 */
$wow_albums = get_posts(
	array(
		'post_type'        => post_type(),
		'post_status'      => 'publish',
		'numberposts'      => $wow_limit > 0 ? $wow_limit : -1,
		'orderby'          => array(
			'menu_order' => 'ASC',
			'date'       => 'DESC',
		),
		'suppress_filters' => false,
	)
);

$wow_cells = array();

if ( ! empty( $attributes['showLogo'] ) ) {
	$wow_logo_id = (int) get_theme_mod( 'custom_logo' );

	if ( $wow_logo_id > 0 ) {
		$wow_cells[] = sprintf(
			'<div class="wow-album-cell wow-album-logo">%s</div>',
			wp_get_attachment_image(
				$wow_logo_id,
				'medium',
				false,
				array( 'class' => 'wow-album-logo__image' )
			)
		);
	} else {
		// The logo slot is always reserved: without it, the whole grid would
		// shift by one tile the moment a logo appears.
		$wow_cells[] = sprintf(
			'<div class="wow-album-cell wow-album-logo wow-album-logo--empty"><span>%s</span></div>',
			esc_html__( 'Upload a site logo', 'wow-blocks' )
		);
	}
}

foreach ( $wow_albums as $wow_album ) {
	$wow_thumb = has_post_thumbnail( $wow_album )
		? get_the_post_thumbnail(
			$wow_album,
			ALBUM_TILE_SIZE,
			array(
				'class'    => 'wow-album-tile__image',
				'sizes'    => sprintf( '%dpx', $wow_tile ),
				'loading'  => 'lazy',
				'decoding' => 'async',
				'alt'      => '',
			)
		)
		: '<span class="wow-album-tile__image wow-album-tile__image--empty"></span>';

	$wow_cells[] = sprintf(
		'<a class="wow-album-cell wow-album-tile" href="%1$s">%2$s'
		. '<span class="wow-album-tile__count">%3$s</span>'
		. '<span class="wow-album-tile__title">%4$s</span></a>',
		esc_url( (string) get_permalink( $wow_album ) ),
		$wow_thumb,
		esc_html( (string) number_format_i18n( image_count( $wow_album->ID ) ) ),
		esc_html( get_the_title( $wow_album ) )
	);
}

if ( array() === $wow_cells ) {
	return;
}

$wow_style = sprintf( '--wow-album-tile:%dpx;--wow-album-columns:%d', $wow_tile, $wow_columns );

if ( '' !== $wow_badge ) {
	$wow_style .= sprintf( ';--wow-album-badge:%s', $wow_badge );
}

$wow_wrapper = get_block_wrapper_attributes(
	array(
		'class' => 'wow-album-grid',
		'style' => $wow_style,
	)
);
?>
<div <?php echo $wow_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php echo implode( '', $wow_cells ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</div>
