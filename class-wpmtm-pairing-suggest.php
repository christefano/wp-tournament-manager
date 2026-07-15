<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure, WordPress-independent pairing suggester for the TD panel.
 *
 * This is deliberately a SIMPLIFIED aid, not the full USCF pairing
 * rulebook: Swiss suggestions use top-half versus bottom-half within
 * score groups with rematch avoidance and due-color preference; round
 * robin uses the standard Berger/circle schedule by pairing number.
 * The TD reviews and edits every suggestion before saving; nothing here
 * writes anything.
 */
class WPMTM_Pairing_Suggest {

	/**
	 * Rating-band labels the club uses for its U-class sections, in
	 * ascending order - see band_for_rating() below for how a numeric
	 * rating maps onto one of these.
	 */
	const U_BANDS = array( 'U800', 'U1000', 'U1200', 'U1400', 'U1600', 'U1800', 'U2000', 'U2200' );

	/**
	 * @param array  $players  Roster rows: id, pair_num, name, rating,
	 *                         optional withdrawn_after_round (int|null).
	 * @param array  $games    Game rows, shape per WPMTM_Scoring::tally().
	 * @param array  $byes     Bye rows, shape per WPMTM_Scoring::tally().
	 * @param int    $round    Round being paired.
	 * @param string $trn_type 'S' Swiss (default), 'R' round robin, or 'Q'
	 *                         quad (a 4-player round robin - treated
	 *                         identically to 'R', see
	 *                         WPMTM_Pairing_Aid::RR_TYPES).
	 * @param string $sec_name Change 5 (U-class section awareness): the
	 *                         section's own sec_name, used only to check
	 *                         whether it names itself after one of the
	 *                         U-class bands (see u_band_advisory_notes()
	 *                         below); optional and defaults to '' (no
	 *                         advisory notes) so existing callers/tests
	 *                         that never pass it keep behaving exactly as
	 *                         before.
	 * @return array array(
	 *   'boards'        => list of array( 'white_player_id' => int, 'black_player_id' => int ),
	 *   'bye_player_id' => int|null,
	 *   'notes'         => string[],
	 * )
	 */
	public static function suggest( array $players, array $games, array $byes, $round, $trn_type = 'S', $sec_name = '' ) {
		$round = (int) $round;
		$tally = WPMTM_Scoring::tally( $players, $games, $byes );

		// Active for this round: not withdrawn before it, and not already
		// on a board or bye in it.
		$active = array();
		foreach ( $players as $p ) {
			$id        = (int) $p['id'];
			$withdrawn = isset( $p['withdrawn_after_round'] ) && null !== $p['withdrawn_after_round']
				? (int) $p['withdrawn_after_round'] : null;
			if ( null !== $withdrawn && $withdrawn < $round ) {
				continue;
			}
			if ( isset( $tally[ $id ]['rounds'][ $round ] ) ) {
				continue;
			}
			$active[] = $p;
		}

		$result = WPMTM_Pairing_Aid::is_round_robin_type( $trn_type )
			? self::suggest_round_robin( $players, $active, $round )
			: self::suggest_swiss( $active, $tally );

		// Change 5: purely advisory - appended after whichever branch above
		// already built its own notes, never altering 'boards' or
		// 'bye_player_id'. See u_band_advisory_notes()'s own docblock.
		$band_notes = self::u_band_advisory_notes( $active, $sec_name );
		if ( $band_notes ) {
			$result['notes'] = array_merge( $result['notes'], $band_notes );
		}

		return $result;
	}

	// -----------------------------------------------------------------
	// Family avoidance (docs/SPEC.md, 2026-07-14).
	// -----------------------------------------------------------------

	/**
	 * Whether two players count as the same family for pairing-avoidance
	 * purposes: best effort, not a guarantee. Two independent signals, either
	 * one is enough:
	 *
	 * 1. Shared family key - both players' normalized family_key (parent
	 *    email from ETECF import, or a TD override typed into the roster
	 *    editor - see WPMTM_Admin's players editor) are non-empty and equal.
	 * 2. Same last name - the stored name is always uppercase LAST,FIRST
	 *    (docs/SPEC.md; never mutated), so the last name is the substring
	 *    before the first comma, trimmed, compared case-insensitively.
	 *
	 * The last-name signal cannot be suppressed per pair - two unrelated
	 * players who happen to share a surname are still treated as family
	 * unless a TD gives them distinct family keys, which does not help here
	 * (this rule only ever fires on a name match already); a TD who needs
	 * to force such a pairing anyway just pairs it by hand, since Suggest
	 * pairings only ever prefills the round-entry form (WPMTM_Frontend_TD::
	 * render_suggest_link()'s docblock).
	 *
	 * @param array $a Player row with a 'name' and optional 'family_key'.
	 * @param array $b Same shape.
	 * @return bool
	 */
	public static function same_family( array $a, array $b ) {
		$key_a = self::normalize_family_key( isset( $a['family_key'] ) ? $a['family_key'] : '' );
		$key_b = self::normalize_family_key( isset( $b['family_key'] ) ? $b['family_key'] : '' );
		if ( '' !== $key_a && '' !== $key_b && $key_a === $key_b ) {
			return true;
		}

		$last_a = self::last_name( isset( $a['name'] ) ? $a['name'] : '' );
		$last_b = self::last_name( isset( $b['name'] ) ? $b['name'] : '' );
		return '' !== $last_a && '' !== $last_b && $last_a === $last_b;
	}

