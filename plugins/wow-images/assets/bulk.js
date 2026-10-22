/**
 * Following a job that is running somewhere else.
 *
 * This page does not convert anything. It asks how far along the job is, and
 * the answer comes back from the server whether or not anybody is looking.
 * The one case where it matters that this tab stays open is when the server
 * cannot call itself: the request that asks about progress then does a turn
 * of the work on the way, and says so.
 */
( function () {
	'use strict';

	var settings = window.wowImagesJob;
	var box = document.getElementById( 'wow-images-progress' );

	if ( ! settings || ! box ) {
		return;
	}

	var bar = box.querySelector( '.wow-images-bar span' );
	var count = box.querySelector( '.wow-images-done' );
	var note = box.querySelector( '.wow-images-note' );
	var quiet = 0;

	function show( answer ) {
		var total = answer.total || 1;

		count.textContent = String( answer.done );
		bar.style.width = Math.round( ( answer.done / total ) * 100 ) + '%';

		if ( answer.driving ) {
			note.textContent = '— ' + settings.text.driving;
		} else if ( answer.working ) {
			note.textContent = '— ' + settings.text.working;
			quiet = 0;
		} else if ( ++quiet > 2 ) {
			note.textContent = '— ' + settings.text.waiting;
		}
	}

	function ask() {
		var body = new FormData();
		body.append( 'action', settings.action );
		body.append( 'nonce', settings.nonce );

		window
			.fetch( settings.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body,
			} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( answer ) {
				if ( ! answer || ! answer.success ) {
					return;
				}

				// Finished, or somebody cleared it: the page is redrawn so
				// that the tally and any complaints come from the server
				// rather than being assembled twice, here and there.
				if ( answer.data.gone || answer.data.finished ) {
					window.location.reload();
					return;
				}

				show( answer.data );
				window.setTimeout( ask, 2000 );
			} )
			.catch( function () {
				// A lost request says nothing about the job, which is not
				// here. Ask again, a little later.
				window.setTimeout( ask, 5000 );
			} );
	}

	ask();
} )();
