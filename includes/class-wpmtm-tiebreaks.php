<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure, WordPress-independent tiebreak engine implementing the four US
 * Chess rulebook 34E systems used by this plugin: Modified Median (34E1),
 * Solkoff (34E2), Cumulative (34E3), and Cumulative of Opposition (34E9).
 * Depends on WPMTM_Scoring::tally() for per-player score and round detail -
 * a pure class depending on another pure class, the same pattern
 * WPMTM_Pairing_Aid already uses.
 *
 * "Opponent" here means a player actually faced across the board, including
 * forfeit pairings, exactly as WPMTM_Scoring::tally()'s 'opponents' list
 * already defines it (byes are excluded, forfeits are included).
 *
 * Unplayed-game adjustment (34E1, applies to 34E2 via 34E1): when an
 * opponent's final score is used for Modified Median or Solkoff, every
 * unplayed round of THAT opponent counts as 0.5 instead of its real value;
 * played games (W/L/D) keep their real value. "Unplayed" means either:
 *   - a bye of any point value, or a forfeit win/loss/draw (the opponent has
 *     a row for the round, just not a played game), or
 *   - a round that has HAPPENED elsewhere in the section (see
 *     rounds_happened() below) but the opponent has no row for at all -
 *     they withdrew before it, or joined the section after it.
 * The tied player's OWN unplayed rounds, by the same two definitions, count
 * as phantom opponents scoring 0 (they have no real opponent to credit).
 * See adjusted_opponent_score() and opponent_scores() below.
 *
 * The second bullet is what tells "this round hasn't been reached by the
 * field yet" apart from "this round happened and this player just wasn't in
 * it": rounds_happened() is derived from the section's OWN game/bye rows -
 * a round with zero rows anywhere hasn't been played by anyone yet, so no
 * player is treated as having skipped it. A round with at least one row
 * (by any player) has happened, so any OTHER player still missing a row for
 * it was out of the field for that round.
 */
class WPMTM_Tiebreaks {

	/** Result tokens for unplayed games (bye of any type, or any forfeit outcome). */
	const UNPLAYED_TOKENS = array( 'B', 'H', 'U', 'X', 'F', 'Z' );

	/**
	 * @param array $players      List of assoc rows, shape per WPMTM_Scoring::tally().
	 * @param array $games        List of assoc rows, shape per WPMTM_Scoring::tally().
	 * @param array $byes         List of assoc rows, shape per WPMTM_Scoring::tally().
	 * @param int   $total_rounds The tournament's announced total round count (section's
	 *                            tot_rnds), used only to trigger 34E1's nine-or-more-round
	 *                            two-score discard. 0 (default) behaves as fewer than nine.
	 * @return array Player id => array(
	 *   'modified_median' => float,
	 *   'solkoff'         => float,
	 *   'cumulative'      => float,
	 *   'cumulative_opp'  => float,
	 * )
	 */
	public static function compute( array $players, array $games, array $byes, $total_rounds = 0 ) {
		$tally           = WPMTM_Scoring::tally( $players, $games, $byes );
		$rounds_happened = self::rounds_happened( $games, $byes );

		$cumulative = array();
		foreach ( $tally as $id => $entry ) {
			$cumulative[ $id ] = self::cumulative_value( $entry['rounds'] );
		}

		$result = array();
		foreach ( $tally as $id => $entry ) {
			$result[ $id ] = array(
				'modified_median' => self::modified_median( $entry, $tally, (int) $total_rounds, $rounds_happened ),
				'solkoff'         => self::solkoff( $entry, $tally, $rounds_happened ),
				'cumulative'      => $cumulative[ $id ],
				'cumulative_opp'  => self::cumulative_of_opposition( $entry, $cumulative ),
			);
		}

		return $result;
	}

