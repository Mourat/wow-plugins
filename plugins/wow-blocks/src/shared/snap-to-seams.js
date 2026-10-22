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
 * A found column is then grown into the bar it belongs to, because most of
 * these joins are not a line but a strip of flat colour ten or fifteen
 * pixels wide, and a frame snapped to one side of a strip keeps the whole
 * strip. Worse, the two sides are not equally findable: in one picture here
 * the bar is brown, the photograph to its left is foliage of the same
 * lightness, and only the right-hand side of the bar steps sharply at all.
 * Snapping to the only side that could be seen left the bar sitting inside
 * the crop.
 *
 * So the bar is found as a region rather than an edge. A strip of flat
 * colour is flat down its whole height, and nothing in a photograph is:
 * measured as the average distance of a column from its own average, the
 * bars in these pictures come out between 0.1 and 2, the photographs
 * between 12 and 58. Growing outwards from the found column while that
 * stays under 4 lands on the bar's two sides exactly. A join with no bar
 * grows nowhere and stays the single line it is.
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

/** How far a column may stray from its own average and still count as flat. */
const FLAT = 4;

/** The widest a bar may be, as a share of the picture. */
const BAND = 0.06;

/**
 * The widest a bar found by its flatness may be, as a share of the picture.
 *
 * Wider than a bar grown from a step is allowed to be, because this one has
 * had to prove there is a photograph on either side of it, and the widest in
 * this library — a pale grey band between two pictures of a cupboard — is
 * fifty-two pixels where a six-hundredth of the width would allow forty-five.
 */
const SPAN = 0.15;

/** How much the picture must stray just outside a bar for it to be one. */
const RESTLESS = 12;

/** How far a colour may drift and still be the same colour. */
const TONE = 6;

/** How near a bar a lone line has to be to be part of it. */
const ASIDE = APART * 3;

/**
 * How flat a fill has to be, averaged over it.
 *
 * Flat enough to be a colour somebody filled with, rather than a blurred
 * corner of a photograph or a wall in even light, both of which pass for
 * flat column by column. Over this library the gap is not close: a fill
 * averages between 0.01 and 0.12, while a white wall beside a door averages
 * 1.25, deep blue sea 1.35 and an out-of-focus foreground 2.57.
 */
const TAME = 0.3;

/** How far outside a bar to look, stepping over the blur at its edge, in px. */
const SKIP = 2;

/** Columns remembered per picture, so a drag does not measure it again. */
const seen = new Map();

