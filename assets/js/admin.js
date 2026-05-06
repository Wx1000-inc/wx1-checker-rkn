/**
 * WX1 RKN Checker – admin JavaScript
 *
 * Drives the async batch-check flow:
 *  1. Parse domain list.
 *  2. POST wxrkn_init_session to create a DB session.
 *  3. Process domains in batches, honouring delay between batches.
 *  4. Each domain triggers a wxrkn_check_domain AJAX call (server-side fetch).
 *  5. Progress bar + table rows update in real-time.
 *  6. CSV export triggers a direct-download GET request.
 */
/* global wxrknData, jQuery */
(function ( $ ) {
	'use strict';

	/* -----------------------------------------------------------------------
	 * State
	 * --------------------------------------------------------------------- */
	var state = {
		running:    false,
		queue:      [],   // [{ domain, idx }]
		processed:  0,
		sessionId:  '',
		batchSize:  1,
		delay:      1000,
		maxPages:   3,
		timeout:    15,
		results:    {}    // domain -> data
	};

	/* -----------------------------------------------------------------------
	 * Initialise event listeners
	 * --------------------------------------------------------------------- */
	function init() {
		$( '#wxrkn-start-btn'  ).on( 'click', startCheck );
		$( '#wxrkn-stop-btn'   ).on( 'click', stopCheck );
		$( '#wxrkn-export-btn' ).on( 'click', exportCsv );
		$( document ).on( 'click', '.wxrkn-load-session', loadSession );
	}

	/* -----------------------------------------------------------------------
	 * Start a fresh check run
	 * --------------------------------------------------------------------- */
	function startCheck() {
		var raw = $( '#wxrkn-domains' ).val() || '';
		var domains = raw.split( /[\r\n]+/ )
			.map( function ( d ) { return d.trim(); } )
			.filter( function ( d ) { return d.length > 0; } );

		if ( domains.length === 0 ) {
			alert( wxrknData.i18n.nodomains );
			return;
		}

		/* Read settings */
		state.batchSize = Math.max( 1, parseInt( $( '#wxrkn-batch-size' ).val(), 10 ) || 1 );
		state.delay     = Math.max( 0, parseInt( $( '#wxrkn-delay'      ).val(), 10 ) || 1000 );
		state.maxPages  = Math.max( 1, parseInt( $( '#wxrkn-max-pages'  ).val(), 10 ) || 3 );
		state.timeout   = Math.max( 5, parseInt( $( '#wxrkn-timeout'    ).val(), 10 ) || 15 );

		/* Reset state */
		state.queue     = domains.map( function ( d, i ) { return { domain: d, idx: i }; } );
		state.processed = 0;
		state.results   = {};
		state.running   = true;
		state.sessionId = generateId();

		/* Reset UI */
		$( '#wxrkn-progress-bar' ).css( 'width', '0%' );
		$( '#wxrkn-progress-text' ).text( '0 / ' + domains.length );
		$( '#wxrkn-status-text'  ).text( '' );
		$( '#wxrkn-results-tbody' ).empty();
		$( '#wxrkn-progress-wrap' ).show();
		$( '#wxrkn-results-wrap'  ).show();
		$( '#wxrkn-export-btn'    ).hide();
		$( '#wxrkn-stop-btn'      ).show();
		$( '#wxrkn-start-btn'     ).prop( 'disabled', true ).hide();

		/* Add all rows in "pending" state */
		domains.forEach( function ( domain, i ) {
			appendPendingRow( domain, i );
		} );

		/* Initialise session on server, then start processing */
		$.post( wxrknData.ajaxUrl, {
			action:     'wxrkn_init_session',
			nonce:      wxrknData.nonce,
			session_id: state.sessionId,
			total:      domains.length
		} ).always( function () {
			processBatch( 0 );
		} );
	}

	/* -----------------------------------------------------------------------
	 * Batch processing
	 * --------------------------------------------------------------------- */
	function processBatch( fromIndex ) {
		if ( ! state.running || fromIndex >= state.queue.length ) {
			onComplete();
			return;
		}

		var batch = state.queue.slice( fromIndex, fromIndex + state.batchSize );
		var nextIndex = fromIndex + batch.length;

		var deferreds = batch.map( function ( item ) {
			return checkDomain( item );
		} );

		/* After all items in this batch finish (success or error) */
		$.when.apply( $, deferreds ).always( function () {
			if ( ! state.running ) {
				onComplete();
				return;
			}
			if ( nextIndex < state.queue.length ) {
				setTimeout( function () {
					processBatch( nextIndex );
				}, state.delay );
			} else {
				onComplete();
			}
		} );
	}

	/* -----------------------------------------------------------------------
	 * Check a single domain
	 * --------------------------------------------------------------------- */
	function checkDomain( item ) {
		var deferred = $.Deferred();

		setRowChecking( item.idx );
		setStatus( wxrknData.i18n.checking + ' ' + item.domain );

		$.ajax( {
			url:    wxrknData.ajaxUrl,
			method: 'POST',
			data: {
				action:     'wxrkn_check_domain',
				nonce:      wxrknData.nonce,
				domain:     item.domain,
				session_id: state.sessionId,
				max_pages:  state.maxPages,
				timeout:    state.timeout
			},
			/* Client-side timeout slightly longer than server-side */
			timeout: ( state.timeout + 45 ) * 1000
		} )
		.done( function ( response ) {
			if ( response && response.success ) {
				fillRow( item.idx, response.data );
				state.results[ item.domain ] = response.data;
			} else {
				var msg = ( response && response.data && response.data.message ) ? response.data.message : 'Error';
				markRowError( item.idx, msg );
			}
		} )
		.fail( function ( xhr ) {
			var msg = xhr.status ? 'HTTP ' + xhr.status : 'Request failed';
			markRowError( item.idx, msg );
		} )
		.always( function () {
			state.processed++;
			updateProgress();
			deferred.resolve();
		} );

		return deferred.promise();
	}

	/* -----------------------------------------------------------------------
	 * Stop / complete
	 * --------------------------------------------------------------------- */
	function stopCheck() {
		if ( ! confirm( wxrknData.i18n.confirmStop ) ) { return; }
		state.running = false;
	}

	function onComplete() {
		state.running = false;
		setStatus( wxrknData.i18n.complete );
		$( '#wxrkn-results-tbody tr' ).removeClass( 'wxrkn-row-checking' );
		$( '#wxrkn-stop-btn'  ).hide();
		$( '#wxrkn-start-btn' ).prop( 'disabled', false ).show();
		$( '#wxrkn-export-btn').show();
	}

	/* -----------------------------------------------------------------------
	 * Progress helpers
	 * --------------------------------------------------------------------- */
	function updateProgress() {
		var total = state.queue.length;
		var done  = state.processed;
		var pct   = total > 0 ? Math.round( ( done / total ) * 100 ) : 0;

		$( '#wxrkn-progress-bar'  ).css( 'width', pct + '%' );
		$( '#wxrkn-progress-text' ).text( done + ' / ' + total + ' (' + pct + '%)' );

		/* Update ARIA */
		$( '.wxrkn-progress-bar-wrap' ).attr( 'aria-valuenow', pct );
	}

	function setStatus( msg ) {
		$( '#wxrkn-status-text' ).text( msg );
	}

	/* -----------------------------------------------------------------------
	 * Table helpers
	 * --------------------------------------------------------------------- */

	/**
	 * Append a "pending" skeleton row.
	 */
	function appendPendingRow( domain, idx ) {
		var $row = $( '<tr>' )
			.attr( 'data-idx', idx )
			.addClass( 'wxrkn-row-pending' );

		$row.append( $( '<td>' ).addClass( 'wxrkn-domain-cell' ).text( domain ) );

		for ( var i = 0; i < 6; i++ ) {
			$row.append( $( '<td>' ).addClass( 'wxrkn-cell-pending' ).text( '\u2014' ) );
		}

		$row.append(
			$( '<td>' ).addClass( 'wxrkn-cell-pending wxrkn-status-pending' ).text( wxrknData.i18n.pending )
		);

		$( '#wxrkn-results-tbody' ).append( $row );
	}

	function setRowChecking( idx ) {
		getRow( idx )
			.addClass( 'wxrkn-row-checking' )
			.find( 'td' ).last()
			.text( wxrknData.i18n.checking );
	}

	/**
	 * Fill a row with real check results.
	 *
	 * @param {number} idx  Row index.
	 * @param {Object} data Result payload from server.
	 */
	function fillRow( idx, data ) {
		var $row = getRow( idx );
		$row.removeClass( 'wxrkn-row-checking wxrkn-row-pending' );

		var keys = [ 'google_analytics', 'privacy_policy', 'yandex_metrika', 'sape', 'liveinternet', 'comments' ];
		var $tds = $row.find( 'td' );

		keys.forEach( function ( key, i ) {
			var $td = $tds.eq( i + 1 );
			$td.removeClass( 'wxrkn-cell-pending wxrkn-cell-yes wxrkn-cell-no wxrkn-cell-error' );

			if ( data.error ) {
				$td.addClass( 'wxrkn-cell-error' ).text( wxrknData.i18n.na );
			} else if ( data[ key ] ) {
				$td.addClass( 'wxrkn-cell-yes' ).text( wxrknData.i18n.yes );
			} else {
				$td.addClass( 'wxrkn-cell-no' ).text( wxrknData.i18n.no );
			}
		} );

		/* Status cell */
		var $st = $tds.last().removeClass();
		if ( data.error ) {
			$st.addClass( 'wxrkn-status-error' )
				.text( wxrknData.i18n.error )
				.attr( 'title', data.error );
		} else {
			$st.addClass( 'wxrkn-status-done' ).text( '\u2713' );
		}
	}

	function markRowError( idx, msg ) {
		var $row = getRow( idx );
		$row.removeClass( 'wxrkn-row-checking wxrkn-row-pending' );

		var $tds = $row.find( 'td' );
		for ( var i = 1; i < 7; i++ ) {
			$tds.eq( i ).removeClass().addClass( 'wxrkn-cell-error' ).text( wxrknData.i18n.na );
		}
		$tds.last().removeClass().addClass( 'wxrkn-status-error' ).text( wxrknData.i18n.error ).attr( 'title', msg || '' );

		state.results[ $row.find( '.wxrkn-domain-cell' ).text() ] = { error: msg };
	}

	function getRow( idx ) {
		return $( '#wxrkn-results-tbody tr[data-idx="' + parseInt( idx, 10 ) + '"]' );
	}

	/* -----------------------------------------------------------------------
	 * CSV Export
	 * --------------------------------------------------------------------- */
	function exportCsv() {
		if ( ! state.sessionId ) { return; }
		var url = wxrknData.ajaxUrl +
			'?action=wxrkn_export_csv' +
			'&session_id=' + encodeURIComponent( state.sessionId ) +
			'&nonce='      + encodeURIComponent( wxrknData.nonce );
		window.location.href = url;
	}

	/* -----------------------------------------------------------------------
	 * Load a previous session from the history table
	 * --------------------------------------------------------------------- */
	function loadSession( e ) {
		e.preventDefault();

		var sessionId = $( this ).data( 'session' );

		$.post( wxrknData.ajaxUrl, {
			action:     'wxrkn_get_results',
			nonce:      wxrknData.nonce,
			session_id: sessionId
		} )
		.done( function ( response ) {
			if ( ! response || ! response.success ) {
				alert( wxrknData.i18n.loadError );
				return;
			}

			var rows = response.data.results;
			state.sessionId = sessionId;
			state.queue     = rows.map( function ( r, i ) { return { domain: r.domain, idx: i }; } );
			state.processed = rows.length;
			state.results   = {};

			$( '#wxrkn-results-tbody' ).empty();
			$( '#wxrkn-results-wrap'  ).show();
			$( '#wxrkn-progress-wrap' ).hide();
			$( '#wxrkn-export-btn'    ).show();

			rows.forEach( function ( row, i ) {
				appendPendingRow( row.domain, i );

				var data = {
					google_analytics: parseInt( row.google_analytics, 10 ) === 1,
					privacy_policy:   parseInt( row.privacy_policy,   10 ) === 1,
					yandex_metrika:   parseInt( row.yandex_metrika,   10 ) === 1,
					sape:             parseInt( row.sape,             10 ) === 1,
					liveinternet:     parseInt( row.liveinternet,     10 ) === 1,
					comments:         parseInt( row.comments,         10 ) === 1,
					error:            row.status === 'error' ? row.error_msg : null
				};

				fillRow( i, data );
				state.results[ row.domain ] = data;
			} );
		} )
		.fail( function () {
			alert( wxrknData.i18n.loadError );
		} );
	}

	/* -----------------------------------------------------------------------
	 * Utilities
	 * --------------------------------------------------------------------- */
	function generateId() {
		/* Use crypto.getRandomValues when available (all modern browsers). */
		if ( window.crypto && window.crypto.getRandomValues ) {
			var arr = new Uint32Array( 3 );
			window.crypto.getRandomValues( arr );
			return arr[ 0 ].toString( 36 ) + arr[ 1 ].toString( 36 ) + arr[ 2 ].toString( 36 );
		}
		/* Fallback: timestamp only (old browsers; still guarded by server-side nonce). */
		return Date.now().toString( 36 ) + Date.now().toString( 36 ).split( '' ).reverse().join( '' );
	}

	/* -----------------------------------------------------------------------
	 * Boot
	 * --------------------------------------------------------------------- */
	$( document ).ready( init );

}( jQuery ) );
