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
