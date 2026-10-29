/**
 * Chunked AJAX import with a live progress bar (Step 2 → Step 3).
 *
 * Without JavaScript (or fetch support) the mapping form falls back to a
 * normal POST that imports everything in a single request.
 *
 * @package Listonix_Listing_Importer_For_HivePress
 */
( function () {
	'use strict';

	var config = window.listonixImporter || window.curiouswpListingImporter || window.listingImporterForHivePress || window.hpPbImport;
	var form = document.getElementById( 'listonix-mapping-form' ) || document.getElementById( 'curiouswp-li-mapping-form' ) || document.getElementById( 'listing-importer-mapping-form' );
	var panel = document.getElementById( 'listonix-progress' ) || document.getElementById( 'curiouswp-li-progress' ) || document.getElementById( 'listing-importer-progress' );

	if ( ! config || ! form || ! panel || ! window.fetch || ! window.FormData ) {
		return;
	}

	var bar = panel.querySelector( '.listonix-progress-bar' ) || panel.querySelector( '.curiouswp-li-progress-bar' ) || panel.querySelector( '.hp-progress-bar' );
	var text = panel.querySelector( '.listonix-progress-text' ) || panel.querySelector( '.curiouswp-li-progress-text' ) || panel.querySelector( '.hp-progress-text' );
	var errorBox = panel.querySelector( '.listonix-error-box' ) || panel.querySelector( '.curiouswp-li-error-box' ) || panel.querySelector( '.hp-error-box' );
	var errorList = errorBox.querySelector( 'ul' );
	var actions = panel.querySelector( '.listonix-progress-actions' ) || panel.querySelector( '.curiouswp-li-progress-actions' ) || panel.querySelector( '.hp-progress-actions' );
	var retryButton = document.getElementById( 'listonix-retry' ) || document.getElementById( 'curiouswp-li-retry' ) || document.getElementById( 'listing-importer-retry' );
	var nonceEl = form.querySelector( '[name="listonix_process_nonce"]' ) || form.querySelector( '[name="curiouswp_li_process_nonce"]' ) || form.querySelector( '[name="listing_importer_process_nonce"]' );
	var nonce = nonceEl ? nonceEl.value : '';
	var running = false;

	function format( template, values ) {
		return template.replace( /\{(\w+)\}/g, function ( match, key ) {
			return Object.prototype.hasOwnProperty.call( values, key ) ? values[ key ] : match;
		} );
	}

	function post( action, data ) {
		data = data || new FormData();
		data.append( 'action', action );
		data.append( 'nonce', nonce );

		return fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data,
		} ).then( function ( response ) {
			return response.text().then( function ( body ) {
				var json;

				try {
					json = JSON.parse( body );
				} catch ( e ) {
					throw new Error( format( config.i18n.badResponse, { status: response.status } ) );
				}

				if ( ! json || ! json.success ) {
					throw new Error( ( json && json.data && json.data.message ) || config.i18n.unknown );
				}

				return json.data;
			} );
		} );
	}

	function render( progress ) {
		var percent = progress.total ? Math.min( 100, Math.round( ( progress.processed / progress.total ) * 100 ) ) : 0;

		bar.style.width = percent + '%';
		text.textContent = format( config.i18n.progress, {
			processed: progress.processed,
			total: progress.total,
			percent: percent,
		} );

		[ 'created', 'updated', 'failed' ].forEach( function ( key ) {
			var statEl = panel.querySelector( '[data-stat="' + key + '"]' );
			if ( statEl ) {
				statEl.textContent = progress[ key ];
			}
		} );

		Object.keys( progress.errors || {} ).forEach( function ( row ) {
			var item = document.createElement( 'li' );
			var label = document.createElement( 'strong' );

			label.textContent = format( config.i18n.rowLabel, { row: row } ) + ' ';
			item.appendChild( label );
			item.appendChild( document.createTextNode( [].concat( progress.errors[ row ] ).join( ' | ' ) ) );
			errorList.appendChild( item );
			errorBox.hidden = false;
		} );
	}

	function runChunks() {
		return post( 'listonix_chunk' ).then( function ( progress ) {
			render( progress );

			if ( progress.done ) {
				running = false;
				text.textContent = config.i18n.done;
				window.location.href = progress.redirect;
				return;
			}

			return runChunks();
		} );
	}

	function fail( error ) {
		running = false;
		text.textContent = config.i18n.failed + ' ' + error.message;
		actions.hidden = false;
	}

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();

		if ( running ) {
			return;
		}

		running = true;
		form.hidden = true;
		panel.hidden = false;
		text.textContent = config.i18n.preparing;

		var mapping = new FormData( form );

		// Send only the mapping; the nonce field belongs to the non-JS form submission.
		mapping.delete( 'listonix_process_nonce' );
		mapping.delete( 'curiouswp_li_process_nonce' );
		mapping.delete( 'listing_importer_process_nonce' );
		mapping.delete( '_wp_http_referer' );

		post( 'listonix_start', mapping )
			.then( function ( progress ) {
				render( progress );
				return runChunks();
			} )
			.catch( fail );
	} );

	if ( retryButton ) {
		retryButton.addEventListener( 'click', function () {
			if ( running ) {
				return;
			}

			running = true;
			actions.hidden = true;
			text.textContent = config.i18n.resuming;
			runChunks().catch( fail );
		} );
	}
} )();
