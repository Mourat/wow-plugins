/**
 * The geometry arrives from PHP (includes/config.php) through an inline
 * script. What lives here are only the fallbacks, in case that script has
 * not run yet.
 */

const FALLBACK = {
	maxWidth: 754,
	rowHeight: 350,
	bandHeight: 97,
	defaultGap: 16,
	defaultBandLeadGap: 32,
	defaultBandImageWidth: 105,
	columnWidths: { full: 754, wide: 498, narrow: 240 },
	layouts: {
		single: [ 'full' ],
		'two-narrow-left': [ 'narrow', 'wide' ],
		'two-narrow-right': [ 'wide', 'narrow' ],
		three: [ 'narrow', 'narrow', 'narrow' ],
	},
};

export const config = { ...FALLBACK, ...( window.wowBlocksConfig || {} ) };

export const LAYOUTS = config.layouts;

/**
 * Column types of a given layout.
 *
 * @param {string} layout Layout slug.
 * @return {string[]} Column types, left to right.
 */
export function columnsFor( layout ) {
	return LAYOUTS[ layout ] || LAYOUTS.three;
}

/**
 * How many cells a layout has.
 *
 * @param {string} layout Layout slug.
 * @return {number} Number of columns.
 */
export function cellCount( layout ) {
	return columnsFor( layout ).length;
}

/** Allowed gaps, in px. */
export const GAP_CHOICES = [ 0, 8, 16, 24, 32, 48 ];
