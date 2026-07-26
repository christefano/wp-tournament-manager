<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Setup guide (docs/SPEC.md, "Design (2026-07-16, setup guide redesign)",
 * superseding the delivery half of "Decisions (2026-07-11, guided
 * walkthrough implemented)"): tells a TD what they just did, what to click
 * next, and why it matters, derived fresh from real tournament data on
 * every request.
 *
 * One delivery channel: a progress stepper PANEL at the top of the
 * tournament edit page (render_panel(), called from
 * WPMTM_Admin::render_tournament_edit()), and on an event page for a TD
 * without wp-admin access (render_panel_for_event()). Always present for
 * any existing tournament, collapsed by default, remembering each TD's
 * open/closed choice. Not a WordPress admin notice, so a third-party
 * "hide all notices" plugin cannot make it disappear - the exact problem
 * the original 2026-07-16 redesign solved (see the SPEC section).
 *
 * There used to be a second channel: an admin_notices CARD on the
 * Tournaments list and Settings pages, plus a slim one-line "start the
 * guide" offer notice on the list page for a TD who had not started it
 * yet. Both are gone (2026-07-22, owner request) - the header "Show setup
 * guide" button on every TM admin page (render_show_guide_button()) is
 * still how a TD starts or returns to the guide; it just has no inline
 * notice to show alongside it any more.
 *
 * Deliberately derive-from-state rather than a stored step counter:
 * derive_step() is a pure static method with no WordPress calls - the
 * unit-tested core (tests/wizard-tests.php) - recomputing from the real
 * tournament/section/player data on every request, so a TD who does
 * something out of order (or skips ahead using the normal UI) is never
 * stuck on a stale step.
 *
 * Naming: the user-facing name is "setup guide" everywhere - button
 * labels, notices, the panel, the README. The word "wizard" is developer
 * jargon and never appears in a UI string. The internal class name
 * WPMTM_Wizard and the internal user-meta key 'wpmtm_wizard' are unchanged
 * (the WPMTM_/wpmtm_ prefix is frozen); this is a copy change, not a
 * refactor.
 *
 * The old "access" step (offering to create the Tournament Manager role)
 * is gone. It was never really part of a tournament's lifecycle - it is a
 * one-time site-level decision, and WPMTM_Settings::field_role_decision()
 * already gives it a full, independent path (its own sanitize callback
 * creates/removes the role via WPMTM_Roles), so removing it here loses no
 * capability.
 */
class WPMTM_Wizard {

	private static $instance = null;

	/**
	 * Guards render_panel_for_event() against rendering more than once per
	 * request - see that method's own docblock.
	 *
	 * @var bool
	 */
	private $event_panel_rendered = false;

	const META_KEY = 'wpmtm_wizard';

