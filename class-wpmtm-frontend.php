<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Front-end orchestrator for the results/standings display plus the TD's
 * round-entry panel, rendered on the linked TEC event page (docs/SPEC.md,
 * "Results entry lives on the event page itself"). Every visitor sees the
 * standings block; users who can WPMTM_CAPABILITY also see a pairing-aid +
 * round-entry panel below it, the same "editor tools inline" pattern ETR
 * uses for its Registrations tab.
 *
 * This class only owns the render entry points (the_content filter, the TEC
 * template fallback, the did-render flag, and [wpmtm_standings] shortcode
 * registration) plus build_block(), which orchestrates one event's block
 * and delegates the two halves of it:
 *   - WPMTM_Frontend_Public (includes/class-wpmtm-frontend-public.php):
 *     the public standings + wall chart every visitor sees.
 *   - WPMTM_Frontend_TD (includes/class-wpmtm-frontend-td.php): the TD's
 *     pairing aid + round-entry panel + its admin-post save handler.
 * Split out of what used to be a single ~1100-line class, the same way the
 * admin side is split into WPMTM_Admin / WPMTM_Admin_Import /
 * WPMTM_Admin_Export. Both classes are singletons instantiated from this
 * class's constructor below, which is also where WPMTM_Frontend_TD's
 * admin-post hooks end up registered (its own constructor adds them, the
 * same pattern WPMTM_Admin_Import and WPMTM_Admin_Export use for their own
 * hooks).
 *
 * Renders through two hooks so the block still appears on themes where
 * the_content never fires for the event body:
 *   - the_content (priority 25), guarded to the singular tribe_events main
 *     query loop.
 *   - action 'tribe_template_after_include:events/v2/single/content'
 *     (priority 15) and 'tribe_template_after_include:events/single-event/content'
 *     (priority 15), echoing the same block.
 * A single did-render flag (per request) makes sure only one of the two
 * ever actually outputs the block, and a marker string inside the block
 * itself stops the_content from appending a second copy if it somehow runs
 * more than once in the same request.
 *
 * wp-etr tabs (docs/SPEC.md, "Decisions (2026-07-11, event-page tabs)"):
 * when wp-etr 5.2.4+ is active and renders a tab UI for the event, this
 * class's filter_etr_event_tabs() (hooked to wp-etr's 'etr_event_tabs'
 * filter) supplies "Standings" / "Wall chart" / "Round entry" tabs instead
 * of the inline block above, and sets $rendered_this_request so the two
 * hooks in the previous paragraph become no-ops for that request. This only
 * works because wp-etr calls that filter from its own the_content filter
 * (priority 20) and its own TEC-template action (default priority 10) -
 * both of which fire before this class's equivalents above, given the
 * priority bump noted there - so filter_etr_event_tabs() always runs first
 * and $rendered_this_request is already true by the time either of this
 * class's own hooks checks it. When wp-etr is inactive, or active but never
 * actually builds a tab UI for this event (its own per-event Registrations
 * toggle off, or the request never reaches a singular tribe_events page),
 * 'etr_event_tabs' never fires at all and the inline block above remains
 * the fallback, exactly as before wp-etr tab support existed.
 *
 * Uses WPMTM_Admin_Shared only for its notice pipeline (set_notice /
 * render_notices), called from build_block() below before either delegate
 * renders anything, so a notice set by WPMTM_Frontend_TD::handle_save_round()
 * on the previous request/redirect always appears above both halves of the
 * block. require_capability() from that trait is admin-oriented (it
 * wp_die()s on failure) and is not used here at all - a missing capability
 * on the public render path just means "the TD panel is not shown", not a
 * hard stop; WPMTM_Frontend_TD::handle_save_round() does its own
 * current_user_can() check before writing anything.
 */
class WPMTM_Frontend {

	use WPMTM_Admin_Shared;

	const CONTENT_MARKER = 'wpmtm-results-block';

	private static $instance = null;

	private $rendered_this_request = false;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		WPMTM_Frontend_Public::instance();
		WPMTM_Frontend_TD::instance();

