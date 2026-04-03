/**
 * Handbook viewer entry point.
 *
 * Mounts the React app into #orgahb-handbook-viewer once the DOM is ready.
 * window.orgahbHandbookConfig is localised by templates/building-view.php.
 *
 * React is bundled (not external) so this module has no @wordpress/element
 * dependency — it only requires wp-api-fetch and wp-i18n at runtime.
 */

import { createRoot } from '@wordpress/element';
import { setup } from '@shared/api';
import App from './App';

document.addEventListener( 'DOMContentLoaded', () => {
	const config = window.orgahbHandbookConfig;
	if ( ! config ) {
		return;
	}

	// Install the nonce middleware once; api.js functions use it from here on.
	setup( config.nonce );

	const container = document.getElementById( 'orgahb-handbook-viewer' );
	if ( ! container ) {
		return;
	}

	createRoot( container ).render( <App config={config} /> );
} );
