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
 * round-tokens are derived from it via RESULT_TOKEN_MAP, so reciprocity
 * errors are impossible by construction. WPMTM_USCF_Validator remains the
 * export-time backstop. Byes are per-player, stored in wpmtm_byes.
 */
class WPMTM_Schema {

	/**
	 * Bump whenever the CREATE TABLE statements below change; maybe_upgrade()
	 * re-runs dbDelta when the stored option differs from this value.
	 */
	const DB_VERSION = '0.1.7';

	/** Allowed wpmtm_games.result values. */
	const GAME_RESULTS = array( 'W', 'B', 'D', 'FW', 'FB', 'FD' );

	/** Allowed wpmtm_byes.type values. */
	const BYE_TYPES = array( 'B', 'H', 'U' );

	/**
	 * Maps a wpmtm_games.result value to the round-token components
	 * (WPMTM_Round_Token::encode() args) each side gets:
	 *
	 * - W  = White won: White gets token result 'W', Black gets 'L'.
	 * - B  = Black won: White gets 'L', Black gets 'W'.
	 * - D  = Draw: both sides get 'D'.
	 * - FW = White wins by forfeit: White gets 'X', Black gets 'F' (no color).
	 * - FB = Black wins by forfeit: White gets 'F', Black gets 'X' (no color).
	 * - FD = Double forfeit draw: both sides get 'Z' (no color).
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
			send_crosstable tinyint(1) unsigned NOT NULL DEFAULT 0,
			show_photos tinyint(1) unsigned NOT NULL DEFAULT 0,
			locked tinyint(1) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'setup',
			created_at datetime DEFAULT NULL,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_post_id (event_post_id),
			KEY status (status)
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
			PRIMARY KEY  (id),
			UNIQUE KEY section_pair (section_id,pair_num),
			KEY section_id (section_id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$games} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			section_id bigint(20) unsigned NOT NULL,
			round smallint(5) unsigned NOT NULL,
			board smallint(5) unsigned NOT NULL DEFAULT 0,
			white_player_id bigint(20) unsigned NOT NULL,
			black_player_id bigint(20) unsigned NOT NULL,
			result varchar(2) NOT NULL DEFAULT '',
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
