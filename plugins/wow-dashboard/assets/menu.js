/**
 * Opens the folded admin menu while the pointer rests on it.
 *
 * The script does one thing: it puts a class on <body> after a pause and
 * takes it off at once. Everything visible is in menu.css, which is why this
 * stays short enough to read in one go.
 */
( function () {
	var wait = typeof window.wowDashboardMenuWait === 'number' ? window.wowDashboardMenuWait : 280;
	var strip = document.getElementById( 'adminmenumain' );

	if ( ! strip ) {
		return;
	}

	/*
	 * A pointer that cannot hover cannot leave either, so on a touch screen
	 * the menu would open on the first tap and stay open. WordPress has its
	 * own responsive menu there; this one stands aside.
	 */
	if ( window.matchMedia && ! window.matchMedia( '( hover: hover )' ).matches ) {
		return;
	}

	var body = document.body;
	var timer = 0;

	function close() {
		window.clearTimeout( timer );
		body.classList.remove( 'wow-menu-peek' );
	}

	strip.addEventListener( 'mouseenter', function () {
		/*
		 * Asked at the moment of entering, not once at load: the collapse
		 * button folds and unfolds the menu without reloading the page.
		 */
		if ( ! body.classList.contains( 'folded' ) || body.classList.contains( 'wp-responsive-open' ) ) {
			return;
		}

		timer = window.setTimeout( function () {
			/*
			 * While folded, core's hover handler nudges a fly-out upwards by
			 * an inline margin so it fits the window. Open, that list is back
			 * inside the menu and the nudge would lift it off its section.
			 */
			var current = document.querySelector( '#adminmenu .wp-has-current-submenu .wp-submenu' );

			if ( current ) {
				current.style.marginTop = '';
			}

			body.classList.add( 'wow-menu-peek' );
		}, wait );
	} );

	strip.addEventListener( 'mouseleave', close );

	/*
	 * Two ways out that a mouseleave never reports: the pointer leaving the
	 * window entirely over the menu, and the menu being unfolded for good
	 * while open.
	 */
	document.addEventListener( 'mouseleave', close );
	document.addEventListener( 'click', function ( event ) {
		if ( event.target.closest( '#collapse-button' ) ) {
			close();
		}
	} );
}() );
