<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The TD's round-entry panel (WPMTM_CAPABILITY only): pairing aid, round
 * selector, the round-entry form, and the save handler behind it. Split out
 * of what used to be a single WPMTM_Frontend god class, the same way the
 * admin side is split into WPMTM_Admin / WPMTM_Admin_Import /
 * WPMTM_Admin_Export.
 *
 * Registers its own admin-post hooks in its own constructor (the same
 * pattern WPMTM_Admin / WPMTM_Admin_Import / WPMTM_Admin_Export use), so it
 * only ever needs instantiating once - WPMTM_Frontend::instance() does that
 * as part of its own construction, the same way WPMTM_Admin's constructor
 * does not need to know about WPMTM_Admin_Import's hooks; they are wired up
 * as soon as that class's singleton is created.
 *
 * Uses WPMTM_Admin_Shared for set_notice() (the round-save outcome, shown
 * on the redirect back to the event page). require_capability() from that
 * trait is not used here - it wp_die()s on failure, which is correct for
 * an admin screen but wrong for render_td_block(), which is only ever
 * called after WPMTM_Frontend::build_block() has already checked
 * current_user_can( WPMTM_CAPABILITY ); handle_save_round() below re-checks
 * that capability itself before writing anything, since a render-time
 * check is not a substitute for authorizing the POST that actually saves.
 */
class WPMTM_Frontend_TD {

	use WPMTM_Admin_Shared;