/**
 * Where two pictures were joined.
 *
 * @param {HTMLImageElement} image The preview, as displayed.
 * @return {Array<{left: number, right: number}>} The joins, each as the two
 *         sides of its bar, in the picture's displayed pixels. A join with no
 *         bar has both sides in the same place.
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
	const tops = [];

	// Peaks above the bar, and not two of them a few pixels apart.
	for ( let x = border; x < width - border; x++ ) {
		if (
			share[ x ] < BAR ||
			share[ x ] < share[ x - 1 ] ||
			share[ x ] < share[ x + 1 ]
		) {
			continue;
		}

		const last = tops[ tops.length - 1 ];

		if ( undefined !== last && x - last < APART ) {
			if ( share[ x ] > share[ last ] ) {
				tops[ tops.length - 1 ] = x;
			}

			continue;
		}

		tops.push( x );
	}

	// The colour of each column, for telling a fill from what it is beside.
	const reds = new Float32Array( width );
	const greens = new Float32Array( width );
	const blues = new Float32Array( width );

	for ( let x = 0; x < width; x++ ) {
		let red = 0;
		let green = 0;
		let blue = 0;

		for ( let y = 0; y < height; y++ ) {
			const at = ( y * width + x ) * 4;

			red += pixels[ at ];
			green += pixels[ at + 1 ];
			blue += pixels[ at + 2 ];
		}

		reds[ x ] = red / height;
		greens[ x ] = green / height;
		blues[ x ] = blue / height;
	}

	// How far each column strays from its own average, down the height.
	const flat = new Float32Array( width );

	for ( let x = 0; x < width; x++ ) {
		let sum = 0;

		for ( let y = 0; y < height; y++ ) {
			sum += light[ y * width + x ];
		}

		const middle = sum / height;
		let strays = 0;

		for ( let y = 0; y < height; y++ ) {
			strays += Math.abs( light[ y * width + x ] - middle );
		}

		flat[ x ] = strays / height;
	}

	/**
	 * The bar a column belongs to.
	 *
	 * Spreading out while the picture stays flat is not enough on its own.
	 * A bar is often flat against something else flat — a blue divider with
	 * a strip of pale wall beyond it, the first ten pixels of the next
	 * photograph — and a run that swallows both puts the frame ten pixels
	 * inside the picture, past the border the eye is aiming at. So the
	 * colour has to hold as well: the wall is a different colour from the
	 * divider, however flat each of them is.
	 *
	 * One column beyond that on each side, whatever colour it is, as long as
	 * it is flat. Shrinking a picture for the editor smears the bar's edge
	 * across a pixel, and a pixel of divider left along the edge of a crop
	 * is the thing all of this is for.
	 *
	 * @param {number} seed A column known to be flat.
	 * @return {{from: number, to: number}} The bar's first and last column.
	 */
	const grow = ( seed ) => {
		const same = ( x ) =>
			flat[ x ] < FLAT &&
			Math.abs( reds[ x ] - reds[ seed ] ) <= TONE &&
			Math.abs( greens[ x ] - greens[ seed ] ) <= TONE &&
			Math.abs( blues[ x ] - blues[ seed ] ) <= TONE;

		let from = seed;
		let to = seed;

		while ( 0 < from && same( from - 1 ) ) {
			from--;
		}

		while ( to < width - 1 && same( to + 1 ) ) {
			to++;
		}

		if ( 0 < from && flat[ from - 1 ] < FLAT ) {
			from--;
		}

		if ( to < width - 1 && flat[ to + 1 ] < FLAT ) {
			to++;
		}

		return { from, to };
	};

	const bounded = Math.max( 2, Math.round( width * BAND ) );

	for ( const top of tops ) {
		/*
		 * The found column is the step onto the bar, which puts it at one
		 * side of it or just outside: start from whichever of the three is
		 * flattest.
		 */
		const beside = flat[ top + 1 ] <= flat[ top - 1 ] ? top + 1 : top - 1;
		const first = flat[ top ] < FLAT ? top : beside;
		const { from, to } =
			flat[ first ] < FLAT ? grow( first ) : { from: first, to: first };

		if ( flat[ first ] >= FLAT || to - from >= bounded ) {
			// No bar here, or something too wide to be one — a sky, a wall
			// in even light. Then the join is the line that was found.
			keep( found, { left: top, right: top } );
		} else if ( from <= border || to >= width - border - 1 ) {
			// Flat all the way to the side of the picture: a colour the
			// photograph was laid on rather than a bar between two of them,
			// and the join is the one edge of it that is not the picture's.
			const inner = from <= border ? to : from;

			keep( found, { left: inner, right: inner } );
		} else {
			keep( found, { left: from, right: to + 1 } );
		}
	}

	/*
	 * And bars nothing stepped onto.
	 *
	 * A bar is only found by its edges when its edges can be seen, and one
	 * here is a blue strip against a blue sea: on that side there is no step
	 * worth the name, and on the other the sea itself is busy enough to hide
	 * one. The bar is still a bar, though — flat down its whole height, which
	 * is what is looked for here instead.
	 *
	 * Flatness alone is not enough to go on. Across this library fifty-two
	 * runs of columns are flat down their height, and fifty-one of them are
	 * rendered walls and clear skies. What tells the one from the fifty-one
	 * is what lies just outside it: a bar has a photograph on both sides and
	 * photographs are restless, while a flat patch of wall has more wall. Two
	 * pixels out from each end, the bar's neighbours stray by 38 and 43; not
	 * one of the fifty-one manages 12 on both sides.
	 */
	const widest = Math.max( 2, Math.round( width * SPAN ) );

	for ( let x = border; x < width - border; x++ ) {
		if ( flat[ x ] >= FLAT ) {
			continue;
		}

		const from = x;

		while ( x < width - border && flat[ x ] < FLAT ) {
			x++;
		}

		const to = x - 1;
		const span = to - from + 1;
		const before = flat[ Math.max( 0, from - 1 - SKIP ) ];
		const after = flat[ Math.min( width - 1, to + 1 + SKIP ) ];
		// Anything reaching a side of the picture is a fill, not a bar, and
		// is answered for below.
		if ( from <= border || to >= width - border - 1 ) {
			continue;
		}

		if ( span < 2 || Math.min( before, after ) < RESTLESS ) {
			continue;
		}

		// The flattest column in the run is the bar's own; the rest of the
		// run may be the wall it is against.
		let seed = from;

		for ( let at = from + 1; at <= to; at++ ) {
			if ( flat[ at ] < flat[ seed ] ) {
				seed = at;
			}
		}

		const bar = grow( seed );

		if ( bar.to - bar.from >= widest ) {
			continue;
		}

		keep( found, { left: bar.from, right: bar.to + 1 } );
	}

	/*
	 * And the fills: a colour a picture was laid on rather than a bar
	 * between two of them. A third of one picture here is plain terracotta,
	 * and the join is simply where the photograph ends — one edge, not two,
	 * and no width it could be too wide to be.
	 *
	 * Found by walking in from the side of the picture while the colour
	 * stays the colour it started as. Starting at the edge is what makes it
	 * reliable: a run of flat columns on its own set off along the white
	 * wall at the end of a photograph and only then crossed into the red
	 * bar beyond it, putting the join a finger's width inside the picture.
	 * The colour at the very edge is the fill's own, and nothing else is
	 * allowed into the run.
	 *
	 * Then it must be flat on average, not merely column by column, which
	 * is what parts a fill from a blurred foreground or a wall in even
	 * light.
	 */
	for ( const way of [ 1, -1 ] ) {
		const side = 0 < way ? 0 : width - 1;

		if ( flat[ side ] >= FLAT ) {
			continue;
		}

		let far = side;
		let sum = 0;
		let wide = 0;

		for ( let x = side; 0 <= x && x < width; x += way ) {
			if (
				flat[ x ] >= FLAT ||
				Math.abs( reds[ x ] - reds[ side ] ) > TONE ||
				Math.abs( greens[ x ] - greens[ side ] ) > TONE ||
				Math.abs( blues[ x ] - blues[ side ] ) > TONE
			) {
				break;
			}

			far = x;
			sum += flat[ x ];
			wide++;
		}

		if ( wide < border || sum / wide > TAME ) {
			continue;
		}

		keep( found, { left: far, right: far } );
	}

	found.sort( ( one, other ) => one.left - other.left );
	/*
	 * A lone line beside a bar is thrown away. It is not a join of its own
	 * but the far side of whatever the bar is standing against — where the
	 * pale wall at the edge of a photograph gives way to the photograph
	 * proper, say. Left in, it is a second thing to snap to a few pixels
	 * past the bar, and the frame goes to it instead: the magnet steps over
	 * the border it was aiming at and lands inside the picture.
	 *
	 * Before the joining below rather than after it. Eight pixels is near
	 * enough to be joined on to the bar, and a line swallowed that way
	 * drags the bar's far edge out to itself — the same wrong answer by
	 * another road, and the one that was actually happening.
	 */
	for ( let i = found.length - 1; 0 <= i; i-- ) {
		const one = found[ i ];

		if ( one.left !== one.right ) {
			continue;
		}

		const leaning = found.some(
			( other, at ) =>
				at !== i &&
				other.left !== other.right &&
				one.left >= other.left - ASIDE &&
				one.left <= other.right + ASIDE
		);

		if ( leaning ) {
			found.splice( i, 1 );
		}
	}

	/*
	 * A last pass over them in order. The three ways of finding a join
	 * arrive in their own order and each only compares against what is
	 * there at the time, so the same join can be kept twice — a bar, and
	 * the step onto it that was found before the bar existed.
	 */
	for ( let i = found.length - 1; 0 < i; i-- ) {
		const near = found[ i - 1 ];
		const far = found[ i ];

		if ( far.left <= near.right + APART ) {
			near.right = Math.max( near.right, far.right );

			found.splice( i, 1 );
		}
	}
	return found;
}

