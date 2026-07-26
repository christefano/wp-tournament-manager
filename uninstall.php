<?php
/**
 * Uninstall cleanup for Tournament Manager.
 * Always removes wpmtm_options, wpmtm_db_version, wpmtm_role_decision, every
 * per-tournament wpmtm_td_check_{id} option (docs/SPEC.md, 2026-07-16, TD
 * check timestamp), every _wpmtm_rating_source / _wpmtm_rating_checked
 * attendee postmeta pair TM ever wrote on someone else's tec_tc_attendee
 * post (docs/SPEC.md, 2026-07-17, rating provenance), the
 * wpmtm_manage_tournaments capability from every role, and the optional
 * wpmtm_tournament_manager role (WPMTM_Roles) if it was ever created. Drops
 * the five wpmtm_* tables only when delete_data_on_uninstall was enabled in
 * the saved options - off by default, so club history survives an
 * accidental uninstall. The attendee postmeta cleanup is unconditional
 * (not gated on delete_data_on_uninstall) like the TD-check option above:
 * it is TM's own residue on a post type TM does not own, not tournament
 * history - the actual history (wpmtm_players.rating_source/rating_checked)
 * lives in, and is dropped with, the gated wpmtm_players table.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- not a real global: WP core's uninstall_plugin() includes this file from inside its own function body, so every variable here is local to that call frame, not the PHP global scope.
$options = get_option( 'wpmtm_options', array() );

delete_option( 'wpmtm_options' );
delete_option( 'wpmtm_db_version' );
delete_option( 'wpmtm_role_decision' );

global $wpdb;
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- not a real global (see note above); direct query against wp_options is inherent (no core API lists options by LIKE pattern), value is bound via $wpdb->prepare().
$td_check_options = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'wpmtm_td_check_' ) . '%'
	)
);
foreach ( $td_check_options as $option_name ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- not a real global, see note above.
	delete_option( $option_name );
}

// wpmtm_exported_{id} (docs/SPEC.md, 2026-07-21, setup guide Export step):
// same per-tournament option shape as wpmtm_td_check_ above, recording when
// a USCF export zip was last generated so the setup guide can mark its
// Export step complete. Swept the same way.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- not a real global (see note above); direct query against wp_options is inherent (no core API lists options by LIKE pattern), value is bound via $wpdb->prepare().
$exported_options = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'wpmtm_exported_' ) . '%'
	)
);
foreach ( $exported_options as $option_name ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- not a real global, see note above.
	delete_option( $option_name );
}

// _wpmtm_rating_source / _wpmtm_rating_checked (docs/SPEC.md, 2026-07-17,
// rating provenance): fixed meta keys (not per-id suffixed, unlike
// wpmtm_td_check_ above), so an exact meta_key match against wp_postmeta
// is enough - no LIKE scan needed. Written by WPMTM_Registration_Check on
// tec_tc_attendee posts, which TM does not own and no longer has any
// other way to reach once this plugin is gone.
foreach ( array( '_wpmtm_rating_source', '_wpmtm_rating_checked' ) as $meta_key ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- not a real global, see note above ($meta_key is foreach-local).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- direct query against wp_postmeta is inherent (no core API deletes postmeta across every post by key alone); value bound via $wpdb->prepare().
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", $meta_key ) );
}

global $wp_roles;
if ( ! isset( $wp_roles ) ) {
	$wp_roles = wp_roles(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
}
foreach ( $wp_roles->roles as $role_name => $role_info ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- not a real global, see note above ($role_name and $role_info are foreach-local).
	$role = get_role( $role_name );
	if ( $role && $role->has_cap( 'wpmtm_manage_tournaments' ) ) {
		$role->remove_cap( 'wpmtm_manage_tournaments' );
	}
}

if ( get_role( 'wpmtm_tournament_manager' ) ) {
	remove_role( 'wpmtm_tournament_manager' );
}

// Transient cleanup: wpmtm_uscf_member_*, wpmtm_uscf_affiliate_*
// (USCF API caches), wpmtm_reg_warn_* (registration warnings), and
// wpmtm_notice_* (admin notices).
//
// Two important differences from the options sweeps above:
// - Every transient has a paired _transient_timeout_ row in wp_options.
//   Deleting only the transient value leaves orphaned timeout rows behind.
//   Use delete_transient() to remove both, ensuring clean database state.
// - On a site with a persistent object cache (Redis, Memcache, APCu),
//   transients never touch wp_options at all, so direct SQL discovery finds
//   nothing. delete_transient() works in both cases: it clears the cache
//   backend on persistent-cache sites, and deletes wp_options rows on
//   database-only sites. Guard the SQL discovery with wp_using_ext_object_cache()
//   so we don't try to query options that don't exist; a persistent-cache site
//   has no transient rows to discover in wp_options anyway, and relies on
//   transient expiration in the cache backend itself.
if ( ! wp_using_ext_object_cache() ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- not a real global (see note at top of file); direct query against wp_options is inherent (no core API lists transients by LIKE pattern), value is bound via $wpdb->prepare().
	// Two easy mistakes here, both of which make this sweep silently clean
	// nothing while looking correct, so they are spelled out:
	// - The stored option name is `_transient_wpmtm_...` WITH a leading
	//   underscore. A pattern of `transient_wpmtm_%` is anchored at the start
	//   of the string and matches zero rows.
	// - The SUBSTRING offset must use the length of the RAW prefix, not the
	//   esc_like()'d one. esc_like() adds a backslash before each underscore,
	//   so the escaped string is longer than the text actually stored and
	//   would cut several characters off every name recovered.
	// - The two prefixes here are deliberately DIFFERENT lengths. The LIKE
	//   narrows to this plugin's own transients (`_transient_wpmtm_%`), but
	//   the SUBSTRING strips only `_transient_`, because delete_transient()
	//   wants the name WITH its `wpmtm_` prefix still attached. Stripping the
	//   longer prefix returns `uscf_member_123` where delete_transient()
	//   needs `wpmtm_uscf_member_123`, and it would then delete nothing.
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- not a real global (see note at top of file); direct query against wp_options is inherent (no core API lists transients by LIKE pattern), values are bound via $wpdb->prepare().
	$transient_names = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT SUBSTRING( option_name, %d ) FROM {$wpdb->options} WHERE option_name LIKE %s",
			strlen( '_transient_' ) + 1,
			$wpdb->esc_like( '_transient_wpmtm_' ) . '%'
		)
	);
	foreach ( $transient_names as $transient_name ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- not a real global, see note at top of file.
		delete_transient( $transient_name );
	}
}

if ( is_array( $options ) && ! empty( $options['delete_data_on_uninstall'] ) ) {
	global $wpdb;
	$prefix = $wpdb->prefix . 'wpmtm_'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- not a real global, see note above.
	foreach ( array( 'byes', 'games', 'players', 'sections', 'tournaments' ) as $table ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- not a real global, see note above ($table is foreach-local).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DROP TABLE cannot use $wpdb->prepare() (identifiers, not values); $prefix is $wpdb->prefix + a fixed literal, $table is one of five hardcoded literals above, neither is user input.
		$wpdb->query( "DROP TABLE IF EXISTS {$prefix}{$table}" );
	}
}
