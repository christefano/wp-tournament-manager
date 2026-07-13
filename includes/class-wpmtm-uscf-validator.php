<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pre-export validator for the same structured data shape
 * WPMTM_USCF_Export consumes (see that class's docblock for the exact
 * shape: top-level event fields + sections[], each with players[], each
 * with rounds[] of 7-char tokens or ['result'=>,'opponent'=>,'color'=>]
 * triples). Catches the mistakes USCF's importer would otherwise bounce,
 * in plain language a volunteer TD can act on before submitting.
 *
 * validate() re-runs the full check set on every call - no caching, no
 * mutation of $data. Findings are plain assoc arrays:
 *   [ 'level' => 'error'|'warning', 'code' => string, 'message' => string,
 *     'section' => int|null, 'player' => int|null, 'round' => int|null ]
 */
class WPMTM_USCF_Validator {

	protected $data;
	protected $rated;

	public function __construct( array $data, bool $rated = true ) {
		$this->data  = $data;
		$this->rated = $rated;
	}

	public function is_valid() {
		foreach ( $this->validate() as $finding ) {
			if ( 'error' === $finding['level'] ) {
				return false;
			}
		}
		return true;
	}

	public function validate() {
		$findings = array();

		// Tokens that failed to decode (check 6) are tracked so checks 1
		// and 4 can skip them instead of piling on cascading noise.
		$invalid_tokens = array();

		$this->check_round_tokens( $findings, $invalid_tokens );
		$this->check_reciprocity_and_color( $findings, $invalid_tokens );
		$this->check_section_empty( $findings );
		$this->check_contiguous_pair_numbers( $findings );
		$this->check_opponent_presence( $findings, $invalid_tokens );
		$this->check_round_counts( $findings );
		$this->check_duplicate_members_and_names( $findings );
		$this->check_lst_pair( $findings );
		$this->check_ascii_and_name_format( $findings );
		$this->check_section_numbers( $findings );
		$this->check_dates( $findings );

		if ( $this->rated ) {
			$this->check_affiliate_id( $findings );
			$this->check_td_ids( $findings );
			$this->check_rating_system_vs_timectl( $findings );
			$this->check_blank_member_and_rating( $findings );
			$this->check_r_system_and_trn_type( $findings );
		}

		return $findings;
	}

	// ---- helpers ----

	protected function sections() {
		return isset( $this->data['sections'] ) ? $this->data['sections'] : array();
	}

	protected function players( array $section ) {
		return isset( $section['players'] ) ? $section['players'] : array();
	}

	protected function finding( $level, $code, $message, $section = null, $player = null, $round = null ) {
		return array(
			'level'   => $level,
			'code'    => $code,
			'message' => $message,
			'section' => $section,
			'player'  => $player,
			'round'   => $round,
		);
	}

	protected function is_blank( $value ) {
		return ! isset( $value ) || '' === trim( (string) $value );
	}

	protected function is_ascii( $value ) {
		return (bool) preg_match( '/^[\x00-\x7F]*$/', (string) $value );
	}

	protected function is_valid_date( $value ) {
		if ( ! preg_match( '/^\d{8}$/', (string) $value ) ) {
			return false;
		}
		$year  = (int) substr( $value, 0, 4 );
		$month = (int) substr( $value, 4, 2 );
		$day   = (int) substr( $value, 6, 2 );
		return checkdate( $month, $day, $year );
	}

	protected static function result_word( $result ) {
		$words = array(
			'W' => 'win',
			'L' => 'loss',
			'D' => 'draw',
			'X' => 'forfeit win',
			'F' => 'forfeit loss',
			'Z' => 'forfeit draw',
		);
		return isset( $words[ $result ] ) ? $words[ $result ] : $result;
	}

