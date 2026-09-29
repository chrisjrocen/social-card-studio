/**
 * Editor panel entry point.
 *
 * Implements the registration half of SPEC.md §14.
 */

import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';

import CardPanel from './panel.js';
import './style.scss';

registerPlugin( 'scstudio-card-panel', {
	render: () => (
		<PluginDocumentSettingPanel
			name="scstudio-card"
			title={ __( 'Social card', 'social-card-studio' ) }
			className="scstudio-document-panel"
		>
			<CardPanel />
		</PluginDocumentSettingPanel>
	),
	icon: 'format-image',
} );
