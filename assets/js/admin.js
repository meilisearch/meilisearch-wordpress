/**
 * Meilisearch admin screen: connection test, reindex buttons and live reindex progress.
 *
 * Vanilla JS, no dependencies. Configuration comes from window.meilisearchAdmin
 * (localized by RestController::enqueue_assets()): { restUrl, nonce, i18n }.
 *
 * DOM contract (rendered by the PHP tabs):
 * - button[data-meilisearch-action="test-connection"][data-meilisearch-target="<id of a role=status element>"]
 * - button[data-meilisearch-action="reindex"][data-meilisearch-index="content|products"]
 * - [data-meilisearch-progress="content|products"] containing <progress> and .meilisearch-progress-text
 * - #meilisearch-live: aria-live="polite" region (created if missing)
 */
( function () {
	'use strict';

	var config = window.meilisearchAdmin;
	if ( ! config || ! config.restUrl || ! window.fetch ) {
		return;
	}

	var i18n = config.i18n || {};
	var POLL_INTERVAL = 3000;
	var pollTimer = null;
	var lastStatus = {};

	function format( template ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var next = 0;
		return String( template || '' ).replace( /%(?:(\d+)\$)?[sd]/g, function ( match, position ) {
			var value = position ? args[ parseInt( position, 10 ) - 1 ] : args[ next++ ];
			return undefined === value || null === value ? '' : String( value );
		} );
	}

	function liveRegion() {
		var region = document.getElementById( 'meilisearch-live' );
		if ( ! region ) {
			region = document.createElement( 'div' );
			region.id = 'meilisearch-live';
			region.className = 'screen-reader-text';
			region.setAttribute( 'aria-live', 'polite' );
			region.setAttribute( 'aria-atomic', 'true' );
			document.body.appendChild( region );
		}
		return region;
	}

	function announce( message ) {
		var region = liveRegion();
		region.textContent = '';
		window.setTimeout( function () {
			region.textContent = message;
		}, 100 );
	}

	function request( method, path, body ) {
		var options = {
			method: method,
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': config.nonce, Accept: 'application/json' }
		};
		if ( undefined !== body ) {
			options.headers[ 'Content-Type' ] = 'application/json';
			options.body = JSON.stringify( body );
		}
		return window.fetch( config.restUrl + path, options ).then( function ( response ) {
			return response.json().catch( function () {
				return null;
			} ).then( function ( data ) {
				if ( ! response.ok ) {
					var error = new Error( data && data.message ? data.message : i18n.requestFailed );
					error.status = response.status;
					error.data = data && data.data ? data.data : null;
					throw error;
				}
				return data;
			} );
		} );
	}

	function isActive( state ) {
		return !! state && 'running' === state.status;
	}

	function describe( state ) {
		if ( ! state ) {
			return '';
		}
		switch ( state.status ) {
			case 'running':
				if ( 'sweep' === state.phase ) {
					return format( i18n.sweep, state.deleted || 0 );
				}
				if ( 'finalizing' === state.phase ) {
					return i18n.finalizing;
				}
				return format( i18n.upsert, state.sent, state.total );
			case 'done':
				return format( i18n.done, state.sent, state.deleted || 0 );
			case 'failed':
				return format( i18n.failed, state.error || '' );
		}
		return '';
	}

	function render( index, state ) {
		var button = document.querySelector( '[data-meilisearch-action="reindex"][data-meilisearch-index="' + index + '"]' );
		var container = document.querySelector( '[data-meilisearch-progress="' + index + '"]' );
		if ( button ) {
			button.disabled = isActive( state );
		}
		if ( ! container ) {
			return;
		}
		var bar = container.querySelector( 'progress' );
		var text = container.querySelector( '.meilisearch-progress-text' );
		if ( bar ) {
			var total = state && state.total > 0 ? state.total : 0;
			bar.hidden = ! isActive( state );
			bar.max = 100;
			if ( state && 'upsert' !== state.phase ) {
				bar.value = 100;
			} else {
				bar.value = total ? Math.min( 100, Math.round( ( state.sent * 100 ) / total ) ) : 0;
			}
		}
		if ( text && state ) {
			text.textContent = describe( state );
		}
	}

	function update( states ) {
		var anyActive = false;
		Object.keys( states || {} ).forEach( function ( index ) {
			var state = states[ index ];
			var status = state ? state.status + ':' + state.phase : null;
			render( index, state );
			if ( state && undefined !== lastStatus[ index ] && lastStatus[ index ] !== status ) {
				announce( describe( state ) );
			}
			lastStatus[ index ] = status;
			anyActive = anyActive || isActive( state );
		} );
		return anyActive;
	}

	function poll() {
		window.clearTimeout( pollTimer );
		request( 'GET', 'reindex/status' ).then( function ( states ) {
			if ( update( states ) ) {
				pollTimer = window.setTimeout( poll, POLL_INTERVAL );
			}
		} ).catch( function ( error ) {
			announce( error.message );
		} );
	}

	function startReindex( button ) {
		var index = button.getAttribute( 'data-meilisearch-index' );
		button.disabled = true;
		announce( i18n.starting );
		request( 'POST', 'reindex', { index: index } ).then( function ( state ) {
			var states = {};
			states[ index ] = state;
			update( states );
			announce( describe( state ) );
			poll();
		} ).catch( function ( error ) {
			var states = {};
			if ( 409 === error.status && error.data && error.data.state ) {
				states[ index ] = error.data.state;
				update( states );
				announce( error.message );
				poll();
				return;
			}
			button.disabled = false;
			var container = document.querySelector( '[data-meilisearch-progress="' + index + '"] .meilisearch-progress-text' );
			if ( container ) {
				container.textContent = error.message;
			}
			announce( error.message );
		} );
	}

	function testConnection( button ) {
		var target = document.getElementById( button.getAttribute( 'data-meilisearch-target' ) || '' );
		function show( message ) {
			if ( target ) {
				target.textContent = message;
			} else {
				announce( message );
			}
		}
		button.disabled = true;
		show( i18n.testing );
		request( 'POST', 'connection/test' ).then( function ( data ) {
			show( format( i18n.connected, data && data.version ? data.version : '' ) );
		} ).catch( function ( error ) {
			show( error.message );
		} ).then( function () {
			button.disabled = false;
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target && event.target.closest ? event.target.closest( '[data-meilisearch-action]' ) : null;
		if ( ! button ) {
			return;
		}
		var action = button.getAttribute( 'data-meilisearch-action' );
		if ( 'test-connection' === action ) {
			event.preventDefault();
			testConnection( button );
		} else if ( 'reindex' === action ) {
			event.preventDefault();
			startReindex( button );
		}
	} );

	if ( document.querySelector( '[data-meilisearch-progress]' ) ) {
		poll();
	}
}() );
