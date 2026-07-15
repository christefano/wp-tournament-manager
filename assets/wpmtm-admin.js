/* Tournament Manager admin screens - vanilla JS, no dependencies.
 * Six small behaviors:
 *   1. Repeater tables (sections editor, players editor): "+ Add row"
 *      clones a <template> row with fresh field names; "Remove" either
 *      drops an unsaved row from the DOM or flags an existing row for
 *      server-side deletion via a hidden removed_* field, then drops it.
 *   2. Confirm before following a link or submitting a form marked
 *      data-wpmtm-confirm (used by destructive row-action links such as
 *      the tournament delete link).
 *   3. Double-submit guard: a form marked data-wpmtm-guard disables its
 *      submit button(s) on submit (swapping their text to
 *      data-wpmtm-busy-label, if set) and ignores a second submit of the
 *      same form (used by the ETR import forms, whose write can take a
 *      few seconds).
 *   4. Round robin hint: a section row's Type select
 *      (data-wpmtm-trn-type) shows or hides the one-line round-robin
 *      description (data-wpmtm-rr-hint) in the same table cell, matching
 *      whichever type is currently selected. Shown for both 'R' (Round
 *      Robin) and 'Q' (Quad, a 4-player round robin that behaves exactly
 *      like Round Robin), mirroring WPMTM_Pairing_Aid::RR_TYPES on the
 *      PHP side.
 *   5. Linked event date prefill: on the Add/Edit Tournament form,
 *      choosing an event in the "Linked event" select (#wpmtm-event-post-id)
 *      fills the Begin/End date fields (#wpmtm-begin-date / #wpmtm-end-date)
 *      from that option's data-begin/data-end attributes - but only when a
 *      field is currently empty, so it never clobbers a date the TD already
 *      typed in (including on Edit, where both fields already hold the
 *      saved tournament's dates).
 *   6. "Validate TDs" button (docs/SPEC.md, 2026-07-14, USCF status
 *      validation): a [data-wpmtm-validate-tds] click (Settings page and
 *      tournament edit page) POSTs admin-ajax action=wpmtm_validate_tds
 *      and renders one result row each for the affiliate, the Chief TD,
 *      and the Assistant TD (when set) into the adjacent
 *      [data-wpmtm-validate-tds-results] container. Strings come from the
 *      wpmtmValidateTds object WPMTM_Admin::enqueue_assets() localizes;
 *      all response data lands in the DOM via textContent, never
 *      innerHTML.
 */
