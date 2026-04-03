/**
 * Client-side Fuse.js search helpers.
 *
 * Two search surfaces (spec §26.2):
 *   1. Building-local  — searches only the current building's bundle items.
 *      Use `createBuildingSearch(bundleAreas)` with the already-loaded bundle.
 *      No network request needed.
 *
 *   2. Global — loads the full server index then searches all content.
 *      Use `loadGlobalIndex()` + `createGlobalSearch(docs)`.
 *
 * Fuse.js is bundled (not a WP external) so it is always available here.
 */

import Fuse from 'fuse.js';
import apiFetch from '@wordpress/api-fetch';

// ── Fuse options ──────────────────────────────────────────────────────────────

/**
 * Fuse.js options for content search.
 * Keys with higher weight contribute more to the score.
 */
const CONTENT_FUSE_OPTIONS = {
	includeScore:       true,
	includeMatches:     false,
	threshold:          0.35,    // 0 = exact, 1 = match anything
	ignoreLocation:     true,
	minMatchCharLength: 2,
	keys: [
		{ name: 'title',          weight: 3.0 },
		{ name: 'aliases',        weight: 2.5 },
		{ name: 'local_note',     weight: 2.0 },
		{ name: 'area_label',     weight: 1.5 },
		{ name: 'hotspot_labels', weight: 1.5 },
		{ name: 'section_labels', weight: 1.2 },
		{ name: 'excerpt',        weight: 1.0 },
		{ name: 'version_label',  weight: 0.8 },
		{ name: 'filename',       weight: 0.8 },
	],
};

const BUILDING_FUSE_OPTIONS = {
	...CONTENT_FUSE_OPTIONS,
	keys: [
		{ name: 'title',       weight: 3.0 },
		{ name: 'aliases',     weight: 2.5 },
		{ name: 'code',        weight: 2.5 },
		{ name: 'address',     weight: 1.5 },
		{ name: 'area_labels', weight: 1.2 },
	],
};

// ── Building-local search (no network) ───────────────────────────────────────

/**
 * Creates a Fuse.js index from bundle areas (already loaded in the viewer).
 *
 * Call this once after the bundle loads. The returned `search` function
 * accepts a query string and returns matching bundle items (not area wrappers).
 *
 * @param {Array}  bundleAreas  The `areas` array from GET /buildings/{id}/bundle.
 * @returns {{ search: function(string): Array }}
 */
export function createBuildingSearch( bundleAreas ) {
	// Flatten all items from all areas into a single list, attaching area metadata.
	const docs = [];
	for ( const area of bundleAreas ) {
		for ( const item of area.items ) {
			docs.push( {
				...item,
				area_key:   area.key,
				area_label: area.label,
				// Flatten hotspots JSON into labels for search.
				hotspot_labels: extractHotspotLabels( item.meta?.hotspots_json ),
				filename:       '', // not in bundle payload; search won't use it
			} );
		}
	}

	const fuse = new Fuse( docs, CONTENT_FUSE_OPTIONS );

	return {
		/**
		 * @param {string} query
		 * @returns {Array}  Matching bundle items (same shape as the original items).
		 */
		search( query ) {
			if ( ! query || query.trim().length < 2 ) {
				return docs;
			}
			return fuse.search( query.trim() ).map( ( r ) => r.item );
		},
	};
}

// ── Global search (fetches server index) ─────────────────────────────────────

/** In-memory cache for the global index promise. */
let globalIndexPromise = null;

/**
 * Fetches and caches the global search index from the server.
 * Subsequent calls return the same Promise (no duplicate requests).
 *
 * @returns {Promise<Array>}
 */
export function loadGlobalIndex() {
	if ( ! globalIndexPromise ) {
		globalIndexPromise = apiFetch( { path: 'orgahb/v1/search/index' } );
	}
	return globalIndexPromise;
}

/**
 * Creates a Fuse.js index from a server-provided global document list.
 *
 * @param {Array} docs  Resolved value of `loadGlobalIndex()`.
 * @returns {{ search: function(string, object?): Array }}
 */
export function createGlobalSearch( docs ) {
	// Split docs by type for type-specific key sets.
	const contentDocs  = docs.filter( ( d ) => [ 'page', 'process', 'document' ].includes( d.type ) );
	const buildingDocs = docs.filter( ( d ) => d.type === 'building' );
	const sectionDocs  = docs.filter( ( d ) => d.type === 'section' );

	const contentFuse  = new Fuse( contentDocs,  CONTENT_FUSE_OPTIONS );
	const buildingFuse = new Fuse( buildingDocs, BUILDING_FUSE_OPTIONS );
	const sectionFuse  = new Fuse( sectionDocs,  { ...CONTENT_FUSE_OPTIONS, keys: [ { name: 'title', weight: 3 }, { name: 'excerpt', weight: 1 } ] } );

	return {
		/**
		 * @param {string}  query
		 * @param {{ types?: string[] }} opts  Filter by type(s). Omit for all.
		 * @returns {Array}  Matched docs, sorted by score (best first).
		 */
		search( query, opts = {} ) {
			if ( ! query || query.trim().length < 2 ) {
				return [];
			}
			const q     = query.trim();
			const types = opts.types ?? [ 'page', 'process', 'document', 'building', 'section' ];

			const results = [];
			if ( types.some( ( t ) => [ 'page', 'process', 'document' ].includes( t ) ) ) {
				results.push( ...contentFuse.search( q ) );
			}
			if ( types.includes( 'building' ) ) {
				results.push( ...buildingFuse.search( q ) );
			}
			if ( types.includes( 'section' ) ) {
				results.push( ...sectionFuse.search( q ) );
			}

			// Sort by Fuse score (lower = better match).
			results.sort( ( a, b ) => ( a.score ?? 1 ) - ( b.score ?? 1 ) );

			return results.map( ( r ) => r.item );
		},
	};
}

// ── Private helpers ───────────────────────────────────────────────────────────

/**
 * Extracts space-separated hotspot labels from a hotspot JSON string.
 * Safe to call with null / undefined.
 *
 * @param {string|null|undefined} hotspots_json
 * @returns {string}
 */
function extractHotspotLabels( hotspots_json ) {
	if ( ! hotspots_json ) return '';
	try {
		const hotspots = JSON.parse( hotspots_json );
		if ( ! Array.isArray( hotspots ) ) return '';
		return hotspots.map( ( hs ) => hs.label ?? '' ).filter( Boolean ).join( ' ' );
	} catch {
		return '';
	}
}
