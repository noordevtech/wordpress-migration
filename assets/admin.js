/**
 * NoorDev Migrate dashboard.
 *
 * While this screen is open it drives the sync by calling ndm_tick in a loop;
 * each tick processes a bounded slice of batches server-side and returns
 * progress. When the tab is closed, WP-Cron keeps the sync moving.
 */
( function ( $ ) {
	'use strict';

	var polling = false;

	var STAGE_LABELS = {
		idle: 'Not started',
		db: 'Syncing database…',
		files: 'Syncing files…',
		verify: 'Verifying…',
		ready: '100% synced — ready to replace the target site',
		finalizing: 'Replacing target site…',
		done: 'Migration complete',
		error: 'Interrupted — will resume from the last checkpoint'
	};

	function post( action, data ) {
		return $.post(
			ndmAdmin.ajaxUrl,
			$.extend( { action: 'ndm_' + action, nonce: ndmAdmin.nonce }, data || {} )
		);
	}

	function fmtBytes( bytes ) {
		if ( ! bytes ) {
			return '0 B';
		}
		var units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		var i = Math.floor( Math.log( bytes ) / Math.log( 1024 ) );
		return ( bytes / Math.pow( 1024, i ) ).toFixed( i ? 1 : 0 ) + ' ' + units[ i ];
	}

	function render( s ) {
		var pct = s.progress || 0;

		$( '.ndm-progress-bar' )
			.css( 'width', Math.max( 2, pct ) + '%' )
			.toggleClass( 'ndm-done', s.stage === 'done' )
			.find( 'span' ).text( pct + '%' );

		$( '.ndm-stage-label' ).text( STAGE_LABELS[ s.stage ] || s.stage );

		$( '#ndm-start' ).toggle( s.stage === 'idle' || s.stage === 'done' );
		$( '#ndm-resume' ).toggle( ( s.paused || s.stage === 'error' ) && s.stage !== 'idle' && s.stage !== 'done' );
		$( '#ndm-pause' ).toggle( s.running && ! s.paused );
		$( '#ndm-restart' ).toggle( s.stage !== 'idle' && s.stage !== 'done' );
		$( '#ndm-cancel' ).toggle( s.stage !== 'idle' && s.stage !== 'done' );
		$( '#ndm-cutover' ).toggle( s.stage === 'ready' );

		$( '#ndm-stat-tables' ).text( s.tables ? 'Tables: ' + s.tablesDone + '/' + s.tables : '' );
		$( '#ndm-stat-rows' ).text( s.rowsSent ? 'Rows sent: ' + s.rowsSent.toLocaleString() : '' );
		$( '#ndm-stat-files' ).text(
			s.filesTotal ? 'Files: ' + s.filesDone + '/' + s.filesTotal + ' (' + fmtBytes( s.bytesDone ) + ' of ' + fmtBytes( s.bytesTotal ) + ')' : ''
		);

		if ( s.error ) {
			$( '.ndm-error' ).show().find( 'p' ).text( s.error );
		} else {
			$( '.ndm-error' ).hide();
		}

		var $log = $( '#ndm-log' ).empty();
		( s.log || [] ).slice().reverse().forEach( function ( entry ) {
			$( '<li/>' )
				.addClass( 'ndm-log-' + entry.level )
				.text( '[' + entry.time + '] ' + entry.message )
				.appendTo( $log );
		} );
	}

	function loop() {
		if ( polling ) {
			return;
		}
		polling = true;

		post( 'tick' )
			.done( function ( response ) {
				polling = false;
				if ( ! response || ! response.success ) {
					window.setTimeout( loop, 5000 );
					return;
				}
				render( response.data );
				if ( response.data.running ) {
					window.setTimeout( loop, 500 );
				} else if ( response.data.stage === 'error' ) {
					// Server retries via cron; keep the dashboard fresh.
					window.setTimeout( refresh, 10000 );
				}
			} )
			.fail( function () {
				polling = false;
				// Network hiccup: state is checkpointed server-side, just retry.
				window.setTimeout( loop, 5000 );
			} );
	}

	function refresh() {
		post( 'status' ).done( function ( response ) {
			if ( response && response.success ) {
				render( response.data );
				if ( response.data.running ) {
					loop();
				}
			}
		} );
	}

	function simpleAction( action, data, confirmText ) {
		if ( confirmText && ! window.confirm( confirmText ) ) {
			return;
		}
		post( action, data )
			.done( function ( response ) {
				if ( response && response.success ) {
					render( response.data );
					if ( response.data.running ) {
						loop();
					}
				} else if ( response && response.data && response.data.message ) {
					window.alert( response.data.message );
				}
			} );
	}

	$( function () {
		if ( ! $( '#ndm-dashboard' ).length ) {
			return;
		}

		$( '#ndm-start' ).on( 'click', function () {
			simpleAction( 'start', {} );
		} );
		$( '#ndm-resume' ).on( 'click', function () {
			simpleAction( 'resume', {} );
		} );
		$( '#ndm-restart' ).on( 'click', function () {
			simpleAction( 'start', { fresh: 1 }, ndmAdmin.i18n.confirmFresh );
		} );
		$( '#ndm-pause' ).on( 'click', function () {
			simpleAction( 'pause', {} );
		} );
		$( '#ndm-cancel' ).on( 'click', function () {
			simpleAction( 'cancel', {}, ndmAdmin.i18n.confirmCancel );
		} );
		$( '#ndm-cutover' ).on( 'click', function () {
			simpleAction( 'cutover', {}, ndmAdmin.i18n.confirmCutover );
		} );
		$( '#ndm-rollback' ).on( 'click', function () {
			simpleAction( 'rollback', {}, ndmAdmin.i18n.confirmCutover );
		} );
		$( '#ndm-cleanup-backups' ).on( 'click', function () {
			simpleAction( 'cleanup_backups', {} );
		} );

		refresh();
	} );
} )( jQuery );
