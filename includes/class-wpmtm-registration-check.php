<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * USCF membership + rating check at registration (docs/SPEC.md, "Design
 * (2026-07-15, v1.2 USCF status at registration, export, and CLI)",
 * sections 1 and 2). Hooks Event Tickets' `tec_tickets_commerce_attendee_meta_save`
 * (fires with $attendee_id, $ticket_id whenever an attendee's ETECF fields
 * save, at checkout and on every later edit) to:
 *
 * - Look up the attendee's USCF member ID sync, via the existing
 *   WPMTM_USCF_Status::get_member() 1-day cache (never a fresh/forced
 *   fetch - this runs on every checkout, unlike the export gate).
 * - Warn the registrant, on the Tickets Commerce order-success page, when
 *   membership is not active through the linked event's last day. Nothing
 *   is saved for this: no attendee meta, no DB row, no async sweep - a
 *   short transient (auto-expiring, not a persistent record) bridges the
 *   checkout request to the success-page render of the same order.
 * - When the lookup resolves a Regular or Quick rating, overwrite the
 *   ETECF rating field with it. Never overwrites when the lookup fails/is
 *   UNKNOWN, or the member has no rating on file (WPMTM_USCF_Status::
 *   decide_rating_overwrite() - the pure decision, unit-tested). Whenever
 *   this DOES overwrite, it also stamps provenance meta (RATING_SOURCE_META
 *   / RATING_CHECKED_META, docs/SPEC.md "Decisions (2026-07-17, rating
 *   provenance)") so a disputed rating can be answered later - no backup of
 *   the value replaced, and nothing is stamped on a rating TM never wrote.
 *
 * Both the membership check and the rating overwrite are gated together by
 * ONE master toggle, wpmtm_options['verify_ratings'] (docs/SPEC.md,
 * "Decisions (2026-07-18, master automatic-checking toggle)"; Settings
 * label "Automatically check registrants against USCF (memberships and
 * ratings)"). Before this change the membership check ran unconditionally
 * and only the rating overwrite was gated by the setting; now checks_enabled_for()
 * is the single decision both paths share, so turning the toggle off stops
 * every automatic USCF MUIR call at registration - no membership lookup, no
 * registrant warning, no rating overwrite. Default on. The manual "Validate
 * players" and "Validate with USCF" buttons are unaffected either way -
 * they always call the API on demand.
 *
 * A slow/down/unresolved API lookup is skipped silently in both cases and
 * never affects checkout (no thrown exception is allowed to escape this
 * class's hook callbacks - see the try/catch in on_attendee_meta_save()).
 *
 * No-ops entirely when Event Tickets (Tickets Commerce) is not active:
 * the constructor only wires its hooks when the ET class this plugin
 * depends on exists, the same guard style WPMTM_USCF_Status::
 * ajax_validate_players() uses for wp-etr.
 *
 * Unrated tournaments, AND events with no linked tournament yet, skip this
 * entirely (docs/SPEC.md, "Decisions (2026-07-17, no-tournament registration
 * checks)"; supersedes the 2026-07-16 unrated-only version): a casual club
 * night with no USCF setup should never trigger a USCF lookup, a registrant
 * warning, or a rating overwrite. See checks_enabled_for(), the pure
 * decision this class consults before doing any work.
 *
 * Registration opens BEFORE the TD creates the tournament, so "no
 * tournament yet" was the common case the 2026-07-16 unrated skip was meant
 * to cover, and treating it as ENABLED (the original reasoning: rated
 * status is unknowable, so check anyway) missed exactly that case - every
 * registrant on a casual night got checked, warned, and had their rating
 * overwritten, and only afterwards did the TD create the (unrated)
 * tournament. TM must not do rated-event work speculatively; a check is
 * worthless before anyone has declared the event rated. The membership
 * question is not lost by skipping here - it re-surfaces once rated status
 * IS known, at roster-import preview time (WPMTM_Admin_Import::
 * membership_warnings()) and on demand via "Validate players".
 */
class WPMTM_Registration_Check {

	/**
	 * Transient lifetime for the registrant-facing membership warning,
	 * in seconds. Only needs to survive the redirect from checkout to
	 * the order-success page, but a slightly longer window covers a
	 * registrant who edits their registration and revisits the success
	 * page URL shortly after.
	 */
	const WARN_TTL = 600;

	/** Fallback ETECF field keys when wp-etr is inactive/unavailable to resolve the configured ones. */
	const DEFAULT_MEMBER_ID_FIELD = 'etecf_uscf_member_id';
	const DEFAULT_RATING_FIELD    = 'etecf_uscf_rating';

	/**
	 * Rating provenance meta (docs/SPEC.md, "Decisions (2026-07-17, rating
	 * provenance)"): written on the attendee ONLY when TM itself overwrites
	 * the rating field with the official USCF value. Absence means "not
	 * written by us" - the honest default for a self-entered rating TM
	 * never touched. No backup of the prior value is kept; provenance is
	 * not a backup, by owner decision.
	 */
	const RATING_SOURCE_META  = '_wpmtm_rating_source';
	const RATING_CHECKED_META = '_wpmtm_rating_checked';
	const RATING_SOURCE_OFFICIAL = 'official';

	private static $instance = null;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Event Tickets' Tickets Commerce module is the actual dependency
		// (it fires the hook this class needs); ETECF's own fields are
		// read as plain post meta (event-tickets-etecf-plugins skill:
		// "read ETECF's data, don't call ETECF's PHP classes"), so no
		// ETECF class_exists() guard is needed here - a missing field
		// just reads as ''.
		if ( ! class_exists( '\TEC\Tickets\Commerce\Module' ) ) {
			return;
		}
		add_action( 'tec_tickets_commerce_attendee_meta_save', array( $this, 'on_attendee_meta_save' ), 20, 2 );
		add_filter( 'tribe_template_after_include_html:tickets/v2/commerce/success', array( $this, 'render_success_warnings' ), 10, 4 );
	}

	// -----------------------------------------------------------------
	// Pure decision: is there a rated tournament to check against yet?
	// Zero WordPress calls, unit-tested directly by
	// tests/registration-check-tests.php.
	// -----------------------------------------------------------------

	/**
	 * Registration checks apply ONLY to a linked tournament that is
	 * explicitly rated. No linked tournament yet, or a linked tournament
	 * that is unrated, both disable the check (docs/SPEC.md, "Decisions
	 * (2026-07-17, no-tournament registration checks)"; TD-PERSONA.md
	 * anti-goal: rated-event machinery must not intrude on casual unrated
	 * nights).
	 *
	 * Owner ruling: rated status being "unknowable" before a tournament
	 * exists is not a reason to check anyway - it is the reason not to. TM
	 * must not do rated-event work speculatively, and a check is worthless
	 * before anyone has declared the event rated. Registration opens before
	 * the TD creates the tournament, so treating null as ENABLED (the
	 * pre-2026-07-17 behavior) meant every registrant on a brand-new
	 * install, or a casual night before its tournament exists, got a USCF
	 * lookup, a warning, and a rating overwrite - the exact scenario the
	 * 2026-07-16 unrated skip was written to prevent, and missed. A
	 * brand-new install with no tournaments at all also made a USCF lookup
	 * for every registrant, against an API USCF does not officially
	 * support.
	 *
	 * This does not lose the membership question: it is checked instead
	 * where rated status IS known - the roster-import preview
	 * (WPMTM_Admin_Import::membership_warnings(), cache-only, never a
	 * block) once a tournament exists, and on demand via "Validate
	 * players" / `wp wpmtm validate players` at any time.
	 *
	 * Also gates on the Settings master toggle (docs/SPEC.md, "Decisions
	 * (2026-07-18, master automatic-checking toggle)"; option key
	 * 'verify_ratings', label "Automatically check registrants against US
	 * Chess (memberships and ratings)"). Before this, the toggle only
	 * governed the rating overwrite while the membership check ran
	 * unconditionally; this unifies both under one owner-facing switch, so
	 * a club that turns automatic checking off gets NO automatic USCF
	 * lookup at registration at all, not just no rating overwrite. The
	 * manual "Validate players" and "Validate with USCF" buttons are
	 * untouched by this: they call the API on demand regardless.
	 *
	 * @param object|null $tournament           A tournaments row (with a
	 *                                            ->rated property), or null
	 *                                            when the event has no
	 *                                            linked tournament.
	 * @param bool        $auto_checks_enabled   wpmtm_options['verify_ratings'];
	 *                                            defaults to true so any
	 *                                            other caller that does not
	 *                                            yet pass this keeps the
	 *                                            pre-toggle-unification
	 *                                            behavior.
	 * @return bool
	 */
	public static function checks_enabled_for( $tournament, $auto_checks_enabled = true ) {
		if ( ! $auto_checks_enabled ) {
			return false;
		}
		if ( null === $tournament ) {
			return false;
		}
		return ! empty( $tournament->rated );
	}

	// -----------------------------------------------------------------
	// Checkout-time hook.
	// -----------------------------------------------------------------

	/**
	 * @param int $attendee_id tec_tc_attendee post ID.
	 * @param int $ticket_id   Unused here; part of the hook signature.
	 */
	public function on_attendee_meta_save( $attendee_id, $ticket_id ) {
		try {
			$this->check_attendee( (int) $attendee_id );
		} catch ( Throwable $e ) {
			// This runs during checkout - never let a USCF API hiccup or
			// an unexpected payload shape break a purchase in progress.
			return;
		}
	}

	protected function check_attendee( $attendee_id ) {
		if ( ! $attendee_id ) {
			return;
		}

		$opts                = WPMTM_Plugin::instance()->get_opts();
		$auto_checks_enabled = ! empty( $opts['verify_ratings'] );

		$event_id   = (int) get_post_meta( $attendee_id, '_tec_tickets_commerce_event', true );
		$tournament = $event_id ? WPMTM_Repository::get_tournament_by_event( $event_id ) : null;
		if ( ! self::checks_enabled_for( $tournament, $auto_checks_enabled ) ) {
			return; // Automatic checking is off, there is no tournament yet, or it is unrated: no USCF lookup, no registrant warning, no rating overwrite.
		}

		$member_id_field = $this->resolve_field_key( 'uscf_id_field', self::DEFAULT_MEMBER_ID_FIELD );
		$raw_id          = (string) get_post_meta( $attendee_id, $member_id_field, true );
		// Normalize first (accepts a bare ID or a pasted USCF profile
		// URL - docs/SPEC.md, "Decisions (2026-07-16, URL-form USCF IDs)"),
		// then sanitize (the strict, unchanged contract every other caller
		// relies on).
		$clean_id = WPMTM_USCF_Status::sanitize_member_id( WPMTM_USCF_Status::normalize_member_id_input( $raw_id ) );
		if ( '' === $clean_id ) {
			return; // No USCF ID on file - nothing to check or sync.
		}

		$through = WPMTM_USCF_Status::resolve_through_date( '', $this->event_end_date( $event_id ), '' );

		$status = WPMTM_USCF_Status::instance();
		// Sync, existing 1-day cache, never forced here. The short timeout is
		// audit item 50: this runs inside Tickets Commerce checkout, so a cache
		// miss against a slow API sits on the buyer's critical path. The check
		// is advisory - a timeout returns null and this method exits silently
		// below, exactly as it already did for any other API failure - so
		// waiting the full REQUEST_TIMEOUT would buy the registrant nothing.
		$env    = $status->get_member( $clean_id, false, false, WPMTM_USCF_Status::CHECKOUT_TIMEOUT );
		if ( null === $env ) {
			return; // API down/unresolved - skip silently, never affect checkout.
		}
		$api = $env['found'] ? $env['data'] : null;

		// Section 1: membership warning, registrant-facing, save nothing.
		$member_verdict = WPMTM_USCF_Status::evaluate_member( $api, $through );
		if ( 'PASS' !== $member_verdict['verdict'] ) {
			$message = '' !== $member_verdict['reason']
				? $member_verdict['reason']
				: __( 'We could not confirm an active USCF membership for the USCF ID on this registration.', 'wp-tournament-manager' );
			set_transient( 'wpmtm_reg_warn_' . $attendee_id, $message, self::WARN_TTL );
		} else {
			// Idempotent on re-edit: a since-fixed membership clears any
			// warning left over from an earlier save of this attendee.
			delete_transient( 'wpmtm_reg_warn_' . $attendee_id );
		}

		// Section 2: ratings sync. $auto_checks_enabled is already known
		// true here (checks_enabled_for() above returned early otherwise),
		// but decide_rating_overwrite() keeps taking the flag explicitly
		// since it is also the unit-tested contract other tests exercise
		// directly with both true and false.
		$new_rating = WPMTM_USCF_Status::decide_rating_overwrite( $api, $auto_checks_enabled );
		if ( null !== $new_rating ) {
			$rating_field = $this->resolve_field_key( 'rating_field', self::DEFAULT_RATING_FIELD );
			update_post_meta( $attendee_id, $rating_field, (string) $new_rating );

			// Provenance (docs/SPEC.md, "Decisions (2026-07-17, rating
			// provenance)"): records that this rating came from USCF,
			// not the adult who registered the player, and when it was
			// checked, so a disputed rating can be answered later. No
			// backup of the value TM just replaced - owner decision;
			// provenance is not a backup. current_time('timestamp'), NOT
			// time(): human_time_diff() and friends compare against
			// current_time('timestamp') by default (see
			// WPMTM_USCF_Status::record_td_check()/last_td_check_text()
			// for the precedent and the bug this avoids).
			update_post_meta( $attendee_id, self::RATING_SOURCE_META, self::RATING_SOURCE_OFFICIAL );
			update_post_meta( $attendee_id, self::RATING_CHECKED_META, current_time( 'timestamp' ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- must match the current_time('timestamp') convention every other provenance/display reader in this codebase compares against, not raw time().
		}
	}

	// -----------------------------------------------------------------
	// Order-success page warning (docs/SPEC.md v1.2 section 1; pinned
	// item: the exact ET hook).
	// -----------------------------------------------------------------

	/**
	 * Appends a plain warning box to the rendered order-success page HTML
	 * for any attendee on that order with a pending membership warning.
	 *
	 * Hook: `tribe_template_after_include_html:tickets/v2/commerce/success`
	 * (TEC\Tickets\Commerce\Shortcodes\Success_Shortcode renders the
	 * 'success' template through Tribe__Template, whose hook name is
	 * built from the plugin's template namespace, 'tickets', plus the
	 * relative template path 'v2/commerce/success' - verified live by
	 * instrumenting Success_Shortcode::get_html() and listing every
	 * tribe_template_* hook it fires). This filter only receives the
	 * (normally empty) after-include segment, not the whole rendered
	 * template - Tribe__Template fires it once the success template's own
	 * output is done, then concatenates whatever this callback returns
	 * onto the end of that output, which is why appending here still
	 * lands after the real content. It works regardless of which payment
	 * gateway processed the order (unlike the gateway-suffixed
	 * `tec_tickets_commerce_success_shortcode_success_page_{gateway}_template_vars`
	 * filter, which would need one registration per gateway and still
	 * only reaches the template if the 'success' template itself renders
	 * that var - it does not).
	 *
	 * @param string          $html     The rendered template HTML so far.
	 * @param string          $file     Absolute template file path.
	 * @param array           $name     Template name parts.
	 * @param Tribe__Template $template The template instance; get_values()
	 *                                   exposes the 'attendees' var the
	 *                                   success template was rendered with.
	 * @return string
	 */
	public function render_success_warnings( $html, $file, $name, $template ) {
		if ( ! is_object( $template ) || ! method_exists( $template, 'get_values' ) ) {
			return $html;
		}
		$values    = $template->get_values();
		$attendees = isset( $values['attendees'] ) && is_array( $values['attendees'] ) ? $values['attendees'] : array();
		if ( empty( $attendees ) ) {
			return $html;
		}

		$messages = array();
		foreach ( $attendees as $attendee ) {
			$attendee_id = is_object( $attendee ) && isset( $attendee->ID ) ? (int) $attendee->ID : (int) $attendee;
			if ( ! $attendee_id ) {
				continue;
			}
			$warning = get_transient( 'wpmtm_reg_warn_' . $attendee_id );
			if ( is_string( $warning ) && '' !== $warning ) {
				$messages[] = $warning;
			}
		}

		if ( empty( $messages ) ) {
			return $html;
		}

		$box  = '<div class="tribe-common event-tickets wpmtm-registration-warning">';
		$box .= '<p><strong>' . esc_html__( 'USCF membership notice', 'wp-tournament-manager' ) . '</strong></p><ul>';
		foreach ( $messages as $message ) {
			$box .= '<li>' . esc_html( $message ) . '</li>';
		}
		$box .= '</ul><p>' . esc_html__( 'This does not affect the registration. Contact the tournament director if it looks like a mistake.', 'wp-tournament-manager' ) . '</p></div>';

		return $html . $box;
	}

	// -----------------------------------------------------------------
	// Field-key + date helpers.
	// -----------------------------------------------------------------

	/**
	 * Resolves the actual ETECF meta key wp-etr treats as the USCF ID or
	 * rating field (its `uscf_id_field` / `rating_field` settings,
	 * pinned item resolution - see docs/SPEC.md v1.2 section 2 and the
	 * build report), so a club that reconfigures those settings still
	 * gets the overwrite on the field the roster import actually reads.
	 * Falls back to the plugin-wide default key when wp-etr is absent.
	 *
	 * @param string $opt_key wp-etr option key ('uscf_id_field' or 'rating_field').
	 * @param string $default Fallback meta key.
	 * @return string
	 */
	protected function resolve_field_key( $opt_key, $default ) {
		if ( class_exists( '\Etr\Plugin' ) && method_exists( '\Etr\Plugin', 'instance' ) ) {
			$etr  = \Etr\Plugin::instance();
			if ( method_exists( $etr, 'get_opts' ) ) {
				$opts = $etr->get_opts();
				if ( is_array( $opts ) && ! empty( $opts[ $opt_key ] ) ) {
					return (string) $opts[ $opt_key ];
				}
			}
		}
		return $default;
	}

	/** The linked TEC event's end date (YYYY-MM-DD), or '' when unknown. */
	protected function event_end_date( $event_id ) {
		if ( ! $event_id ) {
			return '';
		}
		$end = get_post_meta( $event_id, '_EventEndDate', true );
		return is_string( $end ) ? substr( $end, 0, 10 ) : '';
	}
}
