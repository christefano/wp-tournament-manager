<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure, WordPress-independent pairing-aid builder for the v1 manual-pairing
 * model (docs/SPEC.md, "Manual pairing (option A) is the v1 model"): score
 * groups, a color-due indicator, and an opponents-already-played list per
 * player, plus who is still unpaired for the round the TD is about to pair.
 * The TD pairs by hand from this data; nothing here computes pairings.
 */
class WPMTM_Pairing_Aid {

	/**
	 * Section `trn_type` values that pair like a round robin: `R` (Round
	 * Robin) and `Q` (Quad, a 4-player round robin - docs/SPEC.md,
	 * "Decisions (2026-07-10, quads selectable)"). Anything else behaves
	 * as Swiss (`S`). This is the single shared definition of "which
	 * trn_type values are round-robin-like": WPMTM_Pairing_Aid and
	 * WPMTM_Pairing_Suggest (both pure, WP-independent classes) read it
	 * directly, and WPMTM_USCF_Validator and WPMTM_Frontend_TD use the
	 * is_round_robin_type() helper below instead of each keeping their own
	 * raw '`R` or `Q`' comparison.
	 */
	const RR_TYPES = array( 'R', 'Q' );

	/**
	 * How many times a round-robin-like section may run its schedule
	 * (docs/SPEC.md, "Decisions (2026-08-13, double round robin / double
	 * quads via a cycles flag)"). One cycle is the historic behavior:
	 * everyone plays everyone once. Two cycles is a double round robin,
	 * and a 4-player double round robin is the "double quad" format: six
	 * rounds, every pair meeting twice, once with each color.
	 *
	 * Two is the supported ceiling because that is what the section editor
	 * offers and what the tests cover. The scheduling math below is not
	 * limited to two, so raising this constant is the only change a triple
	 * round robin would need.
	 */
	const MAX_CYCLES = 2;

	/**
	 * Whether a section `trn_type` value should use round-robin
	 * (schedule-based) pairing behavior rather than Swiss (score-group)
	 * behavior.
	 *
	 * @param string $trn_type Section pairing type.
	 * @return bool
	 */
	public static function is_round_robin_type( $trn_type ) {
		return in_array( $trn_type, self::RR_TYPES, true );
	}

	/**
	 * Coerces any stored or posted cycles value into the supported range.
	 * Anything unusable (empty, zero, negative, non-numeric) falls back to
	 * a single cycle, so a missing value can never silently double a
	 * section's schedule.
	 *
	 * @param mixed $cycles Raw cycles value.
	 * @return int 1..MAX_CYCLES.
	 */
	public static function normalize_cycles( $cycles ) {
		$cycles = (int) $cycles;
		if ( $cycles < 1 ) {
			return 1;
		}
		return min( $cycles, self::MAX_CYCLES );
	}

	/**
	 * Auto-fill rounds count for a round-robin-like section (docs/SPEC.md,
	 * "Decisions (2026-07-16, auto-set Round Robin / Quad rounds)"):
	 * every player faces every other player exactly once, so an even
	 * roster needs player_count - 1 rounds (the standard circle-method
	 * round count) and an odd roster needs player_count rounds (everyone
	 * sits out exactly one round via a bye). Quad ('Q') is always fixed
	 * at 3 rounds regardless of its actual player count - a quad is a
	 * 4-player round robin by definition, and a partially-filled quad
	 * still runs the same 3-round schedule.
	 *
	 * A section running two cycles doubles that count, so a double quad is
	 * 6 rounds and a double round robin over 8 players is 14. A section
	 * with too few players to schedule stays at 0 whatever the cycles
	 * value, since doubling nothing is still nothing.
	 *
	 * Pure and WP-independent, unit-tested directly.
	 *
	 * @param string $trn_type     Section pairing type; only 'R' and 'Q'
	 *                              are meaningful here (see RR_TYPES).
	 * @param int    $player_count Current roster size for the section.
	 * @param int    $cycles       How many times the schedule runs; see
	 *                              MAX_CYCLES. Defaults to the historic
	 *                              single cycle.
	 * @return int Suggested total rounds; 0 for a Round Robin section
	 *             with fewer than 2 players (nothing to schedule yet).
	 */
	public static function suggested_rounds( $trn_type, $player_count, $cycles = 1 ) {
		$cycles = self::normalize_cycles( $cycles );

		if ( 'Q' === $trn_type ) {
			return 3 * $cycles;
		}

		$player_count = max( 0, (int) $player_count );
		if ( $player_count < 2 ) {
			return 0;
		}

		$single_cycle = ( 0 === $player_count % 2 ) ? $player_count - 1 : $player_count;

		return $single_cycle * $cycles;
	}

