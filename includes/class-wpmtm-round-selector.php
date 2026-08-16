<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure, WordPress-independent round-selector math shared by
 * WPMTM_Frontend_TD's round-entry panel: which round is selected by
 * default, which rounds the "Round: 1 2 3" selector should list, and how
 * to clamp a TD-suppliable round override to the same ceiling. Extracted
 * out of WPMTM_Frontend_TD (a WP-coupled class - $wpdb-backed repository
 * calls, current_user_can(), etc.) so this arithmetic can be unit-tested
 * directly by tests/run-tests.php's zero-WP runner, the same way
 * WPMTM_Round_Entry and WPMTM_Round_Token are.
 *
 * Bug fixed here (docs/SPEC.md, 2026-07-14): a section with every round
 * 1..tot_rnds already entered used to compute a default selected round of
 * max(rounds_with_results) + 1 - one past the last real round - and the
 * round selector then listed that phantom round too ("Round: 1 2 3 4" for
 * a 3-round section), with the phantom round's empty entry form
 * selected by default. A TD could accidentally record a round the USCF
 * export was never told about. See determine_selected_round() and
 * clamp_round_override() below.
 */
class WPMTM_Round_Selector {

	/**
	 * The highest round number a section can legitimately reach: its own
	 * tot_rnds, or any round number that already has results recorded
	 * beyond it (an anomaly - e.g. a TD reduced tot_rnds after already
	 * entering a round past it), whichever is larger. Shared by
	 * determine_selected_round() and clamp_round_override() so both always
	 * agree on the same ceiling; the anomaly case deliberately still
	 * allows reaching the out-of-range round, so that data stays visible
	 * and fixable instead of hidden behind a lower ceiling.
	 *
	 * @param int   $tot_rnds            Section's configured round count.
	 * @param int[] $rounds_with_results Round numbers that already have at
	 *                                   least one game or bye recorded.
	 * @return int
	 */
	public static function max_reachable_round( $tot_rnds, array $rounds_with_results ) {
		$tot_rnds = max( 1, (int) $tot_rnds );
		return $rounds_with_results ? max( $tot_rnds, max( $rounds_with_results ) ) : $tot_rnds;
	}

	/**
	 * Default selected round: the lowest round in 1..tot_rnds with no
	 * results yet, else the final real round - never one past it (the
	 * phantom-round bug this class's docblock describes). An anomalous
	 * result recorded beyond tot_rnds still selects that round rather than
	 * being clamped away, matching max_reachable_round()'s own rationale.
	 *
	 * @param int   $tot_rnds
	 * @param int[] $rounds_with_results
	 * @return int
	 */
	public static function determine_selected_round( $tot_rnds, array $rounds_with_results ) {
		$tot_rnds = max( 1, (int) $tot_rnds );
		for ( $r = 1; $r <= $tot_rnds; $r++ ) {
			if ( ! in_array( $r, $rounds_with_results, true ) ) {
				return $r;
			}
		}
		return min(
			$rounds_with_results ? ( max( $rounds_with_results ) + 1 ) : 1,
			self::max_reachable_round( $tot_rnds, $rounds_with_results )
		);
	}

	/**
	 * The full list of round numbers (1..N) the "Round:" selector should
	 * list/link, given which round ended up selected (which may itself
	 * come from a clamped $_GET override - see clamp_round_override()).
	 *
	 * @param int   $tot_rnds
	 * @param int[] $rounds_with_results
	 * @param int   $selected_round
	 * @return int[]
	 */
	public static function display_rounds( $tot_rnds, array $rounds_with_results, $selected_round ) {
		$max_known = max( self::max_reachable_round( $tot_rnds, $rounds_with_results ), (int) $selected_round );
		return range( 1, $max_known );
	}

