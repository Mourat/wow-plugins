/**
 * Pulling the crop frame onto the seam where two photographs were joined.
 *
 * Several pictures here are two photographs butted together into one, with
 * or without a coloured bar between them, and the crop almost always wants
 * to land exactly on that join. By hand it means nudging a pixel at a time
 * and still being a pixel out.
 *
 * What makes a join findable is that it is straight and runs the whole
 * height of the picture. So for each column: in how many of its rows is
 * there a sharp step from the pixel on the left to the pixel on the right,
 * and is that step the sharpest anywhere near — that is, does the edge sit
 * in this column in that row, rather than a pixel or two over?
 *
 * Both halves of the question are needed, and the second was learnt the
 * hard way. Counting sharp steps alone, a roof of curved tiles, a row of
 * tree trunks, the dark side of a doorway all answer like a join: each is a
 * near-vertical line running the height of the picture. What they are not
 * is straight. A tile's ridge wanders a few pixels as it goes down, so its
 * rows vote for a dozen neighbouring columns and no column holds a majority;
 * a join wanders nowhere, and every row votes for the same one.
 *
 * Over the hundred and twenty pictures in this library, counting steps alone
 * answered for thirty-eight of them. Requiring the step to be in that exact
 * column answers for seven — and the seven are the joins. The ones that fell
 * away fell a long way: their best column dropped to between 0.15 and 0.44
 * of the height, while every real join stayed between 0.50 and 0.99.
 *
 * Hence 0.45. An earlier attempt measured how much edge a column held rather
 * than how much of its height, and could not tell a join from a wall at all
 * — the wall won.
 *
 * Nothing is read but the picture already on screen, at the size it is on
 * screen, so none of this touches the scaling between what is displayed and
 * what is stored — which is where the bodies are buried in this editor.
 */

/**
 * How near the frame has to be to a join before it is pulled.
 *
 * A share of the picture's width rather than a count of pixels, because
 * what counts as "roughly there" is what the eye judges against the whole
 * picture, not against a ruler.
 *
 * The share comes from watching it fail. In a picture 600 wide, with joins
 * at 243 and 263, four attempts to drag to them landed at 218, 299, 307 and
 * 189 — twenty-five, thirty-six, forty-four and fifty-four pixels out.
 * Twelve pixels caught nothing; thirty-two caught half. A tenth of the
 * width is sixty here, which catches all four.
 *
 * Generous on purpose. A picture holds one or two joins, not a field of
 * candidates, so there is little for an over-eager pull to land on by
 * mistake — and Shift or Alt turns it off for a crop that means to be near
 * a join without being on it.
 */
const REACH_SHARE = 0.1;

/** Never less than this, for a picture shown small. */
const REACH_LEAST = 24;

/** How far apart two joins must be to count as two, in px. */
const APART = 8;

/** A step from one pixel to the next worth calling sharp. */
const STEP = 24;

/**
 * How far an edge may wander and still count as the same column, in px.
 *
 * Three is wide enough that the blur of shrinking a picture for the editor
 * does not disqualify a true join, and narrow enough that anything with a
 * hand-drawn look to it — a tile, a branch, a crack in a wall — falls out.
 */
