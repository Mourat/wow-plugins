/**
 * Draws the QR code for an authenticator application.
 *
 * The address is already on the page — it has to be, so that somebody without
 * a camera can read the secret and type it in — and this only turns it into a
 * square. Nothing is sent anywhere: the drawing happens here, in the browser,
 * from the text that is already in the document.
 */
( function () {
	'use strict';

	var box = document.getElementById( 'wow-2fa-qr' );

	if ( ! box || typeof window.qrcode !== 'function' ) {
		return;
	}

	var uri = box.getAttribute( 'data-uri' );

	if ( ! uri ) {
		return;
	}

	try {
		// Error correction level L: the picture is read from a few
		// centimetres away on a clean screen, not off a crumpled label, and
		// the lowest level keeps the squares large and easy to focus on.
		var qr = window.qrcode( 0, 'L' );
		qr.addData( uri );
		qr.make();

		box.innerHTML = qr.createSvgTag( 5, 8 );

		var svg = box.querySelector( 'svg' );

		if ( svg ) {
			svg.setAttribute( 'role', 'img' );
			svg.style.background = '#fff';
			svg.style.maxWidth = '220px';
			svg.style.height = 'auto';
		}
	} catch ( e ) {
		// A picture that cannot be drawn is not a problem worth a broken
		// page: the secret is printed underneath it in words.
		box.textContent = '';
	}
} )();
