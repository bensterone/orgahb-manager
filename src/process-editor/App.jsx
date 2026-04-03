/**
 * Process hotspot editor — main component.
 *
 * Renders an interactive overlay on top of the process diagram image.
 * Hotspots are created by drawing rectangles (mousedown → drag → mouseup).
 * Existing hotspots can be moved and resized via interact.js.
 * All changes are written back to the hidden <textarea id="orgahb_hotspots_json">
 * so the WP save form picks them up unchanged.
 *
 * Spec: §17 Hotspot Fields and Coordinate Rules.
 */

import { useState, useEffect, useRef, useCallback } from 'react';
import interact from 'interactjs';
import { HOTSPOT_KINDS } from '@shared/constants';

// ── Helpers ───────────────────────────────────────────────────────────────────

let _idCounter = 0;

function generateId() {
	_idCounter += 1;
	return `hs_${ Date.now() }_${ _idCounter }`;
}

function clamp( value, min, max ) {
	return Math.max( min, Math.min( max, value ) );
}

function parseHotspots( json ) {
	if ( ! json || ! json.trim() ) return [];
	try {
		const parsed = JSON.parse( json );
		return Array.isArray( parsed ) ? parsed : [];
	} catch {
		return [];
	}
}

function syncToTextarea( hotspots ) {
	const ta = document.getElementById( 'orgahb_hotspots_json' );
	if ( ! ta ) return;
	ta.value = JSON.stringify( hotspots, null, 2 );
	ta.dispatchEvent( new Event( 'change', { bubbles: true } ) );
}

// ── App ───────────────────────────────────────────────────────────────────────

