<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure, WordPress-independent scoring engine. Turns the per-game /
 * per-bye rows a section holds (docs/SPEC.md, "Database schema (approved
 * 2026-07-08)") into per-player score and round-by-round detail, and into
 * sorted standings.
 *
 * Tiebreaks (Modified Median, Solkoff, Cumulative, Cumulative of
 * Opponents) landed in step 5 (docs/SPEC.md, "Revised build order") via
 * WPMTM_Tiebreaks; standings() below merges them into each row and sorts
 * by the full application order.
 */
class WPMTM_Scoring {

	/**
	 * Result-letter to points, per docs/SPEC.md's "Score mapping":
	 * W,B,X,N = 1.0 (win / full-point bye / forfeit win / asymmetric win);
	 * D,H,Z,R = 0.5 (draw / half-point bye / forfeit draw / asymmetric draw);
	 * L,F,U,S = 0.0 (loss / forfeit loss / zero-point bye / asymmetric loss).
	 * The letters are disjoint between game results (W/L/D/X/F/Z/N/S/R) and
	 * bye types (B/H/U), so one flat map covers both without ambiguity.
	 */
	const SCORE_MAP = array(
		'W' => 1.0,
		'B' => 1.0,
		'X' => 1.0,
		'N' => 1.0,
		'D' => 0.5,
		'H' => 0.5,
		'Z' => 0.5,
		'R' => 0.5,
		'L' => 0.0,
		'F' => 0.0,
		'U' => 0.0,
		'S' => 0.0,
	);

	/**
	 * Maps a wpmtm_games.result value to the round-token result letter and
	 * color each side gets. This is a deliberate mirror of
	 * WPMTM_Schema::RESULT_TOKEN_MAP - that copy is the DBF/export source of
	 * truth, this one lets the scoring engine stay a pure class with no
	 * WordPress dependency (required so tests/run-tests.php can cover it).
	 * The two must be kept in sync; there is no third result-to-token
	 * mapping anywhere else in the codebase.
	 */
	const RESULT_TOKEN_MAP = array(
		'W'  => array(
			'white' => array( 'result' => 'W', 'color' => 'W' ),
			'black' => array( 'result' => 'L', 'color' => 'B' ),
		),
		'B'  => array(
			'white' => array( 'result' => 'L', 'color' => 'W' ),
			'black' => array( 'result' => 'W', 'color' => 'B' ),
		),
		'D'  => array(
			'white' => array( 'result' => 'D', 'color' => 'W' ),
			'black' => array( 'result' => 'D', 'color' => 'B' ),
		),
		'FW' => array(
			'white' => array( 'result' => 'X', 'color' => null ),
			'black' => array( 'result' => 'F', 'color' => null ),
		),
		'FB' => array(
			'white' => array( 'result' => 'F', 'color' => null ),
			'black' => array( 'result' => 'X', 'color' => null ),
		),
		'FD' => array(
			'white' => array( 'result' => 'Z', 'color' => null ),
			'black' => array( 'result' => 'Z', 'color' => null ),
		),
	);

	/** Allowed wpmtm_byes.type values and their round-token result letter (identical). */
	const BYE_TYPES = array( 'B', 'H', 'U' );

