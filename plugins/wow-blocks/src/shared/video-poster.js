/**
 * The first frame of a film, as a picture.
 *
 * A film in a cell has to look like a photograph before it plays: a poster
 * stands in while nothing has been fetched yet, on a browser that will not
 * play it, for anyone who has asked for less movement, and on the album tile
 * where there is no film at all. Everywhere else in this plugin a picture
 * means an attachment, with its sizes and its WebP and its AVIF, so the
 * poster is made into one.
 *
 * It is made here rather than on the server because the server cannot: it
 * has no ffmpeg, and ImageMagick without ffmpeg does not read video. The
 * browser already decodes the film to show it, so the first frame costs
 * nothing but a canvas.
 *
 * Not the very first frame, though. A film often opens on black or on the
 * fade the camera started with, and the frame a tenth of a second in is the
 * one that looks like the picture somebody meant to take.
 */

/** How far into the film the frame is taken from, in seconds. */
const INTO = 0.1;

/** How long to wait for a browser that will not decode it, in ms. */
const PATIENCE = 15000;

/**
 * Draws a frame of a film into a JPEG file.
 *
 * @param {string} url  Address of the film.
 * @param {string} name What to call the file.
 * @return {Promise<File|null>} The frame, or nothing if it could not be had.
 */
export default function posterFrom( url, name ) {
	return new Promise( ( resolve ) => {
		const film = document.createElement( 'video' );
		let settled = false;

		const give = ( file ) => {
			if ( ! settled ) {
				settled = true;
				film.removeAttribute( 'src' );
				film.load();
				resolve( file );
			}
		};

		const timer = window.setTimeout( () => give( null ), PATIENCE );

		film.muted = true;
		film.playsInline = true;
		film.preload = 'auto';
		// Films live on this site, so the canvas stays readable; said out
		// loud because a tainted canvas throws rather than returning null.
		film.crossOrigin = 'anonymous';

		film.onerror = () => {
			window.clearTimeout( timer );
			give( null );
		};

		film.onloadeddata = () => {
			// Seeking past the end of a very short film never completes.
			film.currentTime = Math.min( INTO, ( film.duration || 1 ) / 2 );
		};

		film.onseeked = () => {
			window.clearTimeout( timer );

			const canvas = document.createElement( 'canvas' );

			canvas.width = film.videoWidth;
			canvas.height = film.videoHeight;

			const paper = canvas.getContext( '2d' );

			if ( ! paper || ! canvas.width || ! canvas.height ) {
				give( null );

				return;
			}

			paper.drawImage( film, 0, 0, canvas.width, canvas.height );

			canvas.toBlob(
				( blob ) =>
					give(
						blob
							? new File( [ blob ], name, {
									type: 'image/jpeg',
								} )
							: null
					),
				'image/jpeg',
				0.92
			);
		};

		film.src = url;
	} );
}

/**
 * What to call the poster of a film.
 *
 * The film's own name with the extension taken off, so that the two sit
 * together in the media library under names that say they belong to each
 * other.
 *
 * @param {string} url Address of the film.
 * @return {string} A file name.
 */
export function posterName( url ) {
	const last = String( url ).split( '/' ).pop() || 'video';
	const stem = last.split( '?' )[ 0 ].replace( /\.[^.]+$/, '' );

	return `${ stem || 'video' }-poster.jpg`;
}