	/** Trim + lowercase a family key for comparison; '' for empty/missing. */
	protected static function normalize_family_key( $key ) {
		return strtolower( trim( (string) $key ) );
	}

	/**
	 * Last name from a stored "LAST,FIRST" name: the substring before the
	 * first comma, trimmed, lowercased for comparison. A name with no comma
	 * at all (should not happen - names are always stored LAST,FIRST) is
	 * treated as entirely a last name rather than throwing.
	 */
	protected static function last_name( $name ) {
		$name  = (string) $name;
		$comma = strpos( $name, ',' );
		$last  = false !== $comma ? substr( $name, 0, $comma ) : $name;
		return strtolower( trim( $last ) );
	}

	// -----------------------------------------------------------------
	// U-class section awareness (Change 5).
	// -----------------------------------------------------------------

	/**
	 * Maps a numeric rating onto the club's U-class band convention: UXXX
	 * means rated XXXX and up to (but not including) the next 200-point
	 * band. Ratings below 800 land in "U800" (the lowest band absorbs
	 * everything under it, rather than producing a nonsensical band like
	 * "U600"); ratings at or above 2200 land in "U2200" (the top band has
	 * no ceiling). A blank or non-numeric rating (unrated player, or a
	 * stray empty string from the roster) yields '' - no band, so callers
	 * treat it as "nothing to compare".
	 *
	 * @param mixed $rating
	 * @return string One of self::U_BANDS, or ''.
	 */
	public static function band_for_rating( $rating ) {
		if ( '' === (string) $rating || ! is_numeric( $rating ) ) {
			return '';
		}
		$rating = (float) $rating;
		if ( $rating < 800 ) {
			return 'U800';
		}
		if ( $rating >= 2200 ) {
			return 'U2200';
		}
		return 'U' . ( (int) floor( $rating / 200 ) * 200 );
	}

	/**
	 * When the section actually being paired names itself after one of the
	 * U-class bands (sec_name matching self::U_BANDS case-insensitively,
	 * trimmed - e.g. "U1400", "u1400", " U1400 " all match), flags every
	 * active player whose own band_for_rating( rating ) does not match that
	 * section's band. Purely advisory: this never moves a player between
	 * sections and never changes whether a suggested pairing is valid - it
	 * only adds a note the TD can act on later. Sections not named after a
	 * U-class band (the common case - "Open", "Adult Rated", etc.) get no
	 * notes at all, since there is nothing to compare against.
	 *
	 * @param array  $active   The round's active roster (same list already
	 *                         used for pairing - see suggest() above).
	 * @param string $sec_name The section's own sec_name.
	 * @return string[]
	 */
	protected static function u_band_advisory_notes( array $active, $sec_name ) {
		$sec_name = trim( (string) $sec_name );
		if ( '' === $sec_name ) {
			return array();
		}

		$section_band = null;
		foreach ( self::U_BANDS as $band ) {
			if ( 0 === strcasecmp( $band, $sec_name ) ) {
				$section_band = $band;
				break;
			}
		}
		if ( null === $section_band ) {
			return array(); // not a U-class section name - nothing to advise on.
		}

		$notes = array();
		foreach ( $active as $p ) {
			$rating = isset( $p['rating'] ) ? $p['rating'] : '';
			$band   = self::band_for_rating( $rating );
			if ( '' === $band || $band === $section_band ) {
				continue;
			}
			$notes[] = sprintf(
				'%s (rating %s) is rated for section %s, not %s.',
				WPMTM_Name::display( isset( $p['name'] ) ? $p['name'] : '' ),
				$rating,
				$band,
				$sec_name
			);
		}
		return $notes;
	}

	// -----------------------------------------------------------------
	// Swiss.
	// -----------------------------------------------------------------

