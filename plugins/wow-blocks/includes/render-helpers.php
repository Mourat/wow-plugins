<?php
/**
 * Shared cell rendering: an image or a solid colour fill.
 *
 * @package WOW\Blocks
 */

namespace WOW\Blocks\Render;

use function WOW\Blocks\Config\image_size_for_column;
use function WOW\Blocks\ImageSizes\belongs_to_source;

use const WOW\Blocks\ImageSizes\BAND_SOURCE_SIZE;

defined( 'ABSPATH' ) || exit;

/**
 * Brings a cell from the block attributes into a predictable shape.
 *
 * A person edits the block in the editor, so its shape cannot be trusted:
 * everything the renderer touches is normalised here.
 *
 * @param mixed $cell Raw value from the attributes.
 * @return array{kind:string,id:int,url:string,alt:string,color:string,focalPoint:array{x:float,y:float}}
 */
function normalize_cell( $cell ): array {
	$cell = is_array( $cell ) ? $cell : array();

	$focal = isset( $cell['focalPoint'] ) && is_array( $cell['focalPoint'] ) ? $cell['focalPoint'] : array();

	$kind = (string) ( $cell['kind'] ?? '' );

	return array(
		'kind'       => in_array( $kind, array( 'color', 'video' ), true ) ? $kind : 'image',
		'id'         => (int) ( $cell['id'] ?? 0 ),
		'url'        => esc_url_raw( (string) ( $cell['url'] ?? '' ) ),
		'alt'        => sanitize_text_field( (string) ( $cell['alt'] ?? '' ) ),
		'color'      => (string) sanitize_hex_color( (string) ( $cell['color'] ?? '' ) ),
		'posterId'   => (int) ( $cell['posterId'] ?? 0 ),
		'posterUrl'  => esc_url_raw( (string) ( $cell['posterUrl'] ?? '' ) ),
		'focalPoint' => array(
			'x' => clamp_unit( $focal['x'] ?? 0.5 ),
			'y' => clamp_unit( $focal['y'] ?? 0.5 ),
		),
	);
}

/**
 * Clamps a number to the 0..1 range.
 *
 * @param mixed $value Raw value.
 */
function clamp_unit( $value ): float {
	return min( 1.0, max( 0.0, (float) $value ) );
}

/**
 * CSS object-position from a focal point.
 *
 * @param array{x:float,y:float} $focal Focal point.
 */
function object_position( array $focal ): string {
	return sprintf( '%s%% %s%%', round( $focal['x'] * 100, 2 ), round( $focal['y'] * 100, 2 ) );
}

/**
 * Renders the contents of a single cell.
 *
 * @param array  $cell       Normalised cell.
 * @param string $size       Slug of the registered image size.
 * @param string $sizes_attr Value of the sizes attribute for the srcset.
 */
function render_cell( array $cell, string $size, string $sizes_attr ): string {
	if ( 'color' === $cell['kind'] ) {
		if ( '' === $cell['color'] ) {
			return '';
		}

		return sprintf(
			'<span class="wow-cell__fill" style="background-color:%s"></span>',
			esc_attr( $cell['color'] )
		);
	}

	if ( 'video' === $cell['kind'] ) {
		return render_video( $cell, $size );
	}

	/*
	 * No `loading` here on purpose. WordPress decides it per image in
	 * wp_get_loading_optimization_attributes(): it omits lazy loading from
	 * the first few pictures of a page and marks the likeliest of them
	 * `fetchpriority="high"`, because that one is almost always the largest
	 * thing in the first screen and therefore the metric Google measures the
	 * page by. State `loading` here and that reasoning is switched off — a
	 * lazy image is read as "not in the viewport", and the whole page, the
	 * picture at the very top included, waits its turn.
	 */
	$attrs = array(
		'class'          => 'wow-cell__image',
		'style'          => 'object-position:' . object_position( $cell['focalPoint'] ),
		'sizes'          => $sizes_attr,
		'decoding'       => 'async',
	);

	if ( $cell['id'] > 0 ) {
		// The alt text defaults to the attachment's; one set on the block wins.
		if ( '' !== $cell['alt'] ) {
			$attrs['alt'] = $cell['alt'];
		}

		return (string) wp_get_attachment_image( $cell['id'], available_size( $cell['id'], $size ), false, $attrs );
	}

	// External URL: without an attachment there is no srcset to build, so
	// it goes out as it is.
	if ( '' !== $cell['url'] ) {
		unset( $attrs['sizes'] );

		// Here it is stated outright: without an attachment the image never
		// passes through core, so there is nothing to work the answer out.
		$attrs['loading'] = 'lazy';

		return sprintf(
			'<img src="%s" alt="%s" %s />',
			esc_url( $cell['url'] ),
			esc_attr( $cell['alt'] ),
			html_attributes( $attrs )
		);
	}

	return '';
}

