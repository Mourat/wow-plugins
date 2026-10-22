/**
 * A film that comes back to its beginning without a jolt.
 *
 * A video element playing in a loop jumps from its last frame to its
 * first. In a cell standing in for a photograph that reads as a flinch in
 * the corner of the eye, which is the one thing a picture that moves must
 * not do.
 *
 * So the film is kept in two copies, one over the other. A few tenths of a
 * second before the end, the copy underneath starts again from the
 * beginning and the two cross over in opacity; then they swap roles.
 * Nothing is re-encoded and nothing is fetched twice — the same file is
 * decoded by two elements, which for a clip of a few hundred kilobytes is
 * not a cost worth counting.
 *
 * Used by the page and by the editor alike, so that what is being made
 * looks like what will be seen.
 */

/** How long one end of the loop takes to become the other, in seconds. */
export const FADE = 0.4;

/**
 * Runs the crossing between two copies of one film.
 *
 * Both elements are expected to be in the document, one over the other,
 * and to be showing the same film. Which of them is seen is a matter of
 * opacity and is this function's business from here on.
 *
 * @param {HTMLVideoElement[]} pair The two copies.
 * @return {{play: Function, stop: Function}} Its controls.
 */
export default function softLoop( pair ) {
	let front = 0;
	let crossing = false;
	let rolling = false;
	let frame = 0;

	pair.forEach( ( one, at ) => {
		// The looping is ours now, and a looping element never ends.
		one.loop = false;
		one.muted = true;
		one.playsInline = true;
		one.style.transition = `opacity ${ FADE }s linear`;
		one.style.opacity = at ? '0' : '1';
	} );

	const cross = () => {
		const going = pair[ front ];
		const coming = pair[ 1 - front ];

		crossing = true;
		front = 1 - front;

		coming.currentTime = 0;
		// A refusal to play is an answer, not a fault: a browser may be
		// saving power, and the frame on screen is still the right one.
		coming.play().catch( () => {} );
		coming.style.opacity = '1';
		going.style.opacity = '0';

		window.setTimeout( () => {
			going.pause();
			going.currentTime = 0;
			crossing = false;
		}, FADE * 1000 );
	};

	const watch = () => {
		if ( ! rolling ) {
			return;
		}

		const now = pair[ front ];
		const whole = now.duration || 0;

		if ( ! crossing && whole ) {
			// Never more than a third of the film, so that a very short
			// clip is not crossing over its whole length.
			const fade = Math.min( FADE, whole / 3 );

			if ( whole - now.currentTime <= fade ) {
				cross();
			}
		}

		frame = window.requestAnimationFrame( watch );
	};

	return {
		play: () => {
			if ( rolling ) {
				return;
			}

			rolling = true;
			pair[ front ].play().catch( () => {} );
			frame = window.requestAnimationFrame( watch );
		},
		stop: () => {
			rolling = false;
			window.cancelAnimationFrame( frame );
			pair.forEach( ( one ) => one.pause() );
		},
	};
}
