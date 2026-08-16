<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TD round-entry panel scaffolding (section tabs, sub-panel, suggestion
 * links, round selector), extracted verbatim from WPMTM_Frontend_TD
 * (2026-07-29 segmentation). Composed back via a use statement; every method
 * keeps its visibility and $this semantics, so no call site changes. Behavior
 * is identical.
 */
trait WPMTM_Frontend_TD_Panel {
	/**
	 * Sanitized, collision-disambiguated slug per section, used as the
	 * `#tab-round-entry-{slug}` URL-hash suffix for the round-entry section
	 * sub-tabs (docs/SPEC.md, 2026-07-18 hash-based sub-tabs). Two sections
	 * whose names sanitize to the same slug (e.g. "Open" and "OPEN") are
	 * disambiguated by appending the section id to every colliding slug, so
	 * every returned slug is unique within the tournament.
	 *
	 * @param object[] $sections All of the tournament's sections.
	 * @return array section id (int) => slug (string).
	 */
	protected function build_section_slugs( array $sections ) {
		$base_by_id = array();
		$counts     = array();
		foreach ( $sections as $section ) {
			$base = sanitize_title( $section->sec_name );
			if ( '' === $base ) {
				$base = 'section';
			}
			$base_by_id[ (int) $section->id ] = $base;
			$counts[ $base ]                  = isset( $counts[ $base ] ) ? $counts[ $base ] + 1 : 1;
		}

		$slugs = array();
		foreach ( $base_by_id as $section_id => $base ) {
			$slugs[ $section_id ] = $counts[ $base ] > 1 ? $base . '-' . $section_id : $base;
		}
		return $slugs;
	}

