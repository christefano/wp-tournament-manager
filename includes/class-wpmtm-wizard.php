<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Guided TD walkthrough (docs/SPEC.md, "Decisions (2026-07-11, guided
 * walkthrough implemented)"): a non-blocking, per-user wizard that shows a
 * card on every Tournament Manager admin page telling the TD what they just
 * did, what to click next, and why it matters.
 *
 * Deliberately derive-from-state rather than a stored step counter: the
 * step is recomputed from the real tournament/section/player data on every
 * request via derive_step(), so a TD who does something out of order (or
 * skips ahead using the normal UI) is never stuck on a stale step. Only
 * three small facts survive between requests, in per-user meta (so two TDs
 * running two tournaments at once never collide): whether the guide is
 * active, which tournament it is following, and whether the TD dismissed
 * the one-line "start guided setup" offer on the list page.
 *
 * derive_step() itself is a pure static method with no WordPress calls -
 * it is the unit-tested core (tests/wizard-tests.php) - so the steps'
 * copy is plain, untranslated PHP strings rather than __() calls; the
 * rendering methods below escape and print that copy, but do not localize
 * it, the same trade-off the pure step logic requires.
 *
 * A first "access" step gates the flow (7 steps instead of the usual 6)
 * until the site has answered, once, whether to create the
 * 'wpmtm_tournament_manager' role (WPMTM_Roles) so a TD can be granted
 * access without becoming a full administrator; see the 'role_decided'
 * state key on derive_step() and the set_access case in handle_action().
 */
class WPMTM_Wizard {

	private static $instance = null;

