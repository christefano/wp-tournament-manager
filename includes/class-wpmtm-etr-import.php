<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Roster import from the wp-etr "Pairing export" CSV (docs/SPEC.md,
 * "Decisions (2026-07-09, ETR import)").
 *
 * Two layers, deliberately kept separate:
 *
 * - parse() and order_players_for_pairing() are pure / static / WordPress-
 *   independent, so they can be unit-tested by the plain-PHP runner
 *   (tests/run-tests.php) without a database.
 * - import() is the WordPress layer: it writes wpmtm_sections /
 *   wpmtm_players rows from the parsed payload and a TD-confirmed section
 *   map, using WPMTM_Repository for section numbering.
 */
class WPMTM_ETR_Import {

	/** Expected header cells, in order (case-insensitive, trimmed match). */
	const EXPECTED_HEADERS = array( 'Last Name', 'First Name', 'USCF ID', 'Rating', 'Section', 'Status' );

	// -----------------------------------------------------------------
	// Pure parse layer.
	// -----------------------------------------------------------------

	/**
	 * Parses raw ETR "Pairing export" CSV text.
	 *
	 * On success returns:
	 *   array(
	 *     'rows'     => array( array( 'last_name', 'first_name', 'mem_id',
	 *                   'rating', 'section', 'status', 'name', 'skip',
	 *                   'warnings', 'photo_id' ), ... ),
	 *     'sections' => array( array( 'name', 'rated' ), ... )  // file order,
	 *     'warnings' => array( string, ... )                    // file-level
	 *   )
	 *
	 * On failure (bad header / empty file) returns a WP-independent error
	 * structure: array( 'error' => 'empty_file'|'bad_header', 'message' => string ).
	 * The admin layer is responsible for turning 'bad_header' into the
	 * user-facing "This does not look like an ETR Pairing export." notice.
	 *
	 * @param string $csv_text Raw CSV file contents.
	 * @return array
	 */
	public static function parse( $csv_text ) {
		$csv_text = (string) $csv_text;

		if ( '' === trim( $csv_text ) ) {
			return array(
				'error'   => 'empty_file',
				'message' => 'The uploaded file is empty.',
			);
		}

		// Parse from a php://temp stream with fgetcsv rather than splitting on
		// "\n" by hand, so quoted fields containing commas, quotes, or CRLF
		// inside quotes are handled correctly (and both CRLF and bare-LF line
		// endings work, since fgetcsv reads up to the next unquoted newline
		// regardless of style).
		$stream = fopen( 'php://temp', 'r+b' );
		fwrite( $stream, $csv_text );
		rewind( $stream );

		$header = fgetcsv( $stream, 0, ',', '"', '' );
		if ( false === $header || null === $header ) {
			fclose( $stream );
			return array(
				'error'   => 'empty_file',
				'message' => 'The uploaded file is empty.',
			);
		}

		$normalized_header   = array_map( array( __CLASS__, 'normalize_header_cell' ), $header );
		$expected_normalized = array_map( array( __CLASS__, 'normalize_header_cell' ), self::EXPECTED_HEADERS );

		if ( $normalized_header !== $expected_normalized ) {
			fclose( $stream );
			return array(
				'error'   => 'bad_header',
				'message' => 'The header row does not match the expected ETR Pairing export columns.',
			);
		}

		$raw_rows = array();
		while ( false !== ( $fields = fgetcsv( $stream, 0, ',', '"', '' ) ) ) {
			if ( null === $fields ) {
				continue; // fgetcsv() can return null on some blank-line edge cases.
			}
			$raw_rows[] = $fields;
		}

		fclose( $stream );

		return self::normalize_rows( $raw_rows );
	}

	protected static function normalize_header_cell( $cell ) {
		return strtolower( trim( (string) $cell ) );
	}

