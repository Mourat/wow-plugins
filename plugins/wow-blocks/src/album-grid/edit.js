import { __ } from '@wordpress/i18n';
import {
	InspectorControls,
	useBlockProps,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import {
	BaseControl,
	ColorPalette,
	PanelBody,
	RangeControl,
	ToggleControl,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import ServerSideRender from '@wordpress/server-side-render';

import metadata from './block.json';
import { config } from '../shared/config';

/**
 * Editor for the album grid.
 *
 * The preview is drawn by the server through the same render.php the front
 * end uses: the grid depends on the albums that actually exist, and
 * repeating the query in JS would mean keeping two implementations that
 * drift apart.
 *
 * @param {Object}   props
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Writes the attributes.
 * @return {Element} The editor markup.
 */
export default function Edit( { attributes, setAttributes } ) {
	const { showLogo, tileWidth, maxColumns, badgeColor, limit } = attributes;

	const palette = useSelect(
		( select ) => select( blockEditorStore ).getSettings().colors || [],
		[]
	);

	const tile = tileWidth || config.albumTileWidth;
	const columns = maxColumns || config.albumMaxColumns;

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Grid', 'wow-blocks' ) }>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Logo in the first cell', 'wow-blocks' ) }
						help={ __(
							'Uses the site logo. Until one is set, the cell stays reserved.',
							'wow-blocks'
						) }
						checked={ !! showLogo }
						onChange={ ( value ) =>
							setAttributes( { showLogo: value } )
						}
					/>

					<RangeControl
						__nextHasNoMarginBottom
						label={ __( 'Tile width, px', 'wow-blocks' ) }
						value={ tile }
						min={ 120 }
						max={ 400 }
						step={ 10 }
						onChange={ ( value ) =>
							setAttributes( { tileWidth: value } )
						}
					/>

					<RangeControl
						__nextHasNoMarginBottom
						label={ __( 'Columns on a wide screen', 'wow-blocks' ) }
						help={ __(
							'Tiles never stretch: extras wrap to the next line and the grid stays centred.',
							'wow-blocks'
						) }
						value={ columns }
						min={ 1 }
						max={ 8 }
						step={ 1 }
						onChange={ ( value ) =>
							setAttributes( { maxColumns: value } )
						}
					/>

					<RangeControl
						__nextHasNoMarginBottom
						label={ __( 'How many albums to show', 'wow-blocks' ) }
						help={ __( '0 shows every album.', 'wow-blocks' ) }
						value={ limit || 0 }
						min={ 0 }
						max={ 60 }
						step={ 1 }
						onChange={ ( value ) =>
							setAttributes( { limit: value } )
						}
					/>

					<BaseControl
						__nextHasNoMarginBottom
						id="wow-album-badge-color"
						label={ __( 'Badge colour', 'wow-blocks' ) }
					>
						<ColorPalette
							colors={ palette }
							value={ badgeColor }
							onChange={ ( color ) =>
								setAttributes( { badgeColor: color || '' } )
							}
						/>
					</BaseControl>
				</PanelBody>
			</InspectorControls>

			<div { ...useBlockProps() }>
				<ServerSideRender
					block={ metadata.name }
					attributes={ attributes }
				/>
			</div>
		</>
	);
}
