import softLoop from '../shared/soft-loop';

/**
 * Playing the short films in a row, and only while they are being looked at.
 *
 * A film in a cell stands in for a photograph, so it has to behave like one:
 * nothing is fetched until the cell comes near the screen, nothing happens
 * on screens it has already left. The markup arrives with a poster and no
 * source at all — a video element with a source helps itself to the first
 * frames whatever `preload` says it may do, and an album of twenty rows
 * would be twenty downloads nobody asked for.
 *
 * Two distances, one watcher. Two hundred pixels before the cell arrives the
 * film is fetched, so that it is ready rather than starting with a stutter;
 * once a quarter of the cell is actually on screen it plays, and when it
 * leaves it stops where it was.
 *
 * The loop is softened rather than cut. A video element playing in a loop
 * jumps from its last frame to its first, which reads as a jolt in the
 * corner of the eye — exactly the thing a picture that moves must not do.
 * So the film is kept in two copies, one over the other: a few tenths of a
 * second before the end, the copy underneath starts again from the
 * beginning and the two cross over in opacity. Nothing is re-encoded for
 * this and nothing is downloaded twice; the same file is decoded by two
 * elements, which for a clip of a few hundred kilobytes is not a cost worth
 * counting.
 *
 * Somebody who has asked their computer for less movement gets the poster
 * and nothing else. The films are decorative, carry no sound, and say
 * nothing the poster does not.
 */

/** How near the screen a film is fetched, in px. */
const NEAR = 200;

/** How much of a cell has to be on screen before it plays. */
const ENOUGH = 0.25;

/**
 * Puts a second copy of a film behind the first.
 *
 * @param {HTMLVideoElement} film The element from the markup.
 * @return {HTMLVideoElement[]} The two copies, in the order they play.
 */
function twin( film ) {
	const stage = document.createElement( 'span' );

	stage.className = 'wow-cell__film';
	film.parentNode.insertBefore( stage, film );
	stage.appendChild( film );

	const other = film.cloneNode( false );

	// The poster belongs to whichever copy is seen first and nothing else:
	// on the second it would flash between the two halves of the crossing.
	other.removeAttribute( 'poster' );
	stage.appendChild( other );

	return [ film, other ];
}

/**
 * One film in one cell, as something that can be started and stopped.
 *
 * @param {HTMLVideoElement} film The element from the markup.
 * @return {{fetch: Function, play: Function, stop: Function}} Its controls.
 */
function reel( film ) {
	const source = film.dataset.wowVideo;
	const pair = twin( film );
	const loop = softLoop( pair );

	const fetchFilm = () => {
		if ( ! source || pair[ 0 ].src ) {
			return;
		}

		pair.forEach( ( one ) => {
			one.src = source;
			one.load();
		} );
	};

	return {
		fetch: fetchFilm,
		play: () => {
			fetchFilm();
			loop.play();
		},
		stop: loop.stop,
	};
}

/**
 * Starts watching every film on the page.
 */
function watchAll() {
	const films = Array.from(
		document.querySelectorAll( 'video[data-wow-video]' )
	);

	if ( ! films.length ) {
		return;
	}

	if ( window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
		return;
	}

	const reels = new Map();

	films.forEach( ( film ) => reels.set( film, reel( film ) ) );

	if ( ! ( 'IntersectionObserver' in window ) ) {
		reels.forEach( ( one ) => one.play() );

		return;
	}

	const watcher = new window.IntersectionObserver(
		( entries ) => {
			entries.forEach( ( entry ) => {
				const one = reels.get( entry.target );

				if ( ! one ) {
					return;
				}

				if ( entry.isIntersecting ) {
					one.fetch();
				}

				if ( entry.intersectionRatio >= ENOUGH ) {
					one.play();
				} else {
					one.stop();
				}
			} );
		},
		{
			rootMargin: `${ NEAR }px 0px`,
			threshold: [ 0, ENOUGH ],
		}
	);

	films.forEach( ( film ) => watcher.observe( film ) );
}

if ( 'loading' === document.readyState ) {
	document.addEventListener( 'DOMContentLoaded', watchAll );
} else {
	watchAll();
}