	/**
	 * @param array  $players       List of assoc rows: id, pair_num, name, rating,
	 *                              and optionally 'withdrawn_after_round' (int|null;
	 *                              absent is treated the same as null/active).
	 * @param array  $games         List of assoc rows, shape per WPMTM_Scoring::tally().
	 * @param array  $byes          List of assoc rows, shape per WPMTM_Scoring::tally().
	 * @param int    $upcoming_round Round number the TD is about to pair.
	 * @param string $trn_type      Section pairing type, 'S' (default, Swiss),
	 *                              'R' (round robin), or 'Q' (quad - a 4-player
	 *                              round robin, see RR_TYPES above). 'Q' is
	 *                              treated identically to 'R' here; any other
	 *                              value is treated as 'S'. Docs/SPEC.md,
	 *                              "Decisions (2026-07-09, round robin and
	 *                              quads)": round robin still submits to USCF
	 *                              in Swiss format, but the v1 pairing aid for
	 *                              it is different, because round robin pairs
	 *                              by a fixed schedule, not by score.
	 * @return array array(
	 *   'score_groups' => array( array(
	 *     'score'   => float,
	 *     'players' => array( array(
	 *       'id', 'pair_num', 'name', 'rating',
	 *       'color_due'            => 'W'|'B'|null,
	 *       'opponents_played'     => array( pair_num, ... ),
	 *       'opponents_remaining'  => array( pair_num, ... ), // 'R' only.
	 *       'had_bye'              => bool,
	 *     ) ),
	 *   ), ... ),  // Swiss: ordered score desc, one group per score. Round
	 *              // robin: always exactly one group, players ordered by
	 *              // pair_num (the RR schedule position), 'score' unused.
	 *   'unpaired'  => array( array( 'id', 'pair_num', 'name', 'rating' ), ... ),
	 *   'withdrawn' => array( array(
	 *     'id', 'pair_num', 'name', 'rating', 'score', 'withdrawn_after_round',
	 *   ), ... ),  // players whose withdrawn_after_round < $upcoming_round.
	 *   'trn_type'  => 'S'|'R',
	 * )
	 */
	public static function build( array $players, array $games, array $byes, $upcoming_round, $trn_type = 'S', $cycles = 1 ) {
		$cycles         = self::normalize_cycles( $cycles );
		$trn_type       = self::is_round_robin_type( $trn_type ) ? 'R' : 'S';
		$tally          = WPMTM_Scoring::tally( $players, $games, $byes );
		$upcoming_round = (int) $upcoming_round;
		$pair_num_by_id = array();
		foreach ( $players as $player ) {
			$pair_num_by_id[ (int) $player['id'] ] = $player['pair_num'];
		}

		$withdrawn      = array();
		$active_players = array();

		foreach ( $players as $player ) {
			$id                    = (int) $player['id'];
			$withdrawn_after_round = self::withdrawn_after_round( $player );

			// A player who withdrew before the round being paired is no
			// longer paired at all (docs/SPEC.md, withdrawals): they drop out
			// of score groups / the RR schedule group entirely and show up
			// in the 'withdrawn' list instead, with their score frozen as of
			// the withdrawal. They still count as an opponent in other
			// players' opponents_played, since that is derived from $tally
			// directly, but they never appear in anyone's
			// opponents_remaining, since that list is built only from the
			// still-active roster below.
			if ( null !== $withdrawn_after_round && $withdrawn_after_round < $upcoming_round ) {
				$entry       = isset( $tally[ $id ] ) ? $tally[ $id ] : array( 'score' => 0.0 );
				$withdrawn[] = array(
					'id'                    => $id,
					'pair_num'              => $player['pair_num'],
					'name'                  => $player['name'],
					'rating'                => $player['rating'],
					'score'                 => $entry['score'],
					'withdrawn_after_round' => $withdrawn_after_round,
				);
				continue;
			}

			$active_players[] = $player;
		}

		$score_groups = ( 'R' === $trn_type )
			? self::build_round_robin_group( $active_players, $tally, $pair_num_by_id, $cycles )
			: self::build_score_groups( $active_players, $tally, $pair_num_by_id );

		$unpaired = array();
		foreach ( $players as $player ) {
			$id                    = (int) $player['id'];
			$withdrawn_after_round = self::withdrawn_after_round( $player );
			if ( null !== $withdrawn_after_round && $withdrawn_after_round < $upcoming_round ) {
				continue; // withdrawn players are never "unpaired" - they are not paired at all.
			}
			$rounds = isset( $tally[ $id ] ) ? $tally[ $id ]['rounds'] : array();
			if ( ! isset( $rounds[ $upcoming_round ] ) ) {
				$unpaired[] = array(
					'id'       => $id,
					'pair_num' => $player['pair_num'],
					'name'     => $player['name'],
					'rating'   => $player['rating'],
				);
			}
		}

		return array(
			'score_groups' => $score_groups,
			'unpaired'     => $unpaired,
			'withdrawn'    => $withdrawn,
			'trn_type'     => $trn_type,
		);
	}

