<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wpmtm-scoring.php';

/**
 * Pure, WordPress-independent class that maps plain-array tournament data
 * (as returned by WPMTM_Repository::get_export_bundle()) onto the exact
 * structured shape WPMTM_USCF_Export and WPMTM_USCF_Validator consume - see
 * that class's docblock in class-wpmtm-uscf-export.php for the target
 * shape. This class does no validation of its own beyond structural
 * mapping; WPMTM_USCF_Validator remains the export-time gate.
 *
 * Per docs/SPEC.md, only rated sections are ever included in the export;
 * the tournament-level rated flag ("submits to USCF at all") is the
 * caller's responsibility, not this class's.
 */
class WPMTM_Export_Builder {

	/**
	 * @param array $tournament Plain array mirroring the wpmtm_tournaments
	 *                          row: name, begin_date, end_date, city, state,
	 *                          zipcode, send_crosstable, rated, head_td_id,
	 *                          assistant_td_id (and any other row columns,
	 *                          which are ignored). head_td_id/assistant_td_id
	 *                          are the per-tournament TD overrides (docs/
	 *                          SPEC.md, "Decisions (2026-07-11, per-
	 *                          tournament TD overrides and Chief TD
	 *                          rename)"): when non-empty they win over the
	 *                          $options club defaults below.
	 * @param array $options    Plain array: affiliate_id, chief_td_id,
	 *                          assistant_td_id, default_city, default_state,
	 *                          default_zipcode (matches WPMTM_Plugin::get_opts()).
	 * @param array $sections   List of plain arrays, each the section row
	 *                          fields (sec_num, sec_name, r_system, timectl,
	 *                          trn_type, tot_rnds, sch_lvl, gr_prix, gp_pts,
	 *                          fide, rated) plus 'players' (id, pair_num,
	 *                          mem_id, name, state, rating), 'games' (round,
	 *                          board, white_player_id, black_player_id,
	 *                          result), 'byes' (player_id, round, type).
	 * @return array Structured data ready for
	 *               new WPMTM_USCF_Export( $data ) / new WPMTM_USCF_Validator( $data, true ).
	 */
	public static function build( array $tournament, array $options, array $sections ) {
		$data = array(
			'event_name'      => isset( $tournament['name'] ) ? (string) $tournament['name'] : '',
			'begin_date'      => self::format_date( isset( $tournament['begin_date'] ) ? $tournament['begin_date'] : '' ),
			'end_date'        => self::format_date( isset( $tournament['end_date'] ) ? $tournament['end_date'] : '' ),
			'affiliate_id'    => isset( $options['affiliate_id'] ) ? (string) $options['affiliate_id'] : '',
			'city'            => self::first_nonblank( isset( $tournament['city'] ) ? $tournament['city'] : '', isset( $options['default_city'] ) ? $options['default_city'] : '' ),
			'state'           => self::first_nonblank( isset( $tournament['state'] ) ? $tournament['state'] : '', isset( $options['default_state'] ) ? $options['default_state'] : '' ),
			'zipcode'         => self::first_nonblank( isset( $tournament['zipcode'] ) ? $tournament['zipcode'] : '', isset( $options['default_zipcode'] ) ? $options['default_zipcode'] : '' ),
			'send_crosstable' => ! empty( $tournament['send_crosstable'] ) ? 'Y' : 'N',
			// 'chief_td_id' / 'assistant_td_id': the data key names and the
			// DBF field names they feed (H_CTD_ID/S_CTD_ID) are unchanged -
			// the project's non-brittle naming rule keeps internal keys and
			// on-disk USCF field names stable even after a user-facing
			// rename (docs/SPEC.md, "Decisions (2026-07-11, per-tournament
			// TD overrides and Chief TD rename)"). Only the label the TD sees
			// (Settings, the tournament form, validator messages) says "Chief
			// TD" now. Effective value: the tournament's own head/assistant
			// TD override when set, else the club-wide Settings default.
			'chief_td_id'     => self::first_nonblank(
				isset( $tournament['head_td_id'] ) ? $tournament['head_td_id'] : '',
				isset( $options['chief_td_id'] ) ? $options['chief_td_id'] : ''
			),
			'assistant_td_id' => self::first_nonblank(
				isset( $tournament['assistant_td_id'] ) ? $tournament['assistant_td_id'] : '',
				isset( $options['assistant_td_id'] ) ? $options['assistant_td_id'] : ''
			),
			'sections'        => array(),
		);

		$rated_sections = array();
		foreach ( $sections as $section ) {
			// docs/SPEC.md: export includes only rated sections - the
			// tournament-level rated flag means "submits to USCF" and is
			// checked by the caller, not here.
			if ( empty( $section['rated'] ) ) {
				continue;
			}
			$rated_sections[] = $section;
		}

		// docs/SPEC.md, "Decisions (2026-07-09, May fixtures)": the desktop
		// pairing program's own export renumbers the included (rated)
		// sections contiguously from 1, in original sec_num order - unrated
		// sections are simply absent, not skipped-over gaps left in the
		// numbering. Sort by each section's original sec_num first so the
		// renumbering below is stable and independent of the order
		// $sections happens to arrive in, then overwrite sec_num with the
		// new contiguous value; S_LST_PAIR and H_TOT_SECT fall out
		// consistent with this automatically since both are derived counts
		// (player count, section count) rather than copies of sec_num.
		usort(
			$rated_sections,
			function ( $a, $b ) {
				$a_num = isset( $a['sec_num'] ) ? (int) $a['sec_num'] : 0;
				$b_num = isset( $b['sec_num'] ) ? (int) $b['sec_num'] : 0;
				return $a_num <=> $b_num;
			}
		);

		$sec_num = 1;
		foreach ( $rated_sections as $section ) {
			$section['sec_num'] = $sec_num++;
			$data['sections'][] = self::build_section( $section );
		}

		return $data;
	}

