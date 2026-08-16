<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure, WordPress-independent validator for one round's worth of posted
 * board results and byes, before WPMTM_Repository::replace_round() writes
 * them. Errors are plain language: they surface directly to the TD on the
 * event page.
 *
 * A player can simply have no result in a round without failing validation
 * on that alone - it only checks the shape of what was submitted: unique
 * boards, valid ids/results/bye types, no player used twice in the same
 * round, and (when a round number is supplied) no withdrawn player named on
 * a board or bye for a round after their withdrawal.
 */
class WPMTM_Round_Entry {

	const GAME_RESULTS = array( 'W', 'B', 'D', 'FW', 'FB', 'FD' );

	const BYE_TYPES = array( 'B', 'H', 'U' );

	/**
	 * @param array $players List of assoc rows with at least 'id' (and
	 *                       optionally 'name'/'pair_num', used only to make
	 *                       error messages more specific when available, and
	 *                       optionally 'withdrawn_after_round', int|null,
	 *                       used only when $round > 0 - see below).
	 * @param array $boards  List of assoc rows: board, white_player_id,
	 *                       black_player_id, result.
	 * @param array $byes    List of assoc rows: player_id, type.
	 * @param int   $round   The round number these boards/byes are for, or 0
	 *                       (default) to skip the withdrawal check entirely
	 *                       (e.g. when the caller does not know or does not
	 *                       care which round is being validated). When > 0,
	 *                       a board or bye naming a player whose
	 *                       'withdrawn_after_round' is non-null and less
	 *                       than $round is an error: that player played
	 *                       through their withdrawal round and is out from
	 *                       the following round onward.
	 * @param bool  $allow_blank_results Whether a board may carry an empty
	 *                       result. False (default) is the historic
	 *                       results-entry rule: every board must name a
	 *                       played outcome. True is the "Save pairings" path
	 *                       (docs/SPEC.md, "Decisions (2026-08-14, saving
	 *                       pairings before results)"), where a TD is
	 *                       recording who plays whom before any game has
	 *                       been played, so a blank result is the normal
	 *                       case rather than an omission. Every other rule -
	 *                       roster membership, no player twice in a round,
	 *                       withdrawal checks - still applies unchanged.
	 * @return array array( 'ok' => bool, 'errors' => string[] ).
	 */
	public static function validate_round( array $players, array $boards, array $byes, $round = 0, $allow_blank_results = false ) {
		$errors = array();

		if ( empty( $boards ) && empty( $byes ) ) {
			return array(
				'ok'     => false,
				'errors' => $allow_blank_results
					? array( 'This round has no boards and no byes. Add at least one pairing or bye before saving.' )
					: array( 'This round has no boards and no byes. Enter at least one result or bye before saving.' ),
			);
		}

		$round = (int) $round;

		$known_ids                   = array();
		$labels                      = array();
		$withdrawn_after_round_by_id = array();
		foreach ( $players as $player ) {
			if ( ! isset( $player['id'] ) ) {
				continue;
			}
			$id               = (int) $player['id'];
			$known_ids[ $id ] = true;
			$labels[ $id ]    = isset( $player['name'] ) ? $player['name'] : ( 'player #' . $id );

			if ( isset( $player['withdrawn_after_round'] ) && null !== $player['withdrawn_after_round'] && '' !== $player['withdrawn_after_round'] ) {
				$withdrawn_after_round_by_id[ $id ] = (int) $player['withdrawn_after_round'];
			}
		}

		$seen_boards  = array();
		$player_usage = array(); // player id => count of appearances this round.

		foreach ( $boards as $i => $board ) {
			$row_label = 'Board row ' . ( $i + 1 );

			$board_num       = isset( $board['board'] ) ? $board['board'] : null;
			$is_whole_number = is_numeric( $board_num ) && (float) $board_num === (float) (int) $board_num;
			if ( ! $is_whole_number || (int) $board_num < 1 ) {
				$errors[] = "$row_label: board number must be a positive whole number.";
			} else {
				$board_num = (int) $board_num;
				$row_label = "Board $board_num";
				if ( isset( $seen_boards[ $board_num ] ) ) {
					$errors[] = "Board number $board_num is used more than once in this round.";
				} else {
					$seen_boards[ $board_num ] = true;
				}
			}

			$white = isset( $board['white_player_id'] ) ? (int) $board['white_player_id'] : 0;
			$black = isset( $board['black_player_id'] ) ? (int) $board['black_player_id'] : 0;

			if ( $white === $black ) {
				$errors[] = "$row_label: White and Black must be different players.";
			}

			if ( ! isset( $known_ids[ $white ] ) ) {
				$errors[] = "$row_label: the selected White player is not on this section's roster.";
			} else {
				self::record_usage( $player_usage, $white, $labels, $errors, $row_label );
				if ( $round > 0 ) {
					self::check_not_withdrawn( $white, $withdrawn_after_round_by_id, $labels, $errors, $row_label, $round );
				}
			}

			if ( ! isset( $known_ids[ $black ] ) ) {
				$errors[] = "$row_label: the selected Black player is not on this section's roster.";
			} elseif ( $black !== $white ) {
				self::record_usage( $player_usage, $black, $labels, $errors, $row_label );
				if ( $round > 0 ) {
					self::check_not_withdrawn( $black, $withdrawn_after_round_by_id, $labels, $errors, $row_label, $round );
				}
			}

			$result = isset( $board['result'] ) ? strtoupper( trim( (string) $board['result'] ) ) : '';
			if ( $allow_blank_results && '' === $result ) {
				continue; // pairing recorded, result still to come.
			}
			if ( ! in_array( $result, self::GAME_RESULTS, true ) ) {
				$errors[] = $allow_blank_results
					? "$row_label: result must be blank, or one of W, B, D, FW, FB, FD."
					: "$row_label: result must be one of W, B, D, FW, FB, FD.";
			}
		}

		foreach ( $byes as $i => $bye ) {
			$row_label = 'Bye row ' . ( $i + 1 );

			$player_id = isset( $bye['player_id'] ) ? (int) $bye['player_id'] : 0;
			if ( ! isset( $known_ids[ $player_id ] ) ) {
				$errors[] = "$row_label: the selected player is not on this section's roster.";
			} else {
				self::record_usage( $player_usage, $player_id, $labels, $errors, $row_label );
				if ( $round > 0 ) {
					self::check_not_withdrawn( $player_id, $withdrawn_after_round_by_id, $labels, $errors, $row_label, $round );
				}
			}

			$type = isset( $bye['type'] ) ? strtoupper( trim( (string) $bye['type'] ) ) : '';
			if ( ! in_array( $type, self::BYE_TYPES, true ) ) {
				$errors[] = "$row_label: bye type must be one of B, H, U.";
			}
		}

		return array(
			'ok'     => empty( $errors ),
			'errors' => $errors,
		);
	}

