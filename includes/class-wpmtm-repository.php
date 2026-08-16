<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * $wpdb-bound data access for tournaments/sections/players, shared by
 * WPMTM_Admin, WPMTM_Admin_Import, and WPMTM_ETR_Import so each stops
 * keeping its own copy of the same lookups, numbering, and cascade-delete
 * queries. Every method opens `global $wpdb` itself. Four reads are memoized
 * per request (get_tournament_by_event, get_sections, rounds_with_results,
 * rounds_with_results_by_sections - see $memo below); everything else is
 * uncached, so a caller that repeats another read and cares should cache it
 * locally, the way WPMTM_Frontend_Public::section_data_arrays() does.
 *
 * Not unit-tested by tests/run-tests.php: every method is $wpdb-bound and
 * this project does not fake $wpdb for the plain-PHP test runner.
 */
class WPMTM_Repository {

	/**
	 * Per-request read memo for the lookups a single page render repeats
	 * (audit item 48). Counted from the call sites, one TD viewing an event
	 * page with wp-etr tabs active and N sections issued:
	 *
	 * 1. get_tournament_by_event() three times - the FINAL badge, the setup
	 *    guide slot, and the tab filter, each hooked independently and each
	 *    resolving the tournament for itself.
	 * 2. get_sections() nine times - once per tab entry point, once per tab's
	 *    command row via rated_and_complete(), and once for the wizard panel.
	 * 3. rounds_with_results_by_sections() four times, once per tab's command
	 *    row, again via rated_and_complete().
	 * 4. rounds_with_results() 2N times - once in each section's standings
	 *    table and again in each section's round-entry panel.
	 *
	 * Every one of those returns identical rows. The batched round lookup also
	 * seeds the per-section keys the unbatched one reads, so once any tab has
	 * run rated_and_complete() the whole render's round questions are answered
	 * by one query rather than 4 + 2N.
	 *
	 * Only these four are memoized. They are pure lookups that a render path
	 * repeats, whereas get_section()/get_players() are read inside the save
	 * handlers' own write loops, where a stale read would be a correctness
	 * bug rather than a saved query.
	 *
	 * Correctness rule: every write path that could change what either lookup
	 * returns calls flush_memo() - the write methods below do it themselves,
	 * and the three handlers that write to wpmtm_tournaments / wpmtm_sections
	 * with their own $wpdb calls (WPMTM_Admin::handle_save_tournament(),
	 * WPMTM_Admin_Sections::handle_save_sections(), and
	 * WPMTM_ETR_Import::create_section()) call it after their write. In
	 * practice all of those redirect and exit immediately afterwards, so the
	 * flush is belt and braces, which is exactly what it should be.
	 *
	 * @var array
	 */
	private static $memo = array();

	/**
	 * Drops the read memo above. Called by every write path in this class,
	 * and by the handlers that write to these tables directly.
	 */
	public static function flush_memo() {
		self::$memo = array();
	}

	// -----------------------------------------------------------------
	// Reads.
	// -----------------------------------------------------------------

	public static function get_tournament( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . WPMTM_Schema::table( 'tournaments' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom wpmtm_* table, no core API; table name from WPMTM_Schema::table(), not user input; value bound via prepare().
	}

	public static function get_section( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . WPMTM_Schema::table( 'sections' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom wpmtm_* table, no core API; table name from WPMTM_Schema::table(), not user input; value bound via prepare().
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
		$key = 'tournament_by_event:' . $event_post_id;
		if ( array_key_exists( $key, self::$memo ) ) {
			return self::$memo[ $key ];
		}
		global $wpdb;
		self::$memo[ $key ] = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . WPMTM_Schema::table( 'tournaments' ) . ' WHERE event_post_id = %d', $event_post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom wpmtm_* table, no core API; table name from WPMTM_Schema::table(), not user input; value bound via prepare().
		return self::$memo[ $key ];
	}

	public static function get_sections( $tournament_id ) {
		$tournament_id = (int) $tournament_id;
		$key           = 'sections:' . $tournament_id;
		if ( array_key_exists( $key, self::$memo ) ) {
			return self::$memo[ $key ];
		}
		global $wpdb;
		self::$memo[ $key ] = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . WPMTM_Schema::table( 'sections' ) . ' WHERE tournament_id = %d ORDER BY sec_num ASC', $tournament_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom wpmtm_* table, no core API; table name from WPMTM_Schema::table(), not user input; value bound via prepare().
		return self::$memo[ $key ];
	}

	public static function get_players( $section_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . WPMTM_Schema::table( 'players' ) . ' WHERE section_id = %d ORDER BY pair_num ASC', $section_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom wpmtm_* table, no core API; table name from WPMTM_Schema::table(), not user input; value bound via prepare().
	}

	public static function count_players( $section_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . WPMTM_Schema::table( 'players' ) . ' WHERE section_id = %d', $section_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom wpmtm_* table, no core API; table name from WPMTM_Schema::table(), not user input; value bound via prepare().
	}

	/**
	 * How many players in a tournament have a member id but no linked
	 * attendee_id yet (attendee_id column added DB_VERSION 0.1.14). Cheap gate
	 * so backfill_attendee_ids() is only attempted when there is something to
	 * link.
	 */
	public static function count_unlinked_players( $tournament_id ) {
		global $wpdb;
		$players  = WPMTM_Schema::table( 'players' );
		$sections = WPMTM_Schema::table( 'sections' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom wpmtm_* tables, no core API; table names from WPMTM_Schema::table(), not user input; value bound via prepare().
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$players} p JOIN {$sections} s ON s.id = p.section_id WHERE s.tournament_id = %d AND p.attendee_id IS NULL AND p.mem_id IS NOT NULL AND p.mem_id <> ''",
			$tournament_id
		) );
	}