		// Priority 25, not wp-etr's the_content priority (20): guarantees
		// wp-etr's inject_tabs() - and the 'etr_event_tabs' filter it calls
		// synchronously - always runs first when wp-etr is active, so
		// filter_etr_event_tabs() below has already set
		// $rendered_this_request by the time this callback checks it. See
		// the class docblock's "wp-etr tabs" paragraph.
		add_filter( 'the_content', array( $this, 'filter_the_content' ), 25 );
		// TEC fires different template hooks depending on whether the event
		// renders through the classic single template, the v2 views, or the
		// block template; the did-render flag makes registering on both safe.
		// Priority 15, after wp-etr's render_tabs_fallback() (default
		// priority 10 on the same 'events/single-event/content' hook) for
		// the same ordering reason as the_content above.
		add_action( 'tribe_template_after_include:events/v2/single/content', array( $this, 'render_after_tec_template' ), 15 );
		add_action( 'tribe_template_after_include:events/single-event/content', array( $this, 'render_after_tec_template' ), 15 );

		// wp-etr 5.2.4+ tab extensibility filter (present on both its
		// the_content and TEC-template render paths); a no-op registration
		// when wp-etr is inactive or older, since the filter tag is then
		// never invoked. See filter_etr_event_tabs() below.
		add_filter( 'etr_event_tabs', array( $this, 'filter_etr_event_tabs' ), 10, 2 );

