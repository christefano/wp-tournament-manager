<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Accessor for the test player pool in includes/wpmtm-test-players.php.
 *
 * Exists so callers, including wp-etr's "Add test registrants" tool, have a
 * stable public entry point rather than each one `include`-ing a file path
 * that could move. wp-etr checks `class_exists( 'WPMTM_Test_Players' )` and
 * falls back to its own behavior when Tournament Manager is not active, so
 * this is the seam between the two plugins.
 *
 * See the data file's own docblock for where the players came from, how they
 * were verified, and the rule against ever adding an unverified id.
 */
class WPMTM_Test_Players {

	/**
	 * Cached pool, so repeated calls in one request do not re-read the file.
	 *
	 * @var array<int, array{last:string,first:string,rating:int,uscf_id:string}>|null
	 */
	private static $players = null;

	/**
	 * Every player in the pool, in the file's own order (highest rated
	 * first, as the source endpoint returned them).
	 *
	 * @return array<int, array{last:string,first:string,rating:int,uscf_id:string}>
	 */
	public static function all() {
		if ( null === self::$players ) {
			$file          = WPMTM_PLUGIN_DIR . 'includes/wpmtm-test-players.php';
			$loaded        = is_readable( $file ) ? include $file : array();
			self::$players = is_array( $loaded ) ? $loaded : array();
		}
		return self::$players;
	}

	/** How many players the pool holds. */
	public static function count() {
		return count( self::all() );
	}
}