	protected static function suggest_swiss( array $active, array $tally ) {
		$notes = array();

		// Order: score desc, rating desc (blank last), pair_num asc.
		usort(
			$active,
			function ( $a, $b ) use ( $tally ) {
				$score_a = isset( $tally[ $a['id'] ] ) ? $tally[ $a['id'] ]['score'] : 0.0;
				$score_b = isset( $tally[ $b['id'] ] ) ? $tally[ $b['id'] ]['score'] : 0.0;
				if ( $score_a !== $score_b ) {
					return $score_a < $score_b ? 1 : -1;
				}
				$ra = self::numeric_rating( $a );
				$rb = self::numeric_rating( $b );
				if ( null === $ra && null !== $rb ) {
					return 1;
				}
				if ( null !== $ra && null === $rb ) {
					return -1;
				}
				if ( $ra !== $rb ) {
					return $ra < $rb ? 1 : -1;
				}
				return $a['pair_num'] <=> $b['pair_num'];
			}
		);

		// Odd count: bye for the lowest player who has never had a bye,
		// else the absolute lowest.
		$bye_player_id = null;
		if ( count( $active ) % 2 === 1 ) {
			$bye_index = null;
			for ( $i = count( $active ) - 1; $i >= 0; $i-- ) {
				$id      = (int) $active[ $i ]['id'];
				$had_bye = false;
				if ( isset( $tally[ $id ] ) ) {
					foreach ( $tally[ $id ]['rounds'] as $rd ) {
						if ( null === $rd['opponent_player_id'] && in_array( $rd['token_result'], WPMTM_Scoring::BYE_TYPES, true ) ) {
							$had_bye = true;
							break;
						}
					}
				}
				if ( ! $had_bye ) {
					$bye_index = $i;
					break;
				}
			}
			if ( null === $bye_index ) {
				$bye_index = count( $active ) - 1;
				$notes[]   = 'Every remaining player has already had a bye; suggesting the lowest-ranked player again.';
			}
			$bye_player    = array_splice( $active, $bye_index, 1 );
			$bye_player_id = (int) $bye_player[0]['id'];
			$notes[]       = sprintf( 'Odd number of players: suggested bye for %d. %s.', $bye_player[0]['pair_num'], $bye_player[0]['name'] );
		}

		// Build ordered candidate list, then pair top-half vs bottom-half
		// per score group with floats, avoiding rematches.
		$boards = array();
		$pool   = array_values( $active );

		// Group by score (already sorted, so groups are contiguous runs).
		$groups = array();
		foreach ( $pool as $p ) {
			$score               = isset( $tally[ $p['id'] ] ) ? $tally[ $p['id'] ]['score'] : 0.0;
			$key                 = (string) (int) round( $score * 2 );
			$groups[ $key ][] = $p;
		}

		$carry = array(); // floaters from the previous (higher) group.
		foreach ( $groups as $group ) {
			$group = array_merge( $carry, $group );
			$carry = array();
			if ( count( $group ) % 2 === 1 ) {
				$carry[] = array_pop( $group ); // bottom player floats down.
			}
			$half = count( $group ) / 2;
			$top    = array_slice( $group, 0, $half );
			$bottom = array_slice( $group, $half );

			foreach ( $top as $i => $white_candidate ) {
				// Preferred opponent: same index in the bottom half; walk
				// forward (then backward) to dodge rematches, then family
				// (docs/SPEC.md, 2026-07-14: never-rematch stays stronger
				// than family avoidance - see pick_opponent()'s docblock).
				$opp_index      = self::pick_opponent( $white_candidate, $bottom, $i, $tally );
				$forced_rematch = false;
				if ( null === $opp_index ) {
					$opp_index      = $i; // all rematches: accept and note.
					$forced_rematch = true;
					$notes[]        = sprintf(
						'%d. %s has already played every available opponent in the score group; a rematch is suggested.',
						$white_candidate['pair_num'],
						$white_candidate['name']
					);
				}
				$opponent = $bottom[ $opp_index ];
				array_splice( $bottom, $opp_index, 1 );

				// A forced rematch already got its own note above and is a
				// stronger, mutually exclusive condition (a rematch pairing
				// is never also flagged as a family pairing here, even if
				// the two happen to also be family - the rematch note
				// already told the TD to double-check this board).
				if ( ! $forced_rematch && self::same_family( $white_candidate, $opponent ) ) {
					$notes[] = sprintf(
						'%d. %s has no available non-family opponent in this score group; a family pairing (%d. %s) is suggested.',
						$white_candidate['pair_num'],
						$white_candidate['name'],
						$opponent['pair_num'],
						$opponent['name']
					);
				}

				$boards[] = self::assign_colors( $white_candidate, $opponent, $tally );
			}
			// Any bottom players left over (only when tops ran out due to
			// odd carries) float down too.
			foreach ( $bottom as $left ) {
				$carry[] = $left;
			}
		}

		// Leftover carry after the last group: pair them among themselves.
		while ( count( $carry ) >= 2 ) {
			$a        = array_shift( $carry );
			$b        = array_shift( $carry );
			$boards[] = self::assign_colors( $a, $b, $tally );
		}

		return array(
			'boards'        => $boards,
			'bye_player_id' => $bye_player_id,
			'notes'         => $notes,
		);
	}

