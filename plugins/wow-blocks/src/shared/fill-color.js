/**
 * The colour a fill takes from the pictures beside it.
 *
 * A fill in a row is not decoration in its own right: it is the quiet space
 * next to a photograph, and the colour that belongs there is the one the
 * photograph is made of. Pressing "Fill" therefore does not produce a flat
 * grey to be corrected afterwards — it looks left and right and takes what
 * it finds.
 *
 * At the edge of a row there is one neighbour and its colour is the answer.
 * Between two pictures there are two, and the answer is halfway between
 * them: the fill sits between the two photographs on the page, so it should
 * sit between them in colour as well.
 *
 * Only the immediate neighbours are asked, and only if they hold a picture.
 * A fill between two other fills has nothing to take from and keeps what it
 * had.
 */

import averageColor from './average-color';
import { knownUrl } from './attachment-url';
import { config } from './config';

/**
 * Halfway between a set of colours.
 *
 * @param {string[]} colors Hex colours.
 * @return {string} One hex colour.
 */
function mix( colors ) {
	const parts = colors.map( ( color ) => [
		parseInt( color.slice( 1, 3 ), 16 ),
		parseInt( color.slice( 3, 5 ), 16 ),
		parseInt( color.slice( 5, 7 ), 16 ),
	] );

	const channel = ( at ) =>
		Math.round(
			parts.reduce( ( sum, part ) => sum + part[ at ], 0 ) / parts.length
		)
			.toString( 16 )
			.padStart( 2, '0' );

	return `#${ channel( 0 ) }${ channel( 1 ) }${ channel( 2 ) }`;
}

/**
 * The colour for the fill in one cell of a row.
 *
 * @param {Array}    cells   The row's cells.
 * @param {string[]} columns Column types of the layout, left to right.
 * @param {number}   index   Which cell is being filled.
 * @return {Promise<string>} A hex colour, or an empty string when there is
 *                           no picture next to it.
 */
export default async function fillColor( cells, columns, index ) {
	const found = [];

	for ( const at of [ index - 1, index + 1 ] ) {
		const cell = at >= 0 && at < columns.length ? cells[ at ] : null;

		if ( ! cell || 'image' !== cell.kind ) {
			continue;
		}

		// The address as it is now where that is known: a picture cropped
		// since the block was saved lives under a new name, and the old one
		// would be read for a colour it no longer has.
		const url = knownUrl( cell.id ) || cell.url;

		if ( ! url ) {
			continue;
		}

		const color = await averageColor( {
			url,
			focal: cell.focalPoint,
			width: config.columnWidths[ columns[ at ] ] || config.maxWidth,
			height: config.rowHeight,
		} );

		if ( color ) {
			found.push( color );
		}
	}

	if ( ! found.length ) {
		return '';
	}

	return 1 === found.length ? found[ 0 ] : mix( found );
}
