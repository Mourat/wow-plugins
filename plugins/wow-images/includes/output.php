<?php
/**
 * Offering the copies to the browser.
 *
 * Every picture goes out inside a <picture>, with one <source> per format
 * ahead of the original <img>. The browser takes the first source whose type
 * it understands and ignores the rest; one that understands neither never
 * sees them and loads the <img> exactly as before. Nothing is negotiated on
 * the server, no rewrite rule is needed, and a cache in front of the site
 * stores one copy of the page rather than one per browser.
 *
 * The <img> itself is left untouched — its class, its sizes, its alt text,
 * its loading and fetchpriority, all of it decided elsewhere and all of it
 * still applying. This only wraps.
 *
 * What is offered comes from what each attachment remembers, not from what
 * this server can encode today. A site moved to a host with no AVIF encoder
 * goes on serving the AVIF files it already has, which is the whole point of
 * being able to make them somewhere else.
 *
 * @package WOW\Images
 */

namespace WOW\Images\Output;

use WOW\Images\Formats;
use WOW\Images\Options;
use WOW\Images\Record;

defined( 'ABSPATH' ) || exit;

/**
 * The formats to offer, best first.
 *
 * Unlike the list the converter works from, this one does not ask whether the
 * server can write the format.
 *
 * @return string[]
 */
function offered(): array {
	$options = Options\all_options();

	return array_values(
		array_filter(
			Formats\all(),
			static fn ( string $format ): bool => ! empty( $options['formats'][ $format ] )
		)
	);
}

/**
 * What an attachment has, keyed by the file name a URL would end in.
 *
 * @param int $id Attachment ID.
 * @return array<string,array<string,string>> File name => format => copy name.
 */
function copies( int $id ): array {
	$record = Record\read( (array) wp_get_attachment_metadata( $id ) );
	$map    = array();

	foreach ( $record['files'] as $name => $entry ) {
		foreach ( $entry as $format => $copy ) {
			if ( is_array( $copy ) && ! empty( $copy['file'] ) ) {
				$map[ $name ][ $format ] = (string) $copy['file'];
			}
		}
	}

	return $map;
}

/**
 * The address of one picture's copy, or null when it has none.
 *
 * The URL is rewritten by its own tail rather than rebuilt from the copy's
 * name, so that whatever encoding the address arrived in survives: an accent
 * in a file name may be a percent escape here and a letter in the metadata,
 * and the two must not be swapped for one another. The tail is worked out by
 * comparing the names, so a file converted under one naming setting keeps
 * working after the setting changes.
 *
 * @param string                             $url    Address of the original.
 * @param array<string,array<string,string>> $map    What the attachment has.
 * @param string                             $format Format slug.
 */
