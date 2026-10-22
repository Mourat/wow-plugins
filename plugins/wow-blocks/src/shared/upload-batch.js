/**
 * Bookkeeping for one drop.
 *
 * Core reports a batch again after every single file of it, so an image
 * already handed over would otherwise be handed over a second time. Two
 * uploaders are in the field and they disagree about the shape of that
 * report: the classic one passes the whole batch, entries gaining an id one
 * by one and dropping out of the array when they fail; the newer
 * client-side pipeline passes one finished attachment at a time.
 *
 * Hence an image's place within the drop is counted here rather than taken
 * from its index in the report, which is right under either contract.
 */

/**
 * State for one drop.
 *
 * @param {number} expected How many files were dropped.
 * @return {Object} Batch state, meant to be changed in place.
 */
export function createBatch( expected ) {
	return { expected, delivered: new Set(), failed: 0 };
}

/**
 * The finished images of a report that have not been seen before.
 *
 * @param {Object} batch  Batch state.
 * @param {*}      report Whatever core passed to onFileChange.
 * @return {Array} Objects of { media, offset }, offset 0 for the first image
 *         of the drop.
 */
export function takeFinished( batch, report ) {
	const list = Array.isArray( report ) ? report : [ report ];
	const fresh = [];

	list.forEach( ( media ) => {
		if ( ! media?.id || batch.delivered.has( media.id ) ) {
			return;
		}

		fresh.push( { media, offset: batch.delivered.size } );
		batch.delivered.add( media.id );
	} );

	return fresh;
}

/**
 * Whether nothing of this drop is still on its way.
 *
 * @param {Object} batch Batch state.
 * @return {boolean} true once every file has either arrived or failed.
 */
export function isSettled( batch ) {
	return batch.delivered.size + batch.failed >= batch.expected;
}