	/**
	 * Decodes a round entry (string token or ['result'=>,'opponent'=>,
	 * 'color'=>] array) the same way check 6 does, without recording a
	 * finding. Returns the decoded array or null on failure.
	 */
	protected function try_decode( $round ) {
		try {
			if ( is_array( $round ) ) {
				$token = WPMTM_Round_Token::encode(
					isset( $round['result'] ) ? $round['result'] : '',
					isset( $round['opponent'] ) ? $round['opponent'] : 0,
					isset( $round['color'] ) ? $round['color'] : null
				);
			} else {
				$token = trim( (string) $round );
			}
			return WPMTM_Round_Token::decode( $token );
		} catch ( InvalidArgumentException $e ) {
			return null;
		}
	}

	// ---- check 6: round-token validity ----

	protected function check_round_tokens( array &$findings, array &$invalid_tokens ) {
		foreach ( $this->sections() as $s ) {
			$sec_num = isset( $s['sec_num'] ) ? $s['sec_num'] : null;
			foreach ( $this->players( $s ) as $p ) {
				$pair_num = isset( $p['pair_num'] ) ? $p['pair_num'] : null;
				$rounds   = isset( $p['rounds'] ) ? $p['rounds'] : array();
				foreach ( $rounds as $i => $round ) {
					$r = $i + 1;
					try {
						if ( is_array( $round ) ) {
							$token = WPMTM_Round_Token::encode(
								isset( $round['result'] ) ? $round['result'] : '',
								isset( $round['opponent'] ) ? $round['opponent'] : 0,
								isset( $round['color'] ) ? $round['color'] : null
							);
						} else {
							$token = trim( (string) $round );
						}
						WPMTM_Round_Token::decode( $token );
					} catch ( InvalidArgumentException $e ) {
						$invalid_tokens[ $sec_num . ':' . $pair_num . ':' . $r ] = true;
						$findings[] = $this->finding(
							'error',
							'round_token_invalid',
							"Round {$r}: player {$pair_num}'s result could not be understood ({$e->getMessage()}).",
							$sec_num,
							$pair_num,
							$r
						);
					}
				}
			}
		}
	}

	// ---- checks 1 & 2: reciprocity and color consistency ----