	// -----------------------------------------------------------------
	// Section / player mapping.
	// -----------------------------------------------------------------

	protected static function build_section( array $section ) {
		$players = isset( $section['players'] ) ? $section['players'] : array();
		$games   = isset( $section['games'] ) ? $section['games'] : array();
		$byes    = isset( $section['byes'] ) ? $section['byes'] : array();

		$rounds_by_player = self::derive_rounds( $players, $games, $byes );

		$out = array(
			'sec_num'  => isset( $section['sec_num'] ) ? (int) $section['sec_num'] : 0,
			'sec_name' => isset( $section['sec_name'] ) ? (string) $section['sec_name'] : '',
			'r_system' => isset( $section['r_system'] ) ? (string) $section['r_system'] : '',
			'timectl'  => isset( $section['timectl'] ) ? (string) $section['timectl'] : '',
			// Always 'S', regardless of the section's internal trn_type.
			// The US Chess TD/Affiliate FAQ states a round robin may be
			// reported either in the round robin grid format, or "treat it
			// as a Swiss using the Crenshaw-Berger pairings", and both
			// methods "will generate the same ratings" - so Tournament
			// Manager submits every section, round robin (and quad)
			// included, in the Swiss round-by-round format it already
			// produces (real per-round opponent tokens). The section's own
			// 'R' or 'Q' trn_type (see WPMTM_Pairing_Aid::RR_TYPES - a quad
			// is just a 4-player round robin) is kept in the database and
			// drives only this plugin's UI (the pairing aid, quad splitting
			// at import) - see docs/SPEC.md, "Decisions (2026-07-09, round
			// robin and quads)" and "Decisions (2026-07-10, quads
			// selectable)".
			'trn_type' => 'S',
			'tot_rnds' => isset( $section['tot_rnds'] ) ? (int) $section['tot_rnds'] : 0,
			'gr_prix'  => ( isset( $section['gr_prix'] ) && '' !== $section['gr_prix'] ) ? (string) $section['gr_prix'] : 'N',
			'gp_pts'   => isset( $section['gp_pts'] ) ? (string) (int) $section['gp_pts'] : '',
			// No FIDE support (owner decision 2026-07-10, docs/SPEC.md
			// "FIDE flag passthrough - REVERTED"). S_FIDE is always 'N'
			// regardless of what the section row carries; the 'fide'
			// schema column stays in place but is dormant.
			'fide'     => 'N',
			'players'  => array(),
		);

		if ( isset( $section['sch_lvl'] ) && '' !== $section['sch_lvl'] ) {
			$out['sch_lvl'] = (string) $section['sch_lvl'];
		}

		foreach ( $players as $player ) {
			$player_id = isset( $player['id'] ) ? (int) $player['id'] : 0;
			$rounds    = isset( $rounds_by_player[ $player_id ] ) ? $rounds_by_player[ $player_id ] : array();
			$rounds    = self::fill_withdrawn_rounds( $rounds, $player, $out['tot_rnds'] );

			$out['players'][] = array(
				'pair_num' => isset( $player['pair_num'] ) ? (int) $player['pair_num'] : 0,
				'mem_id'   => isset( $player['mem_id'] ) ? (string) $player['mem_id'] : '',
				'name'     => isset( $player['name'] ) ? (string) $player['name'] : '',
				'state'    => isset( $player['state'] ) ? (string) $player['state'] : '',
				'rating'   => isset( $player['rating'] ) ? (string) $player['rating'] : '',
				'rounds'   => $rounds,
			);
		}

		return $out;
	}