	/**
	 * Query arg appended to the 'show' action's redirect so the page it
	 * lands back on (Settings, WPMTM_Settings::render_settings_page()) can
	 * render a one-time confirmation notice - the TD clicking "Show setup
	 * guide" otherwise sees only a page reload with no feedback that
	 * anything happened.
	 */
	const SHOWN_QUERY_ARG = 'wpmtm_guide_shown';

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_post_wpmtm_wizard', array( $this, 'handle_action' ) );
		add_action( 'wp_ajax_wpmtm_toggle_setup_guide_panel', array( $this, 'ajax_toggle_panel' ) );
		// Renders the plugin README in a standalone page for the setup guide's
		// "Documentation" popup (2026-07-22). admin_post (logged-in only) so it
		// covers a TD viewing the guide on the front-end event page too, where
		// the panel also appears; capability- and nonce-checked in the handler.
		add_action( 'admin_post_wpmtm_readme', array( $this, 'render_readme_page' ) );
	}

	// -----------------------------------------------------------------
	// Per-user state (user meta 'wpmtm_wizard'). No step number is ever
	// stored - see the class docblock.
	// -----------------------------------------------------------------

	protected function get_state() {
		$meta     = get_user_meta( get_current_user_id(), self::META_KEY, true );
		$defaults = array(
			// Defaults to true (2026-07-24, owner request: "setup guide
			// enabled by default when TM plugin is activated"), not a
			// register_activation_hook write - this state is per-user meta,
			// and activation runs once for whoever clicks Activate, not for
			// every TD who will ever use the site. Defaulting the lazily-
			// read value here means any user with no stored preference yet
			// (a fresh install, or a TD who has never touched the panel)
			// sees the guide active, while stop()/set_panel_open() etc.
			// still let each TD opt out permanently once they explicitly do.
			'active'          => true,
			'tournament_id'   => 0,
			// Per-TD collapsed/open choice for the tournament edit page's
			// stepper panel (docs/SPEC.md, "Design (2026-07-16, setup guide
			// redesign)", section 1). One flag per TD across every
			// tournament, not per-tournament - simplest state that still
			// satisfies "remembering each TD's open/closed choice", and no
			// schema change.
			'panel_open'      => false,
			// Whether this TD has ever opened or closed the panel themselves
			// (2026-07-21, auto-expand). Without it, "never touched it" and
			// "deliberately collapsed it" are indistinguishable, since
			// panel_open defaults to false for both - and auto-expanding on
			// that ambiguity would reopen the panel on every load for
			// someone who keeps closing it, which is worse than the problem
			// auto-expand solves. Set by set_panel_open(), which only ever
			// runs from the TD's own toggle.
			'panel_choice_made' => false,
		);
		return is_array( $meta ) ? wp_parse_args( $meta, $defaults ) : $defaults;
	}

	protected function save_state( array $state ) {
		update_user_meta( get_current_user_id(), self::META_KEY, $state );
	}

	public function is_active() {
		return ! empty( $this->get_state()['active'] );
	}

	public function is_panel_open() {
		return ! empty( $this->get_state()['panel_open'] );
	}

	public function start() {
		$state                  = $this->get_state();
		$state['active']        = true;
		$state['tournament_id'] = 0;
		$this->save_state( $state );
	}

	public function stop() {
		$state           = $this->get_state();
		$state['active'] = false;
		$this->save_state( $state );
	}

	/**
	 * "Show setup guide": marks the guide active wherever a TD exited it,
	 * so render_panel() picks it back up (the tournament edit page, and
	 * any event page it appears on for a TD without wp-admin access).
	 * Before 2026-07-22 this also un-dismissed the Tournaments list offer
	 * notice - that notice is gone, along with the state it read.
	 * handle_action()'s 'show' case appends SHOWN_QUERY_ARG to the redirect
	 * so the landing page can confirm this actually happened.
	 */
	public function show() {
		$state           = $this->get_state();
		$state['active'] = true;
		$this->save_state( $state );
	}

	public function set_panel_open( $open ) {
		$state                      = $this->get_state();
		$state['panel_open']        = (bool) $open;
		// Only ever reached from the TD's own toggle, so this is the moment
		// their preference becomes explicit and auto-expand stops applying.
		$state['panel_choice_made'] = true;
		$this->save_state( $state );
	}

	/** Whether this TD has ever opened or closed the panel themselves. */
	public function is_panel_choice_made() {
		return ! empty( $this->get_state()['panel_choice_made'] );
	}

	public function get_active_tournament_id() {
		return (int) $this->get_state()['tournament_id'];
	}

	public function set_active_tournament( $id ) {
		$state                  = $this->get_state();
		$state['tournament_id'] = (int) $id;
		$this->save_state( $state );
	}

	// -----------------------------------------------------------------
	// Pure step derivation. Zero WordPress calls, unit-tested directly by
	// tests/wizard-tests.php.
	//
	// $state keys: has_tournament (bool), rated (bool),
	// effective_ids_present (bool), settings_configured (bool, 2026-07-21),
	// tournament_configured (bool, 2026-07-21 step rename pass),
	// player_count (int), section_count (int),
	// sections_configured (bool, 2026-07-21), sections_complete (bool),
	// exported (bool, 2026-07-21 stepper hardening), locked (bool,
	// 2026-07-21 Lock/Verify rework).
	// -----------------------------------------------------------------

	public static function derive_step( array $state ) {
		$has_tournament        = ! empty( $state['has_tournament'] );
		$rated                 = ! empty( $state['rated'] );
		$player_count          = isset( $state['player_count'] ) ? (int) $state['player_count'] : 0;
		$section_count         = isset( $state['section_count'] ) ? (int) $state['section_count'] : 0;
		$settings_configured   = ! empty( $state['settings_configured'] );
		$tournament_configured = ! empty( $state['tournament_configured'] );
		$sections_configured   = ! empty( $state['sections_configured'] );
		$sections_complete     = ! empty( $state['sections_complete'] );
		$exported              = ! empty( $state['exported'] );
		$locked                = ! empty( $state['locked'] );

		if ( ! $has_tournament ) {
			$slug = 'create';
		} elseif ( $locked ) {
			// A TD's explicit lock action always wins, even over an
			// otherwise-incomplete tournament (docs/SPEC.md, 2026-07-21,
			// Lock/Verify steps): locking is a deliberate, out-of-band act
			// (WPMTM_Repository::set_tournament_locked()'s own docblock -
			// "nothing in this plugin sets or clears this flag on its
			// own"), so once it happens the guide should not keep telling
			// a TD to do something they have already declared done with.
			$slug = 'finish';
		} elseif ( ! $settings_configured ) {
			// Checked before roster/sections (docs/SPEC.md, 2026-07-21,
			// setup guide steps rework): the club-wide affiliate/chief TD
			// defaults make every later "Use default" button useful, so
			// this is worth surfacing early, but only for a tournament that
			// already exists - a brand-new install's very first prompt is
			// still "create a tournament" (the 'create' branch above), not
			// a detour through Settings before that tournament exists.
			$slug = 'settings';
		} elseif ( ! $tournament_configured ) {
			// 'tournament' (2026-07-21 step rename pass): this tournament's
			// OWN record - name, dates, the rated flag, and for a rated
			// tournament its Chief TD and affiliate. Before this step
			// existed the stepper jumped Settings straight to Roster, as
			// though a tournament configured itself, and a rated tournament
			// missing its Chief TD surfaced only as a warning banner rather
			// than as something the TD could see they still owed. This step
			// is what surfaces it now. Distinct from 'settings', which is
			// the club-wide defaults set once and reused by every tournament.
			$slug = 'tournament';
		} elseif ( 0 === $player_count ) {
			$slug = 'roster';
		} elseif ( 0 === $section_count || ! $sections_configured ) {
			// 'sections' vs 'rounds' (docs/SPEC.md, 2026-07-21): sections
			// existing with a round count set on each is a different,
			// earlier milestone than rounds actually being ENTERED
			// ($sections_complete below) - in practice a one-click import
			// creates sections and players together, so player_count and
			// section_count almost always clear the 'roster' check
			// together too, but $sections_configured (every section has
			// tot_rnds >= 1) is the real distinguishing signal: it is
			// false right after import until the TD confirms/sets each
			// section's round count, which is a genuinely separate action
			// from either importing or entering results.
			$slug = 'sections';
		} elseif ( ! $sections_complete ) {
			$slug = 'rounds';
		} elseif ( $rated && ! $exported ) {
			$slug = 'export';
		} else {
			$slug = 'finish';
		}

		// 'review' is never returned here, and is no longer a chip at all
		// (2026-07-22, owner request). It briefly was a returnable step and
		// that made it a dead end: nothing stored can prove a TD has read the
		// standings, so no action ever advanced past it. It was then demoted to
		// an advisory chip, and finally removed - its "check before you submit"
		// guidance now lives in the export step's README-linked documentation
		// (rated) and the finish step's copy (unrated), where it sits on an
		// action a TD can actually take. Reintroducing it as a hard gate would
		// require a stored "reviewed" flag set by an explicit TD click, the
		// same category of self-reported state as locking - a deliberate
		// product decision, not something to add back by accident.

		$step         = self::step_copy( $slug, $state );
		$step['slug'] = $slug;
		return $step;
	}

	/**
	 * Plain-language two-line copy for a step: "Status" (where the TD is
	 * right now) and "Next step" (the action to take, naming the exact
	 * control, plus why it matters - one sentence). Kept next to
	 * derive_step() so both are covered by the same pure unit tests.
	 * Untranslated plain PHP strings, not __() calls, matching the
	 * pure/no-WordPress-calls contract the rest of this section needs -
	 * the rendering methods below escape and print this copy but do not
	 * localize it.
	 *
	 * @param string $slug
	 * @param array  $state Same shape derive_step() takes; used only to
	 *                       interpolate the dynamic 'roster imported, N
	 *                       players in M sections' status line.
	 * @return array{title:string,status:string,next:string}
	 */
	private static function step_copy( $slug, array $state ) {
		$player_count  = isset( $state['player_count'] ) ? (int) $state['player_count'] : 0;
		$section_count = isset( $state['section_count'] ) ? (int) $state['section_count'] : 0;
		// Owner's own copy rewrite (2026-07-24): $state['rated'] is the single
		// per-tournament flag (already read at build_state()'s 'rated' key,
		// same flag settings_step_is_met() gates on) - branches here on the
		// same signal the rest of this class already trusts, even though
		// individual SECTIONS carry their own independent 'rated' column too
		// (a tournament can genuinely mix rated and unrated sections, see the
		// "RATED + UNRATED" test fixture). This copy is tournament-scoped, so
		// it follows the tournament's own flag, not a per-section one.
		$rated = ! empty( $state['rated'] );

		// Every step below carries TWO status lines, chosen by the same
		// step_is_met() signal that puts the checkmark on the chip (bug found
		// in a real browser 2026-07-22). Before this, each step had one status
		// written in the "you are here, not done yet" voice, which reads
		// correctly for the CURRENT step and is simply false for any completed
		// one: a TD clicking the ticked Roster chip was told "no players
		// imported yet" while the ticked Sections chip, in the same panel,
		// said "10 players in 2 sections". 'create' is never met and 'finish'
		// already branches on locked (its locked arm IS its done state), so
		// those two keep a single status each.
		$done = self::step_is_met( $slug, $state );

		switch ( $slug ) {
			case 'create':
				return array(
					'title'  => 'Create your tournament',
					'status' => 'Nothing is set up for this event yet.',
					'next'   => 'Use "Create tournament" on the event\'s Registrations tab, or enter players manually later during the Sections step.',
				);

			case 'settings':
				return array(
					'title'  => 'Settings',
					'status' => $done
						? 'The club-wide USCF affiliate ID and Chief TD ID are set.'
						: 'The club\'s USCF affiliate ID and Chief TD ID are not yet set.',
					'next'   => $rated
						? 'Open {settings_link} and (if you plan to run rated tournaments) at a minimum set the club\'s default USCF affiliate ID and Chief TD ID.'
						: 'Open {settings_link} and at a minimum set the club\'s default USCF affiliate ID and Chief TD ID. If you plan to run unrated tournaments, this step is not necessary.',
				);

			case 'tournament':
				return array(
					'title'  => 'Tournament',
					'status' => $done
						? 'This tournament\'s minimum required details are set.'
						: 'This tournament\'s minimum required details have not yet been set.',
					'next'   => '{edit_link} and enter the tournament name, link the calendar event, add the tournament location, and (if it\'s USCF-rated) the club affiliate and Chief TD IDs.',
				);

			case 'roster':
				return array(
					'title'  => 'Roster',
					'status' => $done
						? sprintf(
							'Roster imported, %d player%s in %d section%s.',
							$player_count,
							1 === $player_count ? '' : 's',
							$section_count,
							1 === $section_count ? '' : 's'
						)
						: 'Tournament created, but players have not yet been imported from the event\'s Registration tab.',
					'next'   => 'Import the roster. {edit_link} and upload the Pairing export CSV from the event\'s Registrations tab, or {import_link} directly on the Registrations tab.',
				);

			case 'sections':
				return array(
					'title'  => 'Sections',
					'status' => $done
						? sprintf(
							'All %d section%s have at least one round. Note that most tournaments have at least 3 rounds.',
							$section_count,
							1 === $section_count ? '' : 's'
						)
						: sprintf(
							'Roster imported, %d player%s in %d section%s, but at least one section needs at least one round.',
							$player_count,
							1 === $player_count ? '' : 's',
							$section_count,
							1 === $section_count ? '' : 's'
						),
					'next'   => '{edit_link} and set each section\'s number of rounds in the {sections_link}. Note that most tournaments have at least 3 rounds.',
				);

			case 'rounds':
				// $rounds_started (2026-07-24): whether at least one round has
				// results entered anywhere in the tournament, distinct from
				// $done ($state['sections_complete'], every round in every
				// section). Gives a TD mid-tournament a "you're underway"
				// status instead of the same "roster imported" line they saw
				// before entering anything, and points the Next step at
				// pairing/entering the NEXT round rather than repeating the
				// generic "enter each round" copy shown before any rounds
				// exist.
				$rounds_started = ! empty( $state['rounds_started'] );
				return array(
					'title'  => 'Rounds',
					'status' => $done
						? 'All rounds have been entered.'
						: ( $rounds_started
							? 'Rounds are underway.'
							: sprintf(
								'Roster imported. %d player%s in %d section%s.',
								$player_count,
								1 === $player_count ? '' : 's',
								$section_count,
								1 === $section_count ? '' : 's'
							) ),
					'next'   => ( $rounds_started && ! $done )
						? 'Open the event\'s Rounds tab to {pair_next_link} and {enter_results_link}.'
						: 'Open the event\'s Rounds tab and {rounds_link} with the pairing aid.',
				);

			case 'export':
				// Two done arms, because step_is_met() accepts either: an
				// export that was actually generated, or a lock, which settles
				// the question without one. The review guidance the old
				// standalone 'review' step carried lives in the plugin README
				// now (the panel's Documentation link), not inline here, to keep
				// this line lean (2026-07-22, owner request).
				// $rounds_complete (2026-07-24): distinct from this step's own
				// $done (exported||locked, per step_is_met() above) - an open
				// tournament where every round has been entered has finished
				// PLAYING but not necessarily exported/locked yet. Prefixed
				// onto the not-done status lines so a TD sees "the tournament
				// is finished, export is what's left" rather than the bare
				// "not downloaded yet" that reads the same whether round 1 or
				// the last round was just entered.
				$rounds_complete = ! empty( $state['sections_complete'] );
				return array(
					'title'  => 'Export',
					'status' => $done
						? ( ! empty( $state['exported'] )
							? 'The USCF export has been downloaded.'
							: ( $rated
								? 'This tournament is locked, and results can be downloaded as a USCF export. Note that club affiliate status, TD membership status, and TD safe play certificate status will be checked during export.'
								: 'This tournament is locked, but results do not need to be downloaded as a USCF export. Note that an export is not necessary for unrated tournaments, and this step can be skipped. Tournament results already appear on the Standings tab of the event.' ) )
						: ( $rated
							? ( $rounds_complete
								? 'All rounds have been entered. The USCF export has not been downloaded yet.'
								: 'The USCF export has not been downloaded yet.' )
							: ( $rounds_complete
								? 'All rounds have been entered. The USCF export has not been downloaded yet. Note that an export is not necessary for unrated tournaments, and this step can be skipped. Tournament results already appear on the Standings tab of the event.'
								: 'The USCF export has not been downloaded yet. Note that an export is not necessary for unrated tournaments, and this step can be skipped. Tournament results already appear on the Standings tab of the event.' ) ),
					// The USCF zip drops unrated sections entirely
					// (WPMTM_Export_Builder::build(), see build_csv_report()'s
					// docblock), so the validator never raises a finding for
					// one - a rated tournament's export step only ever needs
					// its RATED sections' errors cleared. The former wording
					// here ("clear any validator errors for unrated
					// sections") pointed a TD at sections that can never be
					// the cause (fixed 2026-07-24, found while checking a
					// mixed rated+unrated tournament's guide text).
					'next'   => $rated
						? '{export_link}, but first clear any validator errors. Upload the USCF export zip contents to ratings.uschess.org.'
						: '{export_link}, but first clear any validator errors if you have any rated sections. Uploading the USCF export zip to ratings.uschess.org is not necessary for unrated tournaments, and this step can be skipped.',
				);

			case 'finish':
			default:
				// Reachable as the CURRENT step only once locked, but a TD
				// can click this chip at any time to preview it, so the copy
				// has to read correctly while the tournament is still open.
				// The lock/unlock action stays clickable (owner request,
				// 2026-07-23): {lock_link}/{unlock_link} render as a nonced
				// button, same wording rated or unrated.
				if ( ! empty( $state['locked'] ) ) {
					return array(
						'title'  => 'Finish',
						'status' => 'This tournament is locked and marked as complete. A "FINAL" status is now shown on the tournament\'s event page to indicate the tournament has been concluded. Congratulations!',
						'next'   => '{unlock_link} to reopen round entry if you need to correct a result.',
					);
				}
				return array(
					'title'  => 'Finish',
					'status' => ! empty( $state['exported'] )
						? ( $rated
							? 'The USCF export has been downloaded. This tournament is still open for edits, but after any edits another USCF export is recommended.'
							: 'The USCF export has been downloaded. This tournament is still open for edits, but note that an export is not necessary for unrated tournaments, and this step can be skipped.' )
						: 'This tournament is still open for edits.',
					'next'   => 'When the tournament is over, {lock_link} to close round entry and mark the tournament as completed.',
				);
		}
	}

	/**
	 * JSON-encode the per-step data blob for the stepper's
	 * `data-wpmtm-steps-data` attribute.
	 *
	 * The JSON_HEX_* flags are load-bearing, not decoration (bug found and
	 * fixed 2026-07-21, when clicking a chip had silently done nothing for
	 * as long as the feature had existed). The copy in $steps_data has
	 * already been through esc_html(), so a step whose text contains a
	 * literal double quote - the "Pairing export" CSV, the "Lock tournament"
	 * button, the ETECF "Section" field - carries a `&quot;` entity INSIDE
	 * the JSON string. esc_attr() runs _wp_specialchars() with
	 * double_encode = false, so it deliberately leaves that existing entity
	 * alone, and the browser then decodes it back into a raw double quote
	 * that terminates the JSON string early. One corrupted entry breaks
	 * JSON.parse for the WHOLE blob, so EVERY chip click died, not just the
	 * one with the quote. Escaping & " ' < > as \uXXXX means nothing
	 * esc_attr() transforms survives into the attribute at all.
	 *
	 * Deliberately json_encode() rather than wp_json_encode(): this stays a
	 * pure method with no WordPress calls, so tests/wizard-tests.php can
	 * exercise the REAL encoding rather than a stub, which is the whole
	 * point of having a regression test for this at all. wp_json_encode()'s
	 * extras buy little here, since nothing in $steps_data is user-supplied
	 * text - it is this class's own copy, admin URLs, and integer counts -
	 * and the one thing they would catch (invalid UTF-8 making json_encode()
	 * return false) is handled explicitly below, degrading to an empty
	 * object so the chips still render and a click is merely inert, rather
	 * than to an empty attribute that would throw in JSON.parse.
	 *
	 * @param array $steps_data Keyed by step slug, as built by render_panel().
	 * @return string JSON safe to place inside an esc_attr()'d attribute.
	 */
	public static function encode_steps_data( array $steps_data ) {
		$json = json_encode( $steps_data, JSON_HEX_QUOT | JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- deliberate, see this method's docblock: it must stay WordPress-free so the pure test suite can exercise the real encoding, and the false-return case wp_json_encode() would absorb is handled on the next line.
		return false === $json ? '{}' : $json;
	}

	/**
	 * Whether the panel should open by itself for a TD who has never
	 * expressed a preference (2026-07-21).
	 *
	 * True only for the early steps. Those are where a TD is still learning
	 * the flow and the guide is worth the screen space unprompted. By the
	 * time they are entering rounds they know what they are doing and want
	 * the room back, so it stays collapsed from 'sections' onward. An
	 * explicit choice always wins over this - see render_panel(), which
	 * consults is_panel_choice_made() first.
	 *
	 * Pure, no WordPress calls; unit-tested directly by tests/wizard-tests.php.
	 *
	 * @param string $slug A step slug, including 'create' for the Add
	 *                      Tournament screen where no tournament exists yet.
	 * @return bool
	 */
	public static function should_auto_expand( $slug ) {
		return in_array( $slug, array( 'create', 'settings', 'tournament', 'roster' ), true );
	}

	/**
	 * Whether a step's OWN completion criterion is satisfied, independent of
	 * where the TD currently is in the flow.
	 *
	 * Added 2026-07-21 to replace stepper_steps()'s original position-based
	 * rule ("every chip left of the current one is complete"), which had a
	 * real bug: on the Add Tournament page the current step is 'create',
	 * which is deliberately not IN the stepper list, so array_search()
	 * returned false and the completed branch became unreachable - every
	 * chip rendered 'upcoming' and the Settings chip showed no checkmark
	 * even for a club whose settings were fully configured. Asking each step
	 * about itself fixes that case and is honest in general: club-level
	 * Settings really is complete before any tournament exists.
	 *
	 * 'export' rides on `locked`: TM knows it generated a zip, but not that
	 * USCF accepted it, so a lock settles the step when no export was produced.
	 * (The former 'review' step, removed 2026-07-22, had no criterion of its
	 * own either - nothing stored can prove a TD looked at the standings - so
	 * it was folded into 'export'/'finish' rather than kept as a dead chip.)
	 *
	 * Pure, no WordPress calls; unit-tested directly by tests/wizard-tests.php.
	 *
	 * @param string $slug
	 * @param array  $state Same shape derive_step() takes.
	 * @return bool
	 */
	public static function step_is_met( $slug, array $state ) {
		switch ( $slug ) {
			case 'settings':
				return ! empty( $state['settings_configured'] );

			case 'tournament':
				return ! empty( $state['tournament_configured'] );

			case 'roster':
				return ( isset( $state['player_count'] ) ? (int) $state['player_count'] : 0 ) > 0;

			case 'sections':
				return ( isset( $state['section_count'] ) ? (int) $state['section_count'] : 0 ) > 0
					&& ! empty( $state['sections_configured'] );

			case 'rounds':
				return ! empty( $state['sections_complete'] );

			case 'export':
				// Either an export was actually generated, or the TD locked
				// the tournament, which settles the question either way - a
				// locked tournament is not waiting on an export, whether they
				// produced one or decided not to. Without the locked arm a
				// TD who locks without exporting would be left staring at a
				// permanently blank Export chip on a finished tournament.
				return ! empty( $state['exported'] ) || ! empty( $state['locked'] );

			case 'finish':
				return ! empty( $state['locked'] );

			default:
				return false;
		}
	}

	/**
	 * A short progress indicator like "Step 5 of 8" describing the current
	 * step's position in the stepper order. Pure, no WordPress calls,
	 * matching the contract step_tip() and step_copy() document.
	 *
	 * Two cases to handle deliberately:
	 * - When every step is complete (locked tournament), return "All steps
	 *   complete" rather than "Step 9 of 8".
	 * - When the derived step is not in the stepper at all (e.g., 'create'
	 *   on the Add Tournament screen), return an empty string and have the
	 *   render site skip the indicator entirely rather than printing
	 *   something meaningless.
	 *
	 * Returns STRUCTURED data, never a built sentence, so the render site can
	 * translate with literal strings. An earlier version returned English
	 * prose and the render site called `__( $progress )` on it, which is a
	 * translation call gettext can never satisfy: strings are extracted from
	 * source at parse time, so a variable argument is never collected and the
	 * text stays untranslatable no matter how many locales are installed.
	 * Plugin Check flags it. Keep the numbers here and the wording there.
	 *
	 * @param array $state Same shape derive_step()/stepper_steps() take.
	 * @return array{position:int,total:int,complete:bool}|null null when the
	 *         derived step is not in the stepper at all, so the caller renders
	 *         nothing.
	 */
	public static function progress_label( array $state ) {
		$current_slug = self::derive_step( $state )['slug'];
		$steps        = self::stepper_steps( $state );

		// Find the position of the current step in the stepper.
		$position = null;
		foreach ( $steps as $i => $step ) {
			if ( $step['slug'] === $current_slug ) {
				$position = $i + 1; // Position is 1-indexed.
				break;
			}
		}

		// The current step is not in the stepper at all ('create', on the Add
		// Tournament screen), so there is no position to report.
		if ( null === $position ) {
			return null;
		}

		$all_complete = true;
		foreach ( $steps as $step ) {
			if ( 'completed' !== $step['status'] ) {
				$all_complete = false;
				break;
			}
		}

		return array(
			'position' => $position,
			'total'    => count( $steps ),
			'complete' => $all_complete,
		);
	}

	/**
	 * One-sentence hover-tip description of what a step is for, shown on
	 * every stepper chip (render_panel()) so a TD can preview what each
	 * step does without clicking through them. Kept next to step_copy()
	 * so both are covered by the same pure unit tests. Untranslated plain
	 * PHP string, not a __() call, matching the pure/no-WordPress-calls
	 * contract step_copy() documents above - the rendering methods below
	 * escape and print this text but do not localize it. Public, unlike
	 * step_copy(): tests/wizard-tests.php exercises every slug the
	 * stepper can emit directly by name, the same way it already does for
	 * derive_step()/stepper_steps(), rather than only indirectly through
	 * another method's return value.
	 *
	 * @param string $slug
	 * @param bool   $rated Owner copy rewrite (2026-07-24): Settings and
	 *                       Export read differently for a rated vs. unrated
	 *                       tournament (every other slug's tip is identical
	 *                       either way). Defaults true so every pre-existing
	 *                       caller/test that only ever passed $slug keeps
	 *                       seeing the rated wording, unchanged.
	 * @return string Empty string for a slug with no reviewed tip copy
	 *                 (including 'create': stepper_steps() never emits that
	 *                 slug as a chip, so it has nothing to attach a tip to
	 *                 yet - see the "Create" step chip decision, still open).
	 */
	public static function step_tip( $slug, $rated = true ) {
		switch ( $slug ) {
			case 'settings':
				return $rated
					? 'Set the club\'s USCF affiliate ID and Chief TD ID so every new tournament can reuse them.'
					: 'Set the club\'s USCF affiliate ID and Chief TD ID so every new tournament can reuse them. Optional for unrated tournaments.';

			case 'tournament':
				return 'Name this tournament, set its dates and location, and whether it\'s USCF-rated.';

			case 'roster':
				return 'Import players from the linked calendar event\'s registrations, or upload a pairing CSV.';

			case 'sections':
				return 'Confirm each section\'s name and set each section\'s number of rounds.';

			case 'rounds':
				return 'Pair each round using the pairing aid, and enter round results.';

			case 'export':
				return $rated
					? 'Download the USCF zip and upload its contents at ratings.uschess.org to get the tournament rated.'
					: 'Download the USCF zip. Optional for unrated tournaments, but useful for importing results into other tournament software.';

			case 'finish':
				return 'Lock this tournament to mark it as complete and to prevent further edits.';

			default:
				return '';
		}
	}

	/**
	 * The compact horizontal stepper (SPEC section 1): Settings -> Tournament
	 * -> Roster -> Sections -> Rounds -> Export -> Finish for a rated
	 * tournament, or the same without Export for an unrated one (docs/SPEC.md,
	 * 2026-07-21 setup guide steps rework; 2026-07-22 Review removal). 'Finish'
	 * is the terminal milestone, tracked off the tournament's own `locked`
	 * flag rather than a step no state ever confirms. "Create your tournament"
	 * is never in this list - the panel this feeds only ever renders for an
	 * existing tournament, so it is done by definition. 'Review' is no longer
	 * a chip (2026-07-22): see the note in the body below. Pure, no WordPress
	 * calls; unit-tested directly by tests/wizard-tests.php.
	 *
	 * @param array $state Same shape derive_step() takes.
	 * @return array<int, array{slug:string,label:string,status:string}> status is 'completed'|'current'|'upcoming'.
	 */
	public static function stepper_steps( array $state ) {
		$rated = ! empty( $state['rated'] );

		// 'export' appears ONLY for a rated tournament - an unrated one has
		// nothing to submit, so the chip would be permanently dead weight.
		// This single conditional is the whole of how the stepper differs by
		// rated status: 7 chips rated, 6 unrated, every other chip identical
		// in both.
		//
		// 'review' was removed as a standalone chip (2026-07-22, owner
		// request): it never had a completion signal of its own (it rode on
		// $sections_complete, same as 'rounds') and overlapped 'export' on a
		// rated tournament. Its "check the standings and wall chart before you
		// finish" guidance is now folded into the 'export' step's copy for a
		// rated tournament and into the 'finish' step's copy for an unrated
		// one, where it sits on an action a TD can actually take.
		$order = $rated
			? array( 'settings', 'tournament', 'roster', 'sections', 'rounds', 'export', 'finish' )
			: array( 'settings', 'tournament', 'roster', 'sections', 'rounds', 'finish' );

		$labels = array(
			'settings'   => 'Settings',
			'tournament' => 'Tournament',
			'roster'     => 'Roster',
			'sections'   => 'Sections',
			'rounds'     => 'Rounds',
			'export'     => 'Export',
			'finish'     => 'Finish',
		);

		$current = self::derive_step( $state )['slug'];

		$steps = array();
		foreach ( $order as $slug ) {
			// 'completed' is checked BEFORE 'current' (2026-07-21, stepper
			// hardening). The other way round, a finished tournament could
			// never show a full row of checkmarks: derive_step() returns
			// 'finish' for a locked tournament, so the final chip rendered
			// 'current' forever and the all-done state was unreachable by
			// construction. The only slug that can be met AND current is
			// 'finish' on a locked tournament, which is genuinely done, so
			// preferring 'completed' cannot hide an action a TD still owes.
			if ( self::step_is_met( $slug, $state ) ) {
				$status = 'completed';
			} elseif ( $slug === $current ) {
				$status = 'current';
			} else {
				$status = 'upcoming';
			}
			$steps[] = array(
				'slug'   => $slug,
				'label'  => $labels[ $slug ],
				'status' => $status,
			);
		}
		return $steps;
	}

	// -----------------------------------------------------------------
	// WordPress glue: gather real data, render the panel, card, or offer.
	// -----------------------------------------------------------------

	public function render_notices() {
		if ( ! current_user_can( WPMTM_CAPABILITY ) ) {
			return;
		}
		if ( ! isset( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page detection, same pattern as WPMTM_Admin::enqueue_assets().
			return;
		}
		$page = sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page detection.
		if ( 0 !== strpos( $page, 'wpmtm' ) ) {
			return;
		}
		// Owner request (2026-07-22): the setup guide notice is gone from
		// the Tournaments list page ('wpmtm'), the same way it was already
		// gone from Settings - this method now returns for every wpmtm
		// admin page and never renders anything. The header "Show setup
		// guide" button (WPMTM_Admin::render_tournaments_list() ->
		// render_show_guide_button()) is untouched: it is not
		// tournament-specific and still opens the guide, it just no longer
		// has a notice card to show it inline on this page - the panel on
		// the tournament edit page (render_panel()) is where it lands.
		// 'wpmtm-edit': unaffected either way, since render_panel() already
		// replaced the card there before this change.
		return;
	}

	/**
	 * Falls back to the stored tournament id (set by render_panel() when a
	 * TD views a tournament's edit page), then to null. The GET 'id'
	 * adoption this used to do lived here only because the old notice card
	 * also rendered on the edit page; now that render_panel() is the one
	 * that sees that GET param, it is the one that calls
	 * set_active_tournament() instead (see render_panel() below).
	 *
	 * @return object|null
	 */
	protected function resolve_active_tournament() {
		$stored_id = $this->get_active_tournament_id();
		if ( $stored_id ) {
			$tournament = WPMTM_Repository::get_tournament( $stored_id );
			if ( $tournament ) {
				return $tournament;
			}
		}

		return null;
	}

	/**
	 * Builds the derive_step()/stepper_steps() input array from real
	 * tournament/section/player data.
	 *
	 * @param object|null $tournament
	 * @return array
	 */
	protected function build_state( $tournament ) {
		if ( ! $tournament ) {
			// settings_configured is computed even with no tournament (fixed
			// 2026-07-21): it is a club-level fact, knowable on the Add
			// Tournament screen, and omitting it made the Settings chip
			// render without its checkmark there for a club that had in fact
			// finished setting up. Everything else genuinely is unknown until
			// a tournament exists.
			$opts = WPMTM_Plugin::instance()->get_opts();
			return array(
				'has_tournament'        => false,
				'rated'                 => false,
				'effective_ids_present' => false,
				'settings_configured'   => '' !== trim( (string) $opts['affiliate_id'] ) && '' !== trim( (string) $opts['chief_td_id'] ),
				'tournament_configured' => false,
				'player_count'          => 0,
				'section_count'         => 0,
				'sections_configured'   => false,
				'sections_complete'     => false,
				'rounds_started'        => false,
				'exported'              => false,
				'locked'                => false,
			);
		}

		$opts     = WPMTM_Plugin::instance()->get_opts();
		$sections = WPMTM_Repository::get_sections( $tournament->id );

		// Two aggregate queries total, regardless of section count (docs/SPEC.md,
		// "Decisions (2026-07-16, wizard N+1 queries)") - this method is
		// hooked to admin_notices and runs on every wpmtm admin page load
		// while guided setup is active, so the old per-section count_players()
		// + rounds_with_results() pair (2N queries) was a real cost on a
		// multi-section tournament.
		$section_ids   = wp_list_pluck( $sections, 'id' );
		$player_counts = WPMTM_Repository::player_counts_by_section( $tournament->id );
		$rounds_map    = WPMTM_Repository::rounds_with_results_by_sections( $section_ids );

		$player_count        = 0;
		$sections_complete   = ! empty( $sections );
		$rounds_started      = false;
		// 2026-07-21: whether EVERY section has a round count set, distinct
		// from $sections_complete (whether every ROUND has been ENTERED) -
		// see derive_step()'s 'sections' vs 'rounds' branch docblock.
		$sections_configured = ! empty( $sections );
		foreach ( $sections as $section ) {
			$sid           = (int) $section->id;
			$player_count += isset( $player_counts[ $sid ] ) ? $player_counts[ $sid ] : 0;
			$done_rounds   = isset( $rounds_map[ $sid ] ) ? count( $rounds_map[ $sid ] ) : 0;
			$tot_rnds      = (int) $section->tot_rnds;
			if ( $done_rounds > 0 ) {
				$rounds_started = true;
			}
			if ( $tot_rnds < 1 ) {
				$sections_configured = false;
			}
			// A section with no planned rounds (tot_rnds < 1) is unconfigured,
			// not complete: a Swiss section imports with tot_rnds 0 until the
			// TD sets a round count, and treating 0 as "done" was sending the
			// guide straight to the done/export step for a tournament that
			// still needs pairing (docs/SPEC.md, 2026-07-17).
			if ( $tot_rnds < 1 || $done_rounds < $tot_rnds ) {
				$sections_complete = false;
			}
		}

		// affiliate is genuinely club-wide and stays a Settings-level value;
		// the chief TD is not - no Settings fallback (docs/SPEC.md,
		// 2026-07-17, "TD default removal"): a rated tournament needs its
		// OWN chief TD id, since that is what the export and the validator
		// now actually check.
		$affiliate_present = '' !== trim( (string) $opts['affiliate_id'] );
		$head_td_present   = '' !== trim( (string) $tournament->head_td_id );

		// 'settings_configured' (docs/SPEC.md, 2026-07-21, setup guide steps
		// rework): the club-wide defaults a TD sets once, not per
		// tournament. timectl_presets is deliberately NOT part of this -
		// WPMTM_Plugin's own defaults ("G/30;d0\nG/25;+5") ship non-empty,
		// so checking it would always read as "configured" regardless of
		// whether the TD ever touched Settings. assistant_td_id is also
		// deliberately excluded - many clubs never have an assistant TD, so
		// requiring it would make this step permanently unfinishable for
		// them. affiliate_id + chief_td_id are the two fields that start
		// genuinely empty and that every other per-tournament "Use default"
		// button depends on.
		$settings_configured = $affiliate_present && '' !== trim( (string) $opts['chief_td_id'] );

		// 'tournament_configured' (2026-07-21): this tournament's own record
		// is sufficiently filled in. Name and begin date are the universal
		// minimum. A RATED tournament additionally needs the two IDs USCF
		// reads off a submission - its own Chief TD and an effective
		// affiliate - the same pair that used to surface only as a warning
		// banner, now expressed as a step the TD can see and complete
		// instead. An unrated tournament needs neither, so it clears this
		// step as soon as it has a name and a date.
		$tournament_configured = '' !== trim( (string) $tournament->name )
			&& '' !== trim( (string) $tournament->begin_date )
			&& ( ! $tournament->rated || ( $affiliate_present && $head_td_present ) );

		return array(
			'has_tournament'        => true,
			'rated'                 => (bool) $tournament->rated,
			'effective_ids_present' => $affiliate_present && $head_td_present,
			'settings_configured'   => $settings_configured,
			'tournament_configured' => $tournament_configured,
			'player_count'          => $player_count,
			'section_count'         => count( $sections ),
			'sections_configured'   => $sections_configured,
			'sections_complete'     => $sections_complete,
			'rounds_started'        => $rounds_started,
			'exported'              => WPMTM_Admin_Export::has_exported( (int) $tournament->id ),
			'locked'                => (bool) $tournament->locked,
		);
	}

	/**
	 * Short "what's next" label + link for a single tournament, for the
	 * Tournaments list page's Status column (2026-07-24, owner request:
	 * the column previously just echoed the DB 'status' value, which is
	 * stamped 'setup' once at creation and never updated again - every
	 * tournament read "Setup" forever, regardless of its real state).
	 * Reuses this same class's derive_step()/build_state() so the list
	 * page and the per-tournament setup guide panel always agree.
	 *
	 * Capped to non-locked tournaments (owner decision, 2026-07-24): a
	 * locked tournament always resolves to the same fixed label with no
	 * link and no query cost, since there is deliberately nothing further
	 * for a TD to do with it (WPMTM_Repository::set_tournament_locked()'s
	 * own docblock - "nothing in this plugin sets or clears this flag on
	 * its own"). build_state() alone costs ~3 queries (get_sections() +
	 * two aggregate counts) per call, so skipping it for every already-
	 * locked tournament is real savings on a list page that calls this
	 * once per row.
	 *
	 * @param object $tournament WPMTM_Repository::tournaments_with_counts()'s row.
	 * @return array{label:string,url:string} $url is '' for the locked case (no link).
	 */
	public function list_status_for( $tournament ) {
		if ( ! empty( $tournament->locked ) ) {
			return array(
				'label' => __( 'Locked and marked as complete', 'wp-tournament-manager' ),
				'url'   => '',
			);
		}

		$state    = $this->build_state( $tournament );
		$slug     = self::derive_step( $state )['slug'];
		$edit_url = add_query_arg( array( 'page' => 'wpmtm-edit', 'id' => (int) $tournament->id ), admin_url( 'admin.php' ) );

		switch ( $slug ) {
			case 'settings':
				return array(
					'label' => __( 'Club settings incomplete', 'wp-tournament-manager' ),
					'url'   => admin_url( 'admin.php?page=wpmtm-settings' ),
				);

			case 'tournament':
				return array(
					'label' => __( 'Tournament settings incomplete', 'wp-tournament-manager' ),
					'url'   => $edit_url,
				);

			case 'roster':
				return array(
					'label' => __( 'Roster not yet imported', 'wp-tournament-manager' ),
					'url'   => $this->get_event_url( $tournament, '#tab-registrations' ),
				);

			case 'sections':
				return array(
					'label' => __( 'Section rounds not yet set', 'wp-tournament-manager' ),
					'url'   => $edit_url . '#wpmtm-sections',
				);

			case 'rounds':
				return array(
					'label' => __( 'Rounds not yet all entered', 'wp-tournament-manager' ),
					'url'   => $this->get_event_url( $tournament, '#tab-round-entry' ),
				);

			case 'export':
				return array(
					'label' => __( 'USCF export not yet downloaded', 'wp-tournament-manager' ),
					'url'   => $edit_url . '#wpmtm-export',
				);

			case 'finish':
			default:
				return array(
					'label' => __( 'Ready to lock and mark as complete', 'wp-tournament-manager' ),
					'url'   => $edit_url,
				);
		}
	}

	/**
	 * The linked event's permalink plus a `#tab-{id}` anchor, or '' when no
	 * event is linked or its permalink cannot be resolved. Used by
	 * format_next() for the {import_link}/{event_link} tokens, so the
	 * permalink-building logic lives in exactly one place (docs/SPEC.md,
	 * 2026-07-17, "setup guide text audit").
	 *
	 * @param object|null $tournament
	 * @param string      $anchor e.g. '#tab-registrations' or '#tab-round-entry'.
	 * @return string
	 */
	protected function get_event_url( $tournament, $anchor ) {
		if ( ! $tournament || ! $tournament->event_post_id ) {
			return '';
		}
		$permalink = get_permalink( (int) $tournament->event_post_id );
		return $permalink ? $permalink . $anchor : '';
	}

	/**
	 * The CTA URL for a given step slug: the exact page/anchor the copy's
	 * "next" line points at.
	 */
	/**
	 * Turn a step's "next" copy into safe HTML, substituting inline-link
	 * tokens for real anchors. `{import_link}` points at the linked event's
	 * Registrations tab (where ETR's "Import to Tournament Manager" button
	 * lives), `{edit_link}` at this tournament's edit page, `{event_link}`
	 * at the linked event's Round entry tab, `{export_link}` at the edit
	 * page's export box, and `{add_new_link}` at the "Add New" tournament
	 * screen (always buildable, since it needs no tournament). A token whose
	 * URL cannot be built (no linked event, no tournament) degrades to its
	 * plain label text rather than a dead link.
	 *
	 * The whole string is esc_html()'d first, which leaves the `{token}`
	 * markers intact (braces are not HTML-special), then each marker is
	 * replaced with an anchor built from esc_url() + a static esc_html()
	 * label. No caller-supplied text ever reaches the output unescaped, so
	 * callers echo the result directly.
	 *
	 * @param string     $next       The step's "next" copy, possibly with tokens.
	 * @param object|null $tournament The tournament row, or null.
	 * @return string Safe HTML.
	 */
	protected function format_next( $next, $tournament ) {
		$html = esc_html( $next );

		$edit_url = $tournament
			? add_query_arg( array( 'page' => 'wpmtm-edit', 'id' => (int) $tournament->id ), admin_url( 'admin.php' ) )
			: '';

		$tokens = array(
			// {import_link} points at the linked event's Registrations tab,
			// where wp-etr's "Create tournament" button lives (renamed from
			// "Import to Tournament Manager", 2026-07-22).
			'{import_link}'    => array( 'label' => __( 'Create tournament', 'wp-tournament-manager' ), 'url' => $this->get_event_url( $tournament, '#tab-registrations' ) ),
			// Sentence case throughout (2026-07-24, owner request): only the
			// first word of each link label is capitalized.
			'{edit_link}'      => array( 'label' => __( 'Edit this tournament', 'wp-tournament-manager' ), 'url' => $edit_url ),
			'{event_link}'     => array( 'label' => __( 'Linked event', 'wp-tournament-manager' ), 'url' => $this->get_event_url( $tournament, '#tab-round-entry' ) ),
			// Mid-sentence phrases (lowercase, like {sections_link}), all
			// pointing at the same Rounds tab as {event_link} - added
			// 2026-07-24 so the 'rounds' step's "next" copy links the action
			// phrase itself ("enter each round" / "pair the next round" /
			// "enter round results") instead of a generic "Linked event".
			'{rounds_link}'    => array( 'label' => __( 'enter each round', 'wp-tournament-manager' ), 'url' => $this->get_event_url( $tournament, '#tab-round-entry' ) ),
			'{pair_next_link}' => array( 'label' => __( 'pair the next round', 'wp-tournament-manager' ), 'url' => $this->get_event_url( $tournament, '#tab-round-entry' ) ),
			'{enter_results_link}' => array( 'label' => __( 'enter round results', 'wp-tournament-manager' ), 'url' => $this->get_event_url( $tournament, '#tab-round-entry' ) ),
			'{export_link}'    => array( 'label' => __( 'Download USCF export', 'wp-tournament-manager' ), 'url' => $edit_url ? $edit_url . '#wpmtm-export' : '' ),
			// Mid-sentence phrase, deliberately lowercase (unlike the other
			// tokens above, which are sentence-case standalone link labels).
			'{sections_link}'  => array( 'label' => __( 'sections editor', 'wp-tournament-manager' ), 'url' => $edit_url ? $edit_url . '#wpmtm-sections' : '' ),
			'{add_new_link}'   => array( 'label' => __( 'Add new', 'wp-tournament-manager' ), 'url' => admin_url( 'admin.php?page=wpmtm-edit' ) ),
			'{settings_link}'  => array( 'label' => __( 'Tournament Manager settings', 'wp-tournament-manager' ), 'url' => admin_url( 'admin.php?page=wpmtm-settings' ) ),
		);

		foreach ( $tokens as $token => $link ) {
			$replacement = $link['url']
				? '<a href="' . esc_url( $link['url'] ) . '">' . esc_html( $link['label'] ) . '</a>'
				: esc_html( $link['label'] );
			$html = str_replace( $token, $replacement, $html );
		}

		// {lock_link} / {unlock_link}: NOT plain <a> links. Locking/unlocking
		// changes state, so - like the edit page's own Lock control
		// (WPMTM_Admin::render_lock_control()) - these render as a real nonced
		// POST form to admin-post.php, never a GET link a prefetch/crawler
		// could follow. The unlock form carries data-wpmtm-confirm so
		// wpmtm-admin.js's submit handler asks before reopening a finished
		// tournament (owner request, 2026-07-22). Degrade to plain label text
		// when no tournament exists (the finish copy is still fed through here
		// for the steps-data blob on the Add Tournament screen).
		$html = str_replace(
			array( '{lock_link}', '{unlock_link}' ),
			array(
				$this->lock_form_token( $tournament, false, __( 'Lock the tournament', 'wp-tournament-manager' ) ),
				$this->lock_form_token( $tournament, true, __( 'Unlock the tournament', 'wp-tournament-manager' ) ),
			),
			$html
		);

		return $html;
	}

	/**
	 * A link-styled, nonced POST form that toggles this tournament's lock,
	 * substituted for the {lock_link}/{unlock_link} tokens by format_next().
	 * Reuses the exact action name, nonce action, and field names the edit
	 * page's Lock control and the front-end command row already POST, so all
	 * three share one handler (WPMTM_Admin::handle_toggle_lock()).
	 *
	 * @param object|null $tournament
	 * @param bool        $confirm Whether to attach a confirm dialog (unlock).
	 * @param string      $label   Link text.
	 * @return string Safe HTML, or the plain escaped label when no tournament.
	 */
	protected function lock_form_token( $tournament, $confirm, $label ) {
		if ( ! $tournament ) {
			return esc_html( $label );
		}
		// The button and its form are SIBLINGS, not nested (2026-07-23): the
		// button sits inline in the sentence and reaches its form by the HTML5
		// `form=` attribute, while the real <form> is rendered hidden right
		// after it. An inline <form> wrapped around the button used to swallow
		// the space before it on the front-end theme ("...is over,Lock the
		// tournament"); a bare inline <button> keeps the normal word spacing.
		// The confirm dialog stays on the form so wpmtm-admin.js's submit
		// handler (which reads data-wpmtm-confirm off the submitted form) still
		// fires - a button[form=x] click submits form x and dispatches its
		// submit event as usual.
		$form_id      = 'wpmtm-lockform-' . ( $confirm ? 'unlock-' : 'lock-' ) . (int) $tournament->id;
		$confirm_attr = $confirm
			? ' data-wpmtm-confirm="' . esc_attr__( 'Unlock this tournament so results can be edited again?', 'wp-tournament-manager' ) . '"'
			: '';
		return '<button type="submit" form="' . esc_attr( $form_id ) . '" class="wpmtm-linkbtn">' . esc_html( $label ) . '</button>'
			. '<form id="' . esc_attr( $form_id ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wpmtm-lockform" hidden' . $confirm_attr . '>'
			. wp_nonce_field( 'wpmtm_toggle_lock_' . (int) $tournament->id, 'wpmtm_toggle_lock_nonce', true, false )
			. '<input type="hidden" name="action" value="wpmtm_toggle_lock">'
			. '<input type="hidden" name="tournament_id" value="' . esc_attr( (int) $tournament->id ) . '">'
			. '</form>';
	}

	// The trailing per-step CTA button (get_cta_url()/get_cta_label()) was
	// removed 2026-07-23 (owner request): every step's copy already carries
	// its own in-sentence link, so a separate button just duplicated it. The
	// contextual-help info icon (readme_anchor()) is the only trailing action
	// on the Next step line now.

	/**
	 * The stepper panel (SPEC section 1): renders ABOVE the tournament
	 * fields on the edit page, called directly from
	 * WPMTM_Admin::render_tournament_edit(). Also rendered on the "Add
	 * Tournament" screen (2026-07-21) with a null $tournament: build_state(
	 * null) already returns has_tournament => false, so derive_step() lands
	 * on 'create' and stepper_steps() shows every step 'upcoming' (none of
	 * the real step slugs ever equal 'create') - an honest "nothing exists
	 * yet, save this form first" display rather than a hidden panel.
	 * Collapsed by default, remembering each TD's open/closed choice.
	 *
	 * Also adopts $tournament as the "tournament in view" (the same job
	 * resolve_active_tournament()'s GET-id adoption used to do from the
	 * old notice card on this page), so a TD who later opens Settings
	 * still sees a notice card that talks about this tournament. Skipped
	 * entirely when $tournament is null - there is nothing to adopt yet.
	 *
	 * @param object|null $tournament
	 */
	public function render_panel( $tournament ) {
		if ( $tournament ) {
			$this->set_active_tournament( (int) $tournament->id );
		}

		$state  = $this->build_state( $tournament );
		$step   = self::derive_step( $state );
		$steps  = self::stepper_steps( $state );
		// An explicit choice always wins. Auto-expansion applies only until
		// the TD has opened or closed the panel themselves even once.
		$open   = $this->is_panel_choice_made()
			? $this->is_panel_open()
			: self::should_auto_expand( $step['slug'] );

		// 2026-07-21 (setup guide steps rework): every step's own copy,
		// keyed by slug, so a step click can show ITS OWN guidance
		// without a new request - assets/wpmtm-admin.js reads this JSON
		// blob and swaps the Status/Next text + the contextual-help anchor
		// client-side. next_html is already fully escaped by format_next()
		// (see that method's own docblock), safe to set as innerHTML by the
		// JS reading it. 'helpUrl' is the README popup deep-linked to this
		// step's own section (2026-07-23); the trailing per-step CTA button
		// was removed the same day (owner request) - every step's copy
		// already carries its own in-sentence link.
		// Raw (entity-decoded) base URL for the JSON blob: this value is
		// assigned straight to element.href by assets/wpmtm-admin.js on a chip
		// click, and a property assignment does NOT decode HTML entities - so a
		// wp_nonce_url()-escaped "&#038;" would corrupt the _wpnonce parameter.
		// The server-rendered current-step href below keeps the escaped form,
		// which the browser decodes normally when parsing the attribute.
		$readme_base = html_entity_decode( $this->readme_url(), ENT_QUOTES );
		$steps_data  = array();
		foreach ( $steps as $s ) {
			$copy                     = self::step_copy( $s['slug'], $state );
			$steps_data[ $s['slug'] ] = array(
				'status'   => $copy['status'],
				'nextHtml' => $this->format_next( $copy['next'], $tournament ),
				'helpUrl'  => $readme_base . '#' . self::readme_anchor( $s['slug'] ),
			);
		}
		?>
		<?php
		// "no-print" (2026-07-22): on an event page this panel renders INSIDE
		// wp-etr's tab panel, and the Standings/Wall chart Print button clones
		// that whole panel (assets/wpmtm-frontend.js's printScoped()), so
		// without this class the entire setup guide - every step, its tip, and
		// the "(completed)" screen reader text - printed above the standings.
		// The class is the established convention for both halves of the
		// problem: openPrintWindow() strips it from the clone, and
		// wpmtm-frontend.css hides it in @media print. Inert in wp-admin,
		// which never loads that stylesheet.
		?>
		<details class="wpmtm-setup-guide-panel no-print" data-wpmtm-setup-guide-panel data-nonce="<?php echo esc_attr( wp_create_nonce( 'wpmtm_toggle_setup_guide_panel' ) ); ?>"<?php echo $open ? ' open' : ''; ?>>
			<summary class="wpmtm-setup-guide-summary">
				<span class="wpmtm-setup-guide-title"><?php esc_html_e( 'Setup guide', 'wp-tournament-manager' ); ?></span>
				<?php
				// encode_steps_data() carries the JSON_HEX_* escaping that
				// keeps this attribute parseable in the browser - see that
				// method's docblock for the bug it fixes and why the flags
				// are not optional.
				$steps_data_json = self::encode_steps_data( $steps_data );
				?>
				<ol class="wpmtm-setup-guide-stepper" data-wpmtm-steps-data="<?php echo esc_attr( $steps_data_json ); ?>" data-wpmtm-current-step="<?php echo esc_attr( $step['slug'] ); ?>">
					<?php foreach ( $steps as $s ) : ?>
						<?php $tip = self::step_tip( $s['slug'], ! empty( $state['rated'] ) ); ?>
						<li>
							<span
								class="wpmtm-setup-guide-step wpmtm-setup-guide-step--<?php echo esc_attr( $s['status'] ); ?>"
								data-wpmtm-step-slug="<?php echo esc_attr( $s['slug'] ); ?>"
								role="button"
								tabindex="0"
								title="<?php echo esc_attr( $tip ); ?>"
							><?php echo esc_html( $s['label'] ); ?><?php if ( 'completed' === $s['status'] ) : ?> <span aria-hidden="true">&#9745;&#65039;</span><span class="screen-reader-text"><?php esc_html_e( '(completed)', 'wp-tournament-manager' ); ?></span><?php endif; ?><?php if ( '' !== $tip ) : ?> <span class="screen-reader-text"><?php echo esc_html( $tip ); ?></span><?php endif; ?></span>
						</li>
					<?php endforeach; ?>
				</ol>
				<?php
				// The "Step N of N" / "All steps complete" progress counter was
				// removed here (2026-07-23, owner request): both read as
				// redundant with the highlighted current step chip and the
				// per-step completed checkmarks already in the stepper above,
				// so the header shows no separate progress text now.
				?>
				<span class="wpmtm-setup-guide-marker" aria-hidden="true"></span>
			</summary>
			<?php
			// These two lines are <div>, not <p> (2026-07-23): the Next step
			// copy can contain an inline <form> (the {lock_link}/{unlock_link}
			// lock button), and <form> is one of the start tags that auto-close
			// an open <p> in the HTML parser - which orphaned the text after
			// the button onto its own line, the phantom duplicate the owner
			// reported. A <div> is legal around a <form>. It also sidesteps the
			// front-end theme's own `p { font-size }` rule, so the small text
			// size is now uniform on the event page too.
			$help_url = $this->readme_url() . '#' . self::readme_anchor( $step['slug'] );
			?>
			<div class="wpmtm-setup-guide-body">
				<div class="wpmtm-setup-guide-line" data-wpmtm-step-title><strong><?php esc_html_e( 'Status:', 'wp-tournament-manager' ); ?></strong> <span data-wpmtm-step-status><?php echo esc_html( $step['status'] ); ?></span></div>
				<div class="wpmtm-setup-guide-line"><strong><?php esc_html_e( 'Next step:', 'wp-tournament-manager' ); ?></strong> <span data-wpmtm-step-next><?php echo $this->format_next( $step['next'], $tournament ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- format_next() returns HTML that is fully escaped internally (esc_html on the copy, esc_url + esc_html on the link tokens). ?></span><a class="wpmtm-setup-guide-help" href="<?php echo esc_url( $help_url ); ?>" data-wpmtm-readme target="_blank" rel="noopener" title="<?php esc_attr_e( 'Open the documentation for this step', 'wp-tournament-manager' ); ?>"><span aria-hidden="true">&#8505;&#65039;</span><span class="screen-reader-text"><?php esc_html_e( 'Open the documentation for this step', 'wp-tournament-manager' ); ?></span></a></div>
			</div>
		</details>
		<?php
	}

	/**
	 * Setup guide panel on a front-end event page, for a TD who lacks
	 * wp-admin access (docs/SPEC.md, 2026-07-21) - reuses render_panel()
	 * verbatim, since nothing in it is actually wp-admin-specific
	 * (admin_url() just builds a URL string, usable from anywhere; the
	 * AJAX open/closed toggle already works from any context admin-ajax.php
	 * is reachable from). Capability-gated the same way
	 * WPMTM_Frontend_Public::render_td_command_row() is - never shown to
	 * the public.
	 *
	 * Guarded against rendering more than once per request. As of 2026-07-22
	 * the callers are WPMTM_Frontend::render_event_setup_guide() (the
	 * 'etr_before_tabs' slot, when wp-etr renders tabs) and
	 * WPMTM_Frontend::build_block() (the no-tabs fallback). Only one of those
	 * fires per request, but the guard is kept so a future second caller, or
	 * a theme that runs the content filter twice, cannot double the panel.
	 *
	 * @param object $tournament
	 */
	public function render_panel_for_event( $tournament ) {
		if ( ! $tournament || ! WPMTM_Roles::user_can_manage_tournament( $tournament ) || $this->event_panel_rendered ) {
			return;
		}
		$this->event_panel_rendered = true;
		$this->render_panel( $tournament );
	}

	/**
	 * "Show setup guide" / "Exit setup guide" button (SPEC section 4,
	 * 2026-07-21: label and target now follow is_active(), matching the
	 * admin bar node below - previously this always read "Show setup
	 * guide" and linked show_url() even while the guide was already open).
	 * Callable from WPMTM_Settings::render_settings_page(),
	 * WPMTM_Admin::render_tournaments_list(), and
	 * WPMTM_Admin::render_tournament_form(), so all three share the same
	 * URL-building and label rather than each re-deriving it. Restored on
	 * the Add/Edit Tournament screen (2026-07-24, reversing 2026-07-22):
	 * that page's panel now honors is_active() too (render_tournament_form()),
	 * so this is the only way back once a TD exits from there.
	 *
	 * @param string $class CSS class(es) for the link. Every current caller
	 *                       passes 'page-title-action' (position
	 *                       consistency, 2026-07-21: the button now sits
	 *                       right after that page's own H1 on every TM
	 *                       admin page - Settings and Edit Tournament used
	 *                       to place it lower, next to Lock/Unlock, with
	 *                       the plain 'button' style). 'button' remains the
	 *                       default only as a fallback style for a future
	 *                       caller that is not H1-adjacent.
	 */
	public function render_show_guide_button( $class = 'button' ) {
		$active = $this->is_active();
		printf(
			'<a href="%1$s" class="%2$s">%3$s</a>',
			esc_url( $active ? $this->stop_url() : $this->show_url() ),
			esc_attr( $class ),
			$active
				? esc_html__( 'Exit setup guide', 'wp-tournament-manager' )
				: esc_html__( 'Show setup guide', 'wp-tournament-manager' )
		);
	}

	/**
	 * Confirmation notice for the "Show setup guide" button/admin-bar node.
	 * Both post to the admin_post_wpmtm_wizard 'show' action
	 * (handle_action() above), which redirects back to the referring page
	 * with SHOWN_QUERY_ARG on success - otherwise a click just reloads the
	 * page with no sign anything happened. Shared (2026-07-24, was
	 * WPMTM_Settings::render_guide_shown_notice()) so every landing page a
	 * "show" redirect can return to - Settings, the Tournaments list, and
	 * the tournament edit page - shows the same confirmation instead of
	 * only the one page that happened to define it. Dismissible, and
	 * read-only: presence of the arg only changes what is displayed, not
	 * any stored state.
	 */
	public function render_guide_shown_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag from a redirect, not a state change; handle_action() already nonce-checked the action that set it.
		if ( empty( $_GET[ self::SHOWN_QUERY_ARG ] ) ) {
			return;
		}
		$list_link = '<a href="' . esc_url( admin_url( 'admin.php?page=wpmtm' ) ) . '">' . esc_html__( 'All Tournaments', 'wp-tournament-manager' ) . '</a>';
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			sprintf(
				/* translators: %s: link text "All Tournaments", pointing at the tournaments list page */
				esc_html__( 'Setup guide enabled and now visible on the %s page and on event pages.', 'wp-tournament-manager' ),
				$list_link // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_url()/esc_html(), safe HTML substituted into the already-escaped %s placeholder above, same pattern as WPMTM_Wizard::format_next().
			)
		);
	}

	// -----------------------------------------------------------------
	// Start/stop/show: one nonced admin_post action, param 'do'. The
	// notice-card ('render_card()') and Tournaments-list offer notice
	// ('render_offer()') this URL-building once served are gone
	// (2026-07-22, owner request) - both admin_notices renders are, so
	// only the panel (render_panel()) and the header button/admin bar
	// node still use start_url()/stop_url()/show_url() below.
	// -----------------------------------------------------------------

	protected function build_action_url( $do ) {
		$url = add_query_arg(
			array(
				'action' => 'wpmtm_wizard',
				'do'     => $do,
			),
			admin_url( 'admin-post.php' )
		);
		return wp_nonce_url( $url, 'wpmtm_wizard_' . $do );
	}

	/**
	 * The nonced admin_post URL that starts the setup guide. Public so the
	 * admin bar node below shares the same URL-building logic as the
	 * admin_post_wpmtm_wizard handler, rather than re-deriving it.
	 */
	public function start_url() {
		return $this->build_action_url( 'start' );
	}

	/**
	 * The nonced admin_post URL that stops (exits) the setup guide. See
	 * start_url() above.
	 */
	public function stop_url() {
		return $this->build_action_url( 'stop' );
	}

	/** The nonced admin_post URL behind "Show setup guide" (see show() above). */
	public function show_url() {
		return $this->build_action_url( 'show' );
	}

	/**
	 * Nonced admin_post URL that renders the plugin README in a standalone
	 * page, for the setup guide's "Documentation" popup. See
	 * render_readme_page().
	 */
	public function readme_url() {
		return wp_nonce_url(
			add_query_arg( 'action', 'wpmtm_readme', admin_url( 'admin-post.php' ) ),
			'wpmtm_readme'
		);
	}

	/**
	 * The README fragment id a given step's contextual-help (info) icon deep
	 * links to (2026-07-23). Matches the slugified heading render_markdown()
	 * emits for that step's section in README.md - the section headings are
	 * titled "{Step} step" (e.g. "Settings step"), which slugifies to
	 * "{slug}-step". 'create' has no chip but is handled so the pre-tournament
	 * panel's icon still lands somewhere sensible.
	 *
	 * @param string $slug
	 * @return string URL fragment (no leading '#').
	 */
	public static function readme_anchor( $slug ) {
		return sanitize_title( $slug ) . '-step';
	}

	/**
	 * Outputs the plugin README.md as a small standalone HTML page (its own
	 * <html> document), opened by the setup guide's "Documentation" link in a
	 * 920x740 popup window (assets/wpmtm-admin.js) sized to match the
	 * Standings/Wall chart print windows. Capability- and nonce-gated. The
	 * README is the plugin's own bundled file, not user input, but its text is
	 * still escaped and run through a small, deliberately limited Markdown
	 * renderer (render_markdown()) rather than echoed raw.
	 */
	public function render_readme_page() {
		if ( ! current_user_can( WPMTM_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this.', 'wp-tournament-manager' ) );
		}
		check_admin_referer( 'wpmtm_readme' );

		$path = WPMTM_PLUGIN_DIR . 'README.md';
		$md   = is_readable( $path ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a bundled plugin file from disk, not a remote URL.
		$body = '' !== $md
			? $this->render_markdown( $md )
			: '<p>' . esc_html__( 'The documentation file could not be read.', 'wp-tournament-manager' ) . '</p>';

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( str_replace( '_', '-', get_locale() ) ); ?>">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php esc_html_e( 'Tournament Manager documentation', 'wp-tournament-manager' ); ?></title>
	<style>
		body { margin: 0; padding: 24px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; font-size: 14px; line-height: 1.6; color: #1d2327; background: #fff; }
		.wpmtm-doc { max-width: 760px; margin: 0 auto; }
		.wpmtm-doc h1 { font-size: 22px; margin: 0 0 16px; }
		.wpmtm-doc h2 { font-size: 18px; margin: 28px 0 10px; border-bottom: 1px solid #e0e0e0; padding-bottom: 4px; }
		.wpmtm-doc h3 { font-size: 15px; margin: 20px 0 8px; }
		.wpmtm-doc p { margin: 0 0 12px; }
		.wpmtm-doc ul, .wpmtm-doc ol { margin: 0 0 12px; padding-left: 24px; }
		.wpmtm-doc li { margin: 0 0 4px; }
		.wpmtm-doc code { background: #f0f0f1; padding: 1px 5px; border-radius: 3px; font-size: 0.92em; }
		.wpmtm-doc pre { background: #f6f7f7; padding: 12px; border-radius: 4px; overflow-x: auto; }
		.wpmtm-doc pre code { background: none; padding: 0; }
		.wpmtm-doc a { color: #2271b1; }
		.wpmtm-doc hr { border: 0; border-top: 1px solid #e0e0e0; margin: 24px 0; }
	</style>
</head>
<body>
	<div class="wpmtm-doc">
		<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_markdown() escapes all text via esc_html() before emitting a fixed, closed set of tags; see that method. ?>
	</div>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * A deliberately small Markdown-to-HTML renderer for the bundled README,
	 * NOT a general-purpose one. Every line of text is esc_html()'d first, so
	 * the only HTML that ever reaches output is the fixed tag set this method
	 * emits (headings, paragraphs, lists, code, links, hr). Handles the subset
	 * the README actually uses: ATX headings, fenced code blocks, unordered
	 * and ordered lists, horizontal rules, inline code, bold, italic, and
	 * links. Anything else degrades to escaped plain text in a paragraph.
	 *
	 * @param string $md Raw Markdown.
	 * @return string HTML.
	 */
	protected function render_markdown( $md ) {
		$lines = preg_split( '/\r\n|\r|\n/', $md );
		$html  = '';
		$in_code = false;
		$list_type = ''; // '', 'ul', or 'ol'.

		$close_list = function () use ( &$html, &$list_type ) {
			if ( '' !== $list_type ) {
				$html .= '</' . $list_type . ">\n";
				$list_type = '';
			}
		};

		foreach ( $lines as $line ) {
			// Fenced code block toggle.
			if ( preg_match( '/^```/', $line ) ) {
				$close_list();
				if ( $in_code ) {
					$html   .= "</code></pre>\n";
					$in_code = false;
				} else {
					$html   .= '<pre><code>';
					$in_code = true;
				}
				continue;
			}
			if ( $in_code ) {
				$html .= esc_html( $line ) . "\n";
				continue;
			}

			// Horizontal rule.
			if ( preg_match( '/^\s*([-*_])(\s*\1){2,}\s*$/', $line ) ) {
				$close_list();
				$html .= "<hr>\n";
				continue;
			}

			// Headings. A slugified id is emitted so the setup guide's
			// contextual-help icon can deep-link to a step's own section
			// (readme_anchor()); sanitize_title() matches the fragment that
			// helper builds.
			if ( preg_match( '/^(#{1,6})\s+(.*)$/', $line, $m ) ) {
				$close_list();
				$level = min( 6, strlen( $m[1] ) );
				$id    = sanitize_title( $m[2] );
				$html .= '<h' . $level . ' id="' . esc_attr( $id ) . '">' . $this->render_inline( $m[2] ) . '</h' . $level . ">\n";
				continue;
			}

			// Unordered list item.
			if ( preg_match( '/^\s*[-*+]\s+(.*)$/', $line, $m ) ) {
				if ( 'ul' !== $list_type ) {
					$close_list();
					$html     .= "<ul>\n";
					$list_type = 'ul';
				}
				$html .= '<li>' . $this->render_inline( $m[1] ) . "</li>\n";
				continue;
			}

			// Ordered list item.
			if ( preg_match( '/^\s*\d+[.)]\s+(.*)$/', $line, $m ) ) {
				if ( 'ol' !== $list_type ) {
					$close_list();
					$html     .= "<ol>\n";
					$list_type = 'ol';
				}
				$html .= '<li>' . $this->render_inline( $m[1] ) . "</li>\n";
				continue;
			}

			// Blank line ends any list; paragraph break otherwise.
			if ( '' === trim( $line ) ) {
				$close_list();
				continue;
			}

			$close_list();
			$html .= '<p>' . $this->render_inline( $line ) . "</p>\n";
		}

		if ( $in_code ) {
			$html .= "</code></pre>\n";
		}
		$close_list();

		return $html;
	}

	/**
	 * Inline Markdown (bold, italic, code, links) on a single line. Escapes
	 * first, then applies formatting on the escaped text; the link regex reads
	 * a plain URL and runs it through esc_url(), so nothing unescaped ever
	 * reaches output.
	 *
	 * @param string $text
	 * @return string HTML.
	 */
	protected function render_inline( $text ) {
		$text = esc_html( $text );
		// Inline code first, so ** or _ inside backticks is left literal.
		$text = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $text );
		// Links [label](url). esc_url() the captured URL defensively.
		$text = preg_replace_callback(
			'/\[([^\]]+)\]\(([^)\s]+)\)/',
			function ( $m ) {
				return '<a href="' . esc_url( $m[2] ) . '" target="_blank" rel="noopener">' . $m[1] . '</a>';
			},
			$text
		);
		$text = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text );
		$text = preg_replace( '/(^|[^*])\*([^*]+)\*/', '$1<em>$2</em>', $text );
		return $text;
	}

	public function handle_action() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce is verified on the next line via check_admin_referer(), which needs $do to build the expected action name first.
		$do = isset( $_REQUEST['do'] ) ? sanitize_key( wp_unslash( $_REQUEST['do'] ) ) : '';
		if ( ! in_array( $do, array( 'start', 'stop', 'show' ), true ) ) {
			wp_die( esc_html__( 'Unknown setup guide action.', 'wp-tournament-manager' ) );
		}
		check_admin_referer( 'wpmtm_wizard_' . $do );
		if ( ! current_user_can( WPMTM_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'wp-tournament-manager' ) );
		}

		switch ( $do ) {
			case 'start':
				$this->start();
				wp_safe_redirect( admin_url( 'admin.php?page=wpmtm-edit' ) );
				exit;

			case 'stop':
				$this->stop();
				wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=wpmtm' ) );
				exit;

			case 'show':
				$this->show();
				$redirect = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=wpmtm' );
				wp_safe_redirect( add_query_arg( self::SHOWN_QUERY_ARG, '1', $redirect ) );
				exit;
		}
	}

	/**
	 * AJAX action 'wpmtm_toggle_setup_guide_panel': persists the panel's
	 * open/closed state as the TD toggles the native <details> element
	 * (assets/wpmtm-admin.js). Fire-and-forget from the browser's
	 * perspective - the <details> element has already toggled itself
	 * natively by the time this fires, so the response body carries
	 * nothing the page needs.
	 */
	public function ajax_toggle_panel() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'wpmtm_toggle_setup_guide_panel' ) ) {
			wp_send_json_error( array(), 403 );
		}
		if ( ! current_user_can( WPMTM_CAPABILITY ) ) {
			wp_send_json_error( array(), 403 );
		}
		$this->set_panel_open( ! empty( $_POST['open'] ) );
		wp_send_json_success();
	}

}
