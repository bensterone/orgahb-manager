/**
 * Admin shell entry point.
 *
 * Mounts the bundle management React app into #orgahb-admin-shell on any
 * admin page that includes it. Currently used on the Handbook dashboard page.
 *
 * window.orgahbAdminConfig is localised by ORGAHB_Admin::enqueue_assets().
 */

import { createRoot } from '@wordpress/element';
import App from './App';

document.addEventListener( 'DOMContentLoaded', () => {
	const config = window.orgahbAdminConfig;
	if ( ! config ) {
		return;
	}

	const container = document.getElementById( 'orgahb-admin-shell' );
	if ( ! container ) {
		return;
	}

	createRoot( container ).render( <App /> );
} );
