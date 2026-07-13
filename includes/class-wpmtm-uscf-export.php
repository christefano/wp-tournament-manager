<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WPMTM_USCF_PROGRAM_ID' ) ) {
	// USCF's importer keys off this literal value in H_PROGRAM for
	// compatibility. It is a required data value, not a project name -
	// see docs/SPEC.md "H_PROGRAM resolved".
	define( 'WPMTM_USCF_PROGRAM_ID', 'SWISSSYS11' );
}

require_once __DIR__ . '/class-wpmtm-dbf-writer.php';
require_once __DIR__ . '/class-wpmtm-round-token.php';

/**
 * Builds the three USCF rating-report DBF files (THEXPORT/TSEXPORT/TDEXPORT)
 * from structured tournament data. Field lists and widths per
 * docs/SPEC.md, verified against tests/fixtures/*.DBF.
 *
 * Expected input shape (all keys optional unless noted; missing values
 * become blank/space-padded fields):
 *
 *   array(
 *     'update_date'      => array( 'year' => 2025, 'month' => 5, 'day' => 16 ),
 *     'format'            => '2C',
 *     'event_name'        => string,
 *     'begin_date'        => 'YYYYMMDD',
 *     'end_date'          => 'YYYYMMDD',
 *     'affiliate_id'      => 'Axxxxxxx',
 *     'city' | 'state' | 'zipcode' | 'country' => string,
 *     'send_crosstable'   => 'Y'|'N',
 *     'chief_td_id'       => string,
 *     'assistant_td_id'   => string,
 *     'other_tds'         => string,
 *     'sections'          => array(
 *       array(
 *         'sec_num'          => int (required),
 *         'sec_name'         => string,
 *         'r_system'         => 'R'|'Q'|'B',
 *         'timectl'          => string,
 *         'chief_td_id'      => string (defaults to top-level chief_td_id),
 *         'assistant_td_id'  => string (defaults to top-level assistant_td_id),
 *         'trn_type'         => 'S' (default; WPMTM_Export_Builder always
 *                               sends 'S' here, even for round-robin
 *                               sections - see its build_section() docblock),
 *         'tot_rnds'         => int (required),
 *         'lst_pair'         => int (defaults to count(players)),
 *         'begin_date' | 'end_date' | 'sch_lvl' | 'gr_prix' | 'gp_pts' | 'fide',
 *         'players'          => array(
 *           array(
 *             'pair_num' => int (required),
 *             'mem_id'   => string,
 *             'name'     => string ('LAST,FIRST MIDDLE'; hard-truncated at 30),
 *             'state'    => string,
 *             'rating'   => string,
 *             'rounds'   => array of either:
 *               - a pre-encoded 7-char token string, or
 *               - array( 'result' => , 'opponent' => , 'color' => ) run
 *                 through WPMTM_Round_Token::encode().
 *           ),
 *         ),
 *       ),
 *     ),
 *   )
 */
class WPMTM_USCF_Export {

	const MAX_ROUND_COLS = 10;
	const NAME_MAX_LEN   = 30;

	protected $data;

	public function __construct( array $data ) {
		$this->data = $data;
	}

	protected function update_date() {
		if ( isset( $this->data['update_date'] ) ) {
			return $this->data['update_date'];
		}
		return array(
			'year'  => (int) date( 'Y' ),
			'month' => (int) date( 'n' ),
			'day'   => (int) date( 'j' ),
		);
	}

	protected function sections() {
		return isset( $this->data['sections'] ) ? $this->data['sections'] : array();
	}

	// ---- THEXPORT (event header, one record) ----

	protected function header_fields() {
		return array(
			array( 'name' => 'H_FORMAT', 'length' => 5 ),
			array( 'name' => 'H_PROGRAM', 'length' => 10 ),
			array( 'name' => 'H_EVENT_ID', 'length' => 12 ),
			array( 'name' => 'H_NAME', 'length' => 35 ),
			array( 'name' => 'H_TOT_SECT', 'length' => 2 ),
			array( 'name' => 'H_BEG_DATE', 'length' => 8 ),
			array( 'name' => 'H_END_DATE', 'length' => 8 ),
			array( 'name' => 'H_AFF_ID', 'length' => 8 ),
			array( 'name' => 'H_CITY', 'length' => 21 ),
			array( 'name' => 'H_STATE', 'length' => 2 ),
			array( 'name' => 'H_ZIPCODE', 'length' => 10 ),
			array( 'name' => 'H_COUNTRY', 'length' => 21 ),
			array( 'name' => 'H_SENDCROS', 'length' => 1 ),
			array( 'name' => 'H_CTD_ID', 'length' => 8 ),
			array( 'name' => 'H_ATD_ID', 'length' => 8 ),
			array( 'name' => 'H_OTHER_TD', 'length' => 254 ),
		);
	}

