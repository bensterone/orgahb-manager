/**
 * REST API wrappers for all orgahb/v1 endpoints.
 *
 * Uses wp.apiFetch (provided by the wp-api-fetch WordPress script).
 * Call setup(nonce) once before any fetch — usually in the app entry point.
 *
 * All functions return Promises; errors are WP_Error-shaped objects:
 *   { code: string, message: string, data?: { status: number } }
 */

import apiFetch from '@wordpress/api-fetch';
import { enqueue as offlineEnqueue, drainQueue } from '@shared/offline';

// ── Setup ─────────────────────────────────────────────────────────────────────

/**
 * Installs the nonce middleware so every REST request is authenticated,
 * and starts a background drain of any queued offline actions when the
 * browser regains connectivity (spec §34.2).
 *
 * @param {string} nonce  wp_rest nonce from wp_create_nonce('wp_rest').
 */
export function setup( nonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( nonce ) );

	// Drain the offline queue whenever connectivity is restored.
	window.addEventListener( 'online', () => drainQueue() );

	// Also attempt an immediate drain in case there are stale entries from a
	// previous session and we are already online.
	if ( navigator.onLine ) {
		drainQueue();
	}
}

// ── Internal helpers ──────────────────────────────────────────────────────────

/**
 * Returns true if the error looks like a network/connectivity failure rather
 * than a server-side validation error (which should propagate normally).
 *
 * @param {unknown} err
 * @returns {boolean}
 */
function isNetworkError( err ) {
	if ( ! navigator.onLine ) return true;
	// apiFetch throws a plain Error with no status for fetch failures.
	if ( err instanceof TypeError ) return true;
	// WP REST errors with no HTTP status are also connectivity failures.
	if ( err && typeof err === 'object' && ! ( 'data' in err ) && ! ( 'status' in err ) ) return true;
	return false;
}

// ── Buildings ─────────────────────────────────────────────────────────────────

/**
 * GET /orgahb/v1/buildings/by-token/{token}
 * Returns the building object for a QR token.
 */
export function getBuilding( token ) {
	return apiFetch( { path: `orgahb/v1/buildings/by-token/${encodeURIComponent( token )}` } );
}

/**
 * GET /orgahb/v1/buildings/{id}/bundle
 * Returns { building_id, building, areas: [{ key, label, description, sort_order, items }] }
 */
export function getBuildingBundle( buildingId ) {
	return apiFetch( { path: `orgahb/v1/buildings/${buildingId}/bundle` } );
}

// ── Acknowledgments ───────────────────────────────────────────────────────────

/**
 * POST /orgahb/v1/acknowledgments
 *
 * Falls back to the offline queue on network failure (spec §34.2).
 *
 * @param {{ post_id: number, revision_id: number, version_label?: string, source?: string, queue_uuid?: string }} data
 * @returns {Promise<{ id: number }|{ queued: true }>}
 */
export function postAcknowledgment( data ) {
	return apiFetch( { path: 'orgahb/v1/acknowledgments', method: 'POST', data } )
		.catch( async ( err ) => {
			if ( isNetworkError( err ) ) {
				await offlineEnqueue( 'acknowledgment', data );
				return { queued: true };
			}
			throw err;
		} );
}

// ── Executions ────────────────────────────────────────────────────────────────

/**
 * POST /orgahb/v1/processes/{id}/execute
 *
 * @param {number} processId
 * @param {{
 *   building_id: number,
 *   hotspot_id: string,
 *   outcome: string,
 *   post_revision_id: number,
 *   area_key?: string,
 *   note?: string,
 *   source?: string,
 *   queue_uuid?: string,
 *   client_recorded_at?: string
 * }} data
 * @returns {{ id: number }|{ deduplicated: true }}
 */
