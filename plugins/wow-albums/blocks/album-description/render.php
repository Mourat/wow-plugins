<?php
/**
 * The album's description.
 *
 * A gallery site has almost no words in it, which is a problem for anyone
 * arriving from a search engine and for the search engine itself. The text
 * belongs to the album rather than to the gallery, so it lives in the
 * excerpt — the field WordPress already keeps for exactly this — and is
 * printed above the pictures.
 *
 * Not core/post-excerpt, which would have done if it did less: that block
 * runs every excerpt through wp_trim_words() at a maximum of a hundred, and
 * wraps whatever survives in a single paragraph. A description of an object
 * and the work done on it runs longer than that and reads as more than one
 * paragraph. It also prints an empty paragraph for a post with no excerpt,
 * and seventeen albums are without one today.
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block default content.
 * @var WP_Block $block      Block instance.
 *
 * @package WOW\Albums
 */

defined( 'ABSPATH' ) || exit;

$wow_post_id = isset( $block->context['postId'] ) ? (int) $block->context['postId'] : (int) get_the_ID();

if ( $wow_post_id <= 0 ) {
	return;
}

$wow_text = (string) get_post_field( 'post_excerpt', $wow_post_id );

// Deliberately the stored excerpt and not get_the_excerpt(): the generated
// one would be built out of the gallery, and an album without a description
// is meant to print nothing rather than something made up.
if ( '' === trim( $wow_text ) ) {
	return;
}

printf(
	'<div %1$s>%2$s</div>',
	get_block_wrapper_attributes( array( 'class' => 'wow-album-description' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	wpautop( wp_kses_post( $wow_text ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
);
