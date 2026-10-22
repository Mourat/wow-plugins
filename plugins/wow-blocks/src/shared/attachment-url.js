/**
 * The address of an attachment as it is now, not as the block remembers it.
 *
 * A block keeps the URL it was handed when the image was placed. Crop that
 * image in the media editor afterwards and WordPress writes a new file under
 * a new name: the front end renders the attachment by id, so it follows,
 * while the editor went on drawing the old file from the saved URL. The two
 * then showed different pictures, and framing set against the one on the
 * canvas landed somewhere else on the page.
 *
 * The value saved in the block still wins whenever it changes while the
 * editor is open, because that only happens when the media modal hands over a
 * picture the person just chose, which is newer than anything asked for here.
 *
 * The same question answers a second one: whether the attachment is still
 * there at all. A deleted image leaves the id and the URL sitting in the
 * block, and the saved URL goes on being drawn until someone notices a white
 * gap on the published page. Told outright that the attachment is gone, the
 * cell says so instead.
 */

import apiFetch from '@wordpress/api-fetch';
import { useEffect, useRef, useState } from '@wordpress/element';

/** Addresses already fetched, so a row of cells asks the once. */
const known = new Map();

/** Ids WordPress has answered for with "no such attachment". */
const gone = new Set();

/**
 * Hooks waiting to hear that a note has been torn up.
 *
 * Forgetting an address has to reach the cells already on screen: an image
 * cropped in the editor comes back under a new name, and the cell drawing the
 * old one has no other way of finding out.
 */
const listeners = new Set();

/**
 * Whether a failed read means the attachment no longer exists.
 *
 * Only this one answer is taken as proof. Offline, a server error, or a
 * permission the person happens to lack are all failures too, and none of
 * them is a reason to tell someone their image has disappeared.
 *
 * @param {Object} error Rejection from apiFetch.
 * @return {boolean} true when WordPress said there is no such attachment.
 */
export function saysGone( error ) {
	return (
		'rest_post_invalid_id' === error?.code || 404 === error?.data?.status
	);
}

/**
 * Current state of an attachment.
 *
 * @param {number} id    Attachment id, 0 when the cell holds no image.
 * @param {string} saved URL stored in the block.
 * @return {{url: string, missing: boolean}} Address to draw, and whether the
 *                                           attachment is known to be gone.
 */
export default function useAttachment( id, saved ) {
	const initial = useRef( saved );
	const [ fetched, setFetched ] = useState( () => known.get( id ) );
	const [ missing, setMissing ] = useState( () => gone.has( id ) );
	const [ asked, setAsked ] = useState( 0 );

	useEffect( () => {
		const onForget = ( forgotten ) => {
			if ( forgotten === id ) {
				setAsked( ( count ) => count + 1 );
			}
		};

		listeners.add( onForget );

		return () => listeners.delete( onForget );
	}, [ id ] );

	useEffect( () => {
		if ( ! id ) {
			setFetched( undefined );
			setMissing( false );

			return undefined;
		}

		if ( known.has( id ) || gone.has( id ) ) {
			setFetched( known.get( id ) );
			setMissing( gone.has( id ) );

			return undefined;
		}

		let cancelled = false;

		apiFetch( { path: `/wp/v2/media/${ id }?_fields=source_url` } )
			.then( ( media ) => {
				if ( ! media?.source_url ) {
					return;
				}

				known.set( id, media.source_url );

				if ( ! cancelled ) {
					setFetched( media.source_url );
				}
			} )
			.catch( ( error ) => {
				// Any other failure leaves the saved URL in place: it is the
				// best guess available, and the cell keeps showing something.
				if ( ! saysGone( error ) ) {
					return;
				}

				gone.add( id );

				if ( ! cancelled ) {
					setMissing( true );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [ id, asked ] );

	if ( saved !== initial.current ) {
		return { url: saved, missing: false };
	}

	return { url: fetched || saved, missing };
}

/**
 * Forgets what is known about an attachment.
 *
 * Called when one is deleted from under a block, so that a later id of the
 * same number — or a retry after the server was briefly unreachable — is
 * asked for afresh rather than answered from a stale note.
 *
 * @param {number} id Attachment id.
 */
export function forgetAttachment( id ) {
	known.delete( id );
	gone.delete( id );

	listeners.forEach( ( listener ) => listener( id ) );
}
