<?php
/**
 * The formats this plugin makes, and whether this server can make them.
 *
 * Two of them, in the order a browser should prefer them. AVIF is the
 * smaller and the younger; WebP is the one every browser in use has
 * understood for years. A visitor gets whichever is first on the list that
 * their browser admits to supporting, and the original JPEG if neither.
 *
 * Nothing here decides what a visitor is sent — the browser does that, from
 * the <picture> element in output.php. All this knows is how to write a file.
 *
 * @package WOW\Images
 */

namespace WOW\Images\Formats;

defined( 'ABSPATH' ) || exit;

/**
 * The formats, best first.
 *
 * The quality figures are not comparable between formats: they are separate
 * encoders with separate scales, and the numbers here come from measuring
 * this site's own photographs rather than from any general rule. AVIF at 55
 * lands around 40-55% of the JPEG it was made from; WebP at 82 around 75-85%.
 * Both are settings, and both can be moved on the settings screen.
 *
 * @var array<string,array{mime:string,label:string,quality:int}>
 */
const FORMATS = array(
	'avif' => array(
		'mime'    => 'image/avif',
		'label'   => 'AVIF',
		'quality' => 55,
	),
	'webp' => array(
		'mime'    => 'image/webp',
		'label'   => 'WebP',
		'quality' => 82,
	),
);

/**
 * What a picture has to be before there is any point reading it.
 *
 * WebP is on the list as well as off it: it is something this plugin makes,
 * and it is also something an AVIF can be made from — which is the ordinary
 * case once pictures are stored as WebP. A format is never made from itself;
 * that is decided per file, where both ends of the question are known.
 *
 * SVG is absent because it is text and has no pixels to re-encode.
 *
 * @var string[]
 */
const SOURCES = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );

/**
 * What a picture has to be before it is worth storing in another format.
 *
 * Narrower than SOURCES: converting a WebP to a WebP achieves nothing, and
 * an AVIF kept as the only copy would leave the few browsers that cannot
 * read one with nothing at all.
 *
 * @var string[]
 */
const REPLACEABLE = array( 'image/jpeg', 'image/png', 'image/gif' );

/**
 * The slugs of every format, best first.
 *
 * @return string[]
 */
function all(): array {
	return array_keys( FORMATS );
}

/**
 * Whether a slug names a format this plugin knows.
 */
function known( string $format ): bool {
	return isset( FORMATS[ $format ] );
}

/**
 * The media type a format is served as.
 */
function mime( string $format ): string {
	return FORMATS[ $format ]['mime'] ?? '';
}

/**
 * The format's name as a person writes it.
 */
function label( string $format ): string {
	return FORMATS[ $format ]['label'] ?? strtoupper( $format );
}

/**
 * The quality to encode at when the settings say nothing.
 */
function default_quality( string $format ): int {
	return FORMATS[ $format ]['quality'] ?? 82;
}

/**
 * Whether this server can write the format at all.
 *
 * Asks WordPress rather than looking for Imagick or GD directly, because the
 * answer is about a particular build: an ImageMagick without the libheif
 * delegate, or a PHP whose GD was compiled without libavif, has the extension
 * loaded and still cannot write a single AVIF byte. supports_mime_type() goes
 * and asks the library, which is the only answer worth having.
 *
 * The result is remembered for the request. The question costs an Imagick
 * instantiation, and the bulk screen asks it once per file.
 */
function supported( string $format ): bool {
	static $answers = array();

	if ( ! known( $format ) ) {
		return false;
	}

	if ( ! isset( $answers[ $format ] ) ) {
		$answers[ $format ] = wp_image_editor_supports( array( 'mime_type' => mime( $format ) ) );
	}

	return $answers[ $format ];
}

/**
 * Whether a picture of this media type is worth reading.
 */
function convertible( string $mime ): bool {
	return in_array( $mime, SOURCES, true );
}

/**
 * Whether a picture of this media type is worth storing as something else.
 */
function replaceable( string $mime ): bool {
	return in_array( $mime, REPLACEABLE, true );
}

/**
 * Whether a format is safe to keep a picture in and nothing else.
 *
 * WebP is: every browser in use has read it since 2020, so a library kept in
 * it is a library everybody can see. AVIF is not, and the difference matters
 * because this is the only copy — a few browsers would be shown nothing at
 * all rather than something slightly heavier. AVIF is worth having beside the
 * picture, never instead of it.
 */
function storable( string $format ): bool {
	return 'webp' === $format;
}

/**
 * The format slug a media type belongs to, or an empty string.
 *
 * Lets a file say which of this plugin's formats it already is, so that
 * nothing tries to make it into itself.
 */
function of_mime( string $mime ): string {
	foreach ( FORMATS as $format => $about ) {
		if ( $about['mime'] === $mime ) {
			return $format;
		}
	}

	return '';
}

/**
 * The name of the image library doing the work, for the settings screen.
 *
 * There is no supported way to ask WordPress this, so it is worked out the
 * same way WordPress does: whichever implementation says it can handle a
 * JPEG first is the one that will be handed every file.
 */
function engine(): string {
	$editor = _wp_image_editor_choose( array( 'mime_type' => 'image/jpeg' ) );

	if ( 'WP_Image_Editor_Imagick' === $editor ) {
		$version = class_exists( 'Imagick' ) ? ( \Imagick::getVersion()['versionString'] ?? '' ) : '';

		// "ImageMagick 7.1.1-43 Q16 x86_64 22550 https://imagemagick.org" is
		// more than anybody needs; the first two words are the answer.
		return trim( implode( ' ', array_slice( explode( ' ', (string) $version ), 0, 2 ) ) ) ?: 'ImageMagick';
	}

	if ( 'WP_Image_Editor_GD' === $editor ) {
		return 'GD';
	}

	return '';
}
