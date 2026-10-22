/**
 * Noticing that the image a block holds has just been deleted.
 *
 * The media modal opened from "Replace image" arrives with the block's own
 * image selected and "Delete permanently" in its sidebar. The delete goes
 * through, but nothing tells the modal to redraw: the thumbnail stays on
 * screen, a second click on it asks the server to delete something that is
 * already gone, and WordPress answers "Error in deleting the attachment." —
 * which reads as though the first delete had failed, when it had not. The
 * block, meanwhile, goes on drawing an image that no longer exists.
 *
 * So the block watches for the deletion itself, empties the cell and closes
 * the modal, which is also what clears the stale thumbnail: the next open
 * rebuilds the collection from the server.
 *
 * The one hard part is knowing a real deletion when it is seen. Opening the
 * modal makes Gutenberg fire a `destroy` for every model it is about to
 * reload — `updateCollection()` in @wordpress/media-utils — so the event
 * proves nothing by itself. Neither does the `destroyed` flag the model
 * carries: core sets it in a `done` handler attached after the one Backbone
 * uses to announce the destruction, so at the moment the event arrives the
 * flag is still unset. The server is the only thing that actually knows, so
 * the server is asked.
 */

import apiFetch from '@wordpress/api-fetch';
import { useEffect, useRef } from '@wordpress/element';

import { forgetAttachment, saysGone } from './attachment-url';

/**
 * Empties whatever holds an attachment, if that attachment is really gone.
 *
 * @param {number}   id     Attachment id, 0 when nothing is held.
 * @param {Function} onGone Called when the attachment no longer exists.
 * @return {Promise} Settles once the server has answered.
 */
export async function clearIfDeleted( id, onGone ) {
	if ( ! id ) {
		return;
	}

	try {
		await apiFetch( { path: `/wp/v2/media/${ id }?_fields=id` } );
	} catch ( error ) {
		// Anything short of "there is no such attachment" is left alone: a
		// server that is briefly unreachable must not cost anyone a block.
		if ( ! saysGone( error ) ) {
			return;
		}

		forgetAttachment( id );
		window.wp?.media?.frame?.close?.();
		onGone();
	}
}

/**
 * Calls back when the attachment a block holds is deleted from the library.
 *
 * @param {number}   id     Attachment id, 0 when the block holds no image.
 * @param {Function} onGone Empties whatever was holding the attachment.
 */
export default function useAttachmentDeleted( id, onGone ) {
	// The callback is rebuilt on every render; the subscription is not, so it
	// reads the current one rather than closing over the first.
	const latest = useRef( onGone );
	latest.current = onGone;

	useEffect( () => {
		const all = window.wp?.media?.model?.Attachments?.all;

		if ( ! id || ! all ) {
			return undefined;
		}

		let cancelled = false;
		let asking = false;

		const onDestroy = ( model ) => {
			if ( model?.id !== id || asking ) {
				return;
			}

			asking = true;

			clearIfDeleted( id, () => {
				if ( ! cancelled ) {
					latest.current();
				}
			} ).then( () => {
				asking = false;
			} );
		};

		all.on( 'destroy', onDestroy );

		return () => {
			cancelled = true;
			all.off( 'destroy', onDestroy );
		};
	}, [ id ] );
}