export default function App( { config } ) {
	const { imageUrl, imageId } = config;

	const [ hotspots,   setHotspots   ] = useState( () => parseHotspots( config.hotspotsJson ) );
	const [ selected,   setSelected   ] = useState( null );
	const [ imgSize,    setImgSize    ] = useState( null );
	const [ noImage,    setNoImage    ] = useState( ! imageUrl );
	const [ liveImgUrl, setLiveImgUrl ] = useState( imageUrl );

	// Drawing state — live preview while dragging.
	const [ drawing,    setDrawing    ] = useState( null );  // { x, y, w, h } in pct, or null
	const drawStartRef = useRef( null );                     // { x_pct, y_pct }
	const isMouseDownRef = useRef( false );

	const wrapRef = useRef( null );
	const imgRef  = useRef( null );

	// Sync hotspots → textarea on every change.
	useEffect( () => {
		syncToTextarea( hotspots );
	}, [ hotspots ] );

	// Listen for the metabox image-picker replacing the image.
	useEffect( () => {
		const handler = ( e ) => {
			setLiveImgUrl( e.detail.url );
			setNoImage( false );
			setHotspots( ( prev ) => prev.map( ( hs ) => ( { ...hs, _needs_review: true } ) ) );
		};
		document.addEventListener( 'orgahb:process_image_changed', handler );
		return () => document.removeEventListener( 'orgahb:process_image_changed', handler );
	}, [] );

	const onImageLoad = useCallback( () => {
		if ( imgRef.current ) {
			setImgSize( {
				w: imgRef.current.offsetWidth,
				h: imgRef.current.offsetHeight,
			} );
		}
	}, [] );

	// ── Draw-to-create rectangle ──────────────────────────────────────────────

	const getPctPos = useCallback( ( e ) => {
		if ( ! imgRef.current ) return null;
		const rect = imgRef.current.getBoundingClientRect();
		return {
			x_pct: clamp( ( ( e.clientX - rect.left ) / rect.width  ) * 100, 0, 100 ),
			y_pct: clamp( ( ( e.clientY - rect.top  ) / rect.height ) * 100, 0, 100 ),
		};
	}, [] );

	const handlePointerDown = useCallback( ( e ) => {
		// Only draw on the image or the empty wrapper — not on existing hotspots.
		if ( e.target !== imgRef.current && ! e.target.classList.contains( 'orgahb-pe-image-wrap' ) ) return;
		if ( ! imgSize ) return;
		e.preventDefault();
		e.currentTarget.setPointerCapture( e.pointerId );

		const pos = getPctPos( e );
		if ( ! pos ) return;

		isMouseDownRef.current = true;
		drawStartRef.current = pos;
		setDrawing( { x: pos.x_pct, y: pos.y_pct, w: 0, h: 0 } );
		setSelected( null );
	}, [ imgSize, getPctPos ] );

	const handlePointerMove = useCallback( ( e ) => {
		if ( ! isMouseDownRef.current || ! drawStartRef.current ) return;
		const pos = getPctPos( e );
		if ( ! pos ) return;

		const start = drawStartRef.current;
		setDrawing( {
			x: Math.min( start.x_pct, pos.x_pct ),
			y: Math.min( start.y_pct, pos.y_pct ),
			w: Math.abs( pos.x_pct - start.x_pct ),
			h: Math.abs( pos.y_pct - start.y_pct ),
		} );
	}, [ getPctPos ] );

	const handlePointerUp = useCallback( ( e ) => {
		if ( ! isMouseDownRef.current ) return;
		isMouseDownRef.current = false;

		const pos = getPctPos( e );
		const start = drawStartRef.current;
		drawStartRef.current = null;
		setDrawing( null );

		if ( ! pos || ! start ) return;

		const x_pct = Math.min( start.x_pct, pos.x_pct );
		const y_pct = Math.min( start.y_pct, pos.y_pct );
		const w_pct = Math.abs( pos.x_pct - start.x_pct );
		const h_pct = Math.abs( pos.y_pct - start.y_pct );

		// Require minimum 2% in each dimension — prevents accidental clicks.
		if ( w_pct < 2 || h_pct < 2 ) return;

		const newHotspot = {
			id:            generateId(),
			label:         'New step',
			kind:          HOTSPOT_KINDS.STEP,
			x_pct,
			y_pct,
			w_pct,
			h_pct,
			sort_order:    hotspots.length,
			target_type:   null,
			target_id:     null,
			target_url:    null,
			help_text:     '',
			note_required: false,
			aliases:       '',
		};

		setHotspots( ( prev ) => [ ...prev, newHotspot ] );
		setSelected( newHotspot.id );
	}, [ getPctPos, hotspots.length ] );

	// ── Interact.js: drag + resize for existing hotspots ─────────────────────

	const setupInteract = useCallback( ( el, hsId ) => {
		if ( ! el ) return;

		interact( el )
			.draggable( {
				listeners: {
					move( event ) {
						if ( ! imgSize ) return;
						setHotspots( ( prev ) => prev.map( ( hs ) => {
							if ( hs.id !== hsId ) return hs;
							return {
								...hs,
								x_pct: clamp( hs.x_pct + ( event.dx / imgSize.w ) * 100, 0, 100 - hs.w_pct ),
								y_pct: clamp( hs.y_pct + ( event.dy / imgSize.h ) * 100, 0, 100 - hs.h_pct ),
							};
						} ) );
					},
				},
			} )
			.resizable( {
				edges: { right: true, bottom: true, bottomRight: true },
				listeners: {
					move( event ) {
						if ( ! imgSize ) return;
						setHotspots( ( prev ) => prev.map( ( hs ) => {
							if ( hs.id !== hsId ) return hs;
							const w_pct = clamp( ( event.rect.width  / imgSize.w ) * 100, 2, 100 - hs.x_pct );
							const h_pct = clamp( ( event.rect.height / imgSize.h ) * 100, 2, 100 - hs.y_pct );
							return { ...hs, w_pct, h_pct };
						} ) );
					},
				},
			} );
	}, [ imgSize ] );

	// ── Hotspot field edit helpers ────────────────────────────────────────────

	const updateHotspot = useCallback( ( id, patch ) => {
		setHotspots( ( prev ) => prev.map( ( hs ) => hs.id === id ? { ...hs, ...patch } : hs ) );
	}, [] );

	const deleteHotspot = useCallback( ( id ) => {
		setHotspots( ( prev ) => prev.filter( ( hs ) => hs.id !== id ) );
		setSelected( ( sel ) => sel === id ? null : sel );
	}, [] );

	const selectedHotspot = hotspots.find( ( hs ) => hs.id === selected ) ?? null;

	// ── Render ────────────────────────────────────────────────────────────────

	if ( noImage ) {
		return (
			<div className="orgahb-pe-no-image">
				<p>Select a diagram image above to start drawing hotspots.</p>
			</div>
		);
	}

	return (
		<div className="orgahb-pe">
			<div className="orgahb-pe-toolbar">
				<span className="orgahb-pe-hint">
					Draw a rectangle on the diagram to add a hotspot. Drag to move, drag the corner to resize.
				</span>
				<span className="orgahb-pe-count">
					{ hotspots.length } hotspot{ hotspots.length !== 1 ? 's' : '' }
				</span>
			</div>

			<div className="orgahb-pe-stage" ref={wrapRef}>
				{/* eslint-disable-next-line jsx-a11y/no-static-element-interactions */}
				<div
					className={ `orgahb-pe-image-wrap${ imgSize ? ' is-ready' : '' }` }
					onPointerDown={handlePointerDown}
					onPointerMove={handlePointerMove}
					onPointerUp={handlePointerUp}
					onPointerCancel={handlePointerUp}
					role="presentation"
				>
					<img
						ref={imgRef}
						src={liveImgUrl}
						alt="Process diagram"
						className="orgahb-pe-image"
						onLoad={onImageLoad}
						draggable="false"
						onDragStart={ ( e ) => e.preventDefault() }
					/>

					{ imgSize && hotspots.map( ( hs ) => (
						<HotspotOverlay
							key={hs.id}
							hotspot={hs}
							isSelected={ hs.id === selected }
							onSelect={ () => setSelected( hs.id ) }
							setupInteract={setupInteract}
						/>
					) ) }

					{ /* Live draw preview */ }
					{ drawing && drawing.w > 0.5 && drawing.h > 0.5 && (
						<div
							className="orgahb-pe-draw-preview"
							style={ {
								left:   `${ drawing.x }%`,
								top:    `${ drawing.y }%`,
								width:  `${ drawing.w }%`,
								height: `${ drawing.h }%`,
							} }
							aria-hidden="true"
						/>
					) }
				</div>
			</div>

			{ selectedHotspot && (
				<HotspotPanel
					hotspot={selectedHotspot}
					onChange={ ( patch ) => updateHotspot( selectedHotspot.id, patch ) }
					onDelete={ () => deleteHotspot( selectedHotspot.id ) }
					onClose={ () => setSelected( null ) }
				/>
			) }

			{ hotspots.some( ( hs ) => hs._needs_review ) && (
				<div className="orgahb-pe-review-warning" role="alert">
					The diagram image was replaced. Review hotspot positions before saving.
					<button
						className="button button-small"
						onClick={ () => setHotspots( ( prev ) => prev.map( ( hs ) => {
							const { _needs_review, ...rest } = hs;
							return rest;
						} ) ) }
					>
						Mark all reviewed
					</button>
				</div>
			) }
		</div>
	);
}

