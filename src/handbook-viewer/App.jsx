/**
 * Handbook viewer — main React component tree.
 *
 * Component hierarchy:
 *   App
 *   ├── BuildingHeader   — title, code, address, contacts, emergency
 *   ├── AreaTabs         — horizontal tab strip (hidden when only one area)
 *   ├── ContentList      — sorted content items for the active area
 *   │   └── ContentItemRow
 *   ├── ObservationPanel — open observations + add-observation form
 *   └── ContentModal     — full-screen overlay opened when an item is tapped
 *       ├── PageViewer       — rendered WP post HTML + acknowledgment bar
 *       ├── DocumentViewer   — PDF iframe or download link
 *       └── ProcessViewer    — diagram image + hotspot overlay + HotspotSheet
 */

import { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import Panzoom from '@panzoom/panzoom';
import {
	getBuildingBundle,
	postAcknowledgment,
	postExecution,
	getBuildingObservations,
	postObservation,
	getPageContent,
} from '@shared/api';
import { EXECUTION_OUTCOMES, CONTENT_TYPES, DISPLAY_MODES, HOTSPOT_KINDS } from '@shared/constants';
import { createBuildingSearch } from '@shared/search';

// ── App ───────────────────────────────────────────────────────────────────────

export default function App( { config } ) {
	const [ bundle,     setBundle     ] = useState( null );
	const [ loading,    setLoading    ] = useState( true );
	const [ error,      setError      ] = useState( null );
	const [ activeArea, setActiveArea ] = useState( null );
	const [ activeItem,  setActiveItem  ] = useState( null );
	const [ searchQuery, setSearchQuery ] = useState( '' );

	useEffect( () => {
		getBuildingBundle( config.buildingId )
			.then( ( data ) => {
				setBundle( data );
				if ( data.areas?.length ) {
					setActiveArea( data.areas[ 0 ].key );
				}
				setLoading( false );
			} )
			.catch( ( err ) => {
				setError( err.message || 'Failed to load building.' );
				setLoading( false );
			} );
	}, [ config.buildingId ] );

	// Build the Fuse.js searcher once after the bundle loads.
	const searcher = useMemo(
		() => bundle ? createBuildingSearch( bundle.areas ) : null,
		[ bundle ]
	);

	// Flat list of all items across all areas — used by LINK hotspot navigation.
	const allItems = useMemo(
		() => bundle ? bundle.areas.flatMap( ( a ) => a.items ) : [],
		[ bundle ]
	);

	if ( loading ) {
		return <div className="orgahb-hv-loading" aria-live="polite">Loading…</div>;
	}
	if ( error ) {
		return <div className="orgahb-hv-error" role="alert">{error}</div>;
	}
	if ( ! bundle ) {
		return null;
	}

	const { building, areas } = bundle;
	const currentArea = areas.find( ( a ) => a.key === activeArea );

	// When a search query is active, show matching items across all areas
	// instead of the normal area-scoped list.
	const isSearching    = searchQuery.trim().length >= 2;
	const searchResults  = isSearching && searcher ? searcher.search( searchQuery ) : null;

	return (
		<div className="orgahb-hv">
			<BuildingHeader building={building} />
			<SearchBar
				query={searchQuery}
				onChange={setSearchQuery}
			/>
			{ ! isSearching && (
				<AreaTabs areas={areas} active={activeArea} onSelect={setActiveArea} />
			) }
			{ isSearching ? (
				<SearchResults
					items={searchResults}
					query={searchQuery}
					onOpenItem={setActiveItem}
				/>
			) : currentArea ? (
				<ContentList
					area={currentArea}
					onOpenItem={setActiveItem}
				/>
			) : null }
			<ObservationPanel buildingId={config.buildingId} config={config} />
			{ activeItem && (
				<ContentModal
					item={activeItem}
					config={config}
					allItems={allItems}
					onOpenItem={setActiveItem}
					onClose={ () => setActiveItem( null ) }
				/>
			) }
		</div>
	);
}

// ── Building Header ───────────────────────────────────────────────────────────

function BuildingHeader( { building } ) {
	const { meta } = building;
	return (
		<header className="orgahb-hv-header">
			<h1 className="orgahb-hv-title">{ building.title }</h1>
			{ ( meta.code || meta.address ) && (
				<p className="orgahb-hv-subtitle">
					{ meta.code    && <span className="orgahb-hv-code">{ meta.code }</span> }
					{ meta.address && <span className="orgahb-hv-address">{ meta.address }</span> }
				</p>
			) }
			{ meta.emergency_notes && (
				<div className="orgahb-hv-emergency" role="note">
					<strong>Emergency:</strong> { meta.emergency_notes }
				</div>
			) }
			{ meta.contacts && (
				<details className="orgahb-hv-contacts">
					<summary>Contacts</summary>
					<pre>{ meta.contacts }</pre>
				</details>
			) }
		</header>
	);
}

// ── Search Bar ────────────────────────────────────────────────────────────────

function SearchBar( { query, onChange } ) {
	return (
		<div className="orgahb-hv-search">
			<label className="orgahb-hv-search-label" htmlFor="orgahb-search-input">
				<span className="screen-reader-text">Search this building</span>
			</label>
			<input
				id="orgahb-search-input"
				type="search"
				className="orgahb-hv-search-input"
				value={query}
				onChange={ ( e ) => onChange( e.target.value ) }
				placeholder="Search this building…"
				autoComplete="off"
				spellCheck="false"
			/>
			{ query && (
				<button
					className="orgahb-hv-search-clear"
					onClick={ () => onChange( '' ) }
					aria-label="Clear search"
				>
					×
				</button>
			) }
		</div>
	);
}

// ── Search Results ────────────────────────────────────────────────────────────

function SearchResults( { items, query, onOpenItem } ) {
	if ( ! items || items.length === 0 ) {
		return (
			<p className="orgahb-hv-search-empty">
				No results for <strong>{ query }</strong>.
			</p>
		);
	}

	return (
		<>
			<p className="orgahb-hv-search-count">
				{ items.length } result{ items.length !== 1 ? 's' : '' } for <strong>{ query }</strong>
			</p>
			<ul className="orgahb-hv-items" aria-label="Search results">
				{ items.map( ( item ) => (
					<ContentItemRow
						key={ item.link_id }
						item={item}
						onOpen={ () => onOpenItem( item ) }
					/>
				) ) }
			</ul>
		</>
	);
}

// ── Area Tabs ─────────────────────────────────────────────────────────────────

function AreaTabs( { areas, active, onSelect } ) {
	if ( areas.length <= 1 ) {
		return null;
	}
	return (
		<nav className="orgahb-hv-areas" role="tablist" aria-label="Building areas">
			{ areas.map( ( area ) => (
				<button
					key={area.key}
					role="tab"
					aria-selected={ area.key === active }
					className={ `orgahb-hv-area-tab${ area.key === active ? ' is-active' : '' }` }
					onClick={ () => onSelect( area.key ) }
				>
					{ area.label }
				</button>
			) ) }
		</nav>
	);
}

// ── Content List ──────────────────────────────────────────────────────────────

function ContentList( { area, onOpenItem } ) {
	// Featured items first, then by sort_order.
	const items = [ ...area.items ].sort( ( a, b ) => {
		if ( a.is_featured !== b.is_featured ) {
			return a.is_featured ? -1 : 1;
		}
		return a.sort_order - b.sort_order;
	} );

	if ( ! items.length ) {
		return <p className="orgahb-hv-empty">No content in this area.</p>;
	}

	return (
		<ul className="orgahb-hv-items" aria-label={ `${ area.label } content` }>
			{ items.map( ( item ) => (
				<ContentItemRow
					key={item.link_id}
					item={item}
					onOpen={ () => onOpenItem( item ) }
				/>
			) ) }
		</ul>
	);
}

function ContentItemRow( { item, onOpen } ) {
	const typeIcon = {
		[ CONTENT_TYPES.PAGE ]:     '📄',
		[ CONTENT_TYPES.PROCESS ]:  '🔧',
		[ CONTENT_TYPES.DOCUMENT ]: '📋',
	};
	const icon = typeIcon[ item.content_type ] || '•';

	// Review-overdue warning: next_review date is in the past (spec §21.3).
	const isOverdue = !! ( item.meta.next_review && item.meta.next_review < new Date().toISOString().slice( 0, 10 ) );

	return (
		<li className={ `orgahb-hv-item${ item.is_featured ? ' is-featured' : '' }${ isOverdue ? ' is-overdue' : '' }` }>
			<button className="orgahb-hv-item-btn" onClick={onOpen}>
				<span className="orgahb-hv-item-icon" aria-hidden="true">{ icon }</span>
				<span className="orgahb-hv-item-title">{ item.title }</span>
				{ item.is_featured && (
					<span className="orgahb-hv-featured-badge">Featured</span>
				) }
				{ item.meta.requires_ack && (
					<span
						className={ `orgahb-hv-ack-badge${ item.meta.user_has_acked ? ' is-acked' : '' }` }
						aria-label={ item.meta.user_has_acked ? 'Acknowledged' : 'Acknowledgment required' }
					>✓</span>
				) }
				{ isOverdue && (
					<span className="orgahb-hv-overdue-badge" aria-label="Review overdue">⚠</span>
				) }
			</button>
			{ item.advisory_interval_label && (
				<p className="orgahb-hv-interval">{ item.advisory_interval_label }</p>
			) }
			{ item.local_note && (
				<p className="orgahb-hv-note">{ item.local_note }</p>
			) }
		</li>
	);
}

// ── Content Modal ─────────────────────────────────────────────────────────────

function ContentModal( { item, config, allItems, onOpenItem, onClose } ) {
	// Close on backdrop click.
	const handleBackdrop = useCallback( ( e ) => {
		if ( e.target === e.currentTarget ) {
			onClose();
		}
	}, [ onClose ] );

	// Close on Escape.
	useEffect( () => {
		const handler = ( e ) => { if ( e.key === 'Escape' ) onClose(); };
		document.addEventListener( 'keydown', handler );
		return () => document.removeEventListener( 'keydown', handler );
	}, [ onClose ] );

	return (
		<div
			className="orgahb-hv-modal-backdrop"
			role="dialog"
			aria-modal="true"
			aria-label={ item.title }
			onClick={handleBackdrop}
		>
			<div className="orgahb-hv-modal">
				<div className="orgahb-hv-modal-header">
					<h2>{ item.title }</h2>
					<button
						className="orgahb-hv-modal-close"
						onClick={onClose}
						aria-label="Close"
					>
						×
					</button>
				</div>
				<div className="orgahb-hv-modal-body">
					{ item.content_type === CONTENT_TYPES.PAGE && (
						<PageViewer item={item} config={config} />
					) }
					{ item.content_type === CONTENT_TYPES.DOCUMENT && (
						<DocumentViewer item={item} />
					) }
					{ item.content_type === CONTENT_TYPES.PROCESS && (
						<ProcessViewer item={item} config={config} allItems={allItems} onOpenItem={onOpenItem} onClose={onClose} />
					) }
				</div>
			</div>
		</div>
	);
}

// ── Page Viewer ───────────────────────────────────────────────────────────────

function PageViewer( { item, config } ) {
	const [ html,     setHtml     ] = useState( null );
	const [ loading,  setLoading  ] = useState( true );
	const [ acked,    setAcked    ] = useState( !! item.meta.user_has_acked );
	const [ ackBusy,  setAckBusy  ] = useState( false );
	const [ ackError, setAckError ] = useState( null );

	useEffect( () => {
		getPageContent( item.content_id )
			.then( ( data ) => {
				setHtml( data.content?.rendered ?? '' );
				setLoading( false );
			} )
			.catch( () => {
				setHtml( '' );
				setLoading( false );
			} );
	}, [ item.content_id ] );

	const handleAck = useCallback( () => {
		if ( ! config.currentUser.canAck ) return;
		setAckBusy( true );
		setAckError( null );
		postAcknowledgment( {
			post_id:       item.content_id,
			revision_id:   item.meta.current_revision_id || item.content_id,
			version_label: item.meta.version_label || '',
			source:        'ui',
		} )
			.then( () => { setAcked( true ); setAckBusy( false ); } )
			.catch( ( err ) => {
				setAckError( err.message || 'Failed to acknowledge.' );
				setAckBusy( false );
			} );
	}, [ item, config ] );

	return (
		<div className="orgahb-hv-page-viewer">
			{ item.meta.version_label && (
				<p className="orgahb-hv-version">Version: { item.meta.version_label }</p>
			) }
			{ loading ? (
				<p>Loading…</p>
			) : (
				/* WordPress sanitises page content server-side before storage. */
				/* eslint-disable-next-line react/no-danger */
				<div
					className="orgahb-hv-page-content"
					dangerouslySetInnerHTML={ { __html: html } }
				/>
			) }
			{ item.meta.requires_ack && config.currentUser.canAck && ! acked && (
				<div className="orgahb-hv-ack-bar">
					{ ackError && <p className="orgahb-hv-ack-error" role="alert">{ ackError }</p> }
					<button
						className="orgahb-hv-ack-btn"
						onClick={handleAck}
						disabled={ ackBusy || loading }
					>
						{ ackBusy ? 'Acknowledging…' : 'Acknowledge' }
					</button>
				</div>
			) }
			{ acked && <p className="orgahb-hv-acked" aria-live="polite">✓ Acknowledged</p> }
		</div>
	);
}

// ── Document Viewer ───────────────────────────────────────────────────────────

function DocumentViewer( { item } ) {
	const { meta } = item;
	const containerRef = useRef( null );
	const [ pdfError, setPdfError ] = useState( null );
	const [ pdfLoading, setPdfLoading ] = useState( false );

	const isPdf    = meta.document_mime === 'application/pdf';
	const isInline = ( meta.display_mode || DISPLAY_MODES.PDF_INLINE ) === DISPLAY_MODES.PDF_INLINE;

	useEffect( () => {
		if ( ! isPdf || ! isInline || ! containerRef.current || ! meta.attachment_url ) return;

		setPdfLoading( true );
		setPdfError( null );

		// Dynamic import keeps pdfjs-dist out of the main bundle — it loads
		// only when a PDF document is actually opened (spec §27.4).
		import( /* webpackChunkName: "pdf" */ '@shared/pdf' )
			.then( ( { renderPdf } ) => renderPdf( meta.attachment_url, containerRef.current ) )
			.catch( () => setPdfError( 'Could not load PDF.' ) )
			.finally( () => setPdfLoading( false ) );
	}, [ meta.attachment_url, isPdf, isInline ] );

	if ( ! meta.attachment_url ) {
		return <p className="orgahb-hv-doc-missing">Document file not available.</p>;
	}

	return (
		<div className="orgahb-hv-doc-viewer">
			{ meta.version_label && (
				<p className="orgahb-hv-version">Version: { meta.version_label }</p>
			) }
			{ isPdf && isInline ? (
				<>
					{ pdfLoading && <p className="orgahb-hv-pdf-loading" aria-live="polite">Loading…</p> }
					{ pdfError  && <p className="orgahb-hv-error" role="alert">{ pdfError }</p> }
					<div ref={containerRef} className="orgahb-hv-pdf-canvas-wrap" />
					<p className="orgahb-hv-doc-download">
						<a href={ meta.attachment_url } target="_blank" rel="noreferrer">
							Download PDF
						</a>
					</p>
				</>
			) : (
				<p className="orgahb-hv-doc-download">
					<a
						href={ meta.attachment_url }
						target="_blank"
						rel="noreferrer"
						className="orgahb-hv-doc-link"
					>
						Open { item.title }
					</a>
				</p>
			) }
		</div>
	);
}

// ── Process Viewer ────────────────────────────────────────────────────────────

function ProcessViewer( { item, config, allItems, onOpenItem, onClose } ) {
	const [ activeHotspot, setActiveHotspot ] = useState( null );
	const wrapRef = useRef( null );
	const pzRef   = useRef( null );

	const hotspots = (() => {
		try {
			return JSON.parse( item.meta.hotspots_json || '[]' );
		} catch {
			return [];
		}
	})();

	// Attach Panzoom to the diagram wrap so image + hotspot layer transform together.
	useEffect( () => {
		const el = wrapRef.current;
		if ( ! el ) return;

		const pz = Panzoom( el, {
			maxScale:    5,
			contain:     'outside',
			// Let Panzoom own all touch events on the diagram; page scroll
			// happens outside this container.
			touchAction: 'none',
		} );
		pzRef.current = pz;

		// Wheel-to-zoom on desktop.
		const parent = el.parentElement;
		parent?.addEventListener( 'wheel', pz.zoomWithWheel, { passive: false } );

		return () => {
			parent?.removeEventListener( 'wheel', pz.zoomWithWheel );
			pz.destroy();
			pzRef.current = null;
		};
	}, [ item.meta.image_url, item.meta.image_svg_inline ] );

	const handleReset = useCallback( () => {
		pzRef.current?.reset( { animate: true } );
	}, [] );

	// Hotspot clicks must fire even when Panzoom captures pointer events.
	// We check pan distance to distinguish a tap from a drag.
	const pointerStart = useRef( null );
	const handlePointerDown = useCallback( ( e ) => {
		pointerStart.current = { x: e.clientX, y: e.clientY };
	}, [] );
	const handlePointerUp = useCallback( ( e, hs ) => {
		if ( ! pointerStart.current ) return;
		const dx = Math.abs( e.clientX - pointerStart.current.x );
		const dy = Math.abs( e.clientY - pointerStart.current.y );
		pointerStart.current = null;
		// Only treat as a tap if the pointer barely moved.
		if ( dx < 8 && dy < 8 ) {
			setActiveHotspot( hs );
		}
	}, [] );

	return (
		<div className="orgahb-hv-process-viewer">
			{ item.meta.image_url ? (
				<>
					<div className="orgahb-hv-process-outer">
						<div ref={wrapRef} className="orgahb-hv-process-diagram-wrap">
							{ item.meta.image_svg_inline ? (
								/* Sanitized inline SVG: scales with Panzoom, accessible (spec §28.1) */
								<div
									className="orgahb-hv-process-image orgahb-hv-process-svg"
									/* eslint-disable-next-line react/no-danger */
									dangerouslySetInnerHTML={ { __html: item.meta.image_svg_inline } }
									aria-label={ item.title }
									role="img"
								/>
							) : (
								<img
									src={ item.meta.image_url }
									alt={ item.title }
									className="orgahb-hv-process-image"
									draggable="false"
								/>
							) }
							{ hotspots.length > 0 && (
								<div className="orgahb-hv-hotspot-layer">
									{ hotspots.map( ( hs ) => (
										<button
											key={ hs.id }
											className={ `orgahb-hv-hotspot${ hs.kind === HOTSPOT_KINDS.STEP ? ' is-step' : ' is-link' }` }
											style={ {
												left:   `${ hs.x_pct }%`,
												top:    `${ hs.y_pct }%`,
												width:  `${ hs.w_pct }%`,
												height: `${ hs.h_pct }%`,
											} }
											onPointerDown={ handlePointerDown }
											onPointerUp={ ( e ) => handlePointerUp( e, hs ) }
											aria-label={ hs.label }
										/>
									) ) }
								</div>
							) }
						</div>
					</div>
					<div className="orgahb-hv-panzoom-controls">
						<button
							className="orgahb-hv-panzoom-reset"
							onClick={handleReset}
							aria-label="Reset diagram zoom"
						>
							⊙ Reset
						</button>
					</div>
					{ hotspots.length > 0 && (
						<details className="orgahb-hv-hotspot-list">
							<summary>Hotspots ({ hotspots.length })</summary>
							<ul>
								{ hotspots.map( ( hs ) => (
									<li key={ hs.id }>
										<button
											className="orgahb-hv-hotspot-list-btn"
											onClick={ () => setActiveHotspot( hs ) }
										>
											{ hs.label }
										</button>
										{ hs.description && (
											<span className="orgahb-hv-hotspot-list-desc"> — { hs.description }</span>
										) }
									</li>
								) ) }
							</ul>
						</details>
					) }
				</>
			) : (
				<p className="orgahb-hv-no-image">No diagram available.</p>
			) }
			{ activeHotspot && (
				<HotspotSheet
					hotspot={activeHotspot}
					item={item}
					config={config}
					allItems={allItems}
					onOpenItem={onOpenItem}
					onCloseProcess={onClose}
					onClose={ () => setActiveHotspot( null ) }
				/>
			) }
		</div>
	);
}

// ── Hotspot Bottom Sheet ──────────────────────────────────────────────────────

function HotspotSheet( { hotspot, item, config, allItems, onOpenItem, onCloseProcess, onClose } ) {
	const [ outcome, setOutcome ] = useState( 'completed' );
	const [ note,    setNote    ] = useState( '' );
	const [ busy,    setBusy    ] = useState( false );
	const [ done,    setDone    ] = useState( false );
	const [ error,   setError   ] = useState( null );

	const canLog =
		config.currentUser.canLog &&
		item.meta.is_field_executable &&
		hotspot.kind === HOTSPOT_KINDS.STEP;

	const handleLog = useCallback( () => {
		setBusy( true );
		setError( null );
		postExecution( item.content_id, {
			building_id:      config.buildingId,
			hotspot_id:       hotspot.id,
			outcome,
			post_revision_id: item.meta.current_revision_id || item.content_id,
			note,
			source:           'ui',
		} )
			.then( () => { setDone( true ); setBusy( false ); } )
			.catch( ( err ) => {
				setError( err.message || 'Failed to log evidence.' );
				setBusy( false );
			} );
	}, [ hotspot, item, config, outcome, note ] );

	return (
		<div
			className="orgahb-hv-sheet-backdrop"
			role="dialog"
			aria-modal="true"
			aria-label={ hotspot.label }
			onClick={ ( e ) => { if ( e.target === e.currentTarget ) onClose(); } }
		>
			<div className="orgahb-hv-sheet">
				<div className="orgahb-hv-sheet-header">
					<h3>{ hotspot.label }</h3>
					<button
						className="orgahb-hv-sheet-close"
						onClick={onClose}
						aria-label="Close"
					>
						×
					</button>
				</div>
				<div className="orgahb-hv-sheet-body">
					{ hotspot.description && <p>{ hotspot.description }</p> }
					{ canLog && ! done ? (
						<div className="orgahb-hv-exec-form">
							<label className="orgahb-hv-exec-label">
								Outcome
								<select
									value={outcome}
									onChange={ ( e ) => setOutcome( e.target.value ) }
								>
									{ EXECUTION_OUTCOMES.map( ( o ) => (
										<option key={ o.value } value={ o.value }>
											{ o.label }
										</option>
									) ) }
								</select>
							</label>
							<label className="orgahb-hv-exec-label">
								Note (optional)
								<textarea
									value={note}
									onChange={ ( e ) => setNote( e.target.value ) }
									rows={2}
									placeholder="Add a note…"
								/>
							</label>
							{ error && <p className="orgahb-hv-error" role="alert">{ error }</p> }
							<button
								className="orgahb-hv-exec-btn"
								onClick={handleLog}
								disabled={busy}
							>
								{ busy ? 'Logging…' : 'Log Evidence' }
							</button>
						</div>
					) : done ? (
						<p className="orgahb-hv-done" aria-live="polite">✓ Evidence logged.</p>
					) : hotspot.kind === HOTSPOT_KINDS.LINK ? (
						<LinkHotspotAction
							hotspot={hotspot}
							allItems={allItems}
							onOpenItem={onOpenItem}
							onCloseProcess={onCloseProcess}
							onClose={onClose}
						/>
					) : (
						<p className="orgahb-hv-hotspot-info">Process step.</p>
					) }
				</div>
			</div>
		</div>
	);
}

// ── Link Hotspot Action ───────────────────────────────────────────────────────

function LinkHotspotAction( { hotspot, allItems, onOpenItem, onCloseProcess, onClose } ) {
	// Resolve internal target unconditionally (hook rules require top-level calls).
	const internalTarget = useMemo(
		() => hotspot.target_id
			? allItems.find( ( i ) => i.content_id === hotspot.target_id ) ?? null
			: null,
		[ hotspot.target_id, allItems ]
	);

	const handleNavigate = useCallback( () => {
		if ( ! internalTarget ) return;
		onClose();        // close hotspot sheet
		onCloseProcess(); // close current process modal
		onOpenItem( internalTarget );
	}, [ internalTarget, onClose, onCloseProcess, onOpenItem ] );

	// External URL link.
	if ( hotspot.target_type === 'url' && hotspot.target_url ) {
		return (
			<p className="orgahb-hv-hotspot-info">
				<a
					href={ hotspot.target_url }
					target="_blank"
					rel="noreferrer"
					className="orgahb-hv-hotspot-link"
				>
					{ hotspot.label }
				</a>
			</p>
		);
	}

	// Internal content link.
	if ( hotspot.target_type && hotspot.target_id ) {
		if ( ! internalTarget ) {
			return <p className="orgahb-hv-hotspot-info">Linked content not available in this building.</p>;
		}
		return (
			<button className="orgahb-hv-hotspot-navigate" onClick={handleNavigate}>
				Open: { internalTarget.title }
			</button>
		);
	}

	return <p className="orgahb-hv-hotspot-info">Navigation link.</p>;
}

// ── Observations Panel ────────────────────────────────────────────────────────

function ObservationPanel( { buildingId, config } ) {
	const [ observations, setObservations ] = useState( [] );
	const [ showForm,     setShowForm     ] = useState( false );
	const [ loading,      setLoading      ] = useState( true );

	const fetchObs = useCallback( () => {
		getBuildingObservations( buildingId, { status: 'open_only', limit: 20 } )
			.then( ( data ) => { setObservations( data ); setLoading( false ); } )
			.catch( () => setLoading( false ) );
	}, [ buildingId ] );

	useEffect( () => { fetchObs(); }, [ fetchObs ] );

	const handleAdded = useCallback( () => {
		setShowForm( false );
		fetchObs();
	}, [ fetchObs ] );

	return (
		<section className="orgahb-hv-observations">
			<div className="orgahb-hv-obs-header">
				<h2>Observations</h2>
				{ config.currentUser.canObserve && (
					<button
						className="orgahb-hv-obs-add-btn"
						onClick={ () => setShowForm( ! showForm ) }
						aria-expanded={ showForm }
					>
						{ showForm ? 'Cancel' : '+ Add' }
					</button>
				) }
			</div>
			{ showForm && (
				<ObservationForm
					buildingId={buildingId}
					onAdded={handleAdded}
					onCancel={ () => setShowForm( false ) }
				/>
			) }
			{ loading ? (
				<p>Loading…</p>
			) : observations.length === 0 ? (
				<p className="orgahb-hv-obs-empty">No open observations.</p>
			) : (
				<ul className="orgahb-hv-obs-list">
					{ observations.map( ( obs ) => (
						<li
							key={ obs.id }
							className={ `orgahb-hv-obs-item orgahb-hv-obs-${ obs.status }` }
						>
							<span className="orgahb-hv-obs-summary">{ obs.summary }</span>
							{ obs.area_key && obs.area_key !== 'general' && (
								<span className="orgahb-hv-obs-area"> — { obs.area_key }</span>
							) }
							<span className="orgahb-hv-obs-status">{ obs.status }</span>
						</li>
					) ) }
				</ul>
			) }
		</section>
	);
}

// ── Add Observation Form ──────────────────────────────────────────────────────

function ObservationForm( { buildingId, onAdded, onCancel } ) {
	const [ summary,  setSummary  ] = useState( '' );
	const [ category, setCategory ] = useState( '' );
	const [ extRef,   setExtRef   ] = useState( '' );
	const [ busy,     setBusy     ] = useState( false );
	const [ error,    setError    ] = useState( null );

	const handleSubmit = useCallback( ( e ) => {
		e.preventDefault();
		if ( ! summary.trim() ) return;
		setBusy( true );
		setError( null );

		const data = { summary: summary.trim() };
		if ( category.trim() ) data.category           = category.trim();
		if ( extRef.trim() )   data.external_reference = extRef.trim();

		postObservation( buildingId, data )
			.then( () => onAdded() )
			.catch( ( err ) => {
				setError( err.message || 'Failed to save observation.' );
				setBusy( false );
			} );
	}, [ summary, category, extRef, buildingId, onAdded ] );

	return (
		<form className="orgahb-hv-obs-form" onSubmit={handleSubmit}>
			<label className="orgahb-hv-obs-label">
				Summary *
				<input
					type="text"
					value={summary}
					onChange={ ( e ) => setSummary( e.target.value ) }
					required
					placeholder="Describe the observation…"
				/>
			</label>
			<label className="orgahb-hv-obs-label">
				Category
				<input
					type="text"
					value={category}
					onChange={ ( e ) => setCategory( e.target.value ) }
					placeholder="e.g. maintenance, safety…"
				/>
			</label>
			<label className="orgahb-hv-obs-label">
				External reference
				<input
					type="text"
					value={extRef}
					onChange={ ( e ) => setExtRef( e.target.value ) }
					placeholder="CRM ticket ID or reference…"
				/>
			</label>
			{ error && <p className="orgahb-hv-error" role="alert">{ error }</p> }
			<div className="orgahb-hv-obs-form-actions">
				<button type="submit" disabled={ busy || ! summary.trim() }>
					{ busy ? 'Saving…' : 'Save Observation' }
				</button>
				<button type="button" onClick={onCancel}>Cancel</button>
			</div>
		</form>
	);
}