	/**
	 * Pure per-row normalization shared by parse() (CSV upload path) and
	 * WPMTM_Admin_Import::handle_import_from_event() (the "Import to
	 * Tournament Manager" button wp-etr renders directly on the event page,
	 * which hands over rows without a CSV round-trip). Both paths must
	 * apply identical No-show / "Need New ID" / duplicate-detection rules
	 * so a roster imports the same way regardless of which door it came in.
	 *
	 * @param array $raw_rows List of arrays in EXPECTED_HEADERS order (last,
	 *                        first, USCF id, rating, section, status), each
	 *                        cell a raw untrimmed string. A wholly blank row
	 *                        (e.g. a CSV file's trailing newline) is
	 *                        silently skipped. An optional 7th element -
	 *                        photo_id, an attachment id (int), or 0/absent
	 *                        for no photo - is carried through into the
	 *                        'photo_id' key of the returned row when
	 *                        present. Only WPMTM_Admin_Import::
	 *                        build_rows_from_event() (the wp-etr one-click
	 *                        import path) ever supplies it; parse()'s CSV
	 *                        rows are always 6 cells, since ETR's "Pairing
	 *                        export" CSV has no photo column, so a CSV
	 *                        import's photo_id is always null.
	 * @return array array( 'rows' => ..., 'sections' => ..., 'warnings' => ... )
	 *               - same success shape parse() returns.
	 */
	public static function normalize_rows( array $raw_rows ) {
		$rows           = array();
		$sections_order = array();
		$file_warnings  = array();
		$seen           = array(); // duplicate-detection key => true, first occurrence only.

		foreach ( $raw_rows as $fields ) {
			if ( ! is_array( $fields ) ) {
				continue;
			}

			$fields = array_pad( array_values( $fields ), 7, '' );

			$last_name  = trim( (string) $fields[0] );
			$first_name = trim( (string) $fields[1] );
			$mem_id_raw = trim( (string) $fields[2] );
			$rating_raw = trim( (string) $fields[3] );
			$section    = trim( (string) $fields[4] );
			$status     = trim( (string) $fields[5] );

			// Optional 7th element: photo_id, an attachment id carried only
			// by the wp-etr one-click import path (build_rows_from_event()).
			// A CSV row is always padded to exactly this with '' above, so
			// this normalizes to null the same way an explicit 0 ("no
			// photo", wp-etr's own sentinel) does.
			$photo_id = is_numeric( $fields[6] ) && (int) $fields[6] > 0 ? (int) $fields[6] : null;

			if ( '' === $last_name && '' === $first_name ) {
				continue; // fully blank data row.
			}

			$row_warnings = array();
			$player_label = trim( $last_name . ' ' . $first_name );

			// mem_id: strip everything but digits; a non-empty raw value that
			// yields no digits at all (e.g. "Need New ID") becomes a blank ID
			// with a warning naming the player, rather than silently vanishing.
			$mem_id = preg_replace( '/\D+/', '', $mem_id_raw );
			if ( '' !== $mem_id_raw && '' === $mem_id ) {
				$msg             = sprintf( '%s: USCF ID "%s" is not a number; imported with a blank ID.', $player_label, $mem_id_raw );
				$row_warnings[]  = $msg;
				$file_warnings[] = $msg;
			}
			$mem_id = substr( $mem_id, 0, 8 );

			// rating: digits only, cap 4; blank stays blank.
			$rating = substr( preg_replace( '/\D+/', '', $rating_raw ), 0, 4 );

			// status: "No-show" (case-insensitive) skips the row; any other
			// non-empty status still imports the row but warns, since it is
			// unrecognized (ETR's vocabulary may grow).
			$skip = false;
			if ( '' !== $status ) {
				if ( 0 === strcasecmp( $status, 'no-show' ) ) {
					$skip = true;
				} else {
					$msg             = sprintf( '%s: unrecognized status "%s"; row was still imported.', $player_label, $status );
					$row_warnings[]  = $msg;
					$file_warnings[] = $msg;
				}
			}

			// name: LAST,FIRST, uppercased, comma with no following space -
			// matches the known-good DBF style. ASCII is deliberately NOT
			// forced here; the export-time validator flags non-ASCII instead
			// so the TD sees a warning rather than a silently mangled name.
			$name = strtoupper( $last_name ) . ',' . strtoupper( $first_name );

			// Exact-duplicate detection: same last+first+mem_id+section,
			// case-insensitive. First occurrence wins; later ones are marked
			// skip with a duplicate warning (not dropped from 'rows', so the
			// preview screen can still show the TD what was skipped and why).
			$dup_key = strtolower( $last_name ) . '|' . strtolower( $first_name ) . '|' . strtolower( $mem_id ) . '|' . strtolower( $section );
			if ( isset( $seen[ $dup_key ] ) ) {
				$skip            = true;
				$msg             = sprintf( 'Duplicate row for %s in "%s" skipped (row %d).', $player_label, $section, count( $rows ) + 2 );
				$row_warnings[]  = $msg;
				$file_warnings[] = $msg;
			} else {
				$seen[ $dup_key ] = true;
			}

			if ( '' !== $section && ! in_array( $section, $sections_order, true ) ) {
				$sections_order[] = $section;
			}

			$rows[] = array(
				'last_name'  => $last_name,
				'first_name' => $first_name,
				'mem_id'     => $mem_id,
				'rating'     => $rating,
				'section'    => $section,
				'status'     => $status,
				'name'       => $name,
				'skip'       => $skip,
				'warnings'   => $row_warnings,
				'photo_id'   => $photo_id,
			);
		}

		$sections = array();
		foreach ( $sections_order as $sec_name ) {
			$sections[] = array(
				'name'  => $sec_name,
				// Plain default of true (rated) - no name guessing. Section
				// names say nothing reliable about rating (a section named
				// "U1800" is a rated section), so the preview defaults every
				// new section to the tournament's own rated flag instead and
				// the TD confirms each checkbox (owner decision 2026-07-10).
				'rated' => true,
			);
		}

		return array(
			'rows'     => $rows,
			'sections' => $sections,
			'warnings' => $file_warnings,
		);
	}