// ── Hotspot Overlay ───────────────────────────────────────────────────────────

function HotspotOverlay( { hotspot, isSelected, onSelect, setupInteract } ) {
	const refCallback = useCallback( ( el ) => {
		if ( el ) setupInteract( el, hotspot.id );
	}, [ hotspot.id, setupInteract ] );

	const isStep = hotspot.kind === HOTSPOT_KINDS.STEP;

	return (
		<div
			ref={refCallback}
			className={
				`orgahb-pe-hotspot` +
				` ${ isStep ? 'is-step' : 'is-link' }` +
				` ${ isSelected ? 'is-selected' : '' }` +
				` ${ hotspot._needs_review ? 'needs-review' : '' }`
			}
			style={ {
				left:   `${ hotspot.x_pct }%`,
				top:    `${ hotspot.y_pct }%`,
				width:  `${ hotspot.w_pct }%`,
				height: `${ hotspot.h_pct }%`,
			} }
			onPointerDown={ ( e ) => e.stopPropagation() }
			onClick={ ( e ) => { e.stopPropagation(); onSelect(); } }
			title={ hotspot.label }
			aria-label={ hotspot.label }
			role="button"
			tabIndex={0}
			onKeyDown={ ( e ) => { if ( e.key === 'Enter' || e.key === ' ' ) onSelect(); } }
		>
			<span className="orgahb-pe-hs-label">{ hotspot.label }</span>
			<div className="orgahb-pe-resize-handle" aria-hidden="true" />
		</div>
	);
}

// ── Hotspot Panel ─────────────────────────────────────────────────────────────

