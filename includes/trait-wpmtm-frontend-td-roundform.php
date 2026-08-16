<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TD round-entry rendering (pairing aid, the entry form, board rows, player
 * option builders, byes area), extracted verbatim from WPMTM_Frontend_TD
 * (2026-07-29 segmentation). Composed back via a use statement; behavior is
 * identical.
 */
trait WPMTM_Frontend_TD_RoundForm {
	/**
	 * DOM id of a section's round-entry form, so a section's controls can be
	 * addressed unambiguously on a page that renders one form per section.
	 *
	 * @param int $section_id
	 * @return string
	 */
	protected function round_form_id( $section_id ) {
		return 'wpmtm-round-form-' . (int) $section_id;
	}

	/**
	 * @param bool $show_photos Tournament's show_photos flag. WPMTM_Pairing_Aid
	 *                          is a pure class and its score-group player
	 *                          rows do not carry photo_id (see
	 *                          WPMTM_Frontend_Public::map_players()'s
	 *                          docblock), so a local id => photo_id map is
	 *                          built from $players below and used to look
	 *                          each row's avatar up by player id instead.
	 *
	 * Corrected 2026-08-14 (audit item 66): this docblock used to sit above
	 * round_form_id() instead, orphaned there when that method was inserted
	 * between it and the method it actually describes.
	 */
	protected function render_pairing_aid( array $players, array $games, array $byes, $selected_round, $trn_type = 'S', $show_photos = false, $cycles = 1, $section_id = 0, $locked = false, $section_name = '' ) {
		$aid   = WPMTM_Pairing_Aid::build( $players, $games, $byes, $selected_round, $trn_type, $cycles );
		$is_rr = 'R' === $aid['trn_type'];

		// WPMTM_Pairing_Aid::build() rebuilds its own row shape
		// (id/pair_num/name/rating/...) rather than merging $players, so it
		// drops both 'photo_id' and 'family_name_first' (see
		// WPMTM_Frontend_Public::map_players()'s docblock). Both are looked up
		// by player id for every name/avatar this method renders (score-group
		// rows, "Not yet paired", Withdrawn), so build both id-keyed maps in a
		// single pass over $players before rendering. photo_by_id stays empty
		// unless photos are on, exactly as before.
		$photo_by_id        = array();
		$family_first_by_id = array();
		foreach ( $players as $p ) {
			$pid = (int) $p['id'];
			if ( $show_photos ) {
				$photo_by_id[ $pid ] = isset( $p['photo_id'] ) ? $p['photo_id'] : null;
			}
			$family_first_by_id[ $pid ] = ! empty( $p['family_name_first'] );
		}
		?>
		<div<?php echo $section_id ? ' id="wpmtm-pairing-aid-' . esc_attr( $section_id ) . '"' : ''; ?> class="wpmtm-pairing-aid" data-wpmtm-pairing-aid>
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
					<?php
					// The circle-method sentence names the same algorithm
					// WPMTM_Pairing_Suggest::suggest_round_robin() actually runs
					// (fix the last seat, rotate the rest by round number - see
					// that method's own "Circle method" comment), so a TD reading
					// this and a TD reading Suggest's output are being told about
					// the same schedule, not two different things.
					esc_html_e( 'Pair so everyone eventually faces everyone. Boards follow a fixed schedule drawn from pairing number, the standard round-robin circle method, the same schedule Suggest pairings computes automatically.', 'wp-tournament-manager' );
					?>
				<?php else : ?>
					<?php
					// The last sentence repeats, in this always-visible guidance,
					// the same disclaimer render_round_entry_form() already shows
					// but only once a suggestion has actually been requested (see
					// its own $suggestion-gated notice) - a TD pairing by hand,
					// who never clicks Suggest, was never told this plugin's
					// Swiss logic is simplified rather than full USCF pairing.
					esc_html_e( 'Pair players within each score group from the top down: start with the highest score group, matching players inside it before moving to the next group. Give each player the color marked "due" when possible. Avoid pairing two players who have already played each other in this section (see "Opponents played"). Suggest pairings follows a simplified top-half versus bottom-half model with rematch avoidance and due colors, not the full USCF pairing rules.', 'wp-tournament-manager' );
					?>
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
							// The column header already says "Color due" (see the <th>
							// above), so repeating "due" in every row was redundant.
							$due = '';
							if ( 'W' === $p['color_due'] ) {
								$due = __( 'W', 'wp-tournament-manager' );
							} elseif ( 'B' === $p['color_due'] ) {
								$due = __( 'B', 'wp-tournament-manager' );
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

	/**
	 * @param bool   $write_blocked Whether this round may be written at all
	 *                              right now. Withdraws the Save button, since
	 *                              handle_save_round() is going to refuse the
	 *                              write and offering a button that will be
	 *                              rejected is worse than offering none. The
	 *                              banner above the form names the round in the
	 *                              way. The two modes ask different questions of
	 *                              it: results wait on every earlier round being
	 *                              scored, while pairing only waits for Swiss,
	 *                              whose draw comes from the standings.
	 * @param string $mode           'pair' or 'results'. In pair mode the form
	 *                              carries no result fields at all, which is
	 *                              what makes "saving pairings cannot touch
	 *                              results" structural rather than a promise.
	 */
	protected function render_round_entry_form( $tournament, $section, array $players, array $games, array $byes, $selected_round, $round_param, $suggestion = null, $write_blocked = false, $mode = 'results' ) {
		$is_pair_mode = ( 'pair' === $mode );
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

		// Rounds that already have recorded games are the exception: a
		// withdrawal backdated to before a round the player actually played
		// leaves the game row fully intact (scoring and export read it
		// as-is), so the selects for those existing rows must still be able
		// to show the recorded players' names - otherwise the row renders
		// "-- select --" and the TD reads it as lost data. Splice this
		// round's recorded players back into the select-lookup roster only;
		// the JSON island below stays active-players-only, so new rows and
		// fresh pairings never offer a withdrawn player.
		$select_players = $active_players;
		if ( $round_games ) {
			$have_ids = array();
			foreach ( $active_players as $p ) {
				$have_ids[ (int) $p['id'] ] = true;
			}
			foreach ( $round_games as $g ) {
				foreach ( array( 'white_player_id', 'black_player_id' ) as $side ) {
					$pid = isset( $g[ $side ] ) ? (int) $g[ $side ] : 0;
					if ( ! $pid || isset( $have_ids[ $pid ] ) ) {
						continue;
					}
					foreach ( $players as $p ) {
						if ( (int) $p['id'] === $pid ) {
							$select_players[]  = $p;
							$have_ids[ $pid ] = true;
							break;
						}
					}
				}
			}
		}

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
					esc_html__( 'Suggested pairings loaded for round %d. Review each board, then Save pairings to record them.', 'wp-tournament-manager' ),
					(int) $selected_round
				);
				?>
			</p>
		<?php endif; ?>
		<?php
		// No separate heading here (removed 2026-08-14): the <details> summary
		// wrapping this form already says "Pairings for round N" or "Results
		// for round N" depending on the mode - see render_section_td_panel().
		// A second heading duplicated that, and could even contradict it: this
		// one picked its text from whether the round had games yet, which is
		// not the same question as which mode the TD is in, so an unpaired
		// round in Results mode could read "Pairing round N" under a "Results
		// for round N" summary.
		?>
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
		<?php
		$save_confirm = $is_pair_mode
			? sprintf(
				/* translators: %d: round number */
				__( 'Save the pairings for round %d? This replaces any pairings already saved for this round. Results recorded for a pairing that has not changed are kept.', 'wp-tournament-manager' ),
				(int) $selected_round
			)
			: sprintf(
				/* translators: %d: round number */
				__( 'Save results for round %d? This replaces any results already saved for this round.', 'wp-tournament-manager' ),
				(int) $selected_round
			);
		?>
		<form id="<?php echo esc_attr( $this->round_form_id( $section->id ) ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpmtm-round-entry-form" data-wpmtm-guard data-wpmtm-save-confirm="<?php echo esc_attr( $save_confirm ); ?>">
			<?php wp_nonce_field( 'wpmtm_save_round_' . $section->id . '_' . $selected_round, 'wpmtm_round_nonce' ); ?>
			<input type="hidden" name="action" value="wpmtm_save_round">
			<input type="hidden" name="tournament_id" value="<?php echo esc_attr( $tournament->id ); ?>">
			<input type="hidden" name="section_id" value="<?php echo esc_attr( $section->id ); ?>">
			<input type="hidden" name="round" value="<?php echo esc_attr( $selected_round ); ?>">
			<input type="hidden" name="wpmtm_return_round_param" value="<?php echo esc_attr( $round_param ); ?>">
			<?php if ( $is_pair_mode ) : ?>
				<?php
				// The whole point of the split: this form posts no board_result
				// fields at all, so a pairing save physically cannot carry a
				// result with it. The flag tells handle_save_round() to carry
				// forward the results already stored for pairings that have not
				// changed, rather than treating their absence as "blank them".
				?>
				<input type="hidden" name="wpmtm_pairings_only" value="1">
				<input type="hidden" name="wpmtm_mode" value="pair">
			<?php else : ?>
				<input type="hidden" name="wpmtm_mode" value="results">
			<?php endif; ?>

			<?php $this->render_players_json( $active_players, $section->id ); ?>
			<table class="wpmtm-boards-table wpmtm-table" id="<?php echo esc_attr( $table_id ); ?>" data-wpmtm-round-repeater>
				<thead>
					<tr>
						<th><?php esc_html_e( 'Board', 'wp-tournament-manager' ); ?></th>
						<th><?php esc_html_e( 'White', 'wp-tournament-manager' ); ?></th>
						<th><?php esc_html_e( 'Black', 'wp-tournament-manager' ); ?></th>
						<?php if ( ! $is_pair_mode ) : ?>
							<th><?php esc_html_e( 'Result', 'wp-tournament-manager' ); ?></th>
						<?php endif; ?>
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
									// A suggestion pairs players; it never claims
									// an outcome. Blank until the game is played.
									'result'          => '',
								),
								$locked,
								$section->id,
								$is_pair_mode
							);
						}
					} elseif ( $round_games ) {
						foreach ( $round_games as $game ) {
							$this->render_board_row( $select_players, $game, $locked, $section->id, $is_pair_mode );
						}
					} else {
						$this->render_board_row( $active_players, null, $locked, $section->id, $is_pair_mode );
					}
					?>
				</tbody>
				<template>
					<?php $this->render_board_row( $active_players, null, $locked, $section->id, $is_pair_mode ); ?>
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

			// Item 35 (docs/SPEC.md): a player withdrawn as of before this round
			// is filtered out of $active_players, so a bye they had saved for
			// this round (entered while still active, before the withdrawal was
			// backdated) would render no row at all and be silently dropped on
			// the next save. Collect those here from the full roster - any
			// player carrying a saved bye this round ($byes_prefill) who is not
			// in the active set - and hand them to render_byes_area() as
			// read-only, value-preserving rows.
			$active_ids = array();
			foreach ( $active_players as $ap ) {
				$active_ids[ (int) $ap['id'] ] = true;
			}
			$preserved_bye_players = array();
			foreach ( $players as $rp ) {
				$rpid = (int) $rp['id'];
				if ( isset( $byes_prefill[ $rpid ] ) && ! isset( $active_ids[ $rpid ] ) ) {
					$rp['bye_type']          = $byes_prefill[ $rpid ];
					$preserved_bye_players[] = $rp;
				}
			}

			?>
			<details class="wpmtm-byes-details" <?php echo $is_pair_mode ? 'open' : ''; ?>>
				<summary><?php esc_html_e( 'Byes', 'wp-tournament-manager' ); ?></summary>
				<?php
				// Byes are a pairing decision - who sits out - so they open by
				// default in pair mode, the same job they belong to. They stay
				// reachable in results mode too, collapsed rather than removed,
				// because Withdraw lives inside this same control (the 'WD'
				// option below): a player who leaves partway through a round is
				// recorded here, and that discovery happens while entering that
				// round's results, not while pairing it.
				$this->render_byes_area( $active_players, $byes_prefill, $locked, $allow_withdraw, $preserved_bye_players );
				?>
			</details>

			<?php if ( ! $locked && ! $write_blocked ) : ?>
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
					if ( $is_pair_mode ) {
						$save_label = '' !== (string) $section->sec_name
							? sprintf(
								/* translators: 1: round number, 2: section name */
								__( 'Save pairings for round %1$d, %2$s', 'wp-tournament-manager' ),
								(int) $selected_round,
								$section->sec_name
							)
							: sprintf( /* translators: %d: round number */ __( 'Save pairings for round %d', 'wp-tournament-manager' ), (int) $selected_round );
					} else {
						$save_label = $round_games
							? sprintf( /* translators: %d: round number */ __( 'Update round %d', 'wp-tournament-manager' ), (int) $selected_round )
							: sprintf( /* translators: %d: round number */ __( 'Save round %d', 'wp-tournament-manager' ), (int) $selected_round );
					}
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
				<?php if ( $is_pair_mode ) : ?>
					<p class="description">
						<?php
						// Moved here from inside the Byes details (owner request,
						// 2026-08-14): it belongs with the action it describes, the
						// Save button just above, not tucked inside a disclosure a TD
						// may have already collapsed.
						esc_html_e( 'Saving pairings replaces this round\'s pairings. Results already recorded for a pairing that has not changed are kept.', 'wp-tournament-manager' );
						?>
					</p>
				<?php else : ?>
					<p class="description">
						<?php esc_html_e( 'Saving a round replaces that round\'s results entirely, so correcting a mistake is just re-saving the round with the fix. Standings above update immediately.', 'wp-tournament-manager' ); ?>
					</p>
				<?php endif; ?>
				<?php
				$last_saved = WPMTM_Repository::get_round_saved_at( $section->id, $selected_round );
				if ( $last_saved ) :
					$last_saved_display = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $last_saved ) );
				?>
					<p class="wpmtm-last-saved"><small><?php echo esc_html( sprintf( /* translators: %s: date and time the round was last saved */ __( 'Last saved %s', 'wp-tournament-manager' ), $last_saved_display ) ); ?></small></p>
				<?php endif; ?>
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
	protected function render_board_row( array $players, $game, $locked = false, $section_id = 0, $is_pair_mode = false ) {
		$board  = $game ? $game['board'] : '';
		$white  = $game ? $game['white_player_id'] : '';
		$black  = $game ? $game['black_player_id'] : '';
		// Blank, not 'W': a new or freshly suggested board has not been played
		// yet, and defaulting it to a White win is how an untouched round used
		// to record a full set of White wins on save.
		$result = $game ? $game['result'] : '';

		// Only rows backed by an already-saved game carry a Remove
		// confirmation (a non-empty board number is the tell: suggestion
		// prefills and blank/template rows pass board => ''). Removing a
		// saved board then saving deletes the whole shared row, the
		// opponent's recorded result included, so that click gets a
		// confirm; discarding a not-yet-saved row stays one click.
		$remove_confirm = ( $game && '' !== $board )
			? __( 'Remove this board? Saving the round will then delete BOTH players\' recorded result for it. To fix a wrong result, change the Result dropdown instead.', 'wp-tournament-manager' )
			: '';
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
			<?php if ( ! $is_pair_mode ) : ?>
			<td>
				<select name="board_result[]" <?php disabled( $locked ); ?>>
					<?php foreach ( $this->result_options() as $code => $label ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $result, $code ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<?php endif; ?>
			<td><button type="button" class="button-link-delete" data-remove-row<?php echo $remove_confirm ? ' data-wpmtm-remove-confirm="' . esc_attr( $remove_confirm ) . '"' : ''; ?>><?php esc_html_e( 'Remove', 'wp-tournament-manager' ); ?></button></td>
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
			// Blank is a real state, not a missing choice: a board that has
			// been paired but not yet played has no result. Before this option
			// existed the select's first entry was "White won", so a freshly
			// suggested round that a TD saved without touching every row
			// recorded a White win on every board - the "Save pairings also
			// saved results" field report. It also made a saved blank result
			// render as "White won", since selected() matched nothing and the
			// browser fell back to the first option.
			''   => __( '-- not played yet --', 'wp-tournament-manager' ),
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
	 *
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
	 * @param array $preserved_bye_players Players who hold a saved bye in this
	 *                              round but are withdrawn as of before it, so
	 *                              they are not in $players and get no editable
	 *                              row (item 35, docs/SPEC.md). Rendered as
	 *                              read-only "saved bye" lines carrying the bye
	 *                              value through a hidden carried_byes[] input,
	 *                              so re-saving the round preserves the bye
	 *                              instead of silently dropping it - without
	 *                              offering a contradictory Withdraw control on
	 *                              an already-withdrawn player. Each entry is a
	 *                              player row with an added 'bye_type' key.
	 */
	protected function render_byes_area( array $players, array $byes_prefill, $locked = false, $allow_withdraw = true, array $preserved_bye_players = array() ) {
		// Audit item 69: single source for the three bye-type labels, read
		// by both the editable select below and the read-only "saved bye"
		// rows further down. Before this they were the same three strings
		// typed twice - once here for the read-only rows, once as literal
		// <option> text in the select - so editing one left the other
		// disagreeing about what a "B"/"H"/"U" bye actually is.
		$bye_type_labels = array(
			'B' => __( 'Full-point bye (B)', 'wp-tournament-manager' ),
			'H' => __( 'Half-point bye (H)', 'wp-tournament-manager' ),
			'U' => __( 'Unplayed (U)', 'wp-tournament-manager' ),
		);
		?>
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
								<?php foreach ( $bye_type_labels as $code => $label ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $current, $code ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
								<?php if ( $allow_withdraw ) : ?>
									<option value="WD" <?php selected( $current, 'WD' ); ?>><?php esc_html_e( 'Withdraw (out from this round on)', 'wp-tournament-manager' ); ?></option>
								<?php endif; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
				<?php foreach ( $preserved_bye_players as $p ) : ?>
					<?php
					// Read-only "saved bye" row for a player withdrawn as of
					// before this round who nonetheless has a bye saved in it
					// (item 35). The hidden carried_byes[] input round-trips the
					// value so handle_save_round() re-writes it verbatim; no
					// select, so the TD is never offered a Withdraw option for
					// someone already withdrawn, and the round-entry validator
					// (which would reject a bye for a withdrawn player) never
					// sees it - carried_byes is merged in only after validation.
					// See handle_save_round().
					$bye_type  = isset( $p['bye_type'] ) ? strtoupper( (string) $p['bye_type'] ) : '';
					$bye_label = isset( $bye_type_labels[ $bye_type ] ) ? $bye_type_labels[ $bye_type ] : $bye_type;
					?>
					<tr class="wpmtm-bye-row-readonly">
						<td><?php echo esc_html( $p['pair_num'] ); ?></td>
						<td>
							<?php echo esc_html( WPMTM_Name::display( $p['name'], ! empty( $p['family_name_first'] ) ) ); ?>
							<p class="description wpmtm-player-note"><?php esc_html_e( 'Withdrawn. The bye saved before the withdrawal is kept as-is.', 'wp-tournament-manager' ); ?></p>
						</td>
						<td>
							<?php echo esc_html( $bye_label ); ?>
							<input type="hidden" name="carried_byes[<?php echo esc_attr( $p['id'] ); ?>]" value="<?php echo esc_attr( $bye_type ); ?>" />
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