	// -----------------------------------------------------------------
	// Pure ordering layer (shared by import() and directly unit-tested).
	// -----------------------------------------------------------------

	/**
	 * Orders a flat list of players for pairing-number assignment: rating
	 * descending, blank ratings last, ties (including all-blank) broken by
	 * name ascending. This is the same ordering ETR itself uses when it
	 * assigns pairing numbers (see the ETR README) - so simply assigning
	 * contiguous pair_num values 1..N in this sorted order reproduces ETR's
	 * numbering exactly. The ordering IS the pairing-number assignment; do
	 * not re-sort by anything else downstream.
	 *
	 * Pure and WP-independent: operates on plain arrays with at least a
	 * 'rating' key (numeric-string or '') and a 'name' key.
	 *
	 * @param array $players
	 * @return array Re-indexed (0-based) sorted copy.
	 */
	public static function order_players_for_pairing( array $players ) {
		$players = array_values( $players );

		usort(
			$players,
			function ( $a, $b ) {
				$rating_a = isset( $a['rating'] ) && '' !== $a['rating'] ? (int) $a['rating'] : null;
				$rating_b = isset( $b['rating'] ) && '' !== $b['rating'] ? (int) $b['rating'] : null;

				if ( null === $rating_a && null === $rating_b ) {
					return strcmp( (string) $a['name'], (string) $b['name'] );
				}
				if ( null === $rating_a ) {
					return 1; // blank ratings sort after any rated player.
				}
				if ( null === $rating_b ) {
					return -1;
				}
				if ( $rating_a === $rating_b ) {
					return strcmp( (string) $a['name'], (string) $b['name'] );
				}
				return $rating_b - $rating_a; // descending.
			}
		);

		return $players;
	}

