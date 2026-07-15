<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralized page-cache flush for a tournament's linked TEC event page.
 *
 * Registrations, roster edits, and round results all change what the
 * public event page shows (docs/SPEC.md, "Results entry lives on the
 * event page itself") without ever editing the event post itself, so a
 * page-cache plugin has nothing telling it the page is stale - it never
 * invalidates the cached page on its own. Every plugin write path that
 * affects a tournament with a linked event must call
 * flush_event_page() so the public standings/roster a page cache is
 * still serving actually catch up.
 */
class WPMTM_Cache {

	/**
	 * Best-effort cache flush for one event post: WordPress's own post
	 * cache plus the major third-party page-cache plugins, each guarded
	 * by function_exists() (or, for LiteSpeed, a plain action fire that is
	 * a no-op when the plugin is absent) so this never fatals on a site
	 * running none, one, or several of them.
	 *
	 * @param int $event_post_id
	 */
	public static function flush_event_page( $event_post_id ) {
		$id = absint( $event_post_id );
		if ( ! $id ) {
			return;
		}

		try {
			clean_post_cache( $id );

			// W3 Total Cache.
			if ( function_exists( 'w3tc_flush_post' ) ) {
				w3tc_flush_post( $id );
			}

			// WP Super Cache.
			if ( function_exists( 'wp_cache_post_change' ) ) {
				wp_cache_post_change( $id );
			}

			// WP Rocket.
			if ( function_exists( 'rocket_clean_post' ) ) {
				rocket_clean_post( $id );
			}

			// LiteSpeed Cache: safe unconditionally, a no-op when LiteSpeed is not active.
			do_action( 'litespeed_purge_post', $id );

			// WP Fastest Cache.
			if ( function_exists( 'wpfc_clear_post_cache_by_id' ) ) {
				wpfc_clear_post_cache_by_id( $id );
			}
		} catch ( Throwable $e ) {
			// Best-effort cache flush only; a flush failure must not block
			// or roll back the write that already succeeded above.
		}
	}
}
