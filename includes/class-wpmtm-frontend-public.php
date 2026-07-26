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
 * before this class runs) and never gates on WPMTM_CAPABILITY (this is
 * public data, shown to every visitor).
 */
class WPMTM_Frontend_Public {

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
	 */
	public function render_td_command_row( $tournament, $print_scope = '' ) {
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
	 * guide's own 'export' step, re-derived here rather than shared
	 * because that method is protected on a different class or (against
	 * the underlying repository data) would sit oddly. An unrated
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
		$rounds_map  = WPMTM_Repository::rounds_with_results_by_sections( $section_ids );
		foreach ( $sections as $section ) {
			$sid       = (int) $section->id;
			$tot_rnds  = (int) $section->tot_rnds;
			$done      = isset( $rounds_map[ $sid ] ) ? count( $rounds_map[ $sid ] ) : 0;
			if ( $tot_rnds < 1 || $done < $tot_rnds ) {
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
	 * @param object $section
	 * @param bool   $show_photos         Tournament's show_photos flag; when
	 *                                    true, the standings table and the
	 *                                    wall chart below it each gain a
	 *                                    leading avatar cell per row (see
	 *                                    render_avatar()). When false the
	 *                                    column is not emitted at all -
	 *                                    today's exact layout.
	 * @param bool   $include_wall_chart  Whether to render the wall chart
	 *                                    (wrapped in its <details> disclosure)
	 *                                    directly under the standings table.
	 *                                    False from render_standings_only()
	 *                                    above, which puts the wall chart in
	 *                                    its own tab instead; true (default)
	 *                                    everywhere else, preserving the
	 *                                    original combined layout.
	 * @param bool   $show_td_note        Whether the section-not-complete
	 *                                    note is allowed to render for a
	 *                                    WPMTM_CAPABILITY user here. Default
	 *                                    false; both callers of
	 *                                    render_public_block() and the sole
	 *                                    caller of render_standings_only()
	 *                                    decide the right value for their
	 *                                    own caching guarantees - see those
	 *                                    methods' docblocks. Never shown to
	 *                                    the public regardless of this flag:
	 *                                    still gated on current_user_can()
	 *                                    below.
	 */
	protected function render_section_standings( $section, $show_photos = false, $include_wall_chart = true, $show_td_note = false ) {
		list( $players, $games, $byes ) = $this->section_data_arrays( $section );
		?>
		<div class="wpmtm-section-standings">
			<h3><?php echo esc_html( $section->sec_name ); ?></h3>
			<?php if ( empty( $games ) && empty( $byes ) ) : ?>
				<p><?php esc_html_e( 'No results yet.', 'wp-tournament-manager' ); ?></p>
			<?php else : ?>
				<?php
				$standings = WPMTM_Scoring::standings( $players, $games, $byes );

				// Same completeness test as WPMTM_Wizard::build_state() (docs/SPEC.md,
				// "Decisions (2026-07-18, rank by score until complete)"): a
				// tot_rnds of 0 is never complete, and every planned round
				// must already have at least one game or bye recorded.
				// Mid-tournament, tiebreaks are noise (a round-1 "leader" is
				// really just the player who happened to draw the strongest
				// opponent so far), so ranks group on score alone until
				// then - the tiebreak columns keep displaying throughout.
				$tot_rnds          = (int) $section->tot_rnds;
				$rounds_done       = WPMTM_Repository::rounds_with_results( $section->id );
				$section_complete  = $tot_rnds >= 1 && count( $rounds_done ) >= $tot_rnds;
				$ranks             = WPMTM_Scoring::ranks_for( $standings, $section_complete );

				$pair_num_by_id = array();
				$max_round      = (int) $section->tot_rnds;
				foreach ( $players as $p ) {
					$pair_num_by_id[ $p['id'] ] = $p['pair_num'];
				}
				foreach ( $standings as $row ) {
					foreach ( array_keys( $row['rounds'] ) as $r ) {
						$max_round = max( $max_round, (int) $r );
					}
				}
				?>
				<table class="wpmtm-standings-table wpmtm-table">
					<thead>
						<tr>
							<?php if ( $show_photos ) : ?>
								<th class="wpmtm-col-photo"><span class="screen-reader-text"><?php esc_html_e( 'Photo', 'wp-tournament-manager' ); ?></span></th>
							<?php endif; ?>
							<th><?php esc_html_e( 'Rank', 'wp-tournament-manager' ); ?></th>
							<th><?php esc_html_e( 'Name', 'wp-tournament-manager' ); ?></th>
							<th><?php esc_html_e( 'Score', 'wp-tournament-manager' ); ?></th>
							<th title="<?php esc_attr_e( 'Modified Median', 'wp-tournament-manager' ); ?>"><?php esc_html_e( 'MM', 'wp-tournament-manager' ); ?></th>
							<th title="<?php esc_attr_e( 'Solkoff', 'wp-tournament-manager' ); ?>"><?php esc_html_e( 'Sol', 'wp-tournament-manager' ); ?></th>
							<th title="<?php esc_attr_e( 'Cumulative', 'wp-tournament-manager' ); ?>"><?php esc_html_e( 'Cum', 'wp-tournament-manager' ); ?></th>
							<th title="<?php esc_attr_e( 'Cumulative of Opposition', 'wp-tournament-manager' ); ?>"><?php esc_html_e( 'CO', 'wp-tournament-manager' ); ?></th>
							<?php for ( $r = 1; $r <= $max_round; $r++ ) : ?>
								<th>
									<?php
									printf(
										/* translators: %d: round number */
										esc_html__( 'Rd %d', 'wp-tournament-manager' ),
										(int) $r
									);
									?>
								</th>
							<?php endfor; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $standings as $i => $row ) : ?>
							<tr>
								<?php if ( $show_photos ) : ?>
									<td class="wpmtm-avatar-cell">
										<?php
										echo self::render_avatar( isset( $row['photo_id'] ) ? $row['photo_id'] : null ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_avatar() returns either wp_get_attachment_image()'s own escaped markup or a static, hardcoded silhouette SVG with an escaped aria-label; see that method's docblock.
										?>
									</td>
								<?php endif; ?>
								<td><?php echo esc_html( $ranks[ $i ] ); ?></td>
								<td>
									<?php echo esc_html( WPMTM_Name::display( $row['name'], ! empty( $row['family_name_first'] ) ) ); ?>
									<?php if ( isset( $row['withdrawn_after_round'] ) && null !== $row['withdrawn_after_round'] ) : ?>
										<?php
										printf(
											/* translators: %d: round number after which the player withdrew */
											' ' . esc_html__( '(withdrew after round %d)', 'wp-tournament-manager' ),
											(int) $row['withdrawn_after_round']
										);
										?>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( self::format_score( $row['score'] ) ); ?></td>
								<td><?php echo esc_html( self::format_score( $row['modified_median'] ) ); ?></td>
								<td><?php echo esc_html( self::format_score( $row['solkoff'] ) ); ?></td>
								<td><?php echo esc_html( self::format_score( $row['cumulative'] ) ); ?></td>
								<td><?php echo esc_html( self::format_score( $row['cumulative_opp'] ) ); ?></td>
								<?php for ( $r = 1; $r <= $max_round; $r++ ) : ?>
									<?php $cell = isset( $row['rounds'][ $r ] ) ? $row['rounds'][ $r ] : null; ?>
									<td><?php echo esc_html( $this->compact_round_result( $cell, $pair_num_by_id ) ); ?></td>
								<?php endfor; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="wpmtm-standings-help description">
					<?php esc_html_e( 'Ties in score are broken left to right by Modified Median, then Solkoff, then Cumulative, then Cumulative of Opposition (USCF rule 34E).', 'wp-tournament-manager' ); ?>
				</p>
				<?php if ( $show_td_note && ! $section_complete && current_user_can( WPMTM_CAPABILITY ) ) : ?>
					<p class="wpmtm-standings-td-note">
						<?php esc_html_e( 'TD note (not shown to the public): ranked by score alone until every round in this section is entered. The tiebreak columns above will then decide placement.', 'wp-tournament-manager' ); ?>
					</p>
				<?php endif; ?>
				<?php if ( $include_wall_chart ) : ?>
					<?php $this->render_wall_chart( $players, $games, $byes, $max_round, $show_photos ); ?>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Bare wall chart <table>: WPMTM_Scoring::crosstable() rendered as one
	 * row per player in pair_num order (not rank), with the per-round
	 * compact result cell plus the running score under it and a final total
	 * column. No wrapper of any kind - callers decide how (or whether) to
	 * frame it: render_wall_chart() below wraps it in a <details> disclosure
	 * for the no-tabs paths, while render_wall_chart_only() renders it
	 * directly, since the Wall chart tab itself is already the disclosure.
	 * Public data, never gated by WPMTM_CAPABILITY; nothing here writes
	 * anything (docs/SPEC.md, "Decisions (2026-07-10, wall chart)").
	 *
	 * @param bool $show_photos Tournament's show_photos flag; see
	 *                          render_section_standings() above.
	 */
	protected function render_wall_chart_table( array $players, array $games, array $byes, $max_round, $show_photos = false ) {
		$crosstable = WPMTM_Scoring::crosstable( $players, $games, $byes );
		if ( empty( $crosstable ) ) {
			return;
		}
		?>
		<table class="wpmtm-wall-chart-table wpmtm-table">
			<thead>
				<tr>
					<?php if ( $show_photos ) : ?>
						<th class="wpmtm-col-photo"><span class="screen-reader-text"><?php esc_html_e( 'Photo', 'wp-tournament-manager' ); ?></span></th>
					<?php endif; ?>
					<th><?php esc_html_e( '#', 'wp-tournament-manager' ); ?></th>
					<th><?php esc_html_e( 'Name', 'wp-tournament-manager' ); ?></th>
					<?php for ( $r = 1; $r <= $max_round; $r++ ) : ?>
						<th>
							<?php
							printf(
								/* translators: %d: round number */
								esc_html__( 'Rd %d', 'wp-tournament-manager' ),
								(int) $r
							);
							?>
						</th>
					<?php endfor; ?>
					<th><?php esc_html_e( 'Total', 'wp-tournament-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $crosstable as $row ) : ?>
					<tr>
						<?php if ( $show_photos ) : ?>
							<td class="wpmtm-avatar-cell">
								<?php
								echo self::render_avatar( isset( $row['photo_id'] ) ? $row['photo_id'] : null ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_avatar() returns either wp_get_attachment_image()'s own escaped markup or a static, hardcoded silhouette SVG with an escaped aria-label; see that method's docblock.
								?>
							</td>
						<?php endif; ?>
						<td><?php echo esc_html( $row['pair_num'] ); ?></td>
						<td><?php echo esc_html( WPMTM_Name::display( $row['name'], ! empty( $row['family_name_first'] ) ) ); ?></td>
						<?php for ( $r = 1; $r <= $max_round; $r++ ) : ?>
							<?php $cell = isset( $row['rounds'][ $r ] ) ? $row['rounds'][ $r ] : null; ?>
							<td>
								<?php if ( null !== $cell ) : ?>
									<?php echo esc_html( $cell['cell'] ); ?><br>
									<span class="wpmtm-wall-chart-running"><?php echo esc_html( self::format_score( $cell['running'] ) ); ?></span>
								<?php endif; ?>
							</td>
						<?php endfor; ?>
						<td><?php echo esc_html( self::format_score( $row['score'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Public, read-only wall chart, collapsed by default behind a native
	 * <details> element so it does not compete with the standings table
	 * above it on the no-tabs paths (render_public_block(): the
	 * [wpmtm_standings] shortcode and the "wp-etr absent, or its filter
	 * never fired this request" inline fallback). When wp-etr tabs are
	 * active the wall chart gets its own tab instead and uses
	 * render_wall_chart_table() directly - see render_wall_chart_only().
	 *
	 * @param bool $show_photos Tournament's show_photos flag; see
	 *                          render_section_standings() above.
	 */
	protected function render_wall_chart( array $players, array $games, array $byes, $max_round, $show_photos = false ) {
		ob_start();
		$this->render_wall_chart_table( $players, $games, $byes, $max_round, $show_photos );
		$table_html = trim( ob_get_clean() );
		if ( '' === $table_html ) {
			return; // crosstable was empty - see render_wall_chart_table()'s guard.
		}
		?>
		<details class="wpmtm-wall-chart">
			<summary><?php esc_html_e( 'Wall chart', 'wp-tournament-manager' ); ?></summary>
			<?php echo $table_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $table_html is entirely render_wall_chart_table()'s own escaped output, captured above. ?>
		</details>
		<?php
	}

	/** Compact per-round cell: token letter + opponent pair_num (no color letter), e.g. "W12", "L3", "B". */
	protected function compact_round_result( $round_data, array $pair_num_by_id ) {
		if ( null === $round_data ) {
			return '';
		}
		$text = $round_data['token_result'];
		if ( null !== $round_data['opponent_player_id'] && isset( $pair_num_by_id[ $round_data['opponent_player_id'] ] ) ) {
			$text .= $pair_num_by_id[ $round_data['opponent_player_id'] ];
		}
		return $text;
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
			);
		}
		return $players;
	}

	// -----------------------------------------------------------------
	// Public Pairings tab (docs/SPEC.md, 2026-07-23, player-facing pairings).
	// -----------------------------------------------------------------

	/**
	 * The public Pairings tab: a view-only board list per section for the
	 * latest fully-paired ("published") round, with a read-only selector back
	 * to earlier published rounds. Unlike the TD Rounds tab this renders for
	 * every visitor, so it never exposes an entry form, the pairing aid, or
	 * any command that changes state - just who plays whom, on which board,
	 * with which color, and the result once the TD has entered it.
	 *
	 * A round only appears here once WPMTM_Round_Selector::published_rounds()
	 * considers it fully paired, so a draft the TD is mid-entry never flashes
	 * to players. When nothing is published yet, each section shows an empty
	 * state rather than a blank tab.
	 *
	 * @param object $tournament
	 */
	public function render_pairings_only( $tournament ) {
		$sections = WPMTM_Repository::get_sections( $tournament->id );
		if ( empty( $sections ) ) {
			echo '<p>' . esc_html__( 'No sections have been set up yet.', 'wp-tournament-manager' ) . '</p>';
			return;
		}

		// Full TD command bar (2026-07-23), same call Standings/Wall chart use:
		// self-gates on WPMTM_CAPABILITY, so a public visitor sees no toolbar
		// here at all (previously the "Print pairings" button was shown to
		// everyone). Scope "pairings" clones #etr-panel-pairings with
		// .no-print / .wpmtm-toolbar stripped for the print, same as before -
		// only the board tables print, not the toolbar or round selector.
		$this->render_td_command_row( $tournament, 'pairings' );

		$multi = count( $sections ) > 1;
		foreach ( $sections as $section ) {
			$this->render_pairings_section( $section, $multi );
		}
	}

	/**
	 * One section's board list for its selected published round. The selected
	 * round defaults to the latest published one; a ?wpmtm_pround_{id}=N query
	 * param (the read-only selector's own links) picks an earlier published
	 * round, and is ignored unless it names a genuinely published round, so a
	 * hand-edited URL can never surface an unpublished draft.
	 *
	 * @param object $section
	 * @param bool   $multi   Whether the tournament has more than one section
	 *                        (adds the section-name heading, matching
	 *                        render_standings_only()'s per-section layout).
	 */
	protected function render_pairings_section( $section, $multi ) {
		list( $players, $games, $byes ) = $this->section_data_arrays( $section );
		$published = WPMTM_Round_Selector::published_rounds( $players, $games, $byes, (int) $section->tot_rnds );
		?>
		<div class="wpmtm-pairings-section">
			<?php if ( $multi ) : ?>
				<h3><?php echo esc_html( $section->sec_name ); ?></h3>
			<?php endif; ?>

			<?php
			if ( empty( $published ) ) {
				echo '<p class="wpmtm-pairings-empty">' . esc_html__( 'Pairings for the next round will appear here once they are posted.', 'wp-tournament-manager' ) . '</p>';
				echo '</div>';
				return;
			}

			$latest        = (int) max( $published );
			$round_param   = 'wpmtm_pround_' . (int) $section->id;
			$selected      = $latest;
			if ( isset( $_GET[ $round_param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only round selector, not a state change; no writes happen on this public tab.
				$requested = absint( wp_unslash( $_GET[ $round_param ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, see note above.
				if ( in_array( $requested, $published, true ) ) {
					$selected = $requested;
				}
			}

			$this->render_pairings_round_selector( $published, $selected, $round_param );

			// Player-id lookups for the name/color rendering below; the pure
			// helpers work in ids, the WP layer resolves them to display names.
			$name_by_id   = array();
			$family_by_id = array();
			foreach ( $players as $p ) {
				$name_by_id[ (int) $p['id'] ]   = $p['name'];
				$family_by_id[ (int) $p['id'] ] = ! empty( $p['family_name_first'] );
			}

			$round_games = array_filter( $games, function ( $g ) use ( $selected ) {
				return (int) $g['round'] === (int) $selected;
			} );
			usort( $round_games, function ( $a, $b ) {
				return (int) $a['board'] <=> (int) $b['board'];
			} );

			$round_byes = array_filter( $byes, function ( $b ) use ( $selected ) {
				return (int) $b['round'] === (int) $selected;
			} );
			?>

			<table class="wpmtm-table wpmtm-pairings-table">
				<caption>
					<?php
					/* translators: %d: round number */
					printf( esc_html__( 'Round %d', 'wp-tournament-manager' ), (int) $selected );
					?>
				</caption>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Board', 'wp-tournament-manager' ); ?></th>
						<th scope="col"><?php esc_html_e( 'White', 'wp-tournament-manager' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Black', 'wp-tournament-manager' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Result', 'wp-tournament-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $round_games as $g ) : ?>
						<?php
						$white_id = (int) $g['white_player_id'];
						$black_id = (int) $g['black_player_id'];
						?>
						<tr>
							<td><?php echo esc_html( (int) $g['board'] ); ?></td>
							<td><?php echo esc_html( isset( $name_by_id[ $white_id ] ) ? WPMTM_Name::display( $name_by_id[ $white_id ], ! empty( $family_by_id[ $white_id ] ) ) : '' ); ?></td>
							<td><?php echo esc_html( isset( $name_by_id[ $black_id ] ) ? WPMTM_Name::display( $name_by_id[ $black_id ], ! empty( $family_by_id[ $black_id ] ) ) : '' ); ?></td>
							<td><?php echo esc_html( self::pairing_result_label( $g['result'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( ! empty( $round_byes ) ) : ?>
				<?php
				$bye_names = array();
				foreach ( $round_byes as $b ) {
					$pid = (int) $b['player_id'];
					if ( isset( $name_by_id[ $pid ] ) ) {
						$bye_names[] = WPMTM_Name::display( $name_by_id[ $pid ], ! empty( $family_by_id[ $pid ] ) );
					}
				}
				?>
				<?php if ( ! empty( $bye_names ) ) : ?>
					<p class="wpmtm-pairings-byes">
						<strong><?php esc_html_e( 'Byes:', 'wp-tournament-manager' ); ?></strong>
						<?php echo esc_html( implode( ', ', $bye_names ) ); ?>
					</p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The read-only round selector for the Pairings tab, using the same
	 * pill-band markup as the TD round selector
	 * (WPMTM_Frontend_TD::render_round_selector(): .wpmtm-round-selector-band
	 * / .wpmtm-round-tab, styled by assets/wpmtm-frontend.css, already loaded
	 * on this public tab) - the two previously diverged, this tab still
	 * showing the older plain "Round: 1 2 3" text-link version. Lists only
	 * published rounds (unlike the TD selector's display_rounds(), which also
	 * lists the in-progress round) and links each with the pairings query
	 * param and #tab-pairings hash, so a click reloads on the same tab and
	 * section round. Marked .no-print so it drops out of the printed sheet
	 * (the round number is carried by each table's <caption> instead).
	 *
	 * @param int[]  $published    Published round numbers (ascending).
	 * @param int    $selected     Currently shown round.
	 * @param string $round_param  The wpmtm_pround_{id} query param name.
	 */
	protected function render_pairings_round_selector( array $published, $selected, $round_param ) {
		if ( count( $published ) < 2 ) {
			return; // Only one published round; nothing to switch between.
		}
		?>
		<div class="wpmtm-round-selector-band wpmtm-pairings-round-selector no-print">
			<span class="wpmtm-round-selector-label"><?php esc_html_e( 'Round', 'wp-tournament-manager' ); ?></span>
			<div class="wpmtm-round-selector" role="group" aria-label="<?php esc_attr_e( 'Rounds', 'wp-tournament-manager' ); ?>">
				<?php foreach ( $published as $r ) : ?>
					<?php if ( (int) $r === (int) $selected ) : ?>
						<span class="wpmtm-round-tab" aria-selected="true"><?php echo esc_html( $r ); ?></span>
					<?php else : ?>
						<a class="wpmtm-round-tab" aria-selected="false" href="<?php echo esc_url( add_query_arg( $round_param, $r ) . '#tab-pairings' ); ?>"><?php echo esc_html( $r ); ?></a>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * A player-friendly label for a game's stored result code (the
	 * WPMTM_Round_Entry::GAME_RESULTS set), in the universal score notation
	 * players recognize on a wall chart. An empty result (not yet entered)
	 * shows a dash, so an upcoming board reads as unplayed rather than blank.
	 *
	 * @param string $result Stored wpmtm_games.result code.
	 * @return string
	 */
	protected static function pairing_result_label( $result ) {
		switch ( (string) $result ) {
			case 'W':
				return '1-0';
			case 'B':
				return '0-1';
			case 'D':
				return '½-½';
			case 'FW':
				return __( '1-0 (F)', 'wp-tournament-manager' );
			case 'FB':
				return __( '0-1 (F)', 'wp-tournament-manager' );
			case 'FD':
				return __( '0-0 (F)', 'wp-tournament-manager' );
			default:
				return ''; // Not yet entered; an upcoming board reads as blank.
		}
	}
}
