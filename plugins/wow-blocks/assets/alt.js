/**
 * Filling in alternative text a few hundred pictures at a time.
 *
 * The work itself takes a moment, but "a moment" is not a thing a screen can
 * show. So the page asks for a batch, draws how far it has got, and asks
 * again — and anybody watching sees that the site is working rather than
 * wondering whether it has died.
 *
 * Without this script the form below still posts and still does the whole
 * job in one request. The bar is an improvement on that, not a condition of
 * it.
 */
( function () {
	'use strict';

	var settings = window.wowBlocksAlt;
	var form = document.getElementById( 'wow-blocks-alt-form' );

	if ( ! settings || ! form ) {
		return;
	}

	var box = document.getElementById( 'wow-blocks-alt-progress' );
	var bar = box.querySelector( '.wow-blocks-bar span' );
	var tally = box.querySelector( '.wow-blocks-tally' );
	var total = parseInt( box.dataset.total, 10 ) || 0;
	var done = 0;

	function draw() {
		bar.style.width = ( total ? Math.round( ( done / total ) * 100 ) : 100 ) + '%';
		tally.textContent = settings.text.counting
			.replace( '%1$s', done )
			.replace( '%2$s', total );
	}

	function step() {
		window
			.fetch( settings.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body:
					'action=' +
					encodeURIComponent( settings.action ) +
					'&_ajax_nonce=' +
					encodeURIComponent( settings.nonce ),
			} )
			.then( function ( answer ) {
				return answer.json();
			} )
			.then( function ( answer ) {
				if ( ! answer || ! answer.success ) {
					throw new Error( 'refused' );
				}

				done += answer.data.written;
				draw();

				if ( answer.data.left > 0 && answer.data.written > 0 ) {
					step();

					return;
				}

				// Nothing left, or nothing written and so nothing to gain
				// from asking again: show the page as it now stands.
				window.location = settings.doneUrl.replace(
					'%d',
					String( done )
				);
			} )
			.catch( function () {
				tally.textContent = settings.text.failed;
			} );
	}

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();
		form.querySelector( 'input[type="submit"]' ).disabled = true;
		box.hidden = false;
		draw();
		step();
	} );
} )();
