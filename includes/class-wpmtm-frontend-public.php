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
	}

	// -----------------------------------------------------------------
	// Public standings (all visitors).
	// -----------------------------------------------------------------

	public function render_public_block( $tournament ) {
		$sections    = WPMTM_Repository::get_sections( $tournament->id );
		$show_photos = (bool) $tournament->show_photos;
		?>
		<div class="wpmtm-frontend-results">
			<?php $this->render_switch_to_tournament_link( $tournament ); ?>
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
				<?php $this->render_section_standings( $section, $show_photos ); ?>
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
	 * @param object $tournament
	 */
	public function render_standings_only( $tournament ) {
		$this->render_switch_to_tournament_link( $tournament );
		$this->render_print_toolbar();

		// wp-etr's tab label itself already says "Standings" (this method
		// never prints that text - see this method's own docblock above),
		// so a locked tournament's "Final" badge (render_public_block()'s
		// literal <h2>Standings</h2> equivalent for the no-tabs paths) has
		// no heading to sit "next to" here; it renders instead as the first
		// line of the tab content, immediately under the tab label.
		if ( (bool) $tournament->locked ) {
			echo '<p class="wpmtm-final-badge">' . esc_html__( 'Final', 'wp-tournament-manager' ) . '</p>';
		}

		$sections = WPMTM_Repository::get_sections( $tournament->id );
		if ( empty( $sections ) ) {
			echo '<p>' . esc_html__( 'No sections have been set up yet.', 'wp-tournament-manager' ) . '</p>';
			return;
		}
		$show_photos = (bool) $tournament->show_photos;
		foreach ( $sections as $section ) {
			$this->render_section_standings( $section, $show_photos, false );
		}
	}

	/**
	 * "Switch to tournament" link to the admin edit screen for the given
	 * tournament, shown at the very top of the public Tournament Manager
	 * block (render_public_block() above) and the "Standings" tab
	 * (render_standings_only() above), so a TD opening the event page has
	 * a one-click way back to the admin edit page. Capability-gated on
	 * WPMTM_CAPABILITY (never shown to anonymous visitors or a logged-in
	 * user who cannot manage tournaments) and only rendered when a
	 * tournament is actually linked, i.e. $tournament is a real row - both
	 * callers here only ever pass one after resolving it via
	 * WPMTM_Repository::get_tournament_by_event() or an explicit id, so
	 * there is nothing further to check on that front.
	 *
	 * @param object $tournament
	 */
	protected function render_switch_to_tournament_link( $tournament ) {
		if ( ! $tournament || ! current_user_can( WPMTM_CAPABILITY ) ) {
			return;
		}
		$edit_url = add_query_arg( array( 'page' => 'wpmtm-edit', 'id' => $tournament->id ), admin_url( 'admin.php' ) );
		?>
		<p class="wpmtm-switch-to-tournament">
			<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Switch to tournament', 'wp-tournament-manager' ); ?></a>
		</p>
		<?php
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
			echo '<p>' . esc_html__( 'No results yet.', 'wp-tournament-manager' ) . '</p>';
			return;
		}

		$this->render_print_toolbar();
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
	 * @param string $label Button label; defaults to "Print" (Wall chart's
	 *                       original wording). WPMTM_Frontend_TD passes
	 *                       "Print pairing sheet" instead.
	 */
	public function render_print_toolbar( $label = '' ) {
		if ( '' === $label ) {
			$label = __( 'Print', 'wp-tournament-manager' );
		}
		?>
		<div class="wpmtm-toolbar no-print">
			<button type="button" class="wpmtm-btn wpmtm-print" data-wpmtm-print><?php echo esc_html( $label ); ?></button>
		</div>
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
	 */
	protected function render_section_standings( $section, $show_photos = false, $include_wall_chart = true ) {
		list( $players, $games, $byes ) = $this->section_data_arrays( $section );
		?>
		<div class="wpmtm-section-standings">
			<h3><?php echo esc_html( $section->sec_name ); ?></h3>
			<?php if ( empty( $games ) && empty( $byes ) ) : ?>
				<p><?php esc_html_e( 'No results yet.', 'wp-tournament-manager' ); ?></p>
			<?php else : ?>
				<?php
				$standings = WPMTM_Scoring::standings( $players, $games, $byes );

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
				<p class="wpmtm-standings-label"><?php esc_html_e( 'Standings', 'wp-tournament-manager' ); ?></p>
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
								<td><?php echo esc_html( $i + 1 ); ?></td>
								<td>
									<?php echo esc_html( WPMTM_Name::display( $row['name'], ! empty( $row['family_name_first'] ) ) ); ?>
									<?php if ( isset( $row['withdrawn_after_round'] ) && null !== $row['withdrawn_after_round'] ) : ?>
										<?php
										printf(
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
					<?php esc_html_e( 'Ties in score are broken left to right by Modified Median, then Solkoff, then Cumulative, then Cumulative of Opposition (US Chess rule 34E).', 'wp-tournament-manager' ); ?>
				</p>
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

		// Static, hardcoded silhouette markup - no user input other than the
		// already-escaped, translatable aria-label - so it is safe to build
		// as a plain string and echo at the call sites without further
		// escaping (wp_kses_post() strips <svg>, so there is nothing useful
		// to run this through instead).
		return '<svg viewBox="0 0 128 128" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="' . esc_attr__( 'No profile photo', 'wp-tournament-manager' ) . '" class="wpmtm-avatar wpmtm-avatar--placeholder"><rect width="128" height="128" rx="64" fill="#EAE7DE"/><circle cx="64" cy="50" r="22" fill="#b48c49" opacity="0.55"/><path d="M24 118c4-26 20-38 40-38s36 12 40 38" fill="#b48c49" opacity="0.55"/></svg>';
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
			);
		}
		return $players;
	}
}