	protected function check_reciprocity_and_color( array &$findings, array $invalid_tokens ) {
		foreach ( $this->sections() as $s ) {
			$sec_num = isset( $s['sec_num'] ) ? $s['sec_num'] : null;
			$players = $this->players( $s );

			$by_pair_num = array();
			foreach ( $players as $p ) {
				if ( isset( $p['pair_num'] ) ) {
					$by_pair_num[ $p['pair_num'] ] = $p;
				}
			}

			$tot_rnds = isset( $s['tot_rnds'] ) ? (int) $s['tot_rnds'] : 0;

			foreach ( $players as $a ) {
				// Cast once so a string pair_num (e.g. from a form post or a
				// loosely-typed caller) still compares equal to the decoded
				// token's integer opponent below - otherwise a self-paired
				// player with a string pair_num would miss the
				// reciprocity_self_paired check and fall through to a
				// confusing reciprocity_mismatch against itself.
				$a_pair   = isset( $a['pair_num'] ) ? (int) $a['pair_num'] : null;
				$a_rounds = isset( $a['rounds'] ) ? $a['rounds'] : array();

				for ( $r = 1; $r <= $tot_rnds; $r++ ) {
					$key = $sec_num . ':' . $a_pair . ':' . $r;
					if ( isset( $invalid_tokens[ $key ] ) ) {
						continue;
					}
					if ( ! array_key_exists( $r - 1, $a_rounds ) ) {
						continue;
					}
					$a_decoded = $this->try_decode( $a_rounds[ $r - 1 ] );
					if ( null === $a_decoded ) {
						continue; // check 6 already flags decode failures.
					}

					if ( in_array( $a_decoded['result'], WPMTM_Round_Token::NO_OPPONENT, true ) ) {
						continue; // byes stand alone.
					}

					$opponent = $a_decoded['opponent'];

					if ( $opponent === $a_pair ) {
						$findings[] = $this->finding(
							'error',
							'reciprocity_self_paired',
							"Round {$r}: player {$a_pair} is recorded as playing against themselves.",
							$sec_num,
							$a_pair,
							$r
						);
						continue;
					}

					if ( ! isset( $by_pair_num[ $opponent ] ) ) {
						$findings[] = $this->finding(
							'error',
							'reciprocity_bad_opponent',
							"Round {$r}: player {$a_pair} is recorded against pairing number {$opponent}, but no such player exists in the section.",
							$sec_num,
							$a_pair,
							$r
						);
						continue;
					}

					if ( in_array( $a_decoded['result'], array( 'N', 'S', 'R' ), true ) ) {
						$findings[] = $this->finding(
							'warning',
							'reciprocity_asymmetric',
							"Round {$r}: player {$a_pair} has an asymmetric result code '{$a_decoded['result']}' that does not need to mirror the opponent; verify this is intentional.",
							$sec_num,
							$a_pair,
							$r
						);
						continue;
					}

					$b        = $by_pair_num[ $opponent ];
					$b_pair   = $opponent;
					$b_rounds = isset( $b['rounds'] ) ? $b['rounds'] : array();
					if ( ! array_key_exists( $r - 1, $b_rounds ) ) {
						continue; // other checks cover a missing round.
					}
					$b_decoded = $this->try_decode( $b_rounds[ $r - 1 ] );
					if ( null === $b_decoded ) {
						continue; // other checks cover a bad token.
					}

					$mirror = array(
						'W' => 'L',
						'L' => 'W',
						'D' => 'D',
						'X' => 'F',
						'F' => 'X',
						'Z' => 'Z',
					);
					$expected_mirror_result = isset( $mirror[ $a_decoded['result'] ] ) ? $mirror[ $a_decoded['result'] ] : null;

					if ( $b_decoded['result'] !== $expected_mirror_result || $b_decoded['opponent'] !== $a_pair ) {
						$findings[] = $this->finding(
							'error',
							'reciprocity_mismatch',
							"Board result mismatch in round {$r}: player {$a_pair} has a " . self::result_word( $a_decoded['result'] ) . " over {$b_pair}, but player {$b_pair} does not have a " . self::result_word( $expected_mirror_result ) . " vs {$a_pair}.",
							$sec_num,
							$a_pair,
							$r
						);
						continue;
					}

					$played = array( 'W', 'L', 'D' );
					if ( in_array( $a_decoded['result'], $played, true ) && in_array( $b_decoded['result'], $played, true )
						&& $a_decoded['color'] === $b_decoded['color'] ) {
						$color_word = ( 'W' === $a_decoded['color'] ) ? 'White' : 'Black';
						$findings[] = $this->finding(
							'error',
							'color_mismatch',
							"Color mismatch in round {$r}: player {$a_pair} and player {$b_pair} are both recorded playing {$color_word}.",
							$sec_num,
							$a_pair,
							$r
						);
					}
				}
			}
		}
	}

	// ---- check 2a: a section with no players at all ----

	/**
	 * A section with zero players fails cleanly here instead of falling
	 * through into check_contiguous_pair_numbers(), which would otherwise
	 * report the confusing "pairing numbers are not contiguous from 1 to
	 * 0." - technically true (there are zero pairing numbers, and 0 is
	 * trivially contiguous with itself) but useless to a TD. This check
	 * runs first and check_contiguous_pair_numbers() below skips any
	 * section this one already flagged.
	 */
	protected function check_section_empty( array &$findings ) {
		foreach ( $this->sections() as $s ) {
			if ( ! empty( $this->players( $s ) ) ) {
				continue;
			}
			$sec_num  = isset( $s['sec_num'] ) ? $s['sec_num'] : null;
			$sec_name = isset( $s['sec_name'] ) ? $s['sec_name'] : '';
			$findings[] = $this->finding(
				'error',
				'section_empty',
				"Section {$sec_num} ('{$sec_name}') has no players; import registrations or add players before exporting.",
				$sec_num,
				null,
				null
			);
		}
	}

	// ---- check 3: contiguous pair numbers ----

