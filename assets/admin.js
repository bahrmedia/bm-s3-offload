/**
 * BM S3 Offload – admin screen.
 *
 * Vanilla JS, no dependencies.
 */
( function () {
	'use strict';

	var config = window.bms3oAdmin;
	if ( ! config ) {
		return;
	}

	/**
	 * POSTs to admin-ajax.php.
	 *
	 * @param {Object} data Fields.
	 * @return {Promise<Object>} Response data.
	 */
	function post( data ) {
		var body = new window.FormData();
		body.append( 'nonce', config.nonce );
		Object.keys( data ).forEach( function ( key ) {
			body.append( key, data[ key ] );
		} );

		return window.fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.catch( function ( error ) {
				// Network error: worth retrying.
				error.retryable = true;
				throw error;
			} )
			.then( function ( response ) {
				return response.json().catch( function () {
					// No JSON (e.g. timeout or 502 from a proxy): worth retrying.
					var error = new Error( 'HTTP ' + response.status );
					error.retryable = true;
					throw error;
				} );
			} )
			.then( function ( json ) {
				if ( ! json || ! json.success ) {
					throw new Error( json && json.data && json.data.message ? json.data.message : 'Error' );
				}
				return json.data;
			} );
	}

	/*
	 * Provider presets.
	 */
	var provider = document.getElementById( 'bms3o-provider' );
	if ( provider && ! provider.disabled ) {
		provider.addEventListener( 'change', function () {
			var preset = config.providers[ provider.value ];
			if ( ! preset ) {
				return;
			}
			var region = document.getElementById( 'bms3o-region' );
			var endpoint = document.getElementById( 'bms3o-endpoint' );
			var pathStyle = document.getElementById( 'bms3o-path-style' );

			if ( region && ! region.disabled ) {
				region.value = preset.region;
			}
			if ( endpoint && ! endpoint.disabled ) {
				endpoint.value = preset.endpoint.replace( '{region}', preset.region );
				if ( endpoint.value.indexOf( '{' ) !== -1 ) {
					endpoint.focus();
				}
			}
			if ( pathStyle && ! pathStyle.disabled ) {
				pathStyle.checked = !! preset.path_style;
			}
		} );

		// Keep the region inside the endpoint in sync while the preset placeholder is used.
		var regionInput = document.getElementById( 'bms3o-region' );
		if ( regionInput ) {
			regionInput.addEventListener( 'change', function () {
				var preset = config.providers[ provider.value ];
				var endpoint = document.getElementById( 'bms3o-endpoint' );
				if ( ! preset || ! endpoint || endpoint.disabled || preset.endpoint.indexOf( '{region}' ) === -1 ) {
					return;
				}
				var parts = preset.endpoint.split( '{region}' );
				var pattern = new RegExp( '^' + parts.map( function ( part ) {
					return part.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
				} ).join( '[^/]+' ) + '$' );
				if ( pattern.test( endpoint.value ) ) {
					endpoint.value = preset.endpoint.replace( '{region}', regionInput.value );
				}
			} );
		}
	}

	/*
	 * Connection test.
	 */
	var testButton = document.getElementById( 'bms3o-test' );
	var testResult = document.getElementById( 'bms3o-test-result' );
	if ( testButton && testResult ) {
		testButton.addEventListener( 'click', function () {
			testButton.disabled = true;
			testResult.textContent = config.i18n.running;

			post( { action: 'bms3o_test' } )
				.then( function ( data ) {
					testResult.textContent = '';
					data.steps.forEach( function ( step ) {
						var li = document.createElement( 'li' );
						li.className = step.ok ? 'is-ok' : 'is-error';
						li.textContent = ( step.ok ? '✔ ' : '✘ ' ) + step.step + ( step.message ? ' – ' + step.message : '' );
						testResult.appendChild( li );
					} );
				} )
				.catch( function ( error ) {
					testResult.textContent = config.i18n.failed + ' ' + error.message;
				} )
				.then( function () {
					testButton.disabled = false;
				} );
		} );
	}

	/*
	 * Bulk operations.
	 */
	Array.prototype.forEach.call( document.querySelectorAll( '.bms3o-card' ), function ( card ) {
		var button = card.querySelector( '.bms3o-start' );
		var progress = card.querySelector( '.bms3o-progress' );
		var status = card.querySelector( '.bms3o-status' );
		var errorList = card.querySelector( '.bms3o-errors' );
		var removeLocal = card.querySelector( '.bms3o-remove-local' );
		var total = parseInt( card.getAttribute( 'data-total' ), 10 ) || 0;
		var startLabel = button.textContent;
		var running = false;
		var processed = 0;
		var errors = 0;

		function update() {
			progress.hidden = false;
			progress.value = total ? Math.min( 100, Math.round( processed / total * 100 ) ) : 100;
			status.textContent = config.i18n.processed + ': ' + processed + ' / ' + total + ( errors ? ' · ' + config.i18n.errors + ': ' + errors : '' );
		}

		function finish( message ) {
			running = false;
			button.textContent = startLabel;
			status.textContent += ' ' + message;
		}

		// Delays (seconds) before retrying a failed request; then the operation stops.
		var retryDelays = [ 3, 10, 30 ];

		function step( cursor, attempt ) {
			attempt = attempt || 0;
			if ( ! running ) {
				update();
				finish( config.i18n.stopped );
				return;
			}
			post( {
				action: 'bms3o_bulk',
				operation: card.getAttribute( 'data-operation' ),
				cursor: cursor,
				remove_local: removeLocal && removeLocal.checked ? 1 : 0
			} )
				.then( function ( data ) {
					if ( data.total ) {
						total = data.total;
					}
					processed += data.processed;
					Object.keys( data.errors ).forEach( function ( id ) {
						errors++;
						var li = document.createElement( 'li' );
						li.textContent = '#' + id + ': ' + data.errors[ id ];
						errorList.appendChild( li );
					} );
					update();
					if ( data.done ) {
						finish( config.i18n.done );
					} else {
						step( data.cursor );
					}
				} )
				.catch( function ( error ) {
					// Server-side refusals (permissions, invalid request) are not retried.
					if ( ! error.retryable || attempt >= retryDelays.length || ! running ) {
						update();
						finish( config.i18n.failed + ' ' + error.message );
						return;
					}
					update();
					status.textContent += ' · ' + config.i18n.retrying.replace( '%d', retryDelays[ attempt ] );
					window.setTimeout( function () {
						step( cursor, attempt + 1 );
					}, retryDelays[ attempt ] * 1000 );
				} );
		}

		button.addEventListener( 'click', function () {
			if ( running ) {
				running = false;
				return;
			}
			if ( card.getAttribute( 'data-danger' ) === '1' && ! window.confirm( config.i18n.confirm ) ) {
				return;
			}
			running = true;
			processed = 0;
			errors = 0;
			errorList.textContent = '';
			button.textContent = config.i18n.stop;
			update();
			step( 0 );
		} );
	} );
	/*
	 * Background operations.
	 */
	var panel = document.getElementById( 'bms3o-background' );
	if ( panel ) {
		var bgTitle = panel.querySelector( '.bms3o-bg-title' );
		var bgStatus = panel.querySelector( '.bms3o-bg-status' );
		var bgProgress = panel.querySelector( '.bms3o-progress' );
		var bgErrors = panel.querySelector( '.bms3o-errors' );
		var bgStop = panel.querySelector( '.bms3o-bg-stop' );
		var pollTimer = null;

		var render = function ( state ) {
			if ( ! state ) {
				panel.hidden = true;
				return;
			}
			var running = 'running' === state.status;
			var labels = { running: config.i18n.bgRunning, done: config.i18n.bgDone, stopped: config.i18n.bgStopped };
			panel.hidden = false;
			panel.className = 'bms3o-background notice inline ' + ( running ? 'notice-info' : ( 'done' === state.status ? 'notice-success' : 'notice-warning' ) );
			bgTitle.textContent = ( labels[ state.status ] || state.status ) + ': ' + state.label;
			bgStatus.textContent = config.i18n.processed + ': ' + state.processed + ' / ' + state.total;
			bgProgress.value = state.total ? Math.min( 100, Math.round( state.processed / state.total * 100 ) ) : ( running ? 0 : 100 );
			bgStop.hidden = ! running;
			bgErrors.textContent = '';
			Object.keys( state.errors || {} ).forEach( function ( id ) {
				var li = document.createElement( 'li' );
				li.textContent = '#' + id + ': ' + state.errors[ id ];
				bgErrors.appendChild( li );
			} );

			window.clearTimeout( pollTimer );
			if ( running ) {
				pollTimer = window.setTimeout( poll, 5000 );
			}
		};

		var poll = function ( command, extra ) {
			var data = { action: 'bms3o_background', command: command || 'status' };
			Object.keys( extra || {} ).forEach( function ( key ) {
				data[ key ] = extra[ key ];
			} );
			return post( data ).then( function ( result ) {
				render( result.state );
			} ).catch( function ( error ) {
				window.alert( config.i18n.failed + ' ' + error.message );
			} );
		};

		bgStop.addEventListener( 'click', function () {
			poll( 'stop' );
		} );

		Array.prototype.forEach.call( document.querySelectorAll( '.bms3o-start-background' ), function ( button ) {
			button.addEventListener( 'click', function () {
				var card = button.closest( '.bms3o-card' );
				if ( card.getAttribute( 'data-danger' ) === '1' && ! window.confirm( config.i18n.confirm ) ) {
					return;
				}
				var removeLocal = card.querySelector( '.bms3o-remove-local' );
				poll( 'start', {
					operation: card.getAttribute( 'data-operation' ),
					remove_local: removeLocal && removeLocal.checked ? 1 : 0
				} );
			} );
		} );

		poll( 'status' );
	}
}() );
