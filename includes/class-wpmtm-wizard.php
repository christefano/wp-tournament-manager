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

	// Split across trait files (2026-07-29 segmentation) to keep this file
	// small; each is composed in verbatim, so every method stays reachable as
	// WPMTM_Wizard::method() exactly as before:
	//   - WPMTM_Wizard_Steps: pure static step derivation (tests/wizard-tests.php).
	//   - WPMTM_Wizard_Panel:  panel data-gathering and rendering.
	//   - WPMTM_Wizard_Readme: the standalone README popup page + Markdown.
	use WPMTM_Wizard_Steps;
	use WPMTM_Wizard_Panel;
	use WPMTM_Wizard_Readme;

	private static $instance = null;

	/**
	 * Guards render_panel_for_event() against rendering more than once per
	 * request - see that method's own docblock.
	 *
	 * @var bool
	 */
	private $event_panel_rendered = false;

	const META_KEY = 'wpmtm_wizard';

	// Keys inside the per-user state array stored under META_KEY. Constants so
	// a typo is a fatal, not a silently-missed preference. The string VALUES
	// are the persisted meta keys and must never change (existing user_meta
	// relies on them); only the code references go through the constants.
	const STATE_ACTIVE            = 'active';
	const STATE_TOURNAMENT_ID     = 'tournament_id';
	const STATE_PANEL_OPEN        = 'panel_open';
	const STATE_PANEL_CHOICE_MADE = 'panel_choice_made';

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
			self::STATE_ACTIVE        => true,
			self::STATE_TOURNAMENT_ID => 0,
			// Per-TD collapsed/open choice for the tournament edit page's
			// stepper panel (docs/SPEC.md, "Design (2026-07-16, setup guide
			// redesign)", section 1). One flag per TD across every
			// tournament, not per-tournament - simplest state that still
			// satisfies "remembering each TD's open/closed choice", and no
			// schema change.
			self::STATE_PANEL_OPEN => false,
			// Whether this TD has ever opened or closed the panel themselves
			// (2026-07-21, auto-expand). Without it, "never touched it" and
			// "deliberately collapsed it" are indistinguishable, since
			// panel_open defaults to false for both - and auto-expanding on
			// that ambiguity would reopen the panel on every load for
			// someone who keeps closing it, which is worse than the problem
			// auto-expand solves. Set by set_panel_open(), which only ever
			// runs from the TD's own toggle.
			self::STATE_PANEL_CHOICE_MADE => false,
		);
		return is_array( $meta ) ? wp_parse_args( $meta, $defaults ) : $defaults;
	}

	protected function save_state( array $state ) {
		update_user_meta( get_current_user_id(), self::META_KEY, $state );
	}

	public function is_active() {
		return ! empty( $this->get_state()[ self::STATE_ACTIVE ] );
	}

	public function is_panel_open() {
		return ! empty( $this->get_state()[ self::STATE_PANEL_OPEN ] );
	}

	public function start() {
		$state                  = $this->get_state();
		$state[ self::STATE_ACTIVE ]        = true;
		$state[ self::STATE_TOURNAMENT_ID ] = 0;
		$this->save_state( $state );
	}

	public function stop() {
		$state           = $this->get_state();
		$state[ self::STATE_ACTIVE ] = false;
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
		$state[ self::STATE_ACTIVE ] = true;
		$this->save_state( $state );
	}

	public function set_panel_open( $open ) {
		$state                      = $this->get_state();
		$state[ self::STATE_PANEL_OPEN ]        = (bool) $open;
		// Only ever reached from the TD's own toggle, so this is the moment
		// their preference becomes explicit and auto-expand stops applying.
		$state[ self::STATE_PANEL_CHOICE_MADE ] = true;
		$this->save_state( $state );
	}

	/** Whether this TD has ever opened or closed the panel themselves. */
	public function is_panel_choice_made() {
		return ! empty( $this->get_state()[ self::STATE_PANEL_CHOICE_MADE ] );
	}

	public function get_active_tournament_id() {
		return (int) $this->get_state()[ self::STATE_TOURNAMENT_ID ];
	}

	public function set_active_tournament( $id ) {
		$state                  = $this->get_state();
		$state[ self::STATE_TOURNAMENT_ID ] = (int) $id;
		$this->save_state( $state );
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

	public function handle_action() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce is verified on the next line via check_admin_referer(), which needs $do to build the expected action name first.
		$do = isset( $_REQUEST['do'] ) ? sanitize_key( wp_unslash( $_REQUEST['do'] ) ) : '';
		if ( ! in_array( $do, array( 'start', 'stop', 'show' ), true ) ) {
			wp_die( esc_html__( 'Unknown setup guide action.', 'wp-tournament-manager' ) );
		}
		check_admin_referer( 'wpmtm_wizard_' . $do );
		if ( ! current_user_can( WPMTM_CAPABILITY ) ) {
			wp_die( esc_html__( 'No permission to perform this action.', 'wp-tournament-manager' ) );
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