	/**
	 * Sanitizes and filters the read-only "carried" byes posted alongside a
	 * round (item 35, docs/SPEC.md): byes held by players withdrawn as of
	 * before the round, preserved verbatim on resave. Kept out of
	 * validate_round() on purpose - that method correctly rejects a bye for a
	 * withdrawn player, but a carried bye is existing history being kept, not a
	 * new pairing, so it is allowed here and merged into the written byes only
	 * after the editable set has validated.
	 *
	 * Drops any entry whose type is not a real bye (B/H/U), whose player is not
	 * on the roster, who is already on a board this round, who already has an
	 * editable bye this round, or who is a duplicate. In every collision the
	 * editable data the TD actively entered wins.
	 *
	 * Pure (no WP functions) so the zero-dependency test runner can exercise
	 * it: callers wp_unslash() the raw POST array before handing it in.
	 *
	 * @param array $posted_carried    player_id => raw bye type, from the form.
	 * @param array $known_player_ids  [ id => true ] roster membership.
	 * @param array $boards_player_ids flat list of player ids on a board this round.
	 * @param array $editable_bye_ids  [ id => true ] players with an editable bye.
	 * @return array list of [ 'player_id' => int, 'type' => string ] for replace_round().
	 */
	public static function filter_carried_byes( array $posted_carried, array $known_player_ids, array $boards_player_ids, array $editable_bye_ids ) {
		$carried = array();
		$seen    = array();

		foreach ( $posted_carried as $player_id => $type ) {
			$player_id = (int) $player_id;
			$type      = strtoupper( trim( (string) $type ) );

			if ( ! in_array( $type, self::BYE_TYPES, true ) ) {
				continue;
			}

			if ( $player_id <= 0
				|| ! isset( $known_player_ids[ $player_id ] )
				|| in_array( $player_id, $boards_player_ids, true )
				|| isset( $editable_bye_ids[ $player_id ] )
				|| isset( $seen[ $player_id ] ) ) {
				continue;
			}

			$seen[ $player_id ] = true;
			$carried[]          = array(
				'player_id' => $player_id,
				'type'      => $type,
			);
		}

		return $carried;
	}

