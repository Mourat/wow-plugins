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
		button.disabled = true;
		button.addEventListener( 'click', () =>
			quickSave( id, nonce, frame, button )
		);

		bar.appendChild( button );
		follow( id, button );
	};

	look();
}

/**
 * Keeps the button dim until there is something for it to do.
 *
 * Two questions, asked the way core asks them itself.
 *
 * Is there a crop worth applying? Not simply "is there a selection": core
 * opens the crop tool by selecting the whole picture when nothing is
 * selected yet, so a selection exists half a second after the editor opens
 * and before anybody has drawn anything. A selection covering the entire
 * image is a crop that would change nothing, which is the same as none.
 * What is read is the width and height of the selection against the size
 * of the image, both of which core keeps at full scale, with a couple of
 * pixels of slack for the rounding between the two.
 *
 * (Nor is it the state of the Apply Crop button beside it: the class core
 * dims is on the div wrapping that button, and a div has no disabled
 * property — asking one returns undefined, which reads as "go ahead".)
 *
 * Is there anything else worth saving — a rotation, a resize? Core's Save
 * Edits button is a button, it is disabled until there is, and it can be
 * read as it looks.
 *
 * Read rather than listened to, because the selection changes inside core's
 * own drag handling, which announces nothing. Four times a second is far
 * below noticing and costs a handful of lookups.
 *
 * @param {number} id     Attachment id.
 * @param {Object} button The button to dim.
 */
function follow( id, button ) {
	const panel = document.getElementById( `imgedit-panel-${ id }` );

	if ( ! panel ) {
		return;
	}

	const watch = window.setInterval( () => {
		// Gone from the page: the editor was closed, and so is this.
		if ( ! button.isConnected || ! panel.isConnected ) {
			window.clearInterval( watch );

			return;
		}

		const save = panel.querySelector( 'button.imgedit-submit-btn' );

		button.disabled = ! hasCrop( id ) && ( ! save || save.disabled );
	}, 250 );
}

/**
 * Whether a crop has been chosen that would change anything.
 *
 * @param {number} id Attachment id.
 * @return {boolean} true when the selection is a real crop.
 */
function hasCrop( id ) {
	const number = ( of ) => {
		const value = parseInt( document.getElementById( of )?.value, 10 );

		return Number.isNaN( value ) ? 0 : value;
	};

	if ( ! document.getElementById( `imgedit-selection-${ id }` )?.value ) {
		return false;
	}

	const width = number( `imgedit-sel-width-${ id }` );
	const height = number( `imgedit-sel-height-${ id }` );

	if ( width < 1 || height < 1 ) {
		return false;
	}

	// The size of the picture itself, as core holds it.
	const whole = window.imageEdit?.hold || {};
	const slack = 2;

	const everything =
		number( `imgedit-start-x-${ id }` ) <= 0 &&
		number( `imgedit-start-y-${ id }` ) <= 0 &&
		width + slack >= ( whole.w || 0 ) &&
		height + slack >= ( whole.h || 0 );

	return ! everything;
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
