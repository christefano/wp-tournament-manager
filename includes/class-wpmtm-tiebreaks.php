<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure, WordPress-independent tiebreak engine implementing the four US
 * Chess rulebook 34E systems used by this plugin: Modified Median (34E1),
 * Solkoff (34E2), Cumulative (34E4), and Cumulative of Opposition (34E9).
 * Depends on WPMTM_Scoring::tally() for per-player score and round detail -
 * a pure class depending on another pure class, the same pattern
 * WPMTM_Pairing_Aid already uses.
 *
 * "Opponent" here means a player actually faced across the board, including
 * forfeit pairings, exactly as WPMTM_Scoring::tally()'s 'opponents' list
 * already defines it (byes are excluded, forfeits are included). An
 * opponent's "final score" is their tally() score, which already includes
 * byes.
 *
 * Documented simplification (docs/SPEC.md, "Decisions (2026-07-09,
 * tiebreaks)"): unplayed games are not converted into phantom opponents
 * with adjusted scores, a refinement some tiebreak implementations apply
 * for byes/forfeits within the Modified Median and Solkoff calculations.
 * This is an open item to confirm with an experienced TD before the first
 * rated submission where tiebreaks matter for prizes.
 */
class WPMTM_Tiebreaks {

	/**
	 * @param array $players List of assoc rows, shape per WPMTM_Scoring::tally().
	 * @param array $games   List of assoc rows, shape per WPMTM_Scoring::tally().
	 * @param array $byes    List of assoc rows, shape per WPMTM_Scoring::tally().
	 * @return array Player id => array(
	 *   'modified_median' => float,
	 *   'solkoff'         => float,
	 *   'cumulative'      => float,
	 *   'cumulative_opp'  => float,
	 * )
	 */
	public static function compute( array $players, array $games, array $byes ) {
		$tally = WPMTM_Scoring::tally( $players, $games, $byes );

		$cumulative = array();
		foreach ( $tally as $id => $entry ) {
			$cumulative[ $id ] = self::cumulative_value( $entry['rounds'] );
		}

		$result = array();
		foreach ( $tally as $id => $entry ) {
			$result[ $id ] = array(
				'modified_median' => self::modified_median( $entry, $tally ),
				'solkoff'         => self::solkoff( $entry, $tally ),
				'cumulative'      => $cumulative[ $id ],
				'cumulative_opp'  => self::cumulative_of_opposition( $entry, $cumulative ),
			);
		}

		return $result;
	}

	/**
	 * Modified Median (34E1). Classifies the player's score against half
	 * their rounds-counted (plus/minus/even), then discards the single
	 * lowest opponent score (plus), single highest (minus), or both
	 * (even), and sums what remains. With fewer opponents than the discard
	 * count, the result is simply the sum of whatever is left - possibly
	 * nothing, i.e. 0.0 - never negative and never an error.
	 */
	protected static function modified_median( array $entry, array $tally ) {
		$rounds_counted = count( $entry['rounds'] );
		$score          = $entry['score'];

		if ( $score > $rounds_counted / 2 ) {
			$classification = 'plus';
		} elseif ( $score < $rounds_counted / 2 ) {
			$classification = 'minus';
		} else {
			$classification = 'even';
		}

		$scores = self::opponent_scores( $entry, $tally );
		sort( $scores );
		$count = count( $scores );

		if ( 'plus' === $classification ) {
			if ( $count > 0 ) {
				array_shift( $scores );
			}
		} elseif ( 'minus' === $classification ) {
			if ( $count > 0 ) {
				array_pop( $scores );
			}
		} else { // even: discard both the highest and the lowest.
			if ( $count >= 2 ) {
				array_shift( $scores );
				array_pop( $scores );
			} elseif ( 1 === $count ) {
				array_shift( $scores ); // the single opponent is both the highest and the lowest.
			}
		}

		return (float) array_sum( $scores );
	}

	/** Solkoff (34E2): sum of all opponents' final scores, no discards. */
	protected static function solkoff( array $entry, array $tally ) {
		return (float) array_sum( self::opponent_scores( $entry, $tally ) );
	}

	/**
	 * Cumulative (34E4): running total after each round the player has an
	 * entry for (played or byed), summed in round order, then reduced by
	 * 1.0 for each unplayed win - a full-point bye ('B') or forfeit win
	 * ('X') token. Rounds absent from the player's rounds array simply
	 * contribute no term; the running total is only sampled at rounds the
	 * player actually has an entry for.
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

	/** Final scores of every opponent faced, in the player's round order (byes excluded, forfeits included, per tally()). */
	protected static function opponent_scores( array $entry, array $tally ) {
		$scores = array();
		foreach ( $entry['opponents'] as $opponent_id ) {
			if ( isset( $tally[ $opponent_id ] ) ) {
				$scores[] = $tally[ $opponent_id ]['score'];
			}
		}
		return $scores;
	}
}
