/**
 * Accepting images by drag and drop.
 *
 * Between sites the browser hands over a link rather than a file:
 * `dataTransfer.files` is empty and all there is is `text/html` or
 * `text/uri-list`. Hence the two branches. Files from disk go to
 * `mediaUpload`, links go to a server-side sideload (`POST /wp/v2/media`
 * with a `url` parameter, added in WP 7.1). Sideloading also settles the
 * CORS question: the site fetches the image, not the browser.
 *
 * A link to a picture already in this library takes a third road and is
 * copied on disk instead — see `includes/duplicate.php` for why fetching
 * our own picture over HTTP is both fragile and wrong about which file it
 * ends up with.
 */

import apiFetch from '@wordpress/api-fetch';
import {
	MediaUploadCheck,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import { DropZone, Spinner } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { mediaToCell } from './cells';
import { createBatch, isSettled, takeFinished } from './upload-batch';
import './media-drop.scss';

/**
 * Whether a URL is fit for a server-side sideload.
 *
 * Filters out relative addresses and `data:`. Core accepts public http(s)
 * only anyway, but the rest would fail after the round trip rather than
 * before it.
 *
 * @param {string} value URL to check.
 * @return {boolean} true for an absolute http(s) URL.
 */
function isRemoteHTTP( value ) {
	try {
		const { protocol } = new window.URL( value );

		return 'http:' === protocol || 'https:' === protocol;
	} catch {
		return false;
	}
}

/**
 * Whether a URL points at the site being edited.
 *
 * Only the origin is compared. Anything inside it that is not an attachment
 * falls through to the ordinary sideload a moment later, so being wrong here
 * costs a round trip and nothing else.
 *
 * @param {string} value URL to check.
 * @return {boolean} true when it is this very site.
 */
function isOwnSite( value ) {
	try {
		return (
			new window.URL( value, window.location.href ).origin ===
			window.location.origin
		);
	} catch {
		return false;
	}
}

/**
 * The image URL out of an HTML fragment.
 *
 * @param {string} html Fragment from `dataTransfer`.
 * @return {string} The image URL, or an empty string.
 */
function imageSrcFromHTML( html ) {
	const doc = new window.DOMParser().parseFromString( html, 'text/html' );

	// getAttribute rather than the .src property: the property would resolve
	// a relative address against the editor's own URL and send the request
	// to our own site.
	return doc.querySelector( 'img' )?.getAttribute( 'src' )?.trim() || '';
}

/**
 * The first URL out of a text/uri-list payload.
 *
 * Lines starting with a hash are comments, per the format.
 *
 * @param {string} uriList Contents of `text/uri-list`.
 * @return {string} The first URL, or an empty string.
 */
function firstURI( uriList ) {
	return (
		( uriList || '' )
			.split( /[\r\n]+/ )
			.map( ( line ) => line.trim() )
			.find( ( line ) => line && ! line.startsWith( '#' ) ) || ''
	);
}

/**
 * A drop target laid over a cell.
 *
 * Handles both files from disk and images dragged in from another tab. The
 * outcome is the same either way: a finished attachment in the media library.
 *
 * @param {Object}   props
 * @param {Function} props.onMedia Called once per finished image, with the
 *                                 cell fields and the image's place within
 *                                 the drop.
 * @param {Function} props.onStart Called when a drop is accepted, before
 *                                 anything has been uploaded.
 * @return {Element} The drop target.
 */
export default function MediaDropZone( { onMedia, onStart } ) {
	// null when idle, 'busy' while uploading, a string for an error message.
	const [ status, setStatus ] = useState( null );

	// Drops are allowed to overlap, so the spinner follows a count of the
	// drops still running rather than a single flag: finishing the first of
	// two must not clear it.
	const running = useRef( 0 );

	const mediaUpload = useSelect(
		( select ) => select( blockEditorStore ).getSettings().mediaUpload,
		[]
	);

	const begin = () => {
		running.current += 1;
		setStatus( 'busy' );
		onStart?.();
	};

	// An error message stays on screen: it says more than a cleared spinner.
	const end = () => {
		running.current = Math.max( 0, running.current - 1 );

		if ( 0 === running.current ) {
			setStatus( ( current ) => ( 'busy' === current ? null : current ) );
		}
	};

	const fail = ( message ) =>
		setStatus(
			message || __( 'The image could not be uploaded.', 'wow-blocks' )
		);

	const handleFiles = ( files ) => {
		if ( ! mediaUpload ) {
			fail();

			return;
		}

		const batch = createBatch( files.length );
		let closed = false;

		const close = () => {
			if ( ! closed ) {
				closed = true;
				end();
			}
		};

		begin();
		mediaUpload( {
			allowedTypes: [ 'image' ],
			filesList: files,
			// The first reports carry temporary blob URLs; an entry gains an
			// id once its own upload is over. Every image is passed on once,
			// with its place in the drop, so three files can fill three
			// cells instead of only the first one being kept.
			onFileChange: ( report ) => {
				takeFinished( batch, report ).forEach( ( { media, offset } ) =>
					onMedia( mediaToCell( media ), offset )
				);

				if ( isSettled( batch ) ) {
					close();
				}
			},
			// Core calls this once for every file it will not take.
			onError: ( message ) => {
				batch.failed += 1;
				fail( message );

				if ( isSettled( batch ) ) {
					close();
				}
			},
		} );
	};

	const handleURL = async ( url ) => {
		if ( ! isRemoteHTTP( url ) ) {
			fail(
				__( 'That is a link to a page, not an image.', 'wow-blocks' )
			);

			return;
		}

		begin();

		try {
			let attachment = null;

			if ( isOwnSite( url ) ) {
				try {
					attachment = await apiFetch( {
						path: '/wow-blocks/v1/duplicate',
						method: 'POST',
						data: { url },
					} );
				} catch {
					// Our own address, but not one of our pictures. The
					// ordinary sideload is still allowed its try.
					attachment = null;
				}
			}

			if ( ! attachment ) {
				attachment = await apiFetch( {
					path: '/wp/v2/media',
					method: 'POST',
					data: { url },
				} );
			}

			// A link carries one image, so it is always first of its drop.
			onMedia(
				mediaToCell( {
					id: attachment.id,
					url: attachment.source_url,
					alt: attachment.alt_text,
				} ),
				0
			);
		} catch ( error ) {
			// Another site may well answer 403 to a request without a
			// Referer. Show the reason instead of staying silent.
			fail( error?.message );
		} finally {
			end();
		}
	};

	return (
		<MediaUploadCheck>
			<DropZone
				onFilesDrop={ handleFiles }
				onHTMLDrop={ ( html ) => handleURL( imageSrcFromHTML( html ) ) }
				onDrop={ ( event ) =>
					handleURL(
						firstURI(
							event.dataTransfer?.getData( 'text/uri-list' ) ||
								event.dataTransfer?.getData( 'text/plain' )
						)
					)
				}
			/>

			{ 'busy' === status && (
				<div className="wow-drop-status">
					<Spinner />
				</div>
			) }

			{ status && 'busy' !== status && (
				<button
					type="button"
					className="wow-drop-status is-error"
					onClick={ () => setStatus( null ) }
				>
					{ status }
				</button>
			) }
		</MediaUploadCheck>
	);
}
