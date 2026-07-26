/* Tournament Manager admin screens - vanilla JS, no dependencies.
 * Eight small behaviors:
 *   1. Repeater tables (sections editor, players editor): "+ Add row"
 *      clones a <template> row with fresh field names; "Remove" either
 *      drops an unsaved row from the DOM or flags an existing row for
 *      server-side deletion via a hidden removed_* field, then drops it. A
 *      table carrying data-wpmtm-remove-confirm (the players table only,
 *      2026-07-24 - removing a player also deletes their opponents' game
 *      rows for every round already played, not just this player's own
 *      data) window.confirm()s that message before removing an
 *      ALREADY-SAVED row (data-existing-id set); a row added and never
 *      saved yet has nothing to lose, so it is still removed instantly.
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
 *   6. "Validate with USCF" button (docs/SPEC.md, 2026-07-14, USCF status
 *      validation; renamed from "Validate TDs" 2026-07-18 since it checks
 *      the club affiliate too, not just the TDs): a [data-wpmtm-validate-tds]
 *      click (Settings page and tournament edit page) disables the button
 *      and swaps its text to "Validating with USCF..." (wpmtmValidateTds.validating),
 *      POSTs admin-ajax action=wpmtm_validate_tds, and renders one result
 *      row each for the affiliate, the Chief TD, and the Assistant TD
 *      (when set) into the adjacent [data-wpmtm-validate-tds-results]
 *      container. Strings come from the wpmtmValidateTds object
 *      WPMTM_Admin::enqueue_assets() localizes; all response data lands in
 *      the DOM via textContent, never innerHTML. On the tournament edit
 *      page only (docs/SPEC.md, 2026-07-16, TD check timestamp), the
 *      response also carries last_checked_text, which replaces the
 *      adjacent [data-wpmtm-td-check-last] element's text in place - no
 *      page reload needed to see "Last checked ... ago" update after a
 *      check.
 *   7. Setup guide panel (docs/SPEC.md, "Design (2026-07-16, setup guide
 *      redesign)"): the tournament edit page's [data-wpmtm-setup-guide-panel]
 *      is a native <details> element, so clicking its <summary> already
 *      opens/closes it with no JS at all. This behavior only persists that
 *      open/closed choice per TD: on the element's native 'toggle' event,
 *      it POSTs admin-ajax action=wpmtm_toggle_setup_guide_panel with the
 *      element's own data-nonce and the new open state. Fire-and-forget -
 *      the <details> element has already toggled itself by the time this
 *      fires, so nothing in the response is used.
 *   8. "Use default" button (docs/SPEC.md, 2026-07-17, TD default removal):
 *      the tournament edit page's Chief/Assistant TD ID fields each have an
 *      adjacent [data-wpmtm-use-default] button carrying the Settings
 *      default in its data-default attribute and the target field's id in
 *      data-target. A click copies data-default into that field verbatim -
 *      the tournament's own TD fields are never autopopulated on render, so
 *      this is the only path that ever puts a Settings default into a
 *      tournament field, and only on an explicit click. A blank default
 *      renders the button disabled server-side, so there is nothing left
 *      to copy.
 *   9. Setup guide step click (docs/SPEC.md, 2026-07-21, setup guide steps
 *      rework): before this, the stepper's step chips were inert labels -
 *      any click anywhere in the summary bar (including on a chip) only
 *      ever toggled the panel via the native <details>/<summary> behavior
 *      behavior 7 above persists. A [data-wpmtm-step-slug] click (or
 *      Enter/Space when it has focus) now shows THAT step's own Status/
 *      Next/CTA in the panel body, reading the per-step copy
 *      render_panel() already embedded server-side as JSON on the
 *      stepper's own data-wpmtm-steps-data attribute - no extra request.
 *      preventDefault() stops the native toggle the browser would
 *      otherwise run for any click landing inside <summary>, so clicking a
 *      chip changes what is displayed without opening/closing the panel -
 *      except when the panel starts closed, where the chip is visible but
 *      its target (the body) is not: that case explicitly opens the panel
 *      (a real, JS-driven toggle, so behavior 7's persistence still fires
 *      for it) rather than silently updating hidden content.
 *   10. Export button auto-reenable (2026-07-24, Tournament Export CSV
 *      button): a form marked BOTH data-wpmtm-guard and
 *      data-wpmtm-reenable-on-focus is a file-download form (the CSV/USCF
 *      export buttons) - the POST response is a Content-Disposition:
 *      attachment, so the browser downloads it without ever navigating
 *      away, and behavior 3's disable would otherwise be permanent until a
 *      manual page reload. Chosen over a fixed timer (owner decision,
 *      2026-07-24): re-enables on the window's next 'focus' or 'pageshow'
 *      event, since a save-file dialog blurs the window and refocusing it
 *      is the TD's own signal they are done, whether the download took 2
 *      seconds or 2 minutes - a timer either fires too early (button
 *      clickable mid-download) or forces an arbitrary wait. A 60-second
 *      fallback timer still re-enables even if neither event ever fires
 *      (e.g. a browser configured to skip the save dialog entirely, so the
 *      window's focus never actually leaves) - a dead-man's switch, not the
 *      primary mechanism. Restores each button's own original label
 *      (stashed in data-wpmtm-original-label before the busy-label swap),
 *      not just the disabled state.
 */