	private static $instance = null;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_post_wpmtm_save_round', array( $this, 'handle_save_round' ) );
		add_action( 'admin_post_nopriv_wpmtm_save_round', array( $this, 'handle_save_round_nopriv' ) );
	}

	// -----------------------------------------------------------------
	// TD panel (WPMTM_CAPABILITY only).
	// -----------------------------------------------------------------

	public function render_td_block( $tournament ) {
		$sections = WPMTM_Repository::get_sections( $tournament->id );
		if ( empty( $sections ) ) {
			return;
		}

		// assets/wpmtm-frontend.css/.js are shared with the public standings
		// + wall chart, so this is WPMTM_Frontend_Public's own method now
		// (see that method's docblock) - WPMTM_Frontend::build_block() /
		// filter_etr_event_tabs() also call it unconditionally (every
		// visitor, not just a WPMTM_CAPABILITY user), and this call here is
		// a harmless duplicate on the paths where both run in one request.
		WPMTM_Frontend_Public::instance()->enqueue_frontend_assets();

		// Multi-section sub-tabs (docs/SPEC.md, 2026-07-18): a TD always
		// works one section at a time, so 2+ sections get a client-side
		// tab strip over the same server-rendered panels instead of every
		// section's full stack piling up vertically on a phone. A single
		// section is the persona's normal event and must render exactly as
		// before - no tablist, no wrapper, no extra attributes - so the
		// tablist and the per-panel wrapper below are both gated on this.
		$multi_section = count( $sections ) > 1;
		$section_slugs = $multi_section ? $this->build_section_slugs( $sections ) : array();
		?>
		<div class="wpmtm-td-panel">
			<?php
			// 2026-07-21: Round entry previously had no TD command row at
			// all (a pre-existing gap - Standings/Wall chart both had it).
			// Same instance-sharing pattern this file already uses for
			// enqueue_frontend_assets()/section_data_arrays() above.
			WPMTM_Frontend_Public::instance()->render_td_command_row( $tournament );
			?>
			<?php if ( (bool) $tournament->locked ) : ?>
				<div class="wpmtm-locked-banner">
					<p><strong><?php esc_html_e( 'This tournament is complete and locked.', 'wp-tournament-manager' ); ?></strong></p>
					<p><?php esc_html_e( 'Unlock the tournament from its admin page to make changes.', 'wp-tournament-manager' ); ?></p>
					<?php if ( (bool) $tournament->rated ) : ?>
						<?php
						// 2026-07-21: re-download link for a TD who already
						// submitted this export and wants the same zip again
						// (e.g. they lost the file) - the exact same
						// admin_post_wpmtm_export_uscf handler and nonce the
						// Export box's own button uses
						// (WPMTM_Admin_Export::render_export_box()), just a
						// second entry point to it. No help text, no
						// readiness/error checks replicated here (owner
						// decision, 2026-07-21) - a locked tournament's data
						// is frozen, so if it exported once it still will.
						?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpmtm-locked-banner-export">
							<?php wp_nonce_field( 'wpmtm_export_uscf_' . $tournament->id, 'wpmtm_export_uscf_nonce' ); ?>
							<input type="hidden" name="action" value="wpmtm_export_uscf">
							<input type="hidden" name="tournament_id" value="<?php echo esc_attr( $tournament->id ); ?>">
							<button type="submit" class="wpmtm-locked-banner-export-btn"><?php esc_html_e( 'Download USCF export', 'wp-tournament-manager' ); ?></button>
						</form>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<?php if ( $multi_section ) : ?>
				<div class="wpmtm-section-tablist-band">
					<span class="wpmtm-section-tablist-label"><?php esc_html_e( 'Section:', 'wp-tournament-manager' ); ?></span>
					<?php $this->render_section_tablist( $tournament, $sections, $section_slugs ); ?>
				</div>
			<?php endif; ?>
			<?php foreach ( $sections as $index => $section ) : ?>
				<?php if ( $multi_section ) : ?>
					<?php
					$section_id = (int) $section->id;
					$slug       = $section_slugs[ $section_id ];
					?>
					<div
						id="wpmtm-section-panel-<?php echo esc_attr( $section_id ); ?>"
						class="wpmtm-section-panel"
						data-wpmtm-section-panel="<?php echo esc_attr( $slug ); ?>"
						aria-labelledby="wpmtm-section-tab-<?php echo esc_attr( $section_id ); ?>"
					>
						<?php $this->render_section_td_panel( $tournament, $section, $slug ); ?>
					</div>
				<?php else : ?>
					<?php $this->render_section_td_panel( $tournament, $section ); ?>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
		<?php
	}

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
	 * data-wpmtm-tournament carries the tournament id; assets/wpmtm-
	 * frontend.js no longer uses it for sessionStorage (removed in favor of
	 * the URL hash), but it stays as a stable per-tournament scope hook.
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
	protected function render_section_td_panel( $tournament, $section, $slug = '' ) {
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
		$show_photos                    = (bool) $tournament->show_photos;
		$hash                           = $this->round_entry_hash( $slug );
		?>
		<div class="wpmtm-td-section-panel">
			<h3><?php echo esc_html( $section->sec_name ); ?></h3>
			<?php $this->render_round_selector( $tot_rnds, $rounds_with_results, $selected_round, $round_param, $hash ); ?>

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
				?>
				<details class="wpmtm-pairing-aid-details" <?php echo $suggest_eligible ? 'open' : ''; ?>>
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
					WPMTM_Frontend_Public::instance()->render_print_toolbar( __( 'Print pairing sheet', 'wp-tournament-manager' ), 'pairing-sheet' );
					$this->render_pairing_aid( $players, $games, $byes, $selected_round, $section->trn_type, $show_photos );
					$this->render_suggest_link( $section, $suggest_eligible, $hash );
					?>
				</details>

				<?php
				$suggestion = $this->maybe_build_suggestion( $section, $players, $games, $byes, $selected_round, $suggest_eligible );
				?>
				<?php
				// Open whenever a suggestion was just built too, not only when
				// the round is otherwise ineligible (bug found 2026-07-24): a
				// fresh suggestion never writes to the database, so
				// $suggest_eligible (computed above from DB games/byes alone)
				// stays true, and this <details> would otherwise stay
				// collapsed - hiding the very success notice and prefilled
				// boards the TD just asked for behind a closed disclosure they
				// had no reason to think to open.
				$entry_form_open = ( ! $suggest_eligible ) || $suggestion;
				?>
				<details class="wpmtm-round-entry-details" <?php echo $entry_form_open ? 'open' : ''; ?>>
					<summary><?php esc_html_e( 'Entry form', 'wp-tournament-manager' ); ?></summary>
					<?php
					$this->render_suggest_ineligible_notice( $section, $suggest_eligible );
					$this->render_round_entry_form( $tournament, $section, $players, $games, $byes, $selected_round, $round_param, $suggestion );
					?>
				</details>
			<?php endif; ?>
		</div>
		<?php
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
	 */
	/**
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
	protected function render_suggest_link( $section, $eligible, $hash = '#tab-round-entry' ) {
		if ( ! $eligible ) {
			return;
		}
		$suggest_param = 'wpmtm_suggest_' . $section->id;
		?>
		<p class="wpmtm-suggest-pairings">
			<a
				href="<?php echo esc_url( add_query_arg( $suggest_param, 1 ) . $hash ); ?>"
				class="wpmtm-btn wpmtm-suggest-btn"
				data-busy-label="<?php echo esc_attr__( 'Preparing suggestions...', 'wp-tournament-manager' ); ?>"
			><?php esc_html_e( 'Suggest pairings', 'wp-tournament-manager' ); ?></a>
		</p>
		<?php
	}

	/**
	 * Runs WPMTM_Pairing_Suggest::suggest() for the selected round when the
	 * TD followed the "Suggest pairings" link, or returns null otherwise
	 * (including when the round stopped being eligible between rendering
	 * the link and this GET, e.g. another tab already saved results) so the
	 * round-entry form falls back to its normal blank/prefilled-from-DB
	 * rendering instead of trusting a stale suggestion.
	 */
	protected function maybe_build_suggestion( $section, array $players, array $games, array $byes, $selected_round, $eligible ) {
		if ( ! $eligible ) {
			return null;
		}
		if ( ! $this->suggest_requested( $section ) ) {
			return null;
		}
		return WPMTM_Pairing_Suggest::suggest( $players, $games, $byes, $selected_round, $section->trn_type, $section->sec_name );
	}

	/** Whether the current GET carries this section's wpmtm_suggest_{id}=1 trigger. */
	protected function suggest_requested( $section ) {
		$suggest_param = 'wpmtm_suggest_' . $section->id;
		return isset( $_GET[ $suggest_param ] ) && absint( wp_unslash( $_GET[ $suggest_param ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only GET trigger for a form prefill, not a state change; capability already gated by render_td_block(), and the round-entry form's own nonced Save round POST is the only thing that ever writes.
	}

	/**
	 * Field report: "Suggest pairings does not seem to work." Server-side it
	 * does - the actual failure is following the link for a round that
	 * already has results (or too few active players), where
	 * maybe_build_suggestion() returns null and the form silently falls
	 * back to blank, with no sign anything was even attempted. This renders
	 * a visible notice above the entry form whenever the GET trigger is
	 * present but the round was not eligible, so that silent null no longer
	 * looks identical to "nothing was clicked".
	 */
	protected function render_suggest_ineligible_notice( $section, $eligible ) {
		if ( $eligible || ! $this->suggest_requested( $section ) ) {
			return;
		}
		?>
		<p class="description wpmtm-suggest-ineligible">
			<?php esc_html_e( 'No suggestions: this round already has results (or too few active players). Pick an empty round to get suggestions.', 'wp-tournament-manager' ); ?>
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
	 * @param string $hash round_entry_hash()'s return value for this section.
	 */
	/**
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
	 */
	protected function render_round_selector( $tot_rnds, array $rounds_with_results, $selected_round, $round_param, $hash = '#tab-round-entry' ) {
		$display_rounds = WPMTM_Round_Selector::display_rounds( $tot_rnds, $rounds_with_results, $selected_round );
		?>
		<div class="wpmtm-round-selector-band">
			<span class="wpmtm-round-selector-label"><?php esc_html_e( 'Round', 'wp-tournament-manager' ); ?></span>
			<div class="wpmtm-round-selector" role="group" aria-label="<?php esc_attr_e( 'Rounds', 'wp-tournament-manager' ); ?>">
				<?php foreach ( $display_rounds as $r ) : ?>
					<?php if ( (int) $r === (int) $selected_round ) : ?>
						<span class="wpmtm-round-tab" aria-selected="true"><?php echo esc_html( $r ); ?></span>
					<?php else : ?>
						<a class="wpmtm-round-tab" aria-selected="false" href="<?php echo esc_url( add_query_arg( $round_param, $r ) . $hash ); ?>"><?php echo esc_html( $r ); ?></a>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * @param bool $show_photos Tournament's show_photos flag. WPMTM_Pairing_Aid
	 *                          is a pure class and its score-group player
	 *                          rows do not carry photo_id (see
	 *                          WPMTM_Frontend_Public::map_players()'s
	 *                          docblock), so a local id => photo_id map is
	 *                          built from $players below and used to look
	 *                          each row's avatar up by player id instead.
	 */
	protected function render_pairing_aid( array $players, array $games, array $byes, $selected_round, $trn_type = 'S', $show_photos = false ) {
		$aid   = WPMTM_Pairing_Aid::build( $players, $games, $byes, $selected_round, $trn_type );
		$is_rr = 'R' === $aid['trn_type'];

		$photo_by_id = array();
		if ( $show_photos ) {
			foreach ( $players as $p ) {
				$photo_by_id[ (int) $p['id'] ] = isset( $p['photo_id'] ) ? $p['photo_id'] : null;
			}
		}

		// Change 2: WPMTM_Pairing_Aid::build() rebuilds its own row shape
		// (id/pair_num/name/rating/...) rather than merging $players, so it
		// drops 'family_name_first' the same way it drops 'photo_id' (see
		// $photo_by_id above and WPMTM_Frontend_Public::map_players()'s
		// docblock) - looked up here by player id for every name this
		// method renders (score-group rows, "Not yet paired", Withdrawn).
		$family_first_by_id = array();
		foreach ( $players as $p ) {
			$family_first_by_id[ (int) $p['id'] ] = ! empty( $p['family_name_first'] );
		}
		?>
		<div class="wpmtm-pairing-aid">
			<?php
			// no-print (2026-07-22, print optimization): this how-to-pair
			// explanation is for the TD reading the live page, not something
			// worth printing on a pairing sheet handed to players at the
			// board. openPrintWindow() (assets/wpmtm-frontend.js) strips
			// .no-print from the print popup's clone the same way it strips
			// .wpmtm-toolbar; wpmtm-frontend.css also hides it via
			// @media print for a plain in-page window.print() fallback.
			?>
			<p class="description no-print">
				<?php if ( $is_rr ) : ?>
					<?php esc_html_e( 'Pair so everyone eventually faces everyone. The "Still to play" list shrinks as rounds are entered.', 'wp-tournament-manager' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'Pair players within each score group from the top down: start with the highest score group, matching players inside it before moving to the next group. Give each player the color marked "due" where you can. Avoid pairing two players who have already played each other in this section (see "Opponents played").', 'wp-tournament-manager' ); ?>
				<?php endif; ?>
			</p>

			<?php foreach ( $aid['score_groups'] as $group ) : ?>
				<table class="wpmtm-score-group wpmtm-table">
					<caption>
						<?php if ( $is_rr ) : ?>
							<?php esc_html_e( 'Round robin: pair by schedule', 'wp-tournament-manager' ); ?>
						<?php else : ?>
							<?php
							printf(
								/* translators: %s: score value, e.g. 2.5 */
								esc_html__( 'Score group: %s', 'wp-tournament-manager' ),
								esc_html( WPMTM_Frontend_Public::format_score( $group['score'] ) )
							);
							?>
						<?php endif; ?>
					</caption>
					<thead>
						<tr>
							<?php if ( $show_photos ) : ?>
								<th class="wpmtm-col-photo"><span class="screen-reader-text"><?php esc_html_e( 'Photo', 'wp-tournament-manager' ); ?></span></th>
							<?php endif; ?>
							<th><?php esc_html_e( 'Pair', 'wp-tournament-manager' ); ?></th>
							<th><?php esc_html_e( 'Name', 'wp-tournament-manager' ); ?></th>
							<th><?php esc_html_e( 'Color due', 'wp-tournament-manager' ); ?></th>
							<th><?php echo $is_rr ? esc_html__( 'Still to play', 'wp-tournament-manager' ) : esc_html__( 'Opponents played', 'wp-tournament-manager' ); ?></th>
							<th><?php esc_html_e( 'Bye', 'wp-tournament-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $group['players'] as $p ) : ?>
							<?php
							$due = '';
							if ( 'W' === $p['color_due'] ) {
								$due = __( 'due W', 'wp-tournament-manager' );
							} elseif ( 'B' === $p['color_due'] ) {
								$due = __( 'due B', 'wp-tournament-manager' );
							}
							$opponents_list = $is_rr ? $p['opponents_remaining'] : $p['opponents_played'];
							?>
							<tr>
								<?php if ( $show_photos ) : ?>
									<td class="wpmtm-avatar-cell">
										<?php
										echo WPMTM_Frontend_Public::render_avatar( isset( $photo_by_id[ $p['id'] ] ) ? $photo_by_id[ $p['id'] ] : null ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see WPMTM_Frontend_Public::render_avatar()'s docblock.
										?>
									</td>
								<?php endif; ?>
								<td><?php echo esc_html( $p['pair_num'] ); ?></td>
								<td><?php echo esc_html( WPMTM_Name::display( $p['name'], isset( $family_first_by_id[ $p['id'] ] ) ? $family_first_by_id[ $p['id'] ] : false ) ); ?></td>
								<td><?php echo esc_html( $due ); ?></td>
								<td><?php echo esc_html( implode( ', ', $opponents_list ) ); ?></td>
								<td><?php echo $p['had_bye'] ? esc_html__( 'Yes', 'wp-tournament-manager' ) : ''; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endforeach; ?>

			<?php if ( $aid['unpaired'] ) : ?>
				<p>
					<?php esc_html_e( 'Not yet paired for this round:', 'wp-tournament-manager' ); ?>
					<?php
					$names = array();
					foreach ( $aid['unpaired'] as $u ) {
						$family_first = isset( $family_first_by_id[ $u['id'] ] ) ? $family_first_by_id[ $u['id'] ] : false;
						$names[]      = $u['pair_num'] . '. ' . WPMTM_Name::display( $u['name'], $family_first );
					}
					echo esc_html( implode( ', ', $names ) );
					?>
				</p>
			<?php endif; ?>

			<?php if ( $aid['withdrawn'] ) : ?>
				<h4><?php esc_html_e( 'Withdrawn', 'wp-tournament-manager' ); ?></h4>
				<table class="wpmtm-withdrawn-list">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Pair', 'wp-tournament-manager' ); ?></th>
							<th><?php esc_html_e( 'Name', 'wp-tournament-manager' ); ?></th>
							<th><?php esc_html_e( 'Score', 'wp-tournament-manager' ); ?></th>
							<th><?php esc_html_e( 'Withdrawn', 'wp-tournament-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $aid['withdrawn'] as $w ) : ?>
							<tr>
								<td><?php echo esc_html( $w['pair_num'] ); ?></td>
								<td><?php echo esc_html( WPMTM_Name::display( $w['name'], isset( $family_first_by_id[ $w['id'] ] ) ? $family_first_by_id[ $w['id'] ] : false ) ); ?></td>
								<td><?php echo esc_html( WPMTM_Frontend_Public::format_score( $w['score'] ) ); ?></td>
								<td>
									<?php
									printf(
										/* translators: %d: round number after which the player withdrew */
										esc_html__( 'after round %d', 'wp-tournament-manager' ),
										(int) $w['withdrawn_after_round']
									);
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Filters a section's roster down to players still eligible to be paired
	 * for a given round: never withdrawn, or withdrawn as of a later round
	 * than the one being entered (withdrawn_after_round = N means they
	 * played through round N and are out from round N + 1 onward).
	 *
	 * @return array
	 */
	protected function players_active_for_round( array $players, $round ) {
		$round = (int) $round;
		return array_values(
			array_filter(
				$players,
				function ( $p ) use ( $round ) {
					$withdrawn_after_round = isset( $p['withdrawn_after_round'] ) ? $p['withdrawn_after_round'] : null;
					return null === $withdrawn_after_round || (int) $withdrawn_after_round >= $round;
				}
			)
		);
	}

	protected function render_round_entry_form( $tournament, $section, array $players, array $games, array $byes, $selected_round, $round_param, $suggestion = null ) {
		$round_games = array_values(
			array_filter(
				$games,
				function ( $g ) use ( $selected_round ) {
					return (int) $g['round'] === (int) $selected_round;
				}
			)
		);

		$byes_prefill = array();
		foreach ( $byes as $b ) {
			if ( (int) $b['round'] === (int) $selected_round ) {
				$byes_prefill[ $b['player_id'] ] = $b['type'];
			}
		}

		// Players withdrawn before the selected round cannot be paired for
		// it (docs/SPEC.md, withdrawals) - keep them out of the White/Black
		// selects and the byes table entirely, rather than let the TD pick
		// them and hit the round-entry validator's error on save.
		$active_players = $this->players_active_for_round( $players, $selected_round );

		// A suggestion only ever prefills boards that are empty of real
		// pairing rows - "not enough active players" and similar failures
		// come back as an empty 'boards' list, in which case there is
		// nothing to prefill and the form falls back to its normal blank
		// row, with the suggester's notes still shown above it.
		$suggested_boards = ( $suggestion && ! empty( $suggestion['boards'] ) ) ? $suggestion['boards'] : array();
		if ( $suggested_boards && $suggestion['bye_player_id'] ) {
			$byes_prefill[ $suggestion['bye_player_id'] ] = 'B';
		}

		// Change 6 ("conclude and lock a tournament"): re-read straight off
		// $tournament (already freshly fetched per request by whichever
		// caller resolved it - see handle_save_round()'s own re-fetch and
		// its docblock note), not cached anywhere across requests. The
		// disabled selects/no-Save-button below are only the visual cue;
		// handle_save_round() re-checks this same flag server-side and is
		// the actual protection against a locked tournament being edited.
		$locked   = (bool) $tournament->locked;
		$table_id = 'wpmtm-boards-table-' . $section->id;
		?>
		<?php if ( $suggestion ) : ?>
			<?php
			/*
			 * Change 4: a visible confirmation that the suggester actually
			 * ran and prefilled the boards below, at the very top of the
			 * entry form area - the field report behind render_suggest_
			 * ineligible_notice() above ("Suggest pairings does not seem to
			 * work") was really two different silent failures: one where
			 * the round was not eligible (that notice), and one where it
			 * WAS eligible and a suggestion WAS built, but nothing on the
			 * page told the TD that had happened. $suggestion is only ever
			 * non-null here when maybe_build_suggestion() found the round
			 * eligible and the GET trigger present (see that method's own
			 * docblock), i.e. exactly the "keep the existing explanation
			 * instead" case (render_suggest_ineligible_notice() above) is
			 * mutually exclusive with this one - never both for the same
			 * request.
			 */
			?>
			<p class="notice notice-success wpmtm-suggest-success">
				<?php
				printf(
					/* translators: %d: round number */
					esc_html__( 'Suggested pairings loaded for round %d. Review each board, then Save round to record them.', 'wp-tournament-manager' ),
					(int) $selected_round
				);
				?>
			</p>
		<?php endif; ?>
		<h4>
			<?php
			// Copy/markup only, not a new save mechanism (owner decision,
			// 2026-07-17): "Save round" still does one combined write below.
			// $round_games (filtered to the selected round, computed at the
			// top of this method) is the same "has this round been paired
			// yet" signal round_ready_for_suggestion() above already relies
			// on, reused here rather than a new query - a saved suggestion
			// prefill does not count, since nothing is written until Save
			// round is actually clicked.
			if ( $round_games ) {
				printf(
					/* translators: %d: round number */
					esc_html__( 'Entering results for round %d', 'wp-tournament-manager' ),
					(int) $selected_round
				);
			} else {
				printf(
					/* translators: %d: round number */
					esc_html__( 'Pairing round %d', 'wp-tournament-manager' ),
					(int) $selected_round
				);
			}
			?>
		</h4>
		<?php if ( $suggestion && ! empty( $suggestion['notes'] ) ) : ?>
			<ul class="wpmtm-suggest-notes">
				<?php foreach ( $suggestion['notes'] as $note ) : ?>
					<li><?php echo esc_html( $note ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php if ( $suggestion ) : ?>
			<p class="description">
				<?php esc_html_e( 'Suggestions follow a simplified top-half versus bottom-half model with rematch avoidance and due colors, not the full USCF pairing rules. Review every board before saving.', 'wp-tournament-manager' ); ?>
			</p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpmtm-round-entry-form" data-wpmtm-guard data-wpmtm-save-confirm="<?php echo esc_attr( sprintf( /* translators: %d: round number */ __( 'Save results for round %d? This replaces any results already saved for this round.', 'wp-tournament-manager' ), (int) $selected_round ) ); ?>">
			<?php wp_nonce_field( 'wpmtm_save_round_' . $section->id . '_' . $selected_round, 'wpmtm_round_nonce' ); ?>
			<input type="hidden" name="action" value="wpmtm_save_round">
			<input type="hidden" name="tournament_id" value="<?php echo esc_attr( $tournament->id ); ?>">
			<input type="hidden" name="section_id" value="<?php echo esc_attr( $section->id ); ?>">
			<input type="hidden" name="round" value="<?php echo esc_attr( $selected_round ); ?>">
			<input type="hidden" name="wpmtm_return_round_param" value="<?php echo esc_attr( $round_param ); ?>">

			<?php $this->render_players_json( $active_players, $section->id ); ?>
			<table class="wpmtm-boards-table wpmtm-table" id="<?php echo esc_attr( $table_id ); ?>" data-wpmtm-round-repeater>
				<thead>
					<tr>
						<th><?php esc_html_e( 'Board', 'wp-tournament-manager' ); ?></th>
						<th><?php esc_html_e( 'White', 'wp-tournament-manager' ); ?></th>
						<th><?php esc_html_e( 'Black', 'wp-tournament-manager' ); ?></th>
						<th><?php esc_html_e( 'Result', 'wp-tournament-manager' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php
					if ( $suggested_boards ) {
						foreach ( $suggested_boards as $board ) {
							$this->render_board_row(
								$active_players,
								array(
									'board'           => '',
									'white_player_id' => $board['white_player_id'],
									'black_player_id' => $board['black_player_id'],
									'result'          => 'W',
								),
								$locked,
								$section->id
							);
						}
					} elseif ( $round_games ) {
						foreach ( $round_games as $game ) {
							$this->render_board_row( $active_players, $game, $locked, $section->id );
						}
					} else {
						$this->render_board_row( $active_players, null, $locked, $section->id );
					}
					?>
				</tbody>
				<template>
					<?php $this->render_board_row( $active_players, null, $locked, $section->id ); ?>
				</template>
			</table>
			<p><button type="button" class="button" data-wpmtm-add-board-for="<?php echo esc_attr( $table_id ); ?>"><?php esc_html_e( '+ Add board', 'wp-tournament-manager' ); ?></button></p>
			<p class="description"><?php esc_html_e( 'Board numbers are assigned automatically in row order. They are only for pairing convenience and are not part of the USCF export.', 'wp-tournament-manager' ); ?></p>

			<?php
			// Withdraw-dropdown gating (docs/SPEC.md, 2026-07-18): withdrawing
			// "as of" a round only means anything when a later round still
			// exists to be dropped from - see
			// WPMTM_Round_Selector::withdraw_offered()'s docblock.
			$allow_withdraw = WPMTM_Round_Selector::withdraw_offered( $selected_round, $section->tot_rnds );
			$this->render_byes_area( $active_players, $byes_prefill, $locked, $allow_withdraw );
			?>

			<p class="description">
				<?php esc_html_e( 'Saving a round replaces that round\'s results entirely, so correcting a mistake is just re-saving the round with the fix. Standings above update immediately.', 'wp-tournament-manager' ); ?>
			</p>

			<?php if ( ! $locked ) : ?>
				<?php
				/*
				 * Owner decision (2026-07-17): keep the "Save round" label
				 * and its one-combined-write behavior exactly as is; only
				 * the styling changes. submit_button() emits wp-admin's
				 * .button/.button-primary classes, which have no matching
				 * CSS on this front-end event page (this form renders on
				 * the event's Round entry tab, not in wp-admin), so the
				 * button looked inconsistent next to this page's other
				 * controls. Rendered by hand instead, with the same
				 * .wpmtm-btn class the Suggest pairings link and Print
				 * button already use on this page (render_suggest_link()
				 * above, WPMTM_Frontend_Public::render_print_toolbar()), so
				 * all three look alike. type="submit"/name="submit" and the
				 * data-wpmtm-busy-label attribute are unchanged, so the
				 * double-submit guard (assets/wpmtm-frontend.js) still finds
				 * and disables this button exactly as before.
				 */
				?>
				<p class="submit">
					<?php
					// "Save round N" (2026-07-23, owner request): naming the
					// exact round on the button makes it plain which round a
					// TD is about to write, one more guard against saving the
					// wrong round. The POST is dispatched by the wpmtm_save_round
					// action field above, never this button's value, so the
					// label is free to change.
					$save_label = sprintf( /* translators: %d: round number */ __( 'Save round %d', 'wp-tournament-manager' ), (int) $selected_round );
					?>
					<button
						type="submit"
						name="submit"
						id="submit"
						value="<?php echo esc_attr( $save_label ); ?>"
						class="wpmtm-btn wpmtm-save-round-btn"
						data-wpmtm-busy-label="<?php echo esc_attr__( 'Saving...', 'wp-tournament-manager' ); ?>"
					><?php echo esc_html( $save_label ); ?></button>
				</p>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * One board row. Board numbers are never posted as a field - the
	 * handler assigns them from row order at save time ("auto-numbered"
	 * per the spec) - so the first cell is a display-only number and the
	 * White/Black/result selects post as three parallel arrays
	 * (board_white[], board_black[], board_result[]) rather than a single
	 * nested "boards[][...]" array; a select always submits a value, so
	 * the indexes stay aligned across the three arrays row by row. That
	 * also means the JS repeater never has to rewrite field names on
	 * add/remove (contrast assets/wpmtm-admin.js, whose repeaters track a
	 * per-row id for server-side row deletion; this form does not need
	 * that, since a whole round is replaced wholesale on save).
	 *
	 * @param array      $players    Section roster, for the White/Black selects.
	 * @param array|null $game       Existing game row to prefill, or null for a blank/template row.
	 * @param bool       $locked     Change 6: when true, the three selects get
	 *                               the disabled attribute - a visual cue only,
	 *                               since the caller (render_round_entry_form())
	 *                               never renders the Save round button either
	 *                               when locked, and handle_save_round() below
	 *                               is the real, server-side guard.
	 * @param int        $section_id Ties the White/Black selects to the
	 *                               matching render_players_json() data
	 *                               island - see that method's docblock.
	 */
	protected function render_board_row( array $players, $game, $locked = false, $section_id = 0 ) {
		$board  = $game ? $game['board'] : '';
		$white  = $game ? $game['white_player_id'] : '';
		$black  = $game ? $game['black_player_id'] : '';
		$result = $game ? $game['result'] : 'W';
		?>
		<tr>
			<td class="wpmtm-col-num"><?php echo '' !== $board ? esc_html( $board ) : esc_html__( 'auto', 'wp-tournament-manager' ); ?></td>
			<td>
				<select name="board_white[]" class="wpmtm-player-select" data-wpmtm-players-section="<?php echo esc_attr( $section_id ); ?>" <?php disabled( $locked ); ?>>
					<?php $this->render_player_options( $players, $white ); ?>
				</select>
			</td>
			<td>
				<select name="board_black[]" class="wpmtm-player-select" data-wpmtm-players-section="<?php echo esc_attr( $section_id ); ?>" <?php disabled( $locked ); ?>>
					<?php $this->render_player_options( $players, $black ); ?>
				</select>
			</td>
			<td>
				<select name="board_result[]" <?php disabled( $locked ); ?>>
					<?php foreach ( $this->result_options() as $code => $label ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $result, $code ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td><button type="button" class="button-link-delete" data-remove-row><?php esc_html_e( 'Remove', 'wp-tournament-manager' ); ?></button></td>
		</tr>
		<?php
	}

	/**
	 * Board-row White/Black select options. Only ever emits "-- select --"
	 * plus (when the row already has a value - an existing game, or a
	 * suggested board) that one player's own option, so the row still shows
	 * the right name with JS disabled or before it runs. The rest of the
	 * roster is populated client-side (assets/wpmtm-frontend.js) from the
	 * data island render_players_json() prints once per section, rather than
	 * every board's select repeating the entire roster as literal <option>
	 * tags: a section with P players and ~P/2 boards previously duplicated
	 * the whole roster P/2 * 2 times, an O(players * boards) HTML payload -
	 * the main driver of the TD round-entry page's outsized page weight.
	 *
	 * @param array $players     Section roster (or, from render_board_row()'s
	 *                            callers, only the players active for the
	 *                            selected round) - only consulted to look up
	 *                            $selected_id's own label.
	 * @param mixed $selected_id Currently selected player id, or '' for none.
	 */
	protected function render_player_options( array $players, $selected_id ) {
		echo '<option value="">' . esc_html__( '-- select --', 'wp-tournament-manager' ) . '</option>';
		$selected_id = (int) $selected_id;
		if ( $selected_id <= 0 ) {
			return;
		}
		foreach ( $players as $p ) {
			if ( (int) $p['id'] === $selected_id ) {
				printf(
					'<option value="%1$d" selected>%2$s</option>',
					$selected_id,
					esc_html( $p['pair_num'] . '. ' . WPMTM_Name::display( $p['name'], ! empty( $p['family_name_first'] ) ) )
				);
				break;
			}
		}
	}

	/**
	 * The section's active-player roster as a single JSON data island (id +
	 * display label), printed once per render_round_entry_form() call.
	 * assets/wpmtm-frontend.js reads it and populates every
	 * .wpmtm-player-select whose data-wpmtm-players-section matches -
	 * see render_player_options()'s docblock for why this replaces repeating
	 * the roster as literal <option> tags in every board row.
	 *
	 * @param array $players    Section roster active for the selected round.
	 * @param int   $section_id
	 */
	protected function render_players_json( array $players, $section_id ) {
		$data = array();
		foreach ( $players as $p ) {
			$data[] = array(
				'id'    => (int) $p['id'],
				'label' => $p['pair_num'] . '. ' . WPMTM_Name::display( $p['name'], ! empty( $p['family_name_first'] ) ),
			);
		}
		printf(
			'<script type="application/json" class="wpmtm-players-data" data-wpmtm-players-section="%1$d">%2$s</script>',
			(int) $section_id,
			wp_json_encode( $data ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode() escapes forward slashes by default, so a label containing "</script>" cannot break out of this tag; consumed only as JSON.parse()'d text by assets/wpmtm-frontend.js, never rendered as HTML.
		);
	}

	protected function result_options() {
		return array(
			'W'  => __( 'White won (W)', 'wp-tournament-manager' ),
			'B'  => __( 'Black won (B)', 'wp-tournament-manager' ),
			'D'  => __( 'Draw (D)', 'wp-tournament-manager' ),
			'FW' => __( 'White won by forfeit (FW)', 'wp-tournament-manager' ),
			'FB' => __( 'Black won by forfeit (FB)', 'wp-tournament-manager' ),
			'FD' => __( 'Double forfeit (FD)', 'wp-tournament-manager' ),
		);
	}

	/**
	 * Byes area: every section player gets a bye-type select, not just
	 * players not currently on a board - tracking "not on a board" as
	 * boards are added/removed client-side would need JS kept in sync with
	 * the repeater above, which is more complexity than the payoff is
	 * worth. The help text tells the TD to leave "None" for anyone on a
	 * board.
	 *
	 * The extra "Withdraw" option (value 'WD') is not a bye type at all -
	 * handle_save_round() strips it out of the posted byes before validation
	 * and turns it into WPMTM_Repository::set_player_withdrawn() instead,
	 * once the round itself has saved successfully (docs/SPEC.md,
	 * withdrawals).
	 *
	 * Withdraw-dropdown gating (docs/SPEC.md, 2026-07-18): the option is
	 * only offered when $allow_withdraw is true, i.e. when a later round
	 * still exists to be dropped from (see
	 * WPMTM_Round_Selector::withdraw_offered(), the pure logic this is
	 * gated on). Withdrawing "as of" the final round is meaningless - there
	 * is no later round for the player to be marked out of - so a player
	 * not playing the final round gets a bye/unplayed entry instead.
	 */
	/**
	 * @param array $players        Section roster active for the selected round.
	 * @param array $byes_prefill   player_id => bye type, for existing rows.
	 * @param bool  $locked         Change 6: when true, every bye-type select
	 *                              gets the disabled attribute - see
	 *                              render_board_row()'s docblock for why this
	 *                              is a visual cue only.
	 * @param bool  $allow_withdraw Whether the Withdraw option should be
	 *                              offered at all (see this method's own
	 *                              docblock above). Default true so any other
	 *                              caller keeps today's behavior.
	 */
	protected function render_byes_area( array $players, array $byes_prefill, $locked = false, $allow_withdraw = true ) {
		?>
		<h4><?php esc_html_e( 'Byes', 'wp-tournament-manager' ); ?></h4>
		<p class="description">
			<?php if ( $allow_withdraw ) : ?>
				<?php esc_html_e( 'Assign a bye to anyone not playing this round. Leave None if the player is on a board above. Withdraw marks the player out from this round on, instead of writing a bye.', 'wp-tournament-manager' ); ?>
			<?php else : ?>
				<?php esc_html_e( 'Assign a bye to anyone not playing this round. Leave None if the player is on a board above. This is the final round, so there is no later round to withdraw from. A player sitting it out gets a bye instead.', 'wp-tournament-manager' ); ?>
			<?php endif; ?>
		</p>
		<table class="wpmtm-byes-table wpmtm-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Pair', 'wp-tournament-manager' ); ?></th>
					<th><?php esc_html_e( 'Name', 'wp-tournament-manager' ); ?></th>
					<th><?php esc_html_e( 'Bye', 'wp-tournament-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $players as $p ) : ?>
					<?php $current = isset( $byes_prefill[ $p['id'] ] ) ? $byes_prefill[ $p['id'] ] : ''; ?>
					<tr>
						<td><?php echo esc_html( $p['pair_num'] ); ?></td>
						<td>
							<?php echo esc_html( WPMTM_Name::display( $p['name'], ! empty( $p['family_name_first'] ) ) ); ?>
							<?php if ( ! empty( $p['notes'] ) ) : ?>
								<p class="description wpmtm-player-note"><?php echo esc_html( $p['notes'] ); ?></p>
							<?php endif; ?>
						</td>
						<td>
							<select name="byes[<?php echo esc_attr( $p['id'] ); ?>]" <?php disabled( $locked ); ?>>
								<option value="" <?php selected( $current, '' ); ?>><?php esc_html_e( 'None', 'wp-tournament-manager' ); ?></option>
								<option value="B" <?php selected( $current, 'B' ); ?>><?php esc_html_e( 'Full-point bye (B)', 'wp-tournament-manager' ); ?></option>
								<option value="H" <?php selected( $current, 'H' ); ?>><?php esc_html_e( 'Half-point bye (H)', 'wp-tournament-manager' ); ?></option>
								<option value="U" <?php selected( $current, 'U' ); ?>><?php esc_html_e( 'Unplayed (U)', 'wp-tournament-manager' ); ?></option>
								<?php if ( $allow_withdraw ) : ?>
									<option value="WD" <?php selected( $current, 'WD' ); ?>><?php esc_html_e( 'Withdraw (out from this round on)', 'wp-tournament-manager' ); ?></option>
								<?php endif; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	// -----------------------------------------------------------------
	// Save handler.
	// -----------------------------------------------------------------

	public function handle_save_round() {
		$section_id = isset( $_POST['section_id'] ) ? absint( $_POST['section_id'] ) : 0;
		$round      = isset( $_POST['round'] ) ? absint( $_POST['round'] ) : 0;
		check_admin_referer( 'wpmtm_save_round_' . $section_id . '_' . $round, 'wpmtm_round_nonce' );

		$tournament_id = isset( $_POST['tournament_id'] ) ? absint( $_POST['tournament_id'] ) : 0;
		$round_param   = isset( $_POST['wpmtm_return_round_param'] ) ? sanitize_key( wp_unslash( $_POST['wpmtm_return_round_param'] ) ) : '';

		$section    = $section_id ? WPMTM_Repository::get_section( $section_id ) : null;
		$tournament = $section ? WPMTM_Repository::get_tournament( $section->tournament_id ) : null;

		if ( ! $section || ! $tournament || (int) $tournament->id !== $tournament_id ) {
			wp_die( esc_html__( 'Section not found, or it does not belong to the posted tournament.', 'wp-tournament-manager' ) );
		}

		if ( ! WPMTM_Roles::user_can_manage_tournament( $tournament ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'wp-tournament-manager' ) );
		}

		// The section slug is recomputed here from the just-fetched, real DB
		// rows - never trusted from the POST - the same way $tournament's
		// locked state a few lines below is always re-fetched fresh rather
		// than carried over from render time. '' (no slug) for a single-
		// section tournament, matching render_section_td_panel()'s own
		// round_entry_hash( '' ) call, so the redirect hash is unchanged for
		// the common single-section case (docs/SPEC.md, 2026-07-18).
		$all_sections = WPMTM_Repository::get_sections( $tournament->id );
		$slug         = '';
		if ( count( $all_sections ) > 1 ) {
			$slugs = $this->build_section_slugs( $all_sections );
			$slug  = isset( $slugs[ $section_id ] ) ? $slugs[ $section_id ] : '';
		}

		$redirect_back = $this->build_return_url( $tournament, $round_param, $round, $slug );

		// Change 6 ("conclude and lock a tournament"): $tournament above
		// was just re-fetched fresh from the database a few lines up (not
		// carried over from render time), so this is always the current
		// locked state, not a stale one from whenever the form was
		// rendered - the real protection against a locked tournament being
		// edited; the round-entry form's disabled selects and missing Save
		// button (render_round_entry_form() above) are only the visual cue.
		if ( (bool) $tournament->locked ) {
			$this->set_notice( 'error', __( 'This tournament is locked. Unlock it to enter results.', 'wp-tournament-manager' ) );
			wp_safe_redirect( $redirect_back );
			exit;
		}

		if ( $round <= 0 ) {
			$this->set_notice( 'error', __( 'Invalid round number. Nothing was saved.', 'wp-tournament-manager' ) );
			wp_safe_redirect( $redirect_back );
			exit;
		}

		// Reuses WPMTM_Frontend_Public's mapping helper rather than
		// building this array inline a second time - see that method's
		// docblock for why it is the one place this mapping lives.
		$players = WPMTM_Frontend_Public::instance()->map_players( $section_id );

		// Boards are auto-numbered from posted row order (see
		// render_board_row()); a fully blank row (the unused trailing "Add
		// board" row) is skipped rather than counted.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each element is sanitized below (absint() for player ids, sanitize_text_field() for the result code) as it is read out of the array; nonce already verified above via check_admin_referer().
		$posted_white  = ( isset( $_POST['board_white'] ) && is_array( $_POST['board_white'] ) ) ? wp_unslash( $_POST['board_white'] ) : array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- see note above.
		$posted_black  = ( isset( $_POST['board_black'] ) && is_array( $_POST['board_black'] ) ) ? wp_unslash( $_POST['board_black'] ) : array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- see note above.
		$posted_result = ( isset( $_POST['board_result'] ) && is_array( $_POST['board_result'] ) ) ? wp_unslash( $_POST['board_result'] ) : array();

		$posted_white  = array_values( $posted_white );
		$posted_black  = array_values( $posted_black );
		$posted_result = array_values( $posted_result );

		$boards    = array();
		$board_num = 0;
		foreach ( $posted_white as $i => $white_raw ) {
			$black_raw  = isset( $posted_black[ $i ] ) ? $posted_black[ $i ] : '';
			$result_raw = isset( $posted_result[ $i ] ) ? $posted_result[ $i ] : '';

			if ( '' === $white_raw && '' === $black_raw ) {
				continue; // unused blank "add board" row.
			}

			++$board_num;
			$boards[] = array(
				'board'           => $board_num,
				'white_player_id' => absint( $white_raw ),
				'black_player_id' => absint( $black_raw ),
				'result'          => strtoupper( trim( sanitize_text_field( $result_raw ) ) ),
			);
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each $type is sanitized below (sanitize_text_field()) as it is read out of the array; nonce already verified above via check_admin_referer().
		$posted_byes = ( isset( $_POST['byes'] ) && is_array( $_POST['byes'] ) ) ? wp_unslash( $_POST['byes'] ) : array();
		$byes        = array();
		$withdrawals = array(); // player ids posted as 'WD' - not a bye type, handled after a successful save below.
		foreach ( $posted_byes as $player_id => $type ) {
			$type = strtoupper( trim( sanitize_text_field( $type ) ) );
			if ( '' === $type ) {
				continue; // "None" selected.
			}
			if ( 'WD' === $type ) {
				// Withdrawals are not bye rows (docs/SPEC.md, withdrawals):
				// stripped from $byes here so neither validate_round() nor
				// replace_round() ever sees 'WD' as a bye type.
				$withdrawals[] = absint( $player_id );
				continue;
			}
			$byes[] = array(
				'player_id' => absint( $player_id ),
				'type'      => $type,
			);
		}

		// Guard: a player cannot both play a board and withdraw in the same round.
		$boards_player_ids = array();
		foreach ( $boards as $b ) {
			if ( $b['white_player_id'] ) {
				$boards_player_ids[] = $b['white_player_id'];
			}
			if ( $b['black_player_id'] ) {
				$boards_player_ids[] = $b['black_player_id'];
			}
		}
		$conflict = array_intersect( $withdrawals, $boards_player_ids );
		if ( ! empty( $conflict ) ) {
			$this->set_notice( 'error', __( 'A player cannot both play a board and withdraw in the same round. Remove one of the two and save again.', 'wp-tournament-manager' ) );
			wp_safe_redirect( $redirect_back );
			exit;
		}

		$validation = WPMTM_Round_Entry::validate_round( $players, $boards, $byes, $round );

		if ( ! $validation['ok'] ) {
			$this->set_notice(
				'error',
				__( 'The round could not be saved:', 'wp-tournament-manager' ) . ' ' . implode( ' ', $validation['errors'] )
			);
			wp_safe_redirect( $redirect_back );
			exit;
		}

		$saved = WPMTM_Repository::replace_round( $section_id, $round, $boards, $byes );

		if ( ! $saved ) {
			$this->set_notice( 'error', __( 'The round could not be saved due to a database error. Nothing was changed.', 'wp-tournament-manager' ) );
			wp_safe_redirect( $redirect_back );
			exit;
		}

		// Withdrawals are only applied after the round itself saved
		// successfully - player P withdrawing as of round R means they
		// played through round R - 1, so that is the value recorded.
		$withdrawn_count = 0;
		foreach ( $withdrawals as $player_id ) {
			if ( WPMTM_Repository::set_player_withdrawn( $player_id, $round - 1 ) ) {
				++$withdrawn_count;
			}
		}

		WPMTM_Cache::flush_event_page( (int) $tournament->event_post_id );

		$message = __( 'Round saved. Standings and results above update immediately.', 'wp-tournament-manager' );
		if ( $withdrawn_count > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of players marked withdrawn */
				_n( '%d player was marked withdrawn.', '%d players were marked withdrawn.', $withdrawn_count, 'wp-tournament-manager' ),
				$withdrawn_count
			);
		}
		$this->set_notice( 'success', $message );
		wp_safe_redirect( $redirect_back );
		exit;
	}

	/** Logged-out POSTs to this action are always rejected outright. */
	public function handle_save_round_nopriv() {
		wp_die( esc_html__( 'Forbidden', 'wp-tournament-manager' ), 403 );
	}

	/**
	 * Redirect target after a save attempt: the event's own page (with the
	 * round GET param preserved so the TD lands back on the round they just
	 * worked on), or the admin tournament edit screen if the tournament has
	 * no linked event.
	 *
	 * When redirecting to the event page, appends round_entry_hash( $slug )
	 * (plain string concat after add_query_arg(), same as
	 * render_suggest_link() / render_round_selector() above) so saving a
	 * round lands the TD back on the wp-etr Round entry tab - and, for a
	 * multi-section tournament, the same section sub-tab they were already
	 * working, replacing the old sessionStorage-based restore (docs/SPEC.md,
	 * 2026-07-18) - instead of whichever tab/section wp-etr and this plugin
	 * would otherwise default to. Not appended for the admin edit screen
	 * fallback - that screen has no such tab.
	 *
	 * @param string $slug '' for single-section, else the just-saved
	 *                     section's build_section_slugs() value (see
	 *                     handle_save_round()'s own recompute-from-DB note).
	 */
	protected function build_return_url( $tournament, $round_param, $round, $slug = '' ) {
		$is_event_page = (bool) $tournament->event_post_id;
		$base          = $is_event_page ? get_permalink( $tournament->event_post_id ) : '';
		if ( ! $base ) {
			$is_event_page = false;
			$base          = add_query_arg( array( 'page' => 'wpmtm-edit', 'id' => $tournament->id ), admin_url( 'admin.php' ) );
		}
		if ( '' !== $round_param ) {
			$base = add_query_arg( $round_param, $round, $base );
		}
		return $is_event_page ? $base . $this->round_entry_hash( $slug ) : $base;
	}
}
