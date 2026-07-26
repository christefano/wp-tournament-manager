<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds a single flat CSV of a tournament's results from every section -
 * rated and unrated alike (2026-07-24, "Tournament Export" section). Unlike
 * WPMTM_USCF_Export, this is not a USCF submission format: one row per
 * player per round, in the same round-token vocabulary the rest of the
 * plugin already uses (WPMTM_Scoring::RESULT_TOKEN_MAP / round-token
 * legend: W/L/D/X/F/Z results, B/H/U byes, W/B colors) rather than invented
 * English labels, since a TD reading this file already reads that
 * vocabulary everywhere else in Tournament Manager. Consumes the same
 * structured shape WPMTM_Export_Builder::build_for_csv() produces.
 */
class WPMTM_CSV_Export {

	const HEADER_ROW = array(
		'Section #',
		'Section Name',
		'Rated',
		'Pairing #',
		'Player Name',
		'USCF ID',
		'Rating',
		'Round',
		'Result',
		'Color',
		'Opponent Pairing #',
		'Opponent Name',
	);

	/**
	 * @param array $data Structured tournament data, same shape
	 *                     WPMTM_USCF_Export/WPMTM_USCF_Validator consume
	 *                     (see class-wpmtm-uscf-export.php's docblock),
	 *                     built by WPMTM_Export_Builder::build_for_csv().
	 * @return string The complete CSV file content. LF line endings -
	 *                 fputcsv()'s CRLF $eol param needs PHP 8.1, below this
	 *                 plugin's PHP 7.4 floor (README.md Requirements), and
	 *                 plain LF is valid CSV every spreadsheet app reads fine.
	 */
	public static function build( array $data ) {
		$rows   = array( self::HEADER_ROW );
		$sections = isset( $data['sections'] ) ? $data['sections'] : array();

		foreach ( $sections as $section ) {
			$rows = array_merge( $rows, self::section_rows( $section ) );
		}

		return self::rows_to_csv( $rows );
	}

	protected static function section_rows( array $section ) {
		$sec_num  = isset( $section['sec_num'] ) ? (string) $section['sec_num'] : '';
		$sec_name = isset( $section['sec_name'] ) ? $section['sec_name'] : '';
		$rated    = isset( $section['rated'] ) ? $section['rated'] : 'N';
		$tot_rnds = isset( $section['tot_rnds'] ) ? (int) $section['tot_rnds'] : 0;
		$players  = isset( $section['players'] ) ? $section['players'] : array();

		$name_by_pair = array();
		foreach ( $players as $player ) {
			if ( isset( $player['pair_num'] ) ) {
				$name_by_pair[ (int) $player['pair_num'] ] = isset( $player['name'] ) ? $player['name'] : '';
			}
		}

		$rows = array();
		foreach ( $players as $player ) {
			$pair_num = isset( $player['pair_num'] ) ? (int) $player['pair_num'] : 0;
			$rounds   = isset( $player['rounds'] ) ? $player['rounds'] : array();

			for ( $round = 1; $round <= $tot_rnds; $round++ ) {
				$entry       = isset( $rounds[ $round - 1 ] ) ? $rounds[ $round - 1 ] : array();
				$opponent    = isset( $entry['opponent'] ) ? (int) $entry['opponent'] : 0;
				$opponent_nm = ( $opponent && isset( $name_by_pair[ $opponent ] ) ) ? $name_by_pair[ $opponent ] : '';

				$rows[] = array(
					$sec_num,
					$sec_name,
					$rated,
					(string) $pair_num,
					isset( $player['name'] ) ? $player['name'] : '',
					isset( $player['mem_id'] ) ? $player['mem_id'] : '',
					isset( $player['rating'] ) ? $player['rating'] : '',
					(string) $round,
					isset( $entry['result'] ) ? $entry['result'] : '',
					isset( $entry['color'] ) ? $entry['color'] : '',
					$opponent ? (string) $opponent : '',
					$opponent_nm,
				);
			}
		}

		return $rows;
	}

	/**
	 * fputcsv() against an in-memory stream, not hand-rolled escaping - the
	 * same reasoning as WPMTM_DBF_Writer avoiding hand-rolled binary
	 * packing: a player or section name containing a comma, quote, or
	 * newline (all legal in either field) is exactly the input a manual
	 * implode(',', ...) gets wrong.
	 */
	protected static function rows_to_csv( array $rows ) {
		$stream = fopen( 'php://temp', 'r+' );
		foreach ( $rows as $row ) {
			fputcsv( $stream, $row );
		}
		rewind( $stream );
		$csv = stream_get_contents( $stream );
		fclose( $stream );
		return $csv;
	}
}
