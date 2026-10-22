/**
 * Working with the array of cells.
 *
 * The cells sit in a single array attribute rather than in InnerBlocks: the
 * editor stays responsive on long pages and the column settings never get
 * lost.
 */

import { cellCount } from './config';

/** An empty cell. */
export function emptyCell() {
	return {
		kind: 'image',
		id: 0,
		url: '',
		alt: '',
		color: '',
		focalPoint: { x: 0.5, y: 0.5 },
	};
}

/**
 * Fits the array length to the layout.
 *
 * Switching to a layout with fewer columns trims the surplus cells along
 * with their contents; switching back will not bring them back.
 *
 * @param {Array}  cells  Current cells.
 * @param {string} layout Layout slug.
 * @return {Array} An array exactly as long as the layout has columns.
 */
export function resizeCells( cells, layout ) {
	const next = Array.isArray( cells )
		? cells.slice( 0, cellCount( layout ) )
		: [];

	while ( next.length < cellCount( layout ) ) {
		next.push( emptyCell() );
	}

	return next;
}

/**
 * Returns a copy of the array with one cell replaced.
 *
 * @param {Array}  cells Current cells.
 * @param {number} index Index of the cell to change.
 * @param {Object} patch Fields to overwrite.
 * @return {Array} A new array of cells.
 */
export function replaceCell( cells, index, patch ) {
	return cells.map( ( cell, i ) =>
		i === index ? { ...cell, ...patch } : cell
	);
}

/**
 * Whether a cell is filled.
 *
 * @param {Object} cell The cell.
 * @return {boolean} true when it holds an image or a colour.
 */
export function isFilled( cell ) {
	if ( ! cell ) {
		return false;
	}

	return 'color' === cell.kind ? !! cell.color : !! ( cell.id || cell.url );
}

/**
 * Image fields taken from a media library object.
 *
 * @param {Object} media Attachment from the media library.
 * @return {Object} Cell fields.
 */
export function mediaToCell( media ) {
	return {
		kind: 'image',
		id: media.id,
		url: media.url,
		alt: media.alt || '',
	};
}

/**
 * Where an image from a drop should land.
 *
 * The first image of a drop takes the cell it was dropped on, filled or not:
 * dropping onto a cell that already holds an image replaces it, as it always
 * has. Every further image of the same drop takes the first cell to its
 * right that is still empty. The row is never rearranged and the search does
 * not wrap round: a drop fills rightwards, and what does not fit stays in
 * the media library.
 *
 * Called afresh for every image that arrives, against the cells as they are
 * at that moment, so an image naturally steps over the cell the one before
 * it has just taken.
 *
 * @param {Array}  cells   Current cells.
 * @param {number} dropped Index of the cell that took the drop.
 * @param {number} offset  Place of the image within the drop, 0 for the first.
 * @return {number} Index of the cell to fill, or -1 when none is free.
 */
export function dropTarget( cells, dropped, offset ) {
	if ( 0 === offset ) {
		return dropped < cells.length ? dropped : -1;
	}

	for ( let index = dropped + 1; index < cells.length; index++ ) {
		if ( ! isFilled( cells[ index ] ) ) {
			return index;
		}
	}

	return -1;
}