	/**
	 * Backfills attendee_id on players that were imported before the column
	 * existed (or added by CSV) by matching their USCF member id against the
	 * linked event's current attendees (via wp-etr's build_sections(), whose
	 * rows carry both the attendee post id and the member id). Idempotent:
	 * only touches rows with a NULL attendee_id and an UNAMBIGUOUS member-id
	 * match (a member id shared by two attendees is skipped, never guessed).
	 * Returns the number of rows linked. No-op without wp-etr or a linked event.
	 *
	 * @param object $tournament A wpmtm_tournaments row (needs event_post_id, id).
	 * @return int
	 */
	public static function backfill_attendee_ids( $tournament ) {
		if ( ! $tournament || empty( $tournament->event_post_id ) ) {
			return 0;
		}
		if ( ! class_exists( '\Etr\Plugin' ) || ! method_exists( \Etr\Plugin::instance(), 'build_sections' ) ) {
			return 0;
		}

		$by_mem = array();
		foreach ( (array) \Etr\Plugin::instance()->build_sections( (int) $tournament->event_post_id ) as $rows ) {
			foreach ( (array) $rows as $r ) {
				$aid = isset( $r['id'] ) ? (int) $r['id'] : 0;
				$mem = isset( $r['uscf_id'] ) ? WPMTM_USCF_Status::normalize_member_id_input( (string) $r['uscf_id'] ) : '';
				if ( ! $aid || '' === $mem ) {
					continue;
				}
				// A member id already seen is ambiguous - null it so the match
				// below skips it rather than picking the wrong attendee.
				$by_mem[ $mem ] = array_key_exists( $mem, $by_mem ) ? 0 : $aid;
			}
		}
		if ( empty( $by_mem ) ) {
			return 0;
		}

		global $wpdb;
		$players_table  = WPMTM_Schema::table( 'players' );
		$sections_table = WPMTM_Schema::table( 'sections' );

		// One read for every candidate row across the whole tournament,
		// instead of get_sections() plus a get_players() per section. Same
		// predicate as count_unlinked_players(), which is the cheap gate the
		// only caller checks before getting here.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom wpmtm_* tables, no core API; table names from WPMTM_Schema::table(), not user input; value bound via prepare().
		$candidates = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.id, p.mem_id FROM {$players_table} p JOIN {$sections_table} s ON s.id = p.section_id WHERE s.tournament_id = %d AND p.attendee_id IS NULL AND p.mem_id IS NOT NULL AND p.mem_id <> ''",
			(int) $tournament->id
		) );

		$updates = array(); // player id => attendee id.
		foreach ( (array) $candidates as $p ) {
			$mem = WPMTM_USCF_Status::normalize_member_id_input( (string) $p->mem_id );
			if ( '' === $mem || empty( $by_mem[ $mem ] ) ) {
				continue;
			}
			$updates[ (int) $p->id ] = (int) $by_mem[ $mem ];
		}
		if ( empty( $updates ) ) {
			return 0;
		}

		// Audit item 38: this used to be one UPDATE per matched player, run
		// during a front-end GET render. Collapsed into a single CASE update
		// so a roster imported before the attendee_id column existed costs one
		// statement to link, not one per player. Both the CASE arms and the
		// IN() list are built from ints keyed/valued out of the query above,
		// and every one is still bound through prepare().
		$cases  = '';
		$values = array();
		foreach ( $updates as $player_id => $attendee_id ) {
			$cases   .= ' WHEN %d THEN %d';
			$values[] = $player_id;
			$values[] = $attendee_id;
		}
		$placeholders = implode( ',', array_fill( 0, count( $updates ), '%d' ) );
		$values       = array_merge( $values, array_keys( $updates ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- custom wpmtm_players table, no core API; the CASE arms and IN() list are %d placeholders built above, every value bound via prepare().
		$result = $wpdb->query( $wpdb->prepare(
			"UPDATE {$players_table} SET attendee_id = CASE id{$cases} END WHERE id IN ({$placeholders})",
			$values
		) );

		self::flush_memo();

		return false === $result ? 0 : count( $updates );
	}

	/**
	 * Every tournament with its section and player counts, in one query
	 * (LEFT JOIN + aggregate) instead of a COUNT pair per row on the
	 * tournaments list screen. Each returned row is the tournament row
	 * plus two extra numeric properties: section_count, player_count.
	 *
	 * @return object[]
	 */
	public static function tournaments_with_counts( $limit = 0, $offset = 0, $created_by = 0 ) {
		global $wpdb;
		$t_table = WPMTM_Schema::table( 'tournaments' );
		$s_table = WPMTM_Schema::table( 'sections' );
		$p_table = WPMTM_Schema::table( 'players' );

		$limit      = max( 0, (int) $limit );
		$offset     = max( 0, (int) $offset );
		$created_by = max( 0, (int) $created_by );

		// Ownership filter in SQL, not in PHP after the fact (audit item 51).
		// The list screen used to fetch every tournament ever held and then
		// array_filter() a dedicated-role TD's own back out of it, so the cost
		// of the screen grew with the whole club's history rather than with
		// what the viewer can actually see. NULL created_by is grandfathered in
		// as manageable by any capability holder, matching
		// WPMTM_Roles::user_can_manage_tournament()'s own rule.
		$where  = '';
		$values = array();
		if ( $created_by ) {
			$where    = 'WHERE t.created_by IS NULL OR t.created_by = %d';
			$values[] = $created_by;
		}

		$limit_sql = '';
		if ( $limit ) {
			$limit_sql = 'LIMIT %d OFFSET %d';
			$values[]  = $limit;
			$values[]  = $offset;
		}

		$sql = "SELECT t.*,
				COUNT(DISTINCT s.id) AS section_count,
				COUNT(p.id) AS player_count
			FROM {$t_table} t
			LEFT JOIN {$s_table} s ON s.tournament_id = t.id
			LEFT JOIN {$p_table} p ON p.section_id = s.id
			{$where}
			GROUP BY t.id
			ORDER BY t.begin_date DESC, t.id DESC
			{$limit_sql}";

		if ( empty( $values ) ) {
			return $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- static SQL, no user input.
		}

		return $wpdb->get_results( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table names come from WPMTM_Schema::table() and the WHERE/LIMIT fragments are %d placeholders built above; every value is bound via prepare().
	}

	/**
	 * How many tournaments the list screen's pager is paging through, under
	 * the same ownership filter tournaments_with_counts() applies (audit item
	 * 51). No join: the counts per row are irrelevant here.
	 *
	 * @param int $created_by 0 for every tournament (an administrator), or a
	 *                        user id to count only theirs plus ownerless ones.
	 * @return int
	 */
	public static function count_tournaments( $created_by = 0 ) {
		global $wpdb;
		$t_table    = WPMTM_Schema::table( 'tournaments' );
		$created_by = max( 0, (int) $created_by );

		if ( ! $created_by ) {
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from WPMTM_Schema::table(), not user input; no other input in this statement.
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from WPMTM_Schema::table(), not user input; value bound via prepare().
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$t_table} WHERE created_by IS NULL OR created_by = %d",
			$created_by
		) );
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

		$sql = "SELECT s.id AS section_id, COUNT(p.id) AS player_count FROM {$s_table} s LEFT JOIN {$p_table} p ON p.section_id = s.id WHERE s.tournament_id = %d GROUP BY s.id"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom wpmtm_* tables, no core API; $s_table/$p_table come from WPMTM_Schema::table(), a trusted internal constant, not user input.

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- custom wpmtm_* tables, no core API; $sql is the literal string built above (not user input) and IS passed through $wpdb->prepare() with $tournament_id bound as its placeholder; the sniff cannot see through the $sql variable to confirm that statically.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $tournament_id ) );

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
			return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom wpmtm_* table, no core API; table name from WPMTM_Schema::table(), not user input; value bound via prepare().
				$wpdb->prepare( "SELECT * FROM {$table} WHERE section_id = %d AND round = %d ORDER BY round ASC, board ASC", $section_id, $round ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from WPMTM_Schema::table(), not user input.
			);
		}

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom wpmtm_* table, no core API; table name from WPMTM_Schema::table(), not user input; value bound via prepare().
			$wpdb->prepare( "SELECT * FROM {$table} WHERE section_id = %d ORDER BY round ASC, board ASC", $section_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from WPMTM_Schema::table(), not user input.
		);
	}

	/**
	 * The most recent time a round's boards were written, as a 'Y-m-d H:i:s'
	 * string in the site timezone (WPMTM_Repository::replace_round() stamps
	 * every inserted row with current_time('mysql')), or null when the round
	 * has no game rows yet - either never saved, or a byes-only round, which
	 * writes no game row and therefore carries no timestamp. Powers the
	 * "Last saved ..." cue under the round-entry Save button.
	 *
	 * @return string|null
	 */
	public static function get_round_saved_at( $section_id, $round ) {
		global $wpdb;
		$table = WPMTM_Schema::table( 'games' );

		$value = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom wpmtm_* table, no core API; table name from WPMTM_Schema::table(), not user input; values bound via prepare().
			$wpdb->prepare( "SELECT MAX(saved_at) FROM {$table} WHERE section_id = %d AND round = %d", (int) $section_id, (int) $round ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from WPMTM_Schema::table(), not user input.
		);

		return ( null === $value || '' === $value ) ? null : (string) $value;
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

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom wpmtm_* table, no core API; table name from WPMTM_Schema::table(), not user input; value bound via prepare().
			$wpdb->prepare(
				"SELECT b.* FROM {$byes_table} b INNER JOIN {$players_table} p ON p.id = b.player_id WHERE p.section_id = %d ORDER BY b.round ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from WPMTM_Schema::table(), not user input.
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
		$key = 'rounds:' . (int) $section_id;
		if ( array_key_exists( $key, self::$memo ) ) {
			return self::$memo[ $key ];
		}

		global $wpdb;
		$games_table   = WPMTM_Schema::table( 'games' );
		$byes_table    = WPMTM_Schema::table( 'byes' );
		$players_table = WPMTM_Schema::table( 'players' );
		$section_id    = (int) $section_id;

		$sql = "SELECT round FROM {$games_table} WHERE section_id = %d
			UNION
			SELECT b.round FROM {$byes_table} b INNER JOIN {$players_table} p ON p.id = b.player_id WHERE p.section_id = %d
			ORDER BY round ASC";

		$rows = $wpdb->get_col( $wpdb->prepare( $sql, $section_id, $section_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names are hardcoded constants above, not user input; both %d placeholders are bound via prepare().

		self::$memo[ $key ] = array_map( 'intval', $rows );
		return self::$memo[ $key ];
	}

	/**
	 * Whether any game row exists anywhere in a tournament, paired or scored.
	 *
	 * Used only to pick the Rounds tab's opening mode: an event with nothing
	 * recorded at all has not been paired yet, so the tab opens on pairing.
	 * Deliberately the loosest possible test - a single paired board is enough
	 * to mean "this event is under way".
	 *
	 * @param int $tournament_id
	 * @return bool
	 */
	public static function tournament_has_games( $tournament_id ) {
		$tournament_id = (int) $tournament_id;
		$key           = 'has_games:' . $tournament_id;
		if ( array_key_exists( $key, self::$memo ) ) {
			return self::$memo[ $key ];
		}

		global $wpdb;
		$games_table    = WPMTM_Schema::table( 'games' );
		$sections_table = WPMTM_Schema::table( 'sections' );

		$found = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom wpmtm_* tables, no core API; table names from WPMTM_Schema::table(), not user input; value bound via prepare().
			$wpdb->prepare( "SELECT EXISTS( SELECT 1 FROM {$games_table} g INNER JOIN {$sections_table} s ON s.id = g.section_id WHERE s.tournament_id = %d )", $tournament_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from WPMTM_Schema::table(), not user input.
		);

		self::$memo[ $key ] = ( 1 === $found );
		return self::$memo[ $key ];
	}

	/**
	 * Rounds that are fully SCORED, as opposed to merely populated.
	 *
	 * rounds_with_results() counts a round as soon as any game or bye row
	 * exists for it, which is the right rule for navigation (the round
	 * selector must offer a round the moment it has been paired). It became
	 * the wrong rule for completeness the day "Save pairings" started writing
	 * game rows whose result is blank: a section whose rounds were all paired
	 * but none played would have reported itself complete, which ranks the
	 * standings by tiebreak, offers the USCF export, and tells the setup guide
	 * the event is finished.
	 *
	 * A round counts here when it has at least one game or bye AND no game in
	 * it is still waiting on a result. A byes-only round has nothing to score,
	 * so it counts as soon as it exists.
	 *
	 * @param int $section_id
	 * @return int[] Distinct round numbers, ascending.
	 */
	public static function rounds_fully_scored( $section_id ) {
		$section_id = (int) $section_id;
		$key        = 'rounds_scored:' . $section_id;
		if ( array_key_exists( $key, self::$memo ) ) {
			return self::$memo[ $key ];
		}

		global $wpdb;
		$games_table   = WPMTM_Schema::table( 'games' );
		$byes_table    = WPMTM_Schema::table( 'byes' );
		$players_table = WPMTM_Schema::table( 'players' );

		$sql = "SELECT r.round FROM (
				SELECT round FROM {$games_table} WHERE section_id = %d
				UNION
				SELECT b.round FROM {$byes_table} b INNER JOIN {$players_table} p ON p.id = b.player_id WHERE p.section_id = %d
			) r
			WHERE NOT EXISTS (
				SELECT 1 FROM {$games_table} g
				WHERE g.section_id = %d AND g.round = r.round AND ( g.result IS NULL OR g.result = '' )
			)
			ORDER BY r.round ASC";

		$rows = $wpdb->get_col( $wpdb->prepare( $sql, $section_id, $section_id, $section_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names are hardcoded constants above, not user input; every value is bound via prepare().

		self::$memo[ $key ] = array_map( 'intval', $rows );
		return self::$memo[ $key ];
	}

	/**
	 * rounds_fully_scored() batched across many sections, mirroring
	 * rounds_with_results_by_sections() so the completeness callers keep their
	 * single-query shape.
	 *
	 * @param array $section_ids
	 * @return array section_id => int[] rounds, every requested id present.
	 */
	public static function rounds_fully_scored_by_sections( array $section_ids ) {
		$section_ids = array_values( array_unique( array_map( 'intval', $section_ids ) ) );
		sort( $section_ids );

		$batch_key = 'rounds_scored_batch:' . implode( ',', $section_ids );
		if ( array_key_exists( $batch_key, self::$memo ) ) {
			return self::$memo[ $batch_key ];
		}

		$results = array();
		foreach ( $section_ids as $id ) {
			$results[ $id ] = array();
		}
		if ( empty( $section_ids ) ) {
			self::$memo[ $batch_key ] = $results;
			return $results;
		}

		global $wpdb;
		$games_table   = WPMTM_Schema::table( 'games' );
		$byes_table    = WPMTM_Schema::table( 'byes' );
		$players_table = WPMTM_Schema::table( 'players' );

		$placeholders = implode( ',', array_fill( 0, count( $section_ids ), '%d' ) );

		$sql = "SELECT r.section_id, r.round FROM (
				SELECT section_id, round FROM {$games_table} WHERE section_id IN ({$placeholders})
				UNION
				SELECT p.section_id AS section_id, b.round AS round FROM {$byes_table} b INNER JOIN {$players_table} p ON p.id = b.player_id WHERE p.section_id IN ({$placeholders})
			) r
			WHERE NOT EXISTS (
				SELECT 1 FROM {$games_table} g
				WHERE g.section_id = r.section_id AND g.round = r.round AND ( g.result IS NULL OR g.result = '' )
			)
			ORDER BY r.round ASC";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $section_ids, $section_ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- placeholders built above, values bound via prepare().

		foreach ( $rows as $row ) {
			$sid = (int) $row->section_id;
			if ( ! isset( $results[ $sid ] ) ) {
				$results[ $sid ] = array();
			}
			$results[ $sid ][] = (int) $row->round;
		}

		// Audit item 58: rounds_with_results_by_sections() below seeds the
		// per-section memo rounds_with_results() reads, so a render that hits
		// the batch first pays nothing for the per-section calls that follow.
		// This method never did the same for rounds_fully_scored(), so its
		// per-section callers (render_section_standings(),
		// render_section_td_panel(), handle_save_round()) each still cost one
		// query per section even after this batch already answered the same
		// question. Seed 'rounds_scored:{$sid}' the same way.
		foreach ( $results as $sid => $rounds ) {
			self::$memo[ 'rounds_scored:' . $sid ] = $rounds;
		}

		self::$memo[ $batch_key ] = $results;
		return $results;
	}

	/**
	 * rounds_with_results() batched across many sections in one query
	 * (WHERE section_id IN (...), following the batching pattern in
	 * delete_tournament_cascade()), instead of one query pair per section.
	 * Used by WPMTM_Wizard::build_state() (docs/SPEC.md, "Decisions
	 * (2026-07-16, wizard N+1 queries)"), which previously called
	 * count_players() and rounds_with_results() once per section on every
	 * admin page load while guided setup is active - 2N queries for N
	 * sections.
	 *
	 * Corrected 2026-08-14 (audit item 66): this docblock used to sit above
	 * tournament_has_games() instead, orphaned there when that method was
	 * inserted between it and the method it actually describes.
	 *
	 * @param array $section_ids Section ids.
	 * @return array section_id => int[] of distinct round numbers with at
	 *               least one game or bye recorded, sorted ascending.
	 *               Every requested section id is present in the result
	 *               (an empty array for a section with no results yet).
	 */
	public static function rounds_with_results_by_sections( array $section_ids ) {
		$section_ids = array_values( array_unique( array_map( 'intval', $section_ids ) ) );
		sort( $section_ids );

		$batch_key = 'rounds_batch:' . implode( ',', $section_ids );
		if ( array_key_exists( $batch_key, self::$memo ) ) {
			return self::$memo[ $batch_key ];
		}

		$results = array();
		foreach ( $section_ids as $id ) {
			$results[ $id ] = array();
		}
		if ( empty( $section_ids ) ) {
			self::$memo[ $batch_key ] = $results;
			return $results;
		}

		global $wpdb;
		$games_table   = WPMTM_Schema::table( 'games' );
		$byes_table    = WPMTM_Schema::table( 'byes' );
		$players_table = WPMTM_Schema::table( 'players' );

		$placeholders = implode( ',', array_fill( 0, count( $section_ids ), '%d' ) );

		$sql = "SELECT section_id, round FROM {$games_table} WHERE section_id IN ({$placeholders})
			UNION
			SELECT p.section_id AS section_id, b.round AS round FROM {$byes_table} b INNER JOIN {$players_table} p ON p.id = b.player_id WHERE p.section_id IN ({$placeholders})";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $section_ids, $section_ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- placeholders built above, values bound via prepare().

		foreach ( $rows as $row ) {
			$sid = (int) $row->section_id;
			if ( ! isset( $results[ $sid ] ) ) {
				$results[ $sid ] = array();
			}
			$results[ $sid ][] = (int) $row->round;
		}

		foreach ( $results as $sid => $rounds ) {
			$rounds = array_values( array_unique( $rounds ) );
			sort( $rounds );
			$results[ $sid ] = $rounds;
			// Fill the per-section memo rounds_with_results() reads (audit
			// item 48). One TD event-page render asks this batched question
			// four times (once per tab's command row, via rated_and_complete())
			// and then asks the per-section question 2N more times, once in
			// each section's standings table and again in its round-entry
			// panel. Seeding the same keys here collapses all of that to the
			// single query that ran first.
			self::$memo[ 'rounds:' . $sid ] = $results[ $sid ];
		}

		self::$memo[ $batch_key ] = $results;
		return $results;
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
		self::flush_memo();
		$section_id = (int) $section_id;
		$round      = (int) $round;

		$games_table   = WPMTM_Schema::table( 'games' );
		$byes_table    = WPMTM_Schema::table( 'byes' );
		$players_table = WPMTM_Schema::table( 'players' );

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- static SQL, no user input.

		$ok = false !== $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom wpmtm_* table, no core API; table name from WPMTM_Schema::table(), not user input; value bound via prepare().
			$wpdb->prepare( "DELETE FROM {$games_table} WHERE section_id = %d AND round = %d", $section_id, $round ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from WPMTM_Schema::table(), not user input.
		);

		$ok = $ok && false !== $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom wpmtm_* table, no core API; table name from WPMTM_Schema::table(), not user input; value bound via prepare().
			$wpdb->prepare(
				"DELETE b FROM {$byes_table} b INNER JOIN {$players_table} p ON p.id = b.player_id WHERE p.section_id = %d AND b.round = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from WPMTM_Schema::table(), not user input.
				$section_id,
				$round
			)
		);

		if ( $ok ) {
			foreach ( $boards as $board ) {
				$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom wpmtm_* table, no core API; $wpdb->update()/delete()/insert() escape values internally.
					$games_table,
					array(
						'section_id'      => $section_id,
						'round'           => $round,
						'board'           => (int) $board['board'],
						'white_player_id' => (int) $board['white_player_id'],
						'black_player_id' => (int) $board['black_player_id'],
						'result'          => $board['result'],
						'saved_at'        => current_time( 'mysql' ),
					),
					array( '%d', '%d', '%d', '%d', '%d', '%s', '%s' )
				);
				if ( false === $result ) {
					$ok = false;
					break;
				}
			}
		}

		if ( $ok ) {
			foreach ( $byes as $bye ) {
				$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom wpmtm_* table, no core API; $wpdb->update()/delete()/insert() escape values internally.
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
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- static SQL, no user input.
			return true;
		}

		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- static SQL, no user input.
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
	 *                      city, state, zipcode, show_photos, created_by;
	 *                      any missing key falls back to the same defaults
	 *                      handle_save_tournament() uses for a brand new
	 *                      tournament (created_by defaults to the current
	 *                      user, so an imported tournament is owned by the
	 *                      TD who clicked the import, never NULL-owned).
	 * @return int New tournament id, or 0 on failure.
	 */
	public static function create_tournament( array $fields ) {
		global $wpdb;
		$table = WPMTM_Schema::table( 'tournaments' );
		$now   = current_time( 'mysql' );

		$created_by = isset( $fields['created_by'] ) ? (int) $fields['created_by'] : (int) get_current_user_id();

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
			'created_by'      => $created_by > 0 ? $created_by : null,
			'created_at'      => $now,
			'updated_at'      => $now,
		);
		$formats = array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%s' );

		$result = $wpdb->insert( $table, $data, $formats ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom wpmtm_* table, no core API; $wpdb->update()/delete()/insert() escape values internally.

		self::flush_memo();

		return false !== $result ? (int) $wpdb->insert_id : 0;
	}

	// -----------------------------------------------------------------
	// Numbering.
	// -----------------------------------------------------------------

	public static function next_sec_num( $tournament_id ) {
		global $wpdb;
		$table = WPMTM_Schema::table( 'sections' );
		$max   = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(sec_num) FROM {$table} WHERE tournament_id = %d", $tournament_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom wpmtm_* table, no core API; table name from WPMTM_Schema::table(), not user input; value bound via prepare().
		return $max ? ( (int) $max + 1 ) : 1;
	}

	public static function next_pair_num( $section_id ) {
		global $wpdb;
		$table = WPMTM_Schema::table( 'players' );
		$max   = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(pair_num) FROM {$table} WHERE section_id = %d", $section_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom wpmtm_* table, no core API; table name from WPMTM_Schema::table(), not user input; value bound via prepare().
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

		return false !== $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom wpmtm_* table, no core API; $wpdb->update()/delete()/insert() escape values internally.
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
		self::flush_memo();
		return false !== $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom wpmtm_* table, no core API; $wpdb->update()/delete()/insert() escape values internally.
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
		self::flush_memo();
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom wpmtm_* table, no core API; table name from WPMTM_Schema::table(), not user input; value bound via prepare().
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE {$filter_column} = %d ORDER BY {$order_column} ASC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- column names are hardcoded by the two callers above, not user input.
				$filter_value
			)
		);
		$n = 1;
		foreach ( $ids as $id ) {
			$wpdb->update( $table, array( $number_column => $n ), array( 'id' => (int) $id ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom wpmtm_* table, no core API; $wpdb->update()/delete()/insert() escape values internally.
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

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom wpmtm_* table, no core API; table name from WPMTM_Schema::table(), not user input; value bound via prepare().
			$wpdb->prepare(
				"DELETE FROM {$games_table} WHERE section_id = %d AND (white_player_id = %d OR black_player_id = %d)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from WPMTM_Schema::table(), not user input.
				$section_id,
				$player_id,
				$player_id
			)
		);
		$wpdb->delete( $byes_table, array( 'player_id' => $player_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom wpmtm_* table, no core API; $wpdb->update()/delete()/insert() escape values internally.
		$wpdb->delete( $players_table, array( 'id' => $player_id, 'section_id' => $section_id ), array( '%d', '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom wpmtm_* table, no core API; $wpdb->update()/delete()/insert() escape values internally.
	}

	public static function delete_section_cascade( $section_id, $tournament_id ) {
		global $wpdb;
		self::flush_memo();
		$section_id = (int) $section_id;

		$sections_table = WPMTM_Schema::table( 'sections' );
		$players_table  = WPMTM_Schema::table( 'players' );
		$games_table    = WPMTM_Schema::table( 'games' );
		$byes_table     = WPMTM_Schema::table( 'byes' );

		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$sections_table} WHERE id = %d AND tournament_id = %d", $section_id, $tournament_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom wpmtm_* table, no core API; table name from WPMTM_Schema::table(), not user input; value bound via prepare().
		if ( ! $exists ) {
			return;
		}

		$player_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$players_table} WHERE section_id = %d", $section_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom wpmtm_* table, no core API; table name from WPMTM_Schema::table(), not user input; value bound via prepare().
		if ( $player_ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $player_ids ), '%d' ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$byes_table} WHERE player_id IN ({$placeholders})", $player_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built above, values bound via prepare().; custom wpmtm_* table, no core API; placeholders built above match count(), values bound via prepare().
		}

		$wpdb->delete( $games_table, array( 'section_id' => $section_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom wpmtm_* table, no core API; $wpdb->update()/delete()/insert() escape values internally.
		$wpdb->delete( $players_table, array( 'section_id' => $section_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom wpmtm_* table, no core API; $wpdb->update()/delete()/insert() escape values internally.
		$wpdb->delete( $sections_table, array( 'id' => $section_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom wpmtm_* table, no core API; $wpdb->update()/delete()/insert() escape values internally.
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

		self::flush_memo();

		// Both per-tournament options are metadata keyed by tournament id
		// rather than schema columns (docs/SPEC.md, 2026-07-16, TD check
		// timestamp; 2026-07-21, setup guide Export step), so they need their
		// own cleanup here - a dropped tournament id must never leave either
		// behind. uninstall.php sweeps both by prefix; this is the delete-one
		// path.
		//
		// Audit item 45: wpmtm_exported_ was missing here. Beyond the orphan
		// row, tournament ids are not unique forever - InnoDB before 8.0 resets
		// the AUTO_INCREMENT counter on restart - so a later tournament could
		// inherit a stale "already exported" flag and have the setup guide skip
		// its Export step.
		delete_option( 'wpmtm_td_check_' . $tournament_id );
		delete_option( WPMTM_Admin_Export::EXPORTED_OPTION_PREFIX . $tournament_id );

		$sections_table = WPMTM_Schema::table( 'sections' );
		$players_table  = WPMTM_Schema::table( 'players' );
		$games_table    = WPMTM_Schema::table( 'games' );
		$byes_table     = WPMTM_Schema::table( 'byes' );

		$section_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$sections_table} WHERE tournament_id = %d", $tournament_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom wpmtm_* table, no core API; table name from WPMTM_Schema::table(), not user input; value bound via prepare().
		if ( ! $section_ids ) {
			$wpdb->delete( WPMTM_Schema::table( 'tournaments' ), array( 'id' => $tournament_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom wpmtm_* table, no core API; $wpdb->update()/delete()/insert() escape values internally.
			return;
		}

		$section_placeholders = implode( ',', array_fill( 0, count( $section_ids ), '%d' ) );

		$player_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$players_table} WHERE section_id IN ({$section_placeholders})", $section_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built above, values bound via prepare().; custom wpmtm_* table, no core API; placeholders built above match count(), values bound via prepare().

		if ( $player_ids ) {
			$player_placeholders = implode( ',', array_fill( 0, count( $player_ids ), '%d' ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$byes_table} WHERE player_id IN ({$player_placeholders})", $player_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built above, values bound via prepare().; custom wpmtm_* table, no core API; placeholders built above match count(), values bound via prepare().
		}

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$games_table} WHERE section_id IN ({$section_placeholders})", $section_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built above, values bound via prepare().; custom wpmtm_* table, no core API; placeholders built above match count(), values bound via prepare().
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$players_table} WHERE section_id IN ({$section_placeholders})", $section_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built above, values bound via prepare().; custom wpmtm_* table, no core API; placeholders built above match count(), values bound via prepare().
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$sections_table} WHERE id IN ({$section_placeholders})", $section_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built above, values bound via prepare().; custom wpmtm_* table, no core API; placeholders built above match count(), values bound via prepare().

		$wpdb->delete( WPMTM_Schema::table( 'tournaments' ), array( 'id' => $tournament_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom wpmtm_* table, no core API; $wpdb->update()/delete()/insert() escape values internally.
	}
}
