<?php
/**
 * What an album tells a search engine and a link preview about itself.
 *
 * A gallery has almost no words in it, so the little it does have has to
 * count. The album's description goes out as the meta description and in the
 * Open Graph tags, and the cover becomes the picture a link to the album
 * shows when it is shared.
 *
 * WordPress writes the <title> by itself and needs no help with it. Nothing
 * here is written for an album without a description: a made-up summary is
 * worse than none, and an empty tag is worse still.
 *
 * @package WOW\Albums
 */

namespace WOW\Albums\Seo;

use const WOW\Albums\PostType\POST_TYPE;

defined( 'ABSPATH' ) || exit;

/**
 * How long a meta description may be before search engines cut it.
 *
 * Not a rule anyone publishes, and the real limit is in pixels rather than
 * characters. Around this length is where the tail starts disappearing.
 */
const DESCRIPTION_LENGTH = 160;

/**
 * Whether this plugin should write the tags at all.
 *
 * A proper SEO plugin writes its own, and two meta descriptions on a page
 * are worse than one. The named constants are how those plugins announce
 * themselves.
 */
function should_output(): bool {
	$taken = defined( 'WPSEO_VERSION' )
		|| defined( 'RANK_MATH_VERSION' )
		|| defined( 'AIOSEO_VERSION' )
		|| defined( 'SEOPRESS_VERSION' );

	/**
	 * Turns the album meta tags off, or back on.
	 *
	 * @param bool $output Whether to write the tags.
	 */
	return (bool) apply_filters( 'wow_albums_meta_tags', ! $taken );
}

/**
 * The album's description, cut to a length that survives the search results.
 *
 * The stored excerpt and not get_the_excerpt(): the generated one would be
 * assembled out of the gallery blocks and say nothing worth reading.
 *
 * @param int $post_id Album ID.
 * @return string Description, empty when the album has none.
 */
function description( int $post_id ): string {
	$text = strip_shortcodes( (string) get_post_field( 'post_excerpt', $post_id ) );

	/*
	 * wp_html_excerpt() does nearly all of this already: strips the tags,
	 * collapses the blank line between two paragraphs into a space, cuts to
	 * length, and repairs an HTML entity sliced in half by the cut — the last
	 * of which hand-written truncation always forgets. What it does not do is
	 * stop on a word.
	 */
	$full  = trim( wp_strip_all_tags( $text, true ) );
	$short = trim( wp_html_excerpt( $text, DESCRIPTION_LENGTH ) );

	if ( '' === $short || $short === $full ) {
		return $short;
	}

	$space = mb_strrpos( $short, ' ' );

	if ( false !== $space && $space > DESCRIPTION_LENGTH / 2 ) {
		$short = mb_substr( $short, 0, $space );
	}

	return rtrim( $short, " \t\n\r\0\x0B,;:" ) . '…';
}

/**
 * Prints one meta tag.
 *
 * @param string $attribute name or property.
 * @param string $key       Tag name.
 * @param string $value     Tag value; nothing is printed for an empty one.
 */
function tag( string $attribute, string $key, string $value ): void {
	if ( '' === $value ) {
		return;
	}

	printf(
		"<meta %s=\"%s\" content=\"%s\" />\n",
		esc_attr( $attribute ),
		esc_attr( $key ),
		esc_attr( $value )
	);
}

/**
 * Writes the tags into the head of an album page.
 */
function head(): void {
	if ( ! is_singular( POST_TYPE ) || ! should_output() ) {
		return;
	}

	$post_id = (int) get_queried_object_id();

	if ( $post_id <= 0 ) {
		return;
	}

	$description = description( $post_id );
	$title       = (string) get_the_title( $post_id );
	$url         = (string) get_permalink( $post_id );

	tag( 'name', 'description', $description );

	tag( 'property', 'og:type', 'article' );
	tag( 'property', 'og:title', $title );
	tag( 'property', 'og:description', $description );
	tag( 'property', 'og:url', $url );
	tag( 'property', 'og:site_name', (string) get_bloginfo( 'name' ) );
	tag( 'property', 'og:locale', (string) get_locale() );

	$cover = (int) get_post_thumbnail_id( $post_id );
	/*
	 * A wide crop rather than one of the core sizes: a link preview is shown
	 * in a strip about twice as wide as it is tall, and this one is already
	 * close to it, so the networks crop less off the picture. WordPress falls
	 * back to the full image when an album cover is too small to have it.
	 */
	$image = $cover > 0 ? wp_get_attachment_image_src( $cover, 'wow-col-full-2x' ) : false;

	if ( is_array( $image ) ) {
		tag( 'property', 'og:image', (string) $image[0] );
		tag( 'property', 'og:image:width', (string) $image[1] );
		tag( 'property', 'og:image:height', (string) $image[2] );
		tag( 'property', 'og:image:alt', (string) get_post_meta( $cover, '_wp_attachment_image_alt', true ) );
	}

	// summary_large_image needs a picture; without one the big card is empty.
	tag( 'name', 'twitter:card', is_array( $image ) ? 'summary_large_image' : 'summary' );
}
add_action( 'wp_head', __NAMESPACE__ . '\\head', 1 );
