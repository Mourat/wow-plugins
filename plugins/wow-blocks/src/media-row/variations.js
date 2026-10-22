import { __ } from '@wordpress/i18n';

/**
 * The layouts appear as separate inserter entries, so nobody has to insert
 * the block first and then hunt for the switch.
 */
const variations = [
	{
		name: 'three',
		title: __( '3 columns', 'wow-blocks' ),
		description: __( 'Three equal columns.', 'wow-blocks' ),
		icon: 'grid-view',
		attributes: { layout: 'three' },
		isDefault: true,
		scope: [ 'inserter', 'transform' ],
		isActive: ( blockAttributes ) => 'three' === blockAttributes.layout,
	},
	{
		name: 'two-narrow-right',
		title: __( '2 columns, narrow on the right', 'wow-blocks' ),
		icon: 'align-left',
		attributes: { layout: 'two-narrow-right' },
		scope: [ 'inserter', 'transform' ],
		isActive: ( blockAttributes ) =>
			'two-narrow-right' === blockAttributes.layout,
	},
	{
		name: 'two-narrow-left',
		title: __( '2 columns, narrow on the left', 'wow-blocks' ),
		icon: 'align-right',
		attributes: { layout: 'two-narrow-left' },
		scope: [ 'inserter', 'transform' ],
		isActive: ( blockAttributes ) =>
			'two-narrow-left' === blockAttributes.layout,
	},
	{
		name: 'single',
		title: __( '1 column', 'wow-blocks' ),
		icon: 'align-center',
		attributes: { layout: 'single' },
		scope: [ 'inserter', 'transform' ],
		isActive: ( blockAttributes ) => 'single' === blockAttributes.layout,
	},
];

export default variations;