	/**
	 * Splits an already-ordered player list (rating descending, per
	 * order_players_for_pairing()) into quad (4-player round robin) groups,
	 * per docs/SPEC.md "Decisions (2026-07-09, round robin and quads)".
	 *
	 * Rules, in order:
	 * - Fewer than 4 players: no quad makes sense at all. Returned as a
	 *   single Swiss group with a 'warning' key set, so the caller can
	 *   surface it to the TD.
	 * - Otherwise, group the top players into as many full quads as
	 *   possible, EXCEPT the last one: if players remain after all the
	 *   other full quads are taken (i.e. the player count is not a clean
	 *   multiple of 4), the final quad's 4 players are folded together with
	 *   the 1-3 leftover players into a single 5-7 player Swiss group
	 *   instead of being left as a standalone quad - a 1-3 player "quad" is
	 *   never valid. When the count IS a clean multiple of 4, every group is
	 *   a full quad and nothing is folded.
	 *
	 * Pure and WP-independent: operates on plain arrays, does no database or
	 * WordPress calls, and is unit-tested directly.
	 *
	 * @param array $players Ordered list of player rows (rating desc), each
	 *                       at least the shape order_players_for_pairing()
	 *                       consumes/returns.
	 * @return array List of groups, each:
	 *   array(
	 *     'suffix'   => 'Quad 1'|'Quad 2'|...|'Swiss',
	 *     'trn_type' => 'R'|'S',
	 *     'players'  => array( ...ordered subset of $players... ),
	 *     'warning'  => string, // only present on the "fewer than 4" path.
	 *   )
	 */
	public static function split_into_quads( array $players ) {
		$players = array_values( $players );
		$count   = count( $players );

		if ( $count < 4 ) {
			return array(
				array(
					'suffix'   => 'Swiss',
					'trn_type' => 'S',
					'players'  => $players,
					'warning'  => 'Fewer than 4 players; created as a single Swiss section instead of quads.',
				),
			);
		}

		$full_quads  = intdiv( $count, 4 );
		$remainder   = $count % 4;
		// A clean multiple of 4 keeps every quad standalone; otherwise the
		// last quad's worth of players folds together with the 1-3 leftover
		// players into one Swiss group, so only $full_quads - 1 quads stay
		// standalone.
		$clean_quads = ( 0 === $remainder ) ? $full_quads : ( $full_quads - 1 );

		$groups = array();
		$offset = 0;
		for ( $i = 0; $i < $clean_quads; $i++ ) {
			$groups[] = array(
				'suffix'   => 'Quad ' . ( $i + 1 ),
				'trn_type' => 'R',
				'players'  => array_slice( $players, $offset, 4 ),
			);
			$offset += 4;
		}

		$remaining = array_slice( $players, $offset );
		if ( ! empty( $remaining ) ) {
			$groups[] = array(
				'suffix'   => 'Swiss',
				'trn_type' => 'S',
				'players'  => $remaining,
			);
		}

		return $groups;
	}

	/**
	 * Finds an existing section whose name exactly matches a CSV section
	 * name (case-insensitive, trimmed). Used by the preview screen to
	 * default a re-imported CSV section to "map to existing" instead of
	 * "create new", so re-running an import does not silently create a
	 * second same-named section.
	 *
	 * Pure and WP-independent: operates on a plain "id => name" map rather
	 * than $wpdb rows.
	 *
	 * @param string $csv_name CSV section name.
	 * @param array  $existing Map of existing section id => section name.
	 * @return int|null Matched section id, or null if no exact match.
	 */
	public static function match_existing_section( $csv_name, array $existing ) {
		$needle = strtolower( trim( (string) $csv_name ) );
		if ( '' === $needle ) {
			return null;
		}
		foreach ( $existing as $id => $name ) {
			if ( strtolower( trim( (string) $name ) ) === $needle ) {
				return (int) $id;
			}
		}
		return null;
	}

	// -----------------------------------------------------------------
	// WordPress layer.
	// -----------------------------------------------------------------

