<?php
/**
 * Plugin Name: Tournament Manager
 * Description: Club-level USCF chess tournament management: setup guide, roster import, pairing aid, round results, standings, and USCF DBF export.
 * Version: 1.5.0
 * Author: Christefano Reyes
 * Plugin URI: https://github.com/christefano/wp-tournament-manager
 * Author URI: https://github.com/christefano/
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Tested up to: 6.7
 * Text Domain: wp-tournament-manager
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPMTM_VERSION', '1.5.0' );
define( 'WPMTM_PLUGIN_FILE', __FILE__ );
define( 'WPMTM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPMTM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPMTM_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Internal prefix only - never derived from the display name or slug above.
// Renaming the plugin touches the header, these two path constants, the
// folder/file names, and the text domain; nothing else.
define( 'WPMTM_CAPABILITY', 'wpmtm_manage_tournaments' );

// Core, WordPress-independent classes (DBF writer, round-token codec, time
// control classifier, USCF export, pre-export validator, scoring,
// tiebreaks, pairing aid, pairing suggester, round-entry validator). Loaded
// in dependency order.
require_once WPMTM_PLUGIN_DIR . 'includes/class-wpmtm-round-token.php';
require_once WPMTM_PLUGIN_DIR . 'includes/class-wpmtm-time-control.php';
require_once WPMTM_PLUGIN_DIR . 'includes/class-wpmtm-dbf-writer.php';
require_once WPMTM_PLUGIN_DIR . 'includes/class-wpmtm-uscf-export.php';
require_once WPMTM_PLUGIN_DIR . 'includes/class-wpmtm-csv-export.php';
require_once WPMTM_PLUGIN_DIR . 'includes/class-wpmtm-uscf-validator.php';
require_once WPMTM_PLUGIN_DIR . 'includes/class-wpmtm-scoring.php';
require_once WPMTM_PLUGIN_DIR . 'includes/class-wpmtm-tiebreaks.php';
require_once WPMTM_PLUGIN_DIR . 'includes/class-wpmtm-pairing-aid.php';
require_once WPMTM_PLUGIN_DIR . 'includes/class-wpmtm-pairing-suggest.php';
require_once WPMTM_PLUGIN_DIR . 'includes/class-wpmtm-round-entry.php';
require_once WPMTM_PLUGIN_DIR . 'includes/class-wpmtm-round-selector.php';
require_once WPMTM_PLUGIN_DIR . 'includes/class-wpmtm-export-builder.php';
require_once WPMTM_PLUGIN_DIR . 'includes/class-wpmtm-name.php';
require_once WPMTM_PLUGIN_DIR . 'includes/class-wpmtm-test-players.php';

// WordPress layer.
require_once WPMTM_PLUGIN_DIR . 'includes/class-wpmtm-schema.php';
require_once WPMTM_PLUGIN_DIR . 'includes/class-wpmtm-roles.php';
require_once WPMTM_PLUGIN_DIR . 'includes/class-wpmtm-repository.php';
require_once WPMTM_PLUGIN_DIR . 'includes/class-wpmtm-cache.php';
require_once WPMTM_PLUGIN_DIR . 'includes/class-wpmtm-plugin.php';
require_once WPMTM_PLUGIN_DIR . 'includes/trait-wpmtm-admin-shared.php';
require_once WPMTM_PLUGIN_DIR . 'includes/class-wpmtm-settings.php';
require_once WPMTM_PLUGIN_DIR . 'includes/class-wpmtm-etr-import.php';
// Mixed class like WPMTM_ETR_Import: pure/static USCF status verdict logic
// (unit-tested by tests/run-tests.php) plus a WordPress-layer HTTP client
// and admin-ajax handlers.
require_once WPMTM_PLUGIN_DIR . 'includes/class-wpmtm-uscf-status.php';
// USCF membership + rating check at registration (docs/SPEC.md, v1.2).
// Mostly WordPress-layer (hooks Event Tickets' attendee-meta-save action),
// but also carries one pure static decision, checks_enabled_for()
// (docs/SPEC.md, "Decisions (2026-07-16, unrated registration checks)"),
// so tests/run-tests.php loads this file too.
require_once WPMTM_PLUGIN_DIR . 'includes/class-wpmtm-registration-check.php';
require_once WPMTM_PLUGIN_DIR . 'includes/class-wpmtm-admin.php';
require_once WPMTM_PLUGIN_DIR . 'includes/class-wpmtm-admin-sections.php';
require_once WPMTM_PLUGIN_DIR . 'includes/class-wpmtm-admin-players.php';
require_once WPMTM_PLUGIN_DIR . 'includes/class-wpmtm-admin-import.php';
require_once WPMTM_PLUGIN_DIR . 'includes/class-wpmtm-admin-export.php';
require_once WPMTM_PLUGIN_DIR . 'includes/trait-wpmtm-wizard-steps.php';
require_once WPMTM_PLUGIN_DIR . 'includes/trait-wpmtm-wizard-panel.php';
require_once WPMTM_PLUGIN_DIR . 'includes/trait-wpmtm-wizard-readme.php';
require_once WPMTM_PLUGIN_DIR . 'includes/class-wpmtm-wizard.php';
require_once WPMTM_PLUGIN_DIR . 'includes/trait-wpmtm-frontend-public-standings.php';
require_once WPMTM_PLUGIN_DIR . 'includes/trait-wpmtm-frontend-public-wallchart.php';
require_once WPMTM_PLUGIN_DIR . 'includes/trait-wpmtm-frontend-public-pairings.php';
require_once WPMTM_PLUGIN_DIR . 'includes/trait-wpmtm-frontend-public-card.php';
require_once WPMTM_PLUGIN_DIR . 'includes/class-wpmtm-frontend-public.php';
require_once WPMTM_PLUGIN_DIR . 'includes/trait-wpmtm-frontend-td-panel.php';
require_once WPMTM_PLUGIN_DIR . 'includes/trait-wpmtm-frontend-td-roundform.php';
require_once WPMTM_PLUGIN_DIR . 'includes/trait-wpmtm-frontend-td-handler.php';
require_once WPMTM_PLUGIN_DIR . 'includes/class-wpmtm-frontend-td.php';
require_once WPMTM_PLUGIN_DIR . 'includes/class-wpmtm-frontend.php';
// GitHub release update notifications (this plugin is not on WordPress.org).
require_once WPMTM_PLUGIN_DIR . 'includes/class-wpmtm-updater.php';

register_activation_hook( __FILE__, array( 'WPMTM_Schema', 'activate' ) );

/**
 * Boot the plugin. WPMTM_Plugin holds shared option/helper access;
 * WPMTM_Settings, WPMTM_Admin, WPMTM_Admin_Sections, WPMTM_Admin_Players,
 * WPMTM_Admin_Import, WPMTM_Admin_Export, and WPMTM_Frontend register their
 * own admin_menu / admin_post / front-end hooks and are safe to instantiate
 * unconditionally - each degrades on its own if the current user lacks the
 * capability (WPMTM_Frontend simply omits the TD panel).
 *
 * WPMTM_Schema::maybe_upgrade() runs here too (not just on activation) so
 * existing installs pick up schema changes (e.g. wpmtm_sections.rated) on
 * the next request without requiring deactivate/reactivate.
 */
add_action( 'plugins_loaded', function () {
	WPMTM_Schema::maybe_upgrade();
	WPMTM_Updater::init();
	WPMTM_Plugin::instance();
	WPMTM_Settings::instance();
	WPMTM_Admin::instance();
	WPMTM_Admin_Sections::instance();
	WPMTM_Admin_Players::instance();
	WPMTM_Admin_Import::instance();
	WPMTM_Admin_Export::instance();
	WPMTM_Wizard::instance();
	WPMTM_USCF_Status::instance();
	WPMTM_Registration_Check::instance();
	WPMTM_Frontend::instance();
} );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once WPMTM_PLUGIN_DIR . 'includes/class-wpmtm-cli.php';
	WP_CLI::add_command( 'wpmtm validate', 'WPMTM_CLI' );
}