	protected function check_contiguous_pair_numbers( array &$findings ) {
		foreach ( $this->sections() as $s ) {
			if ( empty( $this->players( $s ) ) ) {
				continue; // check_section_empty() already reports this section.
			}
			$sec_num = isset( $s['sec_num'] ) ? $s['sec_num'] : null;
			$counts  = array();
			foreach ( $this->players( $s ) as $p ) {
				if ( ! isset( $p['pair_num'] ) ) {
					continue;
				}
				$n = $p['pair_num'];
				$counts[ $n ] = isset( $counts[ $n ] ) ? $counts[ $n ] + 1 : 1;
			}

			foreach ( $counts as $n => $count ) {
				if ( $count > 1 ) {
					$findings[] = $this->finding(
						'error',
						'pair_num_duplicate',
						"Section {$sec_num}: pairing number {$n} is used by more than one player.",
						$sec_num,
						$n,
						null
					);
				}
			}

			$unique = array_keys( $counts );
			sort( $unique, SORT_NUMERIC );
			$expected = range( 1, count( $unique ) );
			if ( $unique !== $expected ) {
				$findings[] = $this->finding(
					'error',
					'pair_num_noncontiguous',
					"Section {$sec_num}: pairing numbers are not contiguous from 1 to " . count( $unique ) . '.',
					$sec_num,
					null,
					null
				);
			}
		}
	}

	// ---- check 4: byes/unpaired need opponent 0; played/forfeit need opponent >= 1 ----

	protected function check_opponent_presence( array &$findings, array $invalid_tokens ) {
		foreach ( $this->sections() as $s ) {
			$sec_num = isset( $s['sec_num'] ) ? $s['sec_num'] : null;
			foreach ( $this->players( $s ) as $p ) {
				$pair_num = isset( $p['pair_num'] ) ? $p['pair_num'] : null;
				$rounds   = isset( $p['rounds'] ) ? $p['rounds'] : array();
				foreach ( $rounds as $i => $round ) {
					$r   = $i + 1;
					$key = $sec_num . ':' . $pair_num . ':' . $r;
					if ( isset( $invalid_tokens[ $key ] ) ) {
						continue;
					}
					$decoded = $this->try_decode( $round );
					if ( null === $decoded ) {
						continue;
					}

					if ( in_array( $decoded['result'], WPMTM_Round_Token::NO_OPPONENT, true ) ) {
						if ( 0 !== $decoded['opponent'] ) {
							$findings[] = $this->finding(
								'error',
								'bye_with_opponent',
								"Round {$r}: player {$pair_num}'s bye/unplayed result lists an opponent, but byes must have opponent 0.",
								$sec_num,
								$pair_num,
								$r
							);
						}
						continue;
					}

					$needs_opponent = array_merge( WPMTM_Round_Token::PLAYED, WPMTM_Round_Token::FORFEIT );
					if ( in_array( $decoded['result'], $needs_opponent, true ) && $decoded['opponent'] < 1 ) {
						$findings[] = $this->finding(
							'error',
							'played_without_opponent',
							"Round {$r}: player {$pair_num}'s result requires an opponent, but none is recorded.",
							$sec_num,
							$pair_num,
							$r
						);
					}
				}
			}
		}
	}

	// ---- check 5: round count must equal section's tot_rnds ----

