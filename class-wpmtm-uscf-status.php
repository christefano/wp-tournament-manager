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
 *   15-minute transient cache), the validate_*() combinators, and the two
 *   admin-ajax handlers are the WordPress layer, live-verified rather than
 *   unit-tested.
 */
class WPMTM_USCF_Status {

	const API_BASE = 'https://ratings-api.uschess.org/api/v1';

	/**
	 * Transient lifetime for cached API responses, in seconds. Short (15
	 * minutes) on purpose: a TD who fixes a membership at US Chess can
	 * re-validate shortly after without waiting out a long cache.
	 */
	const CACHE_TTL = 900;

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
				$row['reason'] = 'Not a current US Chess member';
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
				$row['reason'] = 'Not a current US Chess affiliate';
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
	 * GET /members/{id}, cached. Returns:
	 * - array( 'found' => true, 'data' => array )  on 200,
	 * - array( 'found' => false, 'data' => null )  on 404 (cached too, as
	 *   a miss marker),
	 * - null on network error / any other HTTP status ("could not reach
	 *   the API" - never reported as the entity being invalid).
	 *
	 * @param string $id Already-sanitized member ID.
	 * @return array|null
	 */
	public function get_member( $id ) {
		return $this->fetch( '/members/' . rawurlencode( $id ), 'wpmtm_uscf_member_' . $id );
	}

	/**
	 * GET /affiliates/{id}, cached; same envelope as get_member().
	 *
	 * @param string $id Already-sanitized affiliate ID.
	 * @return array|null
	 */
	public function get_affiliate( $id ) {
		return $this->fetch( '/affiliates/' . rawurlencode( $id ), 'wpmtm_uscf_affiliate_' . $id );
	}

	protected function fetch( $path, $cache_key ) {
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) && array_key_exists( 'found', $cached ) ) {
			return $cached;
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
	 * yields verdict 'UNKNOWN' rather than reporting the member invalid.
	 *
	 * @param string $id           Raw member ID.
	 * @param string $through_date YYYY-MM-DD; blank falls back to today.
	 * @return array evaluate_member()'s shape plus a member_id key.
	 */
	public function validate_member( $id, $through_date ) {
		$row = $this->prepare_id_row( $id, 'member' );
		if ( null !== $row ) {
			return $row;
		}
		$clean = self::sanitize_member_id( $id );
		$env   = $this->get_member( $clean );
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
	 * @return array evaluate_td()'s shape plus a member_id key.
	 */
	public function validate_td( $id, $through_date ) {
		$row = $this->prepare_id_row( $id, 'member' );
		if ( null !== $row ) {
			return $row;
		}
		$clean = self::sanitize_member_id( $id );
		$env   = $this->get_member( $clean );
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
	 * @return array evaluate_affiliate()'s shape plus a member_id key
	 *               (the affiliate ID, kept under the same key so every
	 *               row renders through the same client-side code).
	 */
	public function validate_affiliate( $id, $through_date ) {
		$id = trim( (string) $id );
		if ( '' === $id ) {
			return $this->blank_id_row( __( 'No affiliate ID on file', 'wp-tournament-manager' ) );
		}
		$clean = self::sanitize_affiliate_id( $id );
		if ( '' === $clean ) {
			return $this->blank_id_row( __( 'Affiliate ID is not valid', 'wp-tournament-manager' ), $id );
		}
		$env = $this->get_affiliate( $clean );
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
	 * today. Requires WPMTM_CAPABILITY plus edit_post on the event.
	 */
	public function ajax_validate_players() {
		$event_id = isset( $_POST['event'] ) ? absint( $_POST['event'] ) : 0;
		$nonce    = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( ! $event_id || ! wp_verify_nonce( $nonce, 'wpmtm_validate_players_' . $event_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Reload the page and try again.', 'wp-tournament-manager' ) ), 403 );
		}
		if ( ! current_user_can( WPMTM_CAPABILITY ) || ! current_user_can( 'edit_post', $event_id ) ) {
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
				$result = $this->validate_member( isset( $r['uscf_id'] ) ? $r['uscf_id'] : '', $through );
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
	 * - context=tournament (tournament edit page): WPMTM_CAPABILITY;
	 *   effective TD IDs (the per-tournament override when set, else the
	 *   Settings default - the same resolution the USCF export uses);
	 *   affiliate from Settings; through-date the tournament end date.
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

		if ( 'tournament' === $context ) {
			if ( ! current_user_can( WPMTM_CAPABILITY ) ) {
				wp_send_json_error( array( 'message' => __( 'You do not have permission to validate TDs.', 'wp-tournament-manager' ) ), 403 );
			}
			$tournament_id = isset( $_POST['tournament'] ) ? absint( $_POST['tournament'] ) : 0;
			$tournament    = $tournament_id ? WPMTM_Repository::get_tournament( $tournament_id ) : null;
			if ( ! $tournament ) {
				wp_send_json_error( array( 'message' => __( 'Tournament not found.', 'wp-tournament-manager' ) ), 400 );
			}
			// Effective TD IDs: per-tournament override when set, else the
			// Settings default - mirrors WPMTM_Export_Builder::build().
			if ( '' !== trim( (string) $tournament->head_td_id ) ) {
				$chief = (string) $tournament->head_td_id;
			}
			if ( '' !== trim( (string) $tournament->assistant_td_id ) ) {
				$assistant = (string) $tournament->assistant_td_id;
			}
			$end = self::normalize_date( $tournament->end_date );
			if ( '' !== $end ) {
				$through = $end;
			}
		} elseif ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to validate TDs.', 'wp-tournament-manager' ) ), 403 );
		}

		$rows = array();

		$affiliate_row          = $this->validate_affiliate( $affiliate, $through );
		$affiliate_row['role']  = __( 'Affiliate', 'wp-tournament-manager' );
		$rows[]                 = $affiliate_row;

		$chief_row         = $this->validate_td( $chief, $through );
		$chief_row['role'] = __( 'Chief TD', 'wp-tournament-manager' );
		$rows[]            = $chief_row;

		if ( '' !== trim( $assistant ) ) {
			$assistant_row         = $this->validate_td( $assistant, $through );
			$assistant_row['role'] = __( 'Assistant TD', 'wp-tournament-manager' );
			$rows[]                = $assistant_row;
		}

		wp_send_json_success(
			array(
				'through' => $through,
				'rows'    => $rows,
			)
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
