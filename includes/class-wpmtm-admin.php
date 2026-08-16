<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin CRUD screens under the top-level "Tournament Manager" menu:
 * tournaments list and the add/edit tournament form (name, dates, linked
 * event, TD/affiliate IDs, and the lock/unlock control). Every
 * state-changing request goes through admin-post.php, is nonce-verified,
 * and is gated on WPMTM_CAPABILITY. All output is escaped at render time.
 *
 * The ETR roster-import surface (upload, preview, confirm) lives in
 * WPMTM_Admin_Import; this class only makes the two thin calls the edit
 * screen needs (render_import_box(), maybe_render_preview()). The sections
 * editor lives in WPMTM_Admin_Sections and the per-section players editor
 * lives in WPMTM_Admin_Players, split out the same way and with the same
 * nonce/capability/escaping discipline; this class calls into both from
 * render_tournament_edit() below.
 */
class WPMTM_Admin {

	use WPMTM_Admin_Shared;

	/**
	 * Tournaments shown per page on the list screen (audit item 51). Named
	 * rather than inline, matching the MAX_UPLOAD_BYTES precedent from the
	 * 2026-07-30 maintainability pass.
	 */
	const TOURNAMENTS_PER_PAGE = 50;

	private static $instance = null;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . WPMTM_PLUGIN_BASENAME, array( $this, 'add_plugin_action_links' ) );
		add_filter( 'plugin_row_meta', array( $this, 'add_plugin_row_meta' ), 10, 2 );
		add_action( 'admin_bar_menu', array( $this, 'add_new_tournament_menu' ), 90 );
		add_filter( 'admin_title', array( $this, 'filter_admin_title' ), 10, 2 );

		add_action( 'admin_post_wpmtm_save_tournament', array( $this, 'handle_save_tournament' ) );
		add_action( 'admin_post_wpmtm_delete_tournament', array( $this, 'handle_delete_tournament' ) );
		add_action( 'admin_post_wpmtm_toggle_lock', array( $this, 'handle_toggle_lock' ) );
	}

	// -----------------------------------------------------------------
	// Menu / assets
	// -----------------------------------------------------------------

	public function register_menu() {
		// Fixed position 81 (owner request, 2026-07-24): sits directly below
		// WordPress' own "Settings" (80), with Event Registrations (82) and
		// Tickets Extra Custom Fields (83) after it - deliberately anchored
		// to WordPress core's own fixed Settings position, not to The
		// Events Calendar or Event Tickets, so this ordering holds
		// regardless of what position either of those plugins happens to
		// register at.
		add_menu_page(
			__( 'Tournament Manager', 'wp-tournament-manager' ),
			__( 'Tournament Manager', 'wp-tournament-manager' ),
			WPMTM_CAPABILITY,
			'wpmtm',
			array( $this, 'render_tournaments_list' ),
			'dashicons-awards',
			81
		);

		add_submenu_page(
			'wpmtm',
			__( 'All Tournaments', 'wp-tournament-manager' ),
			__( 'All Tournaments', 'wp-tournament-manager' ),
			WPMTM_CAPABILITY,
			'wpmtm',
			array( $this, 'render_tournaments_list' )
		);

		add_submenu_page(
			'wpmtm',
			__( 'Add Tournament', 'wp-tournament-manager' ),
			__( 'Add New', 'wp-tournament-manager' ),
			WPMTM_CAPABILITY,
			'wpmtm-edit',
			array( $this, 'render_tournament_edit' )
		);
	}

	public function add_plugin_action_links( $links ) {
		$our_links = array(
			'wpmtm-tournaments' => '<a href="' . esc_url( admin_url( 'admin.php?page=wpmtm' ) ) . '">' . esc_html__( 'Tournaments', 'wp-tournament-manager' ) . '</a>',
			'wpmtm-settings'    => '<a href="' . esc_url( admin_url( 'admin.php?page=wpmtm-settings' ) ) . '">' . esc_html__( 'Settings', 'wp-tournament-manager' ) . '</a>',
		);
		return array_merge( $our_links, $links );
	}

	/**
	 * Relabel the auto-generated Plugin URI row-meta link to "View details",
	 * matching wp-etr's plugin_row_meta() (Settings::plugin_row_meta()).
	 */
	public function add_plugin_row_meta( $links, $plugin_file ) {
		if ( $plugin_file !== WPMTM_PLUGIN_BASENAME ) {
			return $links;
		}
		foreach ( $links as &$link ) {
			if ( strpos( $link, 'github.com/christefano/wp-tournament-manager' ) !== false ) {
				$link = '<a href="https://github.com/christefano/wp-tournament-manager" target="_blank" rel="noopener">' . esc_html__( 'View details', 'wp-tournament-manager' ) . '</a>';
			}
		}
		return $links;
	}

	/**
	 * The 'wpmtm-edit' submenu is registered with a single static
	 * page_title ("Add Tournament", register_menu() above), since
	 * add_submenu_page() has no way to know at registration time whether a
	 * given page load is the Add or Edit form - only render_tournament_edit()
	 * knows that, from $_GET['id']. Without this filter the browser tab
	 * (and the fallback title WordPress uses if a screen has no <h1>) always
	 * read "Add Tournament" even while editing an existing tournament, out
	 * of step with the page's own dynamic <h1> (render_tournament_form()).
	 */
	public function filter_admin_title( $admin_title, $title ) {
		if ( isset( $_GET['page'] ) && 'wpmtm-edit' === $_GET['page'] && ! empty( $_GET['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only title text, no state change.
			$admin_title = str_replace( $title, __( 'Edit Tournament', 'wp-tournament-manager' ), $admin_title );
		}
		return $admin_title;
	}

	public function add_new_tournament_menu( $wp_admin_bar ) {
		if ( ! current_user_can( WPMTM_CAPABILITY ) ) {
			return;
		}
		$wp_admin_bar->add_node( array(
			'parent' => 'new-content',
			'id'     => 'wpmtm-tournament',
			'title'  => __( 'Tournament', 'wp-tournament-manager' ),
			'href'   => admin_url( 'admin.php?page=wpmtm-edit' ),
		) );
	}

	public function enqueue_assets() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: gates asset enqueue by the current admin page slug, no state change.
		if ( ! isset( $_GET['page'] ) || 0 !== strpos( sanitize_key( wp_unslash( $_GET['page'] ) ), 'wpmtm' ) ) {
			return;
		}
		wp_enqueue_style( 'wpmtm-admin', WPMTM_PLUGIN_URL . 'assets/wpmtm-admin.css', array(), WPMTM_VERSION );
		wp_enqueue_script( 'wpmtm-admin', WPMTM_PLUGIN_URL . 'assets/wpmtm-admin.js', array(), WPMTM_VERSION, true );
		// Strings for the "Validate with USCF" button (renamed from
		// "Validate TDs" 2026-07-18; the Settings page and the tournament
		// edit page - both pass the wpmtm page-prefix gate above); see
		// assets/wpmtm-admin.js behavior 6 and
		// WPMTM_USCF_Status::ajax_validate_tds().
		wp_localize_script(
			'wpmtm-admin',
			'wpmtmValidateTds',
			array(
				'validating'    => __( 'Validating with USCF...', 'wp-tournament-manager' ),
				'requestFailed' => __( 'The validation request failed - try again.', 'wp-tournament-manager' ),
				'colRole'       => __( 'Role', 'wp-tournament-manager' ),
				'colUscfId'     => __( 'USCF ID', 'wp-tournament-manager' ),
				'colName'       => __( 'Name', 'wp-tournament-manager' ),
				'colMembership' => __( 'Membership', 'wp-tournament-manager' ),
				'colTdCert'     => __( 'TD certification', 'wp-tournament-manager' ),
				'colSafePlay'   => __( 'Safe Play', 'wp-tournament-manager' ),
				'colVerdict'    => __( 'Verdict', 'wp-tournament-manager' ),
				/* translators: %s: the "must be active through" date (YYYY-MM-DD) */
				'throughNote'   => __( 'Checked as active through %s.', 'wp-tournament-manager' ),
			)
		);
	}

	// -----------------------------------------------------------------
	// Tournaments list
	// -----------------------------------------------------------------

	public function render_tournaments_list() {
		$this->require_capability();

		// Audit item 51: this used to fetch every tournament the install had
		// ever held and then array_filter() a dedicated-role TD's own back out
		// in PHP, so a club running weekly events for years paid for its whole
		// history on every visit to this screen. The ownership filter is in the
		// query now, and the list is paged.
		$owner_filter = current_user_can( 'manage_options' ) ? 0 : get_current_user_id();
		$total        = WPMTM_Repository::count_tournaments( $owner_filter );
		$per_page     = self::TOURNAMENTS_PER_PAGE;
		$total_pages  = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pager position, no state change.
		$paged = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
		$paged = max( 1, min( $paged, max( 1, $total_pages ) ) );

		$tournaments = WPMTM_Repository::tournaments_with_counts( $per_page, ( $paged - 1 ) * $per_page, $owner_filter );
		?>
		<div class="wrap wpmtm-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Tournament Manager', 'wp-tournament-manager' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpmtm-edit' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'wp-tournament-manager' ); ?></a>
			<?php WPMTM_Wizard::instance()->render_show_guide_button( 'page-title-action' ); ?>
			<hr class="wp-header-end">
			<?php $this->render_admin_header(); ?>
			<?php WPMTM_Wizard::instance()->render_guide_shown_notice(); ?>
			<?php $this->render_notices(); ?>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'wp-tournament-manager' ); ?></th>
						<th><?php esc_html_e( 'Dates', 'wp-tournament-manager' ); ?></th>
						<th><?php esc_html_e( 'Rated', 'wp-tournament-manager' ); ?></th>
						<th><?php esc_html_e( 'Status', 'wp-tournament-manager' ); ?></th>
						<th><?php esc_html_e( 'Sections', 'wp-tournament-manager' ); ?></th>
						<th><?php esc_html_e( 'Players', 'wp-tournament-manager' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'wp-tournament-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $tournaments ) ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'No tournaments yet.', 'wp-tournament-manager' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $tournaments as $t ) : ?>
						<?php
						$edit_url   = add_query_arg( array( 'page' => 'wpmtm-edit', 'id' => $t->id ), admin_url( 'admin.php' ) );
						$delete_url = wp_nonce_url(
							add_query_arg(
								array(
									'action'        => 'wpmtm_delete_tournament',
									'tournament_id' => $t->id,
								),
								admin_url( 'admin-post.php' )
							),
							'wpmtm_delete_tournament_' . $t->id
						);
						?>
						<tr>
							<td><a href="<?php echo esc_url( $edit_url ); ?>"><strong><?php echo esc_html( $t->name ); ?></strong></a></td>
							<td><?php echo esc_html( $this->format_date_range( $t->begin_date, $t->end_date ) ); ?></td>
							<td>
								<?php if ( $t->rated ) : ?>
									<span class="wpmtm-badge wpmtm-badge--rated"><?php esc_html_e( 'Rated', 'wp-tournament-manager' ); ?></span>
								<?php else : ?>
									<span class="wpmtm-badge"><?php esc_html_e( 'Unrated', 'wp-tournament-manager' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php
								$status = WPMTM_Wizard::instance()->list_status_for( $t );
								if ( $status['url'] ) :
									?>
									<a href="<?php echo esc_url( $status['url'] ); ?>"><?php echo esc_html( $status['label'] ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $status['label'] ); ?>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( (int) $t->section_count ); ?></td>
							<td><?php echo esc_html( (int) $t->player_count ); ?></td>
							<td>
								<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'wp-tournament-manager' ); ?></a>
								|
								<a href="<?php echo esc_url( $delete_url ); ?>" class="submitdelete" data-wpmtm-confirm="<?php echo esc_attr__( 'Delete this tournament and all its sections, players, games, and byes? This cannot be undone.', 'wp-tournament-manager' ); ?>"><?php esc_html_e( 'Delete', 'wp-tournament-manager' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<span class="displaying-num">
							<?php
							printf(
								/* translators: %s: total number of tournaments */
								esc_html( _n( '%s tournament', '%s tournaments', $total, 'wp-tournament-manager' ) ),
								esc_html( number_format_i18n( $total ) )
							);
							?>
						</span>
						<?php
						echo wp_kses_post(
							paginate_links(
								array(
									'base'      => add_query_arg( 'paged', '%#%' ),
									'format'    => '',
									'prev_text' => __( '&laquo;', 'wp-tournament-manager' ),
									'next_text' => __( '&raquo;', 'wp-tournament-manager' ),
									'total'     => $total_pages,
									'current'   => $paged,
								)
							)
						);
						?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	protected function format_date_range( $begin, $end ) {
		if ( ! $begin && ! $end ) {
			return '';
		}
		$format = get_option( 'date_format' );
		$b      = $begin ? date_i18n( $format, strtotime( $begin ) ) : '?';
		$e      = $end ? date_i18n( $format, strtotime( $end ) ) : '?';
		return $b === $e ? $b : $b . ' - ' . $e;
	}

	// -----------------------------------------------------------------
	// Add / edit tournament (+ sections, + players dispatch)
	// -----------------------------------------------------------------

	public function render_tournament_edit() {
		$this->require_capability();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: absint()-cast id selects which tournament/section to render, no state change.
		$tournament_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, see note above.
		$section_id    = isset( $_GET['section_id'] ) ? absint( $_GET['section_id'] ) : 0;

		$tournament = $tournament_id ? WPMTM_Repository::get_tournament( $tournament_id ) : null;
		if ( $tournament_id && ! $tournament ) {
			wp_die( esc_html__( 'Tournament not found.', 'wp-tournament-manager' ) );
		}
		if ( $tournament && ! WPMTM_Roles::user_can_manage_tournament( $tournament ) ) {
			wp_die( esc_html__( 'No permission to edit this tournament.', 'wp-tournament-manager' ) );
		}

		if ( $tournament && $section_id ) {
			$section = WPMTM_Repository::get_section( $section_id );
			if ( ! $section || (int) $section->tournament_id !== $tournament_id ) {
				wp_die( esc_html__( 'Section not found.', 'wp-tournament-manager' ) );
			}
			WPMTM_Admin_Players::instance()->render_players_editor( $tournament, $section );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: selects the ETR import preview render path from a pending transient, no state change.
		if ( $tournament && isset( $_GET['wpmtm_etr_step'] ) && 'preview' === $_GET['wpmtm_etr_step'] ) {
			if ( WPMTM_Admin_Import::instance()->maybe_render_preview( $tournament ) ) {
				return;
			}
		}

		$this->render_tournament_form( $tournament );

		if ( $tournament ) {
			WPMTM_Admin_Sections::instance()->render_sections_editor( $tournament );
			WPMTM_Admin_Import::instance()->render_import_box( $tournament );
			WPMTM_Admin_Export::instance()->render_export_box( $tournament );
		}
	}

	protected function render_tournament_form( $tournament ) {
		$opts          = WPMTM_Plugin::instance()->get_opts();
		$is_edit       = null !== $tournament;
		$tournament_id = $is_edit ? (int) $tournament->id : 0;
		$name          = $is_edit ? $tournament->name : '';
		$rated         = $is_edit ? (bool) $tournament->rated : false;
		$begin         = $is_edit ? $tournament->begin_date : current_time( 'Y-m-d' );
		$end           = $is_edit ? $tournament->end_date : '';
		$event_post_id = $is_edit ? (int) $tournament->event_post_id : 0;
		$city          = $is_edit ? $tournament->city : '';
		$state         = $is_edit ? $tournament->state : '';
		$zip           = $is_edit ? $tournament->zipcode : '';
		$head_td       = $is_edit ? $tournament->head_td_id : '';
		$assistant_td  = $is_edit ? $tournament->assistant_td_id : '';
		$affiliate_id  = $is_edit ? $tournament->affiliate_id : '';
		$send_x        = $is_edit ? (bool) $tournament->send_crosstable : false;
		$show_photos   = $is_edit ? (bool) $tournament->show_photos : false;
		?>
		<div class="wrap wpmtm-wrap">
			<h1 class="wp-heading-inline"><?php echo $is_edit ? esc_html__( 'Edit Tournament', 'wp-tournament-manager' ) : esc_html__( 'Add Tournament', 'wp-tournament-manager' ); ?></h1>
			<?php
			// The "Show setup guide" / "Exit setup guide" button is back next
			// to the H1 here (2026-07-24, owner request, reversing 2026-07-22):
			// the panel below now honors is_active() like every other surface,
			// so once a TD exits the guide (from here, the admin bar, or the
			// Tournaments list) it actually stays hidden on this page too,
			// instead of unconditionally reappearing - and this button is the
			// only way to bring it back without leaving the page.
			WPMTM_Wizard::instance()->render_show_guide_button( 'page-title-action' );
			?>
			<?php
			// The panel renders BEFORE <hr class="wp-header-end"> (2026-07-23,
			// owner request): WordPress relocates every admin notice to just
			// before that marker, so putting the panel ahead of it makes the
			// setup guide sit directly under the H1 and ABOVE the status
			// notices (e.g. the past-date warning below), instead of beneath
			// them.
			if ( WPMTM_Wizard::instance()->is_active() ) {
				WPMTM_Wizard::instance()->render_panel( $tournament );
			}
			?>
			<hr class="wp-header-end">
			<?php
			$event_permalink = $event_post_id ? get_permalink( $event_post_id ) : false;
			if ( $event_permalink ) :
				?>
				<p class="wpmtm-switch-to-event">
					<a href="<?php echo esc_url( $event_permalink ); ?>"><?php esc_html_e( 'Event details', 'wp-tournament-manager' ); ?></a>
					&nbsp;|&nbsp;
					<a href="<?php echo esc_url( $event_permalink . '#tab-registrations' ); ?>"><?php esc_html_e( 'Registrations', 'wp-tournament-manager' ); ?></a>
					&nbsp;|&nbsp;
					<a href="<?php echo esc_url( $event_permalink . '#tab-standings' ); ?>"><?php esc_html_e( 'Standings', 'wp-tournament-manager' ); ?></a>
					&nbsp;|&nbsp;
					<a href="<?php echo esc_url( $event_permalink . '#tab-wall-chart' ); ?>"><?php esc_html_e( 'Wall chart', 'wp-tournament-manager' ); ?></a>
					&nbsp;|&nbsp;
					<a href="<?php echo esc_url( $event_permalink . '#tab-round-entry' ); ?>"><?php esc_html_e( 'Rounds', 'wp-tournament-manager' ); ?></a>
				</p>
			<?php endif; ?>
			<?php if ( $is_edit ) : ?>
				<?php
				$today = current_time( 'Y-m-d' );
				$is_past = ( $end && $end < $today ) || ( ! $end && $begin && $begin < $today );
				if ( $is_past ) :
					?>
					<div class="notice notice-warning">
						<p><?php esc_html_e( 'This tournament\'s dates have already passed, so it probably holds final results. Changes here can alter standings that players have already seen, and if it was submitted to USCF the rating report will no longer match. Editing is not blocked, so just check that a change here is really necessary.', 'wp-tournament-manager' ); ?></p>
					</div>
				<?php endif; ?>
				<?php $this->render_lock_control( $tournament ); ?>
			<?php endif; ?>
			<?php $this->render_admin_header(); ?>
			<?php WPMTM_Wizard::instance()->render_guide_shown_notice(); ?>
			<?php $this->render_notices(); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wpmtm_save_tournament_' . $tournament_id, 'wpmtm_tournament_nonce' ); ?>
				<input type="hidden" name="action" value="wpmtm_save_tournament">
				<input type="hidden" name="tournament_id" value="<?php echo esc_attr( $tournament_id ); ?>">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wpmtm-name"><?php esc_html_e( 'Name', 'wp-tournament-manager' ); ?></label></th>
						<td>
							<input type="text" id="wpmtm-name" class="regular-text" maxlength="35" name="name" value="<?php echo esc_attr( $name ); ?>" required>
							<p class="description"><?php esc_html_e( 'Capped at 35 characters - the USCF export format\'s limit for the event name (H_NAME).', 'wp-tournament-manager' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Rated', 'wp-tournament-manager' ); ?></th>
						<td>
							<label><input type="checkbox" name="rated" value="1" <?php checked( $rated ); ?>> <?php esc_html_e( 'This tournament has USCF-rated sections', 'wp-tournament-manager' ); ?></label>
							<p class="description"><?php esc_html_e( 'When checked, each section is given a Rated checkbox in the sections editor and registrations import preview. A tournament can mix rated and unrated sections, e.g. a rated Open and an unrated scholastic side event. Only the sections marked as Rated are included in the USCF export.', 'wp-tournament-manager' ); ?></p>
							<p class="description"><?php esc_html_e( 'Unrated tournaments don\'t require a club affiliate ID or any TD IDs. Enable temporarily to download a DBF export of any sections marked as Rated for historical purposes or to import this tournament\'s into other tournament management software.', 'wp-tournament-manager' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpmtm-begin-date"><?php esc_html_e( 'Begin date', 'wp-tournament-manager' ); ?></label></th>
						<td><input type="date" id="wpmtm-begin-date" name="begin_date" value="<?php echo esc_attr( $begin ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="wpmtm-end-date"><?php esc_html_e( 'End date', 'wp-tournament-manager' ); ?></label></th>
						<td><input type="date" id="wpmtm-end-date" name="end_date" value="<?php echo esc_attr( $end ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="wpmtm-event-post-id"><?php esc_html_e( 'Linked event', 'wp-tournament-manager' ); ?></label></th>
						<td><?php $this->render_event_picker( $event_post_id ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="wpmtm-city"><?php esc_html_e( 'City', 'wp-tournament-manager' ); ?></label></th>
						<td><input type="text" id="wpmtm-city" class="regular-text" maxlength="21" name="city" value="<?php echo esc_attr( $city ); ?>" placeholder="<?php echo esc_attr( $opts['default_city'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="wpmtm-state"><?php esc_html_e( 'State', 'wp-tournament-manager' ); ?></label></th>
						<td><input type="text" id="wpmtm-state" class="small-text" maxlength="2" name="state" value="<?php echo esc_attr( $state ); ?>" placeholder="<?php echo esc_attr( $opts['default_state'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="wpmtm-zip"><?php esc_html_e( 'Zip code', 'wp-tournament-manager' ); ?></label></th>
						<td><input type="text" id="wpmtm-zip" class="regular-text" name="zipcode" value="<?php echo esc_attr( $zip ); ?>" placeholder="<?php echo esc_attr( $opts['default_zipcode'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="wpmtm-head-td-id"><?php esc_html_e( 'Chief TD USCF ID', 'wp-tournament-manager' ); ?></label></th>
						<td>
							<input type="text" id="wpmtm-head-td-id" class="regular-text" maxlength="8" name="head_td_id" value="<?php echo esc_attr( $head_td ); ?>" placeholder="12345678">
							<?php if ( '' !== trim( (string) $opts['chief_td_id'] ) ) : ?>
								<button type="button" class="button" data-wpmtm-use-default data-target="wpmtm-head-td-id" data-default="<?php echo esc_attr( $opts['chief_td_id'] ); ?>"><?php esc_html_e( 'Use default', 'wp-tournament-manager' ); ?></button>
							<?php else : ?>
								<button type="button" class="button" disabled title="<?php esc_attr_e( 'No Default Chief TD ID is set in Tournament Manager Settings.', 'wp-tournament-manager' ); ?>"><?php esc_html_e( 'Use default', 'wp-tournament-manager' ); ?></button>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'This tournament\'s own chief TD. Blank means no chief TD is submitted for this tournament. The "Use default" button copies the club default set on the Tournament Manager Settings page. It does not apply automatically.', 'wp-tournament-manager' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpmtm-assistant-td-id"><?php esc_html_e( 'Assistant TD USCF ID', 'wp-tournament-manager' ); ?></label></th>
						<td>
							<input type="text" id="wpmtm-assistant-td-id" class="regular-text" maxlength="8" name="assistant_td_id" value="<?php echo esc_attr( $assistant_td ); ?>" placeholder="">
							<?php if ( '' !== trim( (string) $opts['assistant_td_id'] ) ) : ?>
								<button type="button" class="button" data-wpmtm-use-default data-target="wpmtm-assistant-td-id" data-default="<?php echo esc_attr( $opts['assistant_td_id'] ); ?>"><?php esc_html_e( 'Use default', 'wp-tournament-manager' ); ?></button>
							<?php else : ?>
								<button type="button" class="button" disabled title="<?php esc_attr_e( 'No Default Assistant TD ID is set in Tournament Manager Settings.', 'wp-tournament-manager' ); ?>"><?php esc_html_e( 'Use default', 'wp-tournament-manager' ); ?></button>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'This tournament\'s own assistant TD. Blank means no assistant TD is submitted for this tournament. The "Use default" button copies the club default set on the Tournament Manager Settings page. It does not apply automatically.', 'wp-tournament-manager' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpmtm-affiliate-id"><?php esc_html_e( 'USCF affiliate ID', 'wp-tournament-manager' ); ?></label></th>
						<td>
							<input type="text" id="wpmtm-affiliate-id" class="regular-text" maxlength="10" name="affiliate_id" value="<?php echo esc_attr( $affiliate_id ); ?>" placeholder="A1234567">
							<?php if ( '' !== trim( (string) $opts['affiliate_id'] ) ) : ?>
								<button type="button" class="button" data-wpmtm-use-default data-target="wpmtm-affiliate-id" data-default="<?php echo esc_attr( $opts['affiliate_id'] ); ?>"><?php esc_html_e( 'Use default', 'wp-tournament-manager' ); ?></button>
							<?php else : ?>
								<button type="button" class="button" disabled title="<?php esc_attr_e( 'No Default USCF affiliate ID is set in Tournament Manager Settings.', 'wp-tournament-manager' ); ?>"><?php esc_html_e( 'Use default', 'wp-tournament-manager' ); ?></button>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'This tournament\'s own affiliate ID, for a shared install running an event on behalf of a different club. Blank falls back to the club default set on the Tournament Manager Settings page at export and validation time. The "Use default" button copies that Settings value in. It does not apply automatically.', 'wp-tournament-manager' ); ?></p>
							<p class="description"><?php esc_html_e( 'To submit a rated tournament on behalf of an affiliate other than the directing TD\'s own, that TD must be an authorized tournament director of the other affiliate. That affiliate\'s Affiliate Manager can add a TD as one of its authorized TDs.', 'wp-tournament-manager' ); ?></p>
							<p class="description"><?php esc_html_e( 'The submitting TD is responsible for the tournament\'s validity and payment of the USCF tournament fee. The lead TD, if different from the submitting TD, is also responsible for the tournament being valid. If the submitter is not the directing TD, do not list the submitter as a TD on the tournament.', 'wp-tournament-manager' ); ?></p>
						</td>
					</tr>
					<?php if ( $is_edit ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'USCF status', 'wp-tournament-manager' ); ?></th>
							<td>
								<button type="button" class="button" data-wpmtm-validate-tds data-context="tournament" data-tournament="<?php echo esc_attr( $tournament_id ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wpmtm_validate_tds' ) ); ?>">
									<?php esc_html_e( 'Validate with USCF', 'wp-tournament-manager' ); ?>
								</button>
								<p class="description" data-wpmtm-validate-save-first hidden><strong><?php esc_html_e( 'Changes remain unsaved.', 'wp-tournament-manager' ); ?></strong> <?php esc_html_e( 'Save the tournament before validating - this checks the saved values, not unsaved edits.', 'wp-tournament-manager' ); ?></p>
								<p class="description wpmtm-td-check-last" data-wpmtm-td-check-last><?php echo esc_html( WPMTM_USCF_Status::last_td_check_text( $tournament_id ) ); ?></p>
								<p class="description"><?php esc_html_e( 'Checks this tournament\'s effective affiliate ID (its own if set, else the Settings default) plus its own Chief and Assistant TD IDs against the USCF ratings API, as active through the tournament end date. A blank TD field reports as missing, not the Settings default. A blank affiliate field reports the Settings default instead. Nothing is blocked by the result. Checks use the saved values, so save the tournament first if the IDs above have been changed.', 'wp-tournament-manager' ); ?></p>
								<div data-wpmtm-validate-tds-results></div>
							</td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Crosstable flag', 'wp-tournament-manager' ); ?></th>
						<td>
							<label><input type="checkbox" name="send_crosstable" value="1" <?php checked( $send_x ); ?>> <?php esc_html_e( 'Set the crosstable flag (H_SENDCROS) in the USCF export header', 'wp-tournament-manager' ); ?></label>
							<p class="description"><?php esc_html_e( 'A leftover from the paper era: this header flag asked USCF to mail the affiliate a printed crosstable of the rated event. Results appear online at ratings.uschess.org regardless, so nearly every club leaves this off.', 'wp-tournament-manager' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Show profile pictures', 'wp-tournament-manager' ); ?></th>
						<td>
							<label><input type="checkbox" name="show_photos" value="1" <?php checked( $show_photos ); ?>> <?php esc_html_e( 'Show profile pictures', 'wp-tournament-manager' ); ?></label>
							<p class="description"><?php esc_html_e( 'Shows registrant photos, when available from the event registration, on the public standings, wall chart, and pairing aid. Useful for youth tournaments or if registrants upload inappropriate photos.', 'wp-tournament-manager' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( $is_edit ? __( 'Save Tournament', 'wp-tournament-manager' ) : __( 'Add Tournament', 'wp-tournament-manager' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Lock/unlock control shown next to the H1 / "Switch to event" link
	 * above (Change 6, "conclude and lock a tournament"): a real POST form
	 * to admin-post.php, nonce-verified the same way every other
	 * state-changing form on this screen is (wp_nonce_field() +
	 * check_admin_referer() in the handler below) - a GET link was used
	 * here previously, but a plain <button type="submit"> is not
	 * followable by a prefetch/crawler the way an <a href> is, so locking
	 * a tournament can no longer be triggered by anything other than an
	 * actual form submission. Locking is still a one-click, infrequent TD
	 * action, not a form field, so it does not belong in
	 * handle_save_tournament()'s bulk form save.
	 *
	 * Icon-button styling (assets/wpmtm-admin.css, .wpmtm-lock-btn) matches
	 * wp-etr's .etr-btn (assets/etr-registrations.css) - the closest
	 * existing analog to a "mailto/email icon button", since neither
	 * wp-etr nor wp-etecf ships a dedicated one (the only glyph buttons
	 * found there, wp-etecf's ".etecf-move-up"/".etecf-move-down" arrows,
	 * carry no custom CSS of their own beyond core's plain ".button"). The
	 * fixed height + inline-flex centering on top of that is this
	 * plugin's own "emoji height fix", added because the lock glyph's own
	 * line-height metric would otherwise make this button taller than a
	 * plain-text .etr-btn-alike and enlarge the row. The glyphs use the
	 * text-presentation variation selector (U+FE0E) so they render as plain
	 * monochrome glyphs rather than color emoji.
	 */
	protected function render_lock_control( $tournament ) {
		$locked  = (bool) $tournament->locked;
		$confirm = $locked
			? __( 'Unlock this tournament so results can be edited again?', 'wp-tournament-manager' )
			: __( 'Lock this tournament and mark it complete? Round entry will be disabled until it is unlocked.', 'wp-tournament-manager' );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpmtm-lock-control">
			<?php wp_nonce_field( 'wpmtm_toggle_lock_' . $tournament->id, 'wpmtm_toggle_lock_nonce' ); ?>
			<input type="hidden" name="action" value="wpmtm_toggle_lock">
			<input type="hidden" name="tournament_id" value="<?php echo esc_attr( $tournament->id ); ?>">
			<button type="submit" class="wpmtm-lock-btn button" data-wpmtm-confirm="<?php echo esc_attr( $confirm ); ?>">
				<?php if ( $locked ) : ?>
					<span aria-hidden="true">&#128275;&#65038;</span>&nbsp;<?php esc_html_e( 'Unlock tournament', 'wp-tournament-manager' ); ?>
				<?php else : ?>
					<span aria-hidden="true">&#128274;&#65038;</span>&nbsp;<?php esc_html_e( 'Lock tournament', 'wp-tournament-manager' ); ?>
				<?php endif; ?>
			</button>
		</form>
		<?php
	}

	protected function render_event_picker( $selected_id ) {
		if ( post_type_exists( 'tribe_events' ) ) {
			// 'tribe_suppress_query_filters' (2026-07-21): without it, The
			// Events Calendar filters any tribe_events query down to UPCOMING
			// events only, which is exactly backwards for this picker. A TD
			// runs the tournament first and enters results afterward, so by
			// the time they are on this screen the event is virtually always
			// in the past. Measured on the live install: 163 published events
			// existed, this query returned 3, and every event the real
			// tournaments were linked to was missing. TEC's own documented
			// escape hatch is this flag - eventDisplay 'custom'/'all' and
			// suppress_filters were each tried and do NOT work, so do not
			// "simplify" it to one of those. Cost is unchanged at 5 queries
			// (WordPress primes the post-meta cache for the whole result set
			// in one go), measured at 65.7 ms for all 163.
			$events = get_posts(
				array(
					'post_type'                    => 'tribe_events',
					'post_status'                  => 'publish',
					'posts_per_page'               => -1,
					'orderby'                      => 'title',
					'order'                        => 'ASC',
					'tribe_suppress_query_filters' => true,
				)
			);

			// Defense in depth for the same bug: whatever the query returns,
			// the tournament's CURRENTLY linked event must always be offered,
			// or the <select> falls back to its first option ("-- none --")
			// and a plain Save silently unlinks the tournament -
			// handle_save_tournament() reads this field straight into
			// event_post_id, so an absent option is real data loss, not just
			// a display gap. Keeps working even if TEC changes its query
			// behavior again.
			$selected_id = (int) $selected_id;
			if ( $selected_id > 0 ) {
				$already_listed = false;
				foreach ( $events as $event ) {
					if ( (int) $event->ID === $selected_id ) {
						$already_listed = true;
						break;
					}
				}
				if ( ! $already_listed ) {
					$selected_post = get_post( $selected_id );
					if ( $selected_post && 'tribe_events' === $selected_post->post_type ) {
						$events[] = $selected_post;
					}
				}
			}

			// Re-order by the event's own start date, most recent first,
			// rather than by title: _EventStartDate is a plain 'Y-m-d H:i:s'
			// string, so a lexical comparison sorts it correctly with no
			// date parsing needed. Fetched once per event here rather than
			// via a 'meta_key' clause on the get_posts() call above, since
			// that would implicitly drop any event with no start date meta
			// from the list entirely; events missing the meta simply sort
			// last instead.
			usort(
				$events,
				function ( $a, $b ) {
					$a_start = get_post_meta( $a->ID, '_EventStartDate', true );
					$b_start = get_post_meta( $b->ID, '_EventStartDate', true );
					if ( '' === $a_start && '' === $b_start ) {
						return strcmp( $a->post_title, $b->post_title );
					}
					if ( '' === $a_start ) {
						return 1;
					}
					if ( '' === $b_start ) {
						return -1;
					}
					return strcmp( $b_start, $a_start ); // descending.
				}
			);

			echo '<select id="wpmtm-event-post-id" name="event_post_id">';
			echo '<option value="0">' . esc_html__( '-- none --', 'wp-tournament-manager' ) . '</option>';
			foreach ( $events as $event ) {
				// The event's own start date is appended to its title so two
				// events with the same name (e.g. an annual tournament) are
				// distinguishable in the select; the suffix is simply
				// omitted when the event carries no _EventStartDate meta.
				$label = get_the_title( $event );
				$start = get_post_meta( $event->ID, '_EventStartDate', true );
				if ( $start ) {
					$start_ts = strtotime( $start );
					if ( $start_ts ) {
						$label .= ' - ' . date_i18n( get_option( 'date_format' ), $start_ts );
					}
				}

				// data-begin / data-end let assets/wpmtm-admin.js prefill the
				// Begin/End date fields from the selected event, without
				// overwriting whatever the TD has already typed there.
				$begin_date = ( is_string( $start ) && strlen( $start ) >= 10 ) ? substr( $start, 0, 10 ) : '';
				$end_meta   = get_post_meta( $event->ID, '_EventEndDate', true );
				$end_date   = ( is_string( $end_meta ) && strlen( $end_meta ) >= 10 ) ? substr( $end_meta, 0, 10 ) : $begin_date;

				printf(
					'<option value="%1$d"%2$s data-begin="%3$s" data-end="%4$s">%5$s</option>',
					(int) $event->ID,
					selected( $selected_id, $event->ID, false ),
					esc_attr( $begin_date ),
					esc_attr( $end_date ),
					esc_html( $label )
				);
			}
			echo '</select>';
			echo '<p class="description">' . esc_html__( 'The Events Calendar event this tournament belongs to.', 'wp-tournament-manager' ) . '</p>';
		} else {
			printf(
				'<input type="number" min="0" id="wpmtm-event-post-id" name="event_post_id" value="%1$d">',
				(int) $selected_id
			);
			echo '<p class="description">' . esc_html__( 'The Events Calendar is not active, so enter the linked event post ID directly (optional).', 'wp-tournament-manager' ) . '</p>';
		}
		echo '<p class="description">' . esc_html__( 'Linking a tournament to its The Events Calendar event page is what puts results and standings on that page: every visitor sees the standings there, and tournament directors also get the round-entry panel, the same way ETR adds its Registrations tab. One tournament per event.', 'wp-tournament-manager' ) . '</p>';
	}

	public function handle_save_tournament() {
		$tournament_id = isset( $_POST['tournament_id'] ) ? absint( $_POST['tournament_id'] ) : 0;
		check_admin_referer( 'wpmtm_save_tournament_' . $tournament_id, 'wpmtm_tournament_nonce' );
		$this->require_capability();

		// Captured before any update, so a changed (or cleared) event link
		// can flush both the old event's page - which stops showing this
		// tournament's block - and the new one, not just whichever one the
		// tournament points at after this save.
		$old_event_post_id = 0;
		if ( $tournament_id ) {
			$existing = WPMTM_Repository::get_tournament( $tournament_id );
			if ( $existing ) {
				if ( ! WPMTM_Roles::user_can_manage_tournament( $existing ) ) {
					wp_die( esc_html__( 'No permission to edit this tournament.', 'wp-tournament-manager' ) );
				}
				$old_event_post_id = (int) $existing->event_post_id;
			}
		}

		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

		if ( '' === $name ) {
			$this->set_notice( 'error', __( 'Tournament name is required. Nothing was saved.', 'wp-tournament-manager' ) );
			wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=wpmtm' ) );
			exit;
		}

		// S3: length caps before write. These fields are already
		// sanitize_text_field'd above/below; truncate here rather than let
		// the DB reject the row or silently clip mid-write. Widths match
		// the wpmtm_tournaments column definitions in WPMTM_Schema. mb_substr
		// (WordPress ships a compat shim, so it is always available) is used
		// instead of substr() so a multi-byte character is never split mid-
		// character at the cap.
		$name = mb_substr( $name, 0, 191 );

		$rated         = ! empty( $_POST['rated'] ) ? 1 : 0;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_date() (below) applies sanitize_text_field() and a strict Y-m-d format check, returning null on anything malformed; the sniff cannot see through the wrapper call.
		$begin_date    = $this->sanitize_date( isset( $_POST['begin_date'] ) ? wp_unslash( $_POST['begin_date'] ) : '' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_date(), see note above.
		$end_date      = $this->sanitize_date( isset( $_POST['end_date'] ) ? wp_unslash( $_POST['end_date'] ) : '' );
		$event_post_id = isset( $_POST['event_post_id'] ) ? absint( $_POST['event_post_id'] ) : 0;
		$city          = isset( $_POST['city'] ) ? sanitize_text_field( wp_unslash( $_POST['city'] ) ) : '';
		$city          = mb_substr( $city, 0, 191 );
		$state         = isset( $_POST['state'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['state'] ) ) ) : '';
		$state         = substr( preg_replace( '/[^A-Z]/', '', $state ), 0, 2 );
		$zipcode       = isset( $_POST['zipcode'] ) ? sanitize_text_field( wp_unslash( $_POST['zipcode'] ) ) : '';
		$zipcode       = mb_substr( $zipcode, 0, 10 );
		$send_x        = ! empty( $_POST['send_crosstable'] ) ? 1 : 0;
		$show_photos   = ! empty( $_POST['show_photos'] ) ? 1 : 0;

		// Per-tournament TD ID overrides (docs/SPEC.md, "Decisions
		// (2026-07-11, per-tournament TD overrides)"): same 8-digit-or-blank
		// validation WPMTM_Settings::sanitize_options() uses for the club
		// defaults. A malformed value aborts the whole save with nothing
		// written, the same way a missing tournament name does above, rather
		// than silently dropping just this one field.
		$head_td_id      = isset( $_POST['head_td_id'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['head_td_id'] ) ) ) : '';
		$assistant_td_id = isset( $_POST['assistant_td_id'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['assistant_td_id'] ) ) ) : '';

		if ( '' !== $head_td_id && ! preg_match( '/^\d{8}$/', $head_td_id ) ) {
			$this->set_notice( 'error', __( 'Chief TD USCF ID must be blank or 8 digits. Nothing was saved.', 'wp-tournament-manager' ) );
			wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=wpmtm' ) );
			exit;
		}
		if ( '' !== $assistant_td_id && ! preg_match( '/^\d{8}$/', $assistant_td_id ) ) {
			$this->set_notice( 'error', __( 'Assistant TD USCF ID must be blank or 8 digits. Nothing was saved.', 'wp-tournament-manager' ) );
			wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=wpmtm' ) );
			exit;
		}

		// Per-tournament affiliate ID override (docs/SPEC.md, "Decisions
		// (2026-07-18, per-tournament USCF affiliate ID)"): validated with
		// the same shape (a letter followed by digits) WPMTM_USCF_Status::
		// sanitize_affiliate_id() already enforces for every other affiliate
		// ID input (registration check, CLI, Settings). A malformed value
		// aborts the whole save with nothing written, the same as the two TD
		// ID fields above, rather than silently dropping just this field.
		// wpmtm_tournaments.affiliate_id is varchar(10), so a sanitized
		// result longer than that (there is no upper digit-count cap in
		// sanitize_affiliate_id() itself) is also rejected here rather than
		// risk a silent MySQL truncation.
		$affiliate_id_raw = isset( $_POST['affiliate_id'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['affiliate_id'] ) ) ) : '';
		$affiliate_id     = '' !== $affiliate_id_raw ? WPMTM_USCF_Status::sanitize_affiliate_id( $affiliate_id_raw ) : '';
		if ( '' !== $affiliate_id_raw && ( '' === $affiliate_id || strlen( $affiliate_id ) > 10 ) ) {
			$this->set_notice( 'error', __( 'USCF affiliate ID must be blank or a letter followed by digits (e.g. A1234567). Nothing was saved.', 'wp-tournament-manager' ) );
			wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=wpmtm' ) );
			exit;
		}

		// S2: a linked event must actually exist, and (when The Events
		// Calendar is active) must be a tribe_events post; otherwise the
		// link is dropped rather than stored pointing at nothing/the wrong
		// post type. The TD is told in the success notice below.
		$event_link_cleared = false;
		if ( $event_post_id > 0 ) {
			$validated_event_id = WPMTM_Plugin::validate_event_post_id( $event_post_id );
			if ( ! $validated_event_id ) {
				$event_post_id      = 0;
				$event_link_cleared = true;
			} else {
				// C1: pre-check for another tournament already owning this
				// event before writing, so the TD gets a clear "already
				// linked to X" notice instead of a bare "Duplicate entry"
				// DB error. The DB unique constraint (if any) remains the
				// authoritative fallback below in case of a race.
				$owner = WPMTM_Repository::get_tournament_by_event( $validated_event_id );
				if ( $owner && (int) $owner->id !== $tournament_id ) {
					$this->set_notice(
						'error',
						sprintf(
							/* translators: %s: name of the tournament the event is already linked to */
							__( 'That event is already linked to the tournament "%s" - each event can have only one.', 'wp-tournament-manager' ),
							$owner->name
						)
					);
					wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=wpmtm' ) );
					exit;
				}
			}
		}

		global $wpdb;
		$table = WPMTM_Schema::table( 'tournaments' );
		$now   = current_time( 'mysql' );

		$data = array(
			'event_post_id'   => $event_post_id ? $event_post_id : null,
			'name'            => $name,
			'rated'           => $rated,
			'begin_date'      => $begin_date,
			'end_date'        => $end_date,
			'city'            => '' !== $city ? $city : null,
			'state'           => '' !== $state ? $state : null,
			'zipcode'         => '' !== $zipcode ? $zipcode : null,
			'head_td_id'      => '' !== $head_td_id ? $head_td_id : null,
			'assistant_td_id' => '' !== $assistant_td_id ? $assistant_td_id : null,
			'affiliate_id'    => '' !== $affiliate_id ? $affiliate_id : null,
			'send_crosstable' => $send_x,
			'show_photos'     => $show_photos,
			'updated_at'      => $now,
		);
		$formats = array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' );

		if ( $tournament_id ) {
			$result = $wpdb->update( $table, $data, array( 'id' => $tournament_id ), $formats, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- custom wpmtm_tournaments table, no core API; $wpdb->update() escapes values via the $formats array. Not a cacheable read.
		} else {
			$data['status']     = 'setup';
			$data['country']    = 'USA';
			$data['created_by'] = get_current_user_id();
			$data['created_at'] = $now;
			$formats[]          = '%s';
			$formats[]          = '%s';
			$formats[]          = '%d';
			$formats[]          = '%s';
			$result = $wpdb->insert( $table, $data, $formats ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom wpmtm_tournaments table, no core API; $wpdb->insert() escapes values via the $formats array.
			if ( false !== $result ) {
				$tournament_id = (int) $wpdb->insert_id;
			}
		}

		// This handler writes wpmtm_tournaments with its own $wpdb call rather
		// than through the repository, so it drops the repository's per-request
		// read memo itself (audit item 48).
		WPMTM_Repository::flush_memo();

		// S1: surface a write failure instead of claiming success.
		if ( false === $result ) {
			$message = ( false !== strpos( (string) $wpdb->last_error, 'Duplicate entry' ) )
				? __( 'That event is already linked to another tournament - each event can have only one.', 'wp-tournament-manager' )
				: __( 'The tournament could not be saved.', 'wp-tournament-manager' );
			$this->set_notice( 'error', $message );
			$fallback = $tournament_id
				? add_query_arg( array( 'page' => 'wpmtm-edit', 'id' => $tournament_id ), admin_url( 'admin.php' ) )
				: admin_url( 'admin.php?page=wpmtm-edit' );
			wp_safe_redirect( wp_get_referer() ? wp_get_referer() : $fallback );
			exit;
		}

		if ( $old_event_post_id && $old_event_post_id !== $event_post_id ) {
			WPMTM_Cache::flush_event_page( $old_event_post_id );
		}
		WPMTM_Cache::flush_event_page( $event_post_id );

		$message = __( 'Tournament saved.', 'wp-tournament-manager' );
		if ( $event_link_cleared ) {
			$message .= ' ' . __( 'The linked event could not be found, so the link was cleared.', 'wp-tournament-manager' );
		}
		$this->set_notice( 'success', $message );

		$this->maybe_notice_td_status( $head_td_id, $assistant_td_id, $end_date );

		wp_safe_redirect( add_query_arg( array( 'page' => 'wpmtm-edit', 'id' => $tournament_id ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Chief + assistant TD USCF status check on tournament create/save
	 * (docs/SPEC.md v1.2 section 4): warn-only, an admin_notices message
	 * queued through the same one-shot transient pipeline (WPMTM_Admin_Shared
	 * ::set_notice()/render_notices()) the save success message above
	 * already uses, so it survives the redirect and renders on the
	 * tournament edit page a TD lands on after saving. Never blocks the
	 * save - this runs after the write has already succeeded. Uses the
	 * normal (cached) USCF lookup, not a forced fresh one - unlike the
	 * DBF export gate, nothing here blocks anything, so a brief staleness
	 * window is an acceptable trade against hitting the API on every
	 * tournament save.
	 *
	 * No Settings fallback (docs/SPEC.md, 2026-07-17, "TD default removal"):
	 * this checks ONLY the tournament's own chief/assistant TD id, the same
	 * as the DBF export and the "Validate with USCF" button, so a
	 * tournament with no TD of its own never triggers a warning about a
	 * club-wide default it does not actually use.
	 *
	 * @param string $head_td_id      This tournament's chief TD id, or ''.
	 * @param string $assistant_td_id This tournament's assistant TD id, or ''.
	 * @param string $end_date        Tournament end date (YYYY-MM-DD), or ''.
	 */
	protected function maybe_notice_td_status( $head_td_id, $assistant_td_id, $end_date ) {
		$chief     = trim( (string) $head_td_id );
		$assistant = trim( (string) $assistant_td_id );

		if ( '' === trim( $chief ) && '' === trim( $assistant ) ) {
			return; // Nothing to check - unrated tournaments commonly have neither set.
		}

		$through = WPMTM_USCF_Status::resolve_through_date( (string) $end_date, '', '' );
		$status  = WPMTM_USCF_Status::instance();
		$lines   = array();

		$roles = array(
			array( __( 'Chief TD', 'wp-tournament-manager' ), $chief ),
			array( __( 'Assistant TD', 'wp-tournament-manager' ), $assistant ),
		);
		foreach ( $roles as $pair ) {
			list( $role, $id ) = $pair;
			if ( '' === trim( $id ) ) {
				continue;
			}
			$verdict = $status->validate_td( $id, $through );
			if ( 'FAIL' === $verdict['verdict'] ) {
				/* translators: 1: TD role (Chief TD / Assistant TD); 2: reason the check failed */
				$lines[] = sprintf( __( '%1$s: %2$s', 'wp-tournament-manager' ), $role, $verdict['reason'] );
			} elseif ( 'UNKNOWN' === $verdict['verdict'] ) {
				/* translators: %s: TD role (Chief TD / Assistant TD) */
				$lines[] = sprintf( __( '%s: USCF status could not be verified right now.', 'wp-tournament-manager' ), $role );
			}
		}

		if ( empty( $lines ) ) {
			return;
		}

		$this->set_notice(
			'warning',
			__( 'USCF TD check (does not block saving): ', 'wp-tournament-manager' ) . implode( ' ', $lines )
		);
	}

	public function handle_delete_tournament() {
		$tournament_id = isset( $_REQUEST['tournament_id'] ) ? absint( $_REQUEST['tournament_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce is verified on the next line via check_admin_referer().
		check_admin_referer( 'wpmtm_delete_tournament_' . $tournament_id );
		$this->require_capability();

		// Captured before the cascade delete removes the tournament row, so
		// the now-orphaned event page's cache still gets flushed.
		$tournament = $tournament_id ? WPMTM_Repository::get_tournament( $tournament_id ) : null;

		if ( $tournament && ! WPMTM_Roles::user_can_manage_tournament( $tournament ) ) {
			wp_die( esc_html__( 'No permission to delete this tournament.', 'wp-tournament-manager' ) );
		}

		if ( $tournament ) {
			WPMTM_Repository::delete_tournament_cascade( $tournament_id );
			WPMTM_Cache::flush_event_page( (int) $tournament->event_post_id );
			$this->set_notice( 'success', __( 'Tournament and all its data deleted.', 'wp-tournament-manager' ) );
		} else {
			$this->set_notice( 'error', __( 'That tournament was already deleted or not found.', 'wp-tournament-manager' ) );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wpmtm' ) );
		exit;
	}

	/**
	 * Flips wpmtm_tournaments.locked (Change 6, "conclude and lock a
	 * tournament"): the server-side counterpart to render_lock_control()
	 * above. Locking never happens automatically - this handler, reached
	 * only by that control's own nonced POST form, is the sole place this
	 * flag is ever written.
	 */
	public function handle_toggle_lock() {
		$tournament_id = isset( $_POST['tournament_id'] ) ? absint( $_POST['tournament_id'] ) : 0;
		check_admin_referer( 'wpmtm_toggle_lock_' . $tournament_id, 'wpmtm_toggle_lock_nonce' );
		$this->require_capability();

		$tournament = $tournament_id ? WPMTM_Repository::get_tournament( $tournament_id ) : null;
		if ( ! $tournament ) {
			wp_die( esc_html__( 'Tournament not found.', 'wp-tournament-manager' ) );
		}
		if ( ! WPMTM_Roles::user_can_manage_tournament( $tournament ) ) {
			wp_die( esc_html__( 'No permission to lock/unlock this tournament.', 'wp-tournament-manager' ) );
		}

		$new_locked = ! (bool) $tournament->locked;
		$saved      = WPMTM_Repository::set_tournament_locked( $tournament_id, $new_locked );

		if ( ! $saved ) {
			$this->set_notice( 'error', __( 'The tournament\'s lock state could not be saved.', 'wp-tournament-manager' ) );
		} else {
			WPMTM_Cache::flush_event_page( (int) $tournament->event_post_id );
			$this->set_notice(
				'success',
				$new_locked
					? __( 'Tournament locked and marked complete.', 'wp-tournament-manager' )
					: __( 'Tournament unlocked. Results can be edited again.', 'wp-tournament-manager' )
			);
		}

		// This handler is shared by both the admin edit page's Lock/Unlock
		// button and the front-end event page's copy of it
		// (WPMTM_Frontend_Public::render_td_command_row()) - 2026-07-21: a
		// front-end click was previously always redirected into wp-admin
		// even though the event page it came from could show the new state
		// just as well. wp_get_referer() sends the TD back to whichever
		// page they actually clicked from, falling back to the admin edit
		// page only when there is no referer to return to.
		// The URL fragment (#tab-...) is never sent in the Referer header, so a
		// front-end lock click would otherwise land on the event page's default
		// tab. The lock form carries the tab hash in wpmtm_return_hash (set by JS
		// at submit time in assets/wpmtm-frontend.js); append it, validated down
		// to a bare fragment, so the TD stays on the tab they acted from.
		$return_hash = '';
		if ( isset( $_POST['wpmtm_return_hash'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by check_admin_referer() at the top of this handler.
			$raw = wp_unslash( $_POST['wpmtm_return_hash'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated to a bare #fragment by the regex below before any use.
			if ( is_string( $raw ) && preg_match( '/^#[A-Za-z0-9_-]{0,64}$/', $raw ) ) {
				$return_hash = $raw;
			}
		}
		$redirect_to = wp_get_referer() ? wp_get_referer() : add_query_arg( array( 'page' => 'wpmtm-edit', 'id' => $tournament_id ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $redirect_to . $return_hash );
		exit;
	}

	// -----------------------------------------------------------------
	// Misc helpers
	// -----------------------------------------------------------------

	protected function sanitize_date( $value ) {
		$value = is_array( $value ) ? '' : sanitize_text_field( wp_unslash( $value ) );
		if ( '' === $value ) {
			return null;
		}
		$d = DateTime::createFromFormat( 'Y-m-d', $value );
		if ( ! $d || $d->format( 'Y-m-d' ) !== $value ) {
			return null;
		}
		return $value;
	}
}
