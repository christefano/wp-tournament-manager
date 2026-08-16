<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings API page ("Settings" submenu under the top-level "Tournament
 * Manager" menu registered by WPMTM_Admin). Manages the single
 * `wpmtm_options` array via register_setting()/sanitize_callback so every
 * key is validated on save; invalid values are rejected with a settings
 * error and the previous value is kept.
 */
class WPMTM_Settings {

	use WPMTM_Admin_Shared;

	private static $instance = null;

	const OPTION_GROUP = 'wpmtm_settings_group';
	const PAGE_SLUG    = 'wpmtm-settings';

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function register_menu() {
		add_submenu_page(
			'wpmtm',
			__( 'Tournament Manager Settings', 'wp-tournament-manager' ),
			__( 'Settings', 'wp-tournament-manager' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			WPMTM_Plugin::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_options' ),
				'default'           => WPMTM_Plugin::DEFAULTS,
				'autoload'          => false,
			)
		);

		// Ensure the option exists with autoload = no from first activation,
		// even before this admin_init handler ever runs a save.
		if ( false === get_option( WPMTM_Plugin::OPTION_KEY, false ) ) {
			add_option( WPMTM_Plugin::OPTION_KEY, WPMTM_Plugin::DEFAULTS, '', 'no' );
		}

		// The Tournament Manager role decision (WPMTM_Roles) is a separate
		// option, not part of WPMTM_Plugin::OPTION_KEY, kept out of the
		// options array so its own sanitize/merge logic (which creates or
		// removes the role as a side effect) stays independent of the rest
		// of this form.
		register_setting(
			self::OPTION_GROUP,
			'wpmtm_role_decision',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_role_decision' ),
				'default'           => '',
				'autoload'          => false,
			)
		);
		if ( false === get_option( 'wpmtm_role_decision', false ) ) {
			add_option( 'wpmtm_role_decision', '', '', 'no' );
		}

		add_settings_section(
			'wpmtm_main',
			'',
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_field(
			'affiliate_id',
			__( 'USCF affiliate ID', 'wp-tournament-manager' ),
			array( $this, 'field_affiliate_id' ),
			self::PAGE_SLUG,
			'wpmtm_main'
		);
		add_settings_field(
			// Option key 'chief_td_id' and this method name are unchanged -
			// only the label the TD sees changes (docs/SPEC.md, "Decisions
			// (2026-07-11, per-tournament TD overrides and Chief TD
			// rename)", updated 2026-07-17 "TD default removal"); renaming
			// the option key would break every existing site's stored
			// settings for no user-visible benefit. As of 2026-07-17 this
			// value is a DEFAULT only: it is never applied automatically,
			// only copied onto a tournament by that tournament's "Use
			// default" button.
			'chief_td_id',
			__( 'Default Chief TD ID', 'wp-tournament-manager' ),
			array( $this, 'field_chief_td_id' ),
			self::PAGE_SLUG,
			'wpmtm_main'
		);
		add_settings_field(
			'assistant_td_id',
			__( 'Default Assistant TD ID', 'wp-tournament-manager' ),
			array( $this, 'field_assistant_td_id' ),
			self::PAGE_SLUG,
			'wpmtm_main'
		);
		add_settings_field(
			// Not a stored option: renders the "Validate with USCF" button
			// (docs/SPEC.md, 2026-07-14, USCF status validation; renamed
			// from "Validate TDs" 2026-07-18 - it also checks the club
			// affiliate, not just the TDs) directly under the three ID
			// fields above, so the on-demand USCF check sits next to the
			// values it checks. sanitize_options() never sees it
			// (type="button", no name attribute, nothing posted).
			'validate_tds',
			__( 'USCF status', 'wp-tournament-manager' ),
			array( $this, 'field_validate_tds' ),
			self::PAGE_SLUG,
			'wpmtm_main'
		);
		add_settings_field(
			'default_city',
			__( 'Default city', 'wp-tournament-manager' ),
			array( $this, 'field_default_city' ),
			self::PAGE_SLUG,
			'wpmtm_main'
		);
		add_settings_field(
			'default_state',
			__( 'Default state', 'wp-tournament-manager' ),
			array( $this, 'field_default_state' ),
			self::PAGE_SLUG,
			'wpmtm_main'
		);
		add_settings_field(
			'default_zipcode',
			__( 'Default zip code', 'wp-tournament-manager' ),
			array( $this, 'field_default_zipcode' ),
			self::PAGE_SLUG,
			'wpmtm_main'
		);
		add_settings_field(
			'timectl_presets',
			__( 'Time control presets', 'wp-tournament-manager' ),
			array( $this, 'field_timectl_presets' ),
			self::PAGE_SLUG,
			'wpmtm_main'
		);
		add_settings_field(
			'delete_data_on_uninstall',
			__( 'On uninstall', 'wp-tournament-manager' ),
			array( $this, 'field_delete_data_on_uninstall' ),
			self::PAGE_SLUG,
			'wpmtm_main'
		);
		add_settings_field(
			// Option key 'verify_ratings' is unchanged - only its MEANING
			// widens here (docs/SPEC.md, "Decisions (2026-07-18, master
			// automatic-checking toggle)"): it now gates BOTH the automatic
			// membership check and the rating overwrite at registration,
			// not the rating overwrite alone. Default ON, same as before.
			'verify_ratings',
			__( 'Automatically check registrant memberships and ratings with USCF', 'wp-tournament-manager' ),
			array( $this, 'field_verify_ratings' ),
			self::PAGE_SLUG,
			'wpmtm_main'
		);

		add_settings_section(
			'wpmtm_access',
			__( 'Access', 'wp-tournament-manager' ),
			'__return_false',
			self::PAGE_SLUG
		);
		add_settings_field(
			'role_decision',
			__( 'Tournament Manager role', 'wp-tournament-manager' ),
			array( $this, 'field_role_decision' ),
			self::PAGE_SLUG,
			'wpmtm_access'
		);
	}

	// -----------------------------------------------------------------
	// Field renderers
	// -----------------------------------------------------------------

	private function opts() {
		return WPMTM_Plugin::instance()->get_opts();
	}

	public function field_affiliate_id() {
		$opts = $this->opts();
		printf(
			'<input type="text" class="regular-text" name="%1$s[affiliate_id]" value="%2$s" placeholder="A1234567">',
			esc_attr( WPMTM_Plugin::OPTION_KEY ),
			esc_attr( $opts['affiliate_id'] )
		);
		echo '<p class="description">' . esc_html__( 'The letter A followed by 7 digits, or leave blank. Only required to export RATED tournaments.', 'wp-tournament-manager' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'To submit a rated tournament on behalf of an affiliate other than the directing TD\'s own, that TD must be an authorized tournament director of the other affiliate. That affiliate\'s Affiliate Manager can add a TD as one of its authorized TDs.', 'wp-tournament-manager' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'The submitting TD is responsible for the tournament\'s validity and payment of the USCF tournament fee. The lead TD, if different from the submitting TD, is also responsible for the tournament being valid. If the submitter is not the directing TD, do not list the submitter as a TD on the tournament.', 'wp-tournament-manager' ) . '</p>';
	}

	public function field_chief_td_id() {
		$opts = $this->opts();
		printf(
			'<input type="text" class="regular-text" name="%1$s[chief_td_id]" value="%2$s" placeholder="12345678">',
			esc_attr( WPMTM_Plugin::OPTION_KEY ),
			esc_attr( $opts['chief_td_id'] )
		);
		echo '<p class="description">' . esc_html__( '8-digit USCF member ID, or leave blank. A starting value only: it is never applied to a tournament automatically. Each tournament\'s edit page has its own Chief TD ID field with a "Use default" button that copies this value in, one click at a time.', 'wp-tournament-manager' ) . '</p>';
	}

	public function field_assistant_td_id() {
		$opts = $this->opts();
		printf(
			'<input type="text" class="regular-text" name="%1$s[assistant_td_id]" value="%2$s" placeholder="">',
			esc_attr( WPMTM_Plugin::OPTION_KEY ),
			esc_attr( $opts['assistant_td_id'] )
		);
		echo '<p class="description">' . esc_html__( '8-digit USCF member ID, or leave blank. A starting value only: it is never applied to a tournament automatically. Each tournament\'s edit page has its own Assistant TD ID field with a "Use default" button that copies this value in, one click at a time.', 'wp-tournament-manager' ) . '</p>';
	}

	/**
	 * The "Validate with USCF" button (docs/SPEC.md, 2026-07-14, USCF
	 * status validation; renamed from "Validate TDs" 2026-07-18 since it
	 * checks the club affiliate too, not just the TDs): checks the saved
	 * affiliate ID, Chief TD, and Assistant TD (when set) against the
	 * USCF ratings API via admin-ajax (WPMTM_USCF_Status::ajax_validate_tds(),
	 * settings context, through-date today); assets/wpmtm-admin.js renders
	 * the result rows into the container below the button and swaps its
	 * text to "Validating with USCF..." while the request is in flight.
	 */
	public function field_validate_tds() {
		printf(
			'<button type="button" class="button" data-wpmtm-validate-tds data-context="settings" data-nonce="%1$s">%2$s</button>',
			esc_attr( wp_create_nonce( 'wpmtm_validate_tds' ) ),
			esc_html__( 'Validate with USCF', 'wp-tournament-manager' )
		);
		echo '<p class="description">' . esc_html__( 'Checks the saved affiliate ID, Chief TD, and Assistant TD (when set) against the USCF ratings API: club affiliate status, membership, TD certification, and Safe Play, as active through today. Nothing is blocked by the result. Save changes first if the IDs above have been edited.', 'wp-tournament-manager' ) . '</p>';
		echo '<div data-wpmtm-validate-tds-results></div>';
	}

	public function field_default_city() {
		$opts = $this->opts();
		printf(
			'<input type="text" class="regular-text" maxlength="21" name="%1$s[default_city]" value="%2$s">',
			esc_attr( WPMTM_Plugin::OPTION_KEY ),
			esc_attr( $opts['default_city'] )
		);
		echo '<p class="description">' . esc_html__( 'Used as the placeholder default when adding a new tournament. Capped at 21 characters, the USCF export format\'s limit for the event city (H_CITY).', 'wp-tournament-manager' ) . '</p>';
	}

	public function field_default_state() {
		$opts = $this->opts();
		printf(
			'<input type="text" class="small-text" maxlength="2" name="%1$s[default_state]" value="%2$s" placeholder="OR">',
			esc_attr( WPMTM_Plugin::OPTION_KEY ),
			esc_attr( $opts['default_state'] )
		);
	}

	public function field_default_zipcode() {
		$opts = $this->opts();
		printf(
			'<input type="text" class="regular-text" name="%1$s[default_zipcode]" value="%2$s">',
			esc_attr( WPMTM_Plugin::OPTION_KEY ),
			esc_attr( $opts['default_zipcode'] )
		);
	}

	public function field_timectl_presets() {
		$opts = $this->opts();
		printf(
			'<textarea class="large-text code" rows="5" name="%1$s[timectl_presets]">%2$s</textarea>',
			esc_attr( WPMTM_Plugin::OPTION_KEY ),
			esc_textarea( $opts['timectl_presets'] )
		);
		echo '<p class="description">' . esc_html__( 'One canonical USCF time control per line, e.g. "G/30;d0". Offered as suggestions when adding a section.', 'wp-tournament-manager' ) . '</p>';
	}

	public function field_delete_data_on_uninstall() {
		$opts = $this->opts();
		printf(
			'<label><input type="checkbox" name="%1$s[delete_data_on_uninstall]" value="1" %2$s> %3$s</label>',
			esc_attr( WPMTM_Plugin::OPTION_KEY ),
			checked( ! empty( $opts['delete_data_on_uninstall'] ), true, false ),
			esc_html__( 'Delete all tournament data (tables) when this plugin is uninstalled.', 'wp-tournament-manager' )
		);
		echo '<p class="description">' . esc_html__( 'Off by default so club history survives an accidental uninstall. Plugin options are always removed on uninstall regardless of this setting.', 'wp-tournament-manager' ) . '</p>';
	}

	/**
	 * Master automatic-checking toggle (docs/SPEC.md, "Decisions
	 * (2026-07-18, master automatic-checking toggle)"; option key
	 * 'verify_ratings' unchanged, meaning widened): when on,
	 * WPMTM_Registration_Check does its usual automatic work at
	 * registration - the USCF membership check (registrant-facing warning,
	 * nothing saved) AND the rating overwrite. When off, it does NEITHER;
	 * WPMTM_Registration_Check::checks_enabled_for() is the single pure
	 * gate both paths share, so there is no way for one to run without the
	 * other. Default on. The manual "Validate players" and "Validate with
	 * USCF" buttons are unaffected either way - they always call the API
	 * on demand, which is the fallback this toggle exists to preserve.
	 */
	public function field_verify_ratings() {
		$opts = $this->opts();
		printf(
			'<label><input type="checkbox" name="%1$s[verify_ratings]" value="1" %2$s> %3$s</label>',
			esc_attr( WPMTM_Plugin::OPTION_KEY ),
			checked( ! empty( $opts['verify_ratings'] ), true, false ),
			esc_html__( 'Check registrant memberships and verify ratings against USCF automatically at registration.', 'wp-tournament-manager' )
		);
		echo '<p class="description">' . esc_html__( 'When enabled, a registrant who enters a USCF ID and whose membership is not active through the event\'s last day sees a heads-up on the order confirmation page, but nothing is blocked. Their self-entered rating is replaced with their official USCF rating (Regular preferred, Quick as a fallback). A rating is only replaced when the USCF API actually returns one. These automatic checks use the USCF MUIR API v1, which USCF does not officially support and which may stop working if it is replaced by the MUIR API v2 currently in development. Disable this setting if it misbehaves. The manual "Validate players" button on an event\'s Registrations tab and "Validate with USCF" buttons are unaffected by this setting and always check on demand.', 'wp-tournament-manager' ) . '</p>';
	}

	public function field_role_decision() {
		$decision = get_option( 'wpmtm_role_decision', '' );
		printf(
			'<label><input type="checkbox" name="wpmtm_role_decision" value="1" %1$s> %2$s</label>',
			checked( 'role' === $decision, true, false ),
			esc_html__( 'Provide a dedicated Tournament Manager role', 'wp-tournament-manager' )
		);
		echo '<p class="description">' . esc_html__( 'Administrators always keep access regardless of this setting. Checking this creates a "Tournament Manager" role that can be assigned to a volunteer TD so they can manage tournaments without being a full site administrator.', 'wp-tournament-manager' ) . '</p>';
	}

	// -----------------------------------------------------------------
	// Sanitization
	// -----------------------------------------------------------------

	public function sanitize_options( $input ) {
		$input    = is_array( $input ) ? wp_unslash( $input ) : array();
		$existing = get_option( WPMTM_Plugin::OPTION_KEY, array() );
		$existing = is_array( $existing ) ? $existing : array();
		$out      = wp_parse_args( $existing, WPMTM_Plugin::DEFAULTS );

		// Audit item 47: this used to enforce /^A\d{7}$/ while the
		// per-tournament override on the tournament form went through
		// WPMTM_USCF_Status::sanitize_affiliate_id() (a letter followed by
		// digits, capped at the varchar(10) column width). Two rules for one
		// field, with contradictory error copy, and an affiliate ID the
		// tournament form accepted was rejected here. Both now use
		// sanitize_affiliate_id(), the same function the registration check,
		// the CLI, and the export gate already validate against.
		$affiliate_raw = isset( $input['affiliate_id'] ) ? trim( sanitize_text_field( $input['affiliate_id'] ) ) : '';
		$affiliate     = '' !== $affiliate_raw ? WPMTM_USCF_Status::sanitize_affiliate_id( $affiliate_raw ) : '';
		if ( '' === $affiliate_raw || ( '' !== $affiliate && strlen( $affiliate ) <= 10 ) ) {
			$out['affiliate_id'] = $affiliate;
		} else {
			add_settings_error( WPMTM_Plugin::OPTION_KEY, 'affiliate_id_invalid', __( 'Affiliate ID must be blank or a letter followed by digits, up to 10 characters (e.g. A1234567). Previous value kept.', 'wp-tournament-manager' ) );
		}

		$chief = isset( $input['chief_td_id'] ) ? sanitize_text_field( $input['chief_td_id'] ) : '';
		if ( '' === $chief || preg_match( '/^\d{8}$/', $chief ) ) {
			$out['chief_td_id'] = $chief;
		} else {
			add_settings_error( WPMTM_Plugin::OPTION_KEY, 'chief_td_id_invalid', __( 'Chief TD ID must be blank or 8 digits. Previous value kept.', 'wp-tournament-manager' ) );
		}

		$assistant = isset( $input['assistant_td_id'] ) ? sanitize_text_field( $input['assistant_td_id'] ) : '';
		if ( '' === $assistant || preg_match( '/^\d{8}$/', $assistant ) ) {
			$out['assistant_td_id'] = $assistant;
		} else {
			add_settings_error( WPMTM_Plugin::OPTION_KEY, 'assistant_td_id_invalid', __( 'Assistant TD ID must be blank or 8 digits. Previous value kept.', 'wp-tournament-manager' ) );
		}

		$out['default_city'] = isset( $input['default_city'] ) ? sanitize_text_field( $input['default_city'] ) : '';

		$state = isset( $input['default_state'] ) ? strtoupper( sanitize_text_field( $input['default_state'] ) ) : '';
		if ( '' === $state || preg_match( '/^[A-Z]{2}$/', $state ) ) {
			$out['default_state'] = $state;
		} else {
			add_settings_error( WPMTM_Plugin::OPTION_KEY, 'default_state_invalid', __( 'Default state must be blank or 2 letters. Previous value kept.', 'wp-tournament-manager' ) );
		}

		$out['default_zipcode'] = isset( $input['default_zipcode'] ) ? sanitize_text_field( $input['default_zipcode'] ) : '';

		$presets_raw = isset( $input['timectl_presets'] ) ? (string) $input['timectl_presets'] : '';
		$lines       = preg_split( '/\r\n|\r|\n/', $presets_raw );
		$lines       = array_filter( array_map( 'sanitize_text_field', array_map( 'trim', $lines ) ) );
		$out['timectl_presets'] = implode( "\n", $lines );

		$out['delete_data_on_uninstall'] = ! empty( $input['delete_data_on_uninstall'] ) ? 1 : 0;

		$out['verify_ratings'] = ! empty( $input['verify_ratings'] ) ? 1 : 0;

		WPMTM_Plugin::instance()->invalidate_opts_cache();

		return $out;
	}

	/**
	 * Sanitize callback for the separate 'wpmtm_role_decision' option
	 * (registered above, not part of WPMTM_Plugin::OPTION_KEY). Toggling the
	 * checkbox on the Settings page creates or removes the
	 * 'wpmtm_tournament_manager' role (WPMTM_Roles) to match - this is the
	 * role decision's only path (docs/SPEC.md, "Design (2026-07-16, setup
	 * guide redesign)").
	 *
	 * @param mixed $input Raw posted checkbox value, or null when unchecked
	 *                      (unchecked boxes are omitted from the POST body).
	 * @return string 'role' or 'admins'.
	 */
	public function sanitize_role_decision( $input ) {
		$existing = get_option( 'wpmtm_role_decision', '' );
		$checked  = ! empty( $input );

		if ( $checked && 'role' !== $existing ) {
			WPMTM_Roles::create_role();
			return 'role';
		}

		if ( ! $checked && 'role' === $existing ) {
			WPMTM_Roles::remove_role();
			return 'admins';
		}

		return $checked ? 'role' : 'admins';
	}

	// -----------------------------------------------------------------
	// Page
	// -----------------------------------------------------------------

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No permission to access this page.', 'wp-tournament-manager' ) );
		}
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Tournament Manager Settings', 'wp-tournament-manager' ); ?></h1>
			<?php // 2026-07-21 (setup guide button position consistency): moved here, right after the H1, matching every other TM admin page - previously this rendered lower, wrapped in its own <p>, after the description and the guide-shown notice. ?>
			<?php WPMTM_Wizard::instance()->render_show_guide_button( 'page-title-action' ); ?>
			<hr class="wp-header-end">
			<?php $this->render_admin_header(); ?>
			<p class="description">
				<?php esc_html_e( 'Affiliate and TD IDs are only required to export DBF files for RATED tournaments. Unrated club nights need none of this.', 'wp-tournament-manager' ); ?>
			</p>
			<?php WPMTM_Wizard::instance()->render_guide_shown_notice(); ?>
			<?php settings_errors( WPMTM_Plugin::OPTION_KEY ); ?>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