	/**
	 * Tallies score and round-by-round detail for every player.
	 *
	 * @param array $players List of assoc rows: id, pair_num, name, rating.
	 * @param array $games   List of assoc rows: round, board, white_player_id,
	 *                       black_player_id, result (one of W,B,D,FW,FB,FD).
	 * @param array $byes    List of assoc rows: player_id, round, type (one of B,H,U).
	 * @return array Player id => array(
	 *   'score'         => float,
	 *   'rounds'        => array( round_number => array(
	 *                        'token_result'        => string,
	 *                        'opponent_player_id'   => int|null,
	 *                        'color'                => 'W'|'B'|null,
	 *                        'board'                => int|null,
	 *                      ) ),
	 *   'colors_played' => array( 'W' => int, 'B' => int ),
	 *   'opponents'     => array( int, ... )  // opponent ids, round order, byes excluded.
	 * )
	 */
	public static function tally( array $players, array $games, array $byes ) {
		$data = array();
		foreach ( $players as $player ) {
			$id          = (int) $player['id'];
			$data[ $id ] = array(
				'score'         => 0.0,
				'rounds'        => array(),
				'colors_played' => array( 'W' => 0, 'B' => 0 ),
				'opponents'     => array(),
			);
		}

		foreach ( $games as $game ) {
			$result = isset( $game['result'] ) ? strtoupper( (string) $game['result'] ) : '';
			if ( ! isset( self::RESULT_TOKEN_MAP[ $result ] ) ) {
				continue; // defensive: malformed row, skip rather than fatal.
			}

			$round = isset( $game['round'] ) ? (int) $game['round'] : 0;
			$board = isset( $game['board'] ) ? (int) $game['board'] : null;
			$white = isset( $game['white_player_id'] ) ? (int) $game['white_player_id'] : 0;
			$black = isset( $game['black_player_id'] ) ? (int) $game['black_player_id'] : 0;
			$map   = self::RESULT_TOKEN_MAP[ $result ];

			self::apply_side( $data, $white, $black, $round, $board, $map['white'] );
			self::apply_side( $data, $black, $white, $round, $board, $map['black'] );
		}

		foreach ( $byes as $bye ) {
			$type = isset( $bye['type'] ) ? strtoupper( (string) $bye['type'] ) : '';
			if ( ! in_array( $type, self::BYE_TYPES, true ) ) {
				continue; // defensive: malformed row, skip rather than fatal.
			}

			$player_id = isset( $bye['player_id'] ) ? (int) $bye['player_id'] : 0;
			$round     = isset( $bye['round'] ) ? (int) $bye['round'] : 0;

			if ( ! isset( $data[ $player_id ] ) ) {
				continue; // bye for a player not in the roster passed in.
			}

			$data[ $player_id ]['rounds'][ $round ] = array(
				'token_result'       => $type,
				'opponent_player_id' => null,
				'color'              => null,
				'board'              => null,
			);
			$data[ $player_id ]['score'] += self::SCORE_MAP[ $type ];
		}

		// Order each player's rounds by round number, then derive the
		// round-ordered opponents list from that order (byes and any round
		// with no opponent are excluded; forfeits DO have a real opponent
		// and are included, since a forfeited pairing still counts for
		// "already played" purposes).
		foreach ( $data as $id => &$entry ) {
			ksort( $entry['rounds'] );
			$entry['opponents'] = array();
			foreach ( $entry['rounds'] as $round_data ) {
				if ( null !== $round_data['opponent_player_id'] ) {
					$entry['opponents'][] = $round_data['opponent_player_id'];
				}
			}
		}
		unset( $entry );

		return $data;
	}

	/**
	 * Applies one side of a game to the running tally: sets that round's
	 * entry, adds the score, and (for played games only, since forfeits
	 * carry a null color) increments the color-played count. Skips silently
	 * if the player id is not part of the roster passed to tally().
	 */
	protected static function apply_side( array &$data, $player_id, $opponent_id, $round, $board, array $side ) {
		if ( ! isset( $data[ $player_id ] ) ) {
			return;
		}

		$data[ $player_id ]['rounds'][ $round ] = array(
			'token_result'       => $side['result'],
			'opponent_player_id' => $opponent_id,
			'color'              => $side['color'],
			'board'              => $board,
		);
		$data[ $player_id ]['score'] += self::SCORE_MAP[ $side['result'] ];

		if ( null !== $side['color'] ) {
			$data[ $player_id ]['colors_played'][ $side['color'] ]++;
		}
	}

	/**
	 * Standings sorted by score desc, then Modified Median desc, Solkoff
	 * desc, Cumulative desc, Cumulative of Opponents desc (US Chess 34E,
	 * via WPMTM_Tiebreaks), then rating desc (blank/non-numeric ratings
	 * last), then name asc.
	 *
	 * @param array $players
	 * @param array $games
	 * @param array $byes
	 * @return array List of rows: each player's fields (id, pair_num, name,
	 *               rating, and 'withdrawn_after_round' when the caller's
	 *               $players rows carry it - array_merge() below passes it
	 *               through untouched, since a withdrawn player's score
	 *               freezes naturally once no further games/byes exist for
	 *               them, needing no separate handling here) plus 'score',
	 *               'rounds', 'colors_played', 'opponents' from tally(), and
	 *               'modified_median', 'solkoff', 'cumulative',
	 *               'cumulative_opp' from WPMTM_Tiebreaks::compute().
	 */
	public static function standings( array $players, array $games, array $byes ) {
		$tally     = self::tally( $players, $games, $byes );
		$tiebreaks = WPMTM_Tiebreaks::compute( $players, $games, $byes );

		$empty_tiebreaks = array(
			'modified_median' => 0.0,
			'solkoff'         => 0.0,
			'cumulative'      => 0.0,
			'cumulative_opp'  => 0.0,
		);

		$rows = array();
		foreach ( $players as $player ) {
			$id     = (int) $player['id'];
			$entry  = isset( $tally[ $id ] ) ? $tally[ $id ] : array(
				'score'         => 0.0,
				'rounds'        => array(),
				'colors_played' => array( 'W' => 0, 'B' => 0 ),
				'opponents'     => array(),
			);
			$tb     = isset( $tiebreaks[ $id ] ) ? $tiebreaks[ $id ] : $empty_tiebreaks;
			$rows[] = array_merge( $player, $entry, $tb );
		}

		usort( $rows, array( __CLASS__, 'compare_standings_rows' ) );

		return $rows;
	}

