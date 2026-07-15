/* Tournament Manager front-end round-entry panel - vanilla JS, no dependencies.
 * Five behaviors:
 *   1. The boards repeater on the round-entry form. "+ Add board" clones
 *      the <template> row; "Remove" drops a row from the DOM.
 *
 *      Unlike the admin repeaters in assets/wpmtm-admin.js, board rows
 *      carry no per-row id and their field names are three parallel
 *      arrays - board_white[], board_black[], board_result[] - with no
 *      index token to rewrite on clone: a select always submits a value,
 *      so the indexes stay aligned across the three arrays row by row,
 *      and a whole round is replaced wholesale on save
 *      (WPMTM_Repository::replace_round()), so there is nothing to track
 *      for server-side per-row deletion the way the admin sections/players
 *      repeaters do.
 *   2. Double-submit guard: a form marked data-wpmtm-guard disables its
 *      submit button(s) on submit (swapping their text to
 *      data-wpmtm-busy-label, if set) and ignores a second submit of the
 *      same form (used by the round-entry Save round form, whose write
 *      can take a moment).
 *   3. Print button (Change 5): a [data-wpmtm-print] click triggers
 *      window.print(), the same pattern wp-etr's assets/etr-tabs.js uses
 *      for its own [data-etr-print] Print button.
 *   4. Self-disabling "Suggest pairings" button (docs/SPEC.md, 2026-07-14):
 *      a .wpmtm-suggest-btn click disables the link, marks it
 *      aria-disabled, and swaps its label to its own data-busy-label
 *      (localized server-side, see
 *      WPMTM_Frontend_TD::render_suggest_link()) before letting the
 *      click/navigation proceed to the same href as before - the
 *      suggestion is still built server-side on the next page load, so
 *      this busy state simply persists until that reload lands. A second
 *      click while already busy (e.g. a double-click) is ignored outright.
 *   5. "Validate players" button (docs/SPEC.md, 2026-07-14, USCF status
 *      validation): wp-etr renders [data-wpmtm-validate-players] in its
 *      Registrations-tab toolbar; a click here POSTs admin-ajax
 *      action=wpmtm_validate_players and renders a results table (name,
 *      USCF ID, status, expiration, verdict) plus a summary line right
 *      below the toolbar. All strings/ajaxurl come from the wpmtmValidate
 *      object WPMTM_USCF_Status::maybe_enqueue_frontend() localizes; all
 *      response data lands in the DOM via textContent, never innerHTML.
 */
