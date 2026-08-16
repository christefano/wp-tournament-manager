<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Update notifications for installs that got Tournament Manager from GitHub
 * rather than from the WordPress.org plugin directory, which this plugin is
 * not distributed through.
 *
 * Without this, a club has no way to learn a new version exists short of
 * someone telling them, and updating means downloading a zip and re-uploading
 * it by hand. With it, Tournament Manager appears in the normal Dashboard >
 * Updates list and updates with the same one click as any other plugin. The
 * club installs nothing extra: the library ships inside this plugin.
 *
 * Wraps the Plugin Update Checker library (YahnisElsts, MIT), vendored at
 * includes/vendor/plugin-update-checker. It polls the repository's GitHub
 * releases, compares the newest release against this install's header
 * Version, and feeds WordPress's own update machinery.
 *
 * Two behaviors of that library this relies on, both verified in its source
 * rather than assumed:
 *
 * 1. enableReleaseAssets() prefers a zip attached to the release but falls
 *    back to GitHub's auto-generated source archive when a release has no
 *    attached asset (Vcs\ReleaseAssetSupport, PREFER_RELEASE_ASSETS). So
 *    releases work whether or not a built zip is attached.
 * 2. The auto-generated archive unpacks to a folder named after the repo and
 *    commit, not the plugin slug, which would otherwise install the plugin
 *    into the wrong directory and silently deactivate it. The library renames
 *    it on the way in via upgrader_source_selection (UpdateChecker::
 *    fixDirectoryName), so that case is handled.
 *
 * VERSION SOURCE: WordPress compares the GitHub release against the Version
 * field in this plugin's header, NOT against CHANGELOG.md. CHANGELOG.md is
 * this project's source of version truth, so the header and WPMTM_VERSION
 * have to be updated to match it before a release is cut. Cutting a release
 * tagged higher than the header shipped inside it makes every site offer the
 * same update forever, because the installed header never catches up to the
 * tag. Bumping the header to match CHANGELOG.md is part of cutting a release.
 */
class WPMTM_Updater {

	/** Repository the update checker polls for releases. */
	const REPOSITORY_URL = 'https://github.com/christefano/wp-tournament-manager/';

	/**
	 * Wire up the update check. Safe to call unconditionally: it no-ops
	 * without the vendored library, on a site that has switched the check
	 * off, and outside the contexts where WordPress looks for updates at all.
	 */
	public static function init() {
		// A site can opt out entirely with
		// define( 'WPMTM_DISABLE_UPDATE_CHECK', true ); in wp-config.php.
		// Worth doing on a development clone, which would otherwise poll
		// GitHub and offer to overwrite the working copy with a release.
		if ( defined( 'WPMTM_DISABLE_UPDATE_CHECK' ) && WPMTM_DISABLE_UPDATE_CHECK ) {
			return;
		}

		// Only load the library where update checks actually happen. The
		// front end never consults the update transients, so a public event
		// page should not pay to include the library on every request.
		if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		$library = WPMTM_PLUGIN_DIR . 'includes/vendor/plugin-update-checker/plugin-update-checker.php';
		if ( ! file_exists( $library ) ) {
			return; // vendor directory stripped; run without update checks rather than fatal.
		}
		require_once $library;

		$factory = '\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory';
		if ( ! class_exists( $factory ) ) {
			return;
		}

		$checker = call_user_func(
			array( $factory, 'buildUpdateChecker' ),
			self::REPOSITORY_URL,
			WPMTM_PLUGIN_FILE,
			'wp-tournament-manager'
		);

		// Releases, not the default branch: a push to main should never
		// prompt a club to update mid-tournament. Only a cut release does.
		$api = $checker->getVcsApi();
		if ( $api ) {
			$api->enableReleaseAssets();
		}
	}
}
