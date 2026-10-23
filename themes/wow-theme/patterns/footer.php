<?php
/**
 * Title: Footer
 * Slug: wow-theme/footer
 * Categories: footer
 * Block Types: core/template-part/footer
 * Description: A single copyright line aligned with the site title in the header.
 *
 * @package wow-theme
 */

?>
<!-- wp:group {"align":"full","className":"wow-footer","textColor":"base","style":{"color":{"background":"var(--wp--custom--wow-accent)"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull wow-footer has-base-color has-text-color has-background" style="background-color:var(--wp--custom--wow-accent)">
	<!-- wp:group {"align":"wide","className":"wow-footer__bar","style":{"dimensions":{"minHeight":"104px"}},"layout":{"type":"default"}} -->
	<div class="wp-block-group alignwide wow-footer__bar" style="min-height:104px">
		<!-- wp:paragraph {"fontSize":"small"} -->
		<p class="has-small-font-size"><a href="<?php echo esc_url( __( 'https://wordpress.org/', 'wow-theme' ) ); ?>" rel="nofollow">Proudly powered by WordPress</a> | wow-arts.com <?php echo esc_html( wp_date( 'Y' ) ); ?></p>
		<!-- /wp:paragraph -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
