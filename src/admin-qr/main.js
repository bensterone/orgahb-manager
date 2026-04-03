/**
 * Admin QR code canvas renderer.
 *
 * Reads the building landing URL from the canvas data attribute and renders
 * the QR code client-side using the `qrcode` npm package.
 * Also wires up the PNG download button.
 *
 * Enqueued only on orgahb_building admin edit screens by ORGAHB_Metaboxes.
 */

import QRCode from 'qrcode';

document.addEventListener( 'DOMContentLoaded', () => {
	const canvas = document.getElementById( 'orgahb-qr-canvas' );
	if ( ! canvas ) return;

	const url = canvas.dataset.url;
	if ( ! url ) return;

	QRCode.toCanvas( canvas, url, {
		width:  200,
		margin: 2,
		color:  { dark: '#000000', light: '#ffffff' },
	} );

	// Download PNG button.
	const btn = document.getElementById( 'orgahb-qr-download' );
	if ( btn ) {
		btn.addEventListener( 'click', ( e ) => {
			e.preventDefault();
			const slug = canvas.dataset.slug || 'building';
			const a    = document.createElement( 'a' );
			a.download = `qr-${ slug }.png`;
			a.href     = canvas.toDataURL( 'image/png' );
			a.click();
		} );
	}
} );
