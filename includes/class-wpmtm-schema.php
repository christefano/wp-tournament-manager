<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Activation: creates/upgrades the five wpmtm_* tables and grants the
 * plugin capability to administrators. Table layout follows the approved
 * schema in docs/SPEC.md ("Database schema (approved 2026-07-08)").
 *
 * Results are stored per-game (one row per board) in wpmtm_games, not
 * per-player: the TD enters one result per board and both players' DBF
 * round-tokens are derived from it via WPMTM_Scoring::RESULT_TOKEN_MAP,
 * so reciprocity errors are impossible by construction.
 * WPMTM_USCF_Validator remains the export-time backstop. Byes are
 * per-player, stored in wpmtm_byes.
 *
 * wpmtm_sections.auto_rounds_hint (added 0.1.9, docs/SPEC.md, "Decisions
 * (2026-07-16, auto-set Round Robin / Quad rounds)"): the tot_rnds value
 * WPMTM_Admin last auto-suggested for a Round Robin / Quad section, or
 * NULL when no auto-fill has happened (Swiss sections, or a TD-typed
 * value with no auto-fill history). Distinct from tot_rnds itself so a
 * later roster-size change can be told apart from a TD's deliberate
 * override: WPMTM_Admin_Sections::handle_save_sections() only re-suggests when
 * the posted tot_rnds is empty (0) or still equals this stored hint.
 *
 * wpmtm_sections.cycles (added 0.1.15, docs/SPEC.md, "Decisions (2026-08-13,
 * double round robin / double quads via a cycles flag)"): how many times a
 * Round Robin / Quad section runs its schedule. 1 is the historic single
 * cycle and the default for every existing row, so the upgrade is a no-op for
 * current sections. 2 is a double round robin, and a 4-player double round
 * robin is the "double quad": six rounds, every pair meeting twice, once with
 * each color. Ignored for Swiss sections. The ceiling lives in
 * WPMTM_Pairing_Aid::MAX_CYCLES, which also normalizes the stored value.
 *
 * wpmtm_games.saved_at (added 0.1.16, docs/SPEC.md, "Decisions (2026-08-13,
 * last-saved cue on the round-entry form)"): the time a round's boards were
 * last written. WPMTM_Repository::replace_round() stamps every row it inserts
 * with current_time('mysql'), and the round-entry form reads MAX(saved_at) for
 * the round to show a "Last saved ..." line under the Save button, matching the
 * ETECF registration form's cue. NULL on rows from before the upgrade, and
 * absent entirely for a byes-only round (which writes no game row), where no
 * cue is shown.
 *
 * wpmtm_players.rating_source / rating_checked (added 0.1.10, docs/SPEC.md,
 * "Decisions (2026-07-17, rating provenance)"): mirrors the attendee-level
 * _wpmtm_rating_source / _wpmtm_rating_checked meta WPMTM_Registration_Check
 * writes whenever it overwrites a rating with the official USCF value, the
 * same way photo_id and family_key already carry an ETECF attendee value
 * through into this table. rating_source is 'official' or NULL ("not
 * written by us" - the honest default); rating_checked is a
 * current_time('timestamp')-style unix integer or NULL. Only ever set by
 * the roster import (from the one-click "Import to Tournament Manager"
 * door; a CSV import carries neither, same as photo_id/family_key); a
 * hand-edited rating in the roster editor clears both, since a TD's typed
 * value is no longer what USCF said.
 *
 * wpmtm_players.notes (added 0.1.11, docs/SPEC.md, "Decisions (2026-07-17,
 * import the registrant note)"): carries ETECF's free-text
 * etecf_additional_information attendee meta into the roster, the
 * deliberately lightweight alternative to a structured byes-by-round
 * field. Registrants sometimes type a bye request into this box; rather
 * than build ETECF fields or byes-by-round machinery, the note is simply
 * imported and shown to the TD in the roster editor and the Round-entry
 * Byes area. Same carry-through pattern as photo_id/family_key: only the
 * one-click/event import path and roster-editor edits populate it, a CSV
 * import leaves it empty (the pairing-export CSV has no notes column).
 *
 * wpmtm_tournaments.affiliate_id (added 0.1.12, docs/SPEC.md, "Decisions
 * (2026-07-18, per-tournament USCF affiliate ID)"): a per-tournament
 * override of the club-wide affiliate ID stored in wpmtm_options, for a
 * shared install running an event on behalf of a different club. Same
 * "own value, Settings fallback" pattern the DBF export already applies to
 * city/state/zipcode (WPMTM_Export_Builder::first_nonblank()) - unlike
 * head_td_id/assistant_td_id, which deliberately have NO Settings fallback
 * (docs/SPEC.md, "Decisions (2026-07-17, TD default removal)"), the
 * affiliate ID keeps its fallback because a blank per-tournament affiliate
 * is the normal case (most tournaments use the club's own affiliate) and
 * not, unlike a blank TD field, a deliberate "this tournament genuinely has
 * none" signal. Never autopopulated on the tournament form; a "Use
 * default" button copies the Settings value in on click, the same as the
 * TD ID fields.
 *
 * wpmtm_tournaments.created_by (added 0.1.13, rescoping the
 * 'wpmtm_tournament_manager' role to a TD's own tournaments): the WP user
 * id that created the tournament, set once at insert time
 * (WPMTM_Admin::handle_save_tournament()) and never changed afterward.
 * WPMTM_Roles::user_can_manage_tournament() is the sole reader: an
 * administrator (manage_options) always passes regardless of this column;
 * a dedicated-role TD passes only when it matches their own user id. NULL
 * for every tournament that existed before this column was added - treated
 * as "no recorded owner" and left manageable by any WPMTM_CAPABILITY user
 * (grandfathered in, since there is no reliable owner to backfill from: a
 * linked event's post_author is often the person who set up the whole
 * site, not the TD who runs the tournament).
 */
class WPMTM_Schema {

	/**
	 * Bump whenever the CREATE TABLE statements below change; maybe_upgrade()
	 * re-runs dbDelta when the stored option differs from this value.
	 */
	const DB_VERSION = '0.1.16';

	/** Allowed wpmtm_games.result values. */
	const GAME_RESULTS = array( 'W', 'B', 'D', 'FW', 'FB', 'FD' );

	/** Allowed wpmtm_byes.type values. */
	const BYE_TYPES = array( 'B', 'H', 'U' );

	/** Full table name for a short table key, e.g. 'tournaments' => '{prefix}wpmtm_tournaments'. */
	public static function table( $key ) {
		global $wpdb;
		return $wpdb->prefix . 'wpmtm_' . $key;
	}

	/** Activation hook target. */
	public static function activate() {
		self::maybe_upgrade();
		self::add_capability();
	}

	/** Runs dbDelta only when the stored db version differs from DB_VERSION. */
	public static function maybe_upgrade() {
		$installed = get_option( 'wpmtm_db_version', '' );
		if ( self::DB_VERSION === $installed ) {
			return;
		}
		self::create_tables();
		update_option( 'wpmtm_db_version', self::DB_VERSION, false );
	}

	public static function add_capability() {
		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( WPMTM_CAPABILITY ) ) {
			$role->add_cap( WPMTM_CAPABILITY );
		}
	}

	protected static function create_tables() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$tournaments = self::table( 'tournaments' );
		$sections    = self::table( 'sections' );
		$players     = self::table( 'players' );
		$games       = self::table( 'games' );
		$byes        = self::table( 'byes' );

		$sql = array();

		$sql[] = "CREATE TABLE {$tournaments} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_post_id bigint(20) unsigned DEFAULT NULL,
			name varchar(191) NOT NULL DEFAULT '',
			rated tinyint(1) unsigned NOT NULL DEFAULT 0,
			begin_date date DEFAULT NULL,
			end_date date DEFAULT NULL,
			city varchar(191) DEFAULT NULL,
			state varchar(2) DEFAULT NULL,
			zipcode varchar(10) DEFAULT NULL,
			country varchar(191) DEFAULT NULL,
			head_td_id varchar(8) DEFAULT NULL,
			assistant_td_id varchar(8) DEFAULT NULL,
			affiliate_id varchar(10) DEFAULT NULL,
			send_crosstable tinyint(1) unsigned NOT NULL DEFAULT 0,
			show_photos tinyint(1) unsigned NOT NULL DEFAULT 0,
			locked tinyint(1) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'setup',
			created_by bigint(20) unsigned DEFAULT NULL,
			created_at datetime DEFAULT NULL,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_post_id (event_post_id),
			KEY status (status),
			KEY created_by (created_by)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$sections} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			tournament_id bigint(20) unsigned NOT NULL,
			sec_num smallint(5) unsigned NOT NULL,
			sec_name varchar(191) NOT NULL DEFAULT '',
			r_system char(1) NOT NULL DEFAULT 'R',
			timectl varchar(40) NOT NULL DEFAULT '',
			trn_type char(1) NOT NULL DEFAULT 'S',
			tot_rnds smallint(5) unsigned NOT NULL DEFAULT 0,
			auto_rounds_hint smallint(5) unsigned DEFAULT NULL,
			cycles tinyint(3) unsigned NOT NULL DEFAULT 1,
			sch_lvl char(1) DEFAULT NULL,
			gr_prix char(1) NOT NULL DEFAULT 'N',
			gp_pts smallint(5) unsigned NOT NULL DEFAULT 0,
			fide char(1) NOT NULL DEFAULT 'N',
			rated tinyint(1) unsigned NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			UNIQUE KEY tournament_section (tournament_id,sec_num),
			KEY tournament_id (tournament_id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$players} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			section_id bigint(20) unsigned NOT NULL,
			pair_num smallint(5) unsigned NOT NULL,
			mem_id varchar(8) DEFAULT NULL,
			name varchar(191) NOT NULL DEFAULT '',
			state char(2) DEFAULT NULL,
			rating varchar(4) DEFAULT NULL,
			photo_id bigint(20) unsigned DEFAULT NULL,
			withdrawn_after_round smallint(5) unsigned DEFAULT NULL,
			family_name_first tinyint(1) unsigned NOT NULL DEFAULT 0,
			family_key varchar(191) NULL,
			rating_source varchar(20) DEFAULT NULL,
			rating_checked int(10) unsigned DEFAULT NULL,
			notes text NULL,
			attendee_id bigint(20) unsigned DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY section_pair (section_id,pair_num),
			KEY section_id (section_id),
			KEY attendee_id (attendee_id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$games} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			section_id bigint(20) unsigned NOT NULL,
			round smallint(5) unsigned NOT NULL,
			board smallint(5) unsigned NOT NULL DEFAULT 0,
			white_player_id bigint(20) unsigned NOT NULL,
			black_player_id bigint(20) unsigned NOT NULL,
			result varchar(2) NOT NULL DEFAULT '',
			saved_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY section_round_board (section_id,round,board),
			KEY section_round (section_id,round),
			KEY white_player_id (white_player_id),
			KEY black_player_id (black_player_id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$byes} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			player_id bigint(20) unsigned NOT NULL,
			round smallint(5) unsigned NOT NULL,
			type char(1) NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY player_round (player_id,round)
		) {$charset_collate};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}
}