( function () {
	'use strict';

	function bindRemove( row, removedInput ) {
		var btn = row.querySelector( '[data-remove-row]' );
		if ( ! btn ) {
			return;
		}
		btn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			var existingId = row.getAttribute( 'data-existing-id' );
			if ( existingId && removedInput ) {
				var ids = removedInput.value ? removedInput.value.split( ',' ).filter( Boolean ) : [];
				ids.push( existingId );
				removedInput.value = ids.join( ',' );
			}
			if ( row.parentNode ) {
				row.parentNode.removeChild( row );
			}
		} );
	}

	function initRepeater( table ) {
		var body = table.querySelector( 'tbody' );
		var tmpl = table.querySelector( 'template' );
		if ( ! body ) {
			return;
		}

		var removedInputId = table.getAttribute( 'data-removed-input' );
		var removedInput   = removedInputId ? document.getElementById( removedInputId ) : null;

		Array.prototype.slice.call( body.querySelectorAll( 'tr' ) ).forEach( function ( row ) {
			bindRemove( row, removedInput );
		} );

		var addBtn = document.querySelector( '[data-add-row-for="' + table.id + '"]' );
		if ( ! addBtn || ! tmpl || ! tmpl.content ) {
			return;
		}

		var counter = 0;
		addBtn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			counter += 1;
			var clone = tmpl.content.firstElementChild.cloneNode( true );

			Array.prototype.slice.call( clone.querySelectorAll( '[name]' ) ).forEach( function ( field ) {
				field.name = field.name.replace( '__INDEX__', 'new' + counter );
				if ( field.type === 'checkbox' || field.type === 'radio' ) {
					field.checked = false;
				} else if ( field.tagName !== 'SELECT' ) {
					field.value = '';
				}
			} );

			bindRemove( clone, removedInput );
			body.appendChild( clone );

			var firstField = clone.querySelector( 'input[type="text"]' );
			if ( firstField ) {
				firstField.focus();
			}
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		Array.prototype.slice.call( document.querySelectorAll( '[data-wpmtm-repeater]' ) ).forEach( initRepeater );
	} );

	var RR_TYPES = [ 'R', 'Q' ]; // mirrors WPMTM_Pairing_Aid::RR_TYPES.

	function syncRoundRobinHint( select ) {
		var cell = select.closest ? select.closest( 'td' ) : null;
		var hint = cell ? cell.querySelector( '[data-wpmtm-rr-hint]' ) : null;
		if ( ! hint ) {
			return;
		}
		hint.hidden = RR_TYPES.indexOf( select.value ) === -1;
	}

	document.addEventListener( 'change', function ( e ) {
		var select = e.target;
		if ( select && select.hasAttribute && select.hasAttribute( 'data-wpmtm-trn-type' ) ) {
			syncRoundRobinHint( select );
		}
	} );

	document.addEventListener( 'DOMContentLoaded', function () {
		Array.prototype.slice.call( document.querySelectorAll( '[data-wpmtm-trn-type]' ) ).forEach( syncRoundRobinHint );
	} );

	document.addEventListener( 'submit', function ( e ) {
		var form = e.target;
		if ( form && form.hasAttribute && form.hasAttribute( 'data-wpmtm-confirm' ) ) {
			var message = form.getAttribute( 'data-wpmtm-confirm' );
			if ( ! window.confirm( message ) ) { // eslint-disable-line no-alert
				e.preventDefault();
			}
		}
	} );

	document.addEventListener( 'click', function ( e ) {
		// Matches both a plain confirm-guarded link (e.g. the tournaments
		// list Delete link) and a confirm-guarded submit button inside a
		// form (e.g. the lock/unlock button) - preventDefault() on the
		// button's click event stops the browser's default action of
		// submitting its form, the same way it stops a link's navigation.
		var el = e.target.closest ? e.target.closest( 'a[data-wpmtm-confirm], button[data-wpmtm-confirm]' ) : null;
		if ( ! el ) {
			return;
		}
		var message = el.getAttribute( 'data-wpmtm-confirm' );
		if ( ! window.confirm( message ) ) { // eslint-disable-line no-alert
			e.preventDefault();
		}
	} );

	function fillLinkedEventDates( select ) {
		var option = select.options[ select.selectedIndex ];
		if ( ! option ) {
			return;
		}
		var begin = option.getAttribute( 'data-begin' );
		var end   = option.getAttribute( 'data-end' );

		var beginField = document.getElementById( 'wpmtm-begin-date' );
		var endField   = document.getElementById( 'wpmtm-end-date' );

		if ( beginField && ! beginField.value && begin ) {
			beginField.value = begin;
		}
		if ( endField && ! endField.value && end ) {
			endField.value = end;
		}
	}

	document.addEventListener( 'change', function ( e ) {
		var select = e.target;
		if ( select && select.id === 'wpmtm-event-post-id' ) {
			fillLinkedEventDates( select );
		}
	} );

	// Behavior 6: "Validate TDs" (see the header comment above).
	function appendValidateCell( row, tag, lines, className ) {
		var cell = document.createElement( tag );
		if ( className ) {
			cell.className = className;
		}
		( Array.isArray( lines ) ? lines : [ lines ] ).forEach( function ( line, index ) {
			if ( index > 0 ) {
				cell.appendChild( document.createElement( 'br' ) );
			}
			cell.appendChild( document.createTextNode( line ) );
		} );
		row.appendChild( cell );
		return cell;
	}

	function joinParts( parts ) {
		return parts.filter( function ( p ) {
			return p !== '' && p !== null && typeof p !== 'undefined';
		} ).join( ' ' );
	}

	function renderTdResults( container, data, i18n ) {
		container.textContent = '';

		var note = document.createElement( 'p' );
		note.className = 'description';
		note.textContent = i18n.throughNote.replace( '%s', data.through || '' );
		container.appendChild( note );

		var table = document.createElement( 'table' );
		table.className = 'widefat striped wpmtm-validate-table';

		var thead   = document.createElement( 'thead' );
		var headRow = document.createElement( 'tr' );
		[ i18n.colRole, i18n.colUscfId, i18n.colName, i18n.colMembership, i18n.colTdCert, i18n.colSafePlay, i18n.colVerdict ].forEach( function ( label ) {
			var th = document.createElement( 'th' );
			th.scope = 'col';
			th.textContent = label;
			headRow.appendChild( th );
		} );
		thead.appendChild( headRow );
		table.appendChild( thead );

		var tbody = document.createElement( 'tbody' );
		( data.rows || [] ).forEach( function ( r ) {
			var row = document.createElement( 'tr' );
			row.className = 'wpmtm-validate-row wpmtm-validate-row--' + String( r.verdict ).toLowerCase();
			appendValidateCell( row, 'td', r.role );
			appendValidateCell( row, 'td', r.member_id );
			appendValidateCell( row, 'td', r.name );
			appendValidateCell( row, 'td', joinParts( [ r.status, r.expiration ] ) );
			// The affiliate row has no TD cert / Safe Play columns at all
			// (its validate path never sets them), so both render blank.
			appendValidateCell( row, 'td', joinParts( [ r.td_level, r.td_cert_status, r.td_cert_expiration ] ) );
			appendValidateCell( row, 'td', r.safe_play_expiration || '' );
			var verdictLines = [ r.verdict + ( r.reason ? ' - ' + r.reason : '' ) ];
			if ( r.warn ) {
				verdictLines.push( '(' + r.warn + ')' );
			}
			appendValidateCell( row, 'td', verdictLines, 'wpmtm-validate-verdict' );
			tbody.appendChild( row );
		} );
		table.appendChild( tbody );
		container.appendChild( table );
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '[data-wpmtm-validate-tds]' ) : null;
		if ( ! btn || btn.disabled || typeof window.wpmtmValidateTds === 'undefined' ) {
			return;
		}
		e.preventDefault();

		var i18n     = window.wpmtmValidateTds;
		var original = btn.textContent;
		btn.disabled = true;
		btn.textContent = i18n.checking;

		var scope     = ( btn.closest ? btn.closest( 'td' ) : null ) || btn.parentNode;
		var container = scope.querySelector( '[data-wpmtm-validate-tds-results]' );
		if ( ! container ) {
			container = document.createElement( 'div' );
			container.setAttribute( 'data-wpmtm-validate-tds-results', '' );
			btn.parentNode.insertBefore( container, btn.nextSibling );
		}

		var body = 'action=wpmtm_validate_tds'
			+ '&context=' + encodeURIComponent( btn.getAttribute( 'data-context' ) || 'settings' )
			+ '&nonce=' + encodeURIComponent( btn.getAttribute( 'data-nonce' ) || '' );
		var tournament = btn.getAttribute( 'data-tournament' );
		if ( tournament ) {
			body += '&tournament=' + encodeURIComponent( tournament );
		}

		fetch( window.ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body
		} ).then( function ( response ) {
			return response.json();
		} ).then( function ( json ) {
			if ( json && json.success && json.data ) {
				renderTdResults( container, json.data, i18n );
			} else {
				container.textContent = ( json && json.data && json.data.message ) ? json.data.message : i18n.requestFailed;
			}
		} ).catch( function () {
			container.textContent = i18n.requestFailed;
		} ).then( function () {
			btn.disabled = false;
			btn.textContent = original;
		} );
	} );

	document.addEventListener( 'submit', function ( e ) {
		var form = e.target;
		if ( ! form || ! form.hasAttribute || ! form.hasAttribute( 'data-wpmtm-guard' ) ) {
			return;
		}
		if ( e.defaultPrevented ) {
			return; // an earlier listener (e.g. data-wpmtm-confirm above) already cancelled this submit.
		}
		if ( form.getAttribute( 'data-submitted' ) ) {
			e.preventDefault();
			return;
		}
		form.setAttribute( 'data-submitted', '1' );

		// Disabling the button synchronously here would be safe too, since
		// form serialization happens after submit handlers run - but a
		// setTimeout(0) is the safer bet against browser edge cases that
		// might otherwise drop the click.
		setTimeout( function () {
			var buttons = form.querySelectorAll( 'button[type="submit"], input[type="submit"]' );
			Array.prototype.slice.call( buttons ).forEach( function ( button ) {
				button.disabled = true;
				var busyLabel = button.getAttribute( 'data-wpmtm-busy-label' );
				if ( ! busyLabel ) {
					return;
				}
				if ( 'INPUT' === button.tagName ) {
					button.value = busyLabel;
				} else {
					button.textContent = busyLabel;
				}
			} );
		}, 0 );
	} );
} )();
