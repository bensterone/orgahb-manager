/**
 * Process hotspot editor — entry point.
 *
 * Mounts the visual hotspot editor inside the "Hotspot JSON" metabox on the
 * orgahb_process edit screen, replacing the raw textarea with an interactive
 * drag-and-resize overlay on top of the process diagram image.
 *
 * The underlying <textarea id="orgahb_hotspots_json"> is kept hidden in the
 * DOM so WordPress saves its value on the normal post-save form submit.
 * The editor writes back to it on every change.
 *
 * window.orgahbProcessEditor is localised by ORGAHB_Metaboxes::enqueue_assets().
 */

import { createRoot } from '@wordpress/element';
import App from './App';

document.addEventListener( 'DOMContentLoaded', () => {
	const config = window.orgahbProcessEditor;
	if ( ! config ) {
		return;
	}

	const container = document.getElementById( 'orgahb-process-editor-mount' );
	if ( ! container ) {
		return;
	}

	createRoot( container ).render( <App config={config} /> );
} );
