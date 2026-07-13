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
		// option, not part of WPMTM_Plugin::OPTION_KEY: it is also written
		// outside this settings form, by WPMTM_Wizard's "access" step, and
		// keeping it out of the options array means neither writer has to
		// know about the other's sanitize/merge logic.
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
			// only the label the TD sees is renamed Chief TD (docs/SPEC.md,
			// "Decisions (2026-07-11, per-tournament TD overrides and Chief
			// TD rename)"); renaming the option key would break every
			// existing site's stored settings for no user-visible benefit.
			'chief_td_id',
			__( 'Chief TD USCF ID', 'wp-tournament-manager' ),
			array( $this, 'field_chief_td_id' ),
			self::PAGE_SLUG,
			'wpmtm_main'
		);
		add_settings_field(
			'assistant_td_id',
			__( 'Assistant TD USCF ID', 'wp-tournament-manager' ),
			array( $this, 'field_assistant_td_id' ),
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
	}

	public function field_chief_td_id() {
		$opts = $this->opts();
		printf(
			'<input type="text" class="regular-text" name="%1$s[chief_td_id]" value="%2$s" placeholder="12345678">',
			esc_attr( WPMTM_Plugin::OPTION_KEY ),
			esc_attr( $opts['chief_td_id'] )
		);
		echo '<p class="description">' . esc_html__( '8-digit USCF member ID, or leave blank. Only required to export RATED tournaments.', 'wp-tournament-manager' ) . '</p>';
	}

	public function field_assistant_td_id() {
		$opts = $this->opts();
		printf(
			'<input type="text" class="regular-text" name="%1$s[assistant_td_id]" value="%2$s" placeholder="">',
			esc_attr( WPMTM_Plugin::OPTION_KEY ),
			esc_attr( $opts['assistant_td_id'] )
		);
		echo '<p class="description">' . esc_html__( '8-digit USCF member ID, or leave blank.', 'wp-tournament-manager' ) . '</p>';
	}

	public function field_default_city() {
		$opts = $this->opts();
		printf(
			'<input type="text" class="regular-text" name="%1$s[default_city]" value="%2$s">',
			esc_attr( WPMTM_Plugin::OPTION_KEY ),
			esc_attr( $opts['default_city'] )
		);
		echo '<p class="description">' . esc_html__( 'Used as the placeholder default when adding a new tournament.', 'wp-tournament-manager' ) . '</p>';
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

	public function field_role_decision() {
		$decision = get_option( 'wpmtm_role_decision', '' );
		printf(
			'<label><input type="checkbox" name="wpmtm_role_decision" value="1" %1$s> %2$s</label>',
			checked( 'role' === $decision, true, false ),
			esc_html__( 'Provide a dedicated Tournament Manager role', 'wp-tournament-manager' )
		);
		echo '<p class="description">' . esc_html__( 'Administrators always keep access regardless of this setting. Checking this creates a "Tournament Manager" role your club can assign to a volunteer TD so they can manage tournaments without being a full site administrator.', 'wp-tournament-manager' ) . '</p>';
	}

	// -----------------------------------------------------------------
	// Sanitization
	// -----------------------------------------------------------------

	public function sanitize_options( $input ) {
		$input    = is_array( $input ) ? wp_unslash( $input ) : array();
		$existing = get_option( WPMTM_Plugin::OPTION_KEY, array() );
		$existing = is_array( $existing ) ? $existing : array();
		$out      = wp_parse_args( $existing, WPMTM_Plugin::DEFAULTS );

		$affiliate = isset( $input['affiliate_id'] ) ? strtoupper( sanitize_text_field( $input['affiliate_id'] ) ) : '';
		if ( '' === $affiliate || preg_match( '/^A\d{7}$/', $affiliate ) ) {
			$out['affiliate_id'] = $affiliate;
		} else {
			add_settings_error( WPMTM_Plugin::OPTION_KEY, 'affiliate_id_invalid', __( 'Affiliate ID must be blank or the letter A followed by 7 digits. Previous value kept.', 'wp-tournament-manager' ) );
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

		WPMTM_Plugin::instance()->invalidate_opts_cache();

		return $out;
	}

	/**
	 * Sanitize callback for the separate 'wpmtm_role_decision' option
	 * (registered above, not part of WPMTM_Plugin::OPTION_KEY). Toggling the
	 * checkbox on the Settings page creates or removes the
	 * 'wpmtm_tournament_manager' role (WPMTM_Roles) to match, the same
	 * create/remove pair the wizard's "access" step calls.
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
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-tournament-manager' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Tournament Manager Settings', 'wp-tournament-manager' ); ?></h1>
			<?php $this->render_admin_header(); ?>
			<p class="description">
				<?php esc_html_e( 'Affiliate and TD IDs are only required to export DBF files for RATED tournaments. Unrated club nights need none of this.', 'wp-tournament-manager' ); ?>
			</p>
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
