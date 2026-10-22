import { __, sprintf } from '@wordpress/i18n';
import {
	BlockControls,
	InspectorControls,
	MediaUpload,
	MediaUploadCheck,
	useBlockProps,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import {
	BaseControl,
	Button,
	ButtonGroup,
	ColorPalette,
	Notice,
	PanelBody,
	SelectControl,
	ToggleControl,
	ToolbarButton,
	ToolbarGroup,
} from '@wordpress/components';
import { createBlock } from '@wordpress/blocks';
import { useDispatch, useSelect } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';

import { GAP_CHOICES, columnsFor, config } from '../shared/config';
import {
	dropTarget,
	emptyCell,
	isFilled,
	mediaToCell,
	replaceCell,
	resizeCells,
} from '../shared/cells';
import useAttachment from '../shared/attachment-url';
import CopyName from '../shared/copy-name';
import fillColor from '../shared/fill-color';
import useAttachmentDeleted, {
	clearIfDeleted,
} from '../shared/attachment-deleted';
import EditImageButton from '../shared/edit-image';
import useBlockAttributes from '../shared/block-attributes';
import FramingPicker from '../shared/framing-picker';
import MediaDropZone from '../shared/media-drop';
import useLibraryOnlyNotice from '../shared/notices';

const LAYOUT_OPTIONS = [
	{ value: 'single', label: __( '1 column', 'wow-blocks' ) },
	{
		value: 'two-narrow-left',
		label: __( '2 columns, narrow on the left', 'wow-blocks' ),
	},
	{
		value: 'two-narrow-right',
		label: __( '2 columns, narrow on the right', 'wow-blocks' ),
	},
	{ value: 'three', label: __( '3 columns', 'wow-blocks' ) },
];

const COLUMN_LABELS = {
	full: __( 'wide', 'wow-blocks' ),
	wide: __( 'wide', 'wow-blocks' ),
	narrow: __( 'narrow', 'wow-blocks' ),
};

export default function Edit( { attributes, setAttributes, clientId } ) {
	const { layout, cells, gap, stackOnMobile } = attributes;
	const columns = columnsFor( layout );
	const [ activeIndex, setActiveIndex ] = useState( 0 );

	// Uploads finish long after the render that started them, so every write
	// they cause goes through the store rather than through this closure.
	const { read, write } = useBlockAttributes( clientId );
	const notifyLibraryOnly = useLibraryOnlyNotice();

	const palette = useSelect(
		( select ) => select( blockEditorStore ).getSettings().colors || [],
		[]
	);

	/*
	 * Where this row sits, so that another can be put in front of it.
	 *
	 * The editor draws its own "add a block" button in the gap between two
	 * blocks, and before the first block there is no gap — so a row at the
	 * top of an album could only be made at the bottom and dragged up,
	 * past twenty others. This asks for the place instead.
	 */
	const { place, parent } = useSelect(
		( select ) => {
			const store = select( blockEditorStore );

			return {
				place: store.getBlockIndex( clientId ),
				parent: store.getBlockRootClientId( clientId ),
			};
		},
		[ clientId ]
	);
	const { insertBlock } = useDispatch( blockEditorStore );

	const addRow = ( where ) =>
		insertBlock(
			createBlock( 'wow/media-row' ),
			'above' === where ? place : place + 1,
			parent
		);

	// The cells array is always exactly as long as the layout has columns.
	// Read afresh: an upload may have rewritten the cells since this render,
	// and writing the rendered copy back would undo it.
	//
	// Not a step of its own: it is the tail of whichever change altered the
	// layout, and history has to take the two back together. Apart, undo
	// returns the cells while leaving the layout, this runs again, and the
	// editor can never be walked backwards past that point.
	useEffect( () => {
		const current = read();

		if ( ! current ) {
			return;
		}

		const fitted = resizeCells( current.cells, current.layout );

		if ( fitted.length !== current.cells?.length ) {
			write( { cells: fitted }, { ownStep: false } );
		}
	}, [ layout, cells.length ] ); // eslint-disable-line react-hooks/exhaustive-deps

	useEffect( () => {
		if ( activeIndex > columns.length - 1 ) {
			setActiveIndex( 0 );
		}
	}, [ columns.length, activeIndex ] );

	// Every write starts from the attributes as they are now rather than from
	// this render: the caller may be a callback several uploads old, holding
	// an array of cells from before any of them had finished.
	/*
	 * Pressing "Fill" asks the pictures on either side what colour to be,
	 * rather than putting down a grey to be corrected by hand. One neighbour
	 * at the edge of a row, halfway between two in the middle; nothing to
	 * take from leaves the old grey, which is at least honest about it.
	 */
	const fillCell = async ( index ) => {
		const found = await fillColor( cells, columns, index );

		updateCell( index, {
			kind: 'color',
			color: found || '#cccccc',
		} );
	};

	const updateCell = ( index, patch ) => {
		const current = read();

		if ( ! current ) {
			return;
		}

		write( {
			cells: replaceCell(
				resizeCells( current.cells, current.layout ),
				index,
				patch
			),
		} );
	};

	// A drop can carry several files. The first takes the cell it landed on,
	// the rest take the empty cells to its right, and whatever does not fit
	// stays in the media library rather than pushing something else out.
	const placeMedia = ( index, patch, offset ) => {
		const current = read();

		if ( ! current ) {
			return;
		}

		const next = resizeCells( current.cells, current.layout );
		const target = dropTarget( next, index, offset );

		if ( 0 > target ) {
			notifyLibraryOnly();

			return;
		}

		write( { cells: replaceCell( next, target, patch ) } );
	};

	const blockProps = useBlockProps( {
		className: [
			'wow-row',
			`wow-row--${ layout }`,
			stackOnMobile ? 'wow-row--stack' : '',
		]
			.filter( Boolean )
			.join( ' ' ),
		style:
			undefined !== gap && null !== gap
				? { '--wow-gap': `${ gap }px` }
				: undefined,
	} );

	const gapOptions = GAP_CHOICES.map( ( value ) => ( {
		value: String( value ),
		label:
			value === config.defaultGap
				? /* translators: %d: gap size in pixels. */
					sprintf( __( '%d px (default)', 'wow-blocks' ), value )
				: `${ value } px`,
	} ) );

	const activeCell = cells[ activeIndex ] || emptyCell();
	const activeColumn = columns[ activeIndex ] || 'narrow';

	return (
		<>
			<BlockControls>
				<ToolbarGroup>
					<ToolbarButton
						icon="insert-before"
						label={ __( 'Add a row above', 'wow-blocks' ) }
						onClick={ () => addRow( 'above' ) }
					/>

					<ToolbarButton
						icon="insert-after"
						label={ __( 'Add a row below', 'wow-blocks' ) }
						onClick={ () => addRow( 'below' ) }
					/>
				</ToolbarGroup>

				<ToolbarGroup>
					{ LAYOUT_OPTIONS.map( ( option ) => (
						<ToolbarButton
							key={ option.value }
							isPressed={ layout === option.value }
							onClick={ () =>
								setAttributes( { layout: option.value } )
							}
						>
							{ option.label }
						</ToolbarButton>
					) ) }
				</ToolbarGroup>
			</BlockControls>

			<InspectorControls>
				<PanelBody title={ __( 'Grid', 'wow-blocks' ) }>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Layout', 'wow-blocks' ) }
						value={ layout }
						options={ LAYOUT_OPTIONS }
						onChange={ ( value ) =>
							setAttributes( { layout: value } )
						}
					/>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Gap', 'wow-blocks' ) }
						help={ __(
							'The same gap between columns and between rows.',
							'wow-blocks'
						) }
						value={ String(
							undefined === gap || null === gap
								? config.defaultGap
								: gap
						) }
						options={ gapOptions }
						onChange={ ( value ) =>
							setAttributes( { gap: parseInt( value, 10 ) } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Stack on mobile', 'wow-blocks' ) }
						checked={ stackOnMobile }
						onChange={ ( value ) =>
							setAttributes( { stackOnMobile: value } )
						}
					/>
				</PanelBody>

				<PanelBody
					title={ sprintf(
						/* translators: 1: column number, 2: column type (narrow or wide). */
						__( 'Column %1$d (%2$s)', 'wow-blocks' ),
						activeIndex + 1,
						COLUMN_LABELS[ activeColumn ]
					) }
				>
					<CellControls
						cell={ activeCell }
						palette={ palette }
						onChange={ ( patch ) =>
							updateCell( activeIndex, patch )
						}
						onFill={ () => fillCell( activeIndex ) }
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<div className="wow-row__grid">
					{ columns.map( ( column, index ) => (
						<Cell
							key={ index }
							cell={ cells[ index ] || emptyCell() }
							column={ column }
							isActive={ index === activeIndex }
							onSelect={ () => setActiveIndex( index ) }
							onChange={ ( patch ) => updateCell( index, patch ) }
							onFill={ () => fillCell( index ) }
							onMedia={ ( patch, offset ) =>
								placeMedia( index, patch, offset )
							}
						/>
					) ) }
				</div>
			</div>
		</>
	);
}

/**
 * A single cell on the editor canvas.
 *
 * @param {Object}   props
 * @param {Object}   props.cell     Cell data.
 * @param {string}   props.column   Column type: full, wide or narrow.
 * @param {boolean}  props.isActive Whether this cell is the selected one.
 * @param {Function} props.onSelect Makes the cell active.
 * @param {Function} props.onChange Changes the cell fields.
 * @param {Function} props.onMedia  Places an image from a drop, which may
 *                                  land in a cell further along the row.
 * @param {Function} props.onFill   Turns the cell into a fill, coloured from
 *                                  the pictures beside it.
 * @return {Element} The cell markup.
 */
function Cell( {
	cell,
	column,
	isActive,
	onSelect,
	onChange,
	onMedia,
	onFill,
} ) {
	/*
	 * Only an image cell has an attachment to lose. A cell switched over to
	 * a colour keeps the id of whatever picture it used to hold, so that
	 * switching back brings it again — and that leftover must not be asked
	 * after. Asked anyway, a long-deleted picture reported the cell empty
	 * while its colour went on being drawn, which is a cell half filled and
	 * half asking to be filled, and the cell would have been emptied
	 * outright the moment anything acted on it.
	 */
	const watching = 'image' === cell.kind ? cell.id || 0 : 0;
	const { url, title, missing } = useAttachment( watching, cell.url || '' );

	useAttachmentDeleted( watching, () => onChange( emptyCell() ) );

	// An image that is no longer in the library is drawn as nothing at all,
	// which is what it is on the page. The cell offers its placeholder again
	// so the gap can be filled, and the attributes stay as they are until
	// something is actually chosen.
	const filled = isFilled( cell ) && ! missing;
	const className = [
		'wow-cell',
		`wow-cell--${ column }`,
		isActive ? 'is-active' : '',
		filled ? 'is-filled' : 'is-empty',
	]
		.filter( Boolean )
		.join( ' ' );

	return (
		<div
			className={ className }
			onFocus={ onSelect }
			onClick={ onSelect }
			role="button"
			tabIndex={ 0 }
			onKeyDown={ ( event ) => {
				if ( 'Enter' === event.key || ' ' === event.key ) {
					onSelect();
				}
			} }
		>
			{ 'color' === cell.kind && cell.color && (
				<span
					className="wow-cell__fill"
					style={ { backgroundColor: cell.color } }
				/>
			) }

			{ 'image' === cell.kind && url && ! missing && (
				<CopyName name={ title } />
			) }

			{ 'image' === cell.kind && url && ! missing && (
				<img
					className="wow-cell__image"
					src={ url }
					alt={ cell.alt }
					style={ {
						objectPosition: `${ ( cell.focalPoint?.x ?? 0.5 ) * 100 }% ${
							( cell.focalPoint?.y ?? 0.5 ) * 100
						}%`,
					} }
				/>
			) }

			{ ! filled && (
				<div className="wow-cell__placeholder">
					<MediaUploadCheck>
						<MediaUpload
							allowedTypes={ [ 'image' ] }
							onSelect={ ( media ) =>
								onChange( mediaToCell( media ) )
							}
							render={ ( { open } ) => (
								<Button
									variant="primary"
									size="small"
									onClick={ open }
								>
									{ __( 'Image', 'wow-blocks' ) }
								</Button>
							) }
						/>
					</MediaUploadCheck>
					<Button variant="secondary" size="small" onClick={ onFill }>
						{ __( 'Fill', 'wow-blocks' ) }
					</Button>
				</div>
			) }

			{ /* Selected on the drop, not when the upload ends: moving the
			     inspector to a cell seconds later, possibly twice over for
			     two drops, is what made this feel out of control. */ }
			<MediaDropZone onMedia={ onMedia } onStart={ onSelect } />
		</div>
	);
}

/**
 * The selected cell's settings live in the inspector rather than on the
 * canvas, which keeps the canvas light even on a page with a hundred images.
 *
 * @param {Object}   props
 * @param {Object}   props.cell     Cell data.
 * @param {Array}    props.palette  Theme palette.
 * @param {Function} props.onChange Changes the cell fields.
 * @param {Function} props.onFill   Turns the cell into a fill, coloured from
 *                                  the pictures beside it.
 * @return {Element} The settings markup.
 */
function CellControls( { cell, palette, onChange, onFill } ) {
	// As in Cell: a colour cell's leftover id is not an image it is showing.
	const { url, missing } = useAttachment(
		'image' === cell.kind ? cell.id || 0 : 0,
		cell.url || ''
	);

	return (
		<>
			<BaseControl
				__nextHasNoMarginBottom
				className="wow-cell-controls__kind"
			>
				<ButtonGroup>
					<Button
						variant={
							'image' === cell.kind ? 'primary' : 'secondary'
						}
						size="small"
						onClick={ () => onChange( { kind: 'image' } ) }
					>
						{ __( 'Image', 'wow-blocks' ) }
					</Button>
					<Button
						variant={
							'color' === cell.kind ? 'primary' : 'secondary'
						}
						size="small"
						onClick={ onFill }
					>
						{ __( 'Fill', 'wow-blocks' ) }
					</Button>
				</ButtonGroup>
			</BaseControl>

			{ 'color' === cell.kind && (
				<ColorPalette
					colors={ palette }
					value={ cell.color }
					onChange={ ( color ) => onChange( { color: color || '' } ) }
				/>
			) }

			{ 'image' === cell.kind && (
				<>
					{ missing && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'This image is no longer in the media library. Choose another one.',
								'wow-blocks'
							) }
						</Notice>
					) }

					<div className="wow-image-actions">
						<MediaUploadCheck>
							<MediaUpload
								allowedTypes={ [ 'image' ] }
								value={ cell.id }
								onSelect={ ( media ) =>
									onChange( mediaToCell( media ) )
								}
								// A deletion can go unheard — the modal is core's,
								// not ours. Closing it is the second chance to ask.
								onClose={ () =>
									clearIfDeleted( cell.id || 0, () =>
										onChange( emptyCell() )
									)
								}
								render={ ( { open } ) => (
									<>
										<Button
											variant="secondary"
											onClick={ open }
										>
											{ cell.url && ! missing
												? __(
														'Replace image',
														'wow-blocks'
													)
												: __(
														'Choose image',
														'wow-blocks'
													) }
										</Button>

										{ ! missing && (
											<EditImageButton
												id={ cell.id }
												open={ open }
												onSaved={ ( media ) =>
													onChange( {
														url:
															media.url ||
															media.source_url ||
															'',
													} )
												}
											/>
										) }
									</>
								) }
							/>
						</MediaUploadCheck>

						{ url && ! missing && (
							<Button
								variant="link"
								isDestructive
								onClick={ () => onChange( emptyCell() ) }
							>
								{ __( 'Remove image', 'wow-blocks' ) }
							</Button>
						) }
					</div>

					{ url && ! missing && (
						<>
							<FramingPicker
								__nextHasNoMarginBottom
								anchors
								label={ __( 'Framing', 'wow-blocks' ) }
								help={ __(
									'The point guaranteed to stay in frame when the image is cropped.',
									'wow-blocks'
								) }
								url={ url }
								value={ cell.focalPoint || { x: 0.5, y: 0.5 } }
								onChange={ ( focalPoint ) =>
									onChange( { focalPoint } )
								}
							/>
						</>
					) }
				</>
			) }
		</>
	);
}
