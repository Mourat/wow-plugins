/**
 * The average colour of what the strip is actually showing.
 *
 * The strip is a small picture beside a flat colour, and the colour that
 * belongs beside a picture is the one the picture is made of. Finding it by
 * hand means a trip to some website that averages an image, a copied hex and
 * a paste back here, repeated every time the framing moves.
 *
 * So it is read off the picture directly. The window is redrawn on a canvas
 * exactly as the stylesheet draws it — same cover, same scale, same offset,
 * term for term — and what comes out is what is on screen, not an average of
 * the whole photograph. Reframe onto the sky and the strip turns blue.
 *
 * The averaging is the plain arithmetic mean of the stored values, which is
 * worth a word because it is not the technically correct one. sRGB is not a
 * linear scale, and averaging light properly means squaring on the way in
 * and taking the root on the way out — which gives a lighter, and by the
 * physics a truer, colour.
 *
 * It gives the wrong answer here all the same. The tool this replaces is the
 * ordinary mean, the colours already chosen across the albums came from it,
 * and a strip that disagrees with its neighbours by twenty or thirty points
 * is wrong in the only way that matters. Checked against two pictures and
 * the colours that were picked for them: the plain mean lands on #8196a4
 * exactly and on #3f4643 within one step of rounding, while the linear mean
 * misses both by about thirty.
 */

/** Decoded images, so dragging the crosshair does not re-decode each time. */
const decoded = new Map();

/**
 * Loads an image, once per address.
 *
 * @param {string} url Where it is.
 * @return {Promise<HTMLImageElement|null>} The image, or null.
 */
function load( url ) {
	if ( decoded.has( url ) ) {
		return decoded.get( url );
	}

	const waiting = new Promise( ( resolve ) => {
		const image = new window.Image();

		image.onload = () => resolve( image );
		image.onerror = () => resolve( null );
		image.src = url;
	} );

	decoded.set( url, waiting );

	return waiting;
}

function clamp( value, low, high ) {
	return Math.min( high, Math.max( low, value ) );
}

/**
 * Two hex digits.
 *
 * @param {number} value 0..255.
 */
function hex( value ) {
	return Math.round( clamp( value, 0, 255 ) )
		.toString( 16 )
		.padStart( 2, '0' );
}

/**
 * The average colour of the part of an image the strip shows.
 *
 * Returns nothing rather than a guess when it cannot look: an image from
 * another site taints the canvas and reading it back throws, and a strip
 * recoloured on a guess would be worse than one left alone.
 *
 * @param {Object} props
 * @param {string} props.url    The image.
 * @param {Object} props.focal  Focal point, 0..1 on each axis.
 * @param {number} props.scale  How far the frame is zoomed in.
 * @param {number} props.width  Width of the window, in px.
 * @param {number} props.height Height of the window, in px.
 * @return {Promise<string>} A hex colour, or an empty string.
 */
export default async function averageColor( {
	url,
	focal,
	scale = 1,
	width,
	height,
} ) {
	if ( ! url || ! width || ! height ) {
		return '';
	}

	const image = await load( url );

	if ( ! image?.naturalWidth || ! image?.naturalHeight ) {
		return '';
	}

	const canvas = document.createElement( 'canvas' );

	canvas.width = Math.max( 1, Math.round( width ) );
	canvas.height = Math.max( 1, Math.round( height ) );

	const paper = canvas.getContext( '2d', { willReadFrequently: true } );

	if ( ! paper ) {
		return '';
	}

	const x = clamp( focal?.x ?? 0.5, 0, 1 );
	const y = clamp( focal?.y ?? 0.5, 0, 1 );
	const zoom = scale > 0 ? scale : 1;

	// The element is `scale` times the window, and object-fit: cover fills it.
	const elementWidth = width * zoom;
	const elementHeight = height * zoom;
	const cover = Math.max(
		elementWidth / image.naturalWidth,
		elementHeight / image.naturalHeight
	);
	const drawnWidth = image.naturalWidth * cover;
	const drawnHeight = image.naturalHeight * cover;

	paper.drawImage(
		image,
		// Where the element sits in the window — the same clamp as the
		// stylesheet, never far enough to drag an edge of the image inside —
		// plus where object-position puts the picture inside the element.
		clamp( width * ( 0.5 - x * zoom ), width * ( 1 - zoom ), 0 ) +
			( elementWidth - drawnWidth ) * x,
		clamp( height * ( 0.5 - y * zoom ), height * ( 1 - zoom ), 0 ) +
			( elementHeight - drawnHeight ) * y,
		drawnWidth,
		drawnHeight
	);

	let pixels;

	try {
		pixels = paper.getImageData( 0, 0, canvas.width, canvas.height ).data;
	} catch {
		// Tainted: the picture is not ours to read.
		return '';
	}

	let red = 0;
	let green = 0;
	let blue = 0;
	let seen = 0;

	for ( let at = 0; at < pixels.length; at += 4 ) {
		const alpha = pixels[ at + 3 ] / 255;

		if ( 0 === alpha ) {
			continue;
		}

		red += pixels[ at ] * alpha;
		green += pixels[ at + 1 ] * alpha;
		blue += pixels[ at + 2 ] * alpha;
		seen += alpha;
	}

	if ( 0 === seen ) {
		return '';
	}

	return `#${ hex( red / seen ) }${ hex( green / seen ) }${ hex(
		blue / seen
	) }`;
}