	/**
	 * Every round number with at least one recorded game or bye, for ANY
	 * player passed in (i.e. every round that has actually happened in this
	 * section so far, as opposed to a round the field hasn't reached yet).
	 * $games/$byes are already scoped to one section by every caller
	 * (WPMTM_Scoring::standings()'s own caller reads them via
	 * section_data_arrays()), so this never mixes rounds across sections.
	 */
	protected static function rounds_happened( array $games, array $byes ) {
		$rounds = array();

		// Both loops mirror WPMTM_Scoring::tally()'s own defensive filters:
		// a row tally() discards contributes nothing to anybody's rounds, so
		// counting its round as "happened" here would invent a phantom
		// unplayed game for every player in the section.
		foreach ( $games as $game ) {
			$result = isset( $game['result'] ) ? strtoupper( (string) $game['result'] ) : '';
			if ( ! isset( WPMTM_Scoring::RESULT_TOKEN_MAP[ $result ] ) ) {
				continue;
			}
			$rounds[ isset( $game['round'] ) ? (int) $game['round'] : 0 ] = true;
		}
		foreach ( $byes as $bye ) {
			$type = isset( $bye['type'] ) ? strtoupper( (string) $bye['type'] ) : '';
			if ( ! in_array( $type, WPMTM_Scoring::BYE_TYPES, true ) ) {
				continue;
			}
			$rounds[ isset( $bye['round'] ) ? (int) $bye['round'] : 0 ] = true;
		}

		return array_keys( $rounds );
	}

	/**
	 * Modified Median (34E1). Classifies the player's score as plus, minus,
	 * or even, then discards the lowest opponent score(s) (plus), highest
	 * (minus), or both ends (even), and sums what remains. Tournaments of
	 * nine or more rounds discard two scores per side instead of one. With
	 * fewer opponents than the discard count, the result is simply the sum
	 * of whatever is left - possibly nothing, i.e. 0.0 - never negative and
	 * never an error.
	 *
	 * 34E1 defines an even score as "exactly one half of the MAXIMUM
	 * POSSIBLE score", so the denominator is the number of rounds the
	 * section has actually played (count of $rounds_happened), NOT the
	 * number of rounds this particular player has an entry for. Those two
	 * agree for a player who played every round, but diverge sharply for a
	 * player who withdrew: someone who won round 1 of a five-round section
	 * and then withdrew has scored 1.0 against a maximum of 5.0, which is a
	 * MINUS score (discard the highest opponent score), even though 1.0 is
	 * above half of the single round they personally played. Falls back to
	 * the player's own round count only when the section has no recorded
	 * rounds at all, where every score is 0 and the classification is even
	 * either way.
	 */
	protected static function modified_median( array $entry, array $tally, $total_rounds, array $rounds_happened ) {
		$max_possible = count( $rounds_happened );
		if ( 0 === $max_possible ) {
			$max_possible = count( $entry['rounds'] );
		}
		$score = $entry['score'];

		if ( $score > $max_possible / 2 ) {
			$classification = 'plus';
		} elseif ( $score < $max_possible / 2 ) {
			$classification = 'minus';
		} else {
			$classification = 'even';
		}

		$scores = self::opponent_scores( $entry, $tally, $rounds_happened );
		sort( $scores );
		$count = count( $scores );

		// The nine-or-more-round rule keys off the tournament's announced
		// length; when a caller doesn't supply it, fall back to the rounds
		// actually played, which is the same number for a finished section.
		$rounds_for_discard_rule = $total_rounds > 0 ? $total_rounds : count( $rounds_happened );
		$discard                 = $rounds_for_discard_rule >= 9 ? 2 : 1;

		if ( 'plus' === $classification ) {
			$n = min( $discard, $count );
			array_splice( $scores, 0, $n );
		} elseif ( 'minus' === $classification ) {
			$n = min( $discard, $count );
			array_splice( $scores, $count - $n, $n );
		} else { // even: discard both the highest and the lowest, up to $discard each.
			$n = min( $discard, (int) floor( $count / 2 ) );
			for ( $i = 0; $i < $n; $i++ ) {
				array_shift( $scores );
				array_pop( $scores );
			}
			if ( 0 === $n && 1 === $count ) {
				array_shift( $scores ); // the single opponent is both the highest and the lowest.
			}
		}

		return (float) array_sum( $scores );
	}

	/** Solkoff (34E2): sum of all opponents' final scores, no discards. */
	protected static function solkoff( array $entry, array $tally, array $rounds_happened ) {
		return (float) array_sum( self::opponent_scores( $entry, $tally, $rounds_happened ) );
	}

