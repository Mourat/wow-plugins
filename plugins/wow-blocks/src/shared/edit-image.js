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
 * Waits until the editor has stopped working.
 *
 * Every step core takes goes to the server and comes back — the crop is
 * applied by one request and the preview redrawn from its answer. While
 * that is happening it shows a waiting panel, so the panel is the signal:
 * gone means done.
 *
 * @param {number}   id   Attachment id.
 * @param {Function} then What to do once it is idle.
 */
function whenIdle( id, then ) {
	const until = Date.now() + 15000;

	const look = () => {
		const wait = document.getElementById( `imgedit-wait-${ id }` );
		const busy = wait && null !== wait.offsetParent;

		if ( busy && Date.now() < until ) {
			window.setTimeout( look, 100 );

			return;
		}

		then();
	};

	// One turn of the loop first: the waiting panel is raised inside the
	// click that starts the work, not before it.
	window.setTimeout( look, 60 );
}

/**
 * Crop, save, and be done with it.
 *
 * Core's editor takes three presses to put a cropped picture back in a
 * block: apply the crop, save the edits, and then — because saving returns
 * to the library rather than closing — choose the picture again. The block
 * already holds this attachment and wants nothing else.
 *
 * So the same two buttons are pressed, in the same order, waiting in
 * between exactly as a person waits for the picture to redraw. Pressed,
 * not reached around: an earlier version called the functions behind them
 * one after the other, which sent two requests about the same picture at
 * once and sometimes saved a crop from the wrong place. A button does more
 * than call a function — it does it at a moment when the editor is ready
 * for it.
 *
 * What is not a press is the last step. Saving returns to the library,
 * where the third press used to be; instead the attachment is handed to
 * the block and the frame closed.
 *
 * @param {number}   id      Attachment id.
 * @param {Object}   frame   The media frame.
 * @param {Function} onSaved Hands the block the picture as it now is.
 */
function quickSave( id, frame, onSaved ) {
	const api = window.imageEdit;
	const panel = document.getElementById( `imgedit-panel-${ id }` );

	if ( ! panel ) {
		return;
	}

	const press = ( one ) => {
		if ( one && ! one.disabled ) {
			one.click();

			return true;
		}

		return false;
	};

	const save = () => {
		const view = api?._view;
		const original = view?.save;

		if ( view ) {
			view.save = function () {
				view.save = original;

				this.model.fetch().done( () => {
					const saved = this.model.toJSON();

					forgetAttachment( id );
					onSaved?.( saved );

					frame.close();
				} );
			};
		}

		if ( ! press( panel.querySelector( 'button.imgedit-submit-btn' ) ) ) {
			// Nothing to save: no crop, no rotation, nothing.
			if ( view ) {
				view.save = original;
			}

			frame.close();
		}
	};

	// A chosen crop is applied first, by its own button, and the saving
	// waits for the picture to come back redrawn.
	if (
		hasCrop( id ) &&
		press(
			panel.querySelector(
				'.imgedit-crop-apply button:not(.imgedit-crop-clear)'
			)
		)
	) {
		whenIdle( id, save );

		return;
	}

	save();
}

/**
 * Puts the button in core's own toolbar, once it has one.
 *
 * The editor arrives over the network after the state changes, so there is
 * nothing to put it beside until it does. Watching beats guessing at a
 * delay, and the watch gives up rather than running for ever.
 *
 * @param {number}   id      Attachment id.
 * @param {Object}   frame   The media frame.
 * @param {Function} onSaved Hands the block the picture as it now is.
 */
function addQuickSave( id, frame, onSaved ) {
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
			quickSave( id, frame, onSaved )
		);

		bar.appendChild( button );
		follow( id, button );
		openCropTool( id );
	};

	look();
}