function translate_url( string $url, array $map, string $format ): ?string {
	$name = rawurldecode( wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
	$copy = $map[ $name ][ $format ] ?? null;

	if ( null === $copy ) {
		return null;
	}

	// Appended: the copy is the original's name with one more extension.
	if ( $copy === $name . '.' . $format ) {
		return $url . '.' . $format;
	}

	// Replaced: the last extension went, and this one came.
	$replaced = (string) preg_replace( '/\.[^.\/?]+$/', '.' . $format, $url );

	return $replaced === $url ? null : $replaced;
}

/**
 * The same srcset, pointing at the copies.
 *
 * All of it or none of it: a set with one address left as it was would hand
 * the browser a JPEG while telling it the source is AVIF, and the browser
 * would believe the label. WordPress strips commas out of uploaded file
 * names, so splitting on them is safe.
 *
 * @param string                             $srcset The original srcset.
 * @param array<string,array<string,string>> $map    What the attachment has.
 * @param string                             $format Format slug.
 */
function translate_srcset( string $srcset, array $map, string $format ): ?string {
	$translated = array();

	foreach ( explode( ',', $srcset ) as $candidate ) {
		$candidate = trim( $candidate );

		if ( '' === $candidate ) {
			continue;
		}

		$parts = preg_split( '/\s+/', $candidate, 2 );
		$url   = translate_url( (string) $parts[0], $map, $format );

		if ( null === $url ) {
			return null;
		}

		$translated[] = isset( $parts[1] ) ? $url . ' ' . $parts[1] : $url;
	}

	return empty( $translated ) ? null : implode( ', ', $translated );
}

/**
 * Wraps an <img> in a <picture> that also offers the copies.
 *
 * @param string $html An <img> tag, as WordPress built it.
 * @param int    $id   Attachment ID, 0 when it is not known.
 * @return string The tag, wrapped when there was anything to offer.
 */
function picture( string $html, int $id ): string {
	if ( $id <= 0 || ! Options\get( 'output' ) || false !== strpos( $html, '<picture' ) ) {
		return $html;
	}

	$formats = offered();

	if ( empty( $formats ) ) {
		return $html;
	}

	$map = copies( $id );

	if ( empty( $map ) ) {
		return $html;
	}

	// The tag processor rather than a regular expression: it knows where an
	// attribute ends, including the ones holding a quote of the other kind.
	$tags = new \WP_HTML_Tag_Processor( $html );

	if ( ! $tags->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
		return $html;
	}

	$src    = (string) $tags->get_attribute( 'src' );
	$srcset = (string) $tags->get_attribute( 'srcset' );
	$sizes  = (string) $tags->get_attribute( 'sizes' );
	$from   = '' !== $srcset ? $srcset : $src;

	if ( '' === $from ) {
		return $html;
	}

	$sources = '';

	foreach ( $formats as $format ) {
		$translated = translate_srcset( $from, $map, $format );

		if ( null === $translated ) {
			continue;
		}

		$sources .= sprintf(
			'<source type="%s" srcset="%s"%s />',
			esc_attr( Formats\mime( $format ) ),
			esc_attr( $translated ),
			// sizes describes how wide the picture will be drawn, which only
			// means anything to a set measured in widths.
			'' !== $srcset && '' !== $sizes ? sprintf( ' sizes="%s"', esc_attr( $sizes ) ) : ''
		);
	}

	if ( '' === $sources ) {
		return $html;
	}

	return '<picture class="wow-picture">' . $sources . $html . '</picture>';
}

/**
 * Wraps a picture rendered from an attachment.
 *
 * @param string     $html Image tag.
 * @param int|string $id   Attachment ID.
 * @return string
 */
function on_attachment_image( $html, $id ): string {
	return picture( (string) $html, (int) $id );
}

/**
 * Wraps a picture found in the text of a post.
 *
 * WordPress hands over the attachment it managed to identify from the tag's
 * class, and 0 when the picture came from somewhere else entirely. Without an
 * attachment there is nothing to look the copies up by, so it goes out as it
 * is.
 *
 * @param string     $html    Image tag.
 * @param string     $context Where the tag was found.
 * @param int|string $id      Attachment ID, 0 when unknown.
 * @return string
 */
function on_content_image( $html, $context, $id ): string {
	return picture( (string) $html, (int) $id );
}

/**
 * The one rule a wrapped picture needs.
 *
 * A <picture> is an inline box, and the pictures on this site are stretched
 * to fill their cell with height:100%. A percentage height against an inline
 * box of no height is nothing at all, so without this every gallery would
 * collapse the day the plugin was switched on. display:contents takes the
 * box away again and leaves the <img> where it was in the layout.
 *
 * It is printed inline rather than fetched: one rule is not worth a request.
 */
function styles(): void {
	wp_register_style( 'wow-images', false, array(), WOW_IMAGES_VERSION );
	wp_enqueue_style( 'wow-images' );
	wp_add_inline_style( 'wow-images', '.wow-picture{display:contents}' );
}

/**
 * Starts wrapping, on the front end only.
 *
 * Not in the admin: the media library draws its own thumbnails through the
 * same function, and its JavaScript expects to find an <img> where it left
 * one. Not in a feed either, where a reader may show the markup rather than
 * render it.
 */
function listen(): void {
	if ( is_admin() || is_feed() || ! Options\get( 'output' ) ) {
		return;
	}

	add_filter( 'wp_get_attachment_image', __NAMESPACE__ . '\\on_attachment_image', 20, 2 );
	add_filter( 'wp_content_img_tag', __NAMESPACE__ . '\\on_content_image', 20, 3 );
	add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\styles' );
}
add_action( 'template_redirect', __NAMESPACE__ . '\\listen' );
