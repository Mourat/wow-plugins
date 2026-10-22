<?php
/**
 * Renders the narrow divider strip.
 *
 * @package WOW\Blocks
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Saved markup; empty for a dynamic block.
 * @var WP_Block $block      Block instance.
 */

use function WOW\Blocks\Render\normalize_cell;
use function WOW\Blocks\Render\object_position;
use function WOW\Blocks\Render\render_cell;

use const WOW\Blocks\Config\DEFAULT_BAND_IMAGE_WIDTH;
use const WOW\Blocks\ImageSizes\BAND_SOURCE_SIZE;

defined( 'ABSPATH' ) || exit;

$wow_position = in_array( $attributes['imagePosition'] ?? 'left', array( 'left', 'right', 'none' ), true )
	? $attributes['imagePosition']
	: 'left';

$wow_width = isset( $attributes['imageWidth'] ) && is_numeric( $attributes['imageWidth'] )
	? max( 1, (int) $attributes['imageWidth'] )
	: DEFAULT_BAND_IMAGE_WIDTH;

$wow_background = (string) sanitize_hex_color( (string) ( $attributes['backgroundColor'] ?? '' ) );

$wow_image = normalize_cell(
	array_merge(
		array( 'kind' => 'image' ),
		is_array( $attributes['image'] ?? null ) ? $attributes['image'] : array()
	)
);

$wow_media = '';

if ( 'none' !== $wow_position ) {
	$wow_media = render_cell(
		$wow_image,
		BAND_SOURCE_SIZE,
		sprintf( '%dpx', $wow_width )
	);
}

// An empty strip, with neither fill nor image, is not wanted on the front end.
if ( '' === $wow_media && '' === $wow_background ) {
	return;
}

$wow_has_image = '' !== $wow_media;

// Framing scale: at 1x the image fits the strip width, above that it zooms
// towards the focal point. The upper bound matches the editor's slider.
$wow_scale = isset( $attributes['imageScale'] ) && is_numeric( $attributes['imageScale'] )
	? min( 4.0, max( 1.0, (float) $attributes['imageScale'] ) )
	: 1.0;

$wow_media_html = $wow_has_image
	? sprintf(
		'<div class="wow-cell wow-band__media" style="--wow-band-scale:%1$s;--wow-band-origin:%2$s;--wow-band-x:%3$s;--wow-band-y:%4$s">%5$s</div>',
		esc_attr( (string) round( $wow_scale, 2 ) ),
		esc_attr( object_position( $wow_image['focalPoint'] ) ),
		esc_attr( (string) round( $wow_image['focalPoint']['x'], 4 ) ),
		esc_attr( (string) round( $wow_image['focalPoint']['y'], 4 ) ),
		$wow_media
	)
	: '';

$wow_fill_html = sprintf(
	'<div class="wow-band__fill"%s></div>',
	'' !== $wow_background ? sprintf( ' style="background-color:%s"', esc_attr( $wow_background ) ) : ''
);

$wow_inner = 'right' === $wow_position
	? $wow_fill_html . $wow_media_html
	: $wow_media_html . $wow_fill_html;

$wow_wrapper = get_block_wrapper_attributes(
	array(
		'class' => 'wow-band ' . ( $wow_has_image ? 'wow-band--image-' . $wow_position : 'wow-band--no-image' ),
		'style' => sprintf( '--wow-band-image-width:%dpx', $wow_width ),
	)
);
?>
<div <?php echo $wow_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="wow-band__inner">
		<?php echo $wow_inner; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>
</div>
