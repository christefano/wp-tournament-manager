/* Tournament Manager admin screens - vanilla JS, no dependencies.
 * Five small behaviors:
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
