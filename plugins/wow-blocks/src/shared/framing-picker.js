/**
 * The focal point picker, kept inside the bounds it can actually honour.
 *
 * Core lets the handle be dragged to the very corner of the image, where two
 * things go wrong at once: the ring hangs half outside the picture, and the
 * frame cannot follow it — there is nothing beyond the edge to put in the
 * middle, so the render clamps and the crosshair ends up pointing at
 * something other than what it covers.
 *
 * Two insets are applied, and the stricter one wins on each axis:
 *
 * - half the handle, so the ring stops with its edge against the picture;
 * - half the visible frame (`0.5 / scale`), so whatever the crosshair covers
 *   really can sit in the middle of the strip.
 *
 * Nothing already stored is rewritten. Values saved before this existed keep
 * their framing until the handle is moved, and only then snap into range.
 *
 * The anchor grid above the picture is exempt from the handle inset: it is
 * there precisely to put an edge of the picture against an edge of the cell,
 * and unlike a dragged handle the render can honour that exactly. Only the
 * frame inset, which is about what the render can reach at all, still applies.
 */

import {
	AlignmentMatrixControl,
	BaseControl,
	FocalPointPicker,
	useBaseControlProps,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import {
	useCallback,
	useLayoutEffect,
	useRef,
	useState,
} from '@wordpress/element';

import './focal-point.scss';

/** Outer size of core's handle, in px. Keep in step with focal-point.scss. */
const HANDLE_SIZE = 40;

const CENTRE = { x: 0.5, y: 0.5 };

/** Where each word of an AlignmentMatrixControl value sits on its axis. */
const ANCHORS = {
	left: 0,
	top: 0,
	center: 0.5,
	right: 1,
	bottom: 1,
};

function clamp( value, min, max ) {
	return Math.min( max, Math.max( min, value ) );
}

/**
 * Nearest anchor to a focal point, as "<vertical> <horizontal>".
 *
 * The grid has nine cells and the point is continuous, so a point dragged
 * somewhere in between is reported as the square it is closest to.
 *
 * @param {Object} point Focal point.
 * @return {string} Value for AlignmentMatrixControl.
 */
function toAnchor( point ) {
	const word = ( value, low, high ) => {
		if ( value <= 0.25 ) {
			return low;
		}

		return value >= 0.75 ? high : 'center';
	};

	return `${ word( point?.y ?? 0.5, 'top', 'bottom' ) } ${ word(
		point?.x ?? 0.5,
		'left',
		'right'
	) }`;
}

/**
 * Focal point for an anchor.
 *
 * @param {string} anchor Value from AlignmentMatrixControl.
 * @return {Object} Focal point.
 */
function fromAnchor( anchor ) {
	const [ y, x ] = String( anchor ).split( ' ' );

	return { x: ANCHORS[ x ] ?? 0.5, y: ANCHORS[ y ] ?? 0.5 };
}

/**
 * Focal point picker that cannot be aimed out of bounds.
 *
 * @param {Object}   props
 * @param {string}   props.url      Image to show.
 * @param {number}   props.scale    Zoom the render applies, 1 when there is none.
 * @param {boolean}  props.anchors  Show the grid of nine anchors above the picture.
 * @param {Object}   props.value    Current focal point.
 * @param {Function} props.onChange Receives the bounded focal point.
 * @return {Element} The picker.
 */
export default function FramingPicker( {
	url,
	scale = 1,
	anchors = false,
	value,
	onChange,
	...props
} ) {
	const ref = useRef( null );
	const [ media, setMedia ] = useState( null );
	const anchorLabel = __( 'Pin to a side', 'wow-blocks' );
	const { baseControlProps, controlProps } = useBaseControlProps( {
		__nextHasNoMarginBottom: true,
		label: anchorLabel,
		help: __(
			'Puts that side of the picture against the same side of the cell, so nothing is cut off there.',
			'wow-blocks'
		),
	} );

	// The picture is letterboxed inside the control, so the handle has to be
	// measured against the media element rather than against the wrapper.
	useLayoutEffect( () => {
		const node = ref.current?.querySelector(
			'.components-focal-point-picker__media'
		);

		if ( ! node || 'undefined' === typeof window.ResizeObserver ) {
			return undefined;
		}

		const observer = new window.ResizeObserver( ( [ entry ] ) => {
			const { width, height } = entry.contentRect;

			setMedia( width > 0 && height > 0 ? { width, height } : null );
		} );

		observer.observe( node );

		return () => observer.disconnect();
	}, [ url ] );

	const inset = useCallback(
		( axis, withHandle ) => {
			const size = media?.[ 'x' === axis ? 'width' : 'height' ] || 0;
			const handle = withHandle && size > 0 ? HANDLE_SIZE / 2 / size : 0;
			const frame = scale > 1 ? 0.5 / scale : 0;

			// Never past the middle: that would leave no range at all.
			return Math.min( 0.5, Math.max( handle, frame ) );
		},
		[ media, scale ]
	);

	const bound = ( point, withHandle = true ) => {
		const x = inset( 'x', withHandle );
		const y = inset( 'y', withHandle );

		return {
			x: clamp( point?.x ?? CENTRE.x, x, 1 - x ),
			y: clamp( point?.y ?? CENTRE.y, y, 1 - y ),
		};
	};

	return (
		<div ref={ ref } className="wow-focal-point">
			{ anchors && (
				<BaseControl
					{ ...baseControlProps }
					className="wow-focal-point__anchors"
				>
					<AlignmentMatrixControl
						{ ...controlProps }
						label={ anchorLabel }
						value={ toAnchor( value || CENTRE ) }
						onChange={ ( anchor ) =>
							onChange( bound( fromAnchor( anchor ), false ) )
						}
					/>
				</BaseControl>
			) }

			<FocalPointPicker
				{ ...props }
				url={ url }
				value={ value || CENTRE }
				onChange={ ( point ) => onChange( bound( point ) ) }
			/>
		</div>
	);
}
