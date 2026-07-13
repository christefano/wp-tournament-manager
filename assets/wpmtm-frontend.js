/* Tournament Manager front-end round-entry panel - vanilla JS, no dependencies.
 * Three behaviors:
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