	public function build_header_bytes() {
		$d = $this->data;

		$record = array(
			'H_FORMAT'   => isset( $d['format'] ) ? $d['format'] : '2C',
			'H_PROGRAM'  => WPMTM_USCF_PROGRAM_ID,
			'H_EVENT_ID' => '', // USCF assigns on submission
			'H_NAME'     => isset( $d['event_name'] ) ? $d['event_name'] : '',
			'H_TOT_SECT' => (string) count( $this->sections() ),
			'H_BEG_DATE' => isset( $d['begin_date'] ) ? $d['begin_date'] : '',
			'H_END_DATE' => isset( $d['end_date'] ) ? $d['end_date'] : '',
			'H_AFF_ID'   => isset( $d['affiliate_id'] ) ? $d['affiliate_id'] : '',
			'H_CITY'     => isset( $d['city'] ) ? $d['city'] : '',
			'H_STATE'    => isset( $d['state'] ) ? $d['state'] : '',
			'H_ZIPCODE'  => isset( $d['zipcode'] ) ? $d['zipcode'] : '',
			'H_COUNTRY'  => isset( $d['country'] ) ? $d['country'] : 'USA',
			'H_SENDCROS' => isset( $d['send_crosstable'] ) ? $d['send_crosstable'] : 'N',
			'H_CTD_ID'   => isset( $d['chief_td_id'] ) ? $d['chief_td_id'] : '',
			'H_ATD_ID'   => isset( $d['assistant_td_id'] ) ? $d['assistant_td_id'] : '',
			'H_OTHER_TD' => isset( $d['other_tds'] ) ? $d['other_tds'] : '',
		);

		$writer = new WPMTM_DBF_Writer( $this->header_fields(), array( $record ), $this->update_date() );
		return $writer->build();
	}

	// ---- TSEXPORT (one record per section) ----

	protected function section_fields() {
		return array(
			array( 'name' => 'S_EVENT_ID', 'length' => 12 ),
			array( 'name' => 'S_SEC_NUM', 'length' => 2 ),
			array( 'name' => 'S_SEC_NAME', 'length' => 30 ),
			array( 'name' => 'S_R_SYSTEM', 'length' => 1 ),
			array( 'name' => 'S_TIMECTL', 'length' => 40 ),
			array( 'name' => 'S_CTD_ID', 'length' => 8 ),
			array( 'name' => 'S_ATD_ID', 'length' => 8 ),
			array( 'name' => 'S_TRN_TYPE', 'length' => 1 ),
			array( 'name' => 'S_TOT_RNDS', 'length' => 2 ),
			array( 'name' => 'S_LST_PAIR', 'length' => 4 ),
			array( 'name' => 'S_BEG_DATE', 'length' => 8 ),
			array( 'name' => 'S_END_DATE', 'length' => 8 ),
			array( 'name' => 'S_SCH_LVL', 'length' => 1 ),
			array( 'name' => 'S_GR_PRIX', 'length' => 1 ),
			array( 'name' => 'S_GP_PTS', 'length' => 3 ),
			array( 'name' => 'S_FIDE', 'length' => 1 ),
		);
	}

	public function build_section_bytes() {
		$d       = $this->data;
		$records = array();

		foreach ( $this->sections() as $s ) {
			$records[] = array(
				'S_EVENT_ID' => '', // USCF assigns
				'S_SEC_NUM'  => (string) $s['sec_num'],
				'S_SEC_NAME' => isset( $s['sec_name'] ) ? $s['sec_name'] : '',
				'S_R_SYSTEM' => isset( $s['r_system'] ) ? $s['r_system'] : '',
				'S_TIMECTL'  => isset( $s['timectl'] ) ? $s['timectl'] : '',
				'S_CTD_ID'   => isset( $s['chief_td_id'] ) ? $s['chief_td_id'] : ( isset( $d['chief_td_id'] ) ? $d['chief_td_id'] : '' ),
				'S_ATD_ID'   => isset( $s['assistant_td_id'] ) ? $s['assistant_td_id'] : ( isset( $d['assistant_td_id'] ) ? $d['assistant_td_id'] : '' ),
				'S_TRN_TYPE' => isset( $s['trn_type'] ) ? $s['trn_type'] : 'S',
				'S_TOT_RNDS' => (string) $s['tot_rnds'],
				'S_LST_PAIR' => (string) ( isset( $s['lst_pair'] ) ? $s['lst_pair'] : count( isset( $s['players'] ) ? $s['players'] : array() ) ),
				'S_BEG_DATE' => isset( $s['begin_date'] ) ? $s['begin_date'] : '',
				'S_END_DATE' => isset( $s['end_date'] ) ? $s['end_date'] : '',
				'S_SCH_LVL'  => isset( $s['sch_lvl'] ) ? $s['sch_lvl'] : '',
				'S_GR_PRIX'  => isset( $s['gr_prix'] ) ? $s['gr_prix'] : 'N',
				'S_GP_PTS'   => isset( $s['gp_pts'] ) ? $s['gp_pts'] : '',
				'S_FIDE'     => isset( $s['fide'] ) ? $s['fide'] : 'N',
			);
		}

		$writer = new WPMTM_DBF_Writer( $this->section_fields(), $records, $this->update_date() );
		return $writer->build();
	}