( function () {
	'use strict';

	function bindRemove( row ) {
		var btn = row.querySelector( '[data-remove-row]' );
		if ( ! btn ) {
			return;
		}
		btn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
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

		Array.prototype.slice.call( body.querySelectorAll( 'tr' ) ).forEach( bindRemove );

		var addBtn = document.querySelector( '[data-wpmtm-add-board-for="' + table.id + '"]' );
		if ( ! addBtn || ! tmpl || ! tmpl.content ) {
			return;
		}

		addBtn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			var clone = tmpl.content.firstElementChild.cloneNode( true );

			Array.prototype.slice.call( clone.querySelectorAll( 'select' ) ).forEach( function ( field ) {
				field.selectedIndex = 0;
			} );

			bindRemove( clone );
			body.appendChild( clone );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		Array.prototype.slice.call( document.querySelectorAll( '[data-wpmtm-round-repeater]' ) ).forEach( initRepeater );
	} );

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '[data-wpmtm-print]' ) : null;
		if ( ! btn ) {
			return;
		}
		window.print();
	} );

	document.addEventListener( 'click', function ( e ) {
		var link = e.target.closest ? e.target.closest( '.wpmtm-suggest-btn' ) : null;
		if ( ! link ) {
			return;
		}
		if ( link.getAttribute( 'aria-disabled' ) === 'true' ) {
			e.preventDefault();
			return;
		}
		link.classList.add( 'wpmtm-btn-busy' );
		link.setAttribute( 'aria-disabled', 'true' );
		var busyLabel = link.getAttribute( 'data-busy-label' );
		if ( busyLabel ) {
			link.textContent = busyLabel;
		}
		// Navigation proceeds to the same href - the busy state simply
		// persists visually until the next page load lands.
	} );

	// Behavior 5: "Validate players" (see the header comment above).
	function sprintfLite( template, values ) {
		return String( template ).replace( /%(\d+)\$s/g, function ( match, num ) {
			var index = parseInt( num, 10 ) - 1;
			return typeof values[ index ] !== 'undefined' ? String( values[ index ] ) : match;
		} );
	}

	function appendCell( row, tag, text, className ) {
		var cell = document.createElement( tag );
		if ( className ) {
			cell.className = className;
		}
		cell.textContent = text;
		row.appendChild( cell );
		return cell;
	}

	function renderPlayersResults( container, data, i18n ) {
		container.textContent = '';

		var summary  = data.summary || { total: 0, pass: 0, fail: 0, unknown: 0 };
		var problems = summary.fail + summary.unknown;

		var summaryEl = document.createElement( 'p' );
		summaryEl.className = 'wpmtm-validate-summary';
		var strong = document.createElement( 'strong' );
		strong.textContent = problems === 0
			? sprintfLite( i18n.summaryAllPass, [ summary.pass, summary.total ] )
			: sprintfLite( i18n.summaryMixed, [ summary.pass, summary.total, problems ] );
		summaryEl.appendChild( strong );
		if ( summary.unknown > 0 ) {
			summaryEl.appendChild(
				document.createTextNode( ' ' + sprintfLite( i18n.summaryUnknown, [ summary.unknown ] ) )
			);
		}
		container.appendChild( summaryEl );

		var table = document.createElement( 'table' );
		table.className = 'wpmtm-table wpmtm-validate-table';

		var thead   = document.createElement( 'thead' );
		var headRow = document.createElement( 'tr' );
		[ i18n.colName, i18n.colUscfId, i18n.colStatus, i18n.colExpiration, i18n.colVerdict ].forEach( function ( label ) {
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
			appendCell( row, 'td', r.name );
			appendCell( row, 'td', r.member_id );
			appendCell( row, 'td', r.status );
			appendCell( row, 'td', r.expiration );
			var verdict = r.verdict + ( r.reason ? ' - ' + r.reason : '' ) + ( r.warn ? ' (' + r.warn + ')' : '' );
			appendCell( row, 'td', verdict, 'wpmtm-validate-verdict' );
			tbody.appendChild( row );
		} );
		table.appendChild( tbody );
		container.appendChild( table );
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '[data-wpmtm-validate-players]' ) : null;
		if ( ! btn || btn.disabled || typeof window.wpmtmValidate === 'undefined' ) {
			return;
		}
		e.preventDefault();

		var i18n     = window.wpmtmValidate.i18n;
		var original = btn.textContent;
		btn.disabled = true;
		btn.textContent = i18n.checking;

		var toolbar   = btn.closest ? btn.closest( '.etr-toolbar' ) : null;
		var anchor    = toolbar || btn;
		var container = anchor.parentNode.querySelector( '.wpmtm-validate-results' );
		if ( ! container ) {
			container = document.createElement( 'div' );
			container.className = 'wpmtm-validate-results';
			anchor.parentNode.insertBefore( container, anchor.nextSibling );
		}

		var body = 'action=wpmtm_validate_players'
			+ '&event=' + encodeURIComponent( btn.getAttribute( 'data-event' ) || '' )
			+ '&nonce=' + encodeURIComponent( btn.getAttribute( 'data-nonce' ) || '' );

		fetch( window.wpmtmValidate.ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body
		} ).then( function ( response ) {
			return response.json();
		} ).then( function ( json ) {
			if ( json && json.success && json.data ) {
				renderPlayersResults( container, json.data, i18n );
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
			return;
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