	protected function check_round_counts( array &$findings ) {
		foreach ( $this->sections() as $s ) {
			$sec_num  = isset( $s['sec_num'] ) ? $s['sec_num'] : null;
			$sec_name = isset( $s['sec_name'] ) ? $s['sec_name'] : '';
			$tot_rnds = isset( $s['tot_rnds'] ) ? $s['tot_rnds'] : 0;
			$players  = $this->players( $s );

			if ( empty( $players ) ) {
				continue; // check_section_empty() already reports this section.
			}

			// A section with players but not a single game or bye recorded
			// anywhere collapses to one section-level finding instead of
			// "player N has 0 rounds recorded" repeated once per player -
			// that per-player spam reads like N separate bugs to a TD when
			// it is really just "nothing has been entered yet". A section
			// with SOME results recorded (even one player, even one round)
			// keeps the per-player round_count_mismatch findings below,
			// since those genuinely point at specific missing rounds.
			$any_rounds_recorded = false;
			foreach ( $players as $p ) {
				if ( ! empty( isset( $p['rounds'] ) ? $p['rounds'] : array() ) ) {
					$any_rounds_recorded = true;
					break;
				}
			}

			if ( ! $any_rounds_recorded && (int) $tot_rnds > 0 ) {
				$findings[] = $this->finding(
					'error',
					'section_no_results',
					"Section {$sec_num} ('{$sec_name}') has players but no results entered yet; enter rounds on the event page before exporting.",
					$sec_num,
					null,
					null
				);
				continue;
			}

			foreach ( $players as $p ) {
				$pair_num = isset( $p['pair_num'] ) ? $p['pair_num'] : null;
				$count    = count( isset( $p['rounds'] ) ? $p['rounds'] : array() );
				if ( $count !== (int) $tot_rnds ) {
					$findings[] = $this->finding(
						'error',
						'round_count_mismatch',
						"Section {$sec_num}: player {$pair_num} has {$count} rounds recorded but the section is set for {$tot_rnds} rounds.",
						$sec_num,
						$pair_num,
						null
					);
				}
			}
		}
	}

	// ---- check 7: duplicate USCF member IDs / names (event-wide) ----

	protected function check_duplicate_members_and_names( array &$findings ) {
		$mem_id_counts = array();
		$name_counts   = array();

		foreach ( $this->sections() as $s ) {
			foreach ( $this->players( $s ) as $p ) {
				if ( ! empty( $p['mem_id'] ) && '' !== trim( (string) $p['mem_id'] ) ) {
					$mem_id = trim( (string) $p['mem_id'] );
					$mem_id_counts[ $mem_id ] = isset( $mem_id_counts[ $mem_id ] ) ? $mem_id_counts[ $mem_id ] + 1 : 1;
				}
				if ( ! empty( $p['name'] ) && '' !== trim( (string) $p['name'] ) ) {
					$name = trim( (string) $p['name'] );
					$name_counts[ $name ] = isset( $name_counts[ $name ] ) ? $name_counts[ $name ] + 1 : 1;
				}
			}
		}

		foreach ( $mem_id_counts as $id => $count ) {
			if ( $count > 1 ) {
				$findings[] = $this->finding(
					'error',
					'duplicate_member_id',
					"USCF member ID {$id} is used by more than one player.",
					null,
					null,
					null
				);
			}
		}

		foreach ( $name_counts as $name => $count ) {
			if ( $count > 1 ) {
				$findings[] = $this->finding(
					'warning',
					'duplicate_player_name',
					"Player name '{$name}' appears more than once; verify these are different people.",
					null,
					null,
					null
				);
			}
		}
	}

	// ---- check 8: lst_pair must equal player count ----

	protected function check_lst_pair( array &$findings ) {
		foreach ( $this->sections() as $s ) {
			if ( ! array_key_exists( 'lst_pair', $s ) ) {
				continue;
			}
			$sec_num = isset( $s['sec_num'] ) ? $s['sec_num'] : null;
			$n       = count( $this->players( $s ) );
			if ( (int) $s['lst_pair'] !== $n ) {
				$findings[] = $this->finding(
					'error',
					'lst_pair_mismatch',
					"Section {$sec_num}: last pairing number is set to {$s['lst_pair']} but {$n} players are recorded.",
					$sec_num,
					null,
					null
				);
			}
		}
	}

	// ---- check 9: ASCII enforcement + name format ----