	// ---- TDEXPORT (one or more records per player) ----

	protected function detail_fixed_fields() {
		return array(
			array( 'name' => 'D_EVENT_ID', 'length' => 12 ),
			array( 'name' => 'D_SEC_NUM', 'length' => 2 ),
			array( 'name' => 'D_PAIR_NUM', 'length' => 4 ),
			array( 'name' => 'D_MEM_ID', 'length' => 8 ),
			array( 'name' => 'D_NAME', 'length' => 30 ),
			array( 'name' => 'D_STATE', 'length' => 2 ),
			array( 'name' => 'D_RATING', 'length' => 4 ),
		);
	}

	/**
	 * Round-column count for the detail file: the widest section's round
	 * count, capped at MAX_ROUND_COLS. Players with more rounds than this
	 * spill into continuation records that reuse the same D_RNDnn columns
	 * for the next chunk of rounds.
	 */
	protected function round_column_count() {
		$max = 0;
		foreach ( $this->sections() as $s ) {
			$max = max( $max, (int) $s['tot_rnds'] );
		}
		return max( 1, min( $max, self::MAX_ROUND_COLS ) );
	}

	protected function detail_fields( $round_cols ) {
		$fields = $this->detail_fixed_fields();
		for ( $i = 1; $i <= $round_cols; $i++ ) {
			$fields[] = array(
				'name'   => 'D_RND' . str_pad( (string) $i, 2, '0', STR_PAD_LEFT ),
				'length' => 7,
			);
		}
		return $fields;
	}

	/**
	 * Normalizes one round entry to a 7-char token. Accepts an already
	 * -encoded string (space-padded to <=7) or a
	 * ['result'=>,'opponent'=>,'color'=>] triple run through
	 * WPMTM_Round_Token::encode().
	 */
	protected function normalize_token( $round ) {
		if ( is_array( $round ) ) {
			return WPMTM_Round_Token::encode(
				$round['result'],
				isset( $round['opponent'] ) ? $round['opponent'] : 0,
				isset( $round['color'] ) ? $round['color'] : null
			);
		}
		return str_pad( (string) $round, WPMTM_Round_Token::WIDTH, ' ', STR_PAD_RIGHT );
	}

	public function build_detail_bytes() {
		$round_cols = $this->round_column_count();
		$fields     = $this->detail_fields( $round_cols );
		$records    = array();

		foreach ( $this->sections() as $s ) {
			foreach ( ( isset( $s['players'] ) ? $s['players'] : array() ) as $p ) {
				$name = isset( $p['name'] ) ? $p['name'] : '';
				// D_NAME hard-truncates at 30 per spec - other fields
				// reject on overlength instead (see WPMTM_DBF_Writer).
				if ( strlen( $name ) > self::NAME_MAX_LEN ) {
					$name = substr( $name, 0, self::NAME_MAX_LEN );
				}

				$tokens = array();
				foreach ( ( isset( $p['rounds'] ) ? $p['rounds'] : array() ) as $r ) {
					$tokens[] = $this->normalize_token( $r );
				}

				$chunks = $tokens ? array_chunk( $tokens, $round_cols ) : array( array() );

				foreach ( $chunks as $chunk ) {
					$record = array(
						'D_EVENT_ID' => '',
						'D_SEC_NUM'  => (string) $s['sec_num'],
						'D_PAIR_NUM' => (string) $p['pair_num'],
						'D_MEM_ID'   => isset( $p['mem_id'] ) ? $p['mem_id'] : '',
						'D_NAME'     => $name,
						'D_STATE'    => isset( $p['state'] ) ? $p['state'] : '',
						'D_RATING'   => isset( $p['rating'] ) ? $p['rating'] : '',
					);
					for ( $i = 1; $i <= $round_cols; $i++ ) {
						$field_name             = 'D_RND' . str_pad( (string) $i, 2, '0', STR_PAD_LEFT );
						$record[ $field_name ] = isset( $chunk[ $i - 1 ] ) ? $chunk[ $i - 1 ] : '';
					}
					$records[] = $record;
				}
			}
		}

		$writer = new WPMTM_DBF_Writer( $fields, $records, $this->update_date() );
		return $writer->build();
	}

	// ---- convenience ----

	public function export_all() {
		return array(
			'THEXPORT' => $this->build_header_bytes(),
			'TSEXPORT' => $this->build_section_bytes(),
			'TDEXPORT' => $this->build_detail_bytes(),
		);
	}

	public function write_files( $dir ) {
		$files = $this->export_all();
		foreach ( $files as $name => $bytes ) {
			$path = rtrim( $dir, '/' ) . '/' . $name . '.DBF';
			if ( false === file_put_contents( $path, $bytes ) ) {
				throw new RuntimeException( 'failed to write ' . $path );
			}
		}
		return $files;
	}
}