	/**
	 * Writes the confirmed import into wpmtm_sections / wpmtm_players.
	 *
	 * @param int   $tournament_id Target tournament.
	 * @param array $parsed        Output of parse() (must not be an error result).
	 * @param array $section_map   CSV section name => array(
	 *                                'mode'       => 'create'|'existing'|'skip',
	 *                                'section_id' => int (for 'existing'),
	 *                                'rated'      => 0|1 (for 'create'),
	 *                                'quads'      => 0|1 (for 'create' only; splits the
	 *                                                CSV section into quad/Swiss groups
	 *                                                per split_into_quads() instead of
	 *                                                creating it as a single section -
	 *                                                docs/SPEC.md, "Decisions
	 *                                                (2026-07-09, round robin and
	 *                                                quads)"; ignored for 'existing',
	 *                                                since a roster mapped onto a
	 *                                                section that already exists is
	 *                                                never split),
	 *                              )
	 * @return array Summary: array(
	 *   'sections_created' => int,
	 *   'players_imported' => int,
	 *   'players_skipped'  => int,
	 *   'sections'         => array( section_name => array( 'section_id', 'imported' ) ),
	 *                         // section_name is the CSV name, or "CSV name Quad N" /
	 *                         // "CSV name Swiss" for each group of a quad split.
	 *   'warnings'         => array( string, ... ),
	 * )
	 */
	public function import( $tournament_id, array $parsed, array $section_map ) {
		$tournament_id = (int) $tournament_id;

		$summary = array(
			'sections_created' => 0,
			'players_imported' => 0,
			'players_skipped'  => 0,
			'sections'         => array(),
			'warnings'         => array(),
		);

		$rows_by_section = array();
		foreach ( $parsed['rows'] as $row ) {
			if ( ! empty( $row['skip'] ) ) {
				$summary['players_skipped']++;
				continue;
			}
			$rows_by_section[ $row['section'] ][] = $row;
		}

		foreach ( $section_map as $csv_section_name => $map ) {
			$csv_section_name = (string) $csv_section_name;
			$mode             = isset( $map['mode'] ) ? $map['mode'] : 'skip';
			$rows             = isset( $rows_by_section[ $csv_section_name ] ) ? $rows_by_section[ $csv_section_name ] : array();

			if ( 'skip' === $mode || ! in_array( $mode, array( 'create', 'existing' ), true ) ) {
				$summary['players_skipped'] += count( $rows );
				continue;
			}

			$rated = ! empty( $map['rated'] ) ? 1 : 0;

			// Quads split only ever applies to a brand-new section - a CSV
			// section mapped onto one that already exists is appended to
			// as-is, never split, regardless of what the checkbox posted.
			if ( 'create' === $mode && ! empty( $map['quads'] ) ) {
				$this->import_as_quads( $tournament_id, $csv_section_name, $rows, $rated, $summary );
				continue;
			}

			if ( 'create' === $mode ) {
				$section_id = $this->create_section(
					$tournament_id,
					array(
						'sec_name' => $csv_section_name,
						'trn_type' => 'S',
						'tot_rnds' => 0,
						'rated'    => $rated,
					)
				);
				$summary['sections_created']++;
			} else { // 'existing'
				$section_id = isset( $map['section_id'] ) ? (int) $map['section_id'] : 0;
				$section    = $section_id ? WPMTM_Repository::get_section( $section_id ) : null;
				$owned      = $section && (int) $section->tournament_id === $tournament_id;
				if ( ! $owned ) {
					$summary['warnings'][] = sprintf( 'Section "%s" was mapped to an existing section that could not be found; its rows were skipped.', $csv_section_name );
					$summary['players_skipped'] += count( $rows );
					continue;
				}
			}

			// Ordering IS the pairing-number assignment: rating descending,
			// blank ratings last, ties by name - see order_players_for_pairing().
			$ordered = self::order_players_for_pairing( $rows );

			$existing_max = $this->max_pair_num( $section_id );
			if ( $existing_max > 0 ) {
				$summary['warnings'][] = sprintf( 'Section "%s" already had players; the imported roster was appended after existing pairing number %d.', $csv_section_name, $existing_max );
			}

			$imported_here = $this->insert_players( $section_id, $ordered, $existing_max + 1 );

			$summary['players_imported']            += $imported_here;
			$summary['sections'][ $csv_section_name ] = array(
				'section_id' => $section_id,
				'imported'   => $imported_here,
			);
		}

		$summary['warnings'] = array_merge( $summary['warnings'], $parsed['warnings'] );

		return $summary;
	}