/**
 * Checks that the attachment really has the crop being asked for.
 *
 * WordPress does not create a crop larger than the source. Request such a
 * size and it substitutes an arbitrary intermediate one instead, usually a
 * good deal smaller than intended. Serving the original is more honest.
 *
 * The entry also has to come from the picture as it is now. After an edit in
 * the media editor the crops that no longer fit inside the new, smaller
 * source keep their old entries, so the front end would quietly show a
 * different photo than the one in the editor.
 *
 * @param int    $id   Attachment ID.
 * @param string $size Desired size.
 */
function available_size( int $id, string $size ): string {
	$meta = wp_get_attachment_metadata( $id );

	if ( ! is_array( $meta ) || empty( $meta['file'] ) || empty( $meta['sizes'][ $size ]['file'] ) ) {
		return 'full';
	}

	return belongs_to_source( (string) $meta['file'], (string) $meta['sizes'][ $size ]['file'] ) ? $size : 'full';
}

/**
 * A short film in a cell, standing in for a photograph.
 *
 * It is written out as a poster and nothing else: no source, no controls,
 * no autoplay attribute. The address of the film is kept in a data
 * attribute until the cell comes near the screen, which is what makes it as
 * cheap to arrive at as a lazily loaded picture — a video element given a
 * source fetches its first frames whatever `preload` says, and a page of
 * them would be a page of downloads nobody asked for.
 *
 * The poster is an ordinary attachment, made from the first frame when the
 * film was placed, so it has every size, every format and all of the
 * loading sense that the pictures beside it have. Before the film plays,
 * and for anyone whose browser will not play it at all, the cell is that
 * picture.
 *
 * Muted is not a preference: a film that is not muted is a film no browser
 * will start by itself.
 *
 * @param array  $cell Normalised cell.
 * @param string $size Image size for the poster.
 */
function render_video( array $cell, string $size ): string {
	if ( '' === $cell['url'] ) {
		return '';
	}

	$poster = '';

	if ( $cell['posterId'] > 0 ) {
		$poster = (string) wp_get_attachment_image_url(
			$cell['posterId'],
			available_size( $cell['posterId'], $size )
		);
	}

	if ( '' === $poster ) {
		$poster = $cell['posterUrl'];
	}

	$attrs = array(
		'class'          => 'wow-cell__video',
		'data-wow-video' => $cell['url'],
		'preload'        => 'none',
	);

	if ( '' !== $poster ) {
		$attrs['poster'] = $poster;
	}

	if ( '' !== $cell['alt'] ) {
		$attrs['aria-label'] = $cell['alt'];
	}

	return sprintf(
		'<video muted loop playsinline disablepictureinpicture %s></video>',
		html_attributes( $attrs )
	);
}

/**
 * Whether the cell asks for anything other than the centre of the picture.
 *
 * @param array $cell Normalised cell.
 */
function is_framed( array $cell ): bool {
	return abs( $cell['focalPoint']['x'] - 0.5 ) > 0.001
		|| abs( $cell['focalPoint']['y'] - 0.5 ) > 0.001;
}

/**
 * Renders a row cell, picking the image size from the column type.
 *
 * A column crop is hard and arrives at exactly the size of the cell, so
 * object-fit has nothing left over to shift: object-position cannot move a
 * picture that already fits, and the framing set in the editor would be
 * ignored on the front end. WordPress cuts such a crop from the centre, which
 * is why an important detail near an edge kept disappearing.
 *
 * A cell framed away from the centre is therefore rendered from the soft
 * source instead — the whole picture at the width of a row — and the browser
 * does the cropping, where the framing does apply. Cropping to cover gives
 * the same picture either way, so nothing shifts when the framing is left in
 * the middle; only a re-framed cell pays for the larger file.
 *
 * @param array  $cell   Normalised cell.
 * @param string $column full|wide|narrow.
 * @param int    $width  Column width in px, for the sizes attribute.
 */
function render_row_cell( array $cell, string $column, int $width ): string {
	$size = is_framed( $cell ) ? BAND_SOURCE_SIZE : image_size_for_column( $column );

	return render_cell(
		$cell,
		$size,
		sprintf( '(max-width: %1$dpx) 100vw, %1$dpx', $width )
	);
}

/**
 * Builds a string of HTML attributes.
 *
 * @param array<string,string> $attrs Attribute => value pairs.
 */
function html_attributes( array $attrs ): string {
	$parts = array();

	foreach ( $attrs as $name => $value ) {
		if ( '' === $value ) {
			continue;
		}

		$parts[] = sprintf( '%s="%s"', esc_attr( $name ), esc_attr( $value ) );
	}

	return implode( ' ', $parts );
}
