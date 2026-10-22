/**
 * The name of a picture, copied from the corner of the picture.
 *
 * Putting a photograph into a strip means finding it in the library, and
 * finding it means knowing what it is called. The name was two dialogs away:
 * open the image editor on the cell that already shows it, read the name,
 * close, open the library, type it. This is the same name, one click away,
 * on the picture itself.
 *
 * What it copies is the title the attachment is filed under, not the name of
 * the file on disk, and the difference matters here more than it sounds. A
 * picture cropped in the media editor is written out under a new file name —
 * `…-e1792671256779.webp` — while its title stays what it always was. The
 * library's search reads titles. Of four hundred attachments on this site a
 * hundred and thirty-one have the two disagreeing, every one of them a
 * cropped picture, and for all of those the file name finds nothing at all.
 */

import { Button } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import './copy-name.scss';

/** How long the button says it has done it. */
const SAID = 1500;

/**
 * Puts text on the clipboard.
 *
 * The clipboard API is the way, and it is only given to a page served from
 * somewhere trusted — https, or localhost. An editor reached over plain http
 * on a local address is neither, and that is a real way to open this site, so
 * there is the old way behind it.
 *
 * @param {string} text What to copy.
 * @return {Promise<boolean>} Whether it went.
 */
async function copy( text ) {
	try {
		await window.navigator.clipboard.writeText( text );

		return true;
	} catch {
		// Falls through to the way that works without a trusted page.
	}

	try {
		const carrier = document.createElement( 'textarea' );

		carrier.value = text;
		carrier.setAttribute( 'readonly', '' );
		carrier.style.position = 'fixed';
		carrier.style.opacity = '0';

		document.body.appendChild( carrier );
		carrier.select();

		const went = document.execCommand( 'copy' );

		document.body.removeChild( carrier );

		return went;
	} catch {
		return false;
	}
}

/*
 * The icons are drawn here rather than taken from the dashicons font. The
 * canvas is an iframe of its own, and a font that has not been given to it
 * leaves a button looking empty — which is worse than no icon at all. Two
 * sheets of paper for copying, a tick for having done it; both inherit the
 * button's colour.
 */

const Sheets = () => (
	<svg
		viewBox="0 0 24 24"
		width="18"
		height="18"
		aria-hidden="true"
		focusable="false"
	>
		<path fill="currentColor" d="M5 3h9v1.6H6.6v10.9H5V3Z" />
		<path
			fill="currentColor"
			d="M8 6.4h11V21H8V6.4Zm1.6 1.6v11.4h7.8V8H9.6Z"
		/>
	</svg>
);

const Tick = () => (
	<svg
		viewBox="0 0 24 24"
		width="18"
		height="18"
		aria-hidden="true"
		focusable="false"
	>
		<path
			fill="currentColor"
			d="m9.6 17.4-5.4-5.3 1.5-1.5 3.9 3.8 8.7-8.6 1.5 1.5L9.6 17.4Z"
		/>
	</svg>
);

/**
 * A small button over the bottom corner of a picture.
 *
 * @param {Object} props
 * @param {string} props.name The name to copy; nothing is drawn without one.
 * @return {Element|null} The button.
 */
export default function CopyName( { name } ) {
	const [ said, setSaid ] = useState( '' );
	const timer = useRef( 0 );

	useEffect( () => () => window.clearTimeout( timer.current ), [] );

	if ( ! name ) {
		return null;
	}

	const say = ( what ) => {
		setSaid( what );
		window.clearTimeout( timer.current );
		timer.current = window.setTimeout( () => setSaid( '' ), SAID );
	};

	const label = {
		done: __( 'Copied', 'wow-blocks' ),
		failed: __( 'The name could not be copied', 'wow-blocks' ),
	};

	return (
		<Button
			className="wow-copy-name"
			size="small"
			showTooltip
			label={
				said
					? label[ said ]
					: sprintf(
							/* translators: %s: the name of a picture. */
							__( 'Copy the name: %s', 'wow-blocks' ),
							name
						)
			}
			onClick={ async ( event ) => {
				// The cell underneath takes a click as "select me", which is
				// not what was meant by pressing a button on top of it.
				event.stopPropagation();

				say( ( await copy( name ) ) ? 'done' : 'failed' );
			} }
		>
			{ 'done' === said ? <Tick /> : <Sheets /> }
		</Button>
	);
}
