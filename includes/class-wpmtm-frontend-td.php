<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The TD's round-entry panel (WPMTM_CAPABILITY only): pairing aid, round
 * selector, the round-entry form, and the save handler behind it. Split out
 * of what used to be a single WPMTM_Frontend god class, the same way the
 * admin side is split into WPMTM_Admin / WPMTM_Admin_Import /
 * WPMTM_Admin_Export.
 *
 * Registers its own admin-post hooks in its own constructor (the same
 * pattern WPMTM_Admin / WPMTM_Admin_Import / WPMTM_Admin_Export use), so it
 * only ever needs instantiating once - WPMTM_Frontend::instance() does that
 * as part of its own construction, the same way WPMTM_Admin's constructor
 * does not need to know about WPMTM_Admin_Import's hooks; they are wired up
 * as soon as that class's singleton is created.
 *
 * Uses WPMTM_Admin_Shared for set_notice() (the round-save outcome, shown
 * on the redirect back to the event page). require_capability() from that
 * trait is not used here - it wp_die()s on failure, which is correct for
 * an admin screen but wrong for render_td_block(), which is only ever
 * called after WPMTM_Frontend::build_block() has already checked
 * current_user_can( WPMTM_CAPABILITY ); handle_save_round() below re-checks
 * that capability itself before writing anything, since a render-time
 * check is not a substitute for authorizing the POST that actually saves.
 */
class WPMTM_Frontend_TD {

	use WPMTM_Admin_Shared;

	// Split across trait files (2026-07-29 segmentation) to keep this file
	// small; each is composed in verbatim, so every method keeps its
	// visibility and $this semantics and every call site is unchanged:
	//   - WPMTM_Frontend_TD_Panel:     section tabs, sub-panel, round selector.
	//   - WPMTM_Frontend_TD_RoundForm: pairing aid, entry form, byes area.
	//   - WPMTM_Frontend_TD_Handler:   handle_save_round and its return URL.
	use WPMTM_Frontend_TD_Panel;
	use WPMTM_Frontend_TD_RoundForm;
	use WPMTM_Frontend_TD_Handler;

	private static $instance = null;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_post_wpmtm_save_round', array( $this, 'handle_save_round' ) );
		add_action( 'admin_post_nopriv_wpmtm_save_round', array( $this, 'handle_save_round_nopriv' ) );
	}

	// -----------------------------------------------------------------
	// TD panel (WPMTM_CAPABILITY only).
	// -----------------------------------------------------------------

	public function render_td_block( $tournament ) {
		$sections = WPMTM_Repository::get_sections( $tournament->id );
		if ( empty( $sections ) ) {
			return;
		}

		// assets/wpmtm-frontend.css/.js are shared with the public standings
		// + wall chart, so this is WPMTM_Frontend_Public's own method now
		// (see that method's docblock) - WPMTM_Frontend::build_block() /
		// filter_etr_event_tabs() also call it unconditionally (every
		// visitor, not just a WPMTM_CAPABILITY user), and this call here is
		// a harmless duplicate on the paths where both run in one request.
		WPMTM_Frontend_Public::instance()->enqueue_frontend_assets();

		// Multi-section sub-tabs (docs/SPEC.md, 2026-07-18): a TD always
		// works one section at a time, so 2+ sections get a client-side
		// tab strip over the same server-rendered panels instead of every
		// section's full stack piling up vertically on a phone. A single
		// section is the persona's normal event and must render exactly as
		// before - no tablist, no wrapper, no extra attributes - so the
		// tablist and the per-panel wrapper below are both gated on this.
		$multi_section = count( $sections ) > 1;
		$section_slugs = $multi_section ? $this->build_section_slugs( $sections ) : array();

		// Pairing and results entry are separate jobs with separate forms - see
		// rounds_mode()'s docblock. Resolved once here and handed to every
		// section panel, so a multi-section event is paired in one pass rather
		// than section by section.
		$mode = $this->rounds_mode( $tournament );
		?>
		<div class="wpmtm-td-panel">
			<?php
			// 2026-07-21: Round entry previously had no TD command row at
			// all (a pre-existing gap - Standings/Wall chart both had it).
			// Same instance-sharing pattern this file already uses for
			// enqueue_frontend_assets()/section_data_arrays() above. The
			// Pair/Results mode switch (2026-08-14) rides in as this row's
			// leading content rather than its own band - see
			// render_td_command_row()'s and rounds_mode_toggle_html()'s
			// docblocks for why.
			WPMTM_Frontend_Public::instance()->render_td_command_row( $tournament, '', $this->rounds_mode_toggle_html( $mode ) );
			?>
			<?php if ( (bool) $tournament->locked ) : ?>
				<div class="wpmtm-locked-banner">
					<p><strong><?php esc_html_e( 'This tournament is complete and locked.', 'wp-tournament-manager' ); ?></strong></p>
					<p><?php esc_html_e( 'Unlock the tournament from its admin page to make changes.', 'wp-tournament-manager' ); ?></p>
					<?php if ( (bool) $tournament->rated ) : ?>
						<?php
						// 2026-07-21: re-download link for a TD who already
						// submitted this export and wants the same zip again
						// (e.g. they lost the file) - the exact same
						// admin_post_wpmtm_export_uscf handler and nonce the
						// Export box's own button uses
						// (WPMTM_Admin_Export::render_export_box()), just a
						// second entry point to it. No help text, no
						// readiness/error checks replicated here (owner
						// decision, 2026-07-21) - a locked tournament's data
						// is frozen, so if it exported once it still will.
						?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpmtm-locked-banner-export">
							<?php wp_nonce_field( 'wpmtm_export_uscf_' . $tournament->id, 'wpmtm_export_uscf_nonce' ); ?>
							<input type="hidden" name="action" value="wpmtm_export_uscf">
							<input type="hidden" name="tournament_id" value="<?php echo esc_attr( $tournament->id ); ?>">
							<button type="submit" class="wpmtm-locked-banner-export-btn"><?php esc_html_e( 'Download USCF export', 'wp-tournament-manager' ); ?></button>
						</form>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<?php if ( $multi_section ) : ?>
				<div class="wpmtm-section-tablist-band">
					<span class="wpmtm-section-tablist-label"><?php esc_html_e( 'Section:', 'wp-tournament-manager' ); ?></span>
					<?php $this->render_section_tablist( $tournament, $sections, $section_slugs ); ?>
				</div>
			<?php endif; ?>
			<?php foreach ( $sections as $index => $section ) : ?>
				<?php if ( $multi_section ) : ?>
					<?php
					$section_id = (int) $section->id;
					$slug       = $section_slugs[ $section_id ];
					?>
					<div
						id="wpmtm-section-panel-<?php echo esc_attr( $section_id ); ?>"
						class="wpmtm-section-panel"
						data-wpmtm-section-panel="<?php echo esc_attr( $slug ); ?>"
						aria-labelledby="wpmtm-section-tab-<?php echo esc_attr( $section_id ); ?>"
					>
						<?php $this->render_section_td_panel( $tournament, $section, $slug, $mode ); ?>
					</div>
				<?php else : ?>
					<?php $this->render_section_td_panel( $tournament, $section, '', $mode ); ?>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
		<?php
	}
}
