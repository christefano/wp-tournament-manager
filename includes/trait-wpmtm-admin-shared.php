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
			wp_die( esc_html__( 'No permission to perform this action.', 'wp-tournament-manager' ) );
		}
	}

	/**
	 * The list of ids a repeater form flagged for server-side deletion, read
	 * out of its hidden comma-separated field (assets/wpmtm-admin.js writes
	 * it as rows are removed).
	 *
	 * Audit item 52: WPMTM_Admin_Sections::handle_save_sections() and
	 * WPMTM_Admin_Players::handle_save_players() are the same ~100-line shape
	 * end to end - nonce, capability, ownership, unslash the rows array,
	 * parse the removed list, per-row sanitize, insert-or-update keyed on
	 * ctype_digit(), count failures, cascade-delete, renumber, notice,
	 * redirect. Only this parse and the failure suffix below are identical
	 * line for line; the per-row bodies differ in real ways (round-robin
	 * auto-rounds on one side, rating provenance on the other), and the
	 * 2026-07-29 audit's item 25 is the standing lesson on what merging
	 * near-identical-but-not-identical blocks in this plugin costs. So the two
	 * genuinely shared pieces move here and the rest stays put, deliberately.
	 *
	 * @param string $field POST field name ('removed_sections'/'removed_players').
	 * @return int[] Positive ids, in posted order, blanks dropped.
	 */
	protected function parse_removed_ids( $field ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- every caller verifies its own nonce via check_admin_referer() before reaching this helper.
		if ( ! isset( $_POST[ $field ] ) ) {
			return array();
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see note above.
		$raw = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
		return array_values( array_filter( array_map( 'absint', explode( ',', $raw ) ) ) );
	}

	/**
	 * The " N row(s) could not be saved." tail both repeater handlers append
	 * when a write failed, or '' when none did. See parse_removed_ids() above
	 * for why only this much is shared.
	 *
	 * @param int $failed_rows
	 * @return string
	 */
	protected function failed_rows_notice( $failed_rows ) {
		$failed_rows = (int) $failed_rows;
		if ( $failed_rows < 1 ) {
			return '';
		}
		return sprintf(
			/* translators: %d: number of rows that could not be saved */
			__( '%d row(s) could not be saved.', 'wp-tournament-manager' ),
			$failed_rows
		);
	}

	/**
	 * @param bool $is_html Set true only when $message was built by the
	 *                      caller with its own trusted markup already
	 *                      escaped piece-by-piece (e.g. a section-names
	 *                      list wrapped in an <a> back to the sections
	 *                      editor) - render_notices() then uses
	 *                      wp_kses_post() instead of esc_html() so that
	 *                      markup survives. Every other caller passes
	 *                      plain text and leaves this false.
	 */
	protected function set_notice( $type, $message, $is_html = false ) {
		$key      = 'wpmtm_notice_' . get_current_user_id();
		$notices  = get_transient( $key );
		$notices  = is_array( $notices ) ? $notices : array();
		$notices[] = array( 'type' => $type, 'message' => $message, 'is_html' => $is_html );
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
			// An HTML message may legitimately carry block-level markup - the
			// round-entry validation error list wraps its items in a <ul>
			// (WPMTM_Frontend_TD::format_round_errors(), audit item 55) - and
			// a <ul> nested inside a <p> is invalid and gets hoisted out of
			// it by the parser. Plain-text messages keep the <p> wrapper that
			// gives a WordPress notice its normal spacing; HTML ones are
			// emitted as-is and are responsible for their own block element.
			if ( ! empty( $notice['is_html'] ) ) {
				printf(
					'<div class="notice notice-%1$s is-dismissible">%2$s</div>',
					esc_attr( $notice['type'] ),
					wp_kses_post( $notice['message'] ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- run through wp_kses_post() right here.
				);
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