	/**
	 * Clamps a TD-suppliable round override (the wpmtm_round_{section_id}
	 * $_GET param) to the same ceiling determine_selected_round() itself
	 * never exceeds, so a hand-edited URL cannot open an entry form for a
	 * round past the real maximum.
	 *
	 * @param int   $requested_round
	 * @param int   $tot_rnds
	 * @param int[] $rounds_with_results
	 * @return int
	 */
	public static function clamp_round_override( $requested_round, $tot_rnds, array $rounds_with_results ) {
		$requested_round = max( 1, (int) $requested_round );
		return min( $requested_round, self::max_reachable_round( $tot_rnds, $rounds_with_results ) );
	}

	/**
	 * The rounds whose pairings are complete enough to show players on the
	 * public Pairings tab (docs/SPEC.md, 2026-07-23, player-facing pairings):
	 * a round is "published" when every player still active for it has been
	 * accounted for - either seated at a board (as white or black) or given a
	 * bye. A partially-paired round (the TD mid-entry) is deliberately NOT
	 * published, so a draft never flashes to players; because a TD saves a
	 * whole round at once, "fully paired" lines up with "the TD finished".
	 *
	 * "Still active for round R" means not withdrawn before it:
	 * withdrawn_after_round is null, or >= R (a player withdrawn after round 2
	 * played rounds 1-2, so is required in those but not in round 3+). A player
	 * id of 0 in a game row (a placeholder half of an unplayed pairing) is
	 * ignored - it can never satisfy a real player's requirement.
	 *
	 * Pure and WordPress-independent like the rest of this class, so
	 * tests/run-tests.php's zero-WP runner covers it directly.
	 *
	 * @param array[] $players  Player rows (id, withdrawn_after_round), from
	 *                          WPMTM_Frontend_Public::map_players().
	 * @param array[] $games    Game rows (round, white_player_id,
	 *                          black_player_id), from section_data_arrays().
	 * @param array[] $byes     Bye rows (player_id, round).
	 * @param int     $tot_rnds Section's configured round count.
	 * @return int[] Ascending list of published round numbers (may be empty).
	 */
	public static function published_rounds( array $players, array $games, array $byes, $tot_rnds ) {
		$tot_rnds = max( 1, (int) $tot_rnds );
		if ( empty( $players ) ) {
			return array();
		}

		// round => [ player_id => true ] for everyone accounted for that round.
		$accounted = array();
		foreach ( $games as $g ) {
			$r = (int) $g['round'];
			foreach ( array( (int) $g['white_player_id'], (int) $g['black_player_id'] ) as $pid ) {
				if ( $pid > 0 ) {
					$accounted[ $r ][ $pid ] = true;
				}
			}
		}
		foreach ( $byes as $b ) {
			$accounted[ (int) $b['round'] ][ (int) $b['player_id'] ] = true;
		}

		$published = array();
		for ( $r = 1; $r <= $tot_rnds; $r++ ) {
			$round_acc  = isset( $accounted[ $r ] ) ? $accounted[ $r ] : array();
			$has_active = false;
			$all_seated = true;
			foreach ( $players as $p ) {
				$wd = isset( $p['withdrawn_after_round'] ) ? $p['withdrawn_after_round'] : null;
				if ( null !== $wd && (int) $wd < $r ) {
					continue; // Withdrawn before this round; not required.
				}
				$has_active = true;
				if ( empty( $round_acc[ (int) $p['id'] ] ) ) {
					$all_seated = false;
					break;
				}
			}
			if ( $has_active && $all_seated ) {
				$published[] = $r;
			}
		}
		return $published;
	}

	/**
	 * Whether the round-entry Byes area should offer its "Withdraw" option
	 * (docs/SPEC.md, 2026-07-18, withdraw-dropdown gating): withdrawing "as
	 * of" a round only means anything when a LATER round still exists to be
	 * dropped from. The final round of a section has no round after it, so
	 * offering Withdraw there is meaningless - a player not playing the
	 * final round gets a bye/unplayed entry instead, never a withdrawal.
	 *
	 * @param int $selected_round The round currently being entered.
	 * @param int $tot_rnds       Section's configured round count.
	 * @return bool
	 */
	public static function withdraw_offered( $selected_round, $tot_rnds ) {
		return (int) $selected_round < (int) $tot_rnds;
	}