	/**
	 * Cumulative (34E3): running total after each round the player has an
	 * entry for (played or byed), summed in round order, then reduced by
	 * 1.0 for each unplayed win - a full-point bye ('B') or forfeit win
	 * ('X') token - and by 0.5 for each unplayed draw - a half-point bye
	 * ('H') or forfeit draw ('Z') token. Rounds absent from the player's
	 * rounds array simply contribute no term; the running total is only
	 * sampled at rounds the player actually has an entry for. Unlike
	 * Modified Median/Solkoff, the rulebook doesn't ask Cumulative to invent
	 * a term for a round a player has no row for at all, so rounds_happened
	 * plays no part here.
	 *
	 * @param array $rounds Round-number-keyed, ascending (WPMTM_Scoring::tally() sorts it).
	 */
	protected static function cumulative_value( array $rounds ) {
		$running    = 0.0;
		$cumulative = 0.0;
		$adjustment = 0.0;

		foreach ( $rounds as $round_data ) {
			$token    = $round_data['token_result'];
			$running += WPMTM_Scoring::SCORE_MAP[ $token ];
			$cumulative += $running;

			if ( 'B' === $token || 'X' === $token ) {
				$adjustment += 1.0;
			} elseif ( 'H' === $token || 'Z' === $token ) {
				$adjustment += 0.5;
			}
		}

		return $cumulative - $adjustment;
	}

	/** Cumulative of Opposition (34E9): sum of each opponent's Cumulative value. */
	protected static function cumulative_of_opposition( array $entry, array $cumulative ) {
		$sum = 0.0;
		foreach ( $entry['opponents'] as $opponent_id ) {
			if ( isset( $cumulative[ $opponent_id ] ) ) {
				$sum += $cumulative[ $opponent_id ];
			}
		}
		return $sum;
	}

	/**
	 * Opponent scores for Modified Median / Solkoff (34E1, inherited by
	 * 34E2): each real opponent's ADJUSTED final score (see
	 * adjusted_opponent_score()), in the player's round order, plus one
	 * phantom 0 for each of the player's OWN unplayed rounds - a bye of any
	 * point value, or a round in $rounds_happened the player has no row for
	 * at all (see this class's docblock) - since neither has a real
	 * opponent to credit, and 34E1 counts them as an opponent scoring 0.
	 */
	protected static function opponent_scores( array $entry, array $tally, array $rounds_happened ) {
		$scores = array();
		foreach ( $entry['opponents'] as $opponent_id ) {
			if ( isset( $tally[ $opponent_id ] ) ) {
				$scores[] = self::adjusted_opponent_score( $tally[ $opponent_id ], $rounds_happened );
			}
		}

		$own_rounds = $entry['rounds'];
		foreach ( $own_rounds as $round_data ) {
			if ( in_array( $round_data['token_result'], WPMTM_Scoring::BYE_TYPES, true ) ) {
				$scores[] = 0.0;
			}
		}
		foreach ( $rounds_happened as $round_number ) {
			if ( ! isset( $own_rounds[ $round_number ] ) ) {
				$scores[] = 0.0;
			}
		}

		return $scores;
	}

	/**
	 * An opponent's final score, adjusted per 34E1: every one of the
	 * opponent's OWN unplayed rounds counts as 0.5 regardless of its real
	 * point value; played games (W/L/D) keep their real value. "Unplayed"
	 * covers both a recorded bye/forfeit row and a round in
	 * $rounds_happened the opponent has no row for at all (see this class's
	 * docblock). This adjusted figure is used only when the opponent is
	 * being credited toward someone else's Modified Median/Solkoff - their
	 * own score/standing is unaffected.
	 */
	protected static function adjusted_opponent_score( array $opponent_entry, array $rounds_happened ) {
		$score      = 0.0;
		$own_rounds = $opponent_entry['rounds'];

		foreach ( $own_rounds as $round_data ) {
			$token  = $round_data['token_result'];
			$score += in_array( $token, self::UNPLAYED_TOKENS, true ) ? 0.5 : WPMTM_Scoring::SCORE_MAP[ $token ];
		}
		foreach ( $rounds_happened as $round_number ) {
			if ( ! isset( $own_rounds[ $round_number ] ) ) {
				$score += 0.5;
			}
		}

		return $score;
	}
}
