/**
 * The one thing the blocks have to say for themselves.
 */

import { useDispatch } from '@wordpress/data';
import { useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Says that an uploaded image was not placed in the block.
 *
 * Silence is what made this confusing in the first place: the file reached
 * the media library, the block did not change, and nothing explained why.
 *
 * One fixed notice id, so a drop of ten images into a full row says it once
 * rather than ten times.
 *
 * @return {Function} Shows the notice.
 */
export default function useLibraryOnlyNotice() {
	const { createNotice } = useDispatch( noticesStore );

	return useCallback(
		() =>
			createNotice(
				'info',
				__(
					'The image went to the media library only: there was no free place for it here.',
					'wow-blocks'
				),
				{
					id: 'wow-blocks/library-only',
					type: 'snackbar',
					isDismissible: true,
				}
			),
		[ createNotice ]
	);
}
