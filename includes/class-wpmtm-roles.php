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
 * The TD opts into this role from the Settings page
 * (WPMTM_Settings::field_role_decision()), the role decision's only path
 * (the setup guide's old "access" step offered the same choice; it was
 * dropped in the 2026-07-16 setup guide redesign since it duplicated this
 * Settings control - see docs/SPEC.md). The decision is tracked in the
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

	/**
	 * Whether the current user may manage a given tournament: administrators
	 * (manage_options) always may, regardless of who created it - the same
	 * blanket access WPMTM_Schema::add_capability() already grants them.
	 * Anyone else needs WPMTM_CAPABILITY AND to be the tournament's own
	 * creator (wpmtm_tournaments.created_by). A tournament with no recorded
	 * creator (created before the created_by column existed) is grandfathered
	 * in as manageable by any WPMTM_CAPABILITY user, since there is no
	 * reliable owner to check it against.
	 *
	 * @param object|int|null $tournament A tournament row (must have ->id and
	 *                                    ->created_by), a tournament id, or
	 *                                    null/0 (returns false).
	 * @return bool
	 */
	public static function user_can_manage_tournament( $tournament ) {
		if ( ! current_user_can( WPMTM_CAPABILITY ) ) {
			return false;
		}
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		if ( is_numeric( $tournament ) ) {
			$tournament = $tournament ? WPMTM_Repository::get_tournament( (int) $tournament ) : null;
		}
		if ( ! $tournament ) {
			return false;
		}
		$created_by = isset( $tournament->created_by ) ? (int) $tournament->created_by : 0;
		if ( ! $created_by ) {
			return true;
		}
		return $created_by === get_current_user_id();
	}
}