	protected function check_ascii_and_name_format( array &$findings ) {
		$d = $this->data;

		$header_fields = array( 'event_name', 'city', 'state', 'zipcode', 'country', 'other_tds', 'affiliate_id', 'chief_td_id', 'assistant_td_id', 'format' );
		foreach ( $header_fields as $field ) {
			if ( isset( $d[ $field ] ) && ! $this->is_ascii( $d[ $field ] ) ) {
				$findings[] = $this->finding(
					'error',
					'non_ascii_field',
					"Event: field '{$field}' contains a non-ASCII character and must be transliterated before export.",
					null,
					null,
					null
				);
			}
		}

		$section_fields = array( 'sec_name', 'timectl', 'r_system', 'trn_type', 'chief_td_id', 'assistant_td_id', 'sch_lvl' );
		$player_fields  = array( 'mem_id', 'name', 'state', 'rating' );

		foreach ( $this->sections() as $s ) {
			$sec_num = isset( $s['sec_num'] ) ? $s['sec_num'] : null;

			foreach ( $section_fields as $field ) {
				if ( isset( $s[ $field ] ) && ! $this->is_ascii( $s[ $field ] ) ) {
					$findings[] = $this->finding(
						'error',
						'non_ascii_field',
						"Section {$sec_num}: field '{$field}' contains a non-ASCII character and must be transliterated before export.",
						$sec_num,
						null,
						null
					);
				}
			}

			foreach ( $this->players( $s ) as $p ) {
				$pair_num = isset( $p['pair_num'] ) ? $p['pair_num'] : null;

				foreach ( $player_fields as $field ) {
					if ( isset( $p[ $field ] ) && ! $this->is_ascii( $p[ $field ] ) ) {
						$findings[] = $this->finding(
							'error',
							'non_ascii_field',
							"Section {$sec_num} player {$pair_num}: field '{$field}' contains a non-ASCII character and must be transliterated before export.",
							$sec_num,
							$pair_num,
							null
						);
					}
				}

				if ( isset( $p['name'] ) && '' !== $p['name'] && ! preg_match( '/^[^,]+,/', $p['name'] ) ) {
					$findings[] = $this->finding(
						'warning',
						'name_format',
						"Section {$sec_num} player {$pair_num}: name '{$p['name']}' does not match the expected 'LAST,FIRST' format.",
						$sec_num,
						$pair_num,
						null
					);
				}
			}
		}
	}

	// ---- check 10: section numbers unique and contiguous ----

	protected function check_section_numbers( array &$findings ) {
		$counts = array();
		foreach ( $this->sections() as $s ) {
			if ( ! isset( $s['sec_num'] ) ) {
				continue;
			}
			$n = $s['sec_num'];
			$counts[ $n ] = isset( $counts[ $n ] ) ? $counts[ $n ] + 1 : 1;
		}

		foreach ( $counts as $n => $count ) {
			if ( $count > 1 ) {
				$findings[] = $this->finding(
					'error',
					'sec_num_duplicate',
					"Section number {$n} is used by more than one section.",
					$n,
					null,
					null
				);
			}
		}

		$unique = array_keys( $counts );
		sort( $unique, SORT_NUMERIC );
		// With zero sections, range( 1, count( $unique ) ) is range( 1, 0 ),
		// which PHP counts DOWN to produce array( 1, 0 ) rather than an
		// empty array - that would never equal the genuinely empty $unique
		// and fire a nonsensical "not contiguous" warning on a tournament
		// with no (rated) sections at all. Zero sections is trivially
		// contiguous - there is nothing to be non-contiguous about - so skip
		// the comparison entirely in that case.
		if ( ! empty( $unique ) ) {
			$expected = range( 1, count( $unique ) );
			if ( $unique !== $expected ) {
				$findings[] = $this->finding(
					'warning',
					'sec_num_noncontiguous',
					'Section numbers are not contiguous starting at 1.',
					null,
					null,
					null
				);
			}
		}
	}

	// ---- check 11: dates ----

