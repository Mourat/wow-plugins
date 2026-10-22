/**
 * The block's own attributes at the moment of writing, not at render time.
 *
 * The `attributes` prop is a snapshot of one render. A callback created when
 * a file is dropped and fired when the upload ends still holds the snapshot
 * taken on the drop, so two uploads racing each other both rebuild the cells
 * from before either of them started and the first image disappears again.
 *
 * Going through the store gives the value as it is now: updateBlockAttributes
 * is a plain action object, so the store has already changed by the time the
 * previous write returns.
 *
 * A write can also say that it is not a step of its own. Some of them only
 * bring the attributes back into agreement with each other — trimming the
 * cells after the layout lost a column, say — and such a write must join the
 * change that caused it rather than stand in the history beside it.
 * Otherwise undo takes back the tidying, leaves the cause in place, and the
 * tidying happens again: nothing moves on screen, a fresh entry is pushed,
 * the redo stack is thrown away, and from then on nothing can be undone at
 * all.
 */

import { store as blockEditorStore } from '@wordpress/block-editor';
import { useRegistry } from '@wordpress/data';
import { useMemo } from '@wordpress/element';

/**
 * Reading and writing this block's attributes outside a render.
 *
 * useRegistry rather than the global select: a nested editor, such as a
 * template part inside the site editor, runs on a registry of its own.
 *
 * updateBlockAttributes rather than setAttributes: core's setAttributes
 * writes to every multi-selected block, which is right for a click on a
 * control and wrong for an upload that finishes on its own.
 *
 * @param {string} clientId Block client id.
 * @return {{read: Function, write: Function}} Attribute access. read()
 *         returns null once the block is gone, so an upload finishing after
 *         the block was deleted quietly comes to nothing. write() takes an
 *         optional `{ ownStep: false }` for a change that belongs to the one
 *         before it.
 */
export default function useBlockAttributes( clientId ) {
	const registry = useRegistry();

	return useMemo(
		() => ( {
			read: () =>
				registry
					.select( blockEditorStore )
					.getBlockAttributes( clientId ),
			write: ( patch, { ownStep = true } = {} ) => {
				const dispatch = registry.dispatch( blockEditorStore );

				/*
				 * The only way core offers of saying "fold this into the
				 * previous step". Unstable in name for years, and what every
				 * core block with a derived attribute uses.
				 */
				if ( ! ownStep ) {
					dispatch.__unstableMarkNextChangeAsNotPersistent();
				}

				dispatch.updateBlockAttributes( clientId, patch );
			},
		} ),
		[ registry, clientId ]
	);
}
