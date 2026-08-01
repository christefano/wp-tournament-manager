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
 *   3. Print button (docs/SPEC.md, 2026-07-18/2026-07-21, clean print
 *      output): a [data-wpmtm-print] click opens a clean popup window
 *      containing just the relevant table(s), mirroring wp-etr's own
 *      [data-etr-print] Print button (assets/etr-tabs.js's
 *      printRegistrations()) so the theme header/sidebar/footer never reach
 *      the printed page. data-wpmtm-print-scope of "standings"/"wall-chart"
 *      prints wp-etr's whole tab panel; "pairing-sheet" (Round entry's
 *      "Print pairing sheet" button) prints just the clicked button's own
 *      section. No scope attribute at all falls back to plain
 *      window.print() (currently unused, kept as the safe default).
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
 *   6. Round entry section sub-tabs (docs/SPEC.md, 2026-07-18): a
 *      .wpmtm-section-tablist only exists in the markup when a tournament
 *      has 2+ sections (WPMTM_Frontend_TD::render_td_block()); every
 *      section panel is still rendered server-side regardless, so this is
 *      a pure show/hide layer, never a gate - with this script absent or
 *      broken every panel stays visible, same as before the tablist
 *      existed. On load the active section's panel is shown and the rest
 *      get .wpmtm-section-panel--hidden; clicking a tab switches; standard
 *      ARIA tablist keyboard nav (manual activation): Left/Right/Home/End
 *      move roving focus between tabs, Enter/Space activates the focused
 *      tab. The active section is remembered per tournament in
 *      sessionStorage so that Save round's page reload lands the TD back
 *      on the section they were working, not bounced to the first tab;
 *      if sessionStorage throws (unavailable, quota, private mode) it is
 *      caught and the behavior silently defaults to the first tab.
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
			// Rows holding an already-saved board carry a server-rendered
			// confirmation message (see render_board_row()): removing such a
			// row deletes the WHOLE board on save, including the opponent's
			// recorded result, so it must never happen on a stray click.
			var confirmMsg = btn.getAttribute( 'data-wpmtm-remove-confirm' );
			if ( confirmMsg && ! window.confirm( confirmMsg ) ) {
				return;
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

		Array.prototype.slice.call( body.querySelectorAll( 'tr' ) ).forEach( bindRemove );

		var addBtn = document.querySelector( '[data-wpmtm-add-board-for="' + table.id + '"]' );
		if ( ! addBtn || ! tmpl || ! tmpl.content ) {
			return;
		}

		addBtn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			var clone = tmpl.content.firstElementChild.cloneNode( true );

			populatePlayerSelectsIn( clone );

			Array.prototype.slice.call( clone.querySelectorAll( 'select' ) ).forEach( function ( field ) {
				field.selectedIndex = 0;
			} );

			bindRemove( clone );
			body.appendChild( clone );
		} );
	}

	/**
	 * Round-entry White/Black <select> population (page-weight fix): each
	 * board row's select ships from the server with only "-- select --" plus
	 * (if already paired) its own selected option - see
	 * WPMTM_Frontend_TD::render_player_options()'s docblock. The section's
	 * full roster is read once from the matching
	 * <script type="application/json" class="wpmtm-players-data"> data
	 * island (render_players_json()) and appended here, client-side, so the
	 * roster travels over the wire once per section instead of once per
	 * board select.
	 */
	function playersDataFor( sectionId ) {
		var script = document.querySelector(
			'.wpmtm-players-data[data-wpmtm-players-section="' + sectionId + '"]'
		);
		if ( ! script ) {
			return null;
		}
		if ( ! script.wpmtmParsed ) {
			try {
				script.wpmtmParsed = JSON.parse( script.textContent );
			} catch ( e ) {
				script.wpmtmParsed = [];
			}
		}
		return script.wpmtmParsed;
	}

	function populatePlayerSelect( select ) {
		if ( select.getAttribute( 'data-wpmtm-populated' ) ) {
			return;
		}
		var players = playersDataFor( select.getAttribute( 'data-wpmtm-players-section' ) );
		if ( ! players ) {
			return;
		}
		var currentValue = select.value;
		var have = {};
		Array.prototype.slice.call( select.options ).forEach( function ( opt ) {
			have[ opt.value ] = true;
		} );
		players.forEach( function ( p ) {
			var idStr = String( p.id );
			if ( have[ idStr ] ) {
				return;
			}
			var opt = document.createElement( 'option' );
			opt.value = idStr;
			opt.textContent = p.label;
			select.appendChild( opt );
		} );
		select.value = currentValue;
		select.setAttribute( 'data-wpmtm-populated', '1' );
	}

	function populatePlayerSelectsIn( root ) {
		Array.prototype.slice.call( root.querySelectorAll( '.wpmtm-player-select' ) ).forEach( populatePlayerSelect );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		populatePlayerSelectsIn( document );
		Array.prototype.slice.call( document.querySelectorAll( '[data-wpmtm-round-repeater]' ) ).forEach( initRepeater );
	} );

	// Standings/Wall chart clean print (docs/SPEC.md, 2026-07-18): the popup
	// is a standalone document (opened with document.write), so it never
	// sees wpmtm-frontend.css - every rule the printed sheet needs has to
	// live here, same approach as wp-etr's etr-tabs.js PRINT_CSS. Photos
	// print (no rule hides .wpmtm-avatar), matching wp-etr's own choice for
	// its avatar column.
	var WPMTM_PRINT_CSS =
		'body{font-family:system-ui,Arial,sans-serif;margin:24px;color:#111}' +
		'h2,h3,h4{margin:0 0 .3em}' +
		'.wpmtm-final-badge{font-weight:700}' +
		'table{width:100%;border-collapse:collapse;margin-bottom:18px;font-size:.85em}' +
		'th,td{text-align:left;padding:4px 8px;border-bottom:1px solid #ccc}' +
		'.wpmtm-avatar{display:block;width:28px;height:28px;border-radius:50%;object-fit:cover}' +
		'caption{text-align:left;font-weight:600;margin-bottom:4px}' +
		'a{color:inherit;text-decoration:none}' +
		'@media print{.wpmtm-section-standings,.wpmtm-wall-chart-section,.wpmtm-score-group{page-break-inside:avoid}}';

	// 'pairing-sheet' (2026-07-22, print optimization): a single section's
	// pairing aid is a handful of narrow columns (pair/name/color due/
	// opponents/bye), unlike Standings/Wall chart's one-column-per-round
	// layout - forcing the same landscape page onto it left the sheet mostly
	// blank down the right side and wasted paper on a multi-page section.
	// Portrait fits the content and prints fewer pages.
	var WPMTM_PAGE_SIZE = {
		'pairing-sheet': '@page{size:portrait;margin:12mm}',
		// The public Pairings tab's board list (Board/White/Black/Result) is
		// four narrow columns, like the pairing sheet - portrait fits it and
		// wastes no paper down the right side. printScoped('pairings') clones
		// #etr-panel-pairings, the whole tab panel.
		'pairings': '@page{size:portrait;margin:12mm}',
		'standings': '@page{size:landscape;margin:12mm}',
		'wall-chart': '@page{size:landscape;margin:12mm}',
		'': '@page{margin:12mm}'
	};

	// Opens a popup, writes source's markup into it with WPMTM_PRINT_CSS,
	// and prints - the shared second half of both print paths below.
	function openPrintWindow( heading, source, scope ) {
		var clone = source.cloneNode( true );
		Array.prototype.slice
			.call( clone.querySelectorAll( '.wpmtm-toolbar, .no-print' ) )
			.forEach( function ( el ) { el.remove(); } );

		var win = window.open( '', '_blank', 'width=920,height=740' );
		if ( ! win ) {
			return;
		}
		var pageSize = WPMTM_PAGE_SIZE[ scope || '' ] || WPMTM_PAGE_SIZE[''];
		var title = document.title || 'Tournament Manager';
		win.document.write(
			'<!DOCTYPE html><html><head><meta charset="utf-8"><title>' +
			title.replace( /[<>]/g, '' ) +
			'</title><style>' + WPMTM_PRINT_CSS + pageSize + '</style></head><body>' +
			( heading ? heading.outerHTML : '' ) + clone.outerHTML + '</body></html>'
		);
		win.document.close();
		win.focus();
		win.onload = function () { win.print(); };
		setTimeout( function () { try { win.print(); } catch ( e ) {} }, 400 );
	}

	// Clones #etr-panel-{scope} (wp-etr's tab panel wrapper, render_tab_markup())
	// rather than a TM-owned container, since render_standings_only() and
	// render_wall_chart_only() print no wrapper of their own - the wp-etr tab
	// panel is the only element that already bounds exactly one tab's content.
	function printScoped( scope ) {
		var source = document.getElementById( 'etr-panel-' + scope );
		if ( ! source ) {
			window.print();
			return;
		}
		openPrintWindow( null, source, scope );
	}

	// 'pairing-sheet' scope (2026-07-21): unlike 'standings'/'wall-chart',
	// .wpmtm-pairing-aid is not a singleton - a multi-section tournament
	// renders one per section, all in the DOM at once (progressive
	// enhancement, see .wpmtm-section-panel--hidden's docblock), so a plain
	// id/class lookup would always grab the first section's. Scope to the
	// CLICKED button's own section via closest(), same as wp-etr's
	// printRegistrations() scopes to closest('.etr-registrations'). The
	// section name and currently-selected round live outside
	// .wpmtm-pairing-aid (siblings within the same panel), so they are
	// read here and synthesized into a heading rather than cloned.
	function printPairingSheet( btn ) {
		var panel = btn.closest( '.wpmtm-td-section-panel' );
		var aid = panel ? panel.querySelector( '.wpmtm-pairing-aid' ) : null;
		if ( ! aid ) {
			window.print();
			return;
		}
		var sectionName  = panel.querySelector( 'h3' );
		var currentRound = panel.querySelector( '.wpmtm-round-selector strong' );
		var heading = document.createElement( 'h2' );
		heading.textContent = ( sectionName ? sectionName.textContent : '' )
			+ ( currentRound ? ' - Round ' + currentRound.textContent : '' );
		openPrintWindow( heading, aid, 'pairing-sheet' );
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '[data-wpmtm-print]' ) : null;
		if ( ! btn ) {
			return;
		}
		var scope = btn.getAttribute( 'data-wpmtm-print-scope' );
		if ( 'pairing-sheet' === scope ) {
			printPairingSheet( btn );
		} else if ( scope ) {
			printScoped( scope );
		} else {
			window.print();
		}
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
		// This exact guard also lives in assets/wpmtm-admin.js, and since
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

		// Confirm gate (data-wpmtm-save-confirm): the round-entry form asks a
		// TD to confirm before overwriting a round's saved results (owner
		// request, 2026-07-23). A DISTINCT attribute from wpmtm-admin.js's own
		// data-wpmtm-confirm submit handler, which also loads on this page for
		// a capability holder - reusing that attribute would pop the dialog
		// twice. Checked before the form is marked submitted below, so
		// cancelling leaves the form fully usable for a later real submit.
		var saveConfirm = form.getAttribute( 'data-wpmtm-save-confirm' );
		if ( saveConfirm && ! window.confirm( saveConfirm ) ) {
			e.preventDefault();
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

	// -----------------------------------------------------------------
	// Round entry section sub-tabs (behavior 6, see file docblock above).
	// -----------------------------------------------------------------

	var SECTION_TAB_STORAGE_KEY = 'wpmtm_section_tab';

	function readStoredSection( tournamentId ) {
		try {
			var raw = window.sessionStorage.getItem( SECTION_TAB_STORAGE_KEY );
			if ( ! raw ) {
				return null;
			}
			var data = JSON.parse( raw );
			return ( data && data[ tournamentId ] ) ? String( data[ tournamentId ] ) : null;
		} catch ( e ) {
			// sessionStorage unavailable or the stored value is not valid
			// JSON - silently fall back to defaulting to the first tab.
			return null;
		}
	}

	function storeSection( tournamentId, sectionId ) {
		try {
			var raw  = window.sessionStorage.getItem( SECTION_TAB_STORAGE_KEY );
			var data = raw ? JSON.parse( raw ) : {};
			data[ tournamentId ] = sectionId;
			window.sessionStorage.setItem( SECTION_TAB_STORAGE_KEY, JSON.stringify( data ) );
		} catch ( e ) {
			// Same as above: best-effort only, never fatal.
		}
	}

	function sectionSlugFromHash() {
		var hash = window.location.hash ? window.location.hash.slice( 1 ) : '';
		var prefix = 'tab-round-entry-';
		if ( hash.indexOf( prefix ) !== 0 ) {
			return null;
		}
		var slug = hash.slice( prefix.length );
		return slug || null;
	}

	function initSectionTabs( tablist ) {
		var container = tablist.closest ? tablist.closest( '.wpmtm-td-panel' ) : tablist.parentNode;
		if ( ! container ) {
			return;
		}
		var tabs   = Array.prototype.slice.call( tablist.querySelectorAll( '[data-wpmtm-section-tab]' ) );
		var panels = Array.prototype.slice.call( container.querySelectorAll( '[data-wpmtm-section-panel]' ) );
		if ( ! tabs.length || ! panels.length ) {
			return;
		}

		var tournamentId = tablist.getAttribute( 'data-wpmtm-tournament' ) || '';

		function tabFor( sectionId ) {
			var match = null;
			tabs.forEach( function ( tab ) {
				if ( tab.getAttribute( 'data-wpmtm-section-tab' ) === sectionId ) {
					match = tab;
				}
			} );
			return match;
		}

		function activate( sectionId, store ) {
			var matchedTab = tabFor( sectionId );
			if ( ! matchedTab ) {
				return;
			}
			tabs.forEach( function ( tab ) {
				var active = tab === matchedTab;
				tab.setAttribute( 'aria-selected', active ? 'true' : 'false' );
				tab.setAttribute( 'tabindex', active ? '0' : '-1' );
			} );
			panels.forEach( function ( panel ) {
				var active = panel.getAttribute( 'data-wpmtm-section-panel' ) === sectionId;
				panel.classList.toggle( 'wpmtm-section-panel--hidden', ! active );
			} );
			if ( store ) {
				storeSection( tournamentId, sectionId );
			}
		}

		var stored     = readStoredSection( tournamentId );
		var fromHash   = sectionSlugFromHash();
		var initial;
		if ( fromHash && tabFor( fromHash ) ) {
			// A deep link is a more specific signal than "last visited" - it wins
			// over sessionStorage.
			initial = fromHash;
		} else if ( stored && tabFor( stored ) ) {
			initial = stored;
		} else {
			initial = tabs[ 0 ].getAttribute( 'data-wpmtm-section-tab' );
		}
		activate( initial, false );

		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				activate( tab.getAttribute( 'data-wpmtm-section-tab' ), true );
			} );
		} );

		tablist.addEventListener( 'keydown', function ( e ) {
			var current = tabs.indexOf( document.activeElement );
			if ( -1 === current ) {
				return;
			}
			var target = null;
			if ( 'ArrowRight' === e.key ) {
				target = tabs[ ( current + 1 ) % tabs.length ];
			} else if ( 'ArrowLeft' === e.key ) {
				target = tabs[ ( current - 1 + tabs.length ) % tabs.length ];
			} else if ( 'Home' === e.key ) {
				target = tabs[ 0 ];
			} else if ( 'End' === e.key ) {
				target = tabs[ tabs.length - 1 ];
			} else if ( 'Enter' === e.key || ' ' === e.key || 'Spacebar' === e.key ) {
				e.preventDefault();
				activate( document.activeElement.getAttribute( 'data-wpmtm-section-tab' ), true );
				return;
			} else {
				return;
			}
			e.preventDefault();
			tabs.forEach( function ( tab ) {
				tab.setAttribute( 'tabindex', tab === target ? '0' : '-1' );
			} );
			target.focus();
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		Array.prototype.slice.call( document.querySelectorAll( '.wpmtm-section-tablist' ) ).forEach( initSectionTabs );
	} );

	// -----------------------------------------------------------------
	// Page-level FINAL badge placement (2026-07-24).
	// -----------------------------------------------------------------

	// WPMTM_Frontend::render_final_badge() prints the badge via wp-etr's
	// 'etr_before_tabs' hook, which fires well after TEC has already
	// rendered the event H1 (the-events-calendar/src/views/single-event/
	// title.php prints it straight through the_title(), no filter to
	// inject a sibling at that point) - so PHP alone cannot place the
	// badge next to the H1, only somewhere after it in the flow. Moving it
	// here is a pure DOM relocation, not a new render: if this script is
	// absent or this specific element is missing, the badge simply stays
	// where PHP put it (wpmtm-frontend.css keeps that placement readable -
	// see .wpmtm-final-badge-page's docblock).
	document.addEventListener( 'DOMContentLoaded', function () {
		var badge = document.querySelector( '.wpmtm-final-badge-page' );
		var title = document.querySelector( '.tribe-events-single-event-title' );
		if ( badge && title ) {
			title.appendChild( badge );
		}
	} );

	// Hiding the TEC event date/cost header and the prev/next event links on
	// the Standings/Wall chart/Rounds tabs is done in CSS alone
	// (wpmtm-frontend.css), keyed off the `data-etr-active-tab` attribute that
	// wp-etr's own etr-tabs.js already mirrors onto <body> - the same
	// mechanism etr-registrations.css uses to hide the "Get Tickets" form and
	// "Add to calendar" widget on non-Details tabs. No JS is needed here.
} )();
