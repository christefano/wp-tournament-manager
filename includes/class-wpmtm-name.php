<?php
/**
 * Pure formatter for a player name stored "LAST,FIRST" (FIRST may carry
 * middle names, e.g. "CAPABLANCA,JOSE RAUL") into a naturally cased
 * "First Last" display string, e.g. "Jose Raul Capablanca". Zero WordPress
 * calls - like WPMTM_Round_Token / WPMTM_Scoring / WPMTM_Pairing_Aid, this
 * is a pure class exercised directly by tests/run-tests.php (see
 * tests/name-tests.php), not through a WordPress test harness.
 *
 * Display-only. The stored "LAST,FIRST" form itself, WPMTM_Export_Builder,
 * and WPMTM_USCF_Validator never call this class and are never touched by
 * it - every front-end renderer that shows a player name to a human calls
 * WPMTM_Name::display() at the point where it echoes that name, not in the
 * data layer (WPMTM_Frontend_Public::map_players() and friends) that feeds
 * WPMTM_Scoring / WPMTM_Pairing_Aid / the export builder, so those pure
 * classes and the DBF export keep seeing the raw stored value untouched.
 */
class WPMTM_Name {

	/**
	 * @param string $stored       Stored name, "LAST,FIRST" (or a bare name
	 *                              with no comma, e.g. a club/team placeholder).
	 * @param bool   $family_first Per-player display preference (the
	 *                              wpmtm_players.family_name_first column,
	 *                              DB_VERSION 0.1.7): false (default) returns
	 *                              the natural "First Last" order this class
	 *                              has always returned; true returns family
	 *                              name first instead, "Last First" (e.g.
	 *                              "HOU,YIFAN" -> "Hou Yifan"), the
	 *                              convention some players (and some
	 *                              cultures) prefer. Display-only, like the
	 *                              rest of this class - never changes the
	 *                              stored "LAST,FIRST" value or the export.
	 * @return string Naturally cased display name, or '' for '' input.
	 */
	public static function display( $stored, $family_first = false ) {
		$stored = trim( (string) $stored );
		if ( '' === $stored ) {
			return '';
		}

		if ( false === strpos( $stored, ',' ) ) {
			return self::title_case( $stored );
		}

		// Split on the FIRST comma only - a middle name/suffix containing a
		// second comma (rare, but not impossible in a hand-corrected roster)
		// stays attached to the first-name part rather than being dropped.
		list( $last, $first ) = explode( ',', $stored, 2 );

		$first = self::title_case( trim( $first ) );
		$last  = self::title_case( trim( $last ) );

		if ( '' === $first ) {
			return $last;
		}
		if ( '' === $last ) {
			return $first;
		}
		return $family_first ? ( $last . ' ' . $first ) : ( $first . ' ' . $last );
	}

	/**
	 * Title-cases a string UTF-8 aware (so "MULLER,HANS" -> "Muller" /
	 * "Hans" and "MÜLLER,HANS" -> "Müller" / "Hans" both work) and collapses
	 * any run of internal whitespace to a single space first, so a stray
	 * double space in the stored data does not survive into the display
	 * string.
	 */
	protected static function title_case( $value ) {
		$value = trim( preg_replace( '/\s+/', ' ', $value ) );
		if ( '' === $value ) {
			return '';
		}
		return mb_convert_case( $value, MB_CASE_TITLE, 'UTF-8' );
	}
}