	/** Swiss score grouping: one group per distinct score, highest first. */
	protected static function build_score_groups( array $active_players, array $tally, array $pair_num_by_id ) {
		// Group by score using an integer "half points" key so float
		// equality never has to be relied on (0.1 + 0.2 style drift).
		$groups_by_half_point = array();

		foreach ( $active_players as $player ) {
			$id    = (int) $player['id'];
			$entry = isset( $tally[ $id ] ) ? $tally[ $id ] : self::empty_tally_entry();

			$half = (int) round( $entry['score'] * 2 );

			$row = array(
				'id'               => $id,
				'pair_num'         => $player['pair_num'],
				'name'             => $player['name'],
				'rating'           => $player['rating'],
				'color_due'        => self::color_due( $entry['colors_played'], $entry['rounds'] ),
				'opponents_played' => self::opponents_played_pair_nums( $entry['opponents'], $pair_num_by_id ),
				'had_bye'          => self::had_bye( $entry['rounds'] ),
			);

			if ( ! isset( $groups_by_half_point[ $half ] ) ) {
				$groups_by_half_point[ $half ] = array(
					'score'   => $entry['score'],
					'players' => array(),
				);
			}
			$groups_by_half_point[ $half ]['players'][] = $row;
		}

		krsort( $groups_by_half_point ); // highest half-point total (score) first.

		$score_groups = array();
		foreach ( $groups_by_half_point as $group ) {
			usort( $group['players'], array( __CLASS__, 'compare_group_players' ) );
			$score_groups[] = $group;
		}

		return $score_groups;
	}

	/**
	 * Round-robin pairing aid: one schedule group holding every active
	 * player, ordered by pairing number rather than score (a round robin
	 * pairs by a fixed schedule, not by standings), with each row's
	 * 'opponents_remaining' - the active players' pair_nums this player has
	 * not yet faced the required number of times - standing in for the
	 * Swiss 'color due' + 'opponents played' pairing workflow: this is what
	 * the TD reads to see who still needs to play whom.
	 *
	 * With $cycles = 2 (a double round robin, or a double quad) an opponent
	 * stays on the remaining list until the pair has met twice, so the aid
	 * keeps guiding the second cycle instead of going blank halfway through
	 * the section. The reversed color for that second game comes from the
	 * existing color_due indicator, which by then is asking for whichever
	 * color the player did not have in the first meeting.
	 */
	protected static function build_round_robin_group( array $active_players, array $tally, array $pair_num_by_id, $cycles = 1 ) {
		$cycles = self::normalize_cycles( $cycles );
		if ( empty( $active_players ) ) {
			return array();
		}

		usort(
			$active_players,
			function ( $a, $b ) {
				return $a['pair_num'] <=> $b['pair_num'];
			}
		);

		$active_pair_nums = array_map(
			function ( $player ) {
				return $player['pair_num'];
			},
			$active_players
		);

		$players_out = array();
		foreach ( $active_players as $player ) {
			$id    = (int) $player['id'];
			$entry = isset( $tally[ $id ] ) ? $tally[ $id ] : self::empty_tally_entry();

			$opponents_played = self::opponents_played_pair_nums( $entry['opponents'], $pair_num_by_id );

			// Count meetings per opponent rather than treating "played" as a
			// set, so a second cycle can tell "met once, owed one more" apart
			// from "met twice, done". At $cycles = 1 this reduces to the
			// original set difference exactly. Keys are cast to string so a
			// pair_num arriving as '3' from the database and as 3 from a test
			// fixture count as the same opponent.
			$times_played = array();
			foreach ( $opponents_played as $opponent_pair_num ) {
				$key                  = (string) $opponent_pair_num;
				$times_played[ $key ] = isset( $times_played[ $key ] ) ? $times_played[ $key ] + 1 : 1;
			}

			$opponents_remaining = array();
			foreach ( $active_pair_nums as $candidate ) {
				if ( (string) $candidate === (string) $player['pair_num'] ) {
					continue; // nobody plays themselves.
				}
				$played = isset( $times_played[ (string) $candidate ] ) ? $times_played[ (string) $candidate ] : 0;
				if ( $played < $cycles ) {
					$opponents_remaining[] = $candidate;
				}
			}
			sort( $opponents_remaining, SORT_NUMERIC );

			$players_out[] = array(
				'id'                   => $id,
				'pair_num'             => $player['pair_num'],
				'name'                 => $player['name'],
				'rating'               => $player['rating'],
				'color_due'            => self::color_due( $entry['colors_played'], $entry['rounds'] ),
				'opponents_played'     => $opponents_played,
				'opponents_remaining'  => $opponents_remaining,
				'had_bye'              => self::had_bye( $entry['rounds'] ),
			);
		}

		// A single schedule group. 'score' carries no pairing meaning in
		// round robin (pairing is by schedule, not by standings), but the
		// key is kept so callers do not have to branch on trn_type just to
		// read $group['score'].
		return array(
			array(
				'score'   => 0.0,
				'players' => $players_out,
			),
		);
	}

