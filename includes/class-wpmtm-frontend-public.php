<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public-facing results/standings rendering: the results block every
 * visitor sees (WPMTM_Frontend::build_block() calls render_public_block()),
 * plus the read-only wall chart underneath it. Split out of what used to be
 * a single WPMTM_Frontend god class, the same way the admin side is split
 * into WPMTM_Admin / WPMTM_Admin_Import / WPMTM_Admin_Export.
 *
 * Also owns section_data_arrays() and map_players(): WPMTM_Frontend_TD
 * reuses both (via WPMTM_Frontend_Public::instance()) for its round-entry
 * panel and its save handler, rather than each class fetching and mapping
 * $wpdb rows independently. See the docblocks on those two methods below.
 *
 * No hooks of its own - it is only ever driven by method calls from
 * WPMTM_Frontend (public render path) and WPMTM_Frontend_TD (data reuse),
 * so its constructor is empty and it does not use WPMTM_Admin_Shared: it
 * never renders a notice itself (build_block() calls render_notices()
 * before this class runs).
 *
 * The standings, wall chart, and pairings tables themselves are public data
 * shown to every visitor. Two things layered on top of them are not, and
 * each gates itself: the TD command row (render_td_command_row()) and the
 * editor-only player card composed in from WPMTM_Frontend_Public_Card. Both
 * gate on WPMTM_Roles::user_can_manage_tournament(), not on a bare
 * WPMTM_CAPABILITY check - see the card trait's docblock for why that
 * distinction matters on a shared multi-club install (audit item 37).
 */
class WPMTM_Frontend_Public {

	// Split across trait files (2026-07-29 segmentation) to keep this file
	// small; each is composed in verbatim, so every method keeps its
	// visibility and $this semantics and every call site is unchanged. The
	// entry points, print toolbar, and shared utilities (map_players,
	// section_data_arrays, format_score, render_avatar) stay here:
	//   - WPMTM_Frontend_Public_Standings: render_section_standings.
	//   - WPMTM_Frontend_Public_WallChart: the wall-chart table and wrapper.
	//   - WPMTM_Frontend_Public_Pairings:  the player-facing pairings tab.
	use WPMTM_Frontend_Public_Standings;
	use WPMTM_Frontend_Public_WallChart;
	use WPMTM_Frontend_Public_Pairings;
	use WPMTM_Frontend_Public_Card;

	private static $instance = null;

