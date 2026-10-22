/**
 * A way into WordPress's own image editor, from the block that holds the
 * image.
 *
 * Cropping and rotating live in core's image editor, and the only route there
 * used to be the long way round: replace image, find in the library the one
 * already chosen, "Edit image", come back. The button goes straight there.
 *
 * It rides on the same frame "Replace image" opens, which is why it is handed
 * that button's `open`. Core's `MediaFrame.Select` already carries an
 * `edit-image` state and knows how to render it — it is what the link in the
 * modal's own sidebar uses — so there is nothing to build: open the frame,
 * put the attachment in that state, and switch to it. Whatever the frame
 * cannot do, it simply stays open on the library, which is where the long way
 * round starts anyway.
 *
 * The model is fetched first. The editor is opened with the attachment's
 * `edit` nonce, and a model taken by id alone holds nothing but the id.
 *
 * A crop comes back under a new name, so when the modal closes whatever was
 * known about the attachment is thrown away and the cell asks again.
 */

import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { forgetAttachment } from './attachment-url';

import './edit-image.scss';

/**
 * Crop, save, and be done with it.
 *
 * Core's editor takes three presses to put a cropped picture back in a
 * block: apply the crop, save the edits, and then — because saving returns
 * to the library rather than closing — choose the picture again. The block
 * already holds this attachment and wants nothing else; the three presses
 * are the long way round to where it already was.
 *
 * All three are core's own steps, in core's own order. imageEdit.crop()
 * queues the selection, imageEdit.save() posts it, and what normally
 * happens next — the editor view fetching the attachment afresh and going
 * back to the library — is replaced for this one press by fetching it and
 * closing instead. The attachment is still fetched, because the new file
 * has a new name and whatever is listening needs to hear it.
 *
 * @param {number} id     Attachment id.
 * @param {string} nonce  Edit nonce for it.
 * @param {Object} frame  The media frame.
 * @param {Object} button The button that was pressed.
 */
function quickSave( id, nonce, frame, button ) {
	const api = window.imageEdit;
	const selection = document.getElementById( `imgedit-selection-${ id }` );

	// A selection not yet applied is what the person is looking at, so it
	// is what they mean. Without one there is nothing to add, and whatever
	// was done earlier is saved as it stands.
	if ( selection?.value ) {
		api.crop( id, nonce, button );
	}

	const view = api._view;
	const original = view?.save;

	if ( view ) {
		view.save = function () {
			view.save = original;

			this.model.fetch().done( () => frame.close() );
		};
	}

	// False means there was nothing to save: no crop, no rotation, nothing.
	if ( false === api.save( id, nonce ) ) {
		if ( view ) {
			view.save = original;
		}

		frame.close();
	}
}

/**
 * Puts the button in core's own toolbar, once it has one.
 *
 * The editor arrives over the network after the state changes, so there is
 * nothing to put it beside until it does. Watching beats guessing at a
 * delay, and the watch gives up rather than running for ever.
 *
 * @param {number} id    Attachment id.
 * @param {string} nonce Edit nonce for it.
 * @param {Object} frame The media frame.
 */
function addQuickSave( id, nonce, frame ) {
	const until = Date.now() + 10000;

	const look = () => {
		if ( Date.now() > until || 'edit-image' !== frame.state()?.id ) {
			return;
		}

		const bar = document.querySelector(
			`#imgedit-panel-${ id } .imgedit-submit.imgedit-menu`
		);

		if ( ! bar ) {
			window.setTimeout( look, 120 );

			return;
		}

		if ( bar.querySelector( '.wow-quick-save' ) ) {
			return;
		}

		const button = document.createElement( 'button' );

		button.type = 'button';
		button.className = 'button button-primary wow-quick-save';
		button.textContent = __( 'Quick save', 'wow-blocks' );
		button.addEventListener( 'click', () =>
			quickSave( id, nonce, frame, button )
		);

		bar.appendChild( button );
	};

	look();
}

/**
 * Puts an already open media frame into its image-editing state.
 *
 * @param {number} id Attachment id.
 */
async function editInFrame( id ) {
	const { wp } = window;
	const frame = wp?.media?.frame;
	const state = frame?.states?.get?.( 'edit-image' );

	if ( ! state || ! window.imageEdit ) {
		return;
	}

	const model = wp.media.attachment( id );

	await model.fetch();

	state.set( 'image', model );
	frame.setState( 'edit-image' );
	frame.once( 'close', () => forgetAttachment( id ) );

	addQuickSave( id, model.get( 'nonces' )?.edit, frame );
}

/**
 * Opens WordPress's image editor for the attachment a block holds.
 *
 * @param {Object}   props
 * @param {number}   props.id   Attachment id; nothing is rendered without one.
 * @param {Function} props.open Opens the media frame, from MediaUpload.
 * @return {Element|null} The button.
 */
export default function EditImageButton( { id, open } ) {
	if ( ! id ) {
		return null;
	}

	return (
		<Button
			className="wow-edit-image"
			variant="secondary"
			onClick={ () => {
				open();
				editInFrame( id );
			} }
		>
			{ __( 'Edit image', 'wow-blocks' ) }
		</Button>
	);
}