	/**
	 * Fills every round after a withdrawn player's withdrawn_after_round
	 * that has no existing entry with a 'U' (not paired / zero-point bye)
	 * token - opponent 0, no color, exactly the shape a real 'U' bye row
	 * takes via the byes loop in derive_rounds() below. This is the USCF-
	 * documented use of the U code for a player no longer in the event
	 * (docs/SPEC.md, round-token legend and the withdrawals design).
	 * Rounds at or before the withdrawal round that are missing are left
	 * absent - WPMTM_USCF_Validator's round-count check still catches those
	 * exactly as it does for a non-withdrawn player's missing round.
	 *
	 * @param array $rounds  Sparse round-index => token-array map, per derive_rounds().
	 * @param array $player  The player row (checked for 'withdrawn_after_round').
	 * @param int   $tot_rnds Section's total round count.
	 * @return array
	 */
	protected static function fill_withdrawn_rounds( array $rounds, array $player, $tot_rnds ) {
		$withdrawn_after_round = self::withdrawn_after_round( $player );
		if ( null === $withdrawn_after_round ) {
			return $rounds;
		}

		for ( $r = $withdrawn_after_round + 1; $r <= (int) $tot_rnds; $r++ ) {
			$index = $r - 1;
			if ( ! array_key_exists( $index, $rounds ) ) {
				$rounds[ $index ] = array(
					'result'   => 'U',
					'opponent' => 0,
					'color'    => null,
				);
			}
		}

		ksort( $rounds );
		return $rounds;
	}