const SWAY = 3;

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

	// How sharply the picture steps across every pixel in it.
	const edge = new Float32Array( width * height );

	for ( let y = 0; y < height; y++ ) {
		const row = y * width;

		for ( let x = 1; x < width - 1; x++ ) {
			edge[ row + x ] = Math.abs(
				light[ row + x + 1 ] - light[ row + x - 1 ]
			);
		}
	}

	/*
	 * For each column, the share of its height that steps sharply here and
	 * nowhere nearer. A row whose sharpest step is a pixel to the side is a
	 * row where the line is a pixel to the side, and it votes for that column
	 * instead — which is how a wandering line comes to have no column at all.
	 */
	const share = new Float32Array( width );

	for ( let x = 1; x < width - 1; x++ ) {
		const first = Math.max( 1, x - SWAY );
		const last = Math.min( width - 2, x + SWAY );
		let rows = 0;

		for ( let y = 0; y < height; y++ ) {
			const row = y * width;
			const here = edge[ row + x ];

			if ( here <= STEP ) {
				continue;
			}

			let straight = true;

			for ( let at = first; at <= last; at++ ) {
				if ( at !== x && edge[ row + at ] > here ) {
					straight = false;

					break;
				}
			}

			if ( straight ) {
				rows++;
			}
		}

		share[ x ] = rows / height;
	}

	/*
	 * Nothing is looked for along the borders. A join between two
	 * photographs is never at the very edge of the pair, while the edge
	 * itself is often the darkest line in the picture — a tree, a shadow,
	 * the corner of a building — and reads exactly like one. Left in, those
	 * pull the frame a few pixels inside the picture on a side nobody
	 * touched, quietly losing a strip of it.
	 */
	const border = Math.max( 8, Math.round( width * 0.02 ) );

	// Peaks above the bar, and not two of them a few pixels apart.
	for ( let x = border; x < width - border; x++ ) {
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
 * @param {number}   edge   Where the frame's edge is.
 * @param {number[]} lines  Where the joins are.
 * @param {number}   reach  How near is near enough.
 * @param {number}   inward Which way to step off the join, in px.
 * @return {number} Where the edge should be.
 */
function pull( edge, lines, reach, inward ) {
	let best = edge;
	let near = reach + 1;

	for ( const line of lines ) {
		const away = Math.abs( line - edge );

		if ( away < near ) {
			near = away;
			best = line;
		}
	}

	return near <= reach ? best + inward : edge;
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

		/*
		 * Not in the next free millisecond but a little after, because the
		 * frame belongs to whoever touched it last. imgAreaSelect answers
		 * the same release of the mouse, and a selection set while it is
		 * still answering would be written over by the position the cursor
		 * was let go at. Waiting until it has finished means the frame is
		 * nobody's but ours for the moment it takes to move it.
		 */
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

			const reach = Math.max(
				REACH_LEAST,
				Math.round( image.clientWidth * REACH_SHARE )
			);
			/*
			 * A pixel inside the join rather than on it. Shrinking the
			 * picture for the editor smears a hard edge across a pixel or
			 * two, so the column that stands out most can sit just inside
			 * the divider rather than just outside it. Giving up one pixel
			 * of a photograph is nothing; leaving a red line along its edge
			 * is the thing being fixed.
			 */
			/*
			 * An edge already against the side of the picture is where it
			 * was put on purpose — all the way — and is left alone. Pulling
			 * it inwards would be the magnet taking something nobody asked
			 * it to take, on a side nobody was holding.
			 */
			const x1 = was.x1 <= 0 ? was.x1 : pull( was.x1, lines, reach, 1 );
			const x2 =
				was.x2 >= image.clientWidth
					? was.x2
					: pull( was.x2, lines, reach, -1 );

			if ( ( x1 === was.x1 && x2 === was.x2 ) || x2 - x1 < 3 ) {
				return;
			}

			ias.setSelection( x1, was.y1, x2, was.y2, true );
			ias.update();

			/*
			 * The width and height fields have to be written too, and this
			 * is not bookkeeping — it is the whole crop.
			 *
			 * What core saves is built from two places at once: the left
			 * edge comes from the selection, the width from the field
			 * beside the picture. The field is filled by the handler that
			 * answers a dragged selection, and a selection set in code
			 * answers nothing. So the drawn rectangle moved and the width
			 * stayed as the hand had left it — the saved crop started at
			 * the join and ran on past it, and a sliver of the divider came
			 * through on both halves while the editor looked perfect.
			 *
			 * At full scale, as core keeps them, and clamped to the picture
			 * the way core clamps them.
			 */
			const sizer = api?.hold?.sizer || 1;
			const whole = api?.hold || {};

			const put = ( which, value ) => {
				const field = document.getElementById(
					`imgedit-${ which }-${ id }`
				);

				if ( field ) {
					field.value = String( value );
				}
			};

			put(
				'sel-width',
				Math.min(
					whole.w || Infinity,
					Math.round( ( x2 - x1 ) / sizer )
				)
			);
			put(
				'sel-height',
				Math.min(
					whole.h || Infinity,
					Math.round( ( was.y2 - was.y1 ) / sizer )
				)
			);

			// Now core can read both halves and agree with what is drawn.
			api.setCropSelection( id, ias.getSelection() );
		}, 40 );
	};

	document.addEventListener( 'mouseup', onLetGo );
	document.addEventListener( 'touchend', onLetGo );

	return stop;
}