		add_shortcode( 'wpmtm_standings', array( $this, 'render_standings_shortcode' ) );
	}

	// -----------------------------------------------------------------
	// Rendering entry points.
	// -----------------------------------------------------------------

	public function filter_the_content( $content ) {
		if ( $this->rendered_this_request ) {
			return $content;
		}
		if ( ! is_singular( 'tribe_events' ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		if ( false !== strpos( $content, self::CONTENT_MARKER ) ) {
			return $content; // re-entrancy guard: already appended once.
		}

		$block = $this->build_block( get_the_ID() );
		if ( '' === $block ) {
			return $content;
		}

		$this->rendered_this_request = true;
		return $content . $block;
	}

	/**
	 * Fallback for themes that never run the event body through
	 * the_content (some TEC v2 single-event templates render the
	 * description directly). Guarded by the same $rendered_this_request
	 * flag as filter_the_content() so the block never appears twice.
	 */
	public function render_after_tec_template() {
		if ( $this->rendered_this_request ) {
			return;
		}
		if ( ! is_singular( 'tribe_events' ) ) {
			return;
		}

		$block = $this->build_block( get_the_ID() );
		if ( '' === $block ) {
			return;
		}

		$this->rendered_this_request = true;
		echo $block; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $block is assembled entirely from escaped fragments in build_block() and the render_* methods in WPMTM_Frontend_Public / WPMTM_Frontend_TD.
	}

	/**
	 * Builds the full results/standings (+ TD panel, if applicable) HTML
	 * for one event post, or '' if the event has no linked tournament.
	 */
	protected function build_block( $event_post_id ) {
		$tournament = WPMTM_Repository::get_tournament_by_event( $event_post_id );
		if ( ! $tournament ) {
			return '';
		}

		$can_manage = current_user_can( WPMTM_CAPABILITY );

		// Every visitor sees the standings/wall chart, so assets/wpmtm-
		// frontend.css/.js (the Wall chart tab's Print button among other
		// things - see WPMTM_Frontend_Public::enqueue_frontend_assets()'s
		// docblock) load unconditionally here, not only when $can_manage
		// also pulls in the TD panel below.
		WPMTM_Frontend_Public::instance()->enqueue_frontend_assets();

		ob_start();

		echo '<div class="' . esc_attr( self::CONTENT_MARKER ) . '">';
		$this->render_notices();
		WPMTM_Frontend_Public::instance()->render_public_block( $tournament );

		if ( $can_manage ) {
			( ! defined( 'DONOTCACHEPAGE' ) ) && define( 'DONOTCACHEPAGE', true );
			WPMTM_Frontend_TD::instance()->render_td_block( $tournament );
		}

		echo '</div>';

		return ob_get_clean();
	}

	// -----------------------------------------------------------------
	// wp-etr tab extensibility ('etr_event_tabs' filter).
	// -----------------------------------------------------------------

	/**
	 * Hooked to wp-etr's 'etr_event_tabs' filter (wp-etr's
	 * includes/class-etr-plugin.php, inject_tabs() / render_tabs_fallback()):
	 * when the current event has a linked tournament, appends "Standings"
	 * and "Wall chart" tabs (every visitor) and, only for users who can
	 * WPMTM_CAPABILITY, a "Round entry" tab carrying
	 * WPMTM_Frontend_TD::render_td_block()'s output. Naming follows the
	 * owner's rationale (docs/SPEC.md, "Decisions (2026-07-11, event-page
	 * tabs)"): "Tournament results" implied the event had ended, but
	 * standings are live during play, so the public tab is "Standings"
	 * instead; the wall chart earns its own tab rather than sitting
	 * collapsed under the standings table.
	 *
	 * Sets $rendered_this_request true so filter_the_content() and
	 * render_after_tec_template() do not also append the old inline block
	 * further down the same request - see the class docblock's "wp-etr
	 * tabs" paragraph for why the priorities on those two hooks guarantee
	 * this callback always runs first when wp-etr is active.
	 *
	 * DONOTCACHEPAGE is defined whenever the "Round entry" tab is added
	 * (i.e. whenever the current user can WPMTM_CAPABILITY), the same
	 * condition build_block() above uses for the inline TD panel - a page
	 * cache must never store an editor-only view and serve it to a visitor.
	 *
	 * @param array $tabs     Tab definitions so far: array( 'id', 'label', 'html' ).
	 * @param int   $event_id The event (tribe_events) post ID.
	 * @return array
	 */
	public function filter_etr_event_tabs( $tabs, $event_id ) {
		$tabs = is_array( $tabs ) ? $tabs : array();

		$tournament = WPMTM_Repository::get_tournament_by_event( (int) $event_id );
		if ( ! $tournament ) {
			return $tabs;
		}

		$this->rendered_this_request = true;

		// See build_block()'s equivalent call above: every visitor gets the
		// Standings + Wall chart tabs, so these assets load unconditionally,
		// not only when the "Round entry" tab further down also needs them.
		WPMTM_Frontend_Public::instance()->enqueue_frontend_assets();

		ob_start();
		WPMTM_Frontend_Public::instance()->render_standings_only( $tournament );
		$tabs[] = array(
			'id'    => 'standings',
			'label' => __( 'Standings', 'wp-tournament-manager' ),
			'html'  => ob_get_clean(),
		);

		ob_start();
		WPMTM_Frontend_Public::instance()->render_wall_chart_only( $tournament );
		$tabs[] = array(
			'id'    => 'wall-chart',
			'label' => __( 'Wall chart', 'wp-tournament-manager' ),
			'html'  => ob_get_clean(),
		);

		if ( current_user_can( WPMTM_CAPABILITY ) ) {
			( ! defined( 'DONOTCACHEPAGE' ) ) && define( 'DONOTCACHEPAGE', true );

			ob_start();
			$this->render_notices();
			WPMTM_Frontend_TD::instance()->render_td_block( $tournament );
			$tabs[] = array(
				'id'    => 'round-entry',
				'label' => __( 'Round entry', 'wp-tournament-manager' ),
				'html'  => ob_get_clean(),
			);
		}

		return $tabs;
	}

	// -----------------------------------------------------------------
	// Shortcode: [wpmtm_standings] (public standings only, anywhere).
	// -----------------------------------------------------------------

	/**
	 * Renders the same public standings block build_block() puts on the
	 * event page, so it can also be placed via [wpmtm_standings] on any
	 * other page or post. Attribute "tournament" (numeric tournament id)
	 * resolves explicitly; otherwise falls back to the tournament linked
	 * to the current post, exactly like the event-page render path.
	 * Returns '' when no tournament resolves, so the shortcode is silently
	 * absent rather than showing an error to visitors.
	 *
	 * Never renders the TD panel and never defines DONOTCACHEPAGE - this
	 * is public data only, on a page render_td_block() has no business
	 * touching.
	 *
	 * Known cache limitation: WPMTM_Cache::flush_event_page() only flushes
	 * the tournament's linked event page (docs/SPEC.md, "Decisions
	 * (2026-07-09, tiebreaks)"). A shortcode placed on some other page is
	 * not covered by that flush, so a page cache serving that other page
	 * can keep showing stale standings until its own cache entry expires
	 * on its own schedule. The event page remains the canonical live view;
	 * treat a shortcode elsewhere as a convenience mirror, not a
	 * guaranteed-fresh copy.
	 *
	 * @param array $atts Shortcode attributes; only "tournament" is read.
	 * @return string
	 */
	public function render_standings_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'tournament' => 0 ), $atts, 'wpmtm_standings' );

		$tournament_id = absint( $atts['tournament'] );
		if ( $tournament_id ) {
			$tournament = WPMTM_Repository::get_tournament( $tournament_id );
		} else {
			$tournament = WPMTM_Repository::get_tournament_by_event( get_the_ID() );
		}

		if ( ! $tournament ) {
			return '';
		}

		ob_start();
		WPMTM_Frontend_Public::instance()->render_public_block( $tournament );
		return ob_get_clean();
	}
}