function HotspotPanel( { hotspot, onChange, onDelete, onClose } ) {
	const isStep = hotspot.kind === HOTSPOT_KINDS.STEP;

	return (
		<div className="orgahb-pe-panel">
			<div className="orgahb-pe-panel-header">
				<strong>Edit Hotspot</strong>
				<button
					type="button"
					className="orgahb-pe-panel-close"
					onClick={onClose}
					aria-label="Close panel"
				>
					×
				</button>
			</div>

			<table className="form-table orgahb-pe-panel-table">
				<tbody>
					<tr>
						<th><label htmlFor="orgahb-pe-label">Label</label></th>
						<td>
							<input
								id="orgahb-pe-label"
								type="text"
								className="regular-text"
								value={ hotspot.label }
								onChange={ ( e ) => onChange( { label: e.target.value } ) }
								// eslint-disable-next-line jsx-a11y/no-autofocus
								autoFocus
							/>
						</td>
					</tr>
					<tr>
						<th><label htmlFor="orgahb-pe-kind">Kind</label></th>
						<td>
							<select
								id="orgahb-pe-kind"
								value={ hotspot.kind }
								onChange={ ( e ) => onChange( { kind: e.target.value } ) }
							>
								<option value={ HOTSPOT_KINDS.STEP }>Step — log field evidence</option>
								<option value={ HOTSPOT_KINDS.LINK }>Link — navigate to content</option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label htmlFor="orgahb-pe-help">Help text</label></th>
						<td>
							<textarea
								id="orgahb-pe-help"
								className="large-text"
								rows={2}
								value={ hotspot.help_text || '' }
								onChange={ ( e ) => onChange( { help_text: e.target.value } ) }
								placeholder="Optional: shown to field operators"
							/>
						</td>
					</tr>
					{ isStep && (
						<tr>
							<th>Note required</th>
							<td>
								<label>
									<input
										type="checkbox"
										checked={ !! hotspot.note_required }
										onChange={ ( e ) => onChange( { note_required: e.target.checked } ) }
									/>
									{ ' ' }Force operator to enter a note
								</label>
							</td>
						</tr>
					) }
					{ ! isStep && (
						<>
							<tr>
								<th><label htmlFor="orgahb-pe-target-type">Target type</label></th>
								<td>
									<select
										id="orgahb-pe-target-type"
										value={ hotspot.target_type || '' }
										onChange={ ( e ) => onChange( { target_type: e.target.value || null } ) }
									>
										<option value="">— none —</option>
										<option value="page">Handbook Page</option>
										<option value="process">Process</option>
										<option value="document">Document</option>
										<option value="url">External URL</option>
									</select>
								</td>
							</tr>
							{ hotspot.target_type === 'url' && (
								<tr>
									<th><label htmlFor="orgahb-pe-target-url">URL</label></th>
									<td>
										<input
											id="orgahb-pe-target-url"
											type="url"
											className="large-text"
											value={ hotspot.target_url || '' }
											onChange={ ( e ) => onChange( { target_url: e.target.value || null } ) }
											placeholder="https://…"
										/>
									</td>
								</tr>
							) }
							{ hotspot.target_type && hotspot.target_type !== 'url' && (
								<tr>
									<th><label htmlFor="orgahb-pe-target-id">Post ID</label></th>
									<td>
										<input
											id="orgahb-pe-target-id"
											type="number"
											className="small-text"
											value={ hotspot.target_id || '' }
											onChange={ ( e ) => onChange( { target_id: e.target.value ? parseInt( e.target.value, 10 ) : null } ) }
											placeholder="Post ID"
											min={1}
										/>
									</td>
								</tr>
							) }
						</>
					) }
					<tr>
						<th><label htmlFor="orgahb-pe-aliases">Aliases</label></th>
						<td>
							<input
								id="orgahb-pe-aliases"
								type="text"
								className="regular-text"
								value={ hotspot.aliases || '' }
								onChange={ ( e ) => onChange( { aliases: e.target.value || '' } ) }
								placeholder="Comma-separated search aliases"
							/>
						</td>
					</tr>
					<tr>
						<th>Position</th>
						<td className="orgahb-pe-coords">
							<span>x: { hotspot.x_pct.toFixed(1) }%</span>
							<span>y: { hotspot.y_pct.toFixed(1) }%</span>
							<span>w: { hotspot.w_pct.toFixed(1) }%</span>
							<span>h: { hotspot.h_pct.toFixed(1) }%</span>
						</td>
					</tr>
				</tbody>
			</table>

			<div className="orgahb-pe-panel-actions">
				<button
					type="button"
					className="button button-link-delete"
					onClick={onDelete}
				>
					Delete hotspot
				</button>
			</div>
		</div>
	);
}