	/**
	 * Sanitizes and filters the player ids posted as a withdrawal (the byes
	 * area's 'WD' option, which is not a bye type - handle_save_round()
	 * strips it out of the posted byes and turns it into
	 * WPMTM_Repository::set_player_withdrawn() after the round itself
	 * saves).
	 *
	 * Audit item 36: withdrawals used to be the ONE thing in a round save
	 * that never got membership-checked. Boards and byes are checked by
	 * validate_round() ($known_ids) and carried byes by
	 * filter_carried_byes(), but 'WD' is stripped before validation runs and
	 * was then applied straight to set_player_withdrawn(), whose SQL is
	 * `WHERE id = %d` with no section scope - so a crafted POST naming any
	 * player id in the database withdrew that player, in any tournament.
	 * Everything posted here is filtered against this section's roster
	 * before it can reach a write.
	 *
	 * Also enforces the withdraw-offered rule server-side. The byes area
	 * only renders the Withdraw option when a later round still exists to be
	 * dropped from (WPMTM_Round_Selector::withdraw_offered()); a render-time
	 * gate is not authorization, so the caller passes the same answer in and
	 * a withdrawal posted for the final round is dropped rather than
	 * recorded as a meaningless "out from after the last round".
	 *
	 * Pure (no WP functions) so the zero-dependency test runner can exercise
	 * it: callers wp_unslash()/sanitize the raw POST values before handing
	 * them in, the same contract filter_carried_byes() has.
	 *
	 * @param array $posted_ids       Candidate player ids (the raw keys of the
	 *                                posted byes array whose value was 'WD').
	 * @param array $known_player_ids [ id => true ] roster membership.
	 * @param bool  $withdraw_allowed WPMTM_Round_Selector::withdraw_offered()'s
	 *                                answer for the round being saved. False
	 *                                drops every withdrawal.
	 * @return int[] Unique, roster-checked player ids, in posted order.
	 */
	public static function filter_withdrawals( array $posted_ids, array $known_player_ids, $withdraw_allowed = true ) {
		if ( ! $withdraw_allowed ) {
			return array();
		}

		$withdrawals = array();
		$seen        = array();

		foreach ( $posted_ids as $player_id ) {
			$player_id = (int) $player_id;

			if ( $player_id <= 0
				|| ! isset( $known_player_ids[ $player_id ] )
				|| isset( $seen[ $player_id ] ) ) {
				continue;
			}

			$seen[ $player_id ] = true;
			$withdrawals[]      = $player_id;
		}

		return $withdrawals;
	}

	/**
	 * Tracks how many times a player id appears across the round (boards +
	 * byes) and appends a duplicate-use error the second and later times.
	 * Shared by the board loop (white, black) and the bye loop above.
	 */
	protected static function record_usage( array &$player_usage, $player_id, array $labels, array &$errors, $row_label ) {
		if ( ! isset( $player_usage[ $player_id ] ) ) {
			$player_usage[ $player_id ] = 0;
		}
		++$player_usage[ $player_id ];

		if ( $player_usage[ $player_id ] > 1 ) {
			$label    = isset( $labels[ $player_id ] ) ? $labels[ $player_id ] : ( 'player #' . $player_id );
			$errors[] = "$row_label: $label already appears elsewhere in this round (a player can only be on one board or bye per round).";
		}
	}

	/**
	 * Appends a plain-language error when $player_id withdrew before $round:
	 * withdrawn_after_round = N means the player played through round N and
	 * is out from round N + 1 onward, so only withdrawn_after_round < $round
	 * is a violation (naming them for their own withdrawal round or earlier
	 * is fine).
	 */
	protected static function check_not_withdrawn( $player_id, array $withdrawn_after_round_by_id, array $labels, array &$errors, $row_label, $round ) {
		if ( ! isset( $withdrawn_after_round_by_id[ $player_id ] ) ) {
			return;
		}

		$withdrawn_after_round = $withdrawn_after_round_by_id[ $player_id ];
		if ( $withdrawn_after_round >= $round ) {
			return;
		}

		$label    = isset( $labels[ $player_id ] ) ? $labels[ $player_id ] : ( 'player #' . $player_id );
		$errors[] = "$row_label: $label withdrew after round $withdrawn_after_round and cannot be paired for round $round.";
	}
}
