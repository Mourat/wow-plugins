import { registerBlockType } from '@wordpress/blocks';

import metadata from './block.json';
import Edit from './edit';
import variations from './variations';
import './style.scss';
import './editor.scss';

registerBlockType( metadata.name, {
	edit: Edit,
	// Rendering is dynamic; render.php assembles the markup.
	save: () => null,
	variations,
} );
