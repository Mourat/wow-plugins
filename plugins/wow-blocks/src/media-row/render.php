<?php
/**
 * Renders an image row.
 *
 * @package WOW\Blocks
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Saved markup; empty for a dynamic block.
 * @var WP_Block $block      Block instance.
 */

use function WOW\Blocks\Config\column_widths;
use function WOW\Blocks\Config\layouts;
use function WOW\Blocks\Render\normalize_cell;
use function WOW\Blocks\Render\render_row_cell;

defined( 'ABSPATH' ) || exit;

$wow_layouts = layouts();
$wow_layout  = isset( $attributes['layout'] ) && isset( $wow_layouts[ $attributes['layout'] ] )
	? $attributes['layout']
	: 'three';

$wow_columns = $wow_layouts[ $wow_layout ]['columns'];
$wow_widths  = column_widths();
$wow_cells   = isset( $attributes['cells'] ) && is_array( $attributes['cells'] ) ? $attributes['cells'] : array();

$wow_rendered    = array();
$wow_has_content = false;

foreach ( $wow_columns as $wow_index => $wow_column ) {
	$wow_cell = normalize_cell( $wow_cells[ $wow_index ] ?? array() );
	$wow_body = render_row_cell( $wow_cell, $wow_column, $wow_widths[ $wow_column ] );

	if ( '' !== $wow_body ) {
		$wow_has_content = true;
	}

	$wow_rendered[] = sprintf(
		'<div class="wow-cell wow-cell--%s">%s</div>',
		esc_attr( $wow_column ),
		$wow_body
	);
}

// A row with no cell filled in is only in the way on the front end.
if ( ! $wow_has_content ) {
	return;
}

$wow_classes = array( 'wow-row', 'wow-row--' . $wow_layout );

if ( ! empty( $attributes['stackOnMobile'] ) ) {
	$wow_classes[] = 'wow-row--stack';
}

$wow_style = '';

if ( isset( $attributes['gap'] ) && is_numeric( $attributes['gap'] ) ) {
	$wow_style = sprintf( '--wow-gap:%dpx', (int) $attributes['gap'] );
}

$wow_wrapper = get_block_wrapper_attributes(
	array(
		'class' => implode( ' ', $wow_classes ),
		'style' => $wow_style,
	)
);
?>
<div <?php echo $wow_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="wow-row__grid">
		<?php echo implode( '', $wow_rendered ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>
</div>