	/**
	 * Public read-only crosstable ("wall chart"): one row per player, in
	 * pair_num order rather than rank, with a compact per-round result cell
	 * - the same token format the standings table's per-round columns
	 * already use (result letter plus opponent pair_num for a played game,
	 * a bare bye letter for a bye, nothing for a round with no entry) -
	 * plus a running cumulative score sampled after each round the player
	 * has an entry for, and the final total.
	 *
	 * @param array $players
	 * @param array $games
	 * @param array $byes
	 * @return array List of rows: each player's fields (id, pair_num, name,
	 *               rating, and 'withdrawn_after_round' when present, passed
	 *               through untouched via array_merge()) plus:
	 *               'rounds' => array( round_number => array(
	 *                   'cell'    => string,  // e.g. "W12", "L3", "D5", "B", "H".
	 *                   'running' => float,   // cumulative score through this round.
	 *               ) ),  // only rounds the player has an entry for.
	 *               'score'  => float,  // final total, same as tally()'s score.
	 */
	public static function crosstable( array $players, array $games, array $byes ) {
		$tally = self::tally( $players, $games, $byes );

		$pair_num_by_id = array();
		foreach ( $players as $player ) {
			$pair_num_by_id[ (int) $player['id'] ] = $player['pair_num'];
		}

		$rows = array();
		foreach ( $players as $player ) {
			$id    = (int) $player['id'];
			$entry = isset( $tally[ $id ] ) ? $tally[ $id ] : array(
				'score'  => 0.0,
				'rounds' => array(),
			);

			$rounds  = array();
			$running = 0.0;
			foreach ( $entry['rounds'] as $round_num => $round_data ) {
				$running += self::SCORE_MAP[ $round_data['token_result'] ];

				$cell = $round_data['token_result'];
				if ( null !== $round_data['opponent_player_id'] && isset( $pair_num_by_id[ $round_data['opponent_player_id'] ] ) ) {
					$cell .= $pair_num_by_id[ $round_data['opponent_player_id'] ];
				}

				$rounds[ $round_num ] = array(
					'cell'    => $cell,
					'running' => $running,
				);
			}

			$rows[] = array_merge(
				$player,
				array(
					'rounds' => $rounds,
					'score'  => $entry['score'],
				)
			);
		}

		usort(
			$rows,
			function ( $a, $b ) {
				return $a['pair_num'] <=> $b['pair_num'];
			}
		);

		return $rows;
	}

	/**
	 * usort() comparator: score desc, Modified Median desc, Solkoff desc,
	 * Cumulative desc, Cumulative of Opponents desc, rating desc (blank
	 * last), name asc.
	 */
	protected static function compare_standings_rows( array $a, array $b ) {
		$numeric_fields = array( 'score', 'modified_median', 'solkoff', 'cumulative', 'cumulative_opp' );
		foreach ( $numeric_fields as $field ) {
			if ( $a[ $field ] !== $b[ $field ] ) {
				return $a[ $field ] < $b[ $field ] ? 1 : -1;
			}
		}

		$rating_a = isset( $a['rating'] ) && '' !== $a['rating'] && is_numeric( $a['rating'] ) ? (float) $a['rating'] : null;
		$rating_b = isset( $b['rating'] ) && '' !== $b['rating'] && is_numeric( $b['rating'] ) ? (float) $b['rating'] : null;

		if ( null === $rating_a && null !== $rating_b ) {
			return 1; // blank/non-numeric ratings sort after any rated player.
		}
		if ( null !== $rating_a && null === $rating_b ) {
			return -1;
		}
		if ( $rating_a !== $rating_b ) {
			return $rating_a < $rating_b ? 1 : -1;
		}

		return strcmp( (string) $a['name'], (string) $b['name'] );
	}
}
