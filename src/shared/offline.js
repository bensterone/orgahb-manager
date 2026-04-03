/**
 * Optional offline queue (spec §34.3).
 *
 * Buffers acknowledgments, evidence events, and observations in IndexedDB
 * when the network is unavailable. A background sync loop drains the queue
 * when connectivity is restored.
 *
 * The queue is keyed by a `queue_uuid` to prevent duplicate submission on
 * the server side (spec §33.8).
 *
 * Exports:
 *   enqueue( type, payload ) — add an action to the queue.
 *   drainQueue()            — attempt to flush all queued actions.
 *   getPendingCount()       — return the number of queued actions.
 */

import { postAcknowledgment, postExecution, postObservation } from '@shared/api';

const DB_NAME    = 'orgahb_offline_queue';
const DB_VERSION = 1;
const STORE      = 'queue';

/** @type {IDBDatabase|null} */
let _db = null;

/**
 * Open (or reuse) the IndexedDB database.
 *
 * @returns {Promise<IDBDatabase>}
 */
function openDb() {
	if ( _db ) return Promise.resolve( _db );

	return new Promise( ( resolve, reject ) => {
		const req = indexedDB.open( DB_NAME, DB_VERSION );

		req.onupgradeneeded = ( e ) => {
			const db = e.target.result;
			if ( ! db.objectStoreNames.contains( STORE ) ) {
				db.createObjectStore( STORE, { keyPath: 'queue_uuid' } );
			}
		};

		req.onsuccess = ( e ) => {
			_db = e.target.result;
			resolve( _db );
		};

		req.onerror = () => reject( req.error );
	} );
}

/**
 * Run a simple IDBRequest-based promise.
 *
 * @param {IDBRequest} req
 * @returns {Promise<any>}
 */
function promisify( req ) {
	return new Promise( ( resolve, reject ) => {
		req.onsuccess = () => resolve( req.result );
		req.onerror  = () => reject( req.error );
	} );
}

/**
 * Generate a UUID v4.
 *
 * Uses crypto.randomUUID() when available, falls back to a manual construction.
 *
 * @returns {string}
 */
function uuid() {
	if ( crypto?.randomUUID ) {
		return crypto.randomUUID();
	}
	// Fallback: RFC 4122 v4.
	return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace( /[xy]/g, ( c ) => {
		const r = ( Math.random() * 16 ) | 0;
		return ( c === 'x' ? r : ( r & 0x3 ) | 0x8 ).toString( 16 );
	} );
}

// ── Public API ────────────────────────────────────────────────────────────────

/**
 * Add an action to the offline queue.
 *
 * @param {'acknowledgment'|'execution'|'observation'} type
 * @param {object} payload  Action-specific data; building_id/post_id etc.
 * @returns {Promise<void>}
 */
export async function enqueue( type, payload ) {
	const db    = await openDb();
	const entry = { queue_uuid: uuid(), type, payload, queued_at: Date.now() };
	const tx    = db.transaction( STORE, 'readwrite' );
	await promisify( tx.objectStore( STORE ).add( entry ) );
}

/**
 * Return the number of actions currently in the queue.
 *
 * @returns {Promise<number>}
 */
export async function getPendingCount() {
	const db = await openDb();
	const tx = db.transaction( STORE, 'readonly' );
	return promisify( tx.objectStore( STORE ).count() );
}

/**
 * Attempt to flush all queued actions.
 *
 * Each entry is dispatched to the appropriate REST endpoint. Successfully
 * submitted entries are removed from the queue. If a submission fails the
 * entry remains for the next drain attempt.
 *
 * @returns {Promise<{ flushed: number, failed: number }>}
 */
export async function drainQueue() {
	const db      = await openDb();
	const tx      = db.transaction( STORE, 'readonly' );
	const entries = await promisify( tx.objectStore( STORE ).getAll() );

	let flushed = 0;
	let failed  = 0;

	for ( const entry of entries ) {
		try {
			await dispatchEntry( entry );

			const del = db.transaction( STORE, 'readwrite' );
			await promisify( del.objectStore( STORE ).delete( entry.queue_uuid ) );
			flushed++;
		} catch {
			failed++;
		}
	}

	return { flushed, failed };
}

/**
 * Dispatch a single queue entry to its REST endpoint.
 *
 * The `queue_uuid` is forwarded so the server can de-duplicate (spec §33.8).
 *
 * @param {{ type: string, payload: object, queue_uuid: string }} entry
 * @returns {Promise<void>}
 */
async function dispatchEntry( entry ) {
	const payload = { ...entry.payload, queue_uuid: entry.queue_uuid };

	switch ( entry.type ) {
		case 'acknowledgment':
			await postAcknowledgment( payload );
			break;
		case 'execution':
			await postExecution( payload.post_id, payload );
			break;
		case 'observation':
			await postObservation( payload.building_id, payload );
			break;
		default:
			throw new Error( `Unknown queue entry type: ${ entry.type }` );
	}
}
