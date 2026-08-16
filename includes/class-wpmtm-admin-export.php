<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * USCF export admin surface (docs/SPEC.md, "Revised build order" item 6):
 * a readiness report (the pre-export validator run inline) plus the zip
 * download, both on the tournament edit screen. Split out of WPMTM_Admin
 * the same way WPMTM_Admin_Import is, with the same nonce/capability/
 * escaping discipline.
 *
 * The validator runs twice by design: once here (render_export_box()) so
 * the TD sees a readiness report before clicking anything, and again in
 * handle_export() before any file is generated, since the page state a
 * browser posts back can never be trusted over a fresh server-side check.
 */
class WPMTM_Admin_Export {

	use WPMTM_Admin_Shared;

	private static $instance = null;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_post_wpmtm_export_uscf', array( $this, 'handle_export' ) );
		add_action( 'admin_post_wpmtm_export_csv', array( $this, 'handle_export_csv' ) );
	}

	// -----------------------------------------------------------------
	// Export box (readiness report + download form) on the tournament
	// edit screen.
	// -----------------------------------------------------------------

	public function render_export_box( $tournament ) {
		$event_url = $tournament->event_post_id ? (string) get_permalink( (int) $tournament->event_post_id ) : '';

		// force=false: a page render must never trigger an outbound USCF API
		// call (docs/SPEC.md, 2026-07-16, "USCF API traffic reduction") -
		// see build_report()/add_uscf_status_findings(). build_csv_report()
		// never calls that method at all - see its own docblock for why.
		$csv_report   = self::build_csv_report( $tournament );
		$csv_findings = self::split_findings( $csv_report['findings'] );
		?>
		<div class="wrap wpmtm-wrap" id="wpmtm-export">
			<h2><?php esc_html_e( 'Tournament Export', 'wp-tournament-manager' ); ?></h2>

			<h3><?php esc_html_e( 'CSV Export', 'wp-tournament-manager' ); ?></h3>
			<p class="description">
				<?php esc_html_e( "The CSV export is a single CSV file containing this tournament's results from all rated and unrated sections that can be imported to other tournament software.", 'wp-tournament-manager' ); ?>
			</p>
			<?php $this->render_findings( $csv_findings, $event_url ); ?>

			<div class="wpmtm-export-buttons">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-wpmtm-guard data-wpmtm-reenable-on-focus>
					<?php wp_nonce_field( 'wpmtm_export_csv_' . $tournament->id, 'wpmtm_export_csv_nonce' ); ?>
					<input type="hidden" name="action" value="wpmtm_export_csv">
					<input type="hidden" name="tournament_id" value="<?php echo esc_attr( $tournament->id ); ?>">
					<?php
					$csv_button_attrs = array( 'data-wpmtm-busy-label' => esc_attr__( 'Preparing CSV export...', 'wp-tournament-manager' ) );
					if ( $csv_findings[0] ) {
						$csv_button_attrs['disabled'] = 'disabled';
					}
					// wrap=false (found while diagnosing an extra gap above this
					// button, 2026-07-24): submit_button()'s default wrapper is
					// <p class="submit">, which carries wp-admin core's own
					// ~25px top margin - redundant with, and much larger than,
					// .wpmtm-export-buttons' own 12px flex gap that already
					// spaces every child here consistently.
					submit_button(
						__( 'Download CSV export (.csv)', 'wp-tournament-manager' ),
						'primary',
						'submit',
						false,
						$csv_button_attrs
					);
					?>
					<?php if ( $csv_findings[0] ) : ?>
						<p class="description"><?php esc_html_e( 'Fix the errors above first. Warnings alone do not block the download.', 'wp-tournament-manager' ); ?></p>
					<?php endif; ?>
				</form>
			</div>

			<h3><?php esc_html_e( 'USCF Export', 'wp-tournament-manager' ); ?></h3>
			<?php if ( ! $tournament->rated ) : ?>
				<p class="description">
					<?php esc_html_e( 'This tournament is marked unrated, so there is nothing to submit to USCF. To enable USCF export, edit the tournament above and check "This tournament has USCF-rated sections".', 'wp-tournament-manager' ); ?>
				</p>
			<?php endif; ?>

			<?php
			$uscf_report   = $tournament->rated ? self::build_report( $tournament, false ) : null;
			$uscf_findings = $uscf_report ? self::split_findings( $uscf_report['findings'] ) : array( array(), array(), array() );
			if ( $tournament->rated ) :
				?>
				<p class="description">
					<?php esc_html_e( 'The USCF download is a zip holding three files (THEXPORT.DBF, TSEXPORT.DBF, and TDEXPORT.DBF) that can each be uploaded to ratings.uschess.org.', 'wp-tournament-manager' ); ?>
				</p>
				<?php $this->render_findings( $uscf_findings, $event_url ); ?>

				<div class="wpmtm-export-buttons">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-wpmtm-guard data-wpmtm-reenable-on-focus>
						<?php wp_nonce_field( 'wpmtm_export_uscf_' . $tournament->id, 'wpmtm_export_uscf_nonce' ); ?>
						<input type="hidden" name="action" value="wpmtm_export_uscf">
						<input type="hidden" name="tournament_id" value="<?php echo esc_attr( $tournament->id ); ?>">
						<?php
						$uscf_button_attrs = array( 'data-wpmtm-busy-label' => esc_attr__( 'Preparing USCF export...', 'wp-tournament-manager' ) );
						if ( $uscf_findings[0] ) {
							$uscf_button_attrs['disabled'] = 'disabled';
						}
						// wrap=false, same reasoning as the CSV button above.
						submit_button(
							__( 'Download USCF export (.zip)', 'wp-tournament-manager' ),
							'primary',
							'submit',
							false,
							$uscf_button_attrs
						);
						?>
						<?php if ( $uscf_findings[0] ) : ?>
							<p class="description"><?php esc_html_e( 'Fix the errors above first. Warnings alone do not block the download.', 'wp-tournament-manager' ); ?></p>
						<?php endif; ?>
					</form>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Splits a flat findings list (WPMTM_USCF_Validator::validate()'s
	 * return shape) into [errors, warnings, notices], the grouping both
	 * the CSV and USCF halves of render_export_box() need.
	 *
	 * @return array{0: array, 1: array, 2: array}
	 */
	protected static function split_findings( array $findings ) {
		$errors   = array();
		$warnings = array();
		$notices  = array();
		foreach ( $findings as $finding ) {
			if ( 'error' === $finding['level'] ) {
				$errors[] = $finding;
			} elseif ( 'notice' === $finding['level'] ) {
				$notices[] = $finding;
			} else {
				$warnings[] = $finding;
			}
		}
		return array( $errors, $warnings, $notices );
	}

	/**
	 * Renders the Errors/Warnings/Notices lists shared by both the CSV and
	 * USCF halves of render_export_box() - previously this markup only
	 * existed once (USCF only); factored out so adding the CSV export
	 * (2026-07-24) did not mean a second hand-kept copy of the same loop.
	 *
	 * @param array{0: array, 1: array, 2: array} $findings split_findings()'s return value.
	 * @param string                               $event_url Linked event permalink, for suggestion_for()'s deep links.
	 */
	protected function render_findings( array $findings, $event_url ) {
		list( $errors, $warnings, $notices ) = $findings;
		if ( empty( $errors ) && empty( $warnings ) && empty( $notices ) ) {
			?>
			<p><strong><?php esc_html_e( 'Ready to export. No issues found.', 'wp-tournament-manager' ); ?></strong></p>
			<?php
			return;
		}
		?>
		<?php if ( $errors ) : ?>
			<h4><?php esc_html_e( 'Errors', 'wp-tournament-manager' ); ?></h4>
			<ul class="wpmtm-etr-warnings wpmtm-export-errors">
				<?php foreach ( $errors as $finding ) : ?>
					<li>
						<?php echo esc_html( $this->format_finding( $finding ) ); ?>
						<br>
						<span class="description">
							<?php
							printf(
								/* translators: %s: suggested action to resolve this finding */
								esc_html__( 'Suggested action: %s', 'wp-tournament-manager' ),
								self::suggestion_for( $finding['code'], isset( $finding['member_id'] ) ? $finding['member_id'] : '', $event_url ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- suggestion_for() returns pre-escaped-safe HTML, see its own docblock.
							);
							?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php if ( $warnings ) : ?>
			<h4><?php esc_html_e( 'Warnings', 'wp-tournament-manager' ); ?></h4>
			<ul class="wpmtm-etr-warnings">
				<?php foreach ( $warnings as $finding ) : ?>
					<li>
						<?php echo esc_html( $this->format_finding( $finding ) ); ?>
						<br>
						<span class="description">
							<?php
							printf(
								/* translators: %s: suggested action to resolve this finding */
								esc_html__( 'Suggested action: %s', 'wp-tournament-manager' ),
								self::suggestion_for( $finding['code'], isset( $finding['member_id'] ) ? $finding['member_id'] : '', $event_url ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- suggestion_for() returns pre-escaped-safe HTML, see its own docblock.
							);
							?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php if ( $notices ) : ?>
			<h4><?php esc_html_e( 'Notices (does not block export)', 'wp-tournament-manager' ); ?></h4>
			<ul class="wpmtm-etr-warnings">
				<?php foreach ( $notices as $finding ) : ?>
					<li><?php echo esc_html( $this->format_finding( $finding ) ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php
	}

	/**
	 * Option key holding the Unix timestamp of the last successful USCF
	 * export for a tournament. Mirrors WPMTM_USCF_Status::record_td_check()'s
	 * `wpmtm_td_check_<id>` pattern rather than adding a schema column, so
	 * this needs no DB_VERSION bump. Autoload is off - only ever read on the
	 * tournament edit screen and by the setup guide. uninstall.php sweeps
	 * `wpmtm_exported_%` alongside `wpmtm_td_check_%`.
	 */
	const EXPORTED_OPTION_PREFIX = 'wpmtm_exported_';

	/**
	 * Records that a USCF export zip was successfully generated for this
	 * tournament (docs/SPEC.md, 2026-07-21, setup guide Export step). This
	 * is what lets the setup guide mark Export complete and advance to
	 * Finish, replacing the earlier state where Export could never become
	 * the current step because nothing downstream of it was observable.
	 *
	 * Deliberately NOT proof that USCF accepted the submission - TM cannot
	 * know that, since the upload happens by hand at ratings.uschess.org.
	 * It means only "this TD built a zip", which is exactly the milestone
	 * the guide needs.
	 *
	 * @param int $tournament_id
	 */
	public static function record_export( $tournament_id ) {
		update_option( self::EXPORTED_OPTION_PREFIX . (int) $tournament_id, current_time( 'timestamp' ), false ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- matches the current_time('timestamp') convention every other provenance/display reader in this codebase compares against, notably WPMTM_USCF_Status::record_td_check().
	}

	/**
	 * Whether a USCF export has ever been generated for this tournament.
	 *
	 * @param int $tournament_id
	 * @return bool
	 */
	public static function has_exported( $tournament_id ) {
		return (int) get_option( self::EXPORTED_OPTION_PREFIX . (int) $tournament_id, 0 ) > 0;
	}

	/**
	 * Plain-language finding text plus its section/player/round context
	 * (when present), e.g. "... (section 1, player 4, round 2)". Shared by
	 * the on-screen readiness report and the admin notice handle_export()
	 * sets on a blocked export.
	 */
	protected function format_finding( array $finding ) {
		$context = array();
		if ( null !== $finding['section'] ) {
			/* translators: %s: section number */
			$context[] = sprintf( __( 'section %s', 'wp-tournament-manager' ), $finding['section'] );
		}
		if ( null !== $finding['player'] ) {
			/* translators: %s: player pairing number */
			$context[] = sprintf( __( 'player %s', 'wp-tournament-manager' ), $finding['player'] );
		}
		if ( null !== $finding['round'] ) {
			/* translators: %s: round number */
			$context[] = sprintf( __( 'round %s', 'wp-tournament-manager' ), $finding['round'] );
		}

		$message = $finding['message'];
		if ( $context ) {
			$message .= ' (' . implode( ', ', $context ) . ')';
		}
		return $message;
	}

	/**
	 * Builds the "could not be created, fix these errors first" notice message
	 * from a readiness report's findings, shared by the USCF and CSV export
	 * handlers (which differ only in the intro sentence). Filters findings to
	 * error level, shows the first five formatted, and appends a "+ N more"
	 * tail. Returns '' when there are no error-level findings, so the caller
	 * treats a blank return as "nothing blocks the download".
	 *
	 * @param array  $findings Readiness report findings (level/message/section/...).
	 * @param string $intro    Already-translated lead sentence for this export type.
	 * @return string The notice message, or '' when no error blocks the export.
	 */
	protected function build_export_error_message( array $findings, $intro ) {
		$errors = array_values(
			array_filter(
				$findings,
				function ( $finding ) {
					return 'error' === $finding['level'];
				}
			)
		);

		if ( ! $errors ) {
			return '';
		}

		$shown    = array_slice( $errors, 0, 5 );
		$messages = array_map( array( $this, 'format_finding' ), $shown );
		$message  = $intro . ' ' . implode( ' ', $messages );
		$more     = count( $errors ) - count( $shown );
		if ( $more > 0 ) {
			/* translators: %d: number of additional errors not shown */
			$message .= ' ' . sprintf( __( '+ %d more error(s).', 'wp-tournament-manager' ), $more );
		}
		return $message;
	}

	/**
	 * Plain-language suggested action for a validator finding code (see
	 * WPMTM_USCF_Validator, not modified here), rendered as a muted line
	 * under each error/warning message in render_export_box() above. One
	 * entry per code the validator can emit; an unrecognized code (e.g. a
	 * future validator addition this method has not been updated for yet)
	 * falls back to a generic suggestion rather than showing nothing.
	 *
	 * @param string $code
	 * @param string $member_id Validated (digits-only) USCF member ID the
	 *                           finding is about, when known. Only used to
	 *                           build a link for 'player_membership_lapsed';
	 *                           every other code ignores it.
	 * @param string $event_url The linked event's permalink (no anchor), or
	 *                           '' if the tournament has none. Only used to
	 *                           build a link for 'section_no_results'; every
	 *                           other code ignores it.
	 * @return string Safe HTML - already escaped internally (either plain
	 *                 esc_html(), or a hand-built esc_url()/esc_html() link),
	 *                 same contract as WPMTM_Wizard::format_next(). Callers
	 *                 must echo this directly, not wrap it in esc_html()
	 *                 again.
	 */
	private static function suggestion_for( $code, $member_id = '', $event_url = '' ) {
		if ( 'player_membership_lapsed' === $code && '' !== $member_id ) {
			return sprintf(
				/* translators: %s: link text "Confirm this player's USCF membership", pointing at the player's USCF ratings profile */
				esc_html__( '%s, or wait for it to be renewed, before submitting.', 'wp-tournament-manager' ),
				'<a href="' . esc_url( 'https://ratings.uschess.org/player/' . $member_id ) . '">' . esc_html__( "Confirm this player's USCF membership", 'wp-tournament-manager' ) . '</a>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_url()/esc_html(), safe HTML substituted into the already-escaped %s placeholder above, same pattern as WPMTM_Wizard::format_next().
			);
		}

		if ( 'section_no_results' === $code && '' !== $event_url ) {
			return sprintf(
				/* translators: %s: link text "Enter rounds", pointing at the event page's Rounds tab */
				esc_html__( '%s for this section on the event page before exporting.', 'wp-tournament-manager' ),
				'<a href="' . esc_url( $event_url . '#tab-round-entry' ) . '">' . esc_html__( 'Enter rounds', 'wp-tournament-manager' ) . '</a>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_url()/esc_html(), safe HTML substituted into the already-escaped %s placeholder above, same pattern as the player_membership_lapsed case above.
			);
		}

		$suggestions = array(
			'affiliate_id_invalid'        => __( 'Enter the club USCF affiliate ID (A followed by 7 digits) in Tournament Manager Settings.', 'wp-tournament-manager' ),
			'chief_td_id_invalid'         => __( 'Enter the Chief TD USCF ID (8 digits) in Settings, or set a per-tournament Chief TD ID on this tournament.', 'wp-tournament-manager' ),
			'assistant_td_id_invalid'     => __( 'Enter a valid 8-digit Assistant TD ID in Settings or on the tournament, or clear the field.', 'wp-tournament-manager' ),
			'member_id_blank'             => __( 'Add this player\'s USCF ID in the players editor. For a brand-new member get it from the membership purchase or the uschess.org member lookup.', 'wp-tournament-manager' ),
			'rating_blank'                => __( 'Enter the player\'s USCF rating, or leave it blank only if the player is genuinely unrated.', 'wp-tournament-manager' ),
			'duplicate_member_id'         => __( 'Two players share a USCF ID. Fix the incorrect one in the players editor.', 'wp-tournament-manager' ),
			'duplicate_player_name'       => __( 'Two players share a name. Confirm they are different people or correct the duplicate.', 'wp-tournament-manager' ),
			'name_format'                 => __( 'Store the name as LAST,FIRST.', 'wp-tournament-manager' ),
			'non_ascii_field'             => __( 'Replace accented or non-English characters with plain ASCII. USCF accepts ASCII only.', 'wp-tournament-manager' ),
			'field_too_long'              => __( 'Shorten this value so it fits within the USCF export format\'s field width limit.', 'wp-tournament-manager' ),
			'name_truncated'              => __( 'Shorten this player\'s name, or it will be truncated to a 30-character version for USCF export.', 'wp-tournament-manager' ),
			'pair_num_duplicate'          => __( 'Two players share a pairing number in this section. Re-import or renumber.', 'wp-tournament-manager' ),
			'pair_num_noncontiguous'      => __( 'Pairing numbers must run 1 to N with no gaps. Re-import the section.', 'wp-tournament-manager' ),
			'rating_system_mismatch'      => __( 'The time control does not match the section rating system. Check the time control or the rating system.', 'wp-tournament-manager' ),
			'reciprocity_mismatch'        => __( 'Re-open this round on the event page and re-enter the board. The two players disagree on the result or color.', 'wp-tournament-manager' ),
			'reciprocity_asymmetric'      => __( 'Re-open this round on the event page and re-enter the board. The two players disagree on the result or color.', 'wp-tournament-manager' ),
			'reciprocity_bad_opponent'    => __( 'Re-open this round on the event page and re-enter the board. The two players disagree on the result or color.', 'wp-tournament-manager' ),
			'reciprocity_self_paired'     => __( 'Re-open this round on the event page and re-enter the board. The two players disagree on the result or color.', 'wp-tournament-manager' ),
			'color_mismatch'              => __( 'Re-open this round on the event page and re-enter the board. The two players disagree on the result or color.', 'wp-tournament-manager' ),
			'lst_pair_mismatch'           => __( 'The section\'s last pairing number does not match its player count. Re-import the section.', 'wp-tournament-manager' ),
			'round_count_mismatch'        => __( 'Enter this player\'s missing round result on the event page, or withdraw the player from that round on.', 'wp-tournament-manager' ),
			'section_no_results'          => __( 'Enter rounds for this section on the event page before exporting.', 'wp-tournament-manager' ),
			'round_token_invalid'         => __( 'A stored result is malformed. Re-enter that board.', 'wp-tournament-manager' ),
			'sec_num_duplicate'           => __( 'Section numbers must be unique and contiguous. This is normally automatic, re-import if it persists.', 'wp-tournament-manager' ),
			'sec_num_noncontiguous'       => __( 'Section numbers must be unique and contiguous. This is normally automatic, re-import if it persists.', 'wp-tournament-manager' ),
			'timectl_below_blitz_minimum' => __( 'The time control is below the blitz minimum. Confirm it is correct.', 'wp-tournament-manager' ),
			'timectl_unparseable'         => __( 'The time control could not be read. Use a standard form like G/30;d5.', 'wp-tournament-manager' ),
			'trn_type_unsupported'        => __( 'Use Swiss or Round Robin. This pairing type cannot be exported.', 'wp-tournament-manager' ),
			'date_format_invalid'         => __( 'Enter the tournament begin and end dates in YYYY-MM-DD form on the Edit Tournament page.', 'wp-tournament-manager' ),
			'date_range_invalid'          => __( 'The end date is before the begin date. Correct the dates on the Edit Tournament page.', 'wp-tournament-manager' ),
			'r_system_invalid'            => __( 'The section rating system is not one of R, Q, or B. Set a valid time control so the system can be derived.', 'wp-tournament-manager' ),
			'player_membership_lapsed'    => __( 'Confirm this player\'s USCF membership at uschess.org/msa, or wait for it to be renewed, before submitting.', 'wp-tournament-manager' ),
			'td_membership_lapsed'        => __( 'This TD\'s USCF membership and/or Safe Play certification must be current through the tournament\'s last day before USCF will accept this submission. Renew at uschess.org, or set a different Chief/Assistant TD.', 'wp-tournament-manager' ),
			'td_status_unknown'           => __( 'The USCF ratings API could not be reached to verify this TD. Try the export again shortly.', 'wp-tournament-manager' ),
		);

		return isset( $suggestions[ $code ] ) ? esc_html( $suggestions[ $code ] ) : esc_html__( 'Review this item before exporting.', 'wp-tournament-manager' );
	}

	/**
	 * Builds the structured export payload and runs the pre-export
	 * validator against it. Shared by render_export_box() (readiness
	 * report) and handle_export() (server-side gate before generating
	 * files) so both always see the same data and the same checks.
	 *
	 * @param object $tournament WPMTM_Repository::get_tournament()'s row.
	 * @param bool   $force      Passed straight through to
	 *                            add_uscf_status_findings() (docs/SPEC.md,
	 *                            2026-07-16): false for a page render (cache
	 *                            only, zero outbound USCF API calls), true
	 *                            for the actual export download (fresh TD
	 *                            and player checks, since this is the one
	 *                            thing that blocks the download).
	 * @return array{data:array,findings:array[]}
	 */
	protected static function build_report( $tournament, $force = false ) {
		$bundle  = WPMTM_Repository::get_export_bundle( $tournament->id );
		$options = WPMTM_Plugin::instance()->get_opts();

		$tournament_arr = $bundle ? $bundle['tournament'] : array();
		$sections_arr   = $bundle ? $bundle['sections'] : array();

		$data = WPMTM_Export_Builder::build( $tournament_arr, $options, $sections_arr );

		// docs/SPEC.md (2026-07-16, timezone fix): stamp the DBF header's
		// update_date with the club's LOCAL date via current_time(), not
		// the pure WPMTM_USCF_Export/WPMTM_DBF_Writer classes' own date()
		// fallback. WordPress forces PHP's timezone to UTC, so that
		// fallback stamps the UTC date - a club exporting in the evening
		// west of UTC would otherwise write tomorrow's date into the DBF.
		$data['update_date'] = array(
			'year'  => (int) current_time( 'Y' ),
			'month' => (int) current_time( 'n' ),
			'day'   => (int) current_time( 'j' ),
		);

		$validator = new WPMTM_USCF_Validator( $data, true );
		$findings  = $validator->validate();

		self::add_uscf_status_findings( $data, $tournament, $findings, $force );

		return array(
			'data'     => $data,
			'findings' => $findings,
		);
	}

	/**
	 * CSV export's version of build_report() above (docs/SPEC.md,
	 * 2026-07-24, "Tournament Export" CSV export). Two deliberate
	 * differences from build_report(): WPMTM_Export_Builder::build_for_csv()
	 * includes every section, rated and unrated alike (build() drops
	 * unrated sections entirely - fine for a USCF submission, wrong for a
	 * TD's own results dump); and the validator runs with $rated=false,
	 * which is exactly the structural-check set a CSV needs - round-token
	 * decoding, reciprocity/color, section-empty, contiguous pairing,
	 * opponent presence, round counts, duplicate members/names, ASCII/name
	 * format, DBF field widths (owner decision, 2026-07-24: kept even
	 * though a CSV has no field-width limit of its own, since an over-long
	 * value is still worth flagging before a TD hands the file to other
	 * software), section numbers, and dates - while skipping the
	 * $rated-gated block (affiliate_id, chief/assistant TD id format,
	 * rating-system-vs-timectl, blank member/rating, r_system/trn_type
	 * validity), all of which only mean anything for a USCF submission.
	 * add_uscf_status_findings() (live USCF ratings-API TD/player checks)
	 * is never called here at all, for the same reason and to keep a CSV
	 * page render at zero outbound API calls unconditionally, not just on a
	 * cached page load.
	 *
	 * @param object $tournament WPMTM_Repository::get_tournament()'s row.
	 * @return array{data:array,findings:array[]}
	 */
	protected static function build_csv_report( $tournament ) {
		$bundle  = WPMTM_Repository::get_export_bundle( $tournament->id );
		$options = WPMTM_Plugin::instance()->get_opts();

		$tournament_arr = $bundle ? $bundle['tournament'] : array();
		$sections_arr   = $bundle ? $bundle['sections'] : array();

		$data = WPMTM_Export_Builder::build_for_csv( $tournament_arr, $options, $sections_arr );

		$validator = new WPMTM_USCF_Validator( $data, false );

		return array(
			'data'     => $data,
			'findings' => $validator->validate(),
		);
	}

	/**
	 * Appends the USCF-ratings-API-backed findings (docs/SPEC.md v1.2
	 * section 3, revised 2026-07-16) to the structural findings
	 * WPMTM_USCF_Validator::validate() already produced: a warning per
	 * rated player whose membership is not active through the tournament's
	 * last day, and a blocking error (or a non-blocking notice on UNKNOWN)
	 * per chief/assistant TD whose membership or Safe Play certification is
	 * not current. $data's chief_td_id / assistant_td_id are already the
	 * effective IDs (tournament override else Settings default -
	 * WPMTM_Export_Builder::build()), so this reuses exactly the IDs
	 * check_td_ids() already validated for format.
	 *
	 * $force controls both how fresh the answer is AND whether a cache
	 * miss is even allowed to make an API call (docs/SPEC.md, "Decisions
	 * (2026-07-16, USCF API traffic reduction)"):
	 * - $force = true (handle_export(), the actual download gate): every
	 *   TD and player check re-fetches fresh, bypassing the cache
	 *   entirely. This is the one thing that blocks the download, so a
	 *   cached stale result must never strand a since-fixed TD, wave
	 *   through a since-lapsed one, or approve a player who has since
	 *   lost membership. Human-initiated and rare (one click per export),
	 *   not a load concern.
	 * - $force = false (render_export_box(), a plain page render): every
	 *   check is cache-only - a cache hit is used, a cache miss produces
	 *   a "not checked yet" UNKNOWN finding instead of calling the API.
	 *   This page renders on every tournament edit page load, so it must
	 *   cause zero outbound USCF API requests; the previous behavior
	 *   (fresh TD fetches on every render, cache-miss player fetches on
	 *   every render) was the actual API-overload bug this revision fixes.
	 *
	 * @param array  $data       WPMTM_Export_Builder::build()'s payload.
	 * @param object $tournament WPMTM_Repository::get_tournament()'s row.
	 * @param array  &$findings  Findings array to append to, by reference.
	 * @param bool   $force      True at export time (fresh, bypass cache);
	 *                            false at render time (cache-only, never
	 *                            fetches).
	 */
	protected static function add_uscf_status_findings( array $data, $tournament, array &$findings, $force = false ) {
		$status     = WPMTM_USCF_Status::instance();
		$cache_only = ! $force;
		$through    = WPMTM_USCF_Status::resolve_through_date( isset( $tournament->end_date ) ? (string) $tournament->end_date : '', '', '' );

		$chief = isset( $data['chief_td_id'] ) ? trim( (string) $data['chief_td_id'] ) : '';
		if ( preg_match( '/^\d{8}$/', $chief ) ) {
			$verdict = $status->validate_td( $chief, $through, $force, $cache_only );
			$finding = WPMTM_USCF_Validator::classify_export_td_verdict( $verdict, __( 'Chief TD', 'wp-tournament-manager' ) );
			if ( $finding ) {
				$findings[] = $finding;
			}
		}

		$assistant = isset( $data['assistant_td_id'] ) ? trim( (string) $data['assistant_td_id'] ) : '';
		if ( preg_match( '/^\d{8}$/', $assistant ) ) {
			$verdict = $status->validate_td( $assistant, $through, $force, $cache_only );
			$finding = WPMTM_USCF_Validator::classify_export_td_verdict( $verdict, __( 'Assistant TD', 'wp-tournament-manager' ) );
			if ( $finding ) {
				$findings[] = $finding;
			}
		}

		foreach ( isset( $data['sections'] ) ? $data['sections'] : array() as $section ) {
			$sec_num = isset( $section['sec_num'] ) ? $section['sec_num'] : null;
			foreach ( isset( $section['players'] ) ? $section['players'] : array() as $player ) {
				$mem_id = isset( $player['mem_id'] ) ? trim( (string) $player['mem_id'] ) : '';
				if ( '' === $mem_id || '' === WPMTM_USCF_Status::sanitize_member_id( $mem_id ) ) {
					continue; // check_blank_member_and_rating() already reports a blank/junk ID.
				}
				$pair_num = isset( $player['pair_num'] ) ? $player['pair_num'] : null;
				$name     = isset( $player['name'] ) ? $player['name'] : '';
				$verdict  = $status->validate_member( $mem_id, $through, $force, $cache_only );
				$finding  = WPMTM_USCF_Validator::classify_export_player_verdict( $verdict, $sec_num, $pair_num, $name );
				if ( $finding ) {
					$findings[] = $finding;
				}
			}
		}
	}

	// -----------------------------------------------------------------
	// Download handler.
	// -----------------------------------------------------------------

	public function handle_export() {
		$tournament_id = isset( $_POST['tournament_id'] ) ? absint( $_POST['tournament_id'] ) : 0;
		check_admin_referer( 'wpmtm_export_uscf_' . $tournament_id, 'wpmtm_export_uscf_nonce' );
		$this->require_capability();

		$tournament = WPMTM_Repository::get_tournament( $tournament_id );
		if ( ! $tournament ) {
			wp_die( esc_html__( 'Tournament not found.', 'wp-tournament-manager' ) );
		}
		if ( ! WPMTM_Roles::user_can_manage_tournament( $tournament ) ) {
			wp_die( esc_html__( 'No permission to edit this tournament.', 'wp-tournament-manager' ) );
		}

		$redirect_back = add_query_arg( array( 'page' => 'wpmtm-edit', 'id' => $tournament_id ), admin_url( 'admin.php' ) );

		if ( ! $tournament->rated ) {
			$this->set_notice( 'error', __( 'This tournament is marked unrated. There is nothing to export to USCF.', 'wp-tournament-manager' ) );
			wp_safe_redirect( $redirect_back );
			exit;
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->set_notice( 'error', __( 'The USCF export could not be created because this server\'s PHP does not have the php-zip extension enabled. Ask the host to enable php-zip, or export from a different server.', 'wp-tournament-manager' ) );
			wp_safe_redirect( $redirect_back );
			exit;
		}

		// Never trust the page state the browser posted back from - re-run
		// the same bundle + build + validate the readiness report used.
		// force=true: this is the actual download gate, so the TD and
		// player USCF checks re-fetch fresh rather than trusting a cache
		// that can now be up to a day old (docs/SPEC.md, 2026-07-16).
		$report = self::build_report( $tournament, true );
		$error_message = $this->build_export_error_message(
			$report['findings'],
			__( 'The USCF export could not be created. Fix these errors first:', 'wp-tournament-manager' )
		);
		if ( '' !== $error_message ) {
			$this->set_notice( 'error', $error_message );
			wp_safe_redirect( $redirect_back );
			exit;
		}

		$zip_path = null;

		try {
			$export = new WPMTM_USCF_Export( $report['data'] );
			$files  = $export->export_all();

			$zip_path = wp_tempnam( 'wpmtm-uscf-export' );
			if ( ! $zip_path ) {
				throw new RuntimeException( 'could not allocate a temp file for the export zip' );
			}

			$zip = new ZipArchive();
			if ( true !== $zip->open( $zip_path, ZipArchive::OVERWRITE ) ) {
				throw new RuntimeException( 'could not open the temp file as a zip archive' );
			}
			foreach ( $files as $name => $bytes ) {
				$zip->addFromString( $name . '.DBF', $bytes );
			}
			$zip->close();

			$zip_filename = sanitize_title( $tournament->name ) . '-uscf-' . str_replace( '-', '', (string) $tournament->begin_date ) . '.zip';

			// Record the export BEFORE streaming (2026-07-21, setup guide
			// Export step): once readfile() runs we exit, so anything after
			// it would never execute. Everything that could fail has already
			// happened by this point - the report validated, the files built,
			// and the zip closed on disk - so recording here cannot claim an
			// export that did not get produced. The setup guide reads this to
			// mark its Export step complete and move on to Finish.
			self::record_export( $tournament_id );

			nocache_headers();
			header( 'Content-Type: application/zip' );
			header( 'Content-Disposition: attachment; filename="' . $zip_filename . '"' );
			header( 'Content-Length: ' . filesize( $zip_path ) );

			readfile( $zip_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a just-built local temp file to the browser as a download, not fetching a remote URL; WP_Filesystem has no streaming-download equivalent.

			unlink( $zip_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing our own just-streamed temp file, not user-supplied; WP_Filesystem offers no benefit here.
			exit;
		} catch ( Throwable $e ) {
			if ( $zip_path && file_exists( $zip_path ) ) {
				unlink( $zip_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- cleanup of our own temp file on the error path, not user-supplied.
			}
			$this->set_notice( 'error', __( 'The USCF export could not be created due to an unexpected error. Please try again. Contact the plugin maintainer if it keeps happening.', 'wp-tournament-manager' ) );
			wp_safe_redirect( $redirect_back );
			exit;
		}
	}

	/**
	 * CSV download handler - handle_export()'s counterpart, but much
	 * simpler: a single in-memory string (WPMTM_CSV_Export::build(), no
	 * ZipArchive/temp file), and no rated gate, since the CSV export exists
	 * specifically so an unrated tournament has a results download too.
	 * Re-runs build_csv_report() itself rather than trusting anything the
	 * browser posted back, same reasoning as handle_export().
	 */
	public function handle_export_csv() {
		$tournament_id = isset( $_POST['tournament_id'] ) ? absint( $_POST['tournament_id'] ) : 0;
		check_admin_referer( 'wpmtm_export_csv_' . $tournament_id, 'wpmtm_export_csv_nonce' );
		$this->require_capability();

		$tournament = WPMTM_Repository::get_tournament( $tournament_id );
		if ( ! $tournament ) {
			wp_die( esc_html__( 'Tournament not found.', 'wp-tournament-manager' ) );
		}
		if ( ! WPMTM_Roles::user_can_manage_tournament( $tournament ) ) {
			wp_die( esc_html__( 'No permission to edit this tournament.', 'wp-tournament-manager' ) );
		}

		$redirect_back = add_query_arg( array( 'page' => 'wpmtm-edit', 'id' => $tournament_id ), admin_url( 'admin.php' ) );

		$report = self::build_csv_report( $tournament );
		$error_message = $this->build_export_error_message(
			$report['findings'],
			__( 'The CSV export could not be created. Fix these errors first:', 'wp-tournament-manager' )
		);
		if ( '' !== $error_message ) {
			$this->set_notice( 'error', $error_message );
			wp_safe_redirect( $redirect_back );
			exit;
		}

		$csv      = WPMTM_CSV_Export::build( $report['data'] );
		$filename = sanitize_title( $tournament->name ) . '-' . str_replace( '-', '', (string) $tournament->begin_date ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $csv ) );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- streaming a generated CSV file download, not HTML output; WPMTM_CSV_Export::build() already fputcsv()-escapes every field.
		exit;
	}
}
