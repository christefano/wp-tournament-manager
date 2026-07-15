<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * $wpdb-bound data access for tournaments/sections/players, shared by
 * WPMTM_Admin, WPMTM_Admin_Import, and WPMTM_ETR_Import so each stops
 * keeping its own copy of the same lookups, numbering, and cascade-delete
 * queries. Every method opens `global $wpdb` itself; there is no
 * request-level caching here (callers that need it cache locally).
 *
 * Not unit-tested by tests/run-tests.php: every method is $wpdb-bound and
 * this project does not fake $wpdb for the plain-PHP test runner.
 */
class WPMTM_Repository {

	// -----------------------------------------------------------------
	// Reads.
	// -----------------------------------------------------------------

	public static function get_tournament( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . WPMTM_Schema::table( 'tournaments' ) . ' WHERE id = %d', $id ) );
	}

	public static function get_section( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . WPMTM_Schema::table( 'sections' ) . ' WHERE id = %d', $id ) );
	}

	/**
	 * Finds the tournament (if any) already linked to a given event post,
	 * used to pre-check for a duplicate link before writing (each event can
	 * have only one tournament).
	 *
	 * @param int $event_post_id
	 * @return object|null Tournament row, or null for a 0/invalid id or no match.
	 */
	public static function get_tournament_by_event( $event_post_id ) {
		$event_post_id = (int) $event_post_id;
		if ( $event_post_id <= 0 ) {
			return null;
		}
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . WPMTM_Schema::table( 'tournaments' ) . ' WHERE event_post_id = %d', $event_post_id ) );
	}

	public static function get_sections( $tournament_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . WPMTM_Schema::table( 'sections' ) . ' WHERE tournament_id = %d ORDER BY sec_num ASC', $tournament_id ) );
	}

	public static function get_players( $section_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . WPMTM_Schema::table( 'players' ) . ' WHERE section_id = %d ORDER BY pair_num ASC', $section_id ) );
	}

	public static function count_players( $section_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . WPMTM_Schema::table( 'players' ) . ' WHERE section_id = %d', $section_id ) );
	}

	/**
	 * Every tournament with its section and player counts, in one query
	 * (LEFT JOIN + aggregate) instead of a COUNT pair per row on the
	 * tournaments list screen. Each returned row is the tournament row
	 * plus two extra numeric properties: section_count, player_count.
	 *
	 * @return object[]
	 */
	public static function tournaments_with_counts() {
		global $wpdb;
		$t_table = WPMTM_Schema::table( 'tournaments' );
		$s_table = WPMTM_Schema::table( 'sections' );
		$p_table = WPMTM_Schema::table( 'players' );

		$sql = "SELECT t.*,
				COUNT(DISTINCT s.id) AS section_count,
				COUNT(p.id) AS player_count
			FROM {$t_table} t
			LEFT JOIN {$s_table} s ON s.tournament_id = t.id
			LEFT JOIN {$p_table} p ON p.section_id = s.id
			GROUP BY t.id
			ORDER BY t.begin_date DESC, t.id DESC";

		return $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- static SQL, no user input.
	}

	/**
	 * Player counts for every section of a tournament in one GROUP BY
	 * query, used by the sections editor rows instead of a COUNT query
	 * per row.
	 *
	 * @return array section_id => player_count (int), sections with zero
	 *               players are included with a count of 0.
	 */
	public static function player_counts_by_section( $tournament_id ) {
		global $wpdb;
		$s_table = WPMTM_Schema::table( 'sections' );
		$p_table = WPMTM_Schema::table( 'players' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id AS section_id, COUNT(p.id) AS player_count
				FROM {$s_table} s
				LEFT JOIN {$p_table} p ON p.section_id = s.id
				WHERE s.tournament_id = %d
				GROUP BY s.id",
				$tournament_id
			)
		);

		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ (int) $row->section_id ] = (int) $row->player_count;
		}
		return $counts;
	}

	/**
	 * Games for a section, all rounds or one round, ordered for display.
	 *
	 * @param int      $section_id
	 * @param int|null $round Optional: limit to one round.
	 * @return object[]
	 */
	public static function get_games( $section_id, $round = null ) {
		global $wpdb;
		$table = WPMTM_Schema::table( 'games' );

		if ( null !== $round ) {
			return $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE section_id = %d AND round = %d ORDER BY round ASC, board ASC", $section_id, $round )
			);
		}

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE section_id = %d ORDER BY round ASC, board ASC", $section_id )
		);
	}

	/**
	 * Byes for every player in a section, joined through wpmtm_players
	 * since wpmtm_byes only stores player_id (byes are per-player, not
	 * per-section - docs/SPEC.md).
	 *
	 * @return object[]
	 */
	public static function get_byes_for_section( $section_id ) {
		global $wpdb;
		$byes_table    = WPMTM_Schema::table( 'byes' );
		$players_table = WPMTM_Schema::table( 'players' );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.* FROM {$byes_table} b INNER JOIN {$players_table} p ON p.id = b.player_id WHERE p.section_id = %d ORDER BY b.round ASC",
				$section_id
			)
		);
	}

	/**
	 * Distinct round numbers that already have at least one game or bye
	 * recorded for a section, used to default the round-entry panel's
	 * selected round to the first round with nothing entered yet.
	 *
	 * @return int[]
	 */
	public static function rounds_with_results( $section_id ) {
		global $wpdb;
		$games_table   = WPMTM_Schema::table( 'games' );
		$byes_table    = WPMTM_Schema::table( 'byes' );
		$players_table = WPMTM_Schema::table( 'players' );
		$section_id    = (int) $section_id;

		$sql = "SELECT round FROM {$games_table} WHERE section_id = %d
			UNION
			SELECT b.round FROM {$byes_table} b INNER JOIN {$players_table} p ON p.id = b.player_id WHERE p.section_id = %d
			ORDER BY round ASC";

		$rows = $wpdb->get_col( $wpdb->prepare( $sql, $section_id, $section_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names are hardcoded constants above, not user input; both %d placeholders are bound via prepare().

		return array_map( 'intval', $rows );
	}

	/**
	 * Replaces a whole round's games and byes for a section in one
	 * transaction: deletes whatever is currently stored for (section_id,
	 * round), then inserts the posted set. This delete-then-insert is the
	 * concurrency guard for the single-results-enterer business rule
	 * (docs/SPEC.md, "Decisions (2026-07-09, round entry)") - a double
	 * submit of the same form just rewrites the same state, rather than
	 * appending duplicate rows.
	 *
	 * @param int   $section_id
	 * @param int   $round
	 * @param array $boards List of assoc rows: board, white_player_id, black_player_id, result.
	 * @param array $byes   List of assoc rows: player_id, type.
	 * @return bool True on success; false (with the transaction rolled back) on any insert failure.
	 */
	public static function replace_round( $section_id, $round, array $boards, array $byes ) {
		global $wpdb;
		$section_id = (int) $section_id;
		$round      = (int) $round;

		$games_table   = WPMTM_Schema::table( 'games' );
		$byes_table    = WPMTM_Schema::table( 'byes' );
		$players_table = WPMTM_Schema::table( 'players' );

		$wpdb->query( 'START TRANSACTION' );

		$ok = false !== $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$games_table} WHERE section_id = %d AND round = %d", $section_id, $round )
		);

		$ok = $ok && false !== $wpdb->query(
			$wpdb->prepare(
				"DELETE b FROM {$byes_table} b INNER JOIN {$players_table} p ON p.id = b.player_id WHERE p.section_id = %d AND b.round = %d",
				$section_id,
				$round
			)
		);

		if ( $ok ) {
			foreach ( $boards as $board ) {
				$result = $wpdb->insert(
					$games_table,
					array(
						'section_id'      => $section_id,
						'round'           => $round,
						'board'           => (int) $board['board'],
						'white_player_id' => (int) $board['white_player_id'],
						'black_player_id' => (int) $board['black_player_id'],
						'result'          => $board['result'],
					),
					array( '%d', '%d', '%d', '%d', '%d', '%s' )
				);
				if ( false === $result ) {
					$ok = false;
					break;
				}
			}
		}

		if ( $ok ) {
			foreach ( $byes as $bye ) {
				$result = $wpdb->insert(
					$byes_table,
					array(
						'player_id' => (int) $bye['player_id'],
						'round'     => $round,
						'type'      => $bye['type'],
					),
					array( '%d', '%d', '%s' )
				);
				if ( false === $result ) {
					$ok = false;
					break;
				}
			}
		}

		if ( $ok ) {
			$wpdb->query( 'COMMIT' );
			return true;
		}

		$wpdb->query( 'ROLLBACK' );
		return false;
	}

	/**
	 * The full plain-array tournament structure WPMTM_Export_Builder::build()
	 * consumes: the tournament row plus every section, each with its
	 * players/games/byes, all as plain arrays (not objects). Reuses the
	 * existing per-table read methods above rather than new SQL.
	 *
	 * @param int $tournament_id
	 * @return array{tournament:array,sections:array[]}|null Null if the
	 *              tournament id does not exist.
	 */
	public static function get_export_bundle( $tournament_id ) {
		$tournament_id  = (int) $tournament_id;
		$tournament_row = self::get_tournament( $tournament_id );
		if ( ! $tournament_row ) {
			return null;
		}

		$sections = array();
		foreach ( self::get_sections( $tournament_id ) as $section_row ) {
			$section = (array) $section_row;

			$players = array();
			foreach ( self::get_players( $section_row->id ) as $player_row ) {
				$players[] = (array) $player_row;
			}

			$games = array();
			foreach ( self::get_games( $section_row->id ) as $game_row ) {
				$games[] = (array) $game_row;
			}

			$byes = array();
			foreach ( self::get_byes_for_section( $section_row->id ) as $bye_row ) {
				$byes[] = (array) $bye_row;
			}

			$section['players'] = $players;
			$section['games']   = $games;
			$section['byes']    = $byes;

			$sections[] = $section;
		}

		return array(
			'tournament' => (array) $tournament_row,
			'sections'   => $sections,
		);
	}

	/**
	 * Inserts a fresh wpmtm_tournaments row. Used by WPMTM_Admin_Import's
	 * "Import to Tournament Manager" handler to create the stub tournament
	 * when the clicked event has no linked tournament yet - a lighter-weight
	 * write than WPMTM_Admin::handle_save_tournament(), which is tied to a
	 * $_POST form and a redirect/exit, so is not reusable here as-is.
	 *
	 * @param array $fields event_post_id, name, rated, begin_date, end_date,
	 *                      city, state, zipcode, show_photos; any missing
	 *                      key falls back to the same defaults
	 *                      handle_save_tournament() uses for a brand new
	 *                      tournament.
	 * @return int New tournament id, or 0 on failure.
	 */
	public static function create_tournament( array $fields ) {
		global $wpdb;
		$table = WPMTM_Schema::table( 'tournaments' );
		$now   = current_time( 'mysql' );

		$data = array(
			'event_post_id'   => ! empty( $fields['event_post_id'] ) ? (int) $fields['event_post_id'] : null,
			'name'            => isset( $fields['name'] ) ? (string) $fields['name'] : '',
			'rated'           => ! empty( $fields['rated'] ) ? 1 : 0,
			'begin_date'      => ! empty( $fields['begin_date'] ) ? $fields['begin_date'] : null,
			'end_date'        => ! empty( $fields['end_date'] ) ? $fields['end_date'] : null,
			'city'            => ! empty( $fields['city'] ) ? $fields['city'] : null,
			'state'           => ! empty( $fields['state'] ) ? $fields['state'] : null,
			'zipcode'         => ! empty( $fields['zipcode'] ) ? $fields['zipcode'] : null,
			'country'         => 'USA',
			'send_crosstable' => 0,
			'show_photos'     => ! empty( $fields['show_photos'] ) ? 1 : 0,
			'status'          => 'setup',
			'created_at'      => $now,
			'updated_at'      => $now,
		);
		$formats = array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' );

		$result = $wpdb->insert( $table, $data, $formats );
		return false !== $result ? (int) $wpdb->insert_id : 0;
	}

	// -----------------------------------------------------------------
	// Numbering.
	// -----------------------------------------------------------------

	public static function next_sec_num( $tournament_id ) {
		global $wpdb;
		$table = WPMTM_Schema::table( 'sections' );
		$max   = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(sec_num) FROM {$table} WHERE tournament_id = %d", $tournament_id ) );
		return $max ? ( (int) $max + 1 ) : 1;
	}

	public static function next_pair_num( $section_id ) {
		global $wpdb;
		$table = WPMTM_Schema::table( 'players' );
		$max   = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(pair_num) FROM {$table} WHERE section_id = %d", $section_id ) );
		return $max ? ( (int) $max + 1 ) : 1;
	}

	/**
	 * Sets or clears a player's withdrawal flag. Writing null reinstates the
	 * player - this is always safe because withdrawing never deletes or
	 * writes any game/bye rows (docs/SPEC.md, withdrawals): it only sets
	 * this column, so clearing it is a plain undo.
	 *
	 * @param int      $player_id
	 * @param int|null $after_round_or_null Last round the player played, or
	 *                                      null to clear the flag.
	 * @return bool True on success.
	 */
	public static function set_player_withdrawn( $player_id, $after_round_or_null ) {
		global $wpdb;
		$table = WPMTM_Schema::table( 'players' );
		$value = null === $after_round_or_null ? null : (int) $after_round_or_null;

		return false !== $wpdb->update(
			$table,
			array( 'withdrawn_after_round' => $value ),
			array( 'id' => (int) $player_id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Flips a tournament's locked flag (Change 6, "conclude and lock a
	 * tournament"). Locking is always an explicit TD action - nothing in
	 * this plugin sets or clears this flag on its own -
	 * WPMTM_Admin::handle_toggle_lock() is the only caller.
	 *
	 * @param int  $tournament_id
	 * @param bool $locked
	 * @return bool True on success.
	 */
	public static function set_tournament_locked( $tournament_id, $locked ) {
		global $wpdb;
		return false !== $wpdb->update(
			WPMTM_Schema::table( 'tournaments' ),
			array(
				'locked'     => $locked ? 1 : 0,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $tournament_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
	}

	public static function renumber_sections( $tournament_id ) {
		self::renumber( WPMTM_Schema::table( 'sections' ), 'tournament_id', $tournament_id, 'sec_num', 'sec_num' );
	}

	public static function renumber_players( $section_id ) {
		self::renumber( WPMTM_Schema::table( 'players' ), 'section_id', $section_id, 'pair_num', 'pair_num' );
	}

	/** Shared renumbering helper: reassigns 1..N in existing order. */
	private static function renumber( $table, $filter_column, $filter_value, $order_column, $number_column ) {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE {$filter_column} = %d ORDER BY {$order_column} ASC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- column names are hardcoded by the two callers above, not user input.
				$filter_value
			)
		);
		$n = 1;
		foreach ( $ids as $id ) {
			$wpdb->update( $table, array( $number_column => $n ), array( 'id' => (int) $id ), array( '%d' ), array( '%d' ) );
			++$n;
		}
	}

	// -----------------------------------------------------------------
	// Cascade deletes.
	// -----------------------------------------------------------------

	public static function delete_player_cascade( $player_id, $section_id ) {
		global $wpdb;
		$player_id = (int) $player_id;

		$players_table = WPMTM_Schema::table( 'players' );
		$games_table   = WPMTM_Schema::table( 'games' );
		$byes_table    = WPMTM_Schema::table( 'byes' );

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$games_table} WHERE section_id = %d AND (white_player_id = %d OR black_player_id = %d)",
				$section_id,
				$player_id,
				$player_id
			)
		);
		$wpdb->delete( $byes_table, array( 'player_id' => $player_id ), array( '%d' ) );
		$wpdb->delete( $players_table, array( 'id' => $player_id, 'section_id' => $section_id ), array( '%d', '%d' ) );
	}

	public static function delete_section_cascade( $section_id, $tournament_id ) {
		global $wpdb;
		$section_id = (int) $section_id;

		$sections_table = WPMTM_Schema::table( 'sections' );
		$players_table  = WPMTM_Schema::table( 'players' );
		$games_table    = WPMTM_Schema::table( 'games' );
		$byes_table     = WPMTM_Schema::table( 'byes' );

		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$sections_table} WHERE id = %d AND tournament_id = %d", $section_id, $tournament_id ) );
		if ( ! $exists ) {
			return;
		}

		$player_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$players_table} WHERE section_id = %d", $section_id ) );
		if ( $player_ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $player_ids ), '%d' ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$byes_table} WHERE player_id IN ({$placeholders})", $player_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above, values bound via prepare().
		}

		$wpdb->delete( $games_table, array( 'section_id' => $section_id ), array( '%d' ) );
		$wpdb->delete( $players_table, array( 'section_id' => $section_id ), array( '%d' ) );
		$wpdb->delete( $sections_table, array( 'id' => $section_id ), array( '%d' ) );
	}

	/**
	 * Deletes a whole tournament and everything under it in batched IN()
	 * queries (one SELECT for section ids, one SELECT for player ids, then
	 * one DELETE per table over those id lists) instead of looping
	 * delete_section_cascade() per section - a tournament can have many
	 * sections/players, and this avoids O(sections) round trips on an
	 * admin action a TD might trigger on a large event.
	 */
	public static function delete_tournament_cascade( $tournament_id ) {
		global $wpdb;
		$tournament_id = (int) $tournament_id;

		$sections_table = WPMTM_Schema::table( 'sections' );
		$players_table  = WPMTM_Schema::table( 'players' );
		$games_table    = WPMTM_Schema::table( 'games' );
		$byes_table     = WPMTM_Schema::table( 'byes' );

		$section_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$sections_table} WHERE tournament_id = %d", $tournament_id ) );
		if ( ! $section_ids ) {
			$wpdb->delete( WPMTM_Schema::table( 'tournaments' ), array( 'id' => $tournament_id ), array( '%d' ) );
			return;
		}

		$section_placeholders = implode( ',', array_fill( 0, count( $section_ids ), '%d' ) );

		$player_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$players_table} WHERE section_id IN ({$section_placeholders})", $section_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above, values bound via prepare().

		if ( $player_ids ) {
			$player_placeholders = implode( ',', array_fill( 0, count( $player_ids ), '%d' ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$byes_table} WHERE player_id IN ({$player_placeholders})", $player_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above, values bound via prepare().
		}

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$games_table} WHERE section_id IN ({$section_placeholders})", $section_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above, values bound via prepare().
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$players_table} WHERE section_id IN ({$section_placeholders})", $section_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above, values bound via prepare().
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$sections_table} WHERE id IN ({$section_placeholders})", $section_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above, values bound via prepare().

		$wpdb->delete( WPMTM_Schema::table( 'tournaments' ), array( 'id' => $tournament_id ), array( '%d' ) );
	}
}
