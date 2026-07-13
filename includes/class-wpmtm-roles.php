<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The optional 'wpmtm_tournament_manager' role: lets a club grant a
 * volunteer TD the WPMTM_CAPABILITY without making them a full WordPress
 * administrator. Never created automatically on activation - see
 * WPMTM_Schema::add_capability(), which keeps granting WPMTM_CAPABILITY to
 * administrators unconditionally, regardless of whether this role exists.
 *
 * The TD opts into this role via the wizard's "access" step
 * (WPMTM_Wizard::handle_action(), do=set_access) or later from the
 * Settings page (WPMTM_Settings). The decision is tracked in the
 * 'wpmtm_role_decision' option: '' (undecided), 'role' (this role exists
 * and carries the capability), or 'admins' (declined - administrators
 * only, the pre-existing behavior).
 */
class WPMTM_Roles {

	const ROLE = 'wpmtm_tournament_manager';

	/**
	 * Adds the role if it does not exist yet, and makes sure it always
	 * carries 'read' plus WPMTM_CAPABILITY. Safe to call repeatedly.
	 */
	public static function create_role() {
		$role = get_role( self::ROLE );

		if ( ! $role ) {
			add_role(
				self::ROLE,
				__( 'Tournament Manager', 'wp-tournament-manager' ),
				array(
					'read'            => true,
					WPMTM_CAPABILITY => true,
				)
			);
			return;
		}

		if ( ! $role->has_cap( 'read' ) ) {
			$role->add_cap( 'read' );
		}
		if ( ! $role->has_cap( WPMTM_CAPABILITY ) ) {
			$role->add_cap( WPMTM_CAPABILITY );
		}
	}

	/**
	 * Removes the role if present. Administrators keep WPMTM_CAPABILITY
	 * regardless (granted separately by WPMTM_Schema::add_capability()), so
	 * this can never lock an administrator out.
	 */
	public static function remove_role() {
		if ( get_role( self::ROLE ) ) {
			remove_role( self::ROLE );
		}
	}
}