	/**
	 * Nearest opponent index in $bottom, preferring $preferred, then
	 * walking forward then backward the same way rematch-avoidance always
	 * has. Two passes over that same walk order (docs/SPEC.md, 2026-07-14 -
	 * never-rematch stays stronger than family avoidance):
	 *
	 * 1. Never a rematch AND never family - the ideal candidate.
	 * 2. Never a rematch (family allowed) - used only when pass 1 found
	 *    nobody, i.e. every non-rematch candidate left happens to be family;
	 *    the caller (suggest_swiss()) notes this as a forced family pairing.
	 *
	 * Null only when every candidate is a rematch (pass 2 also empty) - the
	 * caller then falls back to its own forced-rematch handling, unchanged
	 * from before family avoidance existed.
	 */
	protected static function pick_opponent( array $player, array $bottom, $preferred, array $tally ) {
		$count = count( $bottom );
		if ( 0 === $count ) {
			return null;
		}
		$preferred = min( $preferred, $count - 1 );
		$played    = isset( $tally[ $player['id'] ] ) ? $tally[ $player['id'] ]['opponents'] : array();

		$order = array( $preferred );
		for ( $step = 1; $step < $count; $step++ ) {
			if ( $preferred + $step < $count ) {
				$order[] = $preferred + $step;
			}
			if ( $preferred - $step >= 0 ) {
				$order[] = $preferred - $step;
			}
		}

		foreach ( $order as $idx ) {
			if ( in_array( (int) $bottom[ $idx ]['id'], $played, true ) ) {
				continue;
			}
			if ( self::same_family( $player, $bottom[ $idx ] ) ) {
				continue;
			}
			return $idx;
		}

		foreach ( $order as $idx ) {
			if ( ! in_array( (int) $bottom[ $idx ]['id'], $played, true ) ) {
				return $idx; // non-rematch, but family - accept; caller notes it.
			}
		}

		return null;
	}

	/**
	 * Colors for one suggested board: give each player their due color
	 * when the two differ; otherwise White to the lower-rated player
	 * (simplified equalization convention; the TD edits freely).
	 */
	protected static function assign_colors( array $a, array $b, array $tally ) {
		$due_a = self::due_color( $a, $tally );
		$due_b = self::due_color( $b, $tally );

		$a_white = true;
		if ( 'W' === $due_a && 'W' !== $due_b ) {
			$a_white = true;
		} elseif ( 'W' === $due_b && 'W' !== $due_a ) {
			$a_white = false;
		} elseif ( 'B' === $due_a && 'B' !== $due_b ) {
			$a_white = false;
		} elseif ( 'B' === $due_b && 'B' !== $due_a ) {
			$a_white = true;
		} else {
			$ra      = self::numeric_rating( $a );
			$rb      = self::numeric_rating( $b );
			$a_white = ( null === $ra ? -1 : $ra ) <= ( null === $rb ? -1 : $rb );
		}

		return array(
			'white_player_id' => (int) ( $a_white ? $a['id'] : $b['id'] ),
			'black_player_id' => (int) ( $a_white ? $b['id'] : $a['id'] ),
		);
	}

	/** Due color per equalization-then-alternation, mirroring WPMTM_Pairing_Aid. */
	protected static function due_color( array $player, array $tally ) {
		if ( ! isset( $tally[ $player['id'] ] ) ) {
			return null;
		}
		$entry  = $tally[ $player['id'] ];
		$whites = $entry['colors_played']['W'];
		$blacks = $entry['colors_played']['B'];
		if ( 0 === $whites && 0 === $blacks ) {
			return null;
		}
		if ( $whites < $blacks ) {
			return 'W';
		}
		if ( $blacks < $whites ) {
			return 'B';
		}
		foreach ( array_reverse( $entry['rounds'], true ) as $rd ) {
			if ( null !== $rd['color'] ) {
				return 'W' === $rd['color'] ? 'B' : 'W';
			}
		}
		return null;
	}

