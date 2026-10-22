/**
 * Pulling the crop frame onto the seam where two photographs were joined.
 *
 * Several pictures here are two photographs butted together into one, with
 * or without a coloured bar between them, and the crop almost always wants
 * to land exactly on that join. By hand it means nudging a pixel at a time
 * and still being a pixel out.
 *
 * What makes a join findable is that it runs the whole height of the
 * picture. So for each column: in how many of its rows is there a sharp
 * step from the pixel on the left to the pixel on the right? A join answers
 * with most of them; the texture of a stone wall, which is full of sharp
 * steps, answers with scattered rows and never a column.
 *
 * Measured on four of these pictures before the bar was chosen:
 *
 *     a photograph of windows          0.30 of the height at its best column
 *     a photograph of a rubble wall    0.25
 *     two photographs joined           0.55  <- the join
 *     two photographs with a bar       0.97  <- the bar's edges
 *
 * Hence 0.45: comfortably above anything a single photograph produced, and
 * comfortably below both joins. An earlier attempt measured how much edge a
 * column held rather than how much of its height, and could not tell a join
 * from a wall at all — the wall won.
 *
 * Nothing is read but the picture already on screen, at the size it is on
 * screen, so none of this touches the scaling between what is displayed and
 * what is stored — which is where the bodies are buried in this editor.
 */

/**
 * How near the frame has to be to a join before it is pulled, in px.
 *
 * Measured against a real attempt: the joins in a picture 600 wide sat at
 * 243 and 263, and the frame was let go at 218 and at 299 — twenty-five and
 * thirty-six pixels out, which is what "roughly there" looks like when
 * somebody drags to a seam. Twelve pixels never reached. Thirty-two is
 * about a twentieth of the picture's width, and there is little to lose by
 * being generous: a picture has one or two joins in it, not a field of
 * candidates, and Shift or Alt turns the whole thing off.
 */
const REACH = 32;

/** How far apart two joins must be to count as two, in px. */
const APART = 8;

/** A step from one pixel to the next worth calling sharp. */
const STEP = 24;

/** How much of the height a join has to run down. */
const BAR = 0.45;

/** Columns remembered per picture, so a drag does not measure it again. */
const seen = new Map();

/**
 * Columns where two pictures were joined.
 *
 * @param {HTMLImageElement} image The preview, as displayed.
 * @return {number[]} Column positions, in the picture's displayed pixels.
 */
function seams( image ) {
	const width = image.clientWidth;
	const height = image.clientHeight;
	const key = `${ image.src }|${ width }x${ height }`;

	if ( seen.has( key ) ) {
		return seen.get( key );
	}

	const found = [];

	seen.set( key, found );

	if ( width < 3 || height < 1 ) {
		return found;
	}

	const canvas = document.createElement( 'canvas' );

	canvas.width = width;
	canvas.height = height;

	const paper = canvas.getContext( '2d', { willReadFrequently: true } );

	if ( ! paper ) {
		return found;
	}

	paper.drawImage( image, 0, 0, width, height );

	let pixels;

	try {
		pixels = paper.getImageData( 0, 0, width, height ).data;
	} catch {
		// Another site's picture; not ours to read.
		return found;
	}

	const light = new Float32Array( width * height );

	for ( let at = 0, i = 0; at < pixels.length; at += 4, i++ ) {
		light[ i ] =
			0.2126 * pixels[ at ] +
			0.7152 * pixels[ at + 1 ] +
			0.0722 * pixels[ at + 2 ];
	}

	// For each column, the share of its height that steps sharply.
	const share = new Float32Array( width );

	for ( let x = 1; x < width - 1; x++ ) {
		let rows = 0;

		for ( let y = 0; y < height; y++ ) {
			const row = y * width;

			if (
				Math.abs( light[ row + x + 1 ] - light[ row + x - 1 ] ) > STEP
			) {
				rows++;
			}
		}

		share[ x ] = rows / height;
	}

	// Peaks above the bar, and not two of them a few pixels apart.
	for ( let x = 1; x < width - 1; x++ ) {
		if (
			share[ x ] < BAR ||
			share[ x ] < share[ x - 1 ] ||
			share[ x ] < share[ x + 1 ]
		) {
			continue;
		}

		const last = found[ found.length - 1 ];

		if ( undefined !== last && x - last < APART ) {
			if ( share[ x ] > share[ last ] ) {
				found[ found.length - 1 ] = x;
			}

			continue;
		}

		found.push( x );
	}

	return found;
}

/**
 * The nearest join worth moving to, or the edge unchanged.
 *
 * @param {number}   edge  Where the frame's edge is.
 * @param {number[]} lines Where the joins are.
 * @return {number} Where the edge should be.
 */
function pull( edge, lines ) {
	let best = edge;
	let near = REACH + 1;

	for ( const line of lines ) {
		const away = Math.abs( line - edge );

		if ( away < near ) {
			near = away;
			best = line;
		}
	}

	return near <= REACH ? best : edge;
}

/**
 * Watches for the frame being let go, and tidies it up.
 *
 * Only the sides are pulled: a join between two photographs is vertical,
 * and a frame that also jumped up and down would be a frame fighting the
 * person holding it.
 *
 * Holding Shift — or Alt — while letting go leaves the frame exactly where
 * it was put. Shift is what was asked for; Alt is there because Shift also
 * means something to this editor already, locking the frame's proportions,
 * and one key should not have to mean two things at once.
 *
 * @param {number} id Attachment id.
 * @return {Function} Call it to stop watching.
 */
export default function snapToSeams( id ) {
	const api = window.imageEdit;

	const stop = () => {
		document.removeEventListener( 'mouseup', onLetGo );
		document.removeEventListener( 'touchend', onLetGo );
	};

	const onLetGo = ( event ) => {
		// Asked to be left alone.
		if ( event?.shiftKey || event?.altKey ) {
			return;
		}

		window.setTimeout( () => {
			const image = document.getElementById( `image-preview-${ id }` );

			// The editor has gone; so should this.
			if ( ! image ) {
				stop();

				return;
			}

			const ias = api?.iasapi;

			if ( ! ias?.getSelection ) {
				return;
			}

			const was = ias.getSelection();

			if ( ! was || was.width < 3 || was.height < 3 ) {
				return;
			}

			const lines = seams( image );

			if ( ! lines.length ) {
				return;
			}

			const x1 = pull( was.x1, lines );
			const x2 = pull( was.x2, lines );

			if ( ( x1 === was.x1 && x2 === was.x2 ) || x2 - x1 < 3 ) {
				return;
			}

			ias.setSelection( x1, was.y1, x2, was.y2, true );
			ias.update();

			// Through core's own bookkeeping, so the numbers beside the
			// picture and the selection it saves agree with what is drawn.
			api.setCropSelection( id, ias.getSelection() );
		}, 0 );
	};

	document.addEventListener( 'mouseup', onLetGo );
	document.addEventListener( 'touchend', onLetGo );

	return stop;
}