/**
 * Adds a join to the list, unless it is one already there.
 *
 * The same bar can be arrived at twice — once from each of its sides, or
 * once by its edges and once by its flatness — and two joins in the same
 * place would only make the nearer of them win by a pixel.
 *
 * @param {Array<{left: number, right: number}>} found Joins so far.
 * @param {{left: number, right: number}}        bar   One more.
 */
function keep( found, bar ) {
	for ( const already of found ) {
		// Touching counts as overlapping: a bar and the step onto it are the
		// same join, and they meet without ever sharing a column.
		if ( already.left <= bar.right + 1 && bar.left <= already.right + 1 ) {
			already.left = Math.min( already.left, bar.left );
			already.right = Math.max( already.right, bar.right );

			return;
		}
	}

	found.push( bar );
}

/**
 * The nearest join worth moving to, or the edge unchanged.
 *
 * Which side of the join is taken follows from which edge of the frame is
 * being moved: the frame's left edge belongs on the far side of the bar, its
 * right edge on the near side. Both leave the bar outside the crop, which is
 * the whole point of knowing where the bar begins and ends.
 *
 * @param {number}                               edge   The frame's edge.
 * @param {Array<{left: number, right: number}>} bars   Where the joins are.
 * @param {number}                               reach  How near is near
 *                                                      enough.
 * @param {number}                               inward Which way to step
 *                                                      off the join, in px.
 * @return {number} Where the edge should be.
 */
