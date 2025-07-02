/**
 * External dependencies
 */
import { Icon, percent } from '@wordpress/icons';
import { registerBlockType } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import { Edit, Save } from './edit';
import metadata from './block.json';

// Register the block using the metadata from block.json
registerBlockType( metadata, {
	icon: {
		src: (
			<Icon
				icon={ percent }
				className="wc-block-editor-components-block-icon"
			/>
		),
	},
	edit: Edit,
	save: Save,
} );
