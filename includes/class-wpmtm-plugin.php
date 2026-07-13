<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core singleton: options access and small helpers shared by the Settings
 * and Admin classes. Holds no admin_menu / admin_post hooks of its own.
 */
class WPMTM_Plugin {

	private static $instance = null;

	const OPTION_KEY = 'wpmtm_options';

	/** Default values for the wpmtm_options array. */
	const DEFAULTS = array(
		'affiliate_id'              => '',
		'chief_td_id'               => '',
		'assistant_td_id'           => '',
		'default_city'              => '',
		'default_state'             => '',
		'default_zipcode'           => '',
		'timectl_presets'           => "G/30;d0\nG/25;+5",
		'delete_data_on_uninstall'  => 0,
	);

	private $opts_cache = null;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Reserved for future shared hooks (cache invalidation, etc.).
		// No admin UI is registered here - see WPMTM_Settings / WPMTM_Admin.
	}

	/** Plugin options merged over defaults, cached per-request. */
	public function get_opts() {
		if ( null === $this->opts_cache ) {
			$saved = get_option( self::OPTION_KEY, array() );
			$this->opts_cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::DEFAULTS );
		}
		return $this->opts_cache;
	}

	public function invalidate_opts_cache() {
		$this->opts_cache = null;
	}

	/** Time control presets from settings, one per line, blank lines dropped. */
	public function get_timectl_presets() {
		$opts  = $this->get_opts();
		$lines = preg_split( '/\r\n|\r|\n/', (string) $opts['timectl_presets'] );
		return array_values( array_filter( array_map( 'trim', $lines ) ) );
	}

	/**
	 * Derives a USCF rating system (B/Q/R) from a canonical time control
	 * string. Thin wrapper around WPMTM_Time_Control::classify() for admin
	 * call sites that only need the rating-system letter, not the full
	 * reason/total breakdown.
	 *
	 * @return string|null 'B', 'Q', or 'R'; null if unparseable or below
	 *                      the 5-minute blitz minimum.
	 */
	public static function derive_r_system( $timectl ) {
		return WPMTM_Time_Control::classify( $timectl )['system'];
	}

	/**
	 * Validates a candidate linked-event post id: the post must exist, and
	 * (when The Events Calendar is active) must be a tribe_events post.
	 * Shared by the tournament save handler and any other future caller
	 * that needs the same "does this event actually exist and is it the
	 * right post type" check.
	 *
	 * @param int $id Candidate event post id.
	 * @return int The validated id, or 0 if it is not a usable event.
	 */
	public static function validate_event_post_id( $id ) {
		$id = (int) $id;
		if ( $id <= 0 ) {
			return 0;
		}
		$event_post = get_post( $id );
		$valid      = null !== $event_post && ( ! post_type_exists( 'tribe_events' ) || 'tribe_events' === $event_post->post_type );
		return $valid ? $id : 0;
	}
}
