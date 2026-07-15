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
		?>
		<div class="wpmtm-td-panel">
			<h2><?php esc_html_e( 'Round entry', 'wp-tournament-manager' ); ?></h2>
			<?php if ( (bool) $tournament->locked ) : ?>
				<div class="wpmtm-locked-banner">
					<p><strong><?php esc_html_e( 'This tournament is complete and locked.', 'wp-tournament-manager' ); ?></strong></p>
					<p><?php esc_html_e( 'Unlock the tournament from its admin page to make changes.', 'wp-tournament-manager' ); ?></p>
				</div>
			<?php endif; ?>
			<?php foreach ( $sections as $section ) : ?>
				<?php $this->render_section_td_panel( $tournament, $section ); ?>
			<?php endforeach; ?>
		</div>
		<?php
	}

	protected function render_section_td_panel( $tournament, $section ) {
		$tot_rnds            = max( 1, (int) $section->tot_rnds );
		$rounds_with_results = WPMTM_Repository::rounds_with_results( $section->id );
		$round_param         = 'wpmtm_round_' . $section->id;
		$selected_round      = $this->determine_selected_round( $tot_rnds, $rounds_with_results );

		if ( isset( $_GET[ $round_param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only round selector, not a state change; the save form below is the state-changing action and is separately nonced.
			$selected_round = max( 1, absint( wp_unslash( $_GET[ $round_param ] ) ) );
		}

		list( $players, $games, $byes ) = WPMTM_Frontend_Public::instance()->section_data_arrays( $section );
		$show_photos                    = (bool) $tournament->show_photos;
		?>
		<div class="wpmtm-td-section-panel">
			<h3><?php echo esc_html( $section->sec_name ); ?></h3>
			<?php $this->render_round_selector( $tot_rnds, $rounds_with_results, $selected_round, $round_param ); ?>

			<?php if ( empty( $players ) ) : ?>
				<p><?php esc_html_e( 'This section has no players yet.', 'wp-tournament-manager' ); ?></p>
			<?php else : ?>
				<?php
				// Change 3: same Print toolbar/button as the Standings and
				// Wall chart tabs (WPMTM_Frontend_Public::render_print_toolbar()),
				// with a label specific to this use - printing the pairing
				// aid below (plus the byes table further down) for the
				// section's currently selected round, so a TD can hand a
				// physical pairing sheet to players without re-typing it.
				WPMTM_Frontend_Public::instance()->render_print_toolbar( __( 'Print pairing sheet', 'wp-tournament-manager' ) );

				$this->render_pairing_aid( $players, $games, $byes, $selected_round, $section->trn_type, $show_photos );

				$suggest_eligible = $this->round_ready_for_suggestion( $players, $games, $byes, $selected_round );
				$this->render_suggest_link( $section, $suggest_eligible );

				$suggestion = $this->maybe_build_suggestion( $section, $players, $games, $byes, $selected_round, $suggest_eligible );
				$this->render_suggest_ineligible_notice( $section, $suggest_eligible );
				$this->render_round_entry_form( $tournament, $section, $players, $games, $byes, $selected_round, $round_param, $suggestion );
				?>
			<?php endif; ?>
		</div>
		<?php
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
	 * The '#tab-round-entry' fragment (plain string concat after
	 * add_query_arg(), same as render_round_selector() below and
	 * build_return_url()) keeps the wp-etr tab UI on the Round entry tab
	 * across this GET reload - without it the reload would land back on
	 * whichever tab wp-etr defaults to (Details/Standings), throwing the TD
	 * out of the panel they were just working in. Harmless when wp-etr is
	 * inactive or has no matching tab id: an unmatched hash is simply
	 * ignored by the browser and by assets/etr-tabs.js. ('#tab-{id}' is
	 * wp-etr's canonical deep-link hash as of its 5.2.4 tab-hiding update;
	 * the older '#etr-tab-{id}' form still opens the tab if it reaches an
	 * older wp-etr, but this plugin only needs to target current wp-etr.)
	 */
	protected function render_suggest_link( $section, $eligible ) {
		if ( ! $eligible ) {
			return;
		}
		$suggest_param = 'wpmtm_suggest_' . $section->id;
		?>
		<p class="wpmtm-suggest-pairings">
			<a href="<?php echo esc_url( add_query_arg( $suggest_param, 1 ) . '#tab-round-entry' ); ?>"><?php esc_html_e( 'Suggest pairings', 'wp-tournament-manager' ); ?></a>
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

	/** Default selected round: lowest round (1..tot_rnds) with no results yet, else one past the last entered round, else 1. */
	protected function determine_selected_round( $tot_rnds, array $rounds_with_results ) {
		for ( $r = 1; $r <= $tot_rnds; $r++ ) {
			if ( ! in_array( $r, $rounds_with_results, true ) ) {
				return $r;
			}
		}
		return $rounds_with_results ? ( max( $rounds_with_results ) + 1 ) : 1;
	}

	/**
	 * Each round link's '#tab-round-entry' fragment (plain string
	 * concat after add_query_arg()) keeps the wp-etr tab UI on the Round
	 * entry tab across the GET reload a round switch causes - see
	 * render_suggest_link()'s docblock above for the full rationale.
	 */
	protected function render_round_selector( $tot_rnds, array $rounds_with_results, $selected_round, $round_param ) {
		$max_known = max( array_merge( array( $tot_rnds, $selected_round ), $rounds_with_results ) );
		$display_rounds = range( 1, $max_known );
		?>
		<p class="wpmtm-round-selector">
			<?php esc_html_e( 'Round:', 'wp-tournament-manager' ); ?>
			<?php foreach ( $display_rounds as $r ) : ?>
				<?php if ( (int) $r === (int) $selected_round ) : ?>
					<strong><?php echo esc_html( $r ); ?></strong>
				<?php else : ?>
					<a href="<?php echo esc_url( add_query_arg( $round_param, $r ) . '#tab-round-entry' ); ?>"><?php echo esc_html( $r ); ?></a>
				<?php endif; ?>
			<?php endforeach; ?>
		</p>
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
			<h4><?php esc_html_e( 'Pairing aid', 'wp-tournament-manager' ); ?></h4>
			<p class="description">
				<?php if ( $is_rr ) : ?>
					<?php esc_html_e( 'Pair so everyone eventually faces everyone; the "Still to play" list shrinks as rounds are entered.', 'wp-tournament-manager' ); ?>
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
							<th><?php esc_html_e( '#', 'wp-tournament-manager' ); ?></th>
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
							<th><?php esc_html_e( '#', 'wp-tournament-manager' ); ?></th>
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
		<h4><?php esc_html_e( 'Results entry', 'wp-tournament-manager' ); ?></h4>
		<?php if ( $suggestion && ! empty( $suggestion['notes'] ) ) : ?>
			<ul class="wpmtm-suggest-notes">
				<?php foreach ( $suggestion['notes'] as $note ) : ?>
					<li><?php echo esc_html( $note ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php if ( $suggestion ) : ?>
			<p class="description">
				<?php esc_html_e( 'Suggestions follow a simplified top-half versus bottom-half model with rematch avoidance and due colors, not the full USCF pairing rules; review every board before saving.', 'wp-tournament-manager' ); ?>
			</p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpmtm-round-entry-form" data-wpmtm-guard>
			<?php wp_nonce_field( 'wpmtm_save_round_' . $section->id . '_' . $selected_round, 'wpmtm_round_nonce' ); ?>
			<input type="hidden" name="action" value="wpmtm_save_round">
			<input type="hidden" name="tournament_id" value="<?php echo esc_attr( $tournament->id ); ?>">
			<input type="hidden" name="section_id" value="<?php echo esc_attr( $section->id ); ?>">
			<input type="hidden" name="round" value="<?php echo esc_attr( $selected_round ); ?>">
			<input type="hidden" name="wpmtm_return_round_param" value="<?php echo esc_attr( $round_param ); ?>">

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
								$locked
							);
						}
					} elseif ( $round_games ) {
						foreach ( $round_games as $game ) {
							$this->render_board_row( $active_players, $game, $locked );
						}
					} else {
						$this->render_board_row( $active_players, null, $locked );
					}
					?>
				</tbody>
				<template>
					<?php $this->render_board_row( $active_players, null, $locked ); ?>
				</template>
			</table>
			<p><button type="button" class="button" data-wpmtm-add-board-for="<?php echo esc_attr( $table_id ); ?>"><?php esc_html_e( '+ Add board', 'wp-tournament-manager' ); ?></button></p>
			<p class="description"><?php esc_html_e( 'Board numbers are assigned automatically in row order; they are only for pairing convenience and are not part of the USCF export.', 'wp-tournament-manager' ); ?></p>

			<?php $this->render_byes_area( $active_players, $byes_prefill, $locked ); ?>

			<p class="description">
				<?php esc_html_e( 'Saving a round replaces that round\'s results entirely, so correcting a mistake is just re-saving the round with the fix. Standings above update immediately.', 'wp-tournament-manager' ); ?>
			</p>

			<?php if ( ! $locked ) : ?>
				<?php
				submit_button(
					__( 'Save round', 'wp-tournament-manager' ),
					'primary',
					'submit',
					true,
					array( 'data-wpmtm-busy-label' => esc_attr__( 'Saving...', 'wp-tournament-manager' ) )
				);
				?>
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
	 * @param array      $players Section roster, for the White/Black selects.
	 * @param array|null $game    Existing game row to prefill, or null for a blank/template row.
	 * @param bool       $locked  Change 6: when true, the three selects get
	 *                            the disabled attribute - a visual cue only,
	 *                            since the caller (render_round_entry_form())
	 *                            never renders the Save round button either
	 *                            when locked, and handle_save_round() below
	 *                            is the real, server-side guard.
	 */
	protected function render_board_row( array $players, $game, $locked = false ) {
		$board  = $game ? $game['board'] : '';
		$white  = $game ? $game['white_player_id'] : '';
		$black  = $game ? $game['black_player_id'] : '';
		$result = $game ? $game['result'] : 'W';
		?>
		<tr>
			<td class="wpmtm-col-num"><?php echo '' !== $board ? esc_html( $board ) : esc_html__( 'auto', 'wp-tournament-manager' ); ?></td>
			<td>
				<select name="board_white[]" <?php disabled( $locked ); ?>>
					<?php $this->render_player_options( $players, $white ); ?>
				</select>
			</td>
			<td>
				<select name="board_black[]" <?php disabled( $locked ); ?>>
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
	 * @param array  $players     Section roster (or, from render_board_row()'s
	 *                             callers, only the players active for the
	 *                             selected round).
	 * @param mixed  $selected_id Currently selected player id, or '' for none.
	 */
	protected function render_player_options( array $players, $selected_id ) {
		echo '<option value="">' . esc_html__( '-- select --', 'wp-tournament-manager' ) . '</option>';
		foreach ( $players as $p ) {
			printf(
				'<option value="%1$d" %2$s>%3$s</option>',
				(int) $p['id'],
				selected( (int) $selected_id, (int) $p['id'], false ),
				esc_html( $p['pair_num'] . '. ' . WPMTM_Name::display( $p['name'], ! empty( $p['family_name_first'] ) ) )
			);
		}
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
	 */
	/**
	 * @param array $players      Section roster active for the selected round.
	 * @param array $byes_prefill player_id => bye type, for existing rows.
	 * @param bool  $locked       Change 6: when true, every bye-type select
	 *                            gets the disabled attribute - see
	 *                            render_board_row()'s docblock for why this
	 *                            is a visual cue only.
	 */
	protected function render_byes_area( array $players, array $byes_prefill, $locked = false ) {
		?>
		<h4><?php esc_html_e( 'Byes', 'wp-tournament-manager' ); ?></h4>
		<p class="description"><?php esc_html_e( 'Assign a bye to anyone not playing this round. Leave None if the player is on a board above. Withdraw marks the player out from this round on, instead of writing a bye.', 'wp-tournament-manager' ); ?></p>
		<table class="wpmtm-byes-table wpmtm-table">
			<thead>
				<tr>
					<th><?php esc_html_e( '#', 'wp-tournament-manager' ); ?></th>
					<th><?php esc_html_e( 'Name', 'wp-tournament-manager' ); ?></th>
					<th><?php esc_html_e( 'Bye', 'wp-tournament-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $players as $p ) : ?>
					<?php $current = isset( $byes_prefill[ $p['id'] ] ) ? $byes_prefill[ $p['id'] ] : ''; ?>
					<tr>
						<td><?php echo esc_html( $p['pair_num'] ); ?></td>
						<td><?php echo esc_html( WPMTM_Name::display( $p['name'], ! empty( $p['family_name_first'] ) ) ); ?></td>
						<td>
							<select name="byes[<?php echo esc_attr( $p['id'] ); ?>]" <?php disabled( $locked ); ?>>
								<option value="" <?php selected( $current, '' ); ?>><?php esc_html_e( 'None', 'wp-tournament-manager' ); ?></option>
								<option value="B" <?php selected( $current, 'B' ); ?>><?php esc_html_e( 'Full-point bye (B)', 'wp-tournament-manager' ); ?></option>
								<option value="H" <?php selected( $current, 'H' ); ?>><?php esc_html_e( 'Half-point bye (H)', 'wp-tournament-manager' ); ?></option>
								<option value="U" <?php selected( $current, 'U' ); ?>><?php esc_html_e( 'Unplayed (U)', 'wp-tournament-manager' ); ?></option>
								<option value="WD" <?php selected( $current, 'WD' ); ?>><?php esc_html_e( 'Withdraw (out from this round on)', 'wp-tournament-manager' ); ?></option>
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

		if ( ! current_user_can( WPMTM_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'wp-tournament-manager' ) );
		}

		$tournament_id = isset( $_POST['tournament_id'] ) ? absint( $_POST['tournament_id'] ) : 0;
		$round_param   = isset( $_POST['wpmtm_return_round_param'] ) ? sanitize_key( wp_unslash( $_POST['wpmtm_return_round_param'] ) ) : '';

		$section    = $section_id ? WPMTM_Repository::get_section( $section_id ) : null;
		$tournament = $section ? WPMTM_Repository::get_tournament( $section->tournament_id ) : null;

		if ( ! $section || ! $tournament || (int) $tournament->id !== $tournament_id ) {
			wp_die( esc_html__( 'Section not found, or it does not belong to the posted tournament.', 'wp-tournament-manager' ) );
		}

		$redirect_back = $this->build_return_url( $tournament, $round_param, $round );

		// Change 6 ("conclude and lock a tournament"): $tournament above
		// was just re-fetched fresh from the database a few lines up (not
		// carried over from render time), so this is always the current
		// locked state, not a stale one from whenever the form was
		// rendered - the real protection against a locked tournament being
		// edited; the round-entry form's disabled selects and missing Save
		// button (render_round_entry_form() above) are only the visual cue.
		if ( (bool) $tournament->locked ) {
			$this->set_notice( 'error', __( 'This tournament is locked; unlock it to enter results.', 'wp-tournament-manager' ) );
			wp_safe_redirect( $redirect_back );
			exit;
		}

		if ( $round <= 0 ) {
			$this->set_notice( 'error', __( 'Invalid round number; nothing was saved.', 'wp-tournament-manager' ) );
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
		$posted_white  = ( isset( $_POST['board_white'] ) && is_array( $_POST['board_white'] ) ) ? wp_unslash( $_POST['board_white'] ) : array();
		$posted_black  = ( isset( $_POST['board_black'] ) && is_array( $_POST['board_black'] ) ) ? wp_unslash( $_POST['board_black'] ) : array();
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
			$this->set_notice( 'error', __( 'A player cannot both play a board and withdraw in the same round; remove one of the two and save again.', 'wp-tournament-manager' ) );
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
			$this->set_notice( 'error', __( 'The round could not be saved due to a database error; nothing was changed.', 'wp-tournament-manager' ) );
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
	 * When redirecting to the event page, appends '#tab-round-entry'
	 * (plain string concat after add_query_arg(), same as
	 * render_suggest_link() / render_round_selector() above) so saving a
	 * round lands the TD back on the wp-etr Round entry tab instead of
	 * whichever tab wp-etr defaults to. Not appended for the admin edit
	 * screen fallback - that screen has no such tab.
	 */
	protected function build_return_url( $tournament, $round_param, $round ) {
		$is_event_page = (bool) $tournament->event_post_id;
		$base          = $is_event_page ? get_permalink( $tournament->event_post_id ) : '';
		if ( ! $base ) {
			$is_event_page = false;
			$base          = add_query_arg( array( 'page' => 'wpmtm-edit', 'id' => $tournament->id ), admin_url( 'admin.php' ) );
		}
		if ( '' !== $round_param ) {
			$base = add_query_arg( $round_param, $round, $base );
		}
		return $is_event_page ? $base . '#tab-round-entry' : $base;
	}
}