	const META_KEY = 'wpmtm_wizard';

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
		add_action( 'admin_post_wpmtm_wizard', array( $this, 'handle_action' ) );
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_node' ), 91 );
	}

	// -----------------------------------------------------------------
	// Per-user state (user meta 'wpmtm_wizard'). No step number is ever
	// stored - see the class docblock.
	// -----------------------------------------------------------------

	protected function get_state() {
		$meta     = get_user_meta( get_current_user_id(), self::META_KEY, true );
		$defaults = array(
			'active'          => false,
			'tournament_id'   => 0,
			'offer_dismissed' => false,
		);
		return is_array( $meta ) ? wp_parse_args( $meta, $defaults ) : $defaults;
	}

	protected function save_state( array $state ) {
		update_user_meta( get_current_user_id(), self::META_KEY, $state );
	}

	public function is_active() {
		return ! empty( $this->get_state()['active'] );
	}

	public function is_offer_dismissed() {
		return ! empty( $this->get_state()['offer_dismissed'] );
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

	public function dismiss_offer() {
		$state                    = $this->get_state();
		$state['offer_dismissed'] = true;
		$this->save_state( $state );
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
	// effective_ids_present (bool), player_count (int),
	// sections_complete (bool), role_decided (bool).
	//
	// role_decided reflects whether the site has ever answered the
	// "create a Tournament Manager role?" offer (option
	// 'wpmtm_role_decision' not ''; see WPMTM_Roles). While undecided and
	// no tournament exists yet, the flow gates on a new 'access' step
	// ahead of 'create' and the total step count grows from 6 to 7. A
	// TD who already has a tournament in progress when this flag is
	// still unset (e.g. an existing install upgrading to this feature)
	// is not yanked back to 'access' - the normal step is shown, just
	// renumbered for the 7-step total.
	// -----------------------------------------------------------------

	public static function derive_step( array $state ) {
		$has_tournament        = ! empty( $state['has_tournament'] );
		$rated                 = ! empty( $state['rated'] );
		$effective_ids_present = ! empty( $state['effective_ids_present'] );
		$player_count          = isset( $state['player_count'] ) ? (int) $state['player_count'] : 0;
		$sections_complete     = ! empty( $state['sections_complete'] );
		$role_decided          = ! empty( $state['role_decided'] );

		if ( ! $has_tournament ) {
			$slug = 'create';
		} elseif ( $rated && ! $effective_ids_present ) {
			$slug = 'settings';
		} elseif ( 0 === $player_count ) {
			$slug = 'import';
		} elseif ( ! $sections_complete ) {
			$slug = 'rounds';
		} elseif ( $rated && $sections_complete ) {
			$slug = 'export';
		} else {
			$slug = 'done';
		}

		if ( ! $role_decided && 'create' === $slug ) {
			$slug = 'access';
		}

		$copy         = self::step_copy();
		$step         = $copy[ $slug ];
		$step['slug'] = $slug;

		if ( $role_decided ) {
			$step['total'] = 6;
		} else {
			$step['total'] = 7;
			if ( 'access' !== $slug ) {
				$step['index'] += 1;
			}
		}

		return $step;
	}

	/**
	 * Plain-language copy for every step in the TD lifecycle: what the TD
	 * just did, what to do next (naming the exact control), and why it
	 * matters. Kept here, next to derive_step(), so both are covered by the
	 * same pure unit tests.
	 */
	private static function step_copy() {
		return array(
			'access'   => array(
				'index' => 1,
				'title' => 'Set up access',
				'did'   => 'You started guided setup.',
				'next'  => 'Choose whether to create a Tournament Manager role so you can give volunteers TD access without making them site administrators.',
				'why'   => 'A dedicated role keeps tournament tools available to your TDs without handing out full admin rights.',
			),
			'create'   => array(
				'index' => 1,
				'title' => 'Create your tournament',
				'did'   => 'Nothing is set up for this event yet.',
				'next'  => 'Click "Add New" to create a tournament, or use "Import to Tournament Manager" on the event\'s Registrations tab to create one automatically.',
				'why'   => 'Every other step in this guide needs a tournament to attach to.',
			),
			'settings' => array(
				'index' => 2,
				'title' => 'Enter affiliate and TD IDs',
				'did'   => 'Tournament created.',
				'next'  => 'Open Settings and enter the USCF affiliate ID and the Chief TD USCF ID, or set them on this tournament directly.',
				'why'   => 'A rated tournament cannot export to USCF without these IDs.',
			),
			'import'   => array(
				'index' => 3,
				'title' => 'Import the roster',
				'did'   => 'Affiliate and TD IDs are in place.',
				'next'  => 'Open the tournament and use "Import Registrations" to pull the roster from the linked event.',
				'why'   => 'Round entry and standings both need a roster of players to work with.',
			),
			'rounds'   => array(
				'index' => 4,
				'title' => 'Enter round results',
				'did'   => 'Roster imported.',
				'next'  => 'Open the linked event page and enter each round with the pairing aid.',
				'why'   => 'Results drive the standings and the rated export.',
			),
			'export'   => array(
				'index' => 5,
				'title' => 'Export to USCF',
				'did'   => 'All rounds are entered.',
				'next'  => 'Open the export box on the tournament edit page, clear any validator errors, and download the USCF zip.',
				'why'   => 'You upload that zip at ratings.uschess.org to get the event rated.',
			),
			'done'     => array(
				'index' => 6,
				'title' => 'All done',
				'did'   => 'All rounds are entered.',
				'next'  => 'Nothing else to do - this tournament is unrated, so there is no USCF export.',
				'why'   => 'Unrated tournaments skip the export step entirely.',
			),
		);
	}

	// -----------------------------------------------------------------
	// WordPress glue: gather real data, render the card or the offer.
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

		if ( $this->is_active() ) {
			$tournament = $this->resolve_active_tournament();
			$state      = $this->build_state( $tournament );
			$step       = self::derive_step( $state );
			$this->render_card( $step, $tournament );
			return;
		}

		if ( 'wpmtm' === $page && ! $this->is_offer_dismissed() ) {
			$this->render_offer();
		}
	}

	/**
	 * Prefers an 'id' GET param on the tournament edit page - if it names a
	 * real tournament, that tournament is adopted as the active one (via
	 * set_active_tournament()) so later pages without an id, like Settings,
	 * still know which tournament the guide is following. Falls back to the
	 * stored tournament id, then to null.
	 *
	 * @return object|null
	 */
	protected function resolve_active_tournament() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only lookup, same pattern as WPMTM_Admin::render_tournament_edit().
		if ( $id ) {
			$tournament = WPMTM_Repository::get_tournament( $id );
			if ( $tournament ) {
				$this->set_active_tournament( (int) $tournament->id );
				return $tournament;
			}
		}

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
	 * Builds the derive_step() input array from real tournament/section/
	 * player data.
	 *
	 * @param object|null $tournament
	 * @return array
	 */
	protected function build_state( $tournament ) {
		$role_decided = '' !== get_option( 'wpmtm_role_decision', '' );

		if ( ! $tournament ) {
			return array(
				'has_tournament'        => false,
				'rated'                 => false,
				'effective_ids_present' => false,
				'player_count'          => 0,
				'sections_complete'     => false,
				'role_decided'          => $role_decided,
			);
		}

		$opts     = WPMTM_Plugin::instance()->get_opts();
		$sections = WPMTM_Repository::get_sections( $tournament->id );

		$player_count      = 0;
		$sections_complete = ! empty( $sections );
		foreach ( $sections as $section ) {
			$player_count += WPMTM_Repository::count_players( $section->id );
			$done_rounds   = count( WPMTM_Repository::rounds_with_results( $section->id ) );
			if ( $done_rounds < (int) $section->tot_rnds ) {
				$sections_complete = false;
			}
		}

		$affiliate_present = '' !== trim( (string) $opts['affiliate_id'] );
		$head_td_present   = ( '' !== trim( (string) $tournament->head_td_id ) ) || ( '' !== trim( (string) $opts['chief_td_id'] ) );

		return array(
			'has_tournament'        => true,
			'rated'                 => (bool) $tournament->rated,
			'effective_ids_present' => $affiliate_present && $head_td_present,
			'player_count'          => $player_count,
			'sections_complete'     => $sections_complete,
			'role_decided'          => $role_decided,
		);
	}

	/**
	 * The CTA URL for a given step slug: the exact page/anchor the copy's
	 * "next" line points at.
	 */
	protected function get_cta_url( $slug, $tournament ) {
		switch ( $slug ) {
			case 'create':
				return admin_url( 'admin.php?page=wpmtm-edit' );

			case 'settings':
				return admin_url( 'admin.php?page=wpmtm-settings' );

			case 'import':
				return $tournament
					? add_query_arg( array( 'page' => 'wpmtm-edit', 'id' => $tournament->id ), admin_url( 'admin.php' ) )
					: admin_url( 'admin.php?page=wpmtm-edit' );

			case 'rounds':
				if ( $tournament && $tournament->event_post_id ) {
					$permalink = get_permalink( (int) $tournament->event_post_id );
					if ( $permalink ) {
						return $permalink . '#tab-round-entry';
					}
				}
				return $tournament
					? add_query_arg( array( 'page' => 'wpmtm-edit', 'id' => $tournament->id ), admin_url( 'admin.php' ) )
					: admin_url( 'admin.php?page=wpmtm-edit' );

			case 'export':
				return $tournament
					? add_query_arg( array( 'page' => 'wpmtm-edit', 'id' => $tournament->id ), admin_url( 'admin.php' ) ) . '#wpmtm-export'
					: admin_url( 'admin.php?page=wpmtm-edit' );

			default: // 'done' has no CTA.
				return '';
		}
	}

	protected function get_cta_label( $slug ) {
		$labels = array(
			'create'   => __( 'Create tournament', 'wp-tournament-manager' ),
			'settings' => __( 'Open Settings', 'wp-tournament-manager' ),
			'import'   => __( 'Import registrations', 'wp-tournament-manager' ),
			'rounds'   => __( 'Go to round entry', 'wp-tournament-manager' ),
			'export'   => __( 'Open export box', 'wp-tournament-manager' ),
		);
		return isset( $labels[ $slug ] ) ? $labels[ $slug ] : '';
	}

	/**
	 * The prominent wizard card: heading, progress line + dots, the three
	 * labeled lines, the primary CTA, and the "Exit guided setup" link.
	 */
	protected function render_card( array $step, $tournament ) {
		$slug      = $step['slug'];
		$cta_url   = $this->get_cta_url( $slug, $tournament );
		$cta_label = $this->get_cta_label( $slug );
		$exit_url  = $this->stop_url();

		$needs_event_note = ( 'rounds' === $slug )
			&& ( ! $tournament || ! $tournament->event_post_id || ! get_permalink( (int) $tournament->event_post_id ) );
		?>
		<div class="notice notice-info wpmtm-wizard-card">
			<h2 class="wpmtm-wizard-heading"><?php esc_html_e( 'Guided setup', 'wp-tournament-manager' ); ?></h2>
			<p class="wpmtm-wizard-progress">
				<?php
				printf(
					/* translators: 1: current step number, 2: total step count */
					esc_html__( 'Step %1$d of %2$d: %3$s', 'wp-tournament-manager' ),
					(int) $step['index'],
					(int) $step['total'],
					esc_html( $step['title'] )
				);
				?>
			</p>
			<p class="wpmtm-wizard-dots">
				<?php for ( $i = 1; $i <= (int) $step['total']; $i++ ) : ?>
					<span class="wpmtm-wizard-dot<?php echo ( $i === (int) $step['index'] ) ? ' is-active' : ''; ?>"></span>
				<?php endfor; ?>
			</p>
			<p class="wpmtm-wizard-line"><strong><?php esc_html_e( 'What you just did:', 'wp-tournament-manager' ); ?></strong> <?php echo esc_html( $step['did'] ); ?></p>
			<p class="wpmtm-wizard-line"><strong><?php esc_html_e( 'Next:', 'wp-tournament-manager' ); ?></strong> <?php echo esc_html( $step['next'] ); ?></p>
			<p class="wpmtm-wizard-line"><strong><?php esc_html_e( 'Why it matters:', 'wp-tournament-manager' ); ?></strong> <?php echo esc_html( $step['why'] ); ?></p>
			<?php if ( $needs_event_note ) : ?>
				<p class="wpmtm-wizard-line description"><?php esc_html_e( 'Link this tournament to an event first (edit the tournament and choose a Linked event) so round entry has somewhere to go.', 'wp-tournament-manager' ); ?></p>
			<?php endif; ?>
			<?php if ( 'access' === $slug ) : ?>
				<?php $this->render_access_form(); ?>
				<p class="wpmtm-wizard-actions">
					<a class="wpmtm-wizard-exit" href="<?php echo esc_url( $exit_url ); ?>"><?php esc_html_e( 'Exit guided setup', 'wp-tournament-manager' ); ?></a>
				</p>
			<?php else : ?>
				<p class="wpmtm-wizard-actions">
					<?php if ( $cta_url && $cta_label ) : ?>
						<a class="button button-primary" href="<?php echo esc_url( $cta_url ); ?>"><?php echo esc_html( $cta_label ); ?></a>
					<?php elseif ( 'done' === $slug ) : ?>
						<strong><?php esc_html_e( 'Nice work - this tournament is fully wrapped up.', 'wp-tournament-manager' ); ?></strong>
					<?php endif; ?>
					<a class="wpmtm-wizard-exit" href="<?php echo esc_url( $exit_url ); ?>"><?php esc_html_e( 'Exit guided setup', 'wp-tournament-manager' ); ?></a>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The 'access' step's inline form: a checked-by-default checkbox
	 * offering to create the Tournament Manager role, submitted to the
	 * same nonced admin_post_wpmtm_wizard handler (do=set_access) as the
	 * rest of this class's state changes.
	 */
	protected function render_access_form() {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpmtm-wizard-access-form">
			<?php wp_nonce_field( 'wpmtm_wizard_set_access' ); ?>
			<input type="hidden" name="action" value="wpmtm_wizard">
			<input type="hidden" name="do" value="set_access">
			<p>
				<label><input type="checkbox" name="create_role" value="1" checked> <?php esc_html_e( 'Create a Tournament Manager role (recommended)', 'wp-tournament-manager' ); ?></label>
			</p>
			<p class="description"><?php esc_html_e( 'Leave this checked to create the role now. Uncheck to let only administrators manage tournaments; you can create the role later.', 'wp-tournament-manager' ); ?></p>
			<p>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Continue', 'wp-tournament-manager' ); ?></button>
			</p>
		</form>
		<?php
	}

	/**
	 * The slim single-line offer notice on the Tournaments list page,
	 * shown only when the guide is not active and the TD has not dismissed
	 * it.
	 */
	protected function render_offer() {
		$start_url   = $this->start_url();
		$dismiss_url = $this->build_action_url( 'dismiss' );
		?>
		<div class="notice notice-info wpmtm-wizard-offer">
			<p>
				<?php esc_html_e( 'New to running an event here? Start guided setup.', 'wp-tournament-manager' ); ?>
				<a class="button button-primary" href="<?php echo esc_url( $start_url ); ?>"><?php esc_html_e( 'Start guided setup', 'wp-tournament-manager' ); ?></a>
				<a class="wpmtm-wizard-offer-dismiss" href="<?php echo esc_url( $dismiss_url ); ?>"><?php esc_html_e( 'Do not show this again', 'wp-tournament-manager' ); ?></a>
			</p>
		</div>
		<?php
	}

	// -----------------------------------------------------------------
	// Start/stop/dismiss: one nonced admin_post action, param 'do'.
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
	 * The nonced admin_post URL that starts guided setup. Public so callers
	 * outside this class (WPMTM_Admin::render_tournaments_list()'s header
	 * button, the admin bar node below) share the same URL-building logic
	 * as the offer notice and the admin_post_wpmtm_wizard handler, rather
	 * than each re-deriving it.
	 */
	public function start_url() {
		return $this->build_action_url( 'start' );
	}

	/**
	 * The nonced admin_post URL that stops (exits) guided setup. See
	 * start_url() above.
	 */
	public function stop_url() {
		return $this->build_action_url( 'stop' );
	}

	public function handle_action() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce is verified on the next line via check_admin_referer(), which needs $do to build the expected action name first. $_REQUEST (not $_GET) because 'set_access' below arrives via POST, the others via GET.
		$do = isset( $_REQUEST['do'] ) ? sanitize_key( wp_unslash( $_REQUEST['do'] ) ) : '';
		if ( ! in_array( $do, array( 'start', 'stop', 'dismiss', 'set_access' ), true ) ) {
			wp_die( esc_html__( 'Unknown guided setup action.', 'wp-tournament-manager' ) );
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

			case 'dismiss':
				$this->dismiss_offer();
				wp_safe_redirect( admin_url( 'admin.php?page=wpmtm' ) );
				exit;

			case 'set_access':
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified above via check_admin_referer().
				if ( ! empty( $_POST['create_role'] ) ) {
					WPMTM_Roles::create_role();
					update_option( 'wpmtm_role_decision', 'role' );
				} else {
					update_option( 'wpmtm_role_decision', 'admins' );
				}
				wp_safe_redirect( admin_url( 'admin.php?page=wpmtm-edit' ) );
				exit;
		}
	}

	// -----------------------------------------------------------------
	// Admin bar discoverability, hooked at priority 91 - just after
	// WPMTM_Admin::add_new_tournament_menu()'s "Tournament" node at 90.
	// -----------------------------------------------------------------

	public function add_admin_bar_node( $wp_admin_bar ) {
		if ( ! current_user_can( WPMTM_CAPABILITY ) ) {
			return;
		}

		if ( $this->is_active() ) {
			$wp_admin_bar->add_node(
				array(
					'parent' => 'site-name',
					'id'     => 'wpmtm-wizard-exit',
					'title'  => __( 'Exit guided setup', 'wp-tournament-manager' ),
					'href'   => esc_url( $this->stop_url() ),
				)
			);
		} else {
			$wp_admin_bar->add_node(
				array(
					'parent' => 'site-name',
					'id'     => 'wpmtm-wizard-start',
					'title'  => __( 'Guided setup', 'wp-tournament-manager' ),
					'href'   => esc_url( $this->start_url() ),
				)
			);
		}
	}
}