	protected static function empty_tally_entry() {
		return array(
			'score'         => 0.0,
			'rounds'        => array(),
			'colors_played' => array( 'W' => 0, 'B' => 0 ),
			'opponents'     => array(),
		);
	}

	/** Maps a tally entry's opponent player ids to pair numbers, dropping any id with no known pair_num. */
	protected static function opponents_played_pair_nums( array $opponent_ids, array $pair_num_by_id ) {
		$opponents_played = array();
		foreach ( $opponent_ids as $opponent_id ) {
			if ( isset( $pair_num_by_id[ $opponent_id ] ) ) {
				$opponents_played[] = $pair_num_by_id[ $opponent_id ];
			}
		}
		return $opponents_played;
	}

	/**
	 * Reads a player row's 'withdrawn_after_round' key, treating an absent
	 * key the same as an explicit null (active).
	 *
	 * @return int|null
	 */
	protected static function withdrawn_after_round( array $player ) {
		if ( ! isset( $player['withdrawn_after_round'] ) || null === $player['withdrawn_after_round'] || '' === $player['withdrawn_after_round'] ) {
			return null;
		}
		return (int) $player['withdrawn_after_round'];
	}

	/**
	 * USCF color-equalization-then-alternation due color: fewer Whites due
	 * White, fewer Blacks due Black; equal (including zero) falls through to
	 * alternation from the most recent real-color game, or null with no
	 * games played yet. Byes and forfeits carry a null color and are
	 * excluded from both the counts and the alternation lookup.
	 *
	 * @param array $colors_played array( 'W' => int, 'B' => int ).
	 * @param array $rounds        Round-ordered rounds array, per WPMTM_Scoring::tally().
	 * @return string|null 'W', 'B', or null.
	 */
	protected static function color_due( array $colors_played, array $rounds ) {
		$whites = $colors_played['W'];
		$blacks = $colors_played['B'];

		if ( 0 === $whites && 0 === $blacks ) {
			return null;
		}
		if ( $whites < $blacks ) {
			return 'W';
		}
		if ( $blacks < $whites ) {
			return 'B';
		}

		// Equal and non-zero: due the opposite of the most recent real-color
		// game. $rounds is already sorted ascending by round number
		// (WPMTM_Scoring::tally() sorts it); walk backwards for the latest.
		$rounds_desc = array_reverse( $rounds, true );
		foreach ( $rounds_desc as $round_data ) {
			if ( null !== $round_data['color'] ) {
				return 'W' === $round_data['color'] ? 'B' : 'W';
			}
		}

		return null; // should not happen if the counts were non-zero, but stay defensive.
	}

	/** Whether the player has any bye (B/H/U) on record, any round. */
	protected static function had_bye( array $rounds ) {
		foreach ( $rounds as $round_data ) {
			if ( null === $round_data['opponent_player_id'] && in_array( $round_data['token_result'], WPMTM_Scoring::BYE_TYPES, true ) ) {
				return true;
			}
		}
		return false;
	}

	/** Within a score group: rating desc (blank last), then pair_num asc. */
	protected static function compare_group_players( array $a, array $b ) {
		$rating_a = isset( $a['rating'] ) && '' !== $a['rating'] && is_numeric( $a['rating'] ) ? (float) $a['rating'] : null;
		$rating_b = isset( $b['rating'] ) && '' !== $b['rating'] && is_numeric( $b['rating'] ) ? (float) $b['rating'] : null;

		if ( null === $rating_a && null !== $rating_b ) {
			return 1;
		}
		if ( null !== $rating_a && null === $rating_b ) {
			return -1;
		}
		if ( $rating_a !== $rating_b ) {
			return $rating_a < $rating_b ? 1 : -1;
		}

		return $a['pair_num'] <=> $b['pair_num'];
	}
}
