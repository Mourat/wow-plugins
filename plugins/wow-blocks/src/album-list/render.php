<?php
/**
 * Renders the floating album list.
 *
 * @package WOW\Blocks
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Saved markup; empty for a dynamic block.
 * @var WP_Block $block      Block instance.
 */

use function WOW\Blocks\Albums\post_type;

defined( 'ABSPATH' ) || exit;

$wow_sticky = isset( $attributes['stickyTop'] ) && is_numeric( $attributes['stickyTop'] )
	? max( 0, (int) $attributes['stickyTop'] )
	: 50;

/*
 * orderby is set explicitly: get_posts() always imposes its own default of
 * date, so the manual album order has to be asked for by name — the same
 * order the grid uses.
 */
$wow_albums = get_posts(
	array(
		'post_type'        => post_type(),
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
	return;
}

/*
 * The current album comes from the query rather than from the global $post:
 * the block sits in the template next to the content, and inside somebody
 * else's loop the global post would wander off.
 */
$wow_current = is_singular( post_type() ) ? (int) get_queried_object_id() : 0;

$wow_items = array();

foreach ( $wow_albums as $wow_album ) {
	$wow_is_current = $wow_album->ID === $wow_current;

	$wow_items[] = sprintf(
		'<li class="wow-album-list__item%1$s"><a class="wow-album-list__link" href="%2$s"%3$s>%4$s</a></li>',
		$wow_is_current ? ' is-current' : '',
		esc_url( (string) get_permalink( $wow_album ) ),
		$wow_is_current ? ' aria-current="page"' : '',
		esc_html( get_the_title( $wow_album ) )
	);
}

$wow_classes = 'wow-album-list';

if ( ! empty( $attributes['hideOnMobile'] ) ) {
	$wow_classes .= ' wow-album-list--desktop-only';
}

$wow_wrapper = get_block_wrapper_attributes(
	array(
		'class' => $wow_classes,
		'style' => sprintf( '--wow-album-list-top:%dpx', $wow_sticky ),
	)
);
?>
<nav <?php echo $wow_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> aria-label="<?php esc_attr_e( 'Albums', 'wow-blocks' ); ?>">
	<ul class="wow-album-list__items">
		<?php echo implode( '', $wow_items ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</ul>
</nav>
