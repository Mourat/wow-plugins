import { __ } from '@wordpress/i18n';
import {
	BlockControls,
	InspectorControls,
	MediaReplaceFlow,
	MediaUpload,
	MediaUploadCheck,
	useBlockProps,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import {
	BaseControl,
	Button,
	ColorPalette,
	Notice,
	PanelBody,
	RangeControl,
	SelectControl,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { useEffect, useRef } from '@wordpress/element';

import averageColor from '../shared/average-color';
import { config } from '../shared/config';
import useAttachment from '../shared/attachment-url';
import useAttachmentDeleted, {
	clearIfDeleted,
} from '../shared/attachment-deleted';
import EditImageButton from '../shared/edit-image';
import FramingPicker from '../shared/framing-picker';
import MediaDropZone from '../shared/media-drop';
import useBlockAttributes from '../shared/block-attributes';
import useLibraryOnlyNotice from '../shared/notices';

const POSITION_OPTIONS = [
	{ value: 'left', label: __( 'Left', 'wow-blocks' ) },
	{ value: 'right', label: __( 'Right', 'wow-blocks' ) },
	{ value: 'none', label: __( 'No image', 'wow-blocks' ) },
];

/**
 * Editor for the narrow strip.
 *
 * @param {Object}   props
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Writes the attributes.
 * @param {string}   props.clientId      Block client id.
 * @return {Element} The editor markup.
 */
export default function Edit( { attributes, setAttributes, clientId } ) {
	const { backgroundColor, imagePosition, imageWidth, imageScale, image } =
		attributes;
	const palette = useSelect(
		( select ) => select( blockEditorStore ).getSettings().colors || [],
		[]
	);

	// Uploads outlive the render that started them; see the hook.
	const { read, write } = useBlockAttributes( clientId );
	const { url: imageUrl, missing: imageMissing } = useAttachment(
		image?.id || 0,
		image?.url || ''
	);
	const notifyLibraryOnly = useLibraryOnlyNotice();

	const width =
		undefined === imageWidth || null === imageWidth
			? config.defaultBandImageWidth
			: imageWidth;

	// The image slot always shows unless images are turned off: otherwise
	// there is nothing to grab on the canvas and no way to add one.
	const showsMedia = 'none' !== imagePosition;
	const hasImage = showsMedia && !! image?.url;

	// What the strip still has to show. An image deleted from the library is
	// nothing on the page, so it is nothing here either: the slot goes back
	// to offering a picker. The attributes keep the dead id until something
	// is chosen or "Remove image" is pressed, so nothing is lost to a reading
	// that only failed because the server was briefly out of reach.
	const showsImage = hasImage && ! imageMissing;

	const clearImage = () =>
		write( {
			image: {
				id: 0,
				url: '',
				alt: '',
				focalPoint: { x: 0.5, y: 0.5 },
			},
		} );

	// Deleting the strip's own image from the media modal empties the slot
	// straight away, rather than leaving the block pointing at an id that is
	// no longer there.
	useAttachmentDeleted( image?.id || 0, clearImage );

	// The focal point sets the spot; the scale sets how close it gets.
	const scale = Number( imageScale ) > 0 ? Number( imageScale ) : 1;
	const focal = image?.focalPoint || { x: 0.5, y: 0.5 };
	const origin = `${ focal.x * 100 }% ${ focal.y * 100 }%`;

	/*
	 * Moving the framing repaints the fill with the average colour of what
	 * the strip now shows. Only from the two controls that move it, never
	 * from the attributes themselves: a block drawn on opening an album has
	 * changed nothing, and must not quietly repaint a colour somebody chose.
	 *
	 * After the dragging stops, not during it. The crosshair reports every
	 * pixel it passes over, and a fill that flickers through forty colours
	 * on the way to one is a worse way of arriving there.
	 *
	 * The colour is only ever written here. The picker below stays exactly
	 * as it was: if the average is not the wanted colour, say so by hand and
	 * it stands until the framing moves again.
	 */
	const pending = useRef( 0 );

	useEffect( () => () => window.clearTimeout( pending.current ), [] );

	const repaint = ( framing ) => {
		window.clearTimeout( pending.current );

		pending.current = window.setTimeout( async () => {
			const found = await averageColor( {
				url: imageUrl,
				width,
				height: config.bandHeight,
				...framing,
			} );

			if ( found ) {
				write( { backgroundColor: found } );
			}
		}, 350 );
	};

	// The framing is carried over from the attributes as they are now: this
	// runs from a drop that may have started several renders ago, and the
	// rendered copy of the image would be the one from before the drop.
	const selectImage = ( selected ) => {
		const current = read();

		if ( ! current ) {
			return;
		}

		const framing = current.image?.focalPoint || { x: 0.5, y: 0.5 };

		write( {
			image: {
				id: selected.id,
				url: selected.url,
				alt: selected.alt || '',
				focalPoint: framing,
			},
		} );

		// A picture dropped in and left where it fell has a colour too, and
		// nobody should have to nudge the crosshair to be given it.
		repaint( { url: selected.url, focal: framing, scale } );
	};

	// The strip holds one image. A drop of several keeps the first and says
	// where the others went. Telling core to take only one file is not an
	// option: it then refuses the whole drop, first file included.
	const dropImage = ( patch, offset ) => {
		if ( 0 < offset ) {
			notifyLibraryOnly();

			return;
		}

		selectImage( patch );
	};

	const blockProps = useBlockProps( {
		className: [
			'wow-band',
			showsMedia
				? `wow-band--image-${ imagePosition }`
				: 'wow-band--no-image',
		].join( ' ' ),
		style: { '--wow-band-image-width': `${ width }px` },
	} );

	const media = showsMedia ? (
		<div
			className={ `wow-cell wow-band__media ${
				showsImage ? '' : 'is-empty'
			}` }
			style={ {
				'--wow-band-scale': scale,
				'--wow-band-origin': origin,
				'--wow-band-x': focal.x,
				'--wow-band-y': focal.y,
			} }
		>
			{ showsImage ? (
				<img
					className="wow-cell__image"
					src={ imageUrl }
					alt={ image.alt || '' }
					style={ { objectPosition: origin } }
				/>
			) : (
				<MediaUploadCheck>
					<MediaUpload
						allowedTypes={ [ 'image' ] }
						onSelect={ selectImage }
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
			) }

			<MediaDropZone onMedia={ dropImage } />
		</div>
	) : null;

	const fill = (
		<div
			className="wow-band__fill"
			style={ backgroundColor ? { backgroundColor } : undefined }
		/>
	);

	return (
		<>
			{ showsMedia && (
				<BlockControls group="other">
					<MediaReplaceFlow
						mediaId={ image?.id }
						mediaURL={ image?.url }
						allowedTypes={ [ 'image' ] }
						accept="image/*"
						onSelect={ selectImage }
						name={
							showsImage
								? __( 'Replace image', 'wow-blocks' )
								: __( 'Choose image', 'wow-blocks' )
						}
					/>
				</BlockControls>
			) }

			<InspectorControls>
				<PanelBody title={ __( 'Row', 'wow-blocks' ) }>
					<BaseControl
						__nextHasNoMarginBottom
						id="wow-band-background-color"
						label={ __( 'Fill colour', 'wow-blocks' ) }
					>
						<ColorPalette
							colors={ palette }
							value={ backgroundColor }
							onChange={ ( color ) =>
								setAttributes( {
									backgroundColor: color || '',
								} )
							}
						/>
					</BaseControl>

					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Image', 'wow-blocks' ) }
						value={ imagePosition }
						options={ POSITION_OPTIONS }
						onChange={ ( value ) =>
							setAttributes( { imagePosition: value } )
						}
					/>

					{ showsMedia && (
						<>
							{ imageMissing && (
								<Notice
									status="warning"
									isDismissible={ false }
								>
									{ __(
										'This image is no longer in the media library. Choose another one.',
										'wow-blocks'
									) }
								</Notice>
							) }

							<MediaUploadCheck>
								<MediaUpload
									allowedTypes={ [ 'image' ] }
									value={ image?.id }
									onSelect={ selectImage }
									// A deletion can go unheard — the modal is
									// core's, not ours. Closing it is the
									// second chance to ask.
									onClose={ () =>
										clearIfDeleted(
											image?.id || 0,
											clearImage
										)
									}
									render={ ( { open } ) => (
										<>
											<Button
												variant="secondary"
												onClick={ open }
											>
												{ showsImage
													? __(
															'Replace image',
															'wow-blocks'
														)
													: __(
															'Choose image',
															'wow-blocks'
														) }
											</Button>

											{ showsImage && (
												<EditImageButton
													id={ image?.id }
													open={ open }
												/>
											) }
										</>
									) }
								/>
							</MediaUploadCheck>

							{ hasImage && (
								<Button
									variant="link"
									isDestructive
									onClick={ clearImage }
								>
									{ __( 'Remove image', 'wow-blocks' ) }
								</Button>
							) }

							<RangeControl
								__nextHasNoMarginBottom
								label={ __( 'Image width, px', 'wow-blocks' ) }
								value={ width }
								min={ 40 }
								max={ Math.round( config.maxWidth / 2 ) }
								step={ 1 }
								onChange={ ( value ) =>
									setAttributes( { imageWidth: value } )
								}
							/>

							{ showsImage && (
								<>
									<FramingPicker
										__nextHasNoMarginBottom
										scale={ scale }
										label={ __( 'Framing', 'wow-blocks' ) }
										help={ __(
											'The point the frame centres on.',
											'wow-blocks'
										) }
										url={ imageUrl }
										value={ focal }
										onChange={ ( focalPoint ) => {
											setAttributes( {
												image: { ...image, focalPoint },
											} );
											repaint( {
												focal: focalPoint,
												scale,
											} );
										} }
									/>

									<RangeControl
										__nextHasNoMarginBottom
										label={ __( 'Scale', 'wow-blocks' ) }
										help={ __(
											'At 1× the image fits the strip width. Above that, the frame zooms towards the chosen point.',
											'wow-blocks'
										) }
										value={ scale }
										min={ 1 }
										max={ 4 }
										step={ 0.1 }
										onChange={ ( value ) => {
											setAttributes( {
												imageScale: value || 1,
											} );
											repaint( {
												focal,
												scale: value || 1,
											} );
										} }
									/>
								</>
							) }
						</>
					) }
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<div className="wow-band__inner">
					{ 'right' === imagePosition ? (
						<>
							{ fill }
							{ media }
						</>
					) : (
						<>
							{ media }
							{ fill }
						</>
					) }
				</div>
			</div>
		</>
	);
}