function pull( edge, bars, reach, inward ) {
	let best = edge;
	let near = reach + 1;

	for ( const bar of bars ) {
		const away = Math.min(
			Math.abs( bar.left - edge ),
			Math.abs( bar.right - edge )
		);

		if ( away < near ) {
			near = away;
			best = 0 < inward ? bar.right : bar.left;
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
		// Shift — or Alt — asks for the frame to be left where it was put.
		// The fields below are still written: those are not the magnet.
		const magnet = ! ( event?.shiftKey || event?.altKey );

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

			const bars = magnet ? seams( image ) : [];
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
			 *
			 * An edge already against the side of the picture is where it
			 * was put on purpose — all the way — and is left alone. Pulling
			 * it inwards would be the magnet taking something nobody asked
			 * it to take, on a side nobody was holding.
			 */
			let x1 = was.x1;
			let x2 = was.x2;

			if ( bars.length ) {
				const pulled1 =
					was.x1 <= 0 ? was.x1 : pull( was.x1, bars, reach, 1 );
				const pulled2 =
					was.x2 >= image.clientWidth
						? was.x2
						: pull( was.x2, bars, reach, -1 );

				if (
					( pulled1 !== was.x1 || pulled2 !== was.x2 ) &&
					3 <= pulled2 - pulled1
				) {
					x1 = pulled1;
					x2 = pulled2;

					ias.setSelection( x1, was.y1, x2, was.y2, true );
					ias.update();
				}
			}

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
			 * The two the frame starts at are written for a second reason,
			 * and it is a plain bug in the editor rather than anything to
			 * do with joins. Dragging a frame never fills them in: they are
			 * left reading zero from the moment the panel was built. Lose
			 * focus on any of the four afterwards and core rebuilds the
			 * frame from what they say —
			 *
			 *     x1 = ( xS === sel.x1 ) ? sel.x1 : Math.round( xS * sizer );
			 *
			 * — so a frame drawn around the right of a picture is thrown
			 * against the left edge, keeping its width. Crop it and the
			 * wrong half is saved, which is a thing that has happened here
			 * more than once. Writing where the frame actually starts means
			 * that rebuilding gives back the frame that is drawn.
			 *
			 * The width is clamped to the room left to the right of it, so
			 * that core's other arithmetic — x2 = x1 + round( w * sizer ),
			 * and x1 = 0 should that land past the edge — cannot overflow
			 * by the odd pixel rounding leaves behind.
			 *
			 * At full scale, as core keeps them.
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

			const startX = Math.round( x1 / sizer );
			const startY = Math.round( was.y1 / sizer );

			put( 'start-x', startX );
			put( 'start-y', startY );
			put(
				'sel-width',
				Math.max(
					1,
					Math.min(
						Math.floor( ( x2 - x1 ) / sizer ),
						( whole.w || Infinity ) - startX
					)
				)
			);
			put(
				'sel-height',
				Math.max(
					1,
					Math.min(
						Math.floor( ( was.y2 - was.y1 ) / sizer ),
						( whole.h || Infinity ) - startY
					)
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
