<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * On-demand USCF status validation against the USCF Ratings API (MUIR v1,
 * https://ratings-api.uschess.org/api/v1) - docs/SPEC.md, "Decisions
 * (2026-07-14, USCF status validation)". Checks, ahead of a ratings upload,
 * that the club affiliate, the TDs, and every player have active USCF
 * status, so the TD / Affiliate portal does not bounce the submission.
 * Advisory only: nothing in Tournament Manager ever blocks on a result
 * from this class (docs/TD-PERSONA.md).
 *
 * Two layers, deliberately kept separate, the same split WPMTM_ETR_Import
 * uses:
 *
 * - evaluate_member(), evaluate_td(), and evaluate_affiliate() are pure /
 *   static / WordPress-independent verdict logic operating on a decoded
 *   API payload array (or null for "not found"), so tests/run-tests.php
 *   can cover PASS/FAIL/edge cases against fixture arrays without HTTP or
 *   WordPress. Their reason strings are plain English, matching the other
 *   pure classes (WPMTM_USCF_Validator, WPMTM_ETR_Import's parse layer);
 *   the WP layer's own strings (labels, summaries, errors) are translated.
 * - get_member() / get_affiliate() (thin wp_remote_get wrappers with a
 *   1-day transient cache), the validate_*() combinators, and the two
 *   admin-ajax handlers are the WordPress layer, live-verified rather than
 *   unit-tested.
 */
class WPMTM_USCF_Status {

	const API_BASE = 'https://ratings-api.uschess.org/api/v1';

	/**
	 * Transient lifetime for cached API responses, in seconds. A full day
	 * on purpose (docs/SPEC.md, "Decisions (2026-07-16, USCF API traffic
	 * reduction)"): membership, TD certification, Safe Play, and affiliate
	 * data change rarely, and the MUIR API v1 is officially unsupported by
	 * USCF, so Tournament Manager deliberately keeps its call volume
	 * low. The three human-initiated paths that need a fresher answer than
	 * a day-old cache can give (ajax_validate_players(), ajax_validate_tds(),
	 * and the DBF export gate) pass $force = true to bypass this cache
	 * outright rather than shortening it for everyone.
	 */
	const CACHE_TTL = DAY_IN_SECONDS;

	/**
	 * Days past the through-date inside which a passing expiration still
	 * earns a "renew soon" WARN note on the verdict.
	 */
	const WARN_WINDOW_DAYS = 30;

	private static $instance = null;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_wpmtm_validate_players', array( $this, 'ajax_validate_players' ) );
		add_action( 'wp_ajax_wpmtm_validate_tds', array( $this, 'ajax_validate_tds' ) );
		// The "Validate players" button lives in wp-etr's Registrations-tab
		// toolbar on the single-event page, which can render before any
		// tournament is linked - so the front-end script (and this class's
		// localized data) must load whenever a capable user views an event
		// page, not only when WPMTM_Frontend resolves a tournament.
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_frontend' ) );
	}

	// -----------------------------------------------------------------
	// Pure sanitize layer (unit-tested).
	// -----------------------------------------------------------------

	/**
	 * A USCF member ID is digits only (8 digits typical). Returns the
	 * cleaned ID, or '' when the value is junk that should never reach the
	 * API. Pure and WP-independent.
	 *
	 * @param string $id Raw member ID.
	 * @return string
	 */
	public static function sanitize_member_id( $id ) {
		$id = trim( (string) $id );
		return preg_match( '/^\d{1,10}$/', $id ) ? $id : '';
	}

	/**
	 * Normalizes a REGISTRANT-ENTERED USCF member-ID value that may be a
	 * bare ID or a pasted USCF profile URL (docs/SPEC.md, "Decisions
	 * (2026-07-16, URL-form USCF IDs)"). ETECF's own field help text tells
	 * registrants they may enter either: "Enter your USCF member ID or the
	 * full URL to your USCF profile at ratings.uschess.org." Before this,
	 * a pasted URL sanitized to '' via sanitize_member_id() and every
	 * downstream check silently skipped it.
	 *
	 * Deliberately narrow: this recognizes only a bare digit string or a
	 * ratings.uschess.org/player/{id} URL (with or without an http(s)
	 * scheme, with or without a leading www., an optional trailing slash,
	 * and an optional query string or #fragment after the id) - it never
	 * scrapes digits out of arbitrary text. "Need New ID" (no digits, no
	 * recognized URL) and an unrelated URL that happens to contain digits
	 * both return '', the same as before. Callers should still pass the
	 * result through sanitize_member_id() (normalize first, then
	 * sanitize) - sanitize_member_id() itself stays untouched, since it is
	 * also used for TD IDs, the affiliate check, and CLI args, none of
	 * which should ever accept a URL.
	 *
	 * @param string $raw Raw, registrant-entered value.
	 * @return string Digits-only member ID, or '' when nothing recognized.
	 */
	public static function normalize_member_id_input( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}

		if ( preg_match( '/^\d{1,10}$/', $raw ) ) {
			return $raw;
		}

		if ( preg_match( '~^(?:https?://)?(?:www\.)?ratings\.uschess\.org/player/(\d{1,10})(?:[/?#].*)?$~i', $raw, $matches ) ) {
			return $matches[1];
		}

		return '';
	}

	/**
	 * A USCF affiliate ID is a letter followed by digits (e.g. A1234567).
	 * Returns the uppercased ID, or '' when invalid. Pure and WP-independent.
	 *
	 * @param string $id Raw affiliate ID.
	 * @return string
	 */
	public static function sanitize_affiliate_id( $id ) {
		$id = strtoupper( trim( (string) $id ) );
		return preg_match( '/^[A-Z]\d+$/', $id ) ? $id : '';
	}

	// -----------------------------------------------------------------
	// Pure verdict layer (unit-tested).
	// -----------------------------------------------------------------

	/**
	 * Membership verdict for a GET /members/{id} payload.
	 *
	 * Rules (docs/SPEC.md): PASS when status is 'Active' AND the
	 * expiration date is null (life member) or on/after the through-date.
	 * Everything else FAILs with the status spelled out; a null payload
	 * (HTTP 404) FAILs as "USCF ID not found". A pass whose expiration
	 * falls within WARN_WINDOW_DAYS after the through-date carries a WARN
	 * note; PASS/FAIL is what matters.
	 *
	 * @param array|null $api          Decoded MemberDetailDto, or null for 404.
	 * @param string     $through_date "Must be active through" date,
	 *                                 YYYY-MM-DD; the tournament's last day.
	 *                                 Blank falls back to today.
	 * @return array {
	 *     verdict:    'PASS'|'FAIL',
	 *     reason:     string ('' on PASS),
	 *     warn:       string ('' when none),
	 *     name:       string,
	 *     status:     string,
	 *     expiration: string ('' for null/none),
	 * }
	 */
	public static function evaluate_member( $api, $through_date ) {
		$through = self::normalize_through_date( $through_date );
		$row     = array(
			'verdict'    => 'FAIL',
			'reason'     => '',
			'warn'       => '',
			'name'       => '',
			'status'     => '',
			'expiration' => '',
		);

		if ( null === $api ) {
			$row['reason'] = 'USCF ID not found';
			return $row;
		}

		$row['name']       = self::api_person_name( $api );
		$row['status']     = isset( $api['status'] ) ? (string) $api['status'] : '';
		$expiration        = self::normalize_date( isset( $api['expirationDate'] ) ? $api['expirationDate'] : null );
		$row['expiration'] = $expiration;

		if ( 'Active' !== $row['status'] ) {
			if ( 'None' === $row['status'] ) {
				$row['reason'] = 'Not a current USCF member';
			} elseif ( 'Expired' === $row['status'] ) {
				$row['reason'] = 'Expired' . ( '' !== $expiration ? ' ' . $expiration : '' );
			} elseif ( '' === $row['status'] ) {
				$row['reason'] = 'No membership status on file';
			} else {
				$row['reason'] = $row['status'];
			}
			return $row;
		}

		if ( '' !== $expiration && $expiration < $through ) {
			$row['reason'] = 'Membership expires ' . $expiration . ', before ' . $through;
			return $row;
		}

		$row['verdict'] = 'PASS';
		if ( '' !== $expiration && $expiration <= self::warn_ceiling( $through ) ) {
			$row['warn'] = 'Membership expires ' . $expiration . ' - renew soon';
		}
		return $row;
	}

	/**
	 * TD verdict: the membership check above, PLUS an Active TD
	 * certification valid through the through-date (null cert expiration =
	 * no expiry), PLUS a Safe Play certification that is on file AND valid
	 * through the through-date. A null tdLevel/tdCertStatus means "not a
	 * certified TD". Every failing component contributes to the reason
	 * (joined with '; ') so the TD sees all problems at once.
	 *
	 * @param array|null $api          Decoded MemberDetailDto, or null for 404.
	 * @param string     $through_date See evaluate_member().
	 * @return array evaluate_member()'s shape plus td_level,
	 *               td_cert_status, td_cert_expiration, and
	 *               safe_play_expiration keys.
	 */
	public static function evaluate_td( $api, $through_date ) {
		$through = self::normalize_through_date( $through_date );
		$row     = self::evaluate_member( $api, $through_date );

		$row['td_level']             = '';
		$row['td_cert_status']       = '';
		$row['td_cert_expiration']   = '';
		$row['safe_play_expiration'] = '';

		if ( null === $api ) {
			return $row;
		}

		$reasons = '' !== $row['reason'] ? array( $row['reason'] ) : array();
		$warns   = '' !== $row['warn'] ? array( $row['warn'] ) : array();

		$td_level    = isset( $api['tdLevel'] ) ? $api['tdLevel'] : null;
		$cert_status = isset( $api['tdCertStatus'] ) ? $api['tdCertStatus'] : null;
		$cert_exp    = self::normalize_date( isset( $api['tdCertExpirationDate'] ) ? $api['tdCertExpirationDate'] : null );
		$safe_exp    = self::normalize_date( isset( $api['safePlayExpirationDate'] ) ? $api['safePlayExpirationDate'] : null );

		$row['td_level']             = null !== $td_level ? (string) $td_level : '';
		$row['td_cert_status']       = null !== $cert_status ? (string) $cert_status : '';
		$row['td_cert_expiration']   = $cert_exp;
		$row['safe_play_expiration'] = $safe_exp;

		if ( null === $td_level || null === $cert_status ) {
			$reasons[] = 'Not a certified TD';
		} elseif ( 'Active' !== (string) $cert_status ) {
			$reasons[] = 'TD certification ' . strtolower( (string) $cert_status );
		} elseif ( '' !== $cert_exp && $cert_exp < $through ) {
			$reasons[] = 'TD certification expires ' . $cert_exp . ', before ' . $through;
		} elseif ( '' !== $cert_exp && $cert_exp <= self::warn_ceiling( $through ) ) {
			$warns[] = 'TD certification expires ' . $cert_exp . ' - renew soon';
		}

		if ( '' === $safe_exp ) {
			$reasons[] = 'No Safe Play certification on file';
		} elseif ( $safe_exp < $through ) {
			$reasons[] = 'Safe Play expired ' . $safe_exp;
		} elseif ( $safe_exp <= self::warn_ceiling( $through ) ) {
			$warns[] = 'Safe Play expires ' . $safe_exp . ' - renew soon';
		}

		$row['reason']  = implode( '; ', $reasons );
		$row['warn']    = implode( '; ', $warns );
		$row['verdict'] = empty( $reasons ) ? 'PASS' : 'FAIL';
		return $row;
	}

	/**
	 * Affiliate verdict for a GET /affiliates/{id} payload: PASS when
	 * status is 'Active' AND the expiration date is on/after the
	 * through-date. Unlike members, an affiliate has no life-member case,
	 * so a missing expiration date FAILs rather than passing.
	 *
	 * @param array|null $api          Decoded AffiliateDto, or null for 404.
	 * @param string     $through_date See evaluate_member().
	 * @return array evaluate_member()'s shape plus a state key.
	 */
	public static function evaluate_affiliate( $api, $through_date ) {
		$through = self::normalize_through_date( $through_date );
		$row     = array(
			'verdict'    => 'FAIL',
			'reason'     => '',
			'warn'       => '',
			'name'       => '',
			'status'     => '',
			'expiration' => '',
			'state'      => '',
		);

		if ( null === $api ) {
			$row['reason'] = 'Affiliate ID not found';
			return $row;
		}

		$row['name']       = isset( $api['name'] ) ? trim( (string) $api['name'] ) : '';
		$row['state']      = isset( $api['stateCode'] ) ? (string) $api['stateCode'] : '';
		$row['status']     = isset( $api['status'] ) ? (string) $api['status'] : '';
		$expiration        = self::normalize_date( isset( $api['expirationDate'] ) ? $api['expirationDate'] : null );
		$row['expiration'] = $expiration;

		if ( 'Active' !== $row['status'] ) {
			if ( 'None' === $row['status'] ) {
				$row['reason'] = 'Not a current USCF affiliate';
			} elseif ( 'Expired' === $row['status'] ) {
				$row['reason'] = 'Expired' . ( '' !== $expiration ? ' ' . $expiration : '' );
			} elseif ( '' === $row['status'] ) {
				$row['reason'] = 'No affiliate status on file';
			} else {
				$row['reason'] = $row['status'];
			}
			return $row;
		}

		if ( '' === $expiration ) {
			$row['reason'] = 'No expiration date on file';
			return $row;
		}

		if ( $expiration < $through ) {
			$row['reason'] = 'Affiliate membership expires ' . $expiration . ', before ' . $through;
			return $row;
		}

		$row['verdict'] = 'PASS';
		if ( $expiration <= self::warn_ceiling( $through ) ) {
			$row['warn'] = 'Affiliate membership expires ' . $expiration . ' - renew soon';
		}
		return $row;
	}

	// -----------------------------------------------------------------
	// Pure date helpers.
	// -----------------------------------------------------------------

	/** A blank through-date falls back to today (docs/SPEC.md rule). */
	protected static function normalize_through_date( $through_date ) {
		$through = self::normalize_date( $through_date );
		return '' !== $through ? $through : gmdate( 'Y-m-d' );
	}

	/**
	 * First 10 chars when they look like YYYY-MM-DD, else ''. Null-safe,
	 * so API nulls (life members, non-TDs) normalize to ''.
	 */
	protected static function normalize_date( $value ) {
		$value = substr( trim( (string) $value ), 0, 10 );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}

	/**
	 * The "must be active through" date for a USCF status check
	 * (docs/SPEC.md, v1.2 section 5, "Through-date resolves from the
	 * tournament's last day, or the event end ..., or today when
	 * neither is given"). Shared by the registration hook (tournament
	 * end unknown - event end - today), the tournament-create TD check,
	 * the DBF export TD gate, and WP-CLI, so every caller picks the same
	 * date the same way. Pure: takes already-fetched date strings, does
	 * no DB/API access itself.
	 *
	 * @param string $tournament_end Tournament's end_date, or ''.
	 * @param string $event_end      Linked TEC event's end date, or ''.
	 * @param string $today          Override for "today" (tests); blank
	 *                                uses the real current UTC date.
	 * @return string YYYY-MM-DD.
	 */
	public static function resolve_through_date( $tournament_end, $event_end, $today = '' ) {
		$tournament_end = self::normalize_date( $tournament_end );
		if ( '' !== $tournament_end ) {
			return $tournament_end;
		}
		$event_end = self::normalize_date( $event_end );
		if ( '' !== $event_end ) {
			return $event_end;
		}
		$today = self::normalize_date( $today );
		return '' !== $today ? $today : gmdate( 'Y-m-d' );
	}

	// -----------------------------------------------------------------
	// Pure rating-mapping helpers (docs/SPEC.md, v1.2 section 2).
	// -----------------------------------------------------------------

	/**
	 * Picks the rating to use from a MemberDetailDto's ratings[] array:
	 * Regular (R) preferred, Quick (Q) as a fallback when no Regular
	 * rating is on file. A provisional rating is used exactly like a
	 * fully-established one - the isProvisional flag on each entry is
	 * never consulted, which is what "provisional ratings ARE used when
	 * they are the only rating on file" (docs/SPEC.md, owner decision
	 * 2026-07-15) reduces to: whichever rating (of whatever provisional
	 * status) exists for the preferred system wins. Blitz/online systems
	 * are ignored - USCF DBF export only ever uses R or Q (see
	 * WPMTM_USCF_Validator::check_r_system_and_trn_type()).
	 *
	 * @param array $ratings Decoded ratings[] entries, each with at least
	 *                        'ratingSystem' and 'rating'.
	 * @return int|null The rating to use, or null when neither an R nor
	 *                    a Q rating is on file (never blanks good data -
	 *                    the caller must leave the existing value alone).
	 */
	public static function pick_rating( array $ratings ) {
		$regular = null;
		$quick   = null;

		foreach ( $ratings as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$system = isset( $entry['ratingSystem'] ) ? strtoupper( (string) $entry['ratingSystem'] ) : '';
			$value  = isset( $entry['rating'] ) ? $entry['rating'] : null;
			if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
				continue;
			}
			if ( 'R' === $system && null === $regular ) {
				$regular = (int) $value;
			} elseif ( 'Q' === $system && null === $quick ) {
				$quick = (int) $value;
			}
		}

		if ( null !== $regular ) {
			return $regular;
		}
		return $quick;
	}

	/**
	 * The rating-overwrite decision for the registration hook
	 * (docs/SPEC.md, v1.2 section 2): the single pure function the
	 * caller consults after resolving a member lookup, so
	 * WPMTM_Registration_Check never re-derives this logic and every
	 * skip/overwrite path is unit-tested here.
	 *
	 * Returns null (skip; leave the registrant's self-entered value
	 * alone) when the setting is off, the lookup found nothing usable
	 * (null - covers a 404, a network failure/UNKNOWN, or the caller
	 * simply not having resolved data yet), or the member has no Regular
	 * or Quick rating on file. Membership status (active/expired) is
	 * deliberately not considered here - docs/SPEC.md ties the rating
	 * overwrite only to "the lookup resolves and returns a rating", not
	 * to membership being current.
	 *
	 * @param array|null $api         Decoded MemberDetailDto, or null when
	 *                                 there is nothing usable to map from.
	 * @param bool       $setting_on  wpmtm_options['verify_ratings'].
	 * @return int|null The rating to write, or null to skip.
	 */
	public static function decide_rating_overwrite( $api, $setting_on ) {
		if ( ! $setting_on ) {
			return null;
		}
		if ( null === $api ) {
			return null;
		}
		$ratings = isset( $api['ratings'] ) && is_array( $api['ratings'] ) ? $api['ratings'] : array();
		return self::pick_rating( $ratings );
	}

	/** The last date still inside the WARN window after the through-date. */
	protected static function warn_ceiling( $through ) {
		$ts = strtotime( $through . ' +' . self::WARN_WINDOW_DAYS . ' days' );
		return false !== $ts ? gmdate( 'Y-m-d', $ts ) : $through;
	}

	/** "First Last" from a member payload's firstName/lastName fields. */
	protected static function api_person_name( array $api ) {
		$first = isset( $api['firstName'] ) ? trim( (string) $api['firstName'] ) : '';
		$last  = isset( $api['lastName'] ) ? trim( (string) $api['lastName'] ) : '';
		return trim( $first . ' ' . $last );
	}

	// -----------------------------------------------------------------
	// WordPress layer: HTTP client (thin, live-verified, untested by the
	// pure runner).
	// -----------------------------------------------------------------

	/**
	 * Sentinel returned by fetch() (and passed through get_member() /
	 * get_affiliate()) when $cache_only is true and nothing is cached yet.
	 * Distinct from null ("could not reach the API"): this means the API
	 * was never asked, on purpose, because the caller is a cache-only page
	 * render (docs/SPEC.md, "Decisions (2026-07-16, USCF API traffic
	 * reduction)"). validate_member() / validate_td() / validate_affiliate()
	 * turn this into a not_checked_row() rather than an unreachable_row().
	 */
	const NOT_CACHED = 'wpmtm_uscf_not_cached';

	/**
	 * GET /members/{id}, cached. Returns:
	 * - array( 'found' => true, 'data' => array )  on 200,
	 * - array( 'found' => false, 'data' => null )  on 404 (cached too, as
	 *   a miss marker),
	 * - null on network error / any other HTTP status ("could not reach
	 *   the API" - never reported as the entity being invalid),
	 * - self::NOT_CACHED when $cache_only is true and there is no cache
	 *   entry to read.
	 *
	 * @param string $id          Already-sanitized member ID.
	 * @param bool   $force       When true, bypasses the transient cache and
	 *                             re-fetches (docs/SPEC.md v1.2 section 3: the
	 *                             DBF export TD/Safe Play gate and the
	 *                             on-demand "Validate players"/"Validate with USCF"
	 *                             buttons re-check fresh so a cached stale
	 *                             FAIL never blocks a TD who has since fixed
	 *                             the problem, and a stale PASS never lets a
	 *                             since-lapsed TD through). The fresh result
	 *                             still refreshes the cache for the next
	 *                             non-forced caller.
	 * @param bool   $cache_only  When true, never makes an outbound API call:
	 *                             a cache hit is returned as normal, a cache
	 *                             miss returns self::NOT_CACHED instead of
	 *                             fetching. Used by a plain page render
	 *                             (docs/SPEC.md, 2026-07-16), which must
	 *                             cause zero outbound HTTP requests to the
	 *                             unsupported MUIR API. Mutually exclusive
	 *                             with $force in practice (a forced caller
	 *                             wants a fresh answer, not a cache-only one).
	 * @return array|string|null
	 */
	public function get_member( $id, $force = false, $cache_only = false ) {
		return $this->fetch( '/members/' . rawurlencode( $id ), 'wpmtm_uscf_member_' . $id, $force, $cache_only );
	}

	/**
	 * GET /affiliates/{id}, cached; same envelope as get_member().
	 *
	 * @param string $id          Already-sanitized affiliate ID.
	 * @param bool   $force       See get_member().
	 * @param bool   $cache_only  See get_member().
	 * @return array|string|null
	 */
	public function get_affiliate( $id, $force = false, $cache_only = false ) {
		return $this->fetch( '/affiliates/' . rawurlencode( $id ), 'wpmtm_uscf_affiliate_' . $id, $force, $cache_only );
	}

	protected function fetch( $path, $cache_key, $force = false, $cache_only = false ) {
		if ( ! $force ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) && array_key_exists( 'found', $cached ) ) {
				return $cached;
			}
		}

		if ( $cache_only ) {
			// Cache-only callers (a page render, never an on-demand action)
			// must never trigger an outbound API call on a cache miss - the
			// MUIR API v1 is unsupported by USCF, so unnecessary traffic
			// is a real risk (docs/SPEC.md, 2026-07-16).
			return self::NOT_CACHED;
		}

		$response = wp_remote_get(
			self::API_BASE . $path,
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $code ) {
			$result = array(
				'found' => false,
				'data'  => null,
			);
			set_transient( $cache_key, $result, self::CACHE_TTL );
			return $result;
		}
		if ( 200 !== $code ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return null;
		}

		$result = array(
			'found' => true,
			'data'  => $data,
		);
		set_transient( $cache_key, $result, self::CACHE_TTL );
		return $result;
	}

	// -----------------------------------------------------------------
	// WordPress layer: sanitize + fetch + evaluate combinators.
	// -----------------------------------------------------------------

	/**
	 * Full player/member check for one raw ID: sanitize, fetch, evaluate.
	 * Blank and junk IDs FAIL without an API request; a network failure
	 * yields verdict 'UNKNOWN' rather than reporting the member invalid;
	 * a cache-only miss yields 'UNKNOWN' too, with a "not checked yet"
	 * reason instead of "could not reach the API" (see not_checked_row()).
	 *
	 * @param string $id           Raw member ID.
	 * @param string $through_date YYYY-MM-DD; blank falls back to today.
	 * @param bool   $force        See get_member().
	 * @param bool   $cache_only   See get_member().
	 * @return array evaluate_member()'s shape plus a member_id key.
	 */
	public function validate_member( $id, $through_date, $force = false, $cache_only = false ) {
		$row = $this->prepare_id_row( $id, 'member' );
		if ( null !== $row ) {
			return $row;
		}
		$clean = self::sanitize_member_id( $id );
		$env   = $this->get_member( $clean, $force, $cache_only );
		if ( self::NOT_CACHED === $env ) {
			return $this->not_checked_row( $clean );
		}
		if ( null === $env ) {
			return $this->unreachable_row( $clean );
		}
		$result              = self::evaluate_member( $env['data'], $through_date );
		$result['member_id'] = $clean;
		return $result;
	}

	/**
	 * Full TD check for one raw ID; validate_member() plus the TD cert and
	 * Safe Play rules (evaluate_td()).
	 *
	 * @param string $id           Raw member ID.
	 * @param string $through_date YYYY-MM-DD; blank falls back to today.
	 * @param bool   $force        See get_member().
	 * @param bool   $cache_only   See get_member().
	 * @return array evaluate_td()'s shape plus a member_id key.
	 */
	public function validate_td( $id, $through_date, $force = false, $cache_only = false ) {
		$row = $this->prepare_id_row( $id, 'member' );
		if ( null !== $row ) {
			return $row;
		}
		$clean = self::sanitize_member_id( $id );
		$env   = $this->get_member( $clean, $force, $cache_only );
		if ( self::NOT_CACHED === $env ) {
			return $this->not_checked_row( $clean );
		}
		if ( null === $env ) {
			return $this->unreachable_row( $clean );
		}
		$result              = self::evaluate_td( $env['data'], $through_date );
		$result['member_id'] = $clean;
		return $result;
	}

	/**
	 * Full affiliate check for one raw ID.
	 *
	 * @param string $id           Raw affiliate ID.
	 * @param string $through_date YYYY-MM-DD; blank falls back to today.
	 * @param bool   $force        See get_member().
	 * @param bool   $cache_only   See get_member().
	 * @return array evaluate_affiliate()'s shape plus a member_id key
	 *               (the affiliate ID, kept under the same key so every
	 *               row renders through the same client-side code).
	 */
	public function validate_affiliate( $id, $through_date, $force = false, $cache_only = false ) {
		$id = trim( (string) $id );
		if ( '' === $id ) {
			return $this->blank_id_row( __( 'No affiliate ID on file', 'wp-tournament-manager' ) );
		}
		$clean = self::sanitize_affiliate_id( $id );
		if ( '' === $clean ) {
			return $this->blank_id_row( __( 'Affiliate ID is not valid', 'wp-tournament-manager' ), $id );
		}
		$env = $this->get_affiliate( $clean, $force, $cache_only );
		if ( self::NOT_CACHED === $env ) {
			return $this->not_checked_row( $clean );
		}
		if ( null === $env ) {
			return $this->unreachable_row( $clean );
		}
		$result              = self::evaluate_affiliate( $env['data'], $through_date );
		$result['member_id'] = $clean;
		return $result;
	}

	/**
	 * Batch player check: validate_member() over a list of raw IDs,
	 * preserving order and keys.
	 *
	 * @param array  $ids          Raw member IDs.
	 * @param string $through_date YYYY-MM-DD; blank falls back to today.
	 * @return array
	 */
	public function validate_players( array $ids, $through_date ) {
		$results = array();
		foreach ( $ids as $key => $id ) {
			$results[ $key ] = $this->validate_member( $id, $through_date );
		}
		return $results;
	}

	/**
	 * The shared blank/junk-ID short-circuit for validate_member() /
	 * validate_td(). Returns a finished FAIL row, or null when the ID is
	 * clean and the caller should proceed to the API.
	 */
	protected function prepare_id_row( $id, $kind ) {
		$id = trim( (string) $id );
		if ( '' === $id ) {
			return $this->blank_id_row( __( 'No USCF ID on file', 'wp-tournament-manager' ) );
		}
		if ( '' === self::sanitize_member_id( $id ) ) {
			return $this->blank_id_row( __( 'USCF ID is not valid', 'wp-tournament-manager' ), $id );
		}
		return null;
	}

	protected function blank_id_row( $reason, $id = '' ) {
		return array(
			'verdict'    => 'FAIL',
			'reason'     => $reason,
			'warn'       => '',
			'name'       => '',
			'status'     => '',
			'expiration' => '',
			'member_id'  => $id,
		);
	}

	protected function unreachable_row( $id ) {
		return array(
			'verdict'    => 'UNKNOWN',
			'reason'     => __( 'Could not reach the USCF ratings API - try again later', 'wp-tournament-manager' ),
			'warn'       => '',
			'name'       => '',
			'status'     => '',
			'expiration' => '',
			'member_id'  => $id,
		);
	}

	/**
	 * Cache-only-miss row: verdict UNKNOWN, same shape as unreachable_row()
	 * (deliberately reusing the existing UNKNOWN/notice classification
	 * rather than inventing a new verdict level - docs/SPEC.md, 2026-07-16),
	 * but with an accurate "not checked yet" reason instead of implying an
	 * API outage, plus a not_checked flag so callers that want to word a
	 * finding differently (WPMTM_USCF_Validator::classify_export_td_verdict())
	 * can tell the two apart without string-matching the reason text. Never
	 * blocks anything and never reads as a failure - identical treatment to
	 * an outage as far as every existing UNKNOWN-handling caller is
	 * concerned.
	 */
	protected function not_checked_row( $id ) {
		return array(
			'verdict'     => 'UNKNOWN',
			'reason'      => __( 'Not checked yet - will be checked fresh at export time', 'wp-tournament-manager' ),
			'warn'        => '',
			'name'        => '',
			'status'      => '',
			'expiration'  => '',
			'member_id'   => $id,
			'not_checked' => true,
		);
	}

	// -----------------------------------------------------------------
	// WordPress layer: front-end asset load for the wp-etr toolbar button.
	// -----------------------------------------------------------------

	/**
	 * wp-etr renders the "Validate players" button in its Registrations-tab
	 * toolbar (guarded by class_exists + capability on its side); this
	 * plugin owns the JS that wires it (assets/wpmtm-frontend.js). The
	 * script must load on any single-event page a capable user views -
	 * including an event with no linked tournament yet, where
	 * WPMTM_Frontend never enqueues anything - so this hooks
	 * wp_enqueue_scripts directly. Enqueueing is idempotent by handle, so
	 * overlapping with WPMTM_Frontend's own enqueue is safe.
	 */
	public function maybe_enqueue_frontend() {
		if ( ! is_singular( 'tribe_events' ) || ! current_user_can( WPMTM_CAPABILITY ) ) {
			return;
		}
		WPMTM_Frontend_Public::instance()->enqueue_frontend_assets();
		wp_localize_script(
			'wpmtm-frontend',
			'wpmtmValidate',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'i18n'    => array(
					'checking'       => __( 'Checking...', 'wp-tournament-manager' ),
					'requestFailed'  => __( 'The validation request failed - try again.', 'wp-tournament-manager' ),
					'colName'        => __( 'Name', 'wp-tournament-manager' ),
					'colUscfId'      => __( 'USCF ID', 'wp-tournament-manager' ),
					'colStatus'      => __( 'Status', 'wp-tournament-manager' ),
					'colExpiration'  => __( 'Expiration', 'wp-tournament-manager' ),
					'colVerdict'     => __( 'Verdict', 'wp-tournament-manager' ),
					/* translators: 1: number of valid players, 2: total players checked */
					'summaryAllPass' => __( 'All %2$s players valid.', 'wp-tournament-manager' ),
					/* translators: 1: number of valid players, 2: total players checked, 3: number of problems */
					'summaryMixed'   => __( '%1$s of %2$s players valid - %3$s problems.', 'wp-tournament-manager' ),
					/* translators: %s: number of players that could not be checked */
					'summaryUnknown' => __( '%s could not be checked.', 'wp-tournament-manager' ),
				),
			)
		);
	}

	// -----------------------------------------------------------------
	// WordPress layer: admin-ajax handlers.
	// -----------------------------------------------------------------

	/**
	 * AJAX action 'wpmtm_validate_players': validates every non-no-show
	 * registrant of a wp-etr event against the USCF ratings API. Input:
	 * event (post id), nonce ('wpmtm_validate_players_{event_id}', minted
	 * by wp-etr's toolbar). Through-date: the TEC event's end date, else
	 * today. Requires WPMTM_Roles::user_can_manage_tournament() on the
	 * event's linked tournament (WPMTM_CAPABILITY plus ownership for a
	 * dedicated-role TD; administrators always pass).
	 *
	 * Always forces a fresh lookup (docs/SPEC.md, 2026-07-16): with a
	 * 1-day cache, a cached answer here could be up to a day stale, and a
	 * TD who just fixed a membership and clicks this button expects to see
	 * the fix immediately, not "still failing, try again tomorrow". This
	 * is a human-initiated, rare action, not a load concern.
	 */
	public function ajax_validate_players() {
		$event_id = isset( $_POST['event'] ) ? absint( $_POST['event'] ) : 0;
		$nonce    = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( ! $event_id || ! wp_verify_nonce( $nonce, 'wpmtm_validate_players_' . $event_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Reload the page and try again.', 'wp-tournament-manager' ) ), 403 );
		}
		$tournament = WPMTM_Repository::get_tournament_by_event( $event_id );
		if ( ! $tournament || ! WPMTM_Roles::user_can_manage_tournament( $tournament ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to validate players for this event.', 'wp-tournament-manager' ) ), 403 );
		}
		if ( ! class_exists( '\Etr\Plugin' ) ) {
			wp_send_json_error( array( 'message' => __( 'Event Tickets Registrations (wp-etr) is not active.', 'wp-tournament-manager' ) ), 400 );
		}

		$through = $this->event_through_date( $event_id );

		$rows = array();
		foreach ( \Etr\Plugin::instance()->build_sections( $event_id ) as $section_rows ) {
			foreach ( $section_rows as $r ) {
				if ( ! empty( $r['noshow'] ) ) {
					continue; // same skip rule as the ETR roster import.
				}
				// wp-etr's own build_sections() already display-normalizes a
				// pasted profile URL to digits; normalize_member_id_input()
				// runs again here anyway (idempotent on an already-clean
				// digit string) so this path agrees with the registration
				// check and the ETR roster import regardless of wp-etr's
				// version or a manually-typed value - docs/SPEC.md,
				// "Decisions (2026-07-16, URL-form USCF IDs)".
				$raw_id = isset( $r['uscf_id'] ) ? $r['uscf_id'] : '';
				$result = $this->validate_member( WPMTM_USCF_Status::normalize_member_id_input( $raw_id ), $through, true );
				$rows[] = array(
					'name'       => isset( $r['name'] ) ? (string) $r['name'] : '',
					'member_id'  => '' !== $result['member_id'] ? $result['member_id'] : ( isset( $r['uscf_id'] ) ? (string) $r['uscf_id'] : '' ),
					'status'     => $result['status'],
					'expiration' => $result['expiration'],
					'verdict'    => $result['verdict'],
					'reason'     => $result['reason'],
					'warn'       => $result['warn'],
				);
			}
		}

		wp_send_json_success(
			array(
				'through' => $through,
				'rows'    => $rows,
				'summary' => $this->summarize( $rows ),
			)
		);
	}

	/**
	 * AJAX action 'wpmtm_validate_tds': validates the affiliate, the Chief
	 * TD, and the Assistant TD (when set). Two contexts share the handler
	 * and the 'wpmtm_validate_tds' nonce:
	 * - context=settings (Settings page): manage_options; IDs straight
	 *   from wpmtm_options; through-date today.
	 * - context=tournament (tournament edit page): WPMTM_CAPABILITY; the
	 *   tournament's OWN head_td_id/assistant_td_id ONLY, no Settings
	 *   fallback (docs/SPEC.md, 2026-07-17, TD default removal) - a blank
	 *   tournament field means that TD is genuinely absent for this
	 *   tournament and reports through the blank-id row path; affiliate is
	 *   the EFFECTIVE affiliate - the tournament's own if set, else Settings
	 *   (docs/SPEC.md, 2026-07-18, per-tournament USCF affiliate ID) - a
	 *   club-wide fallback, unlike the TD IDs; through-date the tournament
	 *   end date.
	 *
	 * Always forces a fresh lookup for all three checks (docs/SPEC.md,
	 * 2026-07-16): same reasoning as ajax_validate_players() above - with a
	 * 1-day cache a stale cached FAIL would strand a TD who just fixed the
	 * problem at USCF. Human-initiated and rare, not a load concern.
	 */
	public function ajax_validate_tds() {
		$nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		$context = isset( $_POST['context'] ) ? sanitize_key( wp_unslash( $_POST['context'] ) ) : 'settings';

		if ( ! wp_verify_nonce( $nonce, 'wpmtm_validate_tds' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Reload the page and try again.', 'wp-tournament-manager' ) ), 403 );
		}

		$opts      = WPMTM_Plugin::instance()->get_opts();
		$affiliate = (string) $opts['affiliate_id'];
		$chief     = (string) $opts['chief_td_id'];
		$assistant = (string) $opts['assistant_td_id'];
		$through   = current_time( 'Y-m-d' );

		$tournament_id = 0;

		if ( 'tournament' === $context ) {
			$tournament_id = isset( $_POST['tournament'] ) ? absint( $_POST['tournament'] ) : 0;
			$tournament    = $tournament_id ? WPMTM_Repository::get_tournament( $tournament_id ) : null;
			if ( ! $tournament ) {
				wp_send_json_error( array( 'message' => __( 'Tournament not found.', 'wp-tournament-manager' ) ), 400 );
			}
			if ( ! WPMTM_Roles::user_can_manage_tournament( $tournament ) ) {
				wp_send_json_error( array( 'message' => __( 'You do not have permission to validate TDs.', 'wp-tournament-manager' ) ), 403 );
			}
			// Tournament's own TD IDs ONLY - no Settings fallback (docs/
			// SPEC.md, 2026-07-17, TD default removal). A blank field here
			// means that TD is genuinely absent for this tournament, which
			// the blank-id row path below reports as missing rather than
			// silently validating the club-wide Settings default.
			$chief     = (string) $tournament->head_td_id;
			$assistant = (string) $tournament->assistant_td_id;
			// Per-tournament affiliate override with a club-wide fallback
			// (docs/SPEC.md, 2026-07-18) - unlike chief/assistant above,
			// this one intentionally falls back to Settings when blank.
			$tournament_affiliate = trim( (string) $tournament->affiliate_id );
			if ( '' !== $tournament_affiliate ) {
				$affiliate = $tournament_affiliate;
			}
			$end       = self::normalize_date( $tournament->end_date );
			if ( '' !== $end ) {
				$through = $end;
			}
			// Record that the check actually ran for this tournament (docs/SPEC.md,
			// 2026-07-16, TD check timestamp) - independent of the
			// get_member()/get_affiliate() transient cache above: this marks when
			// the TD last ran the check, not when the cache was last filled, so it
			// must keep advancing even on a cache-hit re-check.
			self::record_td_check( $tournament_id );
		} elseif ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to validate TDs.', 'wp-tournament-manager' ) ), 403 );
		}

		$rows = array();

		$affiliate_row          = $this->validate_affiliate( $affiliate, $through, true );
		$affiliate_row['role']  = __( 'Affiliate', 'wp-tournament-manager' );
		$rows[]                 = $affiliate_row;

		$chief_row         = $this->validate_td( $chief, $through, true );
		$chief_row['role'] = __( 'Chief TD', 'wp-tournament-manager' );
		$rows[]            = $chief_row;

		if ( '' !== trim( $assistant ) ) {
			$assistant_row         = $this->validate_td( $assistant, $through, true );
			$assistant_row['role'] = __( 'Assistant TD', 'wp-tournament-manager' );
			$rows[]                = $assistant_row;
		}

		$response = array(
			'through' => $through,
			'rows'    => $rows,
		);
		if ( $tournament_id ) {
			$response['last_checked_text'] = self::last_td_check_text( $tournament_id );
		}

		wp_send_json_success( $response );
	}

	/**
	 * Per-tournament "TD check last ran" timestamp (docs/SPEC.md, 2026-07-16,
	 * TD check timestamp). Stored with update_option() under a per-tournament
	 * key rather than a schema column - this is presentation metadata, not
	 * tournament data, and there is no natural place for a single nullable
	 * column shared by every tournament row for something that is set post
	 * hoc by an admin-ajax action. autoload is false: this is only ever read
	 * on the single tournament edit page, never on every page load.
	 *
	 * @param int $tournament_id
	 */
	public static function record_td_check( $tournament_id ) {
		update_option( 'wpmtm_td_check_' . (int) $tournament_id, current_time( 'timestamp' ), false ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- human_time_diff() below needs a Unix timestamp in the site's timezone, which is exactly what current_time('timestamp') returns.
	}

	/**
	 * Human-readable "Last checked ... ago" string for the tournament edit
	 * page (WPMTM_Admin::render_tournament_form()) and the AJAX response
	 * above, so the two never drift out of sync. Site-timezone-aware: both
	 * sides passed to human_time_diff() are current_time('timestamp')
	 * values (record_td_check() stores one, and $to below is a fresh one) -
	 * human_time_diff()'s own default for $to is raw time() (UTC), which
	 * would misreport by exactly the site's UTC offset if left implicit,
	 * so it is passed explicitly here.
	 *
	 * @param int $tournament_id
	 * @return string Translated, human-readable text (not yet escaped).
	 */
	public static function last_td_check_text( $tournament_id ) {
		$stored = get_option( 'wpmtm_td_check_' . (int) $tournament_id, 0 );
		if ( ! $stored ) {
			return __( 'Never checked', 'wp-tournament-manager' );
		}
		return sprintf(
			/* translators: %s: human-readable relative time, e.g. "5 minutes" */
			__( 'Last checked %s ago', 'wp-tournament-manager' ),
			human_time_diff( (int) $stored, current_time( 'timestamp' ) ) // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- must match the current_time('timestamp') convention record_td_check() stores under, not raw time().
		);
	}

	/** The event's TEC end date (YYYY-MM-DD), else today. */
	protected function event_through_date( $event_id ) {
		$end = get_post_meta( $event_id, '_EventEndDate', true );
		$end = self::normalize_date( is_string( $end ) ? substr( $end, 0, 10 ) : '' );
		return '' !== $end ? $end : current_time( 'Y-m-d' );
	}

	/** PASS/FAIL/UNKNOWN counts for the players summary line. */
	protected function summarize( array $rows ) {
		$counts = array(
			'total'   => count( $rows ),
			'pass'    => 0,
			'fail'    => 0,
			'unknown' => 0,
		);
		foreach ( $rows as $row ) {
			if ( 'PASS' === $row['verdict'] ) {
				$counts['pass']++;
			} elseif ( 'UNKNOWN' === $row['verdict'] ) {
				$counts['unknown']++;
			} else {
				$counts['fail']++;
			}
		}
		return $counts;
	}
}
