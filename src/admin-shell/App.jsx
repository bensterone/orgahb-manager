/**
 * Admin shell — bundle management UI.
 *
 * Allows editors to manage building bundles:
 *   - select a building
 *   - see its areas and linked content items
 *   - add content items from a searchable list
 *   - remove, reorder (sort_order), feature, and annotate links
 *
 * Uses the orgahb/v1 admin REST endpoints directly:
 *   GET  /buildings        — WP native REST (orgahb-buildings)
 *   GET  /buildings/{id}/links
 *   POST /buildings/{id}/links
 *   PATCH /links/{id}
 *   DELETE /links/{id}
 *   GET  /search/index?building_id={id}  — for the content picker
 */

import { useState, useEffect, useCallback, useMemo } from 'react';
import apiFetch from '@wordpress/api-fetch';
import { createGlobalSearch, loadGlobalIndex } from '@shared/search';

// ── App ───────────────────────────────────────────────────────────────────────

export default function App() {
	const [ buildings,       setBuildings       ] = useState( [] );
	const [ selectedId,      setSelectedId      ] = useState( null );
	const [ links,           setLinks           ] = useState( [] );
	const [ areas,           setAreas           ] = useState( [] );
	const [ loading,         setLoading         ] = useState( false );
	const [ error,           setError           ] = useState( null );
	const [ showPicker,      setShowPicker      ] = useState( false );

	// Load buildings list on mount.
	useEffect( () => {
		apiFetch( { path: 'orgahb/v1/buildings' } )
			.then( ( data ) => setBuildings( data ) )
			.catch( () => setError( 'Failed to load buildings.' ) );
	}, [] );

	const loadBuilding = useCallback( ( id ) => {
		setSelectedId( id );
		setLoading( true );
		setError( null );

		Promise.all( [
			apiFetch( { path: `orgahb/v1/buildings/${ id }/links` } ),
			apiFetch( { path: `orgahb/v1/buildings/${ id }/bundle` } ),
		] )
			.then( ( [ linksData, bundleData ] ) => {
				setLinks( linksData );
				setAreas( bundleData.areas ?? [] );
				setLoading( false );
			} )
			.catch( () => {
				setError( 'Failed to load building bundle.' );
				setLoading( false );
			} );
	}, [] );

	const reloadLinks = useCallback( () => {
		if ( ! selectedId ) return;
		apiFetch( { path: `orgahb/v1/buildings/${ selectedId }/links` } )
			.then( setLinks )
			.catch( () => {} );
	}, [ selectedId ] );

	const handleRemoveLink = useCallback( ( linkId ) => {
		apiFetch( { path: `orgahb/v1/links/${ linkId }`, method: 'DELETE' } )
			.then( reloadLinks )
			.catch( () => setError( 'Failed to remove link.' ) );
	}, [ reloadLinks ] );

	const handlePatchLink = useCallback( ( linkId, patch ) => {
		apiFetch( { path: `orgahb/v1/links/${ linkId }`, method: 'PATCH', data: patch } )
			.then( reloadLinks )
			.catch( () => setError( 'Failed to update link.' ) );
	}, [ reloadLinks ] );

	const handleAddLink = useCallback( ( contentType, contentId, areaKey ) => {
		apiFetch( {
			path:   `orgahb/v1/buildings/${ selectedId }/links`,
			method: 'POST',
			data:   { content_type: contentType, content_id: contentId, area_key: areaKey },
		} )
			.then( () => { reloadLinks(); setShowPicker( false ); } )
			.catch( () => setError( 'Failed to add link.' ) );
	}, [ selectedId, reloadLinks ] );

	// Group links by area for display.
	const linksByArea = useMemo( () => {
		const map = {};
		for ( const link of links ) {
			( map[ link.area_key ] ??= [] ).push( link );
		}
		for ( const key in map ) {
			map[ key ].sort( ( a, b ) => a.sort_order - b.sort_order );
		}
		return map;
	}, [ links ] );

	return (
		<div className="orgahb-as">
			<h2 className="orgahb-as-heading">Bundle Manager</h2>

			{ error && <div className="notice notice-error inline"><p>{ error }</p></div> }

			<div className="orgahb-as-building-row">
				<label htmlFor="orgahb-as-building-select">
					<strong>Building</strong>
				</label>
				<select
					id="orgahb-as-building-select"
					value={ selectedId ?? '' }
					onChange={ ( e ) => e.target.value && loadBuilding( Number( e.target.value ) ) }
				>
					<option value="">— Select a building —</option>
					{ buildings.map( ( b ) => (
						<option key={ b.id } value={ b.id }>{ b.title?.rendered ?? b.title }</option>
					) ) }
				</select>
			</div>

			{ loading && <p className="orgahb-as-loading">Loading…</p> }

			{ selectedId && ! loading && (
				<>
					{ areas.map( ( area ) => (
						<AreaSection
							key={ area.key }
							area={ area }
							links={ linksByArea[ area.key ] ?? [] }
							onRemove={ handleRemoveLink }
							onPatch={ handlePatchLink }
						/>
					) ) }

					<div className="orgahb-as-add-row">
						<button
							className="button button-primary"
							onClick={ () => setShowPicker( ! showPicker ) }
							aria-expanded={ showPicker }
						>
							{ showPicker ? 'Cancel' : '+ Add Content' }
						</button>
					</div>

					{ showPicker && (
						<ContentPicker
							areas={ areas }
							onAdd={ handleAddLink }
						/>
					) }
				</>
			) }
		</div>
	);
}

