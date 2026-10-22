<?php
/**
 * The map.
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block default content.
 * @var WP_Block $block      Block instance.
 *
 * @package WOW\Map
 */

use WOW\Map\Block;
use WOW\Map\Storage;
use WOW\Map\Tiles;

defined( 'ABSPATH' ) || exit;

$wow_map   = Block\settings( $attributes );
$wow_error = null;

/*
 * The one moment the plugin may go and fetch tiles: somebody who can edit is
 * looking at it. The editor's preview comes through the block-renderer route,
 * which already demands that permission, so placing the block is what draws
 * the picture. A visitor never triggers this — by the time a page is read,
 * the map is either a file on this server or it is not shown.
 */
if ( ! Storage\exists( $wow_map['lat'], $wow_map['lon'], $wow_map['zoom'], $wow_map['width'], $wow_map['height'] ) ) {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return '';
	}

	$wow_error = Tiles\ensure( $wow_map['lat'], $wow_map['lon'], $wow_map['zoom'], $wow_map['width'], $wow_map['height'] );
}

if ( is_wp_error( $wow_error ) ) {
	/*
	 * Shown to editors only, and deliberately not to visitors: a customer who
	 * came for an address is not helped by a grey box apologising for a map.
	 */
	printf(
		'<p %1$s>%2$s</p>',
		wp_kses_data( get_block_wrapper_attributes( array( 'class' => 'wow-map wow-map--failed' ) ) ),
		esc_html(
			sprintf(
				/* translators: %s: reason the map could not be drawn. */
				__( 'This map could not be drawn: %s', 'wow-map' ),
				$wow_error->get_error_message()
			)
		)
	);

	return;
}

$wow_source = Storage\file_url( $wow_map['lat'], $wow_map['lon'], $wow_map['zoom'], $wow_map['width'], $wow_map['height'] );
?>
<figure <?php echo wp_kses_data( get_block_wrapper_attributes( array( 'class' => 'wow-map' ) ) ); ?>>
	<img
		class="wow-map__image"
		src="<?php echo esc_url( $wow_source ); ?>"
		width="<?php echo esc_attr( (string) $wow_map['width'] ); ?>"
		height="<?php echo esc_attr( (string) $wow_map['height'] ); ?>"
		alt="<?php echo esc_attr( $wow_map['alt'] ); ?>"
		loading="lazy"
		decoding="async"
	>
	<?php
	/*
	 * Not decoration and not optional. The map data is licensed under the
	 * ODbL, which requires the credit to be shown wherever the map is; this
	 * caption is the only link on the block, the picture itself being just a
	 * picture.
	 */
	?>
	<figcaption class="wow-map__credit">
		<?php
		printf(
			/* translators: %s: link to the OpenStreetMap copyright page, with "OpenStreetMap" as the text. */
			esc_html__( '© %s contributors', 'wow-map' ),
			'<a href="https://www.openstreetmap.org/copyright" rel="nofollow noopener">OpenStreetMap</a>'
		);
		?>
	</figcaption>
</figure>