	protected static function numeric_rating( array $player ) {
		return isset( $player['rating'] ) && '' !== (string) $player['rating'] && is_numeric( $player['rating'] )
			? (float) $player['rating'] : null;
	}

	// -----------------------------------------------------------------
	// Round robin (Berger / circle method).
	// -----------------------------------------------------------------

	/**
	 * Standard circle schedule over the FULL roster by pair_num (the
	 * schedule must stay stable across rounds, so it is built from the
	 * whole section, then withdrawn/already-entered players drop out of
	 * the emitted boards). Odd rosters get a ghost seat; whoever draws
	 * the ghost is the bye suggestion.
	 */
	/**
	 * Family avoidance (docs/SPEC.md, 2026-07-14) deliberately does NOT
	 * apply here: round robin and quad sections use a fixed Berger/circle
	 * schedule by pair number, not a per-round pairing decision, so there
	 * is no opponent choice left to steer away from a family pairing - the
	 * whole schedule is fixed at section creation. A family pairing that
	 * the schedule itself produces is just how round robin works (everyone
	 * eventually plays everyone).
	 */
	protected static function suggest_round_robin( array $players, array $active, $round ) {
		$notes = array();

		$seats = $players;
		usort(
			$seats,
			function ( $a, $b ) {
				return $a['pair_num'] <=> $b['pair_num'];
			}
		);

		$ghost = false;
		if ( count( $seats ) % 2 === 1 ) {
			$ghost   = true;
			$seats[] = null; // ghost seat.
		}

		$n = count( $seats );
		if ( $n < 2 ) {
			return array(
				'boards'        => array(),
				'bye_player_id' => null,
				'notes'         => array( 'Not enough players for a round robin schedule.' ),
			);
		}

		$rounds_in_cycle = $n - 1;
		$r               = ( ( (int) $round - 1 ) % $rounds_in_cycle );

		// Circle method: fix the last seat, rotate the first n-1 seats by
		// $r positions.
		$fixed    = $seats[ $n - 1 ];
		$rotating = array_slice( $seats, 0, $n - 1 );
		$rot      = array_merge(
			array_slice( $rotating, count( $rotating ) - $r ),
			array_slice( $rotating, 0, count( $rotating ) - $r )
		);

		$pairs   = array();
		$pairs[] = array( $rot[0], $fixed );
		for ( $i = 1; $i < $n / 2; $i++ ) {
			$pairs[] = array( $rot[ $i ], $rot[ $n - 1 - $i ] );
		}

		$active_ids = array();
		foreach ( $active as $p ) {
			$active_ids[ (int) $p['id'] ] = true;
		}

		$boards        = array();
		$bye_player_id = null;
		foreach ( $pairs as $i => $pair ) {
			list( $x, $y ) = $pair;
			if ( null === $x || null === $y ) {
				$real = null === $x ? $y : $x;
				if ( $real && isset( $active_ids[ (int) $real['id'] ] ) ) {
					$bye_player_id = (int) $real['id'];
					$notes[]       = sprintf( 'Schedule bye this round: %d. %s.', $real['pair_num'], $real['name'] );
				}
				continue;
			}
			$x_active = isset( $active_ids[ (int) $x['id'] ] );
			$y_active = isset( $active_ids[ (int) $y['id'] ] );
			if ( ! $x_active || ! $y_active ) {
				if ( $x_active || $y_active ) {
					$present = $x_active ? $x : $y;
					$absent  = $x_active ? $y : $x;
					$notes[] = sprintf(
						'%d. %s has no schedule opponent this round (%d. %s is withdrawn or already entered); assign a bye or repair by hand.',
						$present['pair_num'],
						$present['name'],
						$absent['pair_num'],
						$absent['name']
					);
				}
				continue;
			}
			// Colors alternate by round parity for fairness; the circle
			// convention gives the first-listed player White on even
			// pair indexes and Black on odd ones, then flips each round.
			$first_white = ( ( $i + $r ) % 2 ) === 0;
			$boards[]    = array(
				'white_player_id' => (int) ( $first_white ? $x['id'] : $y['id'] ),
				'black_player_id' => (int) ( $first_white ? $y['id'] : $x['id'] ),
			);
		}

		if ( $ghost && null === $bye_player_id && count( $active ) % 2 === 1 ) {
			$notes[] = 'The scheduled bye player is unavailable; pick the bye by hand.';
		}

		return array(
			'boards'        => $boards,
			'bye_player_id' => $bye_player_id,
			'notes'         => $notes,
		);
	}
}
