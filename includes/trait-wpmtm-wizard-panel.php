<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Setup-guide panel rendering and its data-gathering, extracted verbatim
 * from WPMTM_Wizard (2026-07-29 segmentation). Instance methods that use
 * the wizard state; composed into WPMTM_Wizard via a use statement, so every
 * call site (WPMTM_Admin, WPMTM_Frontend classes) reaches them unchanged.
 * Split out only to shrink the wizard file; behavior is identical.
 */
trait WPMTM_Wizard_Panel {
	// -----------------------------------------------------------------
	// WordPress glue: gather real data, render the panel, card, or offer.
	// -----------------------------------------------------------------

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
		// Two different questions, so two different maps since "Save pairings"
		// began writing result-less game rows. $rounds_map answers "has this
		// section been touched at all", which is what $rounds_started below
		// needs. Completeness has to ask the stricter question, or a fully
		// paired but entirely unplayed event would report itself finished.
		$scored_map    = WPMTM_Repository::rounds_fully_scored_by_sections( $section_ids );

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
			// Shared with the two front-end renderers since audit item 54; the
			// "tot_rnds < 1 is unconfigured, not complete" caveat that used to
			// be spelled out here now lives in that method's docblock.
			if ( ! WPMTM_Round_Selector::section_complete( $tot_rnds, isset( $scored_map[ $sid ] ) ? $scored_map[ $sid ] : array() ) ) {
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
	 * its own"). build_state() alone costs 4 queries (get_sections() plus
	 * three aggregate reads - player_counts_by_section(),
	 * rounds_with_results_by_sections(), rounds_fully_scored_by_sections(),
	 * the last added when "Save pairings" started writing result-less game
	 * rows, audit item 59) per call, so skipping it for every already-
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
		$edit_url = self::tournament_edit_url( $tournament );

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
	/**
	 * The wp-admin edit-page URL for a tournament (page=wpmtm-edit&id=N).
	 * Returns '' for a null tournament so callers can substitute a blank into
	 * a link token without a ternary of their own.
	 *
	 * @param object|null $tournament
	 * @return string
	 */
	protected static function tournament_edit_url( $tournament ) {
		if ( ! $tournament ) {
			return '';
		}
		return add_query_arg( array( 'page' => 'wpmtm-edit', 'id' => (int) $tournament->id ), admin_url( 'admin.php' ) );
	}

	protected function format_next( $next, $tournament ) {
		$html = esc_html( $next );

		$edit_url = self::tournament_edit_url( $tournament );

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
			// Mid-sentence phrase (lowercase): a blank new post for a
			// tournament recap write-up. Plain new-post admin screen; the TD
			// picks the post type and writes it up.
			'{recap_link}'     => array( 'label' => __( 'draft a tournament recap post', 'wp-tournament-manager' ), 'url' => admin_url( 'post-new.php' ) ),
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
			. '<input type="hidden" name="wpmtm_return_hash" value="">'
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
	 * Audit item 39 (carried over as item 5 from the 2026-07-29 audit): this
	 * used to rely entirely on its callers for the ownership gate. Both real
	 * callers do check - render_panel_for_event() above, and
	 * WPMTM_Admin::render_tournament_edit() before it reaches
	 * render_tournament_form() - but the panel names the tournament and links
	 * to its edit screen, so the gate belongs here too, where a future third
	 * caller cannot forget it. A null $tournament (the Add Tournament screen)
	 * is unaffected: there is no tournament to be authorized against yet.
	 *
	 * @param object|null $tournament
	 */
	public function render_panel( $tournament ) {
		if ( $tournament && ! WPMTM_Roles::user_can_manage_tournament( $tournament ) ) {
			return;
		}

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
}
