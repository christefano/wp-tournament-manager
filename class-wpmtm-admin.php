<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin CRUD screens under the top-level "Tournament Manager" menu:
 * tournaments list, add/edit tournament, sections editor, and a per-section
 * players editor. Every state-changing request goes through admin-post.php,
 * is nonce-verified, and is gated on WPMTM_CAPABILITY. All output is
 * escaped at render time.
 *
 * The ETR roster-import surface (upload, preview, confirm) lives in
 * WPMTM_Admin_Import; this class only makes the two thin calls the edit
 * screen needs (render_import_box(), maybe_render_preview()).
 *
 * Sections and players are edited as a single bulk form per screen (a
 * repeater table): existing rows can be edited in place, new rows are added
 * client-side (assets/wpmtm-admin.js), and removing a row either drops an
 * unsaved row from the DOM or flags an existing row for server-side
 * deletion via a hidden "removed_*" field. One Save submits the whole set.
 */
class WPMTM_Admin {

	use WPMTM_Admin_Shared;

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

		add_action( 'admin_post_wpmtm_save_tournament', array( $this, 'handle_save_tournament' ) );
		add_action( 'admin_post_wpmtm_delete_tournament', array( $this, 'handle_delete_tournament' ) );
		add_action( 'admin_post_wpmtm_save_sections', array( $this, 'handle_save_sections' ) );
		add_action( 'admin_post_wpmtm_save_players', array( $this, 'handle_save_players' ) );
		add_action( 'admin_post_wpmtm_toggle_lock', array( $this, 'handle_toggle_lock' ) );
	}

	// -----------------------------------------------------------------
	// Menu / assets
	// -----------------------------------------------------------------

	public function register_menu() {
		add_menu_page(
			__( 'Tournament Manager', 'wp-tournament-manager' ),
			__( 'Tournament Manager', 'wp-tournament-manager' ),
			WPMTM_CAPABILITY,
			'wpmtm',
			array( $this, 'render_tournaments_list' ),
			'dashicons-awards'
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
		if ( ! isset( $_GET['page'] ) || 0 !== strpos( sanitize_key( wp_unslash( $_GET['page'] ) ), 'wpmtm' ) ) {
			return;
		}
		wp_enqueue_style( 'wpmtm-admin', WPMTM_PLUGIN_URL . 'assets/wpmtm-admin.css', array(), WPMTM_VERSION );
		wp_enqueue_script( 'wpmtm-admin', WPMTM_PLUGIN_URL . 'assets/wpmtm-admin.js', array(), WPMTM_VERSION, true );
		// Strings for the "Validate TDs" button (the Settings page and the
		// tournament edit page - both pass the wpmtm page-prefix gate
		// above); see assets/wpmtm-admin.js behavior 6 and
		// WPMTM_USCF_Status::ajax_validate_tds().
		wp_localize_script(
			'wpmtm-admin',
			'wpmtmValidateTds',
			array(
				'checking'      => __( 'Checking...', 'wp-tournament-manager' ),
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

		$tournaments = WPMTM_Repository::tournaments_with_counts();
		?>
		<div class="wrap wpmtm-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Tournament Manager', 'wp-tournament-manager' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpmtm-edit' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'wp-tournament-manager' ); ?></a>
			<?php if ( WPMTM_Wizard::instance()->is_active() ) : ?>
				<a href="<?php echo esc_url( WPMTM_Wizard::instance()->stop_url() ); ?>" class="page-title-action"><?php esc_html_e( 'Exit guided setup', 'wp-tournament-manager' ); ?></a>
			<?php else : ?>
				<a href="<?php echo esc_url( WPMTM_Wizard::instance()->start_url() ); ?>" class="page-title-action"><?php esc_html_e( 'Start guided setup', 'wp-tournament-manager' ); ?></a>
			<?php endif; ?>
			<hr class="wp-header-end">
			<?php $this->render_admin_header(); ?>
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
							<td><?php echo esc_html( ucfirst( $t->status ) ); ?></td>
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

		$tournament_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$section_id    = isset( $_GET['section_id'] ) ? absint( $_GET['section_id'] ) : 0;

		$tournament = $tournament_id ? WPMTM_Repository::get_tournament( $tournament_id ) : null;
		if ( $tournament_id && ! $tournament ) {
			wp_die( esc_html__( 'Tournament not found.', 'wp-tournament-manager' ) );
		}

		if ( $tournament && $section_id ) {
			$section = WPMTM_Repository::get_section( $section_id );
			if ( ! $section || (int) $section->tournament_id !== $tournament_id ) {
				wp_die( esc_html__( 'Section not found.', 'wp-tournament-manager' ) );
			}
			$this->render_players_editor( $tournament, $section );
			return;
		}

		if ( $tournament && isset( $_GET['wpmtm_etr_step'] ) && 'preview' === $_GET['wpmtm_etr_step'] ) {
			if ( WPMTM_Admin_Import::instance()->maybe_render_preview( $tournament ) ) {
				return;
			}
		}

		$this->render_tournament_form( $tournament );

		if ( $tournament ) {
			$this->render_sections_editor( $tournament );
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
		$send_x        = $is_edit ? (bool) $tournament->send_crosstable : false;
		$show_photos   = $is_edit ? (bool) $tournament->show_photos : false;
		?>
		<div class="wrap wpmtm-wrap">
			<h1><?php echo $is_edit ? esc_html__( 'Edit Tournament', 'wp-tournament-manager' ) : esc_html__( 'Add Tournament', 'wp-tournament-manager' ); ?></h1>
			<?php
			$event_permalink = $event_post_id ? get_permalink( $event_post_id ) : false;
			if ( $event_permalink ) :
				?>
				<p class="wpmtm-switch-to-event">
					<a href="<?php echo esc_url( $event_permalink ); ?>"><?php esc_html_e( 'Switch to event', 'wp-tournament-manager' ); ?></a>
					&nbsp;|&nbsp;
					<a href="<?php echo esc_url( $event_permalink . '#tab-registrations' ); ?>"><?php esc_html_e( "View the event's Registrations tab", 'wp-tournament-manager' ); ?></a>
				</p>
			<?php endif; ?>
			<?php if ( $is_edit ) : ?>
				<?php $this->render_lock_control( $tournament ); ?>
			<?php endif; ?>
			<?php $this->render_admin_header(); ?>
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
							<label><input type="checkbox" name="rated" value="1" <?php checked( $rated ); ?>> <?php esc_html_e( 'This is a USCF rated tournament', 'wp-tournament-manager' ); ?></label>
							<p class="description"><?php esc_html_e( 'Unrated tournaments never require the affiliate ID or TD IDs, and never use DBF export.', 'wp-tournament-manager' ); ?></p>
							<p class="description"><?php esc_html_e( 'This is the master switch: an unrated tournament never exports, no matter what any individual section says. When this is checked, each section also has its own Rated checkbox (in the Sections editor and the import preview), and only rated sections are included in the USCF files - so one event can mix rated and unrated sections, e.g. a rated Open next to an unrated scholastic side event.', 'wp-tournament-manager' ); ?></p>
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
							<p class="description"><?php esc_html_e( 'Leave blank to use the club default from Tournament Manager Settings.', 'wp-tournament-manager' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpmtm-assistant-td-id"><?php esc_html_e( 'Assistant TD USCF ID', 'wp-tournament-manager' ); ?></label></th>
						<td>
							<input type="text" id="wpmtm-assistant-td-id" class="regular-text" maxlength="8" name="assistant_td_id" value="<?php echo esc_attr( $assistant_td ); ?>" placeholder="">
							<p class="description"><?php esc_html_e( 'Leave blank to use the club default from Tournament Manager Settings.', 'wp-tournament-manager' ); ?></p>
						</td>
					</tr>
					<?php if ( $is_edit ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'USCF status', 'wp-tournament-manager' ); ?></th>
							<td>
								<button type="button" class="button" data-wpmtm-validate-tds data-context="tournament" data-tournament="<?php echo esc_attr( $tournament_id ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wpmtm_validate_tds' ) ); ?>">
									<?php esc_html_e( 'Validate TDs', 'wp-tournament-manager' ); ?>
								</button>
								<p class="description"><?php esc_html_e( 'Checks the club affiliate ID from Settings plus the effective Chief and Assistant TD IDs (the overrides above when set, or the Settings defaults) against the USCF ratings API, as active through the tournament end date. Advisory only - nothing is blocked by the result. Checks use the saved values, so save the tournament first if you changed the IDs above.', 'wp-tournament-manager' ); ?></p>
								<div data-wpmtm-validate-tds-results></div>
							</td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Crosstable flag', 'wp-tournament-manager' ); ?></th>
						<td>
							<label><input type="checkbox" name="send_crosstable" value="1" <?php checked( $send_x ); ?>> <?php esc_html_e( 'Set the crosstable flag (H_SENDCROS) in the USCF export header', 'wp-tournament-manager' ); ?></label>
							<p class="description"><?php esc_html_e( 'A leftover from the paper era: this header flag asked US Chess to mail the affiliate a printed crosstable of the rated event. Results appear online at ratings.uschess.org regardless, so nearly every club leaves this off.', 'wp-tournament-manager' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Show profile pictures', 'wp-tournament-manager' ); ?></th>
						<td>
							<label><input type="checkbox" name="show_photos" value="1" <?php checked( $show_photos ); ?>> <?php esc_html_e( 'Show profile pictures', 'wp-tournament-manager' ); ?></label>
							<p class="description"><?php esc_html_e( 'Shows registrant photos, when available from the event registration, on the public standings, wall chart, and pairing aid. Players without a photo get a neutral silhouette; layout is identical either way.', 'wp-tournament-manager' ); ?></p>
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
			$events = get_posts(
				array(
					'post_type'      => 'tribe_events',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'orderby'        => 'title',
					'order'          => 'ASC',
				)
			);

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
				$old_event_post_id = (int) $existing->event_post_id;
			}
		}

		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

		if ( '' === $name ) {
			$this->set_notice( 'error', __( 'Tournament name is required; nothing was saved.', 'wp-tournament-manager' ) );
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
		$begin_date    = $this->sanitize_date( isset( $_POST['begin_date'] ) ? $_POST['begin_date'] : '' );
		$end_date      = $this->sanitize_date( isset( $_POST['end_date'] ) ? $_POST['end_date'] : '' );
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
			$this->set_notice( 'error', __( 'Chief TD USCF ID must be blank or 8 digits; nothing was saved.', 'wp-tournament-manager' ) );
			wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=wpmtm' ) );
			exit;
		}
		if ( '' !== $assistant_td_id && ! preg_match( '/^\d{8}$/', $assistant_td_id ) ) {
			$this->set_notice( 'error', __( 'Assistant TD USCF ID must be blank or 8 digits; nothing was saved.', 'wp-tournament-manager' ) );
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
			'send_crosstable' => $send_x,
			'show_photos'     => $show_photos,
			'updated_at'      => $now,
		);
		$formats = array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' );

		if ( $tournament_id ) {
			$result = $wpdb->update( $table, $data, array( 'id' => $tournament_id ), $formats, array( '%d' ) );
		} else {
			$data['status']     = 'setup';
			$data['country']    = 'USA';
			$data['created_at'] = $now;
			$formats[]          = '%s';
			$formats[]          = '%s';
			$formats[]          = '%s';
			$result = $wpdb->insert( $table, $data, $formats );
			if ( false !== $result ) {
				$tournament_id = (int) $wpdb->insert_id;
			}
		}

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
		wp_safe_redirect( add_query_arg( array( 'page' => 'wpmtm-edit', 'id' => $tournament_id ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_delete_tournament() {
		$tournament_id = isset( $_REQUEST['tournament_id'] ) ? absint( $_REQUEST['tournament_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce is verified on the next line via check_admin_referer().
		check_admin_referer( 'wpmtm_delete_tournament_' . $tournament_id );
		$this->require_capability();

		// Captured before the cascade delete removes the tournament row, so
		// the now-orphaned event page's cache still gets flushed.
		$tournament = $tournament_id ? WPMTM_Repository::get_tournament( $tournament_id ) : null;

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
					: __( 'Tournament unlocked; results can be edited again.', 'wp-tournament-manager' )
			);
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'wpmtm-edit', 'id' => $tournament_id ), admin_url( 'admin.php' ) ) );
		exit;
	}

	// -----------------------------------------------------------------
	// Sections editor
	// -----------------------------------------------------------------

	protected function render_sections_editor( $tournament ) {
		$sections = WPMTM_Repository::get_sections( $tournament->id );
		$counts   = WPMTM_Repository::player_counts_by_section( $tournament->id );
		$presets  = WPMTM_Plugin::instance()->get_timectl_presets();
		?>
		<div class="wrap wpmtm-wrap">
			<h2><?php esc_html_e( 'Sections', 'wp-tournament-manager' ); ?></h2>

			<div class="notice notice-info inline">
				<p><?php esc_html_e( 'Each row is one section - a separate group of players, such as Open or Reserve.', 'wp-tournament-manager' ); ?></p>
				<ul>
					<li><?php esc_html_e( '# is assigned automatically; you cannot edit it here.', 'wp-tournament-manager' ); ?></li>
					<li><?php esc_html_e( 'Rating system is normally worked out from the time control (Regular, Quick, or Blitz, per USCF rules); a warning appears when you save if they disagree.', 'wp-tournament-manager' ); ?></li>
					<li><?php esc_html_e( 'Time control uses USCF notation, e.g. G/30;d0 - start typing to see suggestions from Settings.', 'wp-tournament-manager' ); ?></li>
					<li><?php esc_html_e( 'Rounds is the total number of rounds planned for the section.', 'wp-tournament-manager' ); ?></li>
					<li><?php esc_html_e( 'Type is Swiss by default. Round Robin pairs every player against every other player once; the pairing aid and USCF submission both adapt automatically (see the note under Type when it is selected).', 'wp-tournament-manager' ); ?></li>
					<li><?php esc_html_e( 'Quad is a 4-player round robin, 3 rounds; it behaves exactly like Round Robin everywhere. The import screen can also split a large section into quads automatically.', 'wp-tournament-manager' ); ?></li>
					<li><?php esc_html_e( 'Advanced holds optional Grand Prix and scholastic settings.', 'wp-tournament-manager' ); ?></li>
					<li><?php esc_html_e( 'Rated controls whether the section goes into the USCF export.', 'wp-tournament-manager' ); ?></li>
					<li><?php esc_html_e( '"Manage (n)" opens that section\'s player list, where pairing numbers are assigned automatically by rating.', 'wp-tournament-manager' ); ?></li>
					<li><?php esc_html_e( 'Remove deletes the section, and once you click Save, all of its players, games, and byes too.', 'wp-tournament-manager' ); ?></li>
				</ul>
			</div>

			<?php if ( $presets ) : ?>
				<datalist id="wpmtm-timectl-presets">
					<?php foreach ( $presets as $preset ) : ?>
						<option value="<?php echo esc_attr( $preset ); ?>"></option>
					<?php endforeach; ?>
				</datalist>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wpmtm_save_sections_' . $tournament->id, 'wpmtm_sections_nonce' ); ?>
				<input type="hidden" name="action" value="wpmtm_save_sections">
				<input type="hidden" name="tournament_id" value="<?php echo esc_attr( $tournament->id ); ?>">
				<input type="hidden" id="wpmtm-removed-sections" name="removed_sections" value="">

				<table class="wp-list-table widefat fixed striped wpmtm-repeater" id="wpmtm-sections-table" data-wpmtm-repeater data-removed-input="wpmtm-removed-sections">
					<thead>
						<tr>
							<th class="wpmtm-col-num"><?php esc_html_e( '#', 'wp-tournament-manager' ); ?></th>
							<th><?php esc_html_e( 'Name', 'wp-tournament-manager' ); ?></th>
							<th><?php esc_html_e( 'Rating system', 'wp-tournament-manager' ); ?></th>
							<th><?php esc_html_e( 'Time control', 'wp-tournament-manager' ); ?></th>
							<th><?php esc_html_e( 'Rounds', 'wp-tournament-manager' ); ?></th>
							<th><?php esc_html_e( 'Type', 'wp-tournament-manager' ); ?></th>
							<th><?php esc_html_e( 'Advanced', 'wp-tournament-manager' ); ?></th>
							<th><?php esc_html_e( 'Rated', 'wp-tournament-manager' ); ?></th>
							<th><?php esc_html_e( 'Players', 'wp-tournament-manager' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $sections as $section ) : ?>
						<?php $this->render_section_row( $section, $tournament->id, null, isset( $counts[ $section->id ] ) ? $counts[ $section->id ] : 0 ); ?>
					<?php endforeach; ?>
					</tbody>
					<template>
						<?php $this->render_section_row( null, $tournament->id, '__INDEX__' ); ?>
					</template>
				</table>
				<p><button type="button" class="button" data-add-row-for="wpmtm-sections-table"><?php esc_html_e( '+ Add section', 'wp-tournament-manager' ); ?></button></p>
				<p class="description"><?php esc_html_e( 'Unrated sections are never included in the USCF export.', 'wp-tournament-manager' ); ?></p>
				<?php submit_button( __( 'Save Sections', 'wp-tournament-manager' ) ); ?>
			</form>
		</div>
		<?php
	}

	protected function render_section_row( $section, $tournament_id, $index = null, $player_count = 0 ) {
		$is_template = null === $section;
		$key         = $is_template ? $index : $section->id;
		$sec_num     = $is_template ? '' : $section->sec_num;
		$sec_name    = $is_template ? '' : $section->sec_name;
		$r_system    = $is_template ? 'R' : $section->r_system;
		$timectl     = $is_template ? '' : $section->timectl;
		$tot_rnds    = $is_template ? '' : $section->tot_rnds;
		$trn_type    = $is_template ? 'S' : $section->trn_type;
		$sch_lvl     = $is_template ? '' : $section->sch_lvl;
		$gr_prix     = ! $is_template && 'Y' === $section->gr_prix;
		$gp_pts      = $is_template ? '' : $section->gp_pts;
		$rated       = $is_template ? true : (bool) $section->rated;

		$players_link = '';
		if ( ! $is_template ) {
			$players_link = add_query_arg( array( 'page' => 'wpmtm-edit', 'id' => $tournament_id, 'section_id' => $section->id ), admin_url( 'admin.php' ) );
		}
		?>
		<tr<?php echo $is_template ? '' : ' data-existing-id="' . esc_attr( $key ) . '"'; ?>>
			<td class="wpmtm-col-num"><?php echo $is_template ? esc_html__( 'auto', 'wp-tournament-manager' ) : esc_html( $sec_num ); ?></td>
			<td><input type="text" maxlength="30" name="sections[<?php echo esc_attr( $key ); ?>][sec_name]" value="<?php echo esc_attr( $sec_name ); ?>" placeholder="<?php esc_attr_e( 'e.g. Open', 'wp-tournament-manager' ); ?>"></td>
			<td>
				<select name="sections[<?php echo esc_attr( $key ); ?>][r_system]">
					<?php
					$systems = array(
						'R' => __( 'Regular', 'wp-tournament-manager' ),
						'Q' => __( 'Quick', 'wp-tournament-manager' ),
						'B' => __( 'Blitz', 'wp-tournament-manager' ),
					);
					foreach ( $systems as $code => $label ) :
						?>
						<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $r_system, $code ); ?>><?php echo esc_html( $label . ' (' . $code . ')' ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td><input type="text" list="wpmtm-timectl-presets" maxlength="40" name="sections[<?php echo esc_attr( $key ); ?>][timectl]" value="<?php echo esc_attr( $timectl ); ?>" placeholder="G/30;d0"></td>
			<td><input type="number" min="0" max="99" class="small-text" name="sections[<?php echo esc_attr( $key ); ?>][tot_rnds]" value="<?php echo esc_attr( $tot_rnds ); ?>"></td>
			<td>
				<select name="sections[<?php echo esc_attr( $key ); ?>][trn_type]" data-wpmtm-trn-type>
					<option value="S" <?php selected( $trn_type, 'S' ); ?>><?php esc_html_e( 'Swiss', 'wp-tournament-manager' ); ?></option>
					<option value="R" <?php selected( $trn_type, 'R' ); ?>><?php esc_html_e( 'Round Robin', 'wp-tournament-manager' ); ?></option>
					<option value="Q" <?php selected( $trn_type, 'Q' ); ?>><?php esc_html_e( 'Quad (4-player round robin)', 'wp-tournament-manager' ); ?></option>
					<option value="DRR" disabled><?php esc_html_e( 'Double Round Robin (not yet supported)', 'wp-tournament-manager' ); ?></option>
					<option value="TS" disabled><?php esc_html_e( 'Team Swiss (not yet supported)', 'wp-tournament-manager' ); ?></option>
					<option value="M" disabled><?php esc_html_e( 'Match (not yet supported)', 'wp-tournament-manager' ); ?></option>
				</select>
				<p class="description wpmtm-rr-hint" data-wpmtm-rr-hint <?php echo in_array( $trn_type, WPMTM_Pairing_Aid::RR_TYPES, true ) ? '' : 'hidden'; ?>>
					<?php esc_html_e( 'Round robin: every player faces every other player once; total rounds is players minus 1 for an even player count, or equal to the player count for an odd count (everyone sits out once). A quad is the same thing fixed at 4 players and 3 rounds.', 'wp-tournament-manager' ); ?>
				</p>
			</td>
			<td class="wpmtm-col-advanced">
				<details>
					<summary><?php esc_html_e( 'Advanced', 'wp-tournament-manager' ); ?></summary>
					<div class="wpmtm-advanced-panel">
						<p><label><input type="checkbox" name="sections[<?php echo esc_attr( $key ); ?>][gr_prix]" value="1" <?php checked( $gr_prix ); ?>> <?php esc_html_e( 'Grand Prix', 'wp-tournament-manager' ); ?></label></p>
						<p><label><?php esc_html_e( 'GP points', 'wp-tournament-manager' ); ?> <input type="number" min="0" max="999" class="small-text" name="sections[<?php echo esc_attr( $key ); ?>][gp_pts]" value="<?php echo esc_attr( $gp_pts ); ?>"></label></p>
						<p><label><?php esc_html_e( 'Scholastic level', 'wp-tournament-manager' ); ?> <input type="text" maxlength="1" class="small-text" name="sections[<?php echo esc_attr( $key ); ?>][sch_lvl]" value="<?php echo esc_attr( $sch_lvl ); ?>"></label></p>
					</div>
				</details>
			</td>
			<td><label><input type="checkbox" name="sections[<?php echo esc_attr( $key ); ?>][rated]" value="1" <?php checked( $rated ); ?>> <?php esc_html_e( 'Rated', 'wp-tournament-manager' ); ?></label></td>
			<td>
				<?php if ( ! $is_template ) : ?>
					<a href="<?php echo esc_url( $players_link ); ?>">
						<?php
						printf(
							/* translators: %d: number of players in the section */
							esc_html__( 'Manage (%d)', 'wp-tournament-manager' ),
							(int) $player_count
						);
						?>
					</a>
				<?php endif; ?>
			</td>
			<td><button type="button" class="button-link-delete" data-remove-row><?php esc_html_e( 'Remove', 'wp-tournament-manager' ); ?></button></td>
		</tr>
		<?php
	}

	public function handle_save_sections() {
		$tournament_id = isset( $_POST['tournament_id'] ) ? absint( $_POST['tournament_id'] ) : 0;
		check_admin_referer( 'wpmtm_save_sections_' . $tournament_id, 'wpmtm_sections_nonce' );
		$this->require_capability();

		$tournament = WPMTM_Repository::get_tournament( $tournament_id );
		if ( ! $tournament ) {
			wp_die( esc_html__( 'Tournament not found.', 'wp-tournament-manager' ) );
		}

		global $wpdb;
		$table = WPMTM_Schema::table( 'sections' );

		$rows    = ( isset( $_POST['sections'] ) && is_array( $_POST['sections'] ) ) ? wp_unslash( $_POST['sections'] ) : array();
		$removed = array();
		if ( isset( $_POST['removed_sections'] ) ) {
			$removed = array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['removed_sections'] ) ) ) ) );
		}

		$mismatched  = array();
		$failed_rows = 0;

		foreach ( $rows as $key => $row ) {
			$sec_name = isset( $row['sec_name'] ) ? sanitize_text_field( $row['sec_name'] ) : '';
			$r_system = isset( $row['r_system'] ) ? strtoupper( sanitize_text_field( $row['r_system'] ) ) : 'R';
			if ( ! in_array( $r_system, array( 'R', 'Q', 'B' ), true ) ) {
				$r_system = 'R';
			}
			$timectl  = isset( $row['timectl'] ) ? sanitize_text_field( $row['timectl'] ) : '';
			$tot_rnds = isset( $row['tot_rnds'] ) ? max( 0, absint( $row['tot_rnds'] ) ) : 0;
			// Swiss, Round Robin, and Quad are the real selectable values in
			// this version (docs/SPEC.md, "Decisions (2026-07-09, round
			// robin and quads)" and "Decisions (2026-07-10, quads
			// selectable)"); the other <option>s in the Type select are
			// rendered disabled, so any other posted value is a
			// tampered/stale form and falls back to Swiss.
			$trn_type = isset( $row['trn_type'] ) ? strtoupper( sanitize_text_field( $row['trn_type'] ) ) : 'S';
			if ( 'S' !== $trn_type && ! WPMTM_Pairing_Aid::is_round_robin_type( $trn_type ) ) {
				$trn_type = 'S';
			}
			$sch_lvl  = isset( $row['sch_lvl'] ) ? sanitize_text_field( $row['sch_lvl'] ) : '';
			$sch_lvl  = '' !== $sch_lvl ? strtoupper( substr( $sch_lvl, 0, 1 ) ) : null;
			$gr_prix  = ! empty( $row['gr_prix'] ) ? 'Y' : 'N';
			$gp_pts   = isset( $row['gp_pts'] ) ? max( 0, absint( $row['gp_pts'] ) ) : 0;
			$rated    = ! empty( $row['rated'] ) ? 1 : 0;
			// No FIDE support (owner decision 2026-07-10, docs/SPEC.md
			// "FIDE flag passthrough - REVERTED"). Always 'N'; the 'fide'
			// schema column stays in place but is dormant.
			$fide     = 'N';

			if ( '' === $sec_name && '' === $timectl && 0 === $tot_rnds && ! ctype_digit( (string) $key ) ) {
				continue; // an unused blank "add" row.
			}

			$derived = WPMTM_Plugin::derive_r_system( $timectl );
			if ( null !== $derived && $derived !== $r_system ) {
				$mismatched[] = '' !== $sec_name ? $sec_name : ( '#' . $key );
			}

			$data    = array(
				'sec_name' => $sec_name,
				'r_system' => $r_system,
				'timectl'  => $timectl,
				'trn_type' => $trn_type,
				'tot_rnds' => $tot_rnds,
				'sch_lvl'  => $sch_lvl,
				'gr_prix'  => $gr_prix,
				'gp_pts'   => $gp_pts,
				'fide'     => $fide,
				'rated'    => $rated,
			);
			$formats = array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%d' );

			if ( ctype_digit( (string) $key ) ) {
				$section_id = (int) $key;
				if ( in_array( $section_id, $removed, true ) ) {
					continue;
				}
				$result = $wpdb->update( $table, $data, array( 'id' => $section_id, 'tournament_id' => $tournament_id ), $formats, array( '%d', '%d' ) );
			} else {
				$data['tournament_id'] = $tournament_id;
				$data['sec_num']       = WPMTM_Repository::next_sec_num( $tournament_id );
				$result = $wpdb->insert( $table, $data, array_merge( $formats, array( '%d', '%d' ) ) );
			}

			if ( false === $result ) {
				++$failed_rows;
			}
		}

		foreach ( $removed as $section_id ) {
			WPMTM_Repository::delete_section_cascade( $section_id, $tournament_id );
		}
		if ( $removed ) {
			WPMTM_Repository::renumber_sections( $tournament_id );
		}

		WPMTM_Cache::flush_event_page( (int) $tournament->event_post_id );

		$notice_parts = array();
		if ( $mismatched ) {
			$notice_parts[] = sprintf(
				/* translators: %s: comma-separated list of section names */
				__( 'Sections saved, but the declared rating system does not match the time control for: %s. Double-check before exporting a rated tournament.', 'wp-tournament-manager' ),
				implode( ', ', $mismatched )
			);
		} else {
			$notice_parts[] = __( 'Sections saved.', 'wp-tournament-manager' );
		}
		if ( $failed_rows > 0 ) {
			$notice_parts[] = sprintf(
				/* translators: %d: number of section rows that could not be saved */
				__( '%d row(s) could not be saved.', 'wp-tournament-manager' ),
				$failed_rows
			);
		}
		$notice_type = $failed_rows > 0 ? 'warning' : ( $mismatched ? 'warning' : 'success' );
		$this->set_notice( $notice_type, implode( ' ', $notice_parts ) );

		wp_safe_redirect( add_query_arg( array( 'page' => 'wpmtm-edit', 'id' => $tournament_id ), admin_url( 'admin.php' ) ) );
		exit;
	}

	// -----------------------------------------------------------------
	// Players editor (per section)
	// -----------------------------------------------------------------

	/**
	 * When the tournament's show_photos flag is set, each player row gains a
	 * leading avatar cell, rendered with WPMTM_Frontend_Public::render_avatar()
	 * - that method is public static with no instance state (see its own
	 * docblock), so it is called directly here rather than duplicated; the
	 * column is absent entirely when show_photos is off, matching the public
	 * standings table and the TD's pairing aid.
	 */
	protected function render_players_editor( $tournament, $section ) {
		$players     = WPMTM_Repository::get_players( $section->id );
		$tot_rnds    = max( 0, (int) $section->tot_rnds );
		$show_photos = (bool) $tournament->show_photos;
		$back_url    = add_query_arg( array( 'page' => 'wpmtm-edit', 'id' => $tournament->id ), admin_url( 'admin.php' ) );
		?>
		<div class="wrap wpmtm-wrap">
			<h1>
				<?php
				printf(
					/* translators: 1: section name, 2: tournament name */
					esc_html__( 'Players: %1$s - %2$s', 'wp-tournament-manager' ),
					esc_html( $section->sec_name ),
					esc_html( $tournament->name )
				);
				?>
			</h1>
			<p><a href="<?php echo esc_url( $back_url ); ?>">&laquo; <?php esc_html_e( 'Back to sections', 'wp-tournament-manager' ); ?></a></p>
			<?php $this->render_notices(); ?>

			<div class="notice notice-info inline">
				<p>
					<?php esc_html_e( 'The roster for this section normally comes from "Import Registrations" on the tournament screen. Use the form below only to correct individual players by hand (registration is closed once the event starts, so this is a data-correction tool, not a registration path).', 'wp-tournament-manager' ); ?>
				</p>
				<p>
					<?php esc_html_e( 'Pairing numbers (the # column) are assigned automatically, highest rating first and unrated players last; you cannot set them by hand. Before a rated event, double-check each USCF ID and rating against ratings.uschess.org - what is entered here is what gets submitted.', 'wp-tournament-manager' ); ?>
				</p>
				<p>
					<?php esc_html_e( 'Withdrawn marks a player out from a chosen round onward: their score stays frozen where it was, they drop out of pairing and round entry from that point on, and the USCF export fills their remaining rounds with the U (not paired) code automatically. Setting a player back to Active reinstates them safely at any time, since withdrawing never writes any result rows.', 'wp-tournament-manager' ); ?>
				</p>
				<p>
					<?php esc_html_e( 'Family name first is for players whose culture puts the family name first (for example many East Asian names): check this so their name shows family name first everywhere in Tournament Manager. The Name field here still stores LAST,FIRST regardless; this flag only controls how that name is displayed.', 'wp-tournament-manager' ); ?>
				</p>
				<p>
					<?php esc_html_e( 'Players sharing a family key, or sharing a last name, are not paired against each other when suggesting pairings (best effort). The family key is filled in automatically for ETR imports carrying a parent email; edit it here to clear a false positive (unrelated players who happen to share a surname still avoid each other by last name alone, regardless of family key) or to add a false negative (give siblings with different surnames the same key).', 'wp-tournament-manager' ); ?>
				</p>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wpmtm_save_players_' . $section->id, 'wpmtm_players_nonce' ); ?>
				<input type="hidden" name="action" value="wpmtm_save_players">
				<input type="hidden" name="section_id" value="<?php echo esc_attr( $section->id ); ?>">
				<input type="hidden" id="wpmtm-removed-players" name="removed_players" value="">

				<table class="wp-list-table widefat fixed striped wpmtm-repeater" id="wpmtm-players-table" data-wpmtm-repeater data-removed-input="wpmtm-removed-players">
					<thead>
						<tr>
							<?php if ( $show_photos ) : ?>
								<th class="wpmtm-col-photo"><?php esc_html_e( 'Photo', 'wp-tournament-manager' ); ?></th>
							<?php endif; ?>
							<th class="wpmtm-col-num"><?php esc_html_e( '#', 'wp-tournament-manager' ); ?></th>
							<th><?php esc_html_e( 'Name (LAST,FIRST MIDDLE)', 'wp-tournament-manager' ); ?></th>
							<th><?php esc_html_e( 'USCF ID', 'wp-tournament-manager' ); ?></th>
							<th><?php esc_html_e( 'State', 'wp-tournament-manager' ); ?></th>
							<th><?php esc_html_e( 'Rating', 'wp-tournament-manager' ); ?></th>
							<th><?php esc_html_e( 'Withdrawn', 'wp-tournament-manager' ); ?></th>
							<th title="<?php echo esc_attr__( 'For players whose culture puts the family name first (for example many East Asian names); this only affects display, not how the name is stored.', 'wp-tournament-manager' ); ?>"><?php esc_html_e( 'Family name first', 'wp-tournament-manager' ); ?></th>
							<th title="<?php echo esc_attr__( 'Players sharing a family key, or sharing a last name, are not paired against each other when suggesting pairings (best effort).', 'wp-tournament-manager' ); ?>"><?php esc_html_e( 'Family key', 'wp-tournament-manager' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $players as $player ) : ?>
						<?php $this->render_player_row( $player, null, $tot_rnds, $show_photos ); ?>
					<?php endforeach; ?>
					</tbody>
					<template>
						<?php $this->render_player_row( null, '__INDEX__', $tot_rnds, $show_photos ); ?>
					</template>
				</table>
				<p><button type="button" class="button" data-add-row-for="wpmtm-players-table"><?php esc_html_e( '+ Add player', 'wp-tournament-manager' ); ?></button></p>
				<?php submit_button( __( 'Save Players', 'wp-tournament-manager' ) ); ?>
			</form>
		</div>
		<?php
	}

	protected function render_player_row( $player, $index = null, $tot_rnds = 0, $show_photos = false ) {
		$is_template            = null === $player;
		$key                    = $is_template ? $index : $player->id;
		$pair_num               = $is_template ? '' : $player->pair_num;
		$name                   = $is_template ? '' : $player->name;
		$mem_id                 = $is_template ? '' : $player->mem_id;
		$state                  = $is_template ? '' : $player->state;
		$rating                 = $is_template ? '' : $player->rating;
		$withdrawn_after_round  = $is_template ? '' : $player->withdrawn_after_round;
		$photo_id               = $is_template ? null : $player->photo_id;
		$family_name_first      = $is_template ? false : (bool) $player->family_name_first;
		$family_key             = $is_template ? '' : (string) $player->family_key;
		$tot_rnds               = max( 0, (int) $tot_rnds );
		?>
		<tr<?php echo $is_template ? '' : ' data-existing-id="' . esc_attr( $key ) . '"'; ?>>
			<?php if ( $show_photos ) : ?>
				<td class="wpmtm-avatar-cell">
					<?php
					echo WPMTM_Frontend_Public::render_avatar( $photo_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see WPMTM_Frontend_Public::render_avatar()'s docblock.
					?>
				</td>
			<?php endif; ?>
			<td class="wpmtm-col-num"><?php echo $is_template ? esc_html__( 'auto', 'wp-tournament-manager' ) : esc_html( $pair_num ); ?></td>
			<td><input type="text" name="players[<?php echo esc_attr( $key ); ?>][name]" value="<?php echo esc_attr( $name ); ?>" placeholder="LAST,FIRST"></td>
			<td><input type="text" maxlength="8" name="players[<?php echo esc_attr( $key ); ?>][mem_id]" value="<?php echo esc_attr( $mem_id ); ?>"></td>
			<td><input type="text" maxlength="2" class="small-text" name="players[<?php echo esc_attr( $key ); ?>][state]" value="<?php echo esc_attr( $state ); ?>"></td>
			<td><input type="text" maxlength="4" class="small-text" name="players[<?php echo esc_attr( $key ); ?>][rating]" value="<?php echo esc_attr( $rating ); ?>"></td>
			<td>
				<select name="players[<?php echo esc_attr( $key ); ?>][withdrawn_after_round]">
					<option value="" <?php selected( '' === (string) $withdrawn_after_round, true ); ?>><?php esc_html_e( 'Active', 'wp-tournament-manager' ); ?></option>
					<?php for ( $n = 0; $n <= $tot_rnds; $n++ ) : ?>
						<option value="<?php echo esc_attr( $n ); ?>" <?php selected( (string) $withdrawn_after_round, (string) $n ); ?>>
							<?php
							if ( 0 === $n ) {
								esc_html_e( 'Before round 1', 'wp-tournament-manager' );
							} else {
								printf(
									/* translators: %d: round number */
									esc_html__( 'After round %d', 'wp-tournament-manager' ),
									$n
								);
							}
							?>
						</option>
					<?php endfor; ?>
				</select>
			</td>
			<td>
				<label>
					<input type="checkbox" name="players[<?php echo esc_attr( $key ); ?>][family_name_first]" value="1" <?php checked( $family_name_first ); ?>>
					<span class="screen-reader-text"><?php esc_html_e( 'Family name first', 'wp-tournament-manager' ); ?></span>
				</label>
			</td>
			<td><input type="text" class="small-text" name="players[<?php echo esc_attr( $key ); ?>][family_key]" value="<?php echo esc_attr( $family_key ); ?>"></td>
			<td><button type="button" class="button-link-delete" data-remove-row><?php esc_html_e( 'Remove', 'wp-tournament-manager' ); ?></button></td>
		</tr>
		<?php
	}

	public function handle_save_players() {
		$section_id = isset( $_POST['section_id'] ) ? absint( $_POST['section_id'] ) : 0;
		check_admin_referer( 'wpmtm_save_players_' . $section_id, 'wpmtm_players_nonce' );
		$this->require_capability();

		$section = WPMTM_Repository::get_section( $section_id );
		if ( ! $section ) {
			wp_die( esc_html__( 'Section not found.', 'wp-tournament-manager' ) );
		}

		global $wpdb;
		$table = WPMTM_Schema::table( 'players' );

		$rows    = ( isset( $_POST['players'] ) && is_array( $_POST['players'] ) ) ? wp_unslash( $_POST['players'] ) : array();
		$removed = array();
		if ( isset( $_POST['removed_players'] ) ) {
			$removed = array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['removed_players'] ) ) ) ) );
		}

		$failed_rows = 0;

		foreach ( $rows as $key => $row ) {
			$name = isset( $row['name'] ) ? sanitize_text_field( $row['name'] ) : '';

			$mem_id = isset( $row['mem_id'] ) ? preg_replace( '/\D+/', '', sanitize_text_field( $row['mem_id'] ) ) : '';
			$mem_id = substr( $mem_id, 0, 8 );

			$state = isset( $row['state'] ) ? strtoupper( sanitize_text_field( $row['state'] ) ) : '';
			$state = substr( preg_replace( '/[^A-Z]/', '', $state ), 0, 2 );

			$rating = isset( $row['rating'] ) ? preg_replace( '/\D+/', '', sanitize_text_field( $row['rating'] ) ) : '';
			$rating = substr( $rating, 0, 4 );

			// Empty string (the "Active" option) means NULL - reinstating a
			// withdrawn player is always safe, since withdrawing never wrote
			// any game/bye rows (docs/SPEC.md, withdrawals).
			$withdrawn_raw         = isset( $row['withdrawn_after_round'] ) ? sanitize_text_field( $row['withdrawn_after_round'] ) : '';
			$withdrawn_after_round = '' !== $withdrawn_raw ? absint( $withdrawn_raw ) : null;

			// Display-only "family name first" flag: never changes how the
			// Name field above is stored - still LAST,FIRST - only how it
			// is later rendered by WPMTM_Name.
			$family_name_first = ! empty( $row['family_name_first'] ) ? 1 : 0;

			// TD override for WPMTM_Pairing_Suggest::same_family()'s
			// heuristics (docs/SPEC.md, 2026-07-14): free-text, not an
			// email field, so sanitize_text_field() rather than
			// sanitize_email() - a TD may type any shared token for
			// siblings with different surnames, not necessarily an email
			// address. Lowercase/trim so it compares the same way
			// WPMTM_Pairing_Suggest::normalize_family_key() does; blank
			// clears it back to NULL (no key).
			$family_key = isset( $row['family_key'] ) ? strtolower( trim( sanitize_text_field( $row['family_key'] ) ) ) : '';

			$is_existing = ctype_digit( (string) $key );

			if ( '' === $name && ! $is_existing ) {
				continue; // unused blank "add" row.
			}

			$data    = array(
				'mem_id'                => '' !== $mem_id ? $mem_id : null,
				'name'                  => $name,
				'state'                 => '' !== $state ? $state : null,
				'rating'                => '' !== $rating ? $rating : null,
				'withdrawn_after_round' => $withdrawn_after_round,
				'family_name_first'     => $family_name_first,
				'family_key'            => '' !== $family_key ? $family_key : null,
			);
			$formats = array( '%s', '%s', '%s', '%s', '%d', '%d', '%s' );

			if ( $is_existing ) {
				$player_id = (int) $key;
				if ( in_array( $player_id, $removed, true ) ) {
					continue;
				}
				$result = $wpdb->update( $table, $data, array( 'id' => $player_id, 'section_id' => $section_id ), $formats, array( '%d', '%d' ) );
			} else {
				$data['section_id'] = $section_id;
				$data['pair_num']   = WPMTM_Repository::next_pair_num( $section_id );
				$result = $wpdb->insert( $table, $data, array_merge( $formats, array( '%d', '%d' ) ) );
			}

			if ( false === $result ) {
				++$failed_rows;
			}
		}

		foreach ( $removed as $player_id ) {
			WPMTM_Repository::delete_player_cascade( $player_id, $section_id );
		}
		if ( $removed ) {
			WPMTM_Repository::renumber_players( $section_id );
		}

		$section_tournament = WPMTM_Repository::get_tournament( $section->tournament_id );
		if ( $section_tournament ) {
			WPMTM_Cache::flush_event_page( (int) $section_tournament->event_post_id );
		}

		$message = __( 'Players saved.', 'wp-tournament-manager' );
		$notice_type = 'success';
		if ( $failed_rows > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of player rows that could not be saved */
				__( '%d row(s) could not be saved.', 'wp-tournament-manager' ),
				$failed_rows
			);
			$notice_type = 'warning';
		}
		$this->set_notice( $notice_type, $message );
		wp_safe_redirect( add_query_arg( array( 'page' => 'wpmtm-edit', 'id' => $section->tournament_id, 'section_id' => $section_id ), admin_url( 'admin.php' ) ) );
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