	/**
	 * Whether a section has every planned round entered: it has a round count
	 * set at all, and at least that many distinct rounds already carry a game
	 * or a bye.
	 *
	 * A tot_rnds of 0 is deliberately NOT complete. A Swiss section imports
	 * with tot_rnds 0 until the TD sets a round count, and treating that as
	 * "done" used to send the setup guide straight to its export step for a
	 * tournament that still needed pairing (docs/SPEC.md, 2026-07-17).
	 *
	 * Audit item 54: this one rule was derived independently in three places -
	 * WPMTM_Frontend_Public::rated_and_complete(), the $section_complete local
	 * in render_section_standings(), and the 'sections_complete' loop in
	 * WPMTM_Wizard::build_state() - each with its own copy of the tot_rnds < 1
	 * caveat above. It lives here now: this class is the pure, WordPress-free
	 * home for exactly this kind of round arithmetic, so it is unit-tested
	 * directly by tests/run-tests.php rather than only through whichever
	 * renderer happened to embed it.
	 *
	 * @param int   $tot_rnds     The section's planned round count.
	 * @param int[] $rounds_done  Distinct round numbers with a result recorded
	 *                            (WPMTM_Repository::rounds_with_results()).
	 * @return bool
	 */
	public static function section_complete( $tot_rnds, array $rounds_done ) {
		$tot_rnds = (int) $tot_rnds;
		if ( $tot_rnds < 1 ) {
			return false;
		}
		return count( $rounds_done ) >= $tot_rnds;
	}

	/**
	 * The lowest round before $round that is not fully scored yet, or 0 when
	 * every earlier round is done.
	 *
	 * Rounds are played in order, so entering results for round 5 while round
	 * 3 is still blank means either the TD is on the wrong round or round 3
	 * was never finished. Both are worth stopping, and for a Swiss section it
	 * is worse than untidy: Swiss pairings for a round are computed from the
	 * standings the previous round produced, so a gap means the later round
	 * was paired against scores that do not exist yet.
	 *
	 * Editing an EARLIER round is always fine and this never blocks it - the
	 * check only looks at rounds strictly before the one being written, so
	 * going back to fix round 3 is unaffected by round 5 being empty.
	 *
	 * @param int   $round        Round about to be written, 1-based.
	 * @param int[] $rounds_scored Rounds that are fully scored
	 *                             (WPMTM_Repository::rounds_fully_scored()).
	 * @return int The first unscored earlier round, or 0 if there is none.
	 */
	public static function first_unscored_before( $round, array $rounds_scored ) {
		$round = (int) $round;
		if ( $round < 2 ) {
			return 0;
		}
		$scored = array();
		foreach ( $rounds_scored as $r ) {
			$scored[ (int) $r ] = true;
		}
		for ( $r = 1; $r < $round; $r++ ) {
			if ( ! isset( $scored[ $r ] ) ) {
				return $r;
			}
		}
		return 0;
	}

	/**
	 * Whether a round may be PAIRED yet, which is a different question from
	 * whether it may be scored.
	 *
	 * A Round Robin or Quad schedule is fixed before round 1: who plays whom
	 * in round 5 comes from pairing numbers, not from results, so those
	 * sections may be paired as far ahead as the TD likes. A Swiss round is
	 * drawn from the current standings, so pairing round 5 before round 4 has
	 * been scored produces a draw built on incomplete scores. The asymmetry is
	 * the format's, not this plugin's.
	 *
	 * @param string $trn_type      Section pairing type.
	 * @param int    $round         Round about to be paired.
	 * @param int[]  $rounds_scored Fully scored rounds.
	 * @return bool
	 */
	public static function can_pair_round( $trn_type, $round, array $rounds_scored ) {
		if ( WPMTM_Pairing_Aid::is_round_robin_type( $trn_type ) ) {
			return true;
		}
		return 0 === self::first_unscored_before( $round, $rounds_scored );
	}
}