	protected function check_dates( array &$findings ) {
		$d = $this->data;

		$begin = isset( $d['begin_date'] ) ? $d['begin_date'] : '';
		$end   = isset( $d['end_date'] ) ? $d['end_date'] : '';

		$begin_ok = ! $this->is_blank( $begin ) && $this->is_valid_date( $begin );
		$end_ok   = ! $this->is_blank( $end ) && $this->is_valid_date( $end );

		if ( ! $begin_ok ) {
			$findings[] = $this->finding(
				'error',
				'date_format_invalid',
				"Event begin date '{$begin}' is not a valid YYYYMMDD date.",
				null,
				null,
				null
			);
		}
		if ( ! $end_ok ) {
			$findings[] = $this->finding(
				'error',
				'date_format_invalid',
				"Event end date '{$end}' is not a valid YYYYMMDD date.",
				null,
				null,
				null
			);
		}
		if ( $begin_ok && $end_ok && $begin > $end ) {
			$findings[] = $this->finding(
				'error',
				'date_range_invalid',
				'Event begin date is after the end date.',
				null,
				null,
				null
			);
		}

		foreach ( $this->sections() as $s ) {
			$sec_num = isset( $s['sec_num'] ) ? $s['sec_num'] : null;

			$s_begin = isset( $s['begin_date'] ) ? $s['begin_date'] : '';
			$s_end   = isset( $s['end_date'] ) ? $s['end_date'] : '';

			$s_begin_present = ! $this->is_blank( $s_begin );
			$s_end_present   = ! $this->is_blank( $s_end );

			$s_begin_ok = $s_begin_present && $this->is_valid_date( $s_begin );
			$s_end_ok   = $s_end_present && $this->is_valid_date( $s_end );

			if ( $s_begin_present && ! $s_begin_ok ) {
				$findings[] = $this->finding(
					'error',
					'date_format_invalid',
					"Section {$sec_num} begin date '{$s_begin}' is not a valid YYYYMMDD date.",
					$sec_num,
					null,
					null
				);
			}
			if ( $s_end_present && ! $s_end_ok ) {
				$findings[] = $this->finding(
					'error',
					'date_format_invalid',
					"Section {$sec_num} end date '{$s_end}' is not a valid YYYYMMDD date.",
					$sec_num,
					null,
					null
				);
			}
			if ( $s_begin_present && $s_end_present && $s_begin_ok && $s_end_ok && $s_begin > $s_end ) {
				$findings[] = $this->finding(
					'error',
					'date_range_invalid',
					"Section {$sec_num} begin date is after the end date.",
					$sec_num,
					null,
					null
				);
			}
		}
	}

	// ---- check 12 (rated only): affiliate ID ----

	protected function check_affiliate_id( array &$findings ) {
		$affiliate_id = isset( $this->data['affiliate_id'] ) ? $this->data['affiliate_id'] : '';
		if ( ! preg_match( '/^A\d{7}$/', (string) $affiliate_id ) ) {
			$findings[] = $this->finding(
				'error',
				'affiliate_id_invalid',
				"Affiliate ID is missing or not in the format 'A' followed by 7 digits.",
				null,
				null,
				null
			);
		}
	}

	// ---- check 13 (rated only): TD IDs ----

	protected function check_td_ids( array &$findings ) {
		$chief_td_id = isset( $this->data['chief_td_id'] ) ? $this->data['chief_td_id'] : '';
		if ( ! preg_match( '/^\d{8}$/', (string) $chief_td_id ) ) {
			$findings[] = $this->finding(
				'error',
				'chief_td_id_invalid',
				'Chief TD ID is missing or is not an 8-digit number.',
				null,
				null,
				null
			);
		}

		$assistant_td_id = isset( $this->data['assistant_td_id'] ) ? $this->data['assistant_td_id'] : '';
		if ( ! $this->is_blank( $assistant_td_id ) && ! preg_match( '/^\d{8}$/', (string) $assistant_td_id ) ) {
			$findings[] = $this->finding(
				'error',
				'assistant_td_id_invalid',
				'Assistant TD ID is not blank and not an 8-digit number.',
				null,
				null,
				null
			);
		}
	}

	// ---- check 14 (rated only): rating system vs time control ----

