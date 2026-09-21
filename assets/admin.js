/* global jevGuard */
( function () {
	'use strict';

	if ( typeof jevGuard === 'undefined' ) {
		return;
	}

	var i18n = jevGuard.i18n || {};

	function sprintf( format ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var i = 0;
		return String( format ).replace( /%%|%(\d+\$)?[sd]/g, function ( match, pos ) {
			if ( match === '%%' ) {
				return '%';
			}
			var index = pos ? parseInt( pos, 10 ) - 1 : i++;
			return typeof args[ index ] === 'undefined' ? '' : args[ index ];
		} );
	}

	function post( action, data ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', jevGuard.nonce );
		Object.keys( data || {} ).forEach( function ( key ) {
			body.append( key, data[ key ] );
		} );
		return fetch( jevGuard.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				if ( ! json || typeof json.success === 'undefined' ) {
					throw new Error( 'Unexpected response' );
				}
				if ( ! json.success ) {
					var err = new Error( ( json.data && json.data.message ) || 'Request failed' );
					err.data = json.data || {};
					throw err;
				}
				return json.data;
			} );
	}

	function sleep( ms ) {
		return new Promise( function ( resolve ) {
			setTimeout( resolve, ms );
		} );
	}

	/**
	 * Exponential backoff for provider rate limits: 5 s → 60 s, honouring Retry-After.
	 */
	function backoffDelay( attempt, retryAfter ) {
		var base = Math.min( 60000, 5000 * Math.pow( 2, attempt ) );
		if ( retryAfter && retryAfter > 0 ) {
			return Math.min( 60000, Math.max( base, retryAfter * 1000 ) );
		}
		return base;
	}

	function setText( el, text, cls ) {
		if ( ! el ) {
			return;
		}
		el.textContent = text;
		el.className = el.className.replace( /\bis-(ok|error|busy)\b/g, '' ).trim();
		if ( cls ) {
			el.className += ' is-' + cls;
		}
	}

	function escapeHtml( text ) {
		var div = document.createElement( 'div' );
		div.textContent = text == null ? '' : String( text );
		return div.innerHTML;
	}

	/* -----------------------------------------------------------------
	 * Notices (all admin pages)
	 * -------------------------------------------------------------- */

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '.jev-guard-notice .notice-dismiss' );
		if ( ! button ) {
			return;
		}
		post( 'jev_guard_dismiss_notice', {} ).catch( function () {} );
	} );

	/* -----------------------------------------------------------------
	 * Settings page
	 * -------------------------------------------------------------- */

	var providerSelect = document.getElementById( 'jev_guard_provider' );
	if ( providerSelect ) {
		document.body.classList.add( 'jev-guard-js' );

		var updateProvider = function () {
			var id = providerSelect.value;
			var preset = ( jevGuard.providers || {} )[ id ] || {};
			document.querySelectorAll( '.jev-guard-provider-note' ).forEach( function ( note ) {
				note.classList.toggle( 'is-active', note.getAttribute( 'data-provider' ) === id );
			} );
			var customRow = document.querySelector( '.jev-guard-row-custom' );
			if ( customRow ) {
				customRow.hidden = id !== 'custom';
			}
			var model = document.getElementById( 'jev_guard_model' );
			if ( model ) {
				model.placeholder = preset.model || '';
			}
		};
		providerSelect.addEventListener( 'change', updateProvider );
		updateProvider();

		var testButton = document.getElementById( 'jev-guard-test-connection' );
		var testResult = document.getElementById( 'jev-guard-test-result' );
		if ( testButton ) {
			testButton.addEventListener( 'click', function () {
				var value = function ( id ) {
					var el = document.getElementById( id );
					return el ? el.value : '';
				};
				testButton.disabled = true;
				setText( testResult, i18n.testing, 'busy' );
				post( 'jev_guard_test_connection', {
					provider: value( 'jev_guard_provider' ),
					api_key: value( 'jev_guard_api_key' ),
					model: value( 'jev_guard_model' ),
					custom_endpoint: value( 'jev_guard_custom_endpoint' ),
					timeout: value( 'jev_guard_timeout' )
				} )
					.then( function ( data ) {
						setText(
							testResult,
							sprintf( i18n.testOk, data.model, data.latency_ms, Math.round( data.probability * 100 ), data.category ),
							'ok'
						);
					} )
					.catch( function ( err ) {
						var d = err.data || {};
						setText( testResult, sprintf( i18n.testFail, ( d.label ? d.label + ' — ' : '' ) + ( d.message || err.message ) ), 'error' );
					} )
					.then( function () {
						testButton.disabled = false;
					} );
			} );
		}

		var calibrateButton = document.getElementById( 'jev-guard-calibrate' );
		if ( calibrateButton ) {
			calibrateButton.addEventListener( 'click', runCalibration );
		}
	}

	function runCalibration() {
		var button = document.getElementById( 'jev-guard-calibrate' );
		var status = document.getElementById( 'jev-guard-calibration-status' );
		var progress = document.getElementById( 'jev-guard-calibration-progress' );
		var bar = progress ? progress.querySelector( '.jev-guard-progress-bar' ) : null;
		var results = document.getElementById( 'jev-guard-calibration-results' );
		var n = parseInt( document.getElementById( 'jev-guard-calibration-size' ).value, 10 ) || 50;

		button.disabled = true;
		results.innerHTML = '';
		setText( status, i18n.calSampling, 'busy' );
		progress.hidden = false;
		bar.style.width = '0%';

		var queue = [];
		var rows = [];
		var attempt = 0;

		var finish = function () {
			button.disabled = false;
			progress.hidden = true;
		};

		var step = function () {
			if ( ! queue.length ) {
				setText( status, '', '' );
				renderCalibration( results, rows );
				finish();
				return;
			}
			var batch = queue.slice( 0, 10 );
			post( 'jev_guard_calibration_batch', { items: JSON.stringify( batch ) } )
				.then( function ( data ) {
					( data.results || [] ).forEach( function ( row ) {
						rows.push( row );
						queue = queue.filter( function ( item ) {
							return item.id !== row.id;
						} );
					} );
					var total = rows.length + queue.length;
					bar.style.width = Math.round( ( rows.length / total ) * 100 ) + '%';
					setText( status, sprintf( i18n.calProgress, rows.length, total ), 'busy' );

					if ( data.halt ) {
						if ( data.halt.code === 'rate_limited' || data.halt.code === 'overloaded' ) {
							var wait = backoffDelay( attempt++, data.halt.retry_after );
							setText( status, sprintf( i18n.rateLimited, Math.round( wait / 1000 ) ), 'busy' );
							return sleep( wait ).then( step );
						}
						setText( status, sprintf( i18n.error, data.halt.label + ' — ' + data.halt.message ), 'error' );
						renderCalibration( results, rows );
						finish();
						return;
					}
					attempt = 0;
					return step();
				} )
				.catch( function ( err ) {
					setText( status, sprintf( i18n.error, err.message ), 'error' );
					finish();
				} );
		};

		post( 'jev_guard_calibration_sample', { n: n } )
			.then( function ( sample ) {
				( sample.spam || [] ).forEach( function ( id ) {
					queue.push( { id: id, expected: 'spam' } );
				} );
				( sample.ham || [] ).forEach( function ( id ) {
					queue.push( { id: id, expected: 'ham' } );
				} );
				if ( queue.length < 4 ) {
					setText( status, i18n.calNoData, 'error' );
					finish();
					return;
				}
				step();
			} )
			.catch( function ( err ) {
				setText( status, sprintf( i18n.error, err.message ), 'error' );
				finish();
			} );
	}

	/**
	 * Applies the plugin's tier policy in JS so alternative thresholds can be evaluated locally.
	 */
	function decide( row, spamT, holdT, holdAbusive, maxLinks ) {
		var p = row.p || 0;
		if ( maxLinks > 0 && ( row.links || 0 ) >= maxLinks && p >= 0.5 ) {
			return 'spam';
		}
		if ( p >= spamT ) {
			return ( row.genuine != null && row.genuine >= 0.5 ) ? 'hold' : 'spam';
		}
		if ( p >= holdT ) {
			return 'hold';
		}
		if ( holdAbusive && row.abuse != null && row.abuse >= 1.5 ) {
			return 'hold';
		}
		return 'allow';
	}

	function renderCalibration( container, rows ) {
		var spamT = parseFloat( container.getAttribute( 'data-spam-threshold' ) ) || 0.85;
		var holdT = parseFloat( container.getAttribute( 'data-hold-threshold' ) ) || 0.5;
		var holdAbusive = container.getAttribute( 'data-hold-abusive' ) === '1';
		var maxLinks = parseInt( container.getAttribute( 'data-max-links' ), 10 ) || 0;

		var scored = rows.filter( function ( r ) {
			return ! r.error && r.p != null;
		} );
		var errors = rows.length - scored.length;
		var spam = scored.filter( function ( r ) { return r.expected === 'spam'; } );
		var ham = scored.filter( function ( r ) { return r.expected === 'ham'; } );

		var tally = function ( sT, hT ) {
			var t = { fp: 0, heldHam: 0, missed: 0, heldSpam: 0, caught: 0, correct: 0 };
			ham.forEach( function ( r ) {
				var d = decide( r, sT, hT, holdAbusive, maxLinks );
				if ( d === 'spam' ) { t.fp++; } else if ( d === 'hold' ) { t.heldHam++; t.correct++; } else { t.correct++; }
			} );
			spam.forEach( function ( r ) {
				var d = decide( r, sT, hT, holdAbusive, maxLinks );
				if ( d === 'spam' ) { t.caught++; t.correct++; } else if ( d === 'hold' ) { t.heldSpam++; t.correct++; } else { t.missed++; }
			} );
			return t;
		};

		var current = tally( spamT, holdT );

		// Suggestion: lowest spam threshold with zero false positives (fallback: fewest), hold threshold just under the lowest spam score.
		var best = null;
		for ( var t = 50; t <= 99; t++ ) {
			var sT = t / 100;
			var hT = Math.min( holdT, sT );
			var r = tally( sT, hT );
			if ( ! best || r.fp < best.fp || ( r.fp === best.fp && r.caught > best.caught ) ) {
				best = { fp: r.fp, caught: r.caught, spamT: sT, holdT: hT };
			}
		}
		if ( best && spam.length ) {
			var minSpam = Math.min.apply( null, spam.map( function ( r ) { return r.p; } ) );
			var suggestedHold = Math.max( 0.2, Math.min( best.spamT - 0.05, Math.floor( minSpam * 100 ) / 100 ) );
			var withHold = tally( best.spamT, suggestedHold );
			if ( withHold.fp === best.fp ) {
				best.holdT = suggestedHold;
				best.caught = withHold.caught;
			}
		}

		var model = scored.length ? scored[ scored.length - 1 ].model : '';
		var html = '<table class="widefat striped jev-guard-calibration" style="max-width:720px"><tbody>';
		var line = function ( label, value ) {
			html += '<tr><th scope="row">' + escapeHtml( label ) + '</th><td>' + escapeHtml( value ) + '</td></tr>';
		};
		line( i18n.calAccuracy, scored.length ? Math.round( ( current.correct / scored.length ) * 1000 ) / 10 + '% (' + current.correct + '/' + scored.length + ')' : '—' );
		line( i18n.calFalsePos, current.fp + ' / ' + ham.length );
		line( i18n.calHeldHam, current.heldHam + ' / ' + ham.length );
		line( i18n.calCaught, current.caught + ' / ' + spam.length );
		line( i18n.calHeldSpam, current.heldSpam + ' / ' + spam.length );
		line( i18n.calMissed, current.missed + ' / ' + spam.length );
		html += '</tbody></table>';

		if ( best ) {
			html += '<p><strong>' + escapeHtml( sprintf( i18n.calSuggest, best.spamT.toFixed( 2 ), best.holdT.toFixed( 2 ), best.fp, best.caught, spam.length ) ) + '</strong></p>';
		}
		if ( model ) {
			html += '<p class="description">' + escapeHtml( sprintf( i18n.calModel, model ) ) + '</p>';
		}
		if ( jevGuard.pageContext ) {
			html += '<p class="description">' + escapeHtml( sprintf( i18n.calContext, jevGuard.pageContext ) ) + '</p>';
		}
		if ( errors ) {
			html += '<p class="description">' + escapeHtml( sprintf( i18n.calErrors, errors ) ) + '</p>';
		}

		var disagreements = scored.filter( function ( r ) {
			var d = decide( r, spamT, holdT, holdAbusive, maxLinks );
			return ( r.expected === 'spam' && d !== 'spam' ) || ( r.expected === 'ham' && d !== 'allow' );
		} );
		html += '<p>' + escapeHtml( i18n.calNote ) + '</p>';
		if ( ! disagreements.length ) {
			html += '<p><em>' + escapeHtml( i18n.calAllAgree ) + '</em></p>';
		} else {
			html += '<table class="widefat striped" style="max-width:960px"><thead><tr><th>' + escapeHtml( i18n.colComment ) + '</th><th>' + escapeHtml( i18n.colModeration ) + '</th><th>' + escapeHtml( i18n.colJev ) + '</th></tr></thead><tbody>';
			disagreements.forEach( function ( r ) {
				var d = decide( r, spamT, holdT, holdAbusive, maxLinks );
				var jev = Math.round( r.p * 100 ) + '% · ' + ( r.category || '' ) + ' → ' + ( d === 'spam' ? i18n.labelSpam : d === 'hold' ? i18n.labelHold : i18n.labelAllow );
				html += '<tr><td><a href="' + escapeHtml( r.link ) + '">' + escapeHtml( r.title || '#' + r.id ) + '</a><br><span class="description">' + escapeHtml( r.author ) + '</span></td>' +
					'<td>' + escapeHtml( r.expected === 'spam' ? i18n.labelSpam : i18n.labelApproved ) + '</td>' +
					'<td class="jev-guard-cell--' + d + '">' + escapeHtml( jev ) + '</td></tr>';
			} );
			html += '</tbody></table>';
		}
		container.innerHTML = html;
	}

	/* -----------------------------------------------------------------
	 * Comments screen
	 * -------------------------------------------------------------- */

	document.addEventListener( 'click', function ( event ) {
		var link = event.target.closest( '.jev-guard-recheck' );
		if ( ! link ) {
			return;
		}
		event.preventDefault();
		if ( link.dataset.busy ) {
			return;
		}
		var id = parseInt( link.getAttribute( 'data-comment-id' ), 10 );
		var row = link.closest( 'tr' );
		var cell = row ? row.querySelector( '.jev-guard-cell' ) : null;
		var inline = link.parentNode ? link.parentNode.querySelector( '.jev-guard-inline-result' ) : null;
		var original = link.textContent;

		link.dataset.busy = '1';
		link.textContent = i18n.recheck;
		if ( cell ) {
			cell.classList.add( 'is-busy' );
		}

		post( 'jev_guard_recheck', { comment_id: id } )
			.then( function ( data ) {
				if ( link.getAttribute( 'data-reload' ) ) {
					window.location.reload();
					return;
				}
				if ( cell ) {
					cell.outerHTML = data.html;
				}
				if ( data.moved && row ) {
					row.classList.add( 'jev-guard-row-moved' );
					var note = document.createElement( 'span' );
					note.className = 'jev-guard-moved';
					note.textContent = ' ' + i18n.movedToSpam;
					link.parentNode.appendChild( note );
					setTimeout( function () {
						row.style.transition = 'opacity .6s';
						row.style.opacity = '0.3';
					}, 1500 );
				}
			} )
			.catch( function ( err ) {
				if ( err.data && err.data.html && cell ) {
					cell.outerHTML = err.data.html;
				}
				if ( inline ) {
					setText( inline, sprintf( i18n.error, err.message ), 'error' );
				} else {
					window.alert( sprintf( i18n.error, err.message ) ); // eslint-disable-line no-alert
				}
			} )
			.then( function () {
				delete link.dataset.busy;
				link.textContent = original;
			} );
	} );

	var bulkButton = document.getElementById( 'jev-guard-check-all' );
	if ( bulkButton ) {
		bulkButton.addEventListener( 'click', function () {
			var status = document.getElementById( 'jev-guard-bulk-status' );
			var token = 'r' + Date.now().toString( 36 ) + Math.random().toString( 36 ).slice( 2, 8 );
			var after = 0;
			var total = 0;
			var checked = 0;
			var spam = 0;
			var attempt = 0;

			bulkButton.disabled = true;
			setText( status, i18n.bulkStart, 'busy' );

			var step = function () {
				post( 'jev_guard_bulk_batch', { after: after, token: token } )
					.then( function ( data ) {
						if ( after === 0 ) {
							total = data.total || 0;
						}
						checked += data.processed || 0;
						spam += data.spam || 0;
						after = data.last_id || after;

						if ( data.halt ) {
							if ( data.halt.fatal ) {
								setText( status, sprintf( i18n.error, data.halt.message ), 'error' );
								bulkButton.disabled = false;
								return;
							}
							var wait = backoffDelay( attempt++, data.halt.retry_after );
							setText( status, sprintf( i18n.rateLimited, Math.round( wait / 1000 ) ), 'busy' );
							return sleep( wait ).then( step );
						}
						attempt = 0;

						if ( data.done ) {
							if ( total === 0 && checked === 0 ) {
								setText( status, i18n.bulkNothing, 'ok' );
							} else {
								setText( status, sprintf( i18n.bulkDone, checked, spam ), 'ok' );
							}
							bulkButton.disabled = false;
							return;
						}
						setText( status, sprintf( i18n.bulkProgress, checked, total, spam ), 'busy' );
						return step();
					} )
					.catch( function ( err ) {
						setText( status, sprintf( i18n.error, err.message ), 'error' );
						bulkButton.disabled = false;
					} );
			};
			step();
		} );
	}
} )();
