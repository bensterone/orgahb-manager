/**
 * PDF.js rendering helpers (spec §27.4).
 *
 * Uses pdfjs-dist to render PDF pages onto <canvas> elements.
 * The worker is loaded from the local plugin vendor path set via
 * window.orgahbHandbookConfig.pdfjsWorkerUrl.
 *
 * Exports:
 *   renderPdf( url, container ) — renders all pages of a PDF into a container div.
 */

import * as pdfjsLib from 'pdfjs-dist';

// ── Worker URL ────────────────────────────────────────────────────────────────

const workerUrl =
	window.orgahbHandbookConfig?.pdfjsWorkerUrl ??
	// Fallback to CDN only in development; production always sets this.
	`https://unpkg.com/pdfjs-dist@${ pdfjsLib.version }/build/pdf.worker.min.mjs`;

pdfjsLib.GlobalWorkerOptions.workerSrc = workerUrl;

// ── Render ────────────────────────────────────────────────────────────────────

/**
 * Renders all pages of a PDF document into a container element.
 *
 * Creates one <canvas> per page, appended to `container`. Any existing
 * canvas children are removed first so callers can safely re-invoke.
 *
 * @param {string}      url        Absolute URL to the PDF file.
 * @param {HTMLElement} container  Mount element for the canvases.
 * @returns {Promise<void>}
 */
export async function renderPdf( url, container ) {
	// Clear previous render.
	container.replaceChildren();

	const loadingTask = pdfjsLib.getDocument( { url, withCredentials: true } );
	const pdf         = await loadingTask.promise;

	for ( let pageNum = 1; pageNum <= pdf.numPages; pageNum++ ) {
		const page     = await pdf.getPage( pageNum );
		const viewport = page.getViewport( { scale: computeScale( page ) } );

		const canvas  = document.createElement( 'canvas' );
		const context = canvas.getContext( '2d' );

		canvas.width  = Math.floor( viewport.width );
		canvas.height = Math.floor( viewport.height );
		canvas.style.cssText = 'display:block;max-width:100%;height:auto;margin:0 auto 8px;';

		container.appendChild( canvas );

		await page.render( { canvasContext: context, viewport } ).promise;
	}
}

/**
 * Computes a device-pixel-ratio-aware scale that fits the page to the
 * viewport width (capped at 1200px logical pixels).
 *
 * @param {import('pdfjs-dist').PDFPageProxy} page
 * @returns {number}
 */
function computeScale( page ) {
	const dpr         = window.devicePixelRatio || 1;
	const viewport    = page.getViewport( { scale: 1 } );
	const maxLogical  = Math.min( window.innerWidth, 1200 );
	const cssScale    = maxLogical / viewport.width;
	return cssScale * dpr;
}
