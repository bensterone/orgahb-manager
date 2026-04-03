/**
 * OrgaHB Metabox admin scripts.
 *
 * Handles:
 *  - Building areas: add row / remove row
 *  - Media upload: image picker for process diagram
 *  - Media upload: file picker for process source file and document file
 */
/* global orgahbMetaboxes, wp */
( function ( $ ) {
	'use strict';

	// ── Building Areas ────────────────────────────────────────────────────────

	/**
	 * Returns the current highest area index so new rows get unique names.
	 *
	 * @returns {number}
	 */
	function nextAreaIndex() {
		var rows = $( '#orgahb-areas-list .orgahb-area-row' );
		return rows.length;
	}

	$( document ).on( 'click', '#orgahb-add-area', function () {
		var idx = nextAreaIndex();
		var row = $( '<div class="orgahb-area-row"></div>' );

		row.append(
			$( '<input type="hidden">' )
				.attr( 'name', 'orgahb_areas[' + idx + '][sort_order]' )
				.val( idx )
		);
		row.append(
			$( '<input type="text">' )
				.attr( 'name', 'orgahb_areas[' + idx + '][key]' )
				.attr( 'placeholder', 'slug-key' )
				.addClass( 'orgahb-area-key' )
		);
		row.append(
			$( '<input type="text">' )
				.attr( 'name', 'orgahb_areas[' + idx + '][label]' )
				.attr( 'placeholder', 'Label' )
				.addClass( 'orgahb-area-label' )
		);
		row.append(
			$( '<input type="text">' )
				.attr( 'name', 'orgahb_areas[' + idx + '][description]' )
				.attr( 'placeholder', 'Description (optional)' )
				.addClass( 'orgahb-area-description' )
		);
		row.append(
			$( '<button type="button" class="button button-small orgahb-remove-area">Remove</button>' )
		);

		$( '#orgahb-areas-list' ).append( row );
	} );

	$( document ).on( 'click', '.orgahb-remove-area', function () {
		$( this ).closest( '.orgahb-area-row' ).remove();
		// Re-index sort_order hidden inputs so the server gets contiguous values.
		$( '#orgahb-areas-list .orgahb-area-row' ).each( function ( i ) {
			$( this ).find( 'input[name*="[sort_order]"]' ).val( i );
			// Re-index all field names to keep array keys contiguous.
			$( this ).find( 'input, select' ).each( function () {
				var name = $( this ).attr( 'name' );
				if ( name ) {
					$( this ).attr( 'name', name.replace( /\[\d+\]/, '[' + i + ']' ) );
				}
			} );
		} );
	} );

	// ── Media upload: process diagram image ───────────────────────────────────

	$( document ).on( 'click', '.orgahb-upload-image', function ( e ) {
		e.preventDefault();
		var btn      = $( this );
		var targetId = btn.data( 'target' );
		var previewId = btn.data( 'preview' );

		var frame = wp.media( {
			title:    orgahbMetaboxes.selectImageTitle,
			button:   { text: orgahbMetaboxes.useImageButton },
			library:  { type: 'image' },
			multiple: false,
		} );

		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			$( '#' + targetId ).val( attachment.id );
			var previewEl = $( '#' + previewId );
			previewEl.empty();
			if ( attachment.sizes && attachment.sizes.medium ) {
				previewEl.append(
					$( '<img>' )
						.attr( 'src', attachment.sizes.medium.url )
						.attr( 'alt', attachment.alt || '' )
						.css( { maxWidth: '300px', height: 'auto' } )
				);
			} else {
				previewEl.append(
					$( '<img>' )
						.attr( 'src', attachment.url )
						.attr( 'alt', attachment.alt || '' )
						.css( { maxWidth: '300px', height: 'auto' } )
				);
			}
			btn.text( orgahbMetaboxes.changeImageButton );

			// Notify the React process editor that the image changed.
			document.dispatchEvent( new CustomEvent( 'orgahb:process_image_changed', {
				detail: { url: attachment.url, id: attachment.id },
			} ) );
		} );

		frame.open();
	} );

	$( document ).on( 'click', '.orgahb-remove-image', function ( e ) {
		e.preventDefault();
		var btn      = $( this );
		var targetId = btn.data( 'target' );
		var previewId = btn.data( 'preview' );
		$( '#' + targetId ).val( '' );
		$( '#' + previewId ).empty();
	} );

	// ── Media upload: generic file (source file + document file) ─────────────

	$( document ).on( 'click', '.orgahb-upload-file, .orgahb-upload-document', function ( e ) {
		e.preventDefault();
		var btn      = $( this );
		var targetId = btn.data( 'target' );
		var labelId  = btn.data( 'label' );   // used by process source file
		var infoId   = btn.data( 'info' );    // used by document file

		var frame = wp.media( {
			title:    orgahbMetaboxes.selectFileTitle,
			button:   { text: orgahbMetaboxes.useFileButton },
			multiple: false,
		} );

		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			$( '#' + targetId ).val( attachment.id );

			// Process source file: update the label span.
			if ( labelId ) {
				$( '#' + labelId ).text( attachment.filename || attachment.title );
			}

			// Document file: update the info div.
			if ( infoId ) {
				var info = $( '#' + infoId );
				info.empty();
				var title = $( '<strong>' ).text( attachment.filename || attachment.title );
				info.append( title );
				if ( attachment.mime ) {
					info.append( ' ' ).append( $( '<span class="orgahb-file-meta">' ).text( attachment.mime ) );
				}
				if ( attachment.filesizeHumanReadable ) {
					info.append( ' ' ).append( $( '<span class="orgahb-file-meta">' ).text( attachment.filesizeHumanReadable ) );
				}
			}
		} );

		frame.open();
	} );

	$( document ).on( 'click', '.orgahb-remove-file', function ( e ) {
		e.preventDefault();
		var btn     = $( this );
		var targetId = btn.data( 'target' );
		var labelId  = btn.data( 'label' );
		$( '#' + targetId ).val( '' );
		if ( labelId ) {
			$( '#' + labelId ).text( orgahbMetaboxes.noFile );
		}
	} );

} )( jQuery );