	/**
	 * Creates the quad/Swiss group sections for one CSV section's rows and
	 * folds their totals into $summary (passed by reference), per
	 * split_into_quads(). Each group becomes its own brand-new section, so
	 * pair numbers always start fresh at 1 within the group - there is no
	 * "already had players" case here, unlike the plain create/existing path.
	 */
	protected function import_as_quads( $tournament_id, $csv_section_name, array $rows, $rated, array &$summary ) {
		$ordered = self::order_players_for_pairing( $rows );
		$groups  = self::split_into_quads( $ordered );

		foreach ( $groups as $group ) {
			$group_name = trim( $csv_section_name . ' ' . $group['suffix'] );

			$section_id = $this->create_section(
				$tournament_id,
				array(
					'sec_name' => $group_name,
					'trn_type' => $group['trn_type'],
					// A round robin of 4 needs 3 rounds (n - 1); the folded
					// Swiss remainder group also runs 3 rounds, per
					// docs/SPEC.md's quad-split decision.
					'tot_rnds' => 3,
					'rated'    => $rated,
				)
			);
			$summary['sections_created']++;

			$imported_here = $this->insert_players( $section_id, $group['players'], 1 );

			$summary['players_imported']  += $imported_here;
			$summary['sections'][ $group_name ] = array(
				'section_id' => $section_id,
				'imported'   => $imported_here,
			);

			if ( ! empty( $group['warning'] ) ) {
				$summary['warnings'][] = sprintf( '%s: %s', $group_name, $group['warning'] );
			}
		}
	}

	/**
	 * Inserts a fresh wpmtm_sections row and returns its id. $fields must
	 * supply sec_name, trn_type, tot_rnds, rated; sec_num is assigned here
	 * via WPMTM_Repository::next_sec_num() and everything else uses the
	 * same defaults the plain (non-quad) create path always used.
	 */
	protected function create_section( $tournament_id, array $fields ) {
		global $wpdb;
		$sections_table = WPMTM_Schema::table( 'sections' );

		$wpdb->insert(
			$sections_table,
			array(
				'tournament_id' => $tournament_id,
				'sec_num'       => WPMTM_Repository::next_sec_num( $tournament_id ),
				'sec_name'      => $fields['sec_name'],
				'r_system'      => 'R',
				'timectl'       => '',
				'trn_type'      => $fields['trn_type'],
				'tot_rnds'      => $fields['tot_rnds'],
				'gr_prix'       => 'N',
				'gp_pts'        => 0,
				'fide'          => 'N',
				'rated'         => $fields['rated'],
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%d' )
		);

		return (int) $wpdb->insert_id;
	}

	protected function max_pair_num( $section_id ) {
		global $wpdb;
		$players_table = WPMTM_Schema::table( 'players' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(pair_num) FROM {$players_table} WHERE section_id = %d", $section_id ) );
	}

	/**
	 * Inserts $ordered as a section's players, starting at $start_pair_num
	 * and incrementing by 1 per row. Returns the number of rows inserted.
	 * $row['photo_id'] (int|null, see normalize_rows()'s docblock) is
	 * written straight through to wpmtm_players.photo_id - null for a CSV
	 * import or any wp-etr row with no photo on file.
	 */
	protected function insert_players( $section_id, array $ordered, $start_pair_num ) {
		global $wpdb;
		$players_table = WPMTM_Schema::table( 'players' );

		$pair_num = (int) $start_pair_num;
		$inserted = 0;
		foreach ( $ordered as $row ) {
			$wpdb->insert(
				$players_table,
				array(
					'section_id' => $section_id,
					'pair_num'   => $pair_num,
					'mem_id'     => '' !== $row['mem_id'] ? $row['mem_id'] : null,
					'name'       => $row['name'],
					'state'      => null,
					'rating'     => '' !== $row['rating'] ? $row['rating'] : null,
					'photo_id'   => ! empty( $row['photo_id'] ) ? (int) $row['photo_id'] : null,
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s', '%d' )
			);
			$pair_num++;
			$inserted++;
		}

		return $inserted;
	}
}
