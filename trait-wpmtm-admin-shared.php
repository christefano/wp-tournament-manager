<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capability gate, the one-shot admin notice pipeline (transient-backed,
 * survives the redirect after a POST handler finishes), and the shared
 * plugin header block, used by WPMTM_Admin, WPMTM_Admin_Import, and
 * WPMTM_Settings so none of them keeps its own copy.
 *
 * The per-user transient holds a LIST of notices rather than a single one,
 * so two notices set in the same request/redirect cycle (e.g. a warning
 * from one handler followed by a success from another) both survive to be
 * rendered, instead of the second silently overwriting the first. This is
 * a simple read-modify-write with no locking; on a genuine race between two
 * concurrent requests for the same user, last writer wins, which is an
 * acceptable trade-off for a low-traffic admin notice.
 */
trait WPMTM_Admin_Shared {

	protected function require_capability() {
		if ( ! current_user_can( WPMTM_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'wp-tournament-manager' ) );
		}
	}

	protected function set_notice( $type, $message ) {
		$key      = 'wpmtm_notice_' . get_current_user_id();
		$notices  = get_transient( $key );
		$notices  = is_array( $notices ) ? $notices : array();
		$notices[] = array( 'type' => $type, 'message' => $message );
		set_transient( $key, $notices, 60 );
	}

	protected function render_notices() {
		$key     = 'wpmtm_notice_' . get_current_user_id();
		$notices = get_transient( $key );
		if ( ! $notices || ! is_array( $notices ) ) {
			return;
		}
		delete_transient( $key );
		foreach ( $notices as $notice ) {
			if ( ! is_array( $notice ) || ! isset( $notice['type'], $notice['message'] ) ) {
				continue;
			}
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $notice['type'] ),
				esc_html( $notice['message'] )
			);
		}
	}

	/**
	 * Shared plugin header shown at the top of every Tournament Manager admin
	 * page (Tournaments list, Add/Edit Tournament, Settings), matching the
	 * pattern wp-etr's Settings::render_admin_header() uses: a name + version
	 * line with GitHub / README / Donate links, plus one description line.
	 */
	protected function render_admin_header() {
		?>
		<p class="description">
			<strong><?php esc_html_e( 'Tournament Manager', 'wp-tournament-manager' ); ?></strong> v<?php echo esc_html( WPMTM_VERSION ); ?>:
			<a href="https://github.com/christefano/wp-tournament-manager" target="_blank" rel="noopener"><?php esc_html_e( 'GitHub', 'wp-tournament-manager' ); ?></a>
			&nbsp;|&nbsp;
			<a href="https://github.com/christefano/wp-tournament-manager/blob/main/README.md" target="_blank" rel="noopener"><?php esc_html_e( 'README', 'wp-tournament-manager' ); ?></a>
			&nbsp;|&nbsp;
			<a href="https://macchess.org/donate" target="_blank" rel="noopener"><?php esc_html_e( 'Donate', 'wp-tournament-manager' ); ?></a>
		</p>
		<p class="description">
			<?php esc_html_e( 'Club-level USCF tournament management: roster import, manual pairing aid, round results, standings, and USCF DBF export.', 'wp-tournament-manager' ); ?>
		</p>
		<?php
	}
}
