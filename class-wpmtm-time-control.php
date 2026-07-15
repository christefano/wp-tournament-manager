<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure, WordPress-independent time-control classifier. Parses a canonical
 * USCF time-control string (docs/SPEC.md, "Time control handling") and
 * derives the USCF rating-system band (blitz/quick/regular) from the total
 * base minutes plus any trailing delay/increment seconds.
 *
 * This is the single source of truth for that parsing/banding logic:
 * WPMTM_USCF_Validator::check_rating_system_vs_timectl() and
 * WPMTM_Plugin::derive_r_system() both call classify() instead of each
 * keeping their own copy.
 */
class WPMTM_Time_Control {

	/**
	 * Classifies a canonical time control string, e.g. 'G/30;d0',
	 * '40/90;SD/30;+30'. Sums base minutes across ';'-separated 'X/nn'
	 * controls; a trailing 'dN' or '+N' segment sets a delay/increment in
	 * seconds. Bands: 5-10 total = blitz (B), 11-29 = quick (Q), 30+ =
	 * regular (R). A total under 5 is reported separately as
	 * 'below_blitz_minimum' rather than folded into 'unparseable', since
	 * the string DID parse - it just describes an impossibly short game.
	 *
	 * Tolerant of a '/' typed in place of the canonical ';' immediately
	 * before a delay/increment segment (e.g. 'G/5/d0', 'G/30/d5',
	 * 'G/25/+5') - a common TD typo, since the base control itself already
	 * uses '/' ('G/30'). These parse identically to their semicolon
	 * equivalents ('G/5;d0', 'G/30;d5', 'G/25;+5'); docs/SPEC.md's
	 * canonical notation is unchanged and stored values are never rewritten
	 * - this only widens what classify() itself accepts as input.
	 *
	 * @param string $timectl Canonical USCF time control string (or the
	 *                        '/'-before-delay tolerant variant above).
	 * @return array{system:?string, reason:string, total:?int}
	 *   system: 'B'|'Q'|'R' on success, null otherwise.
	 *   reason: 'ok'|'unparseable'|'below_blitz_minimum'.
	 *   total:  summed minutes + seconds; null when unparseable (no time
	 *           part found, or a segment could not be parsed at all).
	 */
	public static function classify( $timectl ) {
		$minutes    = 0;
		$seconds    = 0;
		$found_time = false;

		// Normalize a '/' used in place of ';' directly before a delay
		// ('dN') or increment ('+N') segment into the canonical ';'
		// separator, so the rest of this method never has to know the
		// tolerant form exists. A plain base control's own '/' (e.g. the
		// '/90' in '40/90') never matches this, since what follows it is
		// bare digits, not 'd' or '+' followed by digits.
		$timectl = preg_replace( '/\/(d\d+|\+\d+)/i', ';$1', (string) $timectl );

		foreach ( explode( ';', (string) $timectl ) as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}
			if ( preg_match( '/^(?:[A-Za-z]+|\d+)\/(\d+)$/', $part, $m ) ) {
				$minutes   += (int) $m[1];
				$found_time = true;
			} elseif ( preg_match( '/^d(\d+)$/i', $part, $m ) ) {
				$seconds = (int) $m[1];
			} elseif ( preg_match( '/^\+(\d+)$/', $part, $m ) ) {
				$seconds = (int) $m[1];
			} else {
				return array(
					'system' => null,
					'reason' => 'unparseable',
					'total'  => null,
				);
			}
		}

		if ( ! $found_time ) {
			return array(
				'system' => null,
				'reason' => 'unparseable',
				'total'  => null,
			);
		}

		$total = $minutes + $seconds;

		if ( $total < 5 ) {
			return array(
				'system' => null,
				'reason' => 'below_blitz_minimum',
				'total'  => $total,
			);
		}

		if ( $total <= 10 ) {
			$system = 'B';
		} elseif ( $total <= 29 ) {
			$system = 'Q';
		} else {
			$system = 'R';
		}

		return array(
			'system' => $system,
			'reason' => 'ok',
			'total'  => $total,
		);
	}
}
