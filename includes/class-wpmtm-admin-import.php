<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ETR roster import admin surface (docs/SPEC.md, "Decisions (2026-07-09,
 * ETR import)"): upload+parse, the preview screen, and confirm+write. Split
 * out of WPMTM_Admin so the tournament CRUD screens and the import flow
 * stay independently readable; same nonce/capability/escaping discipline.
 *
 * Two-step flow: upload+parse stores the parsed payload in a short-lived
 * per-user transient and redirects to a preview screen; the TD reviews
 * sections/warnings/skips there and confirms before anything is written.
 * Business rule reminder shown in both steps: registration is closed
 * before the event, so this import IS the roster (docs/TD-PERSONA.md).
 */
class WPMTM_Admin_Import {

	use WPMTM_Admin_Shared;

	private static $instance = null;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_post_wpmtm_etr_upload', array( $this, 'handle_etr_upload' ) );
		add_action( 'admin_post_wpmtm_etr_confirm', array( $this, 'handle_etr_confirm' ) );
		add_action( 'admin_post_wpmtm_import_from_event', array( $this, 'handle_import_from_event' ) );
		add_action( 'admin_post_nopriv_wpmtm_import_from_event', array( $this, 'handle_import_from_event_nopriv' ) );
		add_action( 'admin_post_wpmtm_import_pick_event', array( $this, 'handle_import_pick_event' ) );
	}

	// -----------------------------------------------------------------
	// Import box (upload form) on the tournament edit screen.
	// -----------------------------------------------------------------

	public function render_import_box( $tournament ) {
		?>
		<div class="wrap wpmtm-wrap">
			<h2><?php esc_html_e( 'Import Registrations', 'wp-tournament-manager' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Upload the CSV produced by the "Pairing export" button on this event\'s ETR Registrations tab. Registration is closed before the event starts, so this import is the roster - not a way to add late entrants.', 'wp-tournament-manager' ); ?>
				<?php esc_html_e( 'You will see a preview of the sections and players first; nothing is written until you confirm it.', 'wp-tournament-manager' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" data-wpmtm-guard>
				<?php wp_nonce_field( 'wpmtm_etr_upload_' . $tournament->id, 'wpmtm_etr_upload_nonce' ); ?>
				<input type="hidden" name="action" value="wpmtm_etr_upload">
				<input type="hidden" name="tournament_id" value="<?php echo esc_attr( $tournament->id ); ?>">
				<p>
					<input type="file" name="etr_csv" accept=".csv" required>
				</p>
				<?php
				submit_button(
					__( 'Upload and Preview', 'wp-tournament-manager' ),
					'secondary',
					'submit',
					true,
					array( 'data-wpmtm-busy-label' => esc_attr__( 'Importing...', 'wp-tournament-manager' ) )
				);
				?>
			</form>

			<?php $this->render_event_picker_form( $tournament ); ?>
		</div>
		<?php
	}

	// -----------------------------------------------------------------
	// Import box (event picker): a third door into the same preview
	// screen as the CSV upload above and the one-click "Import to
	// Tournament Manager" button wp-etr renders on the event's own
	// Registrations tab. Lets the TD pull a roster straight from any
	// published event without leaving this screen or exporting a CSV
	// first.
	// -----------------------------------------------------------------

	/**
	 * Published tribe_events, most recent first, for the event picker
	 * below - the same shape and cap (30) as wp-etr's own Demo mode event
	 * <select> (Etr\Settings::test_event_choices()), so a TD who
	 * recognizes that picker from wp-etr sees an equivalent list here.
	 * Label is "Title - <site date format>", e.g. "Spring Open - March 5,
	 * 2026"; events with no _EventStartDate meta are excluded by the
	 * meta_key/orderby query itself, matching wp-etr's own limitation.
	 *
	 * @return array event_post_id => label.
	 */
	protected function event_choices_for_import() {
		if ( ! post_type_exists( 'tribe_events' ) ) {
			return array();
		}

		$events = get_posts(
			array(
				'post_type'      => 'tribe_events',
				'post_status'    => 'publish',
				'posts_per_page' => 30,
				'meta_key'       => '_EventStartDate',
				'orderby'        => 'meta_value',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		$choices = array();
		foreach ( $events as $event ) {
			$start = get_post_meta( $event->ID, '_EventStartDate', true );
			$date  = $start ? date_i18n( get_option( 'date_format' ), strtotime( $start ) ) : '';
			$choices[ $event->ID ] = $date ? ( $event->post_title . ' - ' . $date ) : $event->post_title;
		}
		return $choices;
	}

	/**
	 * The "import from a chosen event" form: a <select> of published
	 * events (event_choices_for_import() above) defaulting to this
	 * tournament's own linked event when it has one, posting to
	 * handle_import_pick_event() below. Silently renders nothing when The
	 * Events Calendar is not active, the same way render_event_picker()
	 * in WPMTM_Admin degrades to a plain number field instead - there is
	 * no ETR roster to pull without tribe_events in the first place.
	 */
	protected function render_event_picker_form( $tournament ) {
		if ( ! post_type_exists( 'tribe_events' ) ) {
			return;
		}

		$choices = $this->event_choices_for_import();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-wpmtm-guard>
			<?php wp_nonce_field( 'wpmtm_import_pick_event_' . $tournament->id, 'wpmtm_import_pick_event_nonce' ); ?>
			<input type="hidden" name="action" value="wpmtm_import_pick_event">
			<input type="hidden" name="tournament_id" value="<?php echo esc_attr( $tournament->id ); ?>">
			<p>
				<label for="wpmtm-import-event"><?php esc_html_e( 'Or import directly from an event:', 'wp-tournament-manager' ); ?></label><br>
				<select id="wpmtm-import-event" name="wpmtm_import_event">
					<?php if ( empty( $choices ) ) : ?>
						<option value="0"><?php esc_html_e( 'No published events', 'wp-tournament-manager' ); ?></option>
					<?php else : ?>
						<?php foreach ( $choices as $choice_id => $label ) : ?>
							<option value="<?php echo esc_attr( $choice_id ); ?>" <?php selected( (int) $tournament->event_post_id, $choice_id ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					<?php endif; ?>
				</select>
			</p>
			<p class="description"><?php esc_html_e( 'Choosing an event pulls its ETR roster into this tournament for review; nothing is written until you confirm on the next screen.', 'wp-tournament-manager' ); ?></p>
			<?php
			submit_button(
				__( 'Import from this event', 'wp-tournament-manager' ),
				'secondary',
				'submit',
				true,
				array( 'data-wpmtm-busy-label' => esc_attr__( 'Importing...', 'wp-tournament-manager' ) )
			);
			?>
		</form>
		<?php
	}

	/**
	 * Handles the event-picker form above. Unlike handle_import_from_event()
	 * below - which finds-or-creates a tournament FOR the chosen event - this
	 * always targets the tournament already being edited (posted as
	 * tournament_id): the TD picked this event while editing a specific
	 * tournament, so that is where the roster lands, regardless of whether
	 * the chosen event happens to be linked to some other tournament (or no
	 * tournament at all). The stored transient shape, success notice, and
	 * preview redirect are otherwise identical to handle_import_from_event().
	 */
	public function handle_import_pick_event() {
		$tournament_id = isset( $_POST['tournament_id'] ) ? absint( $_POST['tournament_id'] ) : 0;
		check_admin_referer( 'wpmtm_import_pick_event_' . $tournament_id, 'wpmtm_import_pick_event_nonce' );
		$this->require_capability();

		$tournament = WPMTM_Repository::get_tournament( $tournament_id );
		if ( ! $tournament ) {
			wp_die( esc_html__( 'Tournament not found.', 'wp-tournament-manager' ) );
		}

		$redirect_back = add_query_arg( array( 'page' => 'wpmtm-edit', 'id' => $tournament_id ), admin_url( 'admin.php' ) );

		$event_id = isset( $_POST['wpmtm_import_event'] ) ? absint( $_POST['wpmtm_import_event'] ) : 0;
		$event_id = WPMTM_Plugin::validate_event_post_id( $event_id );
		if ( ! $event_id ) {
			$this->set_notice( 'error', __( 'Please choose a valid event to import from.', 'wp-tournament-manager' ) );
			wp_safe_redirect( $redirect_back );
			exit;
		}

		if ( ! class_exists( '\\Etr\\Plugin' ) || ! method_exists( \Etr\Plugin::instance(), 'build_sections' ) ) {
			wp_die( esc_html__( 'Event Tickets Registrations (wp-etr) 5.2.4 or newer must be active to import from an event.', 'wp-tournament-manager' ) );
		}

		$parsed = WPMTM_ETR_Import::normalize_rows( $this->build_rows_from_event( $event_id ) );

		set_transient(
			$this->etr_import_transient_key(),
			array(
				'tournament_id' => $tournament_id,
				'parsed'        => $parsed,
			),
			15 * MINUTE_IN_SECONDS
		);

		$this->set_notice(
			'success',
			sprintf(
				/* translators: 1: number of registrants, 2: number of sections */
				__( 'Pulled %1$d registrants across %2$d sections from the event. Nothing is saved until you review and confirm below.', 'wp-tournament-manager' ),
				count( $parsed['rows'] ),
				count( $parsed['sections'] )
			)
		);

		wp_safe_redirect( add_query_arg( array( 'page' => 'wpmtm-edit', 'id' => $tournament_id, 'wpmtm_etr_step' => 'preview' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	// -----------------------------------------------------------------
	// Upload + parse.
	// -----------------------------------------------------------------

	public function handle_etr_upload() {
		$tournament_id = isset( $_POST['tournament_id'] ) ? absint( $_POST['tournament_id'] ) : 0;
		check_admin_referer( 'wpmtm_etr_upload_' . $tournament_id, 'wpmtm_etr_upload_nonce' );
		$this->require_capability();

		$tournament = WPMTM_Repository::get_tournament( $tournament_id );
		if ( ! $tournament ) {
			wp_die( esc_html__( 'Tournament not found.', 'wp-tournament-manager' ) );
		}

		$redirect_back = add_query_arg( array( 'page' => 'wpmtm-edit', 'id' => $tournament_id ), admin_url( 'admin.php' ) );

		if ( empty( $_FILES['etr_csv'] ) || ! is_array( $_FILES['etr_csv'] ) ) {
			$this->set_notice( 'error', __( 'No file was uploaded.', 'wp-tournament-manager' ) );
			wp_safe_redirect( $redirect_back );
			exit;
		}

		$file = $_FILES['etr_csv']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_FILES values are validated below (error code, size cap, is_uploaded_file, extension) before use; not passed to output.

		if ( ! isset( $file['error'] ) || UPLOAD_ERR_OK !== $file['error'] ) {
			$this->set_notice( 'error', __( 'The file upload failed; please try again.', 'wp-tournament-manager' ) );
			wp_safe_redirect( $redirect_back );
			exit;
		}

		$max_bytes = 1048576; // 1 MB cap.
		if ( ! isset( $file['size'] ) || $file['size'] > $max_bytes ) {
			$this->set_notice( 'error', __( 'The uploaded file is larger than the 1 MB limit.', 'wp-tournament-manager' ) );
			wp_safe_redirect( $redirect_back );
			exit;
		}

		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			$this->set_notice( 'error', __( 'The uploaded file could not be read.', 'wp-tournament-manager' ) );
			wp_safe_redirect( $redirect_back );
			exit;
		}

		$filename = isset( $file['name'] ) ? sanitize_file_name( $file['name'] ) : '';
		if ( '' === $filename || 'csv' !== strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ) ) {
			$this->set_notice( 'error', __( 'Please upload a .csv file exported from ETR\'s "Pairing export" button.', 'wp-tournament-manager' ) );
			wp_safe_redirect( $redirect_back );
			exit;
		}

		$csv_text = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a just-validated PHP upload tmp file, not a remote URL; WP_Filesystem is unnecessary for this local, already-permission-checked read.
		if ( false === $csv_text ) {
			$this->set_notice( 'error', __( 'The uploaded file could not be read.', 'wp-tournament-manager' ) );
			wp_safe_redirect( $redirect_back );
			exit;
		}

		$parsed = WPMTM_ETR_Import::parse( $csv_text );

		if ( isset( $parsed['error'] ) ) {
			if ( 'bad_header' === $parsed['error'] ) {
				$this->set_notice( 'error', __( 'This does not look like an ETR Pairing export.', 'wp-tournament-manager' ) );
			} else {
				$this->set_notice( 'error', __( 'The uploaded file is empty.', 'wp-tournament-manager' ) );
			}
			wp_safe_redirect( $redirect_back );
			exit;
		}

		if ( empty( $parsed['rows'] ) ) {
			$this->set_notice( 'warning', __( 'The file parsed but contained no player rows.', 'wp-tournament-manager' ) );
			wp_safe_redirect( $redirect_back );
			exit;
		}

		set_transient(
			$this->etr_import_transient_key(),
			array(
				'tournament_id' => $tournament_id,
				'parsed'        => $parsed,
			),
			15 * MINUTE_IN_SECONDS
		);

		wp_safe_redirect( add_query_arg( array( 'page' => 'wpmtm-edit', 'id' => $tournament_id, 'wpmtm_etr_step' => 'preview' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	// -----------------------------------------------------------------
	// Import directly from an event's ETR Registrations tab (the "Import
	// to Tournament Manager" button wp-etr renders next to Pairing
	// export). Skips the CSV round-trip: it reads the same roster
	// directly out of wp-etr, then feeds it through the identical
	// normalize_rows() the CSV upload path uses so both doors land on the
	// same preview screen with the same warnings/skips.
	// -----------------------------------------------------------------

	public function handle_import_from_event() {
		$event_id = isset( $_GET['event'] ) ? absint( $_GET['event'] ) : 0;
		$nonce    = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! $event_id || ! wp_verify_nonce( $nonce, 'wpmtm_import_from_event_' . $event_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'wp-tournament-manager' ) );
		}
		$this->require_capability();

		$event_id = WPMTM_Plugin::validate_event_post_id( $event_id );
		if ( ! $event_id ) {
			wp_die( esc_html__( 'The event could not be found.', 'wp-tournament-manager' ) );
		}

		if ( ! class_exists( '\\Etr\\Plugin' ) || ! method_exists( \Etr\Plugin::instance(), 'build_sections' ) ) {
			wp_die( esc_html__( 'Event Tickets Registrations (wp-etr) 5.2.4 or newer must be active to import from an event.', 'wp-tournament-manager' ) );
		}

		$tournament = WPMTM_Repository::get_tournament_by_event( $event_id );
		if ( ! $tournament ) {
			$tournament_id = $this->create_tournament_for_event( $event_id );
			if ( ! $tournament_id ) {
				wp_die( esc_html__( 'The tournament could not be created.', 'wp-tournament-manager' ) );
			}
			$tournament = WPMTM_Repository::get_tournament( $tournament_id );
		}
		$tournament_id = (int) $tournament->id;

		$parsed = WPMTM_ETR_Import::normalize_rows( $this->build_rows_from_event( $event_id ) );

		set_transient(
			$this->etr_import_transient_key(),
			array(
				'tournament_id' => $tournament_id,
				'parsed'        => $parsed,
			),
			15 * MINUTE_IN_SECONDS
		);

		$this->set_notice(
			'success',
			sprintf(
				/* translators: 1: number of registrants, 2: number of sections */
				__( 'Pulled %1$d registrants across %2$d sections from the event. Nothing is saved until you review and confirm below.', 'wp-tournament-manager' ),
				count( $parsed['rows'] ),
				count( $parsed['sections'] )
			)
		);

		wp_safe_redirect( add_query_arg( array( 'page' => 'wpmtm-edit', 'id' => $tournament_id, 'wpmtm_etr_step' => 'preview' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Logged-out GETs to this action are always rejected outright. */
	public function handle_import_from_event_nopriv() {
		wp_die( esc_html__( 'Forbidden', 'wp-tournament-manager' ), 403 );
	}

	/**
	 * Reads an event's registrations straight from wp-etr and shapes them
	 * into the same row format (last, first, USCF id, rating, section,
	 * status) its own "Pairing export" CSV uses, plus a 7th cell -
	 * photo_id - the CSV never carries, so
	 * WPMTM_ETR_Import::normalize_rows() sees identical input for the
	 * first 6 cells regardless of whether it arrived via a CSV upload or
	 * this button, with photo_id only ever populated on this door.
	 *
	 * @param int $event_id Already-validated event post id.
	 * @return array List of 7-element raw rows.
	 */
	protected function build_rows_from_event( $event_id ) {
		$rows = array();

		foreach ( \Etr\Plugin::instance()->build_sections( $event_id ) as $label => $section_rows ) {
			foreach ( $section_rows as $r ) {
				$rows[] = array(
					isset( $r['last'] ) ? $r['last'] : '',
					isset( $r['first'] ) ? $r['first'] : '',
					isset( $r['uscf_id'] ) ? $r['uscf_id'] : '',
					( isset( $r['rating'] ) && $r['rating'] > 0 ) ? $r['rating'] : '',
					$label,
					// Literal, untranslated: normalize_rows() matches this
					// string exactly (case-insensitively) regardless of
					// wp-etr's own site locale, the same way its CSV export
					// always writes the English word into the Status column.
					! empty( $r['noshow'] ) ? 'No-show' : '',
					// photo_id: wp-etr's build_sections() row key of the
					// same name, an attachment id, or 0 when the registrant
					// has no photo on file (see normalize_rows()'s
					// docblock for how this cell is consumed).
					isset( $r['photo_id'] ) ? $r['photo_id'] : 0,
				);
			}
		}

		return $rows;
	}

	/**
	 * Creates the stub tournament for an event that has none yet, so the
	 * "Import to Tournament Manager" button always has somewhere to land.
	 * The TD can correct any of these on the tournament edit screen
	 * afterward; nothing here is final - including show_photos, which only
	 * seeds a starting value here and is otherwise an ordinary field on the
	 * tournament edit form the TD can flip either way at any time.
	 *
	 * @param int $event_id Already-validated event post id.
	 * @return int New tournament id, or 0 on failure.
	 */
	protected function create_tournament_for_event( $event_id ) {
		$opts = WPMTM_Plugin::instance()->get_opts();

		$start_meta = get_post_meta( $event_id, '_EventStartDate', true );
		$begin_date = ( is_string( $start_meta ) && strlen( $start_meta ) >= 10 )
			? substr( $start_meta, 0, 10 )
			: current_time( 'Y-m-d' );

		$end_meta = get_post_meta( $event_id, '_EventEndDate', true );
		$end_date = ( is_string( $end_meta ) && strlen( $end_meta ) >= 10 )
			? substr( $end_meta, 0, 10 )
			: $begin_date;

		return WPMTM_Repository::create_tournament(
			array(
				'event_post_id' => $event_id,
				'name'          => get_the_title( $event_id ),
				'rated'         => 1,
				'begin_date'    => $begin_date,
				'end_date'      => $end_date,
				'city'          => $opts['default_city'],
				'state'         => $opts['default_state'],
				'zipcode'       => $opts['default_zipcode'],
				// The new tournament's Show profile pictures default comes
				// straight from the event's own ETR "Show photos" toggle
				// (_etr_show_photos post meta) - a tournament created from
				// an event that already opted into showing photos on
				// registration starts with the same behavior on its public
				// pages, docs/SPEC.md "Decisions (2026-07-10)".
				'show_photos'   => (bool) get_post_meta( $event_id, '_etr_show_photos', true ) ? 1 : 0,
			)
		);
	}

	// -----------------------------------------------------------------
	// Preview dispatch + rendering.
	// -----------------------------------------------------------------

	/**
	 * Called from the tournament edit screen when ?wpmtm_etr_step=preview.
	 * Renders the preview and returns true when there is a valid pending
	 * import for this tournament; otherwise sets an error notice (expired
	 * or mismatched transient) and returns false so the caller falls
	 * through to the normal edit screen.
	 */
	public function maybe_render_preview( $tournament ) {
		$pending = $this->get_etr_import_transient();
		if ( $pending && (int) $pending['tournament_id'] === (int) $tournament->id && empty( $pending['parsed']['error'] ) ) {
			$this->render_etr_preview( $tournament, $pending['parsed'] );
			return true;
		}
		$this->set_notice( 'error', __( 'The pending import could not be found; it may have expired. Please upload the CSV again.', 'wp-tournament-manager' ) );
		return false;
	}

	protected function render_etr_preview( $tournament, $parsed ) {
		$existing_sections = WPMTM_Repository::get_sections( $tournament->id );
		$skipped_rows       = array_values(
			array_filter(
				$parsed['rows'],
				function ( $r ) {
					return ! empty( $r['skip'] );
				}
			)
		);

		// Id => name map for match_existing_section(), and a per-section
		// player count so a re-import onto a section that already has
		// players can be flagged (docs/TD-PERSONA.md re-import safety).
		$existing_names = array();
		$existing_rated = array();
		foreach ( $existing_sections as $es ) {
			$existing_names[ $es->id ] = $es->sec_name;
			$existing_rated[ $es->id ] = (bool) $es->rated;
		}
		?>
		<div class="wrap wpmtm-wrap">
			<h1><?php esc_html_e( 'Import Registrations: Preview', 'wp-tournament-manager' ); ?></h1>
			<p>
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'wpmtm-edit', 'id' => $tournament->id ), admin_url( 'admin.php' ) ) ); ?>">
					&laquo; <?php esc_html_e( 'Cancel import', 'wp-tournament-manager' ); ?>
				</a>
			</p>
			<?php $this->render_notices(); ?>

			<div class="notice notice-info inline">
				<p><?php esc_html_e( 'Registration is closed before the event; this import becomes the roster. Nothing is written to the database until you click "Confirm Import" below.', 'wp-tournament-manager' ); ?></p>
				<ul>
					<li><?php esc_html_e( 'Create new section adds that CSV section to this tournament as a new section.', 'wp-tournament-manager' ); ?></li>
					<li><?php esc_html_e( 'Map to existing section adds the players into a section that already exists, appending them after its current pairing numbers.', 'wp-tournament-manager' ); ?></li>
					<li><?php esc_html_e( 'Skip this section means none of that section\'s players are imported.', 'wp-tournament-manager' ); ?></li>
					<li><?php esc_html_e( 'Rated controls whether the section goes into the USCF export; unrated sections are never included. New sections start checked to match this tournament\'s own Rated setting - section names are not a reliable signal (a section named "U1800" can still be rated), so review each checkbox before confirming.', 'wp-tournament-manager' ); ?></li>
					<li><?php esc_html_e( 'Split into quads groups the players by rating into 4-player round robin sections instead of one Swiss section: "Name Quad 1", "Quad 2", and so on. Leftover players (1 to 3 after the last full quad) are folded into a small Swiss section instead of being left as a short quad. Only available for Create new section.', 'wp-tournament-manager' ); ?></li>
					<li><?php esc_html_e( 'The warnings list and the "Rows that will not be imported" table below show exactly what will not be imported and why.', 'wp-tournament-manager' ); ?></li>
				</ul>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-wpmtm-guard>
				<?php wp_nonce_field( 'wpmtm_etr_confirm_' . $tournament->id, 'wpmtm_etr_confirm_nonce' ); ?>
				<input type="hidden" name="action" value="wpmtm_etr_confirm">
				<input type="hidden" name="tournament_id" value="<?php echo esc_attr( $tournament->id ); ?>">

				<h2><?php esc_html_e( 'Sections', 'wp-tournament-manager' ); ?></h2>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'CSV section', 'wp-tournament-manager' ); ?></th>
							<th><?php esc_html_e( 'Players to import', 'wp-tournament-manager' ); ?></th>
							<th><?php esc_html_e( 'Rated', 'wp-tournament-manager' ); ?></th>
							<th><?php esc_html_e( 'Split into quads', 'wp-tournament-manager' ); ?></th>
							<th><?php esc_html_e( 'Action', 'wp-tournament-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $parsed['sections'] as $i => $section ) : ?>
						<?php
						$count = count(
							array_filter(
								$parsed['rows'],
								function ( $r ) use ( $section ) {
									return $r['section'] === $section['name'] && empty( $r['skip'] );
								}
							)
						);

						$matched_id           = WPMTM_ETR_Import::match_existing_section( $section['name'], $existing_names );
						$matched_player_count = $matched_id ? WPMTM_Repository::count_players( $matched_id ) : 0;

						// Split into quads only makes sense for a brand-new
						// section (docs/SPEC.md, round robin and quads
						// decisions); this mirrors the mode <select>'s own
						// default below - a CSV section this plugin already
						// recognizes (matched_id set) defaults to "Map to
						// existing" and the checkbox starts disabled to
						// match. Like that default mode selection, this is a
						// static best guess at page-load time, not something
						// that re-syncs if the TD changes the Action dropdown
						// afterward - the confirm handler is the actual
						// enforcement point and ignores the checkbox for any
						// row that posts back as "existing".
						$quads_unavailable = (bool) $matched_id;

						// Rated checkbox default: a brand-new section starts
						// from this tournament's own Rated flag - no name
						// guessing, since section names say nothing reliable
						// about rating, e.g. "U1800" is a rated section
						// (owner decision 2026-07-10). A CSV section that
						// defaults to "Map to existing" (matched_id set)
						// shows the matched section's own Rated flag instead
						// and is disabled: handle_etr_confirm() /
						// WPMTM_ETR_Import::import() ignore the posted
						// checkbox for the 'existing' mode, so the existing
						// section always keeps whatever Rated value it
						// already has. Like $quads_unavailable above, this is
						// a static best guess at page-load time and does not
						// re-sync if the TD changes the Action dropdown
						// afterward.
						$rated_readonly = (bool) $matched_id;
						$rated_default  = $rated_readonly ? $existing_rated[ $matched_id ] : (bool) $tournament->rated;
						?>
						<tr>
							<td>
								<?php echo esc_html( $section['name'] ); ?>
								<input type="hidden" name="section_map[<?php echo esc_attr( $i ); ?>][name]" value="<?php echo esc_attr( $section['name'] ); ?>">
							</td>
							<td><?php echo esc_html( $count ); ?></td>
							<td>
								<label<?php echo $rated_readonly ? ' title="' . esc_attr__( 'This section already exists; it keeps its own Rated setting.', 'wp-tournament-manager' ) . '"' : ''; ?>>
									<input type="checkbox" name="section_map[<?php echo esc_attr( $i ); ?>][rated]" value="1" <?php checked( $rated_default ); ?> <?php disabled( $rated_readonly ); ?>>
									<?php esc_html_e( 'Rated', 'wp-tournament-manager' ); ?>
								</label>
							</td>
							<td>
								<label<?php echo $quads_unavailable ? ' title="' . esc_attr__( 'Only available for Create new section; a roster mapped onto an existing section is never split.', 'wp-tournament-manager' ) . '"' : ''; ?>>
									<input type="checkbox" name="section_map[<?php echo esc_attr( $i ); ?>][quads]" value="1" <?php disabled( $quads_unavailable ); ?>>
									<?php esc_html_e( 'Split into quads', 'wp-tournament-manager' ); ?>
								</label>
							</td>
							<td>
								<select name="section_map[<?php echo esc_attr( $i ); ?>][mode]">
									<option value="create" <?php selected( ! $matched_id ); ?>><?php esc_html_e( 'Create new section', 'wp-tournament-manager' ); ?></option>
									<?php if ( $existing_sections ) : ?>
										<option value="existing" <?php selected( (bool) $matched_id ); ?>><?php esc_html_e( 'Map to existing section', 'wp-tournament-manager' ); ?></option>
									<?php endif; ?>
									<option value="skip"><?php esc_html_e( 'Skip this section', 'wp-tournament-manager' ); ?></option>
								</select>
								<?php if ( $existing_sections ) : ?>
									<br>
									<select name="section_map[<?php echo esc_attr( $i ); ?>][section_id]">
										<option value="0"><?php esc_html_e( '-- existing section (used only for "Map to existing") --', 'wp-tournament-manager' ); ?></option>
										<?php foreach ( $existing_sections as $es ) : ?>
											<option value="<?php echo esc_attr( $es->id ); ?>" <?php selected( $matched_id, $es->id ); ?>><?php echo esc_html( $es->sec_name ); ?></option>
										<?php endforeach; ?>
									</select>
								<?php endif; ?>
								<?php if ( $matched_id && $matched_player_count > 0 ) : ?>
									<p class="description wpmtm-etr-row-warning">
										<?php
										printf(
											/* translators: 1: number of players already in the matched section, 2: pairing number the import will append after (same value as %1$d) */
											esc_html__( 'This section already has %1$d players; importing will append after pairing number %2$d, which is usually wrong for a re-import. Delete the section\'s players first if you are re-importing a corrected file.', 'wp-tournament-manager' ),
											(int) $matched_player_count,
											(int) $matched_player_count
										);
										?>
									</p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( ! empty( $parsed['warnings'] ) ) : ?>
					<h2><?php esc_html_e( 'Warnings', 'wp-tournament-manager' ); ?></h2>
					<ul class="wpmtm-etr-warnings">
						<?php foreach ( $parsed['warnings'] as $warning ) : ?>
							<li><?php echo esc_html( $warning ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<?php if ( $skipped_rows ) : ?>
					<h2><?php esc_html_e( 'Rows that will not be imported', 'wp-tournament-manager' ); ?></h2>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Name', 'wp-tournament-manager' ); ?></th>
								<th><?php esc_html_e( 'Section', 'wp-tournament-manager' ); ?></th>
								<th><?php esc_html_e( 'Reason', 'wp-tournament-manager' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $skipped_rows as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row['name'] ); ?></td>
								<td><?php echo esc_html( $row['section'] ); ?></td>
								<td>
									<?php
									if ( 0 === strcasecmp( $row['status'], 'no-show' ) ) {
										esc_html_e( 'No-show', 'wp-tournament-manager' );
									} elseif ( ! empty( $row['warnings'] ) ) {
										echo esc_html( implode( ' ', $row['warnings'] ) );
									} else {
										esc_html_e( 'Skipped', 'wp-tournament-manager' );
									}
									?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<?php
				submit_button(
					__( 'Confirm Import', 'wp-tournament-manager' ),
					'primary',
					'submit',
					true,
					array( 'data-wpmtm-busy-label' => esc_attr__( 'Importing...', 'wp-tournament-manager' ) )
				);
				?>
			</form>
		</div>
		<?php
	}

	public function handle_etr_confirm() {
		$tournament_id = isset( $_POST['tournament_id'] ) ? absint( $_POST['tournament_id'] ) : 0;
		check_admin_referer( 'wpmtm_etr_confirm_' . $tournament_id, 'wpmtm_etr_confirm_nonce' );
		$this->require_capability();

		$tournament = WPMTM_Repository::get_tournament( $tournament_id );
		if ( ! $tournament ) {
			wp_die( esc_html__( 'Tournament not found.', 'wp-tournament-manager' ) );
		}

		$redirect_back = add_query_arg( array( 'page' => 'wpmtm-edit', 'id' => $tournament_id ), admin_url( 'admin.php' ) );

		$pending = $this->get_etr_import_transient();
		if ( ! $pending || (int) $pending['tournament_id'] !== $tournament_id || empty( $pending['parsed'] ) || ! empty( $pending['parsed']['error'] ) ) {
			$this->set_notice( 'error', __( 'The pending import could not be found; it may have expired. Please upload the CSV again.', 'wp-tournament-manager' ) );
			wp_safe_redirect( $redirect_back );
			exit;
		}

		// M3/C2: claim the pending import before writing anything, so a
		// double-click (two overlapping requests) cannot both see the same
		// transient and import the CSV twice. delete_transient() returns
		// false when the transient was already gone - i.e. another request
		// already claimed it - in which case we bail with the same "pending
		// import could not be found" notice a genuinely expired transient
		// would produce, rather than importing again.
		if ( ! delete_transient( $this->etr_import_transient_key() ) ) {
			$this->set_notice( 'error', __( 'The pending import could not be found; it may have expired. Please upload the CSV again.', 'wp-tournament-manager' ) );
			wp_safe_redirect( $redirect_back );
			exit;
		}

		$parsed     = $pending['parsed'];
		$posted_map = ( isset( $_POST['section_map'] ) && is_array( $_POST['section_map'] ) ) ? wp_unslash( $_POST['section_map'] ) : array();

		$known_sections = array_column( $parsed['sections'], 'name' );

		$section_map = array();
		foreach ( $posted_map as $row ) {
			$csv_name = isset( $row['name'] ) ? sanitize_text_field( $row['name'] ) : '';
			if ( '' === $csv_name || ! in_array( $csv_name, $known_sections, true ) ) {
				continue; // ignore rows that do not match the parsed payload (tampered/stale form).
			}

			$mode = isset( $row['mode'] ) ? sanitize_key( $row['mode'] ) : 'skip';
			if ( ! in_array( $mode, array( 'create', 'existing', 'skip' ), true ) ) {
				$mode = 'skip';
			}

			$section_map[ $csv_name ] = array(
				'mode'       => $mode,
				'section_id' => isset( $row['section_id'] ) ? absint( $row['section_id'] ) : 0,
				'rated'      => ! empty( $row['rated'] ) ? 1 : 0,
				// Meaningful only for 'create' - WPMTM_ETR_Import::import()
				// itself ignores it for 'existing'/'skip', same as the
				// checkbox being rendered disabled for those rows above.
				'quads'      => ! empty( $row['quads'] ) ? 1 : 0,
			);
		}

		$importer = new WPMTM_ETR_Import();

		try {
			$summary = $importer->import( $tournament_id, $parsed, $section_map );
		} catch ( Throwable $e ) {
			// The transient was already claimed (deleted) above; put the
			// in-memory payload back so the TD can retry the confirm
			// instead of having to re-upload the CSV after a mid-import
			// failure.
			set_transient( $this->etr_import_transient_key(), $pending, 15 * MINUTE_IN_SECONDS );
			$this->set_notice( 'error', __( 'The import could not be completed; please try confirming again.', 'wp-tournament-manager' ) );
			wp_safe_redirect( $redirect_back );
			exit;
		}

		WPMTM_Cache::flush_event_page( (int) $tournament->event_post_id );

		$message = sprintf(
			/* translators: 1: sections created, 2: players imported, 3: players/rows skipped */
			__( 'Import complete: %1$d section(s) created, %2$d player(s) imported, %3$d row(s) skipped.', 'wp-tournament-manager' ),
			(int) $summary['sections_created'],
			(int) $summary['players_imported'],
			(int) $summary['players_skipped']
		);

		if ( ! empty( $summary['warnings'] ) ) {
			$warnings     = array_values( $summary['warnings'] );
			$shown        = array_slice( $warnings, 0, 5 );
			$message     .= ' ' . implode( ' ', $shown );
			$more_warnings = count( $warnings ) - count( $shown );
			if ( $more_warnings > 0 ) {
				$message .= ' ' . sprintf(
					/* translators: %d: number of additional warnings not shown above */
					__( '+ %d more warning(s).', 'wp-tournament-manager' ),
					$more_warnings
				);
			}
		}

		$this->set_notice( 'success', $message );
		wp_safe_redirect( $redirect_back );
		exit;
	}

	// -----------------------------------------------------------------
	// Pending-import transient.
	// -----------------------------------------------------------------

	protected function etr_import_transient_key() {
		return 'wpmtm_etr_import_' . get_current_user_id();
	}

	protected function get_etr_import_transient() {
		$data = get_transient( $this->etr_import_transient_key() );
		return is_array( $data ) ? $data : null;
	}
}