( function () {
	'use strict';

	function bindRemove( row, removedInput, confirmMessage ) {
		var btn = row.querySelector( '[data-remove-row]' );
		if ( ! btn ) {
			return;
		}
		btn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			var existingId = row.getAttribute( 'data-existing-id' );
			// Only an ALREADY-SAVED row has anything to lose - a row just
			// added and never saved yet is removed instantly, same as
			// before, no prompt.
			if ( existingId && confirmMessage && ! window.confirm( confirmMessage ) ) {
				return;
			}
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
		var confirmMessage = table.getAttribute( 'data-wpmtm-remove-confirm' );

		Array.prototype.slice.call( body.querySelectorAll( 'tr' ) ).forEach( function ( row ) {
			bindRemove( row, removedInput, confirmMessage );
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

			bindRemove( clone, removedInput, confirmMessage );
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

	// Behavior 8: "Use default" button (see the header comment above).
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '[data-wpmtm-use-default]' ) : null;
		if ( ! btn || btn.disabled ) {
			return;
		}
		e.preventDefault();
		var value = btn.getAttribute( 'data-default' ) || '';
		if ( '' === value ) {
			return;
		}
		var field = document.getElementById( btn.getAttribute( 'data-target' ) || '' );
		if ( field ) {
			field.value = value;
		}
	} );

	// Behavior 6: "Validate with USCF" (see the header comment above).
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
		btn.textContent = i18n.validating;

		var scope     = ( btn.closest ? btn.closest( 'td' ) : null ) || btn.parentNode;
		var container = scope.querySelector( '[data-wpmtm-validate-tds-results]' );
		if ( ! container ) {
			container = document.createElement( 'div' );
			container.setAttribute( 'data-wpmtm-validate-tds-results', '' );
			btn.parentNode.insertBefore( container, btn.nextSibling );
		}
		// Tournament edit page only (WPMTM_USCF_Status::ajax_validate_tds()
		// only sends last_checked_text for context=tournament) - absent on
		// the Settings page, so this stays null there and the block below
		// simply never runs.
		var lastCheckEl = scope.querySelector( '[data-wpmtm-td-check-last]' );

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
				if ( lastCheckEl && json.data.last_checked_text ) {
					lastCheckEl.textContent = json.data.last_checked_text;
				}
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

	// Behavior 7: setup guide panel open/closed persistence (see the header
	// comment above).
	document.addEventListener( 'toggle', function ( e ) {
		var panel = e.target;
		if ( ! panel || ! panel.hasAttribute || ! panel.hasAttribute( 'data-wpmtm-setup-guide-panel' ) ) {
			return;
		}
		var body = 'action=wpmtm_toggle_setup_guide_panel'
			+ '&open=' + ( panel.open ? '1' : '0' )
			+ '&nonce=' + encodeURIComponent( panel.getAttribute( 'data-nonce' ) || '' );
		// window.ajaxurl is defined automatically in wp-admin; on the front
		// end it falls back to wpmtmFrontendAjax.ajaxUrl, localized by
		// WPMTM_Frontend_Public::enqueue_frontend_assets() for exactly this.
		var ajaxUrl = window.ajaxurl || ( window.wpmtmFrontendAjax && window.wpmtmFrontendAjax.ajaxUrl );
		fetch( ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body
		} ).catch( function () {
			// Best-effort persistence; a failed request just means the
			// panel falls back to its default collapsed state next load.
		} );
	}, true ); // capture: 'toggle' does not bubble.

	// Behavior 9: setup guide step click (see the header comment above).
	function showSetupGuideStep( stepEl ) {
		var stepper = stepEl.closest( '.wpmtm-setup-guide-stepper' );
		var panel   = stepEl.closest( '.wpmtm-setup-guide-panel' );
		if ( ! stepper || ! panel ) {
			return;
		}
		var data;
		try {
			data = JSON.parse( stepper.getAttribute( 'data-wpmtm-steps-data' ) || '{}' )[ stepEl.getAttribute( 'data-wpmtm-step-slug' ) ];
		} catch ( e ) {
			return;
		}
		if ( ! data ) {
			return;
		}

		var statusEl = panel.querySelector( '[data-wpmtm-step-status]' );
		var nextEl   = panel.querySelector( '[data-wpmtm-step-next]' );
		var helpEl   = panel.querySelector( '[data-wpmtm-readme]' );
		if ( statusEl ) {
			statusEl.textContent = data.status;
		}
		if ( nextEl ) {
			// data.nextHtml comes from WPMTM_Wizard::format_next(), already
			// fully escaped server-side (see that method's own docblock) -
			// safe as innerHTML, same trust boundary as the page's own
			// initial render of this exact string.
			nextEl.innerHTML = data.nextHtml; // eslint-disable-line no-unsanitized/property
		}
		// Point the contextual-help (info) icon at THIS step's own README
		// section, so a chip click re-targets the popup deep link too.
		if ( helpEl && data.helpUrl ) {
			helpEl.href = data.helpUrl;
		}

		// A closed panel still shows the step chips (they are in
		// <summary>, always visible) but not the body they just updated -
		// open it for them rather than leaving the update invisible. This
		// IS a real toggle (not prevented below), so behavior 7's own
		// 'toggle' listener persists it exactly as a direct <summary>
		// click would.
		if ( ! panel.open ) {
			panel.open = true;
		}
	}

	document.addEventListener( 'click', function ( e ) {
		var stepEl = e.target.closest ? e.target.closest( '[data-wpmtm-step-slug]' ) : null;
		if ( ! stepEl ) {
			return;
		}
		// Stops the native <details> toggle a plain summary-area click
		// would otherwise run (this chip's click target is still inside
		// <summary>) - see showSetupGuideStep()'s own explicit open call
		// above for the one case a click here should still open it.
		e.preventDefault();
		showSetupGuideStep( stepEl );
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Enter' !== e.key && ' ' !== e.key && 'Spacebar' !== e.key ) {
			return;
		}
		var stepEl = e.target && e.target.hasAttribute && e.target.hasAttribute( 'data-wpmtm-step-slug' ) ? e.target : null;
		if ( ! stepEl ) {
			return;
		}
		e.preventDefault();
		showSetupGuideStep( stepEl );
	} );

	document.addEventListener( 'submit', function ( e ) {
		var form = e.target;
		if ( ! form || ! form.hasAttribute || ! form.hasAttribute( 'data-wpmtm-guard' ) ) {
			return;
		}
		if ( e.defaultPrevented ) {
			return; // an earlier listener (e.g. data-wpmtm-confirm above) already cancelled this submit.
		}
		// This exact guard also lives in assets/wpmtm-frontend.js, and since
		// 2026-07-21 BOTH files load together on an event page for a
		// capability holder. Without this line the two copies fought over the
		// same submit: the first set data-submitted, the second saw it already
		// set and cancelled as a double submit, so a TD's "Save round" never
		// posted at all (found in a real browser 2026-07-22). Marking the
		// EVENT keeps genuine double-submit protection intact, since a real
		// second submit is a different event object with no mark on it.
		if ( e.wpmtmGuarded ) {
			return;
		}
		e.wpmtmGuarded = true;
		if ( form.getAttribute( 'data-submitted' ) ) {
			e.preventDefault();
			return;
		}
		form.setAttribute( 'data-submitted', '1' );

		var reenableOnFocus = form.hasAttribute( 'data-wpmtm-reenable-on-focus' );

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
				if ( reenableOnFocus ) {
					button.setAttribute( 'data-wpmtm-original-label', 'INPUT' === button.tagName ? button.value : button.textContent );
				}
				if ( 'INPUT' === button.tagName ) {
					button.value = busyLabel;
				} else {
					button.textContent = busyLabel;
				}
			} );
			if ( reenableOnFocus ) {
				scheduleExportReenable( form );
			}
		}, 0 );
	} );

	/** See behavior 10 in the file docblock above. */
	function scheduleExportReenable( form ) {
		var done = false;
		var fallbackTimer = setTimeout( reenable, 60000 );

		function reenable() {
			if ( done ) {
				return;
			}
			done = true;
			clearTimeout( fallbackTimer );
			window.removeEventListener( 'focus', reenable );
			window.removeEventListener( 'pageshow', reenable );
			form.removeAttribute( 'data-submitted' );
			var buttons = form.querySelectorAll( 'button[type="submit"], input[type="submit"]' );
			Array.prototype.slice.call( buttons ).forEach( function ( button ) {
				button.disabled = false;
				var originalLabel = button.getAttribute( 'data-wpmtm-original-label' );
				if ( ! originalLabel ) {
					return;
				}
				if ( 'INPUT' === button.tagName ) {
					button.value = originalLabel;
				} else {
					button.textContent = originalLabel;
				}
			} );
		}

		window.addEventListener( 'focus', reenable );
		window.addEventListener( 'pageshow', reenable );
	}

	// README popup: the setup guide's "Documentation" link opens the plugin
	// README in a 920x740 window, matching the Standings/Wall chart print
	// windows (assets/wpmtm-frontend.js openPrintWindow()). Falls through to a
	// normal navigation if the popup is blocked, so the link still works.
	document.addEventListener( 'click', function ( e ) {
		var link = e.target.closest ? e.target.closest( 'a[data-wpmtm-readme]' ) : null;
		if ( ! link ) {
			return;
		}
		var win = window.open( link.href, 'wpmtm-readme', 'width=920,height=740,scrollbars=yes,resizable=yes' );
		if ( win ) {
			e.preventDefault();
			win.focus();
		}
	} );
} )();