	protected function check_rating_system_vs_timectl( array &$findings ) {
		foreach ( $this->sections() as $s ) {
			$sec_num = isset( $s['sec_num'] ) ? $s['sec_num'] : null;
			$timectl = isset( $s['timectl'] ) ? (string) $s['timectl'] : '';

			$classified = WPMTM_Time_Control::classify( $timectl );

			if ( 'unparseable' === $classified['reason'] ) {
				$findings[] = $this->finding(
					'warning',
					'timectl_unparseable',
					"Section {$sec_num}: time control '{$timectl}' could not be parsed to determine a rating system (expected something like G/30;d5).",
					$sec_num,
					null,
					null
				);
				continue;
			}

			if ( 'below_blitz_minimum' === $classified['reason'] ) {
				$findings[] = $this->finding(
					'warning',
					'timectl_below_blitz_minimum',
					"Section {$sec_num}: time control '{$timectl}' total playing time is below the 5 minute blitz minimum.",
					$sec_num,
					null,
					null
				);
				continue;
			}

			$expected = $classified['system'];
			$declared = strtoupper( (string) ( isset( $s['r_system'] ) ? $s['r_system'] : '' ) );
			if ( $declared !== $expected ) {
				$findings[] = $this->finding(
					'error',
					'rating_system_mismatch',
					"Section {$sec_num}: time control '{$timectl}' classifies as {$expected} but the section declares rating system '{$declared}'.",
					$sec_num,
					null,
					null
				);
			}
		}
	}

	// ---- check 15 (rated only): blank member ID / rating ----

	protected function check_blank_member_and_rating( array &$findings ) {
		foreach ( $this->sections() as $s ) {
			$sec_num = isset( $s['sec_num'] ) ? $s['sec_num'] : null;
			foreach ( $this->players( $s ) as $p ) {
				$pair_num   = isset( $p['pair_num'] ) ? $p['pair_num'] : null;
				$mem_blank  = $this->is_blank( isset( $p['mem_id'] ) ? $p['mem_id'] : '' );
				$rate_blank = $this->is_blank( isset( $p['rating'] ) ? $p['rating'] : '' );

				if ( $mem_blank ) {
					$findings[] = $this->finding(
						'warning',
						'member_id_blank',
						"Section {$sec_num} player {$pair_num}: USCF member ID is blank; the TD must confirm this player's membership before submitting. For a brand-new US Chess member, get the ID from the membership purchase (or the uschess.org member lookup) and enter it on the player row in the admin players editor, then re-run this report.",
						$sec_num,
						$pair_num,
						null
					);
				}

				if ( $rate_blank && $mem_blank ) {
					$findings[] = $this->finding(
						'warning',
						'rating_blank',
						"Section {$sec_num} player {$pair_num}: rating is blank and no member ID is recorded either.",
						$sec_num,
						$pair_num,
						null
					);
				}
			}
		}
	}

	// ---- check 16 (rated only): r_system / trn_type validity ----

	protected function check_r_system_and_trn_type( array &$findings ) {
		foreach ( $this->sections() as $s ) {
			$sec_num  = isset( $s['sec_num'] ) ? $s['sec_num'] : null;
			$r_system = strtoupper( (string) ( isset( $s['r_system'] ) ? $s['r_system'] : '' ) );
			if ( ! in_array( $r_system, array( 'R', 'Q', 'B' ), true ) ) {
				$findings[] = $this->finding(
					'error',
					'r_system_invalid',
					"Section {$sec_num}: rating system must be R, Q, or B.",
					$sec_num,
					null,
					null
				);
			}

			// 'S' (Swiss), 'R' (round robin), and 'Q' (quad - a 4-player
			// round robin, see WPMTM_Pairing_Aid::RR_TYPES) are the
			// supported internal pairing types (docs/SPEC.md, "Decisions
			// (2026-07-09, round robin and quads)" and "Decisions
			// (2026-07-10, quads selectable)"). Round robin and quad both
			// still submit to USCF in Swiss format - WPMTM_Export_Builder
			// always writes S_TRN_TYPE 'S' regardless - so 'R'/'Q' are valid
			// here even though neither ever reaches the export payload as
			// anything but 'S'.
			$trn_type = strtoupper( (string) ( isset( $s['trn_type'] ) ? $s['trn_type'] : 'S' ) );
			if ( 'S' !== $trn_type && ! WPMTM_Pairing_Aid::is_round_robin_type( $trn_type ) ) {
				$findings[] = $this->finding(
					'error',
					'trn_type_unsupported',
					"Section {$sec_num}: pairing type '{$trn_type}' is not supported.",
					$sec_num,
					null,
					null
				);
			}
		}
	}

}
