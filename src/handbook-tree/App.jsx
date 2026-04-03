/**
 * Handbook tree viewer — desktop-first full org handbook.
 *
 * Component hierarchy:
 *   App
 *   ├── TopBar        — org name/logo, search input, user chip
 *   ├── Sidebar       — collapsible orgahb_section tree
 *   │   └── SectionNode (recursive)
 *   │       └── ContentItemNode
 *   └── ContentPanel  — inline viewer for selected item
 *       ├── MetadataHeader   — owner / valid-from / status chips
 *       ├── PageViewer       — rendered WP HTML + DocumentOutline + ack bar + Backlinks
 *       ├── DocumentViewer   — PDF inline or download + Backlinks
 *       └── ProcessViewer    — SVG + panzoom + hotspot overlay (read-only in tree ctx)
 *
 * Entry point: [orgahb_handbook] shortcode → #orgahb-handbook-tree mount point.
 */

import { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import Fuse from 'fuse.js';
import Panzoom from '@panzoom/panzoom';
import {
	getSectionsTree,
	postAcknowledgment,
	getPageContent,
	getItemBacklinks,
} from '@shared/api';
import { CONTENT_TYPES, DISPLAY_MODES, HOTSPOT_KINDS } from '@shared/constants';

// ── App ───────────────────────────────────────────────────────────────────────

export default function App( { config } ) {
	const [ tree,        setTree        ] = useState( null );
	const [ loading,     setLoading     ] = useState( true );
	const [ error,       setError       ] = useState( null );
	const [ activeItem,  setActiveItem  ] = useState( null );
	const [ searchQuery, setSearchQuery ] = useState( '' );

	// Track which section nodes are open (set of term IDs).
	const [ openSections, setOpenSections ] = useState( new Set() );

	useEffect( () => {
		getSectionsTree()
			.then( ( data ) => {
				setTree( data );
				// Auto-open all top-level sections on first load.
				const rootIds = new Set( ( data.sections || [] ).map( ( s ) => s.id ) );
				setOpenSections( rootIds );
				setLoading( false );
			} )
			.catch( ( err ) => {
				setError( err.message || 'Failed to load handbook.' );
				setLoading( false );
			} );
	}, [] );

	const toggleSection = useCallback( ( id ) => {
		setOpenSections( ( prev ) => {
			const next = new Set( prev );
			next.has( id ) ? next.delete( id ) : next.add( id );
			return next;
		} );
	}, [] );

	// Build Fuse index from all items in the tree for inline search.
	const fuse = useMemo( () => {
		if ( ! tree ) return null;
		const allItems = [];
		const collect = ( sections ) => {
			sections.forEach( ( s ) => {
				s.items.forEach( ( item ) => allItems.push( { ...item, _sectionName: s.name } ) );
				if ( s.children?.length ) collect( s.children );
			} );
		};
		collect( tree.sections || [] );
		return new Fuse( allItems, {
			keys: [ 'title', '_sectionName', 'meta.owner_name' ],
			threshold: 0.35,
			minMatchCharLength: 2,
		} );
	}, [ tree ] );

	const searchResults = useMemo( () => {
		if ( ! searchQuery.trim() || ! fuse ) return null;
		return fuse.search( searchQuery.trim() ).map( ( r ) => r.item );
	}, [ searchQuery, fuse ] );

	if ( loading ) {
		return <div className="orgahb-ht-loading" aria-live="polite">Loading handbook…</div>;
	}
	if ( error ) {
		return <div className="orgahb-ht-error" role="alert">{ error }</div>;
	}
	if ( ! tree ) return null;

	return (
		<div className="orgahb-ht-root">
			<TopBar
				orgName={ tree.org_name || config.orgName }
				query={searchQuery}
				onSearch={setSearchQuery}
				user={config.currentUser}
			/>
			<div className="orgahb-ht-body">
				<Sidebar
					sections={ tree.sections }
					searchResults={searchResults}
					isSearching={ !! searchQuery }
					openSections={openSections}
					activeItem={activeItem}
					onToggle={toggleSection}
					onSelect={setActiveItem}
				/>
				<ContentPanel
					item={activeItem}
					config={config}
				/>
			</div>
		</div>
	);
}

// ── Top Bar ───────────────────────────────────────────────────────────────────

function TopBar( { orgName, query, onSearch, user } ) {
	return (
		<header className="orgahb-ht-topbar">
			<div className="orgahb-ht-topbar-brand">
				<span className="orgahb-ht-topbar-icon" aria-hidden="true">🏢</span>
				<div>
					<span className="orgahb-ht-topbar-name">{ orgName }</span>
					<span className="orgahb-ht-topbar-sub">Handbuch</span>
				</div>
			</div>
			<div className="orgahb-ht-topbar-search">
				<label htmlFor="orgahb-ht-search" className="screen-reader-text">
					Search handbook
				</label>
				<span className="orgahb-ht-search-icon" aria-hidden="true">🔍</span>
				<input
					id="orgahb-ht-search"
					type="search"
					className="orgahb-ht-search-input"
					value={query}
					onChange={ ( e ) => onSearch( e.target.value ) }
					placeholder="Suchen…"
					autoComplete="off"
					spellCheck="false"
				/>
				{ query && (
					<button
						className="orgahb-ht-search-clear"
						onClick={ () => onSearch( '' ) }
						aria-label="Clear search"
					>×</button>
				) }
			</div>
			<div className="orgahb-ht-topbar-user" aria-label={ user.name }>
				<span className="orgahb-ht-user-avatar">
					{ ( user.name || 'U' ).slice( 0, 2 ).toUpperCase() }
				</span>
			</div>
		</header>
	);
}

// ── Sidebar ───────────────────────────────────────────────────────────────────

function Sidebar( { sections, searchResults, isSearching, openSections, activeItem, onToggle, onSelect } ) {
	return (
		<nav className="orgahb-ht-sidebar" aria-label="Handbook navigation">
			<p className="orgahb-ht-nav-label">NAVIGATION</p>
			{ isSearching ? (
				<SearchResultsList
					items={searchResults}
					activeItem={activeItem}
					onSelect={onSelect}
				/>
			) : (
				<ul className="orgahb-ht-tree" role="tree">
					{ sections.map( ( section ) => (
						<SectionNode
							key={ section.id }
							section={section}
							depth={0}
							openSections={openSections}
							activeItem={activeItem}
							onToggle={onToggle}
							onSelect={onSelect}
						/>
					) ) }
				</ul>
			) }
		</nav>
	);
}

// ── Search Results List (sidebar) ─────────────────────────────────────────────

function SearchResultsList( { items, activeItem, onSelect } ) {
	if ( ! items || items.length === 0 ) {
		return <p className="orgahb-ht-search-empty">No results.</p>;
	}
	return (
		<ul className="orgahb-ht-tree" role="listbox">
			{ items.map( ( item ) => (
				<ContentItemNode
					key={ item.content_id }
					item={item}
					depth={0}
					isActive={ activeItem?.content_id === item.content_id }
					onSelect={onSelect}
				/>
			) ) }
		</ul>
	);
}

// ── Section Node ──────────────────────────────────────────────────────────────

function SectionNode( { section, depth, openSections, activeItem, onToggle, onSelect } ) {
	const isOpen     = openSections.has( section.id );
	const hasContent = section.items.length > 0 || section.children.length > 0;

	return (
		<li
			className="orgahb-ht-section"
			role="treeitem"
			aria-expanded={ hasContent ? isOpen : undefined }
			style={ { '--depth': depth } }
		>
			<button
				className="orgahb-ht-section-btn"
				onClick={ () => onToggle( section.id ) }
				aria-label={ `${ isOpen ? 'Collapse' : 'Expand' } ${ section.name }` }
			>
				<span className="orgahb-ht-section-arrow" aria-hidden="true">
					{ hasContent ? ( isOpen ? '▾' : '▸' ) : ' ' }
				</span>
				<span className="orgahb-ht-section-icon" aria-hidden="true">📁</span>
				<span className="orgahb-ht-section-name">{ section.name }</span>
				{ section.items.length > 0 && (
					<span className="orgahb-ht-section-count">{ section.items.length }</span>
				) }
			</button>

			{ isOpen && hasContent && (
				<ul className="orgahb-ht-tree-children" role="group">
					{ section.items.map( ( item ) => (
						<ContentItemNode
							key={ item.content_id }
							item={item}
							depth={ depth + 1 }
							isActive={ activeItem?.content_id === item.content_id }
							onSelect={onSelect}
						/>
					) ) }
					{ section.children.map( ( child ) => (
						<SectionNode
							key={ child.id }
							section={child}
							depth={ depth + 1 }
							openSections={openSections}
							activeItem={activeItem}
							onToggle={onToggle}
							onSelect={onSelect}
						/>
					) ) }
				</ul>
			) }
		</li>
	);
}

// ── Content Item Node ─────────────────────────────────────────────────────────

const ITEM_ICONS = {
	[ CONTENT_TYPES.PAGE ]:     '📄',
	[ CONTENT_TYPES.PROCESS ]:  '⚙️',
	[ CONTENT_TYPES.DOCUMENT ]: '📋',
};

function ContentItemNode( { item, depth, isActive, onSelect } ) {
	const icon    = ITEM_ICONS[ item.content_type ] || '•';
	const isOverdue = !! (
		item.meta.next_review &&
		item.meta.next_review < new Date().toISOString().slice( 0, 10 )
	);

	return (
		<li
			className={ `orgahb-ht-item${ isActive ? ' is-active' : '' }${ isOverdue ? ' is-overdue' : '' }` }
			role="treeitem"
			aria-selected={isActive}
			style={ { '--depth': depth } }
		>
			<button
				className="orgahb-ht-item-btn"
				onClick={ () => onSelect( item ) }
				aria-current={ isActive ? 'page' : undefined }
			>
				<span className="orgahb-ht-item-icon" aria-hidden="true">{ icon }</span>
				<span className="orgahb-ht-item-title">{ item.title }</span>
				{ item.meta.requires_ack && (
					<span
						className={ `orgahb-ht-ack-dot${ item.meta.user_has_acked ? ' is-acked' : '' }` }
						aria-label={ item.meta.user_has_acked ? 'Acknowledged' : 'Acknowledgment required' }
					/>
				) }
				{ isOverdue && (
					<span className="orgahb-ht-overdue" aria-label="Review overdue">⚠</span>
				) }
			</button>
		</li>
	);
}

// ── Content Panel ─────────────────────────────────────────────────────────────

function ContentPanel( { item, config } ) {
	if ( ! item ) {
		return (
			<main className="orgahb-ht-content orgahb-ht-content--empty">
				<div className="orgahb-ht-welcome">
					<span className="orgahb-ht-welcome-icon" aria-hidden="true">📖</span>
					<h2>Organisationshandbuch</h2>
					<p>Wählen Sie einen Eintrag aus der Navigation.</p>
				</div>
			</main>
		);
	}

	return (
		<main className="orgahb-ht-content" id="orgahb-ht-main" tabIndex={-1}>
			<MetadataHeader item={item} />
			<div className="orgahb-ht-content-body">
				{ item.content_type === CONTENT_TYPES.PAGE && (
					<PageViewer item={item} config={config} />
				) }
				{ item.content_type === CONTENT_TYPES.DOCUMENT && (
					<DocumentViewer item={item} />
				) }
				{ item.content_type === CONTENT_TYPES.PROCESS && (
					<ProcessViewer item={item} />
				) }
			</div>
		</main>
	);
}

// ── Metadata Header ───────────────────────────────────────────────────────────

function MetadataHeader( { item } ) {
	const { meta, status, title } = item;

	const statusLabel = {
		publish:         'Veröffentlicht',
		pending:         'Zur Prüfung',
		draft:           'Entwurf',
		orgahb_archived: 'Archiviert',
	}[ status ] ?? status;

	const chips = [
		meta.owner_name    && { key: 'owner',   label: 'Verantwortlich', value: meta.owner_name },
		meta.valid_from    && { key: 'from',     label: 'Gültig ab',      value: meta.valid_from },
		meta.valid_until   && { key: 'until',    label: 'Gültig bis',     value: meta.valid_until },
		meta.next_review   && { key: 'review',   label: 'Nächste Prüfung',value: meta.next_review },
		meta.version_label && { key: 'version',  label: 'Version',        value: meta.version_label },
	].filter( Boolean );

	return (
		<div className="orgahb-ht-meta-header">
			<div className="orgahb-ht-meta-title-row">
				<h1 className="orgahb-ht-meta-title">{ title }</h1>
				{ status && (
					<span className={ `orgahb-ht-meta-status orgahb-ht-meta-status--${ status.replace( '_', '-' ) }` }>
						{ statusLabel }
					</span>
				) }
				{ meta.version_label && (
					<span className="orgahb-ht-meta-id">
						{ item.content_type.toUpperCase().slice( 0, 4 ) }-{ item.content_id }
					</span>
				) }
			</div>
			{ chips.length > 0 && (
				<div className="orgahb-ht-meta-chips">
					{ chips.map( ( chip ) => (
						<span key={chip.key} className="orgahb-ht-meta-chip">
							<span className="orgahb-ht-meta-chip-label">{ chip.label }</span>
							<span className="orgahb-ht-meta-chip-value">{ chip.value }</span>
						</span>
					) ) }
				</div>
			) }
			{ meta.change_log && (
				<details className="orgahb-ht-meta-changelog">
					<summary>Änderungsprotokoll</summary>
					<p>{ meta.change_log }</p>
				</details>
			) }
		</div>
	);
}

// ── Page Viewer ───────────────────────────────────────────────────────────────

function PageViewer( { item, config } ) {
	const [ html,     setHtml    ] = useState( null );
	const [ loading,  setLoading ] = useState( true );
	const [ acked,    setAcked   ] = useState( !! item.meta.user_has_acked );
	const [ ackBusy,  setAckBusy ] = useState( false );
	const [ ackError, setAckError] = useState( null );
	const contentRef = useRef( null );

	useEffect( () => {
		setLoading( true );
		setHtml( null );
		getPageContent( item.content_id )
			.then( ( data ) => { setHtml( data.content?.rendered ?? '' ); setLoading( false ); } )
			.catch( () => { setHtml( '' ); setLoading( false ); } );
	}, [ item.content_id ] );

	// Reset ack state when item changes.
	useEffect( () => { setAcked( !! item.meta.user_has_acked ); }, [ item ] );

	const handleAck = useCallback( () => {
		setAckBusy( true );
		setAckError( null );
		postAcknowledgment( {
			post_id:       item.content_id,
			revision_id:   item.meta.current_revision_id || item.content_id,
			version_label: item.meta.version_label || '',
			source:        'tree',
		} )
			.then( () => { setAcked( true ); setAckBusy( false ); } )
			.catch( ( err ) => { setAckError( err.message || 'Fehler.' ); setAckBusy( false ); } );
	}, [ item, config ] );

	return (
		<div className="orgahb-ht-page-viewer">
			{ ! loading && html && <DocumentOutline contentRef={contentRef} /> }
			{ loading ? (
				<p className="orgahb-ht-loading-inline">Lädt…</p>
			) : (
				/* WP sanitises page content server-side before storage. */
				/* eslint-disable-next-line react/no-danger */
				<div
					ref={contentRef}
					className="orgahb-ht-page-content"
					dangerouslySetInnerHTML={ { __html: html } }
				/>
			) }
			{ item.meta.requires_ack && config.currentUser.canAck && ! acked && (
				<div className="orgahb-ht-ack-bar">
					{ ackError && <p className="orgahb-ht-error" role="alert">{ ackError }</p> }
					<button
						className="orgahb-ht-ack-btn"
						onClick={handleAck}
						disabled={ ackBusy || loading }
					>
						{ ackBusy ? 'Bestätige…' : '✓ Gelesen und verstanden' }
					</button>
				</div>
			) }
			{ acked && (
				<p className="orgahb-ht-acked" aria-live="polite">✓ Bestätigt</p>
			) }
			<Backlinks itemId={item.content_id} />
		</div>
	);
}

// ── Document Viewer ───────────────────────────────────────────────────────────

function DocumentViewer( { item } ) {
	const { meta } = item;
	const containerRef = useRef( null );
	const [ pdfLoading, setPdfLoading ] = useState( false );
	const [ pdfError,   setPdfError   ] = useState( null );

	const isPdf    = meta.document_mime === 'application/pdf';
	const isInline = ( meta.display_mode || DISPLAY_MODES.PDF_INLINE ) === DISPLAY_MODES.PDF_INLINE;

	useEffect( () => {
		if ( ! isPdf || ! isInline || ! containerRef.current || ! meta.attachment_url ) return;
		setPdfLoading( true );
		setPdfError( null );
		import( /* webpackChunkName: "pdf" */ '@shared/pdf' )
			.then( ( { renderPdf } ) => renderPdf( meta.attachment_url, containerRef.current ) )
			.catch( () => setPdfError( 'PDF konnte nicht geladen werden.' ) )
			.finally( () => setPdfLoading( false ) );
	}, [ meta.attachment_url, isPdf, isInline ] );

	if ( ! meta.attachment_url ) {
		return <p className="orgahb-ht-doc-missing">Dokument nicht verfügbar.</p>;
	}

	return (
		<div className="orgahb-ht-doc-viewer">
			{ isPdf && isInline ? (
				<>
					{ pdfLoading && <p aria-live="polite">Lädt…</p> }
					{ pdfError   && <p className="orgahb-ht-error" role="alert">{ pdfError }</p> }
					<div ref={containerRef} className="orgahb-ht-pdf-canvas-wrap" />
					<p className="orgahb-ht-doc-download">
						<a href={ meta.attachment_url } target="_blank" rel="noreferrer">PDF herunterladen</a>
					</p>
				</>
			) : (
				<p className="orgahb-ht-doc-download">
					<a href={ meta.attachment_url } target="_blank" rel="noreferrer" className="orgahb-ht-doc-link">
						{ item.title } öffnen
					</a>
				</p>
			) }
			<Backlinks itemId={item.content_id} />
		</div>
	);
}

// ── Process Viewer ────────────────────────────────────────────────────────────

/**
 * Read-only process viewer in the tree context — no execution logging since
 * there is no building context. Panzoom + hotspot overlay for navigation.
 */
function ProcessViewer( { item } ) {
	const [ activeHotspot, setActiveHotspot ] = useState( null );
	const wrapRef = useRef( null );
	const pzRef   = useRef( null );

	const hotspots = useMemo( () => {
		try { return JSON.parse( item.meta.hotspots_json || '[]' ); }
		catch { return []; }
	}, [ item.meta.hotspots_json ] );

	useEffect( () => {
		const el = wrapRef.current;
		if ( ! el ) return;
		const pz = Panzoom( el, { maxScale: 5, contain: 'outside', touchAction: 'none' } );
		pzRef.current = pz;
		const parent = el.parentElement;
		parent?.addEventListener( 'wheel', pz.zoomWithWheel, { passive: false } );
		return () => {
			parent?.removeEventListener( 'wheel', pz.zoomWithWheel );
			pz.destroy();
		};
	}, [ item.meta.image_url, item.meta.image_svg_inline ] );

	const pointerStart = useRef( null );
	const handlePointerDown = useCallback( ( e ) => {
		pointerStart.current = { x: e.clientX, y: e.clientY };
	}, [] );
	const handlePointerUp = useCallback( ( e, hs ) => {
		if ( ! pointerStart.current ) return;
		const dx = Math.abs( e.clientX - pointerStart.current.x );
		const dy = Math.abs( e.clientY - pointerStart.current.y );
		pointerStart.current = null;
		if ( dx < 8 && dy < 8 ) setActiveHotspot( hs );
	}, [] );

	const handleReset = useCallback( () => pzRef.current?.reset( { animate: true } ), [] );

	if ( ! item.meta.image_url ) {
		return <p className="orgahb-ht-no-image">Kein Diagramm verfügbar.</p>;
	}

	return (
		<div className="orgahb-ht-process-viewer">
			<div className="orgahb-ht-process-outer">
				<div ref={wrapRef} className="orgahb-ht-process-diagram-wrap">
					{ item.meta.image_svg_inline ? (
						<div
							className="orgahb-ht-process-image orgahb-ht-process-svg"
							/* eslint-disable-next-line react/no-danger */
							dangerouslySetInnerHTML={ { __html: item.meta.image_svg_inline } }
							aria-label={ item.title }
							role="img"
						/>
					) : (
						<img
							src={ item.meta.image_url }
							alt={ item.title }
							className="orgahb-ht-process-image"
							draggable="false"
						/>
					) }
					{ hotspots.length > 0 && (
						<div className="orgahb-ht-hotspot-layer">
							{ hotspots.map( ( hs ) => (
								<button
									key={ hs.id }
									className={ `orgahb-ht-hotspot${ hs.kind === HOTSPOT_KINDS.STEP ? ' is-step' : ' is-link' }` }
									style={ {
										left: `${ hs.x_pct }%`, top: `${ hs.y_pct }%`,
										width: `${ hs.w_pct }%`, height: `${ hs.h_pct }%`,
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
			<div className="orgahb-ht-panzoom-controls">
				<button className="orgahb-ht-panzoom-reset" onClick={handleReset} aria-label="Zoom zurücksetzen">
					⊙ Reset
				</button>
			</div>
			{ hotspots.length > 0 && (
				<details className="orgahb-ht-hotspot-list">
					<summary>Hotspots ({ hotspots.length })</summary>
					<ul>
						{ hotspots.map( ( hs ) => (
							<li key={ hs.id }>
								<button className="orgahb-ht-hotspot-list-btn" onClick={ () => setActiveHotspot( hs ) }>
									{ hs.label }
								</button>
								{ hs.description && (
									<span className="orgahb-ht-hotspot-list-desc"> — { hs.description }</span>
								) }
							</li>
						) ) }
					</ul>
				</details>
			) }
			{ activeHotspot && (
				<HotspotInfo hotspot={activeHotspot} onClose={ () => setActiveHotspot( null ) } />
			) }
		</div>
	);
}

// ── Hotspot Info Panel (read-only) ────────────────────────────────────────────

/**
 * Read-only hotspot detail panel for the tree viewer.
 * No execution logging — shows description and link targets only.
 */
function HotspotInfo( { hotspot, onClose } ) {
	return (
		<div
			className="orgahb-ht-sheet-backdrop"
			role="dialog"
			aria-modal="true"
			aria-label={ hotspot.label }
			onClick={ ( e ) => { if ( e.target === e.currentTarget ) onClose(); } }
		>
			<div className="orgahb-ht-sheet">
				<div className="orgahb-ht-sheet-header">
					<h3>{ hotspot.label }</h3>
					<button className="orgahb-ht-sheet-close" onClick={onClose} aria-label="Schließen">×</button>
				</div>
				<div className="orgahb-ht-sheet-body">
					{ hotspot.description && <p>{ hotspot.description }</p> }
					{ hotspot.kind === HOTSPOT_KINDS.STEP && (
						<p className="orgahb-ht-hotspot-info">Prozessschritt</p>
					) }
					{ hotspot.kind === HOTSPOT_KINDS.LINK && hotspot.target_url && (
						<a
							href={ hotspot.target_url }
							target="_blank"
							rel="noreferrer"
							className="orgahb-ht-hotspot-link"
						>
							{ hotspot.label }
						</a>
					) }
				</div>
			</div>
		</div>
	);
}

// ── Document Outline ──────────────────────────────────────────────────────────

function DocumentOutline( { contentRef } ) {
	const [ headings, setHeadings ] = useState( [] );
	const [ activeId, setActiveId ] = useState( null );
	const [ maxLevel, setMaxLevel ] = useState( 3 );
	const [ open,     setOpen     ] = useState( true );

	useEffect( () => {
		const el = contentRef.current;
		if ( ! el ) return;
		const raf = requestAnimationFrame( () => {
			const nodes = Array.from( el.querySelectorAll( 'h2, h3' ) );
			const items = nodes.map( ( node, i ) => {
				if ( ! node.id ) node.id = `orgahb-ht-outline-${ i }`;
				return { id: node.id, text: node.textContent.trim(), level: parseInt( node.tagName[ 1 ], 10 ) };
			} );
			setHeadings( items );
		} );
		return () => cancelAnimationFrame( raf );
	}, [ contentRef ] );

	useEffect( () => {
		if ( ! headings.length ) return;
		const el = contentRef.current;
		if ( ! el ) return;
		const ids   = headings.map( ( h ) => h.id );
		const nodes = ids.map( ( id ) => el.querySelector( `#${ id }` ) ).filter( Boolean );
		const visible = new Set();
		const observer = new IntersectionObserver(
			( entries ) => {
				entries.forEach( ( e ) => { e.isIntersecting ? visible.add( e.target.id ) : visible.delete( e.target.id ); } );
				const first = ids.find( ( id ) => visible.has( id ) );
				if ( first ) setActiveId( first );
			},
			{ rootMargin: '0px 0px -60% 0px', threshold: 0 }
		);
		nodes.forEach( ( n ) => observer.observe( n ) );
		return () => observer.disconnect();
	}, [ headings, contentRef ] );

	const visible = headings.filter( ( h ) => h.level <= maxLevel );
	if ( visible.length < 2 ) return null;

	return (
		<nav className={ `orgahb-ht-outline${ open ? ' is-open' : '' }` } aria-label="Gliederung">
			<div className="orgahb-ht-outline-toolbar">
				<button className="orgahb-ht-outline-toggle" onClick={ () => setOpen( ( v ) => ! v ) } aria-expanded={open}>
					{ open ? '▾ Gliederung' : '▸ Gliederung' }
				</button>
				{ open && (
					<span className="orgahb-ht-outline-levels">
						<button className={ `orgahb-ht-outline-level-btn${ maxLevel === 2 ? ' is-active' : '' }` } onClick={ () => setMaxLevel( 2 ) } aria-pressed={ maxLevel === 2 }>H2</button>
						<button className={ `orgahb-ht-outline-level-btn${ maxLevel === 3 ? ' is-active' : '' }` } onClick={ () => setMaxLevel( 3 ) } aria-pressed={ maxLevel === 3 }>H2+H3</button>
					</span>
				) }
			</div>
			{ open && (
				<ul className="orgahb-ht-outline-list">
					{ visible.map( ( h ) => (
						<li key={h.id} className={ `orgahb-ht-outline-item is-h${ h.level }${ h.id === activeId ? ' is-active' : '' }` }>
							<a
								href={ `#${ h.id }` }
								className="orgahb-ht-outline-link"
								aria-current={ h.id === activeId ? 'location' : undefined }
								onClick={ ( e ) => { e.preventDefault(); document.getElementById( h.id )?.scrollIntoView( { behavior: 'smooth', block: 'start' } ); } }
							>
								{ h.text }
							</a>
						</li>
					) ) }
				</ul>
			) }
		</nav>
	);
}

// ── Backlinks ─────────────────────────────────────────────────────────────────

function Backlinks( { itemId } ) {
	const [ links,   setLinks   ] = useState( null );
	const [ loading, setLoading ] = useState( false );
	const [ open,    setOpen    ] = useState( false );

	const handleToggle = useCallback( () => {
		setOpen( ( v ) => {
			if ( ! v && links === null ) {
				setLoading( true );
				getItemBacklinks( itemId )
					.then( ( data ) => { setLinks( data ); setLoading( false ); } )
					.catch( () => { setLinks( [] ); setLoading( false ); } );
			}
			return ! v;
		} );
	}, [ itemId, links ] );

	return (
		<section className="orgahb-ht-backlinks">
			<button className="orgahb-ht-backlinks-toggle" onClick={handleToggle} aria-expanded={open}>
				{ open ? '▾' : '▸' } Wird referenziert von
				{ links?.length > 0 && <span className="orgahb-ht-backlinks-count">{ links.length }</span> }
			</button>
			{ open && (
				<div className="orgahb-ht-backlinks-body">
					{ loading && <p>Lädt…</p> }
					{ ! loading && links !== null && links.length === 0 && (
						<p className="orgahb-ht-backlinks-empty">Keine Verknüpfungen gefunden.</p>
					) }
					{ ! loading && links?.length > 0 && (
						<ul className="orgahb-ht-backlinks-list">
							{ links.map( ( bl ) => (
								<li key={ bl.content_id } className="orgahb-ht-backlinks-item">
									<span aria-hidden="true">⚙️</span> { bl.title }
								</li>
							) ) }
						</ul>
					) }
				</div>
			) }
		</section>
	);
}
