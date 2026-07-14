<?php
/**
 * Uninstall cleanup for Tournament Manager.
 * Always removes wpmtm_options, wpmtm_db_version, wpmtm_role_decision, the
 * wpmtm_manage_tournaments capability from every role, and the optional
 * wpmtm_tournament_manager role (WPMTM_Roles) if it was ever created.
 * Drops the five wpmtm_* tables only when delete_data_on_uninstall was
 * enabled in the saved options - off by default, so club history survives
 * an accidental uninstall.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$options = get_option( 'wpmtm_options', array() );

delete_option( 'wpmtm_options' );
delete_option( 'wpmtm_db_version' );
delete_option( 'wpmtm_role_decision' );

global $wp_roles;
if ( ! isset( $wp_roles ) ) {
	$wp_roles = wp_roles(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
}
foreach ( $wp_roles->roles as $role_name => $role_info ) {
	$role = get_role( $role_name );
	if ( $role && $role->has_cap( 'wpmtm_manage_tournaments' ) ) {
		$role->remove_cap( 'wpmtm_manage_tournaments' );
	}
}

if ( get_role( 'wpmtm_tournament_manager' ) ) {
	remove_role( 'wpmtm_tournament_manager' );
}

if ( is_array( $options ) && ! empty( $options['delete_data_on_uninstall'] ) ) {
	global $wpdb;
	$prefix = $wpdb->prefix . 'wpmtm_';
	foreach ( array( 'byes', 'games', 'players', 'sections', 'tournaments' ) as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$prefix}{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name built from a fixed internal prefix, no user input.
	}
}