	/**
	 * Per-request memo for section_data_arrays(), keyed by section id.
	 * See that method's docblock for why this is safe.
	 *
	 * @var array
	 */
	private $section_data_memo = array();

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Enqueues assets/wpmtm-frontend.css and assets/wpmtm-frontend.js for
	 * the current front-end render. Every visitor needs these - the
	 * standings table, the wall chart, and (assets/wpmtm-frontend.js) the
	 * Wall chart tab's Print button click handler are all public, shown
	 * to every visitor, not just a user who can WPMTM_CAPABILITY - so
	 * WPMTM_Frontend::build_block() / filter_etr_event_tabs() both call
	 * this unconditionally as soon as a tournament resolves.
	 * WPMTM_Frontend_TD::render_td_block() (the capability-gated round-
	 * entry panel) also calls it, which is safe: wp_enqueue_style() /
	 * wp_enqueue_script() dedupe by handle, so calling this twice in one
	 * request is a no-op the second time.
	 */
	public function enqueue_frontend_assets() {
		wp_enqueue_style( 'wpmtm-frontend', WPMTM_PLUGIN_URL . 'assets/wpmtm-frontend.css', array(), WPMTM_VERSION );
		wp_enqueue_script( 'wpmtm-frontend', WPMTM_PLUGIN_URL . 'assets/wpmtm-frontend.js', array(), WPMTM_VERSION, true );

		// 2026-07-21: the setup guide panel (WPMTM_Wizard::render_panel_for_event(),
		// called from render_td_command_row() below) reuses render_panel()
		// verbatim, whose markup/behavior is styled and scripted by the
		// admin assets, not the front-end ones - loading them here rather
		// than forking a front-end copy keeps one source of truth for the
		// panel's CSS/JS. Capability-gated, same as the panel itself:
		// never loaded for a public visitor. Every OTHER behavior in
		// wpmtm-admin.js is gated on selectors that only exist in
		// wp-admin markup, so it is a no-op here, not a conflict.
		//
		// The panel's open/closed persistence (wpmtm-admin.js Behavior 7)
		// posts to window.ajaxurl, which WordPress only defines
		// automatically in wp-admin - localized here so it also works on
		// the front end (fixed 2026-07-21; previously this fetch()
		// silently failed on the front end, already inside a .catch(), so
		// the panel always started collapsed here regardless of the TD's
		// last choice - the guide's actual content still worked without
		// it, just not the open/closed memory).
		if ( current_user_can( WPMTM_CAPABILITY ) ) {
			wp_enqueue_style( 'wpmtm-admin', WPMTM_PLUGIN_URL . 'assets/wpmtm-admin.css', array(), WPMTM_VERSION );
			wp_enqueue_script( 'wpmtm-admin', WPMTM_PLUGIN_URL . 'assets/wpmtm-admin.js', array(), WPMTM_VERSION, true );
			wp_localize_script(
				'wpmtm-admin',
				'wpmtmFrontendAjax',
				array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ) )
			);
		}
	}

	// -----------------------------------------------------------------
	// Public standings (all visitors).
	// -----------------------------------------------------------------

	/**
	 * @param object $tournament
	 * @param bool   $show_td_note Whether the "not complete yet" TD-only
	 *                              note (render_section_standings()) is
	 *                              allowed to render here. Default false:
	 *                              this method has two callers and only
	 *                              one of them is safe for TD-only content
	 *                              (docs/SPEC.md, "Decisions (2026-07-18,
	 *                              rank by score until complete)"):
	 *                              WPMTM_Frontend::build_block() (the
	 *                              event-page inline fallback) passes
	 *                              $can_manage, the same
	 *                              current_user_can( WPMTM_CAPABILITY )
	 *                              result it uses right after this call to
	 *                              decide whether to define DONOTCACHEPAGE -
	 *                              so whenever the note could render, this
	 *                              whole page is guaranteed never cached.
	 *                              WPMTM_Frontend::render_standings_shortcode()
	 *                              ([wpmtm_standings], placeable on ANY
	 *                              page) never defines DONOTCACHEPAGE by
	 *                              design - its own docblock already warns
	 *                              a page cache serving that other page can
	 *                              keep stale HTML around past this
	 *                              plugin's own cache-flush reach - so a
	 *                              TD's own render could be cached and
	 *                              later served to a public visitor. It
	 *                              leaves this false (the default), so the
	 *                              note can never enter that HTML at all.
	 */
	public function render_public_block( $tournament, $show_td_note = false ) {
		$this->ensure_attendee_ids_linked( $tournament );
		$sections    = WPMTM_Repository::get_sections( $tournament->id );
		$show_photos = (bool) $tournament->show_photos;
		?>
		<div class="wpmtm-frontend-results">
			<?php $this->render_td_command_row( $tournament ); ?>
			<h2>
				<?php esc_html_e( 'Standings', 'wp-tournament-manager' ); ?>
				<?php if ( (bool) $tournament->locked ) : ?>
					<span class="wpmtm-final-badge"><?php esc_html_e( 'Final', 'wp-tournament-manager' ); ?></span>
				<?php endif; ?>
			</h2>
			<?php if ( empty( $sections ) ) : ?>
				<p><?php esc_html_e( 'No sections have been set up yet.', 'wp-tournament-manager' ); ?></p>
			<?php endif; ?>
			<?php foreach ( $sections as $section ) : ?>
				<?php $this->render_section_standings( $section, $show_photos, true, $show_td_note ); ?>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Standings only (every section), with no page-level heading and no wall
	 * chart - used as the "Standings" tab content when wp-etr's
	 * 'etr_event_tabs' filter renders this block
	 * (WPMTM_Frontend::filter_etr_event_tabs()). The tab's own label already
	 * says "Standings", so the per-section H3s render_section_standings()
	 * prints are the only headings here (docs/SPEC.md, "Decisions
	 * (2026-07-11, event-page tabs)": "results" implies the event ended,
	 * but standings are live during play). The wall chart is deliberately
	 * left out - it gets its own tab, see render_wall_chart_only() below.
	 *
	 * Sole caller is WPMTM_Frontend::filter_etr_event_tabs(), which defines
	 * DONOTCACHEPAGE whenever the current user can WPMTM_CAPABILITY (same
	 * condition as its own "Round entry" tab) - so, unlike
	 * render_public_block()'s $show_td_note default, the TD-only
	 * incomplete-section note is always allowed to render here: this
	 * render is guaranteed fresh for any user it could show up for
	 * (docs/SPEC.md, "Decisions (2026-07-18, rank by score until
	 * complete)").
	 *
	 * @param object $tournament
	 */
	public function render_standings_only( $tournament ) {
		$this->ensure_attendee_ids_linked( $tournament );
		// Print is the first button IN the command row (2026-07-22: owner
		// wants it immediately before "Edit tournament", not its own toolbar
		// stacked above, which was the 2026-07-21 arrangement).
		$this->render_td_command_row( $tournament, 'standings' );

		$sections = WPMTM_Repository::get_sections( $tournament->id );
		if ( empty( $sections ) ) {
			echo '<p>' . esc_html__( 'No sections have been set up yet.', 'wp-tournament-manager' ) . '</p>';
			return;
		}
		$show_photos = (bool) $tournament->show_photos;
		foreach ( $sections as $section ) {
			$this->render_section_standings( $section, $show_photos, false, true );
		}
	}

	/**
	 * TD command row (docs/SPEC.md, 2026-07-18): "Edit tournament" (the
	 * renamed former "Switch to tournament" link to the admin edit screen),
	 * "Lock tournament"/"Unlock tournament" (a real nonced POST form
	 * targeting the same `wpmtm_toggle_lock` admin-post action
	 * `WPMTM_Admin::render_lock_control()`/`handle_toggle_lock()` already
	 * use from the admin edit screen - this is a second, front-end-facing
	 * instance of that same control, not a new action. `handle_toggle_lock()`
	 * (docs/SPEC.md, 2026-07-21) redirects back to `wp_get_referer()` when
	 * one is present, so a front-end click returns here, to the event page
	 * that referred it, not into wp-admin - it only falls back to the
	 * admin edit screen when there is no referer to return to.), "Edit
	 * event" (the linked TEC event's own edit-post screen), and "Export"
	 * (docs/SPEC.md, 2026-07-21 - a direct link to the tournament edit
	 * page's export box, shown only once there is actually something
	 * ready to export: rated, and every section's rounds are all entered -
	 * see rated_and_complete() below). Shown at the top of every TD-facing
	 * render this class and WPMTM_Frontend_TD produce (the no-tabs inline
	 * fallback, and the Standings/Wall chart/Round entry tabs), gated on
	 * WPMTM_CAPABILITY so a public visitor never sees it - never shown to
	 * an anonymous or non-TD visitor.
	 *
	 * @param object $tournament
	 * @param string $print_scope
	 * @param string $leading_html Optional pre-rendered, already-escaped HTML
	 *                             inserted as the FIRST children of the
	 *                             toolbar, before Print/Edit/Lock/Export. Used
	 *                             by the Rounds tab to fold its Pair/Results
	 *                             mode switch into this same row (owner
	 *                             decision, 2026-08-14) rather than stacking a
	 *                             second row above it - this method stays
	 *                             generic (no knowledge of pairing/rounds) by
	 *                             taking the markup as a string rather than a
	 *                             mode value.
	 */
	public function render_td_command_row( $tournament, $print_scope = '', $leading_html = '' ) {
		if ( ! $tournament || ! WPMTM_Roles::user_can_manage_tournament( $tournament ) ) {
			return;
		}
		// The setup guide panel is NO LONGER rendered here (2026-07-22): it now
		// renders above wp-etr's tab nav via the 'etr_before_tabs' slot
		// (WPMTM_Frontend::render_event_setup_guide()), so it stays visible on
		// every tab instead of being buried inside whichever tab's command row
		// rendered first. This command row (Print / Edit / Lock / Export) is
		// still legitimately per-tab - its Print scope differs by tab.
		$edit_url        = add_query_arg( array( 'page' => 'wpmtm-edit', 'id' => $tournament->id ), admin_url( 'admin.php' ) );
		$locked          = (bool) $tournament->locked;
		$edit_event_url  = $tournament->event_post_id ? get_edit_post_link( $tournament->event_post_id, 'raw' ) : '';
		?>
		<div class="wpmtm-toolbar wpmtm-td-command-row no-print">
			<?php
			if ( '' !== $leading_html ) {
				echo $leading_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- caller-built markup, already escaped at the point each value was output (see WPMTM_Frontend_TD_Panel::rounds_mode_toggle_html()); no user input reaches this string un-escaped.
			}
			// Owner request (2026-07-22): on Standings AND Wall chart, Print
			// is the FIRST command, immediately before "Edit tournament" -
			// not its own toolbar stacked above (the 2026-07-21 arrangement).
			// $print_scope is only ever passed by render_standings_only() and
			// render_wall_chart_only()'s main (has-results) path below; every
			// other caller leaves it '' and gets no button here, unchanged -
			// including wall chart's own empty-sections/no-results early
			// returns, matching the pre-2026-07-22 behavior of showing no
			// print button when there is nothing to print.
			if ( '' !== $print_scope ) {
				$this->render_print_button( '', $print_scope );
			}
			?>
			<a href="<?php echo esc_url( $edit_url ); ?>" class="wpmtm-btn"><?php esc_html_e( 'Edit tournament', 'wp-tournament-manager' ); ?></a>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpmtm-td-command-lock-form">
				<?php wp_nonce_field( 'wpmtm_toggle_lock_' . $tournament->id, 'wpmtm_toggle_lock_nonce' ); ?>
				<input type="hidden" name="action" value="wpmtm_toggle_lock">
				<input type="hidden" name="tournament_id" value="<?php echo esc_attr( $tournament->id ); ?>">
				<input type="hidden" name="wpmtm_return_hash" value="">
				<button type="submit" class="wpmtm-btn">
					<?php echo $locked ? esc_html__( 'Unlock tournament', 'wp-tournament-manager' ) : esc_html__( 'Lock tournament', 'wp-tournament-manager' ); ?>
				</button>
			</form>
			<?php if ( $edit_event_url ) : ?>
				<a href="<?php echo esc_url( $edit_event_url ); ?>" class="wpmtm-btn"><?php esc_html_e( 'Edit event', 'wp-tournament-manager' ); ?></a>
			<?php endif; ?>
			<?php if ( $this->rated_and_complete( $tournament ) ) : ?>
				<a href="<?php echo esc_url( $edit_url . '#wpmtm-export' ); ?>" class="wpmtm-btn"><?php esc_html_e( 'Export', 'wp-tournament-manager' ); ?></a>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Whether there is a finished USCF export to jump to: rated, and every
	 * section has both a round count set and every one of those rounds
	 * entered (docs/SPEC.md, 2026-07-21) - the same completeness signal
	 * WPMTM_Wizard::build_state()'s 'sections_complete' uses for the setup
	 * guide's own 'export' step. Both now ask
	 * WPMTM_Round_Selector::section_complete() rather than each keeping its
	 * own copy of the rule, which is where the two had already started to
	 * drift apart in wording (audit item 54). An unrated
	 * tournament, or one with rounds still outstanding, has nothing
	 * meaningful to export yet, so the command link is not shown rather
	 * than linking to an export box that will only report errors.
	 *
	 * @param object $tournament
	 * @return bool
	 */
	protected function rated_and_complete( $tournament ) {
		if ( ! (bool) $tournament->rated ) {
			return false;
		}
		$sections = WPMTM_Repository::get_sections( $tournament->id );
		if ( empty( $sections ) ) {
			return false;
		}
		$section_ids = wp_list_pluck( $sections, 'id' );
		// Scored, not merely paired - see rounds_fully_scored()'s docblock.
		// Offering the USCF export for an event whose rounds have only been
		// paired would hand the TD an export full of missing results.
		$rounds_map  = WPMTM_Repository::rounds_fully_scored_by_sections( $section_ids );
		foreach ( $sections as $section ) {
			$sid = (int) $section->id;
			if ( ! WPMTM_Round_Selector::section_complete( $section->tot_rnds, isset( $rounds_map[ $sid ] ) ? $rounds_map[ $sid ] : array() ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Wall chart only (every section) - the counterpart to
	 * render_standings_only() above, used as the "Wall chart" tab content.
	 * Each section's chart renders as a bare table
	 * (render_wall_chart_table()) rather than wrapped in the
	 * <details>/<summary> disclosure render_wall_chart() uses on the
	 * no-tabs paths (render_public_block(): the [wpmtm_standings] shortcode
	 * and the "wp-etr absent, or active but its filter never fired this
	 * request" inline fallback) - the Wall chart tab itself is already the
	 * disclosure, so nesting a second one inside it would be redundant.
	 * Sections with no games or byes recorded yet are skipped, same as
	 * render_wall_chart()'s own empty-crosstable guard.
	 *
	 * @param object $tournament
	 */
	public function render_wall_chart_only( $tournament ) {
		$this->ensure_attendee_ids_linked( $tournament );
		$sections = WPMTM_Repository::get_sections( $tournament->id );
		if ( empty( $sections ) ) {
			$this->render_td_command_row( $tournament );
			echo '<p>' . esc_html__( 'No sections have been set up yet.', 'wp-tournament-manager' ) . '</p>';
			return;
		}
		$show_photos = (bool) $tournament->show_photos;
		$rendered    = false;

		ob_start();
		foreach ( $sections as $section ) {
			list( $players, $games, $byes ) = $this->section_data_arrays( $section );
			if ( empty( $games ) && empty( $byes ) ) {
				continue;
			}
			$crosstable = WPMTM_Scoring::crosstable( $players, $games, $byes );
			if ( empty( $crosstable ) ) {
				continue;
			}
			$max_round = (int) $section->tot_rnds;
			foreach ( $crosstable as $row ) {
				foreach ( array_keys( $row['rounds'] ) as $r ) {
					$max_round = max( $max_round, (int) $r );
				}
			}
			$rendered = true;
			?>
			<div class="wpmtm-wall-chart-section">
				<h3><?php echo esc_html( $section->sec_name ); ?></h3>
				<?php $this->render_wall_chart_table( $players, $games, $byes, $max_round, $show_photos ); ?>
			</div>
			<?php
		}
		$sections_html = ob_get_clean();

		if ( ! $rendered ) {
			$this->render_td_command_row( $tournament );
			echo '<p>' . esc_html__( 'No results yet.', 'wp-tournament-manager' ) . '</p>';
			return;
		}

		// Print is the first button IN the command row (2026-07-22: owner
		// wants it immediately before "Edit tournament", not its own toolbar
		// stacked above, which was the 2026-07-21 arrangement).
		$this->render_td_command_row( $tournament, 'wall-chart' );
		echo $sections_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- entirely this method's own escaped output, captured above.
	}

	/**
	 * Print toolbar, originally shown above the Wall chart tab's tables and
	 * now (Change 3) reused verbatim - same markup, same [data-wpmtm-print]
	 * binding - above the Standings tab (render_standings_only() below) and
	 * WPMTM_Frontend_TD's Round entry tab, so every phone-facing view gets
	 * the same one-tap Print button. Visible to every visitor on Standings/
	 * Wall chart (parity with wp-etr's public Print button on its
	 * Registrations tab - assets/etr-registrations.css's .etr-btn /
	 * .etr-toolbar, includes/class-etr-registrations.php's
	 * render_toolbar()); the Round entry tab's copy is only ever reached by
	 * a WPMTM_CAPABILITY user, since render_td_block() itself is gated.
	 * assets/wpmtm-frontend.js binds the click on [data-wpmtm-print] to
	 * window.print(); "no-print" hides the button itself when actually
	 * printing.
	 *
	 * Public (not protected) so WPMTM_Frontend_TD can call
	 * WPMTM_Frontend_Public::instance()->render_print_toolbar() for its own
	 * "Print pairing sheet" button instead of duplicating this markup - the
	 * same instance-sharing pattern render_avatar()/format_score() already
	 * use for cross-class reuse.
	 *
	 * $scope (docs/SPEC.md, 2026-07-18/2026-07-21, clean print output):
	 * assets/wpmtm-frontend.js opens a clean popup print window containing
	 * only the relevant tables, mirroring wp-etr's own Registrations Print
	 * button in assets/etr-tabs.js, so the WordPress theme's header/
	 * sidebar/footer never reach the printed page. 'standings' and
	 * 'wall-chart' print wp-etr's whole tab panel (`#etr-panel-{scope}`);
	 * 'pairing-sheet' (WPMTM_Frontend_TD's "Print pairing sheet" button)
	 * prints just the clicked button's own section's `.wpmtm-pairing-aid`
	 * (found via the button's closest `.wpmtm-td-section-panel`, since a
	 * multi-section tournament renders one `.wpmtm-pairing-aid` per
	 * section and a plain id lookup would always grab the first one). '',
	 * the default, is plain in-page `window.print()`.
	 *
	 * @param string $label Button label; defaults to "Print" (Wall chart's
	 *                       original wording). WPMTM_Frontend_TD passes
	 *                       "Print pairing sheet" instead.
	 * @param string $scope '' (default, plain window.print()), 'standings',
	 *                      'wall-chart', or 'pairing-sheet'.
	 */
	public function render_print_toolbar( $label = '', $scope = '' ) {
		// Owner request (2026-07-21): Print is no longer public on Standings/
		// Wall chart (a reversal of the original "parity with wp-etr's own
		// public Print button" design) - TD-only everywhere this renders.
		// The pairing-sheet caller (WPMTM_Frontend_TD) is already
		// capability-gated on its own, so this is a no-op there, not new
		// behavior. wp-etr's own Registrations-tab Print button is a
		// different plugin's own markup - out of reach here, TM-only edits.
		if ( ! current_user_can( WPMTM_CAPABILITY ) ) {
			return;
		}
		?>
		<div class="wpmtm-toolbar no-print">
			<?php $this->render_print_button( $label, $scope ); ?>
		</div>
		<?php
	}

	/**
	 * The Print `<button>` itself, no wrapping div - factored out
	 * (2026-07-22) so render_td_command_row() can embed it as the FIRST
	 * button in its own row (the Standings/Wall chart "Print immediately
	 * before Edit tournament" request), while render_print_toolbar() above
	 * keeps wrapping it in a standalone toolbar div for its other callers
	 * (the pairing-sheet button, and any future plain window.print() use).
	 * Assumes the caller already ran the WPMTM_CAPABILITY check -
	 * render_td_command_row() has already returned by the time it would
	 * reach this, same as render_print_toolbar() above does before it.
	 *
	 * @param string $label Button label; defaults to "Print".
	 * @param string $scope '' (plain window.print()), 'standings',
	 *                      'wall-chart', or 'pairing-sheet'.
	 */
	private function render_print_button( $label = '', $scope = '' ) {
		if ( '' === $label ) {
			$label = __( 'Print', 'wp-tournament-manager' );
		}
		?>
		<button
			type="button"
			class="wpmtm-btn wpmtm-print"
			data-wpmtm-print
			<?php if ( '' !== $scope ) : ?>
				data-wpmtm-print-scope="<?php echo esc_attr( $scope ); ?>"
			<?php endif; ?>
		><?php echo esc_html( $label ); ?></button>
		<?php
	}



	/**
	 * Formats a score to one decimal place, e.g. 2 -> "2.0", 2.5 -> "2.5".
	 * Static: pure formatting with no dependency on instance state, shared
	 * with WPMTM_Frontend_TD's pairing aid and withdrawn-players table
	 * (called there as WPMTM_Frontend_Public::format_score()) so there is
	 * one formatter, not two.
	 */
	public static function format_score( $score ) {
		return number_format( (float) $score, 1 );
	}

	/**
	 * Renders one player's avatar cell content: the event registration photo
	 * (ETECF's 'etecf-avatar' image size, 128x128 hard-cropped, shown here at
	 * a fixed 40x40) when $photo_id resolves to a real, still-existing
	 * attachment, otherwise a neutral silhouette - so every row in a
	 * show_photos-enabled table gets an image and the column never has to
	 * special-case a missing photo. This is the only place in the plugin
	 * that knows what a "photo" is: WPMTM_Scoring / WPMTM_Pairing_Aid and
	 * the rest of the pure, WordPress-free scoring/pairing classes never see
	 * photo_id at all, only the WP-layer player arrays render_avatar() is
	 * called against.
	 *
	 * Public static (like format_score() above) rather than truly private,
	 * so WPMTM_Frontend_TD's pairing aid can call
	 * WPMTM_Frontend_Public::render_avatar() directly for identical output -
	 * pure markup with no instance state, so there is no reason to route it
	 * through WPMTM_Frontend_Public::instance().
	 *
	 * @param int|null $photo_id Attachment id, or null/0 for no photo on file.
	 * @return string Safe HTML: an <img> tag or an inline silhouette <svg>.
	 */
	public static function render_avatar( $photo_id ) {
		$photo_id = (int) $photo_id;

		if ( $photo_id > 0 ) {
			$image_html = wp_get_attachment_image(
				$photo_id,
				'etecf-avatar',
				false,
				array(
					'width'  => 40,
					'height' => 40,
					'class'  => 'wpmtm-avatar',
					'alt'    => '',
				)
			);
			if ( '' !== $image_html ) {
				return $image_html;
			}
		}

		// Page-weight fix: this used to be a ~330-byte inline <svg>, repeated
		// verbatim for every no-photo player - the shape itself now lives
		// once in assets/wpmtm-frontend.css (.wpmtm-avatar--placeholder, a
		// data-URI background image), so each occurrence here is just this
		// small empty <span>. No user input other than the already-escaped,
		// translatable aria-label, so this is safe to build as a plain
		// string and echo at the call sites without further escaping.
		return '<span role="img" aria-label="' . esc_attr__( 'No profile photo', 'wp-tournament-manager' ) . '" class="wpmtm-avatar wpmtm-avatar--placeholder"></span>';
	}

	// -----------------------------------------------------------------
	// Data helpers (also used by WPMTM_Frontend_TD).
	// -----------------------------------------------------------------

	/**
	 * Converts a section's $wpdb rows into the plain associative arrays
	 * WPMTM_Scoring / WPMTM_Pairing_Aid / WPMTM_Round_Entry expect (those
	 * are pure classes with no $wpdb dependency, per tests/run-tests.php's
	 * zero-WP design).
	 *
	 * Memoized per section id for the lifetime of the request. A single TD
	 * page view calls this twice per section - once for the public
	 * standings pass (render_section_standings() above) and once for the
	 * TD panel pass (WPMTM_Frontend_TD::render_section_td_panel()) - which
	 * without this cache would cost 3 duplicate queries per section on
	 * every such view. The cache cannot go stale within one render: the
	 * only thing that writes this data is WPMTM_Repository::replace_round(),
	 * and WPMTM_Frontend_TD::handle_save_round() always ends its request
	 * with a redirect after calling it (never a second read in the same
	 * request), so a fresh request always starts with an empty memo.
	 *
	 * Public so WPMTM_Frontend_TD can call WPMTM_Frontend_Public::instance()
	 * ->section_data_arrays() and reuse the same cached result instead of
	 * fetching independently - the simplest way to share one memo across
	 * both classes' render passes, since each is otherwise its own
	 * singleton with its own instance state.
	 *
	 * @return array array( $players, $games, $byes ).
	 */
	public function section_data_arrays( $section ) {
		$section_id = (int) $section->id;

		if ( isset( $this->section_data_memo[ $section_id ] ) ) {
			return $this->section_data_memo[ $section_id ];
		}

		$players = $this->map_players( $section_id );

		$games = array();
		foreach ( WPMTM_Repository::get_games( $section->id ) as $g ) {
			$games[] = array(
				'round'            => (int) $g->round,
				'board'            => (int) $g->board,
				'white_player_id'  => (int) $g->white_player_id,
				'black_player_id'  => (int) $g->black_player_id,
				'result'           => $g->result,
			);
		}

		$byes = array();
		foreach ( WPMTM_Repository::get_byes_for_section( $section->id ) as $b ) {
			$byes[] = array(
				'player_id' => (int) $b->player_id,
				'round'     => (int) $b->round,
				'type'      => $b->type,
			);
		}

		$this->section_data_memo[ $section_id ] = array( $players, $games, $byes );

		return $this->section_data_memo[ $section_id ];
	}

	/**
	 * Shared player-row mapping: turns a section's wpmtm_players rows into
	 * the plain associative array WPMTM_Scoring / WPMTM_Round_Entry expect.
	 * Used by section_data_arrays() above and, directly (not through the
	 * memoized triple, to avoid fetching games/byes it does not need), by
	 * WPMTM_Frontend_TD::handle_save_round() - one mapping implementation
	 * instead of two.
	 *
	 * 'photo_id' is carried here purely as a passthrough for the WP-layer
	 * renderers (render_avatar() above, and WPMTM_Frontend_TD's pairing
	 * aid): WPMTM_Scoring::standings()/crosstable() merge each player row
	 * as-is, so it survives into their output untouched without those pure
	 * classes ever needing to know it exists. WPMTM_Pairing_Aid::build()
	 * rebuilds its own row shape instead of merging, so it does drop
	 * 'photo_id' - WPMTM_Frontend_TD looks it up from this same $players
	 * array by player id rather than teaching that pure class about photos.
	 *
	 * @return array
	 */
	public function map_players( $section_id ) {
		$players = array();
		foreach ( WPMTM_Repository::get_players( $section_id ) as $p ) {
			$players[] = array(
				'id'                     => (int) $p->id,
				'pair_num'               => (int) $p->pair_num,
				'name'                   => $p->name,
				'rating'                 => $p->rating,
				// USCF member id, carried through purely so the editor-only
				// player card can tell the two "no linked registration" cases
				// apart (audit item 56): a player with no member id can never
				// be linked by backfill_attendee_ids(), which matches on member
				// id alone, so the card says to add one rather than telling the
				// TD to re-import. Never rendered for the public - the card
				// itself is ownership-gated.
				'mem_id'                 => null !== $p->mem_id ? (string) $p->mem_id : '',
				'withdrawn_after_round'  => null !== $p->withdrawn_after_round ? (int) $p->withdrawn_after_round : null,
				'photo_id'               => null !== $p->photo_id ? (int) $p->photo_id : null,
				// Change 2 (family-name-first display option): per-player
				// preference (wpmtm_players.family_name_first, DB_VERSION
				// 0.1.7), carried through purely as a passthrough for
				// WPMTM_Name::display()'s $family_first arg at every render
				// call site, the same way 'photo_id' above is carried for
				// render_avatar() - see this method's own docblock.
				'family_name_first'     => (bool) $p->family_name_first,
				// Family avoidance (docs/SPEC.md, 2026-07-14): per-player
				// family key (wpmtm_players.family_key, DB_VERSION 0.1.8 -
				// normalized parent email from ETR import, or a TD override
				// from the roster editor), carried through the same way for
				// WPMTM_Pairing_Suggest::same_family() to consume; null
				// when unset, never an empty string, so same_family()'s
				// "both non-empty" check works without this array needing
				// its own null-coalescing.
				'family_key'            => $p->family_key,
				// Registrant note (docs/SPEC.md, "Decisions (2026-07-17,
				// import the registrant note)"): the free-text ETECF
				// "Additional information" note carried through at import,
				// passed through here the same way photo_id/family_key
				// are so WPMTM_Frontend_TD::render_byes_area() can show it
				// next to the player's name - the lightweight alternative
				// to a structured byes-by-round field. '' when unset
				// (never null), so a render site's '' !== $notes check
				// works without its own null-coalescing, matching how
				// wpmtm_players.notes itself is nullable in the DB but a
				// bare string everywhere it is displayed.
				'notes'                 => null !== $p->notes ? (string) $p->notes : '',
				// Source ETECF/Event-Tickets attendee post id (DB_VERSION
				// 0.1.14), or null for CSV/manually-added players. Carried
				// through so the editor-only player card can link back to the
				// live registration (edit form, admin note, contact email);
				// never emitted for the public.
				'attendee_id'           => isset( $p->attendee_id ) && null !== $p->attendee_id ? (int) $p->attendee_id : null,
			);
		}
		return $players;
	}

}
