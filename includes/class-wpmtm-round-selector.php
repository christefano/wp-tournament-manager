<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure, WordPress-independent round-selector math shared by
 * WPMTM_Frontend_TD's round-entry panel: which round is selected by
 * default, which rounds the "Round: 1 2 3" selector should list, and how
 * to clamp a TD-suppliable round override to the same ceiling. Extracted
 * out of WPMTM_Frontend_TD (a WP-coupled class - $wpdb-backed repository
 * calls, current_user_can(), etc.) so this arithmetic can be unit-tested
 * directly by tests/run-tests.php's zero-WP runner, the same way
 * WPMTM_Round_Entry and WPMTM_Round_Token are.
 *
 * Bug fixed here (docs/SPEC.md, 2026-07-14): a section with every round
 * 1..tot_rnds already entered used to compute a default selected round of
 * max(rounds_with_results) + 1 - one past the last real round - and the
 * round selector then listed that phantom round too ("Round: 1 2 3 4" for
 * a 3-round section), with the phantom round's empty entry form
 * selected by default. A TD could accidentally record a round the USCF
 * export was never told about. See determine_selected_round() and
 * clamp_round_override() below.
 */
class WPMTM_Round_Selector {

	/**
	 * The highest round number a section can legitimately reach: its own
	 * tot_rnds, or any round number that already has results recorded
	 * beyond it (an anomaly - e.g. a TD reduced tot_rnds after already
	 * entering a round past it), whichever is larger. Shared by
	 * determine_selected_round() and clamp_round_override() so both always
	 * agree on the same ceiling; the anomaly case deliberately still
	 * allows reaching the out-of-range round, so that data stays visible
	 * and fixable instead of hidden behind a lower ceiling.
	 *
	 * @param int   $tot_rnds            Section's configured round count.
	 * @param int[] $rounds_with_results Round numbers that already have at
	 *                                   least one game or bye recorded.
	 * @return int
	 */
	public static function max_reachable_round( $tot_rnds, array $rounds_with_results ) {
		$tot_rnds = max( 1, (int) $tot_rnds );
		return $rounds_with_results ? max( $tot_rnds, max( $rounds_with_results ) ) : $tot_rnds;
	}

	/**
	 * Default selected round: the lowest round in 1..tot_rnds with no
	 * results yet, else the final real round - never one past it (the
	 * phantom-round bug this class's docblock describes). An anomalous
	 * result recorded beyond tot_rnds still selects that round rather than
	 * being clamped away, matching max_reachable_round()'s own rationale.
	 *
	 * @param int   $tot_rnds
	 * @param int[] $rounds_with_results
	 * @return int
	 */
	public static function determine_selected_round( $tot_rnds, array $rounds_with_results ) {
		$tot_rnds = max( 1, (int) $tot_rnds );
		for ( $r = 1; $r <= $tot_rnds; $r++ ) {
			if ( ! in_array( $r, $rounds_with_results, true ) ) {
				return $r;
			}
		}
		return min(
			$rounds_with_results ? ( max( $rounds_with_results ) + 1 ) : 1,
			self::max_reachable_round( $tot_rnds, $rounds_with_results )
		);
	}

	/**
	 * The full list of round numbers (1..N) the "Round:" selector should
	 * list/link, given which round ended up selected (which may itself
	 * come from a clamped $_GET override - see clamp_round_override()).
	 *
	 * @param int   $tot_rnds
	 * @param int[] $rounds_with_results
	 * @param int   $selected_round
	 * @return int[]
	 */
	public static function display_rounds( $tot_rnds, array $rounds_with_results, $selected_round ) {
		$max_known = max( self::max_reachable_round( $tot_rnds, $rounds_with_results ), (int) $selected_round );
		return range( 1, $max_known );
	}

	/**
	 * Clamps a TD-suppliable round override (the wpmtm_round_{section_id}
	 * $_GET param) to the same ceiling determine_selected_round() itself
	 * never exceeds, so a hand-edited URL cannot open an entry form for a
	 * round past the real maximum.
	 *
	 * @param int   $requested_round
	 * @param int   $tot_rnds
	 * @param int[] $rounds_with_results
	 * @return int
	 */
	public static function clamp_round_override( $requested_round, $tot_rnds, array $rounds_with_results ) {
		$requested_round = max( 1, (int) $requested_round );
		return min( $requested_round, self::max_reachable_round( $tot_rnds, $rounds_with_results ) );
	}
}
