/**
 * Shows the button once the page is scrolled and takes the visitor back up.
 */
( function () {
	'use strict';

	var button = document.querySelector( '.wow-back-to-top' );

	if ( ! button ) {
		return;
	}

	var offset = parseInt( button.dataset.offset, 10 );

	if ( isNaN( offset ) ) {
		offset = 300;
	}

	var pending = false;

	function sync() {
		pending = false;
		button.classList.toggle( 'is-visible', window.scrollY > offset );
	}

	/*
	 * Scrolling fires far more often than the screen is redrawn, so the class
	 * is worked out once per frame instead of once per event.
	 */
	function onScroll() {
		if ( pending ) {
			return;
		}

		pending = true;
		window.requestAnimationFrame( sync );
	}

	window.addEventListener( 'scroll', onScroll, { passive: true } );

	// The page can open part-way down: a reload, or a link with an anchor.
	sync();

	button.addEventListener( 'click', function ( event ) {
		var still = window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

		window.scrollTo( { top: 0, behavior: still ? 'auto' : 'smooth' } );

		/*
		 * A mouse or a finger: nothing has to be focused afterwards. The
		 * button is about to hide itself, so focus is simply let go of —
		 * moving it to the top of the page would draw the theme's focus ring
		 * around the whole of the content, and there is nobody to show it to.
		 */
		if ( 0 !== event.detail ) {
			button.blur();

			return;
		}

		/*
		 * The keyboard, on the other hand: the button disappears, and focus
		 * left on a hidden element falls back to the very end of the page —
		 * the opposite of what was just asked for. It goes to the top of the
		 * page instead, without scrolling it a second time.
		 */
		var target = document.getElementById( 'wp--skip-link--target' ) ||
			document.querySelector( 'main' ) ||
			document.body;

		if ( ! target.hasAttribute( 'tabindex' ) ) {
			target.setAttribute( 'tabindex', '-1' );
		}

		/*
		 * The ring around the whole content area says nothing useful even
		 * here; the next Tab lands on the first link of the page and shows
		 * a ring where it belongs. The class goes again the moment focus
		 * moves on, so nothing else on the page is affected.
		 */
		target.classList.add( 'wow-back-to-top-target' );
		target.addEventListener( 'blur', function () {
			target.classList.remove( 'wow-back-to-top-target' );
		}, { once: true } );

		target.focus( { preventScroll: true } );
	} );
}() );