// ── Area Section ──────────────────────────────────────────────────────────────

function AreaSection( { area, links, onRemove, onPatch } ) {
	return (
		<section className="orgahb-as-area">
			<h3 className="orgahb-as-area-title">{ area.label }</h3>
			{ links.length === 0 ? (
				<p className="orgahb-as-empty">No content linked to this area.</p>
			) : (
				<table className="wp-list-table widefat striped orgahb-as-table">
					<thead>
						<tr>
							<th>Title</th>
							<th>Type</th>
							<th>Featured</th>
							<th>Sort</th>
							<th>Local note</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						{ links.map( ( link ) => (
							<LinkRow
								key={ link.id }
								link={ link }
								onRemove={ () => onRemove( link.id ) }
								onPatch={ ( patch ) => onPatch( link.id, patch ) }
							/>
						) ) }
					</tbody>
				</table>
			) }
		</section>
	);
}

// ── Link Row ──────────────────────────────────────────────────────────────────

function LinkRow( { link, onRemove, onPatch } ) {
	const [ note,    setNote    ] = useState( link.local_note ?? '' );
	const [ sort,    setSort    ] = useState( link.sort_order ?? 0 );
	const [ dirty,   setDirty   ] = useState( false );

	const handleSave = useCallback( () => {
		onPatch( { local_note: note, sort_order: Number( sort ) } );
		setDirty( false );
	}, [ note, sort, onPatch ] );

	// Look up title via WP REST if not directly on the link object.
	const title = link.content_title ?? `#${ link.content_id }`;

	return (
		<tr>
			<td>
				<a
					href={ `/wp-admin/post.php?post=${ link.content_id }&action=edit` }
					target="_blank"
					rel="noreferrer"
				>
					{ title }
				</a>
			</td>
			<td>{ link.content_type }</td>
			<td>
				<input
					type="checkbox"
					checked={ !! link.is_featured }
					onChange={ ( e ) => onPatch( { is_featured: e.target.checked } ) }
					title="Featured"
				/>
			</td>
			<td>
				<input
					type="number"
					value={ sort }
					min={0}
					style={ { width: 60 } }
					onChange={ ( e ) => { setSort( e.target.value ); setDirty( true ); } }
				/>
			</td>
			<td>
				<input
					type="text"
					value={ note }
					placeholder="Building-local note…"
					style={ { width: '100%' } }
					onChange={ ( e ) => { setNote( e.target.value ); setDirty( true ); } }
				/>
			</td>
			<td className="orgahb-as-row-actions">
				{ dirty && (
					<button className="button button-small" onClick={ handleSave }>Save</button>
				) }
				<button
					className="button button-small orgahb-as-remove"
					onClick={ () => {
						if ( window.confirm( 'Remove this link from the bundle?' ) ) onRemove();
					} }
				>
					Remove
				</button>
			</td>
		</tr>
	);
}

// ── Content Picker ────────────────────────────────────────────────────────────

function ContentPicker( { areas, onAdd } ) {
	const [ query,   setQuery   ] = useState( '' );
	const [ areaKey, setAreaKey ] = useState( areas[0]?.key ?? 'general' );
	const [ results, setResults ] = useState( [] );
	const [ searcher, setSearcher ] = useState( null );

	useEffect( () => {
		loadGlobalIndex()
			.then( ( docs ) => setSearcher( createGlobalSearch( docs ) ) )
			.catch( () => {} );
	}, [] );

	useEffect( () => {
		if ( ! searcher ) return;
		if ( query.trim().length < 2 ) {
			setResults( [] );
			return;
		}
		setResults(
			searcher.search( query, { types: [ 'page', 'process', 'document' ] } ).slice( 0, 20 )
		);
	}, [ query, searcher ] );

	return (
		<div className="orgahb-as-picker">
			<h4>Add content to bundle</h4>

			<div className="orgahb-as-picker-row">
				<label>
					Area
					<select value={ areaKey } onChange={ ( e ) => setAreaKey( e.target.value ) }>
						{ areas.map( ( a ) => (
							<option key={ a.key } value={ a.key }>{ a.label }</option>
						) ) }
					</select>
				</label>
				<label>
					Search
					<input
						type="search"
						value={ query }
						onChange={ ( e ) => setQuery( e.target.value ) }
						placeholder="Type to search content…"
						autoFocus
					/>
				</label>
			</div>

			{ query.trim().length >= 2 && results.length === 0 && (
				<p className="orgahb-as-picker-empty">No results.</p>
			) }

			{ results.length > 0 && (
				<ul className="orgahb-as-picker-results">
					{ results.map( ( item ) => (
						<li key={ `${ item.type }_${ item.id }` } className="orgahb-as-picker-result">
							<span className="orgahb-as-result-type">{ item.type }</span>
							<span className="orgahb-as-result-title">{ item.title }</span>
							<button
								className="button button-small"
								onClick={ () => onAdd( item.type, item.id, areaKey ) }
							>
								Add
							</button>
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
}
