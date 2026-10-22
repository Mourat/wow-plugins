import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, RangeControl, ToggleControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

import metadata from './block.json';

/**
 * Editor for the album list.
 *
 * The preview is drawn by the server through the same render.php the front
 * end uses: the list depends on the albums that actually exist and on their
 * manual order.
 *
 * @param {Object}   props
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Writes the attributes.
 * @return {Element} The editor markup.
 */
export default function Edit( { attributes, setAttributes } ) {
	const { stickyTop, hideOnMobile } = attributes;

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'List', 'wow-blocks' ) }>
					<RangeControl
						__nextHasNoMarginBottom
						label={ __(
							'Offset from the top when scrolled, px',
							'wow-blocks'
						) }
						help={ __(
							'How far the list sits from the top of the window once the page is scrolled.',
							'wow-blocks'
						) }
						value={ stickyTop ?? 50 }
						min={ 0 }
						max={ 200 }
						step={ 5 }
						onChange={ ( value ) =>
							setAttributes( { stickyTop: value } )
						}
					/>

					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Hide on narrow screens', 'wow-blocks' ) }
						help={ __(
							'Below 768px the columns stack, and the list would sit on top of the gallery.',
							'wow-blocks'
						) }
						checked={ !! hideOnMobile }
						onChange={ ( value ) =>
							setAttributes( { hideOnMobile: value } )
						}
					/>
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