export function postExecution( processId, data ) {
	return apiFetch( { path: `orgahb/v1/processes/${processId}/execute`, method: 'POST', data } )
		.catch( async ( err ) => {
			if ( isNetworkError( err ) ) {
				await offlineEnqueue( 'execution', { ...data, post_id: processId } );
				return { queued: true };
			}
			throw err;
		} );
}

/**
 * GET /orgahb/v1/processes/{id}/hotspots/{hotspotId}/executions
 *
 * @param {number} processId
 * @param {string} hotspotId
 * @param {{ building_id?: number, limit?: number, offset?: number }} params
 */
export function getExecutions( processId, hotspotId, params = {} ) {
	const qs = new URLSearchParams(
		Object.fromEntries( Object.entries( params ).filter( ( [ , v ] ) => v !== undefined ) )
	).toString();
	const suffix = qs ? `?${qs}` : '';
	return apiFetch( {
		path: `orgahb/v1/processes/${processId}/hotspots/${encodeURIComponent( hotspotId )}/executions${suffix}`,
	} );
}

// ── Observations ──────────────────────────────────────────────────────────────

/**
 * GET /orgahb/v1/buildings/{id}/observations
 *
 * @param {number} buildingId
 * @param {{ status?: string, area_key?: string, limit?: number, offset?: number }} params
 */
export function getBuildingObservations( buildingId, params = {} ) {
	const qs = new URLSearchParams(
		Object.fromEntries( Object.entries( params ).filter( ( [ , v ] ) => v !== undefined ) )
	).toString();
	const suffix = qs ? `?${qs}` : '';
	return apiFetch( { path: `orgahb/v1/buildings/${buildingId}/observations${suffix}` } );
}

/**
 * POST /orgahb/v1/buildings/{id}/observations
 *
 * @param {number} buildingId
 * @param {{
 *   summary: string,
 *   category?: string,
 *   area_key?: string,
 *   details?: string,
 *   external_reference?: string
 * }} data
 * @returns {{ id: number }}
 */
export function postObservation( buildingId, data ) {
	return apiFetch( { path: `orgahb/v1/buildings/${buildingId}/observations`, method: 'POST', data } )
		.catch( async ( err ) => {
			if ( isNetworkError( err ) ) {
				await offlineEnqueue( 'observation', { ...data, building_id: buildingId } );
				return { queued: true };
			}
			throw err;
		} );
}

/**
 * POST /orgahb/v1/observations/{id}/resolve
 *
 * @param {number} obsId
 * @param {{ status?: string }} data
 * @returns {{ success: boolean, status: string }}
 */
export function resolveObservation( obsId, data = {} ) {
	return apiFetch( { path: `orgahb/v1/observations/${obsId}/resolve`, method: 'POST', data } );
}

// ── Sections tree ─────────────────────────────────────────────────────────────

/**
 * Returns the full orgahb_section hierarchy with all published content items.
 * Powers the desktop handbook tree viewer.
 *
 * @returns {{ org_name: string, sections: Array }}
 */
export function getSectionsTree() {
	return apiFetch( { path: 'orgahb/v1/sections/tree' } );
}

// ── Backlinks ─────────────────────────────────────────────────────────────────

/**
 * Returns all published processes that link to a given content item via a LINK
 * hotspot — the "What links here?" backlinks list (SiYuan-inspired).
 *
 * @param {number} itemId  content post ID.
 * @returns {Array<{ content_id: number, title: string, content_type: string }>}
 */
export function getItemBacklinks( itemId ) {
	return apiFetch( { path: `orgahb/v1/items/${itemId}/backlinks` } );
}

// ── Page content ──────────────────────────────────────────────────────────────

/**
 * Fetches rendered post content for a handbook page via WP's native REST API.
 *
 * @param {number} pageId  orgahb_page post ID.
 * @returns {{ content: { rendered: string }, excerpt: { rendered: string } }}
 */
export function getPageContent( pageId ) {
	return apiFetch( {
		path: `/wp/v2/orgahb-pages/${pageId}?_fields=content,excerpt`,
	} );
}