	/**
	 * The sub-tab strip shown ABOVE the section panels, only when there are
	 * 2 or more sections (render_td_block() gates the call). Rendered as
	 * anchors (`href="#tab-round-entry-{slug}"`) rather than inert buttons
	 * (docs/SPEC.md, 2026-07-18 hash-based sub-tabs): the human-readable
	 * hash is a real deep-link on its own, and assets/wpmtm-frontend.js
	 * still binds a click handler for JS-driven, no-reload switching plus
	 * the roving-tabindex keyboard nav. Without JavaScript these links are
	 * still followable (the browser jumps to the hash, and every panel
	 * stays fully rendered and visible - see the CSS docblock on
	 * `.wpmtm-section-panel--hidden`), so nothing is gated behind this
	 * markup.
	 *
	 * Deliberately NOT `role="tab"` (nor `role="tabpanel"` on the panel
	 * wrapper below): wp-etr's own assets/etr-tabs.js scans its ENTIRE
	 * `.etr-tabs` root for `[role="tab"]` / `[role="tabpanel"]` with no
	 * scoping to its own top-level tablist, so a nested tablist reusing
	 * those exact roles gets swept into wp-etr's OWN tab bookkeeping - its
	 * `activate()` then sets the native `hidden` attribute on every panel
	 * whose id does not match one of ITS OWN tabs' `aria-controls`, which
	 * is every one of these panels, unconditionally hiding them regardless
	 * of section choice (this was the confirmed root cause of "the Round
	 * entry tab shows nothing" - docs/SPEC.md, 2026-07-18). `aria-selected`,
	 * `aria-controls`, roving `tabindex`, and `role="tablist"` on the
	 * container are kept (wp-etr never queries by that role), so the
	 * interactive/keyboard semantics survive; only the two literal role
	 * strings that collide with wp-etr's own selector are dropped.
	 *
	 * data-wpmtm-tournament carries the tournament id. assets/wpmtm-frontend.js
	 * keys its per-tournament sessionStorage entry on it (SECTION_TAB_STORAGE_KEY),
	 * which is the fallback used when the URL carries no section hash; a hash,
	 * when present, wins over it. Corrected 2026-08-10 (audit item 42): this
	 * said the sessionStorage use had been removed, which was never true.
	 *
	 * @param object[] $sections
	 * @param array    $section_slugs section id => slug, from build_section_slugs().
	 */
	protected function render_section_tablist( $tournament, array $sections, array $section_slugs ) {
		?>
		<div
			class="wpmtm-section-tablist"
			role="tablist"
			aria-label="<?php esc_attr_e( 'Sections', 'wp-tournament-manager' ); ?>"
			data-wpmtm-tournament="<?php echo esc_attr( (int) $tournament->id ); ?>"
		>
			<?php foreach ( $sections as $index => $section ) : ?>
				<?php
				$section_id = (int) $section->id;
				$slug       = $section_slugs[ $section_id ];
				$is_first   = ( 0 === $index );
				?>
				<a
					href="<?php echo esc_url( '#tab-round-entry-' . $slug ); ?>"
					id="wpmtm-section-tab-<?php echo esc_attr( $section_id ); ?>"
					class="wpmtm-section-tab"
					data-wpmtm-section-tab="<?php echo esc_attr( $slug ); ?>"
					aria-controls="wpmtm-section-panel-<?php echo esc_attr( $section_id ); ?>"
					aria-selected="<?php echo $is_first ? 'true' : 'false'; ?>"
					tabindex="<?php echo $is_first ? '0' : '-1'; ?>"
				><?php echo esc_html( $section->sec_name ); ?></a>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * @param string $slug Section slug (build_section_slugs()) for the
	 *                     round-entry hash suffix, or '' for a single-section
	 *                     tournament, which keeps the plain '#tab-round-entry'
	 *                     hash unchanged (docs/SPEC.md, 2026-07-18: single-
	 *                     section tournaments need no hash suffix at all).
	 */
	protected function render_section_td_panel( $tournament, $section, $slug = '', $mode = 'results' ) {
		$tot_rnds            = max( 1, (int) $section->tot_rnds );
		$rounds_with_results = WPMTM_Repository::rounds_with_results( $section->id );
		$round_param         = 'wpmtm_round_' . $section->id;
		$selected_round      = $this->determine_selected_round( $tot_rnds, $rounds_with_results );

		if ( isset( $_GET[ $round_param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only round selector, not a state change; the save form below is the state-changing action and is separately nonced.
			// Clamped to the same ceiling determine_selected_round() itself
			// never exceeds (WPMTM_Round_Selector::max_reachable_round()), so
			// a hand-edited URL cannot open an entry form for a round past
			// the real maximum (docs/SPEC.md, 2026-07-14).
			$selected_round = WPMTM_Round_Selector::clamp_round_override(
				absint( wp_unslash( $_GET[ $round_param ] ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only round selector, see note above.
				$tot_rnds,
				$rounds_with_results
			);
		}

		list( $players, $games, $byes ) = WPMTM_Frontend_Public::instance()->section_data_arrays( $section );
		// Rounds whose every board carries a result: the round-selector marks
		// these with a check so a TD can see at a glance which rounds are
		// fully entered.
		//
		// Audit item 62: this used to be re-derived here from $games alone
		// ("no games in it is never counted complete"), which disagreed with
		// WPMTM_Repository::rounds_fully_scored() - the actual completeness
		// rule used two lines below for the sequential gate, and the same one
		// section_complete()/rated_and_complete() use everywhere else since
		// item 54. That rule deliberately counts a byes-only round as scored
		// the moment it exists (nothing in it needs a result), so a byes-only
		// round showed no check mark here while the gate treated it as
		// finished and let the next round be saved - the selector and the
		// gate disagreeing about the same round. Sharing rounds_fully_scored()
		// removes the third copy of this rule and fixes the disagreement in
		// one move; the repository memoizes it per section per request, so
		// computing it here costs nothing extra now that it is read again
		// below for $blocking_round.
		$rounds_scored = WPMTM_Repository::rounds_fully_scored( $section->id );
		$show_photos   = (bool) $tournament->show_photos;
		$hash          = $this->round_entry_hash( $slug );
		?>
		<div class="wpmtm-td-section-panel">
			<h3><?php echo esc_html( $section->sec_name ); ?></h3>
			<?php $this->render_round_selector( $tot_rnds, $rounds_with_results, $selected_round, $round_param, $hash, $rounds_scored ); ?>

			<?php if ( empty( $players ) ) : ?>
				<p><?php esc_html_e( 'This section has no players yet.', 'wp-tournament-manager' ); ?></p>
			<?php else : ?>
				<?php
				// $suggest_eligible (round has no games/byes yet, >=2 active
				// players - round_ready_for_suggestion()'s own definition)
				// doubles as the state-aware layout signal (2026-07-24, Rounds-
				// tab redesign): an empty round means the TD is making
				// pairings, so the pairing aid opens by default and the entry
				// form (nothing to enter yet) starts collapsed; a round that
				// already has results (or is not eligible for another reason)
				// means the TD is editing, so the entry form opens and the aid
				// tucks away. Native <details> - both blocks stay fully
				// rendered and reachable with no JavaScript, same
				// progressive-enhancement property the section sub-tabs
				// already have (see render_section_tablist()'s docblock).
				$suggest_eligible = $this->round_ready_for_suggestion( $players, $games, $byes, $selected_round );

				// Whether "Suggest pairings" is OFFERED is a different question
				// from whether the round is empty (owner decision 2026-08-14).
				// A TD may re-draw a round they have already paired, so the only
				// thing that withdraws the button is a locked tournament -
				// which in this plugin is also what "marked as complete" means
				// (the FINAL badge and the setup guide's finish step both key
				// off `locked`), and which the save handler already refuses
				// writes for. $suggest_eligible stays the layout signal below:
				// an empty round opens the pairing aid, a played one opens the
				// entry form.
				$suggest_allowed = ! (bool) $tournament->locked;

				// Sequential gating (owner decision 2026-08-14). Rounds run in
				// order, so an earlier round still missing results blocks this
				// one. Pairing ahead is a separate question: a Round Robin or
				// Quad schedule is fixed from pairing numbers before round 1, so
				// those may be paired arbitrarily far ahead, while a Swiss draw
				// comes from the standings and cannot. handle_save_round()
				// enforces both against the database; everything rendered here
				// is the cue, not the gate. $rounds_scored is the same value
				// already fetched above for the round selector's check marks
				// (audit item 62) - the repository memoizes it, but reusing
				// the variable also keeps the two questions visibly answered
				// from the one shared source.
				$blocking_round  = WPMTM_Round_Selector::first_unscored_before( $selected_round, $rounds_scored );
				$can_pair_round  = WPMTM_Round_Selector::can_pair_round( $section->trn_type, $selected_round, $rounds_scored );
				$suggest_allowed = $suggest_allowed && $can_pair_round;
				?>
				<?php if ( $blocking_round > 0 ) : ?>
					<div class="wpmtm-td-note-banner wpmtm-round-blocked">
						<p>
							<?php
							echo esc_html(
								$can_pair_round
									? sprintf(
										/* translators: 1: selected round, 2: the earlier unfinished round */
										__( 'Round %1$d can be paired now, but its results cannot be entered until round %2$d has a result on every board.', 'wp-tournament-manager' ),
										(int) $selected_round,
										(int) $blocking_round
									)
									: sprintf(
										/* translators: 1: selected round, 2: the earlier unfinished round */
										__( 'Round %1$d is not ready yet. A Swiss round is paired from the standings, so round %2$d needs a result on every board first.', 'wp-tournament-manager' ),
										(int) $selected_round,
										(int) $blocking_round
									)
							);
							?>
						</p>
					</div>
				<?php endif; ?>
				<?php if ( 'pair' === $mode ) : ?>
					<details class="wpmtm-pairing-aid-details" open>
						<summary><?php esc_html_e( 'Pairing aid', 'wp-tournament-manager' ); ?></summary>
						<?php
						// Change 3: same Print toolbar/button as the Standings and
						// Wall chart tabs (WPMTM_Frontend_Public::render_print_toolbar()),
						// with a label specific to this use - printing the pairing
						// aid below (plus the byes table further down) for the
						// section's currently selected round, so a TD can hand a
						// physical pairing sheet to players without re-typing it.
						// 'pairing-sheet' scope (2026-07-21): a clean popup of just
						// this section's .wpmtm-pairing-aid, same as Standings/Wall
						// chart - see render_print_toolbar()'s docblock.
						echo '<div class="wpmtm-pairing-actions no-print">';
						WPMTM_Frontend_Public::instance()->render_print_toolbar( __( 'Print pairing sheet', 'wp-tournament-manager' ), 'pairing-sheet' );
						$this->render_suggest_link( $section, $suggest_allowed, $hash );
						echo '</div>';
						// A locked tournament refuses every write, and a Swiss round
						// whose predecessor is unfinished cannot be paired at all.
						// Either withdraws the Save pairings button. Round Robin and
						// Quad stay pairable regardless of the gap, which is the
						// whole point of the type-aware rule.
						$pairing_write_blocked = (bool) $tournament->locked || ! $can_pair_round;
						$this->render_pairing_aid( $players, $games, $byes, $selected_round, $section->trn_type, $show_photos, isset( $section->cycles ) ? $section->cycles : 1, (int) $section->id, $pairing_write_blocked, (string) $section->sec_name );
						?>
					</details>
				<?php endif; ?>

				<?php
				// A suggestion is a pairing act, so it is only ever built in
				// pair mode. Results mode showing prefilled-but-unsaved boards
				// is exactly the confusion this split exists to remove.
				$suggestion = ( 'pair' === $mode )
					? $this->maybe_build_suggestion( $section, $players, $games, $byes, $selected_round, $suggest_allowed )
					: null;
				?>
				<details id="wpmtm-round-entry-<?php echo esc_attr( $section->id ); ?>" class="wpmtm-round-entry-details" data-wpmtm-round-entry open>
					<summary>
						<?php
						echo esc_html(
							'pair' === $mode
								? sprintf( /* translators: %d: round number */ __( 'Pairings for round %d', 'wp-tournament-manager' ), (int) $selected_round )
								: sprintf( /* translators: %d: round number */ __( 'Results for round %d', 'wp-tournament-manager' ), (int) $selected_round )
						);
						?>
					</summary>
					<?php
					if ( 'pair' === $mode ) {
						$this->render_suggest_ineligible_notice( $section, $suggest_allowed, (bool) $tournament->locked, $blocking_round, $suggestion );
					}
					// The two modes ask different questions of the gate. Results
					// wait on every earlier round being scored; pairing only
					// waits for Swiss, since a Round Robin or Quad schedule is
					// fixed before round 1 and can be drawn at any time.
					$write_blocked = ( 'pair' === $mode ) ? ! $can_pair_round : ( $blocking_round > 0 );
					$this->render_round_entry_form( $tournament, $section, $players, $games, $byes, $selected_round, $round_param, $suggestion, $write_blocked, $mode );
					?>
				</details>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Which half of the Rounds tab to show: 'pair' or 'results'.
	 *
	 * Pairing a round and recording what happened in it are two different jobs
	 * done at two different times, and putting both on screen at once is what
	 * made the tab hard to use: the pairing controls and the result dropdowns
	 * belonged to one shared form, so saving either could write the other. The
	 * mode splits them into separate forms, which is what makes "record who
	 * plays whom without touching results" structurally true rather than a
	 * promise the markup quietly broke.
	 *
	 * Tab-level rather than per-section on purpose. A TD pairing an event with
	 * several sections wants to pair all of them in one pass, so the mode
	 * follows the job, not the section.
	 *
	 * @param object $tournament
	 * @return string 'pair' or 'results'
	 */
	protected function rounds_mode( $tournament ) {
		$requested = isset( $_GET['wpmtm_mode'] ) ? sanitize_key( wp_unslash( $_GET['wpmtm_mode'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view selector; every write on this page is separately nonced.
		if ( 'pair' === $requested || 'results' === $requested ) {
			return $requested;
		}

		// No explicit choice: open on the job the TD is most likely there to
		// do. An event with nothing recorded anywhere has not been paired yet,
		// so start in Pair. Once anything exists, Results is the commoner
		// errand, and Pair is one click away.
		return WPMTM_Repository::tournament_has_games( (int) $tournament->id ) ? 'results' : 'pair';
	}

	/**
	 * The two-way switch between pairing and results entry, as an HTML string
	 * rather than direct output (owner decision, 2026-08-14: fold it into the
	 * shared command row instead of stacking a separate band above it).
	 * render_td_command_row() lives on WPMTM_Frontend_Public, a class with no
	 * knowledge of rounds/pairing, so the caller (WPMTM_Frontend_TD) builds
	 * this markup itself and hands it over as its $leading_html argument
	 * rather than that method reaching back into this trait.
	 *
	 * Deliberately its own visually distinct segmented control - a filled pill
	 * for the active mode, an outlined one for the other - rather than styled
	 * like the row's .wpmtm-btn actions: those buttons DO something (Export,
	 * Lock, Print); this pair is a STATE, which side of the tab a TD is on.
	 * Blurring the two would make the row stop reading as "things I can do".
	 *
	 * @param string $mode Current mode ('pair' or 'results').
	 * @return string Safe HTML.
	 */
	protected function rounds_mode_toggle_html( $mode ) {
		$modes = array(
			'pair'    => __( 'Pair rounds', 'wp-tournament-manager' ),
			'results' => __( 'Enter results', 'wp-tournament-manager' ),
		);
		ob_start();
		?>
		<div class="wpmtm-rounds-mode" role="group" aria-label="<?php esc_attr_e( 'Pairing or results entry', 'wp-tournament-manager' ); ?>">
			<?php foreach ( $modes as $value => $label ) : ?>
				<?php if ( $value === $mode ) : ?>
					<span class="wpmtm-rounds-mode-tab" aria-current="true"><?php echo esc_html( $label ); ?></span>
				<?php else : ?>
					<a class="wpmtm-rounds-mode-tab" href="<?php echo esc_url( add_query_arg( 'wpmtm_mode', $value ) . '#tab-round-entry' ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * The Round entry deep-link hash to append after a GET/redirect URL:
	 * plain '#tab-round-entry' for a single-section tournament (unchanged
	 * behavior - see render_section_td_panel()'s docblock), or
	 * '#tab-round-entry-{slug}' for a multi-section one, so a round switch,
	 * a Suggest pairings click, or a Save round redirect all land back on
	 * BOTH the wp-etr Round entry tab AND the section the TD was already
	 * working (docs/SPEC.md, 2026-07-18).
	 *
	 * @param string $slug '' for single-section, else build_section_slugs()'s value.
	 * @return string
	 */
	protected function round_entry_hash( $slug ) {
		return '' === $slug ? '#tab-round-entry' : '#tab-round-entry-' . $slug;
	}

	/**
	 * Whether the "Suggest pairings" link should be offered for a section's
	 * selected round: the round has no games and no byes recorded yet, and
	 * at least two players are active for it. Deliberately mirrors the same
	 * "nothing entered yet" check the round-entry form already relies on
	 * (empty $round_games / $byes_prefill), so the link only ever appears
	 * when a suggestion is actually something to offer instead of noise.
	 */
	protected function round_ready_for_suggestion( array $players, array $games, array $byes, $round ) {
		$round = (int) $round;
		foreach ( $games as $game ) {
			if ( (int) $game['round'] === $round ) {
				return false;
			}
		}
		foreach ( $byes as $bye ) {
			if ( (int) $bye['round'] === $round ) {
				return false;
			}
		}
		return count( $this->players_active_for_round( $players, $round ) ) >= 2;
	}

	/**
	 * Plain GET link that adds wpmtm_suggest_{section_id}=1 to the current
	 * URL, preserving the existing per-section round param the same way
	 * render_round_selector()'s links do. Read-only: it only ever changes
	 * what the round-entry form below prefills, never anything in the
	 * database - the nonce-protected Save round POST is unchanged and is
	 * still the only thing that writes.
	 *
	 * The $hash fragment (plain string concat after add_query_arg(), same
	 * as render_round_selector() below and build_return_url()) keeps the
	 * wp-etr tab UI on the Round entry tab - and, for a multi-section
	 * tournament, the same section sub-tab - across this GET reload;
	 * without it the reload would land back on whichever tab wp-etr
	 * defaults to (Details/Standings), throwing the TD out of the panel
	 * they were just working in. Harmless when wp-etr is inactive or has no
	 * matching tab id: an unmatched hash is simply ignored by the browser.
	 * ('#tab-{id}' is wp-etr's canonical deep-link hash as of its 5.2.4
	 * tab-hiding update; '#tab-round-entry-{slug}' is this plugin's own
	 * extension of it, see round_entry_hash()'s docblock.)
	 *
	 * Self-disabling button (docs/SPEC.md, 2026-07-14): still a plain
	 * anchor with the same href/GET navigation as before (no-JS fallback
	 * keeps working), styled as a button with the existing .wpmtm-btn look
	 * plus its own .wpmtm-suggest-btn hook. assets/wpmtm-frontend.js binds
	 * a click handler that disables it, sets aria-disabled, and swaps its
	 * label to the localized "Preparing suggestions..." string (carried
	 * here as data-busy-label - the same data-attribute pattern
	 * render_round_entry_form() already uses for its Save round button's
	 * data-wpmtm-busy-label) before letting the click/navigation proceed.
	 * The suggestion itself is built server-side on the next page load, so
	 * that busy state simply persists until the reload lands.
	 *
	 * @param string $hash round_entry_hash()'s return value for this section.
	 */
	protected function render_suggest_link( $section, $allowed, $hash = '#tab-round-entry' ) {
		if ( ! $allowed ) {
			return;
		}
		$suggest_param = 'wpmtm_suggest_' . $section->id;

		// The trigger value is a fresh random integer per render, not a
		// constant 1. Field report: "Suggest pairings only works the first
		// time it is clicked for a round." Cause: add_query_arg() builds this
		// href from the CURRENT request URI, so once the page is already at
		// ...&wpmtm_suggest_{id}=1#tab-round-entry, re-rendering the link
		// produced a href byte-identical to window.location - and a click on a
		// link whose URL matches the current one including the fragment is a
		// same-document fragment navigation: the browser scrolls and never
		// reloads. No request, no new suggestion, and the JS busy state that
		// was set on click had nothing to clear it, so the button sat frozen
		// on "Preparing suggestions...". A per-render value keeps every href
		// distinct from the URL currently in the address bar, so each click is
		// a real navigation. suggest_requested() reads this through absint(),
		// which accepts any positive integer, so only the value changed here.
		$suggest_token = wp_rand( 1, PHP_INT_MAX );
		?>
		<p class="wpmtm-suggest-pairings">
			<a
				href="<?php echo esc_url( add_query_arg( $suggest_param, $suggest_token ) . $hash ); ?>"
				class="wpmtm-btn wpmtm-suggest-btn"
				data-busy-label="<?php echo esc_attr__( 'Preparing suggestions...', 'wp-tournament-manager' ); ?>"
			><?php esc_html_e( 'Suggest pairings', 'wp-tournament-manager' ); ?></a>
		</p>
		<?php
	}

	/**
	 * Runs WPMTM_Pairing_Suggest::suggest() for the selected round when the
	 * TD followed the "Suggest pairings" link, or returns null when the link
	 * was not followed or the tournament is locked.
	 *
	 * Re-suggesting a round that ALREADY has pairings is supported on purpose
	 * (owner decision 2026-08-14): now that "Save pairings" persists boards
	 * before their results exist, a TD who saves a round's pairings and then
	 * wants a different draw must be able to ask for one. That makes the
	 * round's own games and byes a correctness hazard rather than a reason to
	 * refuse: WPMTM_Pairing_Suggest::suggest() tallies every game it is handed,
	 * so leaving this round's own boards in would make each already-paired
	 * player look like they had played that opponent, poisoning both the
	 * color balance and the "already met" avoidance for the very round being
	 * paired. They are filtered out here, which is a no-op for the ordinary
	 * empty-round case and keeps the suggestion identical to what it always
	 * was there.
	 */
	protected function maybe_build_suggestion( $section, array $players, array $games, array $byes, $selected_round, $allowed ) {
		if ( ! $allowed ) {
			return null;
		}
		if ( ! $this->suggest_requested( $section ) ) {
			return null;
		}
		$cycles         = isset( $section->cycles ) ? $section->cycles : 1;
		$selected_round = (int) $selected_round;

		$prior_games = array();
		foreach ( $games as $game ) {
			if ( (int) $game['round'] !== $selected_round ) {
				$prior_games[] = $game;
			}
		}
		$prior_byes = array();
		foreach ( $byes as $bye ) {
			if ( (int) $bye['round'] !== $selected_round ) {
				$prior_byes[] = $bye;
			}
		}

		return WPMTM_Pairing_Suggest::suggest( $players, $prior_games, $prior_byes, $selected_round, $section->trn_type, $section->sec_name, $cycles );
	}

	/** Whether the current GET carries this section's wpmtm_suggest_{id}=1 trigger. */
	protected function suggest_requested( $section ) {
		$suggest_param = 'wpmtm_suggest_' . $section->id;
		return isset( $_GET[ $suggest_param ] ) && absint( wp_unslash( $_GET[ $suggest_param ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only GET trigger for a form prefill, not a state change; capability already gated by render_td_block(), and the round-entry form's own nonced Save round POST is the only thing that ever writes.
	}

	/**
	 * Field report: "Suggest pairings does not seem to work." Server-side it
	 * does - the failure was following the link and getting a silent null
	 * back, which looks identical to "nothing was clicked". This renders a
	 * visible notice whenever the GET trigger is present but no suggestion
	 * came back.
	 *
	 * Since 2026-08-14 a round having results is no longer a reason to
	 * refuse (see maybe_build_suggestion()), so the remaining causes are a
	 * locked tournament, the sequential Swiss gate (audit item 61 - added
	 * the same day as the two causes above but never given its own branch
	 * here, so it fell into the "locked" message and told a TD their
	 * tournament was locked when it was not), or too few active players to
	 * pair.
	 *
	 * @param object     $section
	 * @param bool       $allowed        Whether suggesting was offered at all
	 *                                   (locked OR gap-blocked OR either).
	 * @param bool       $locked         Whether the tournament itself is
	 *                                   locked, so this method can tell that
	 *                                   cause apart from the sequential gate
	 *                                   below - both fold into $allowed the
	 *                                   same way at the call site.
	 * @param int        $blocking_round The earlier unscored round blocking
	 *                                   this one, or 0 when nothing does
	 *                                   (WPMTM_Round_Selector::first_unscored_before()).
	 * @param array|null $suggestion     maybe_build_suggestion()'s return value.
	 */
	protected function render_suggest_ineligible_notice( $section, $allowed, $locked, $blocking_round, $suggestion = null ) {
		if ( ! $this->suggest_requested( $section ) ) {
			return;
		}
		if ( $allowed && $suggestion && ! empty( $suggestion['boards'] ) ) {
			return;
		}
		if ( $locked ) {
			$message = __( 'No suggestions: this tournament is locked. Unlock it to change pairings.', 'wp-tournament-manager' );
		} elseif ( $blocking_round > 0 ) {
			$message = sprintf(
				/* translators: %d: the earlier unfinished round */
				__( 'No suggestions: round %d needs a result on every board first. A Swiss round is paired from the standings.', 'wp-tournament-manager' ),
				(int) $blocking_round
			);
		} else {
			$message = __( 'No suggestions: this section does not have enough active players to pair for this round.', 'wp-tournament-manager' );
		}
		?>
		<p class="description wpmtm-suggest-ineligible">
			<?php echo esc_html( $message ); ?>
		</p>
		<?php
	}

	/**
	 * Default selected round: lowest round (1..tot_rnds) with no results
	 * yet, else the final real round (never one past it - see
	 * WPMTM_Round_Selector's own docblock for the phantom-round bug this
	 * used to have and docs/SPEC.md, 2026-07-14). Delegates to the pure
	 * WPMTM_Round_Selector class so this arithmetic is unit-tested directly
	 * by tests/run-tests.php without needing this WP-coupled class's
	 * constructor/repository dependencies.
	 */
	protected function determine_selected_round( $tot_rnds, array $rounds_with_results ) {
		return WPMTM_Round_Selector::determine_selected_round( $tot_rnds, $rounds_with_results );
	}

	/**
	 * Each round link's $hash fragment (plain string concat after
	 * add_query_arg()) keeps the wp-etr tab UI on the Round entry tab - and
	 * the same section sub-tab, for a multi-section tournament - across the
	 * GET reload a round switch causes; see render_suggest_link()'s
	 * docblock above for the full rationale.
	 *
	 * Pill-tab band (2026-07-24, matching .wpmtm-section-tablist-band's
	 * look): the old version was a plain "Round: 1 2 3 4" text line where
	 * the whole line was bolded by ::first-line and the current round only
	 * got underlined on top of that - visually the entire label read as
	 * emphasized, so the one number that actually mattered barely stood
	 * out (owner report, 2026-07-24: "difficult to identify"). Reuses
	 * .wpmtm-section-tab's own box styling and its [aria-selected="true"]
	 * treatment (solid white pill, blue bottom border) so a TD reads round
	 * and section switching as the same kind of control instead of two
	 * different ones on the same page.
	 *
	 * The current round is a plain <span>, not a link - same as the old
	 * <strong> version - since it is already the page being viewed; only
	 * the OTHER rounds are real navigation.
	 *
	 * @param string $hash round_entry_hash()'s return value for this section.
	 */
	protected function render_round_selector( $tot_rnds, array $rounds_with_results, $selected_round, $round_param, $hash = '#tab-round-entry', array $complete_rounds = array() ) {
		$display_rounds = WPMTM_Round_Selector::display_rounds( $tot_rnds, $rounds_with_results, $selected_round );
		?>
		<div class="wpmtm-round-selector-band">
			<span class="wpmtm-round-selector-label"><?php esc_html_e( 'Round:', 'wp-tournament-manager' ); ?></span>
			<div class="wpmtm-round-selector" role="group" aria-label="<?php esc_attr_e( 'Rounds', 'wp-tournament-manager' ); ?>">
				<?php foreach ( $display_rounds as $r ) : ?>
					<?php $is_complete = in_array( (int) $r, $complete_rounds, true ); ?>
					<?php if ( (int) $r === (int) $selected_round ) : ?>
						<span class="wpmtm-round-tab<?php echo $is_complete ? ' wpmtm-round-tab--complete' : ''; ?>" aria-selected="true"><span class="wpmtm-round-tab-num"><?php echo esc_html( $r ); ?></span><?php if ( $is_complete ) : ?><span class="wpmtm-round-tab-check" aria-hidden="true">&#9745;&#65039;</span><span class="screen-reader-text"><?php esc_html_e( '(completed)', 'wp-tournament-manager' ); ?></span><?php endif; ?></span>
					<?php else : ?>
						<a class="wpmtm-round-tab<?php echo $is_complete ? ' wpmtm-round-tab--complete' : ''; ?>" aria-selected="false" href="<?php echo esc_url( add_query_arg( $round_param, $r ) . $hash ); ?>"><span class="wpmtm-round-tab-num"><?php echo esc_html( $r ); ?></span><?php if ( $is_complete ) : ?><span class="wpmtm-round-tab-check" aria-hidden="true">&#9745;&#65039;</span><span class="screen-reader-text"><?php esc_html_e( '(completed)', 'wp-tournament-manager' ); ?></span><?php endif; ?></a>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}
}