/**
 * Presses Crop for the person, because that is what they came for.
 *
 * The editor opens with its tools folded away and the handles for choosing
 * an area appear only once Crop is pressed. Nobody reaches this editor from
 * a block for any other reason — the way in is a button on a picture that is
 * already placed — so the press is a formality, and one press of a
 * formality is one too many.
 *
 * Waited for rather than done at once: core renders that button disabled and
 * enables it when the picture has loaded and it knows the size it is working
 * with. Pressed through core's own handler, not by setting attributes, so
 * everything that follows from a real press follows from this one too.
 *
 * @param {number} id Attachment id.
 */
function openCropTool( id ) {
	const until = Date.now() + 10000;

	const look = () => {
		if ( Date.now() > until ) {
			return;
		}

		const crop = document.querySelector(
			`#imgedit-panel-${ id } button.imgedit-crop`
		);

		if ( ! crop || crop.disabled ) {
			window.setTimeout( look, 120 );

			return;
		}

		/*
		 * Open already, or something is selected: either way, hands off.
		 *
		 * Pressing this button a second time closes the tool and a third
		 * opens it again, and opening it is not the harmless act it looks.
		 * Core rebuilds the selection from the number fields beside the
		 * picture — and those fields are never written while a selection is
		 * dragged, only reset to zero. A reopened selection therefore keeps
		 * its size and moves to the top left corner, so what gets cropped is
		 * the left of the picture when the right was chosen.
		 *
		 * This press is meant to save the person the first one, not to race
		 * them for it. If they got there first, there is nothing to do.
		 */
		if (
			'true' === crop.getAttribute( 'aria-expanded' ) ||
			document.getElementById( `imgedit-selection-${ id }` )?.value
		) {
			return;
		}

		crop.click();
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

		tellTheTruth( id );
	}, 250 );
}

/**
 * Keeps the two number fields saying where the selection actually is.
 *
 * Core writes the width and the height of a dragged selection into their
 * fields and leaves the starting coordinates alone — they are only ever
 * reset to zero, never filled in. So the panel says a selection starts at
 * the top left corner while it plainly does not, and worse, core believes
 * it: reopening the crop tool rebuilds the selection from these fields and
 * drops it into the corner, keeping its size.
 *
 * Filling them in is a line of arithmetic the selection already carries,
 * and it makes both the panel and core right about the same thing. A field
 * being typed into is left alone, because somebody typing a number means it
 * more than this does.
 *
 * @param {number} id Attachment id.
 */
function tellTheTruth( id ) {
	const held = document.getElementById( `imgedit-selection-${ id }` )?.value;

	if ( ! held ) {
		return;
	}

	let selection;

	try {
		selection = JSON.parse( held );
	} catch {
		return;
	}

	[
		[ `imgedit-start-x-${ id }`, selection.x ],
		[ `imgedit-start-y-${ id }`, selection.y ],
	].forEach( ( [ which, value ] ) => {
		const field = document.getElementById( which );

		if (
			! field ||
			field === field.ownerDocument.activeElement ||
			! Number.isFinite( value )
		) {
			return;
		}

		const rounded = String( Math.round( value ) );

		if ( field.value !== rounded ) {
			field.value = rounded;
		}
	} );
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
 * @param {number}   id      Attachment id.
 * @param {Function} onSaved Hands the block the picture as it now is.
 */
async function editInFrame( id, onSaved ) {
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

	addQuickSave( id, frame, onSaved );
}

/**
 * Opens WordPress's image editor for the attachment a block holds.
 *
 * @param {Object}   props
 * @param {number}   props.id      Attachment id; nothing is rendered without one.
 * @param {Function} props.open    Opens the media frame, from MediaUpload.
 * @param {Function} props.onSaved Hands the block the picture as it now is.
 * @return {Element|null} The button.
 */
export default function EditImageButton( { id, open, onSaved } ) {
	if ( ! id ) {
		return null;
	}

	return (
		<Button
			className="wow-edit-image"
			variant="secondary"
			onClick={ () => {
				open();
				editInFrame( id, onSaved );
			} }
		>
			{ __( 'Edit image', 'wow-blocks' ) }
		</Button>
	);
}