	/**
	 * Reads a player row's 'withdrawn_after_round' key, treating an absent
	 * key the same as an explicit null (active) - matches
	 * WPMTM_Pairing_Aid::withdrawn_after_round().
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
	 * Builds, per player id, a sparse array keyed by 0-based round index
	 * (round N lives at key N-1) whose values are
	 * ['result'=>,'opponent'=>,'color'=>] triples ready for
	 * WPMTM_Round_Token::encode(). A round with no game or bye for a
	 * player is simply absent from that player's array - WPMTM_USCF_Export
	 * iterates the array in (ksort'd) key order when building D_RNDnn
	 * columns, and WPMTM_USCF_Validator's round-count check (which compares
	 * count($rounds) against the section's tot_rnds) is exactly what
	 * catches a missing round, so no placeholder token is invented here.
	 *
	 * @return array player_id => array( round_index => array )
	 */
	protected static function derive_rounds( array $players, array $games, array $byes ) {
		$pair_num_by_id = array();
		foreach ( $players as $player ) {
			if ( isset( $player['id'] ) ) {
				$pair_num_by_id[ (int) $player['id'] ] = isset( $player['pair_num'] ) ? (int) $player['pair_num'] : 0;
			}
		}

		$rounds_by_player = array();

		foreach ( $games as $game ) {
			$result = isset( $game['result'] ) ? strtoupper( (string) $game['result'] ) : '';
			if ( ! isset( WPMTM_Scoring::RESULT_TOKEN_MAP[ $result ] ) ) {
				continue; // defensive: malformed row, skip rather than fatal.
			}

			$round = isset( $game['round'] ) ? (int) $game['round'] : 0;
			if ( $round < 1 ) {
				continue;
			}

			$white_id = isset( $game['white_player_id'] ) ? (int) $game['white_player_id'] : 0;
			$black_id = isset( $game['black_player_id'] ) ? (int) $game['black_player_id'] : 0;
			$map      = WPMTM_Scoring::RESULT_TOKEN_MAP[ $result ];

			self::apply_side( $rounds_by_player, $white_id, $black_id, $round, $map['white'], $pair_num_by_id );
			self::apply_side( $rounds_by_player, $black_id, $white_id, $round, $map['black'], $pair_num_by_id );
		}

		foreach ( $byes as $bye ) {
			$type = isset( $bye['type'] ) ? strtoupper( (string) $bye['type'] ) : '';
			if ( ! in_array( $type, WPMTM_Scoring::BYE_TYPES, true ) ) {
				continue; // defensive: malformed row, skip rather than fatal.
			}

			$player_id = isset( $bye['player_id'] ) ? (int) $bye['player_id'] : 0;
			$round     = isset( $bye['round'] ) ? (int) $bye['round'] : 0;
			if ( $round < 1 || ! isset( $pair_num_by_id[ $player_id ] ) ) {
				continue;
			}

			$rounds_by_player[ $player_id ][ $round - 1 ] = array(
				'result'   => $type,
				'opponent' => 0,
				'color'    => null,
			);
		}

		// WPMTM_USCF_Export::build_detail_bytes() iterates each player's
		// 'rounds' array in plain foreach order (not by key) to fill
		// D_RND01.. columns in sequence, so the sparse array must be sorted
		// by round index here rather than left in game/bye insertion order.
		foreach ( $rounds_by_player as &$rounds ) {
			ksort( $rounds );
		}
		unset( $rounds );

		return $rounds_by_player;
	}

	/**
	 * Records one side of a game into $rounds_by_player: the token
	 * components for $player_id's round, with 'opponent' resolved to the
	 * opponent's PAIR NUMBER (not player id), per docs/SPEC.md's round-token
	 * legend and WPMTM_Scoring::RESULT_TOKEN_MAP.
	 */
	protected static function apply_side( array &$rounds_by_player, $player_id, $opponent_id, $round, array $side, array $pair_num_by_id ) {
		if ( ! isset( $pair_num_by_id[ $player_id ] ) ) {
			return; // defensive: game references a player not in this section's roster.
		}

		$opponent_pair_num = isset( $pair_num_by_id[ $opponent_id ] ) ? $pair_num_by_id[ $opponent_id ] : 0;

		$rounds_by_player[ $player_id ][ $round - 1 ] = array(
			'result'   => $side['result'],
			'opponent' => $opponent_pair_num,
			'color'    => $side['color'],
		);
	}

	// -----------------------------------------------------------------
	// Small helpers.
	// -----------------------------------------------------------------

	/**
	 * Tournament dates are stored as MySQL DATE columns ('YYYY-MM-DD');
	 * stripping the dashes reaches the DBF's YYYYMMDD format directly, so
	 * no date parsing or timezone handling is needed here.
	 */
	protected static function format_date( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		return str_replace( '-', '', $value );
	}

	protected static function first_nonblank( $value, $fallback ) {
		$value = trim( (string) $value );
		return '' !== $value ? $value : trim( (string) $fallback );
	}
}
