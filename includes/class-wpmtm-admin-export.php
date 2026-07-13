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
	}

	// -----------------------------------------------------------------
	// Export box (readiness report + download form) on the tournament
	// edit screen.
	// -----------------------------------------------------------------

	public function render_export_box( $tournament ) {
		?>
		<div class="wrap wpmtm-wrap" id="wpmtm-export">
			<h2><?php esc_html_e( 'USCF Export', 'wp-tournament-manager' ); ?></h2>
			<?php if ( ! $tournament->rated ) : ?>
				<p class="description">
					<?php esc_html_e( 'This tournament is marked unrated, so there is nothing to submit to US Chess. To enable USCF export, edit the tournament above and check "This is a USCF rated tournament".', 'wp-tournament-manager' ); ?>
				</p>
			<?php else : ?>
				<?php
				$report   = self::build_report( $tournament );
				$errors   = array();
				$warnings = array();
				foreach ( $report['findings'] as $finding ) {
					if ( 'error' === $finding['level'] ) {
						$errors[] = $finding;
					} else {
						$warnings[] = $finding;
					}
				}
				?>
				<p class="description">
					<?php esc_html_e( 'The download is a zip holding three files - THEXPORT.DBF, TSEXPORT.DBF, and TDEXPORT.DBF - the format US Chess\'s rating system expects. US Chess offers no submission API: after downloading, you upload the zip yourself at ratings.uschess.org (the TD/Affiliate area).', 'wp-tournament-manager' ); ?>
				</p>

				<?php if ( empty( $errors ) && empty( $warnings ) ) : ?>
					<p><strong><?php esc_html_e( 'Ready to export - no issues found.', 'wp-tournament-manager' ); ?></strong></p>
				<?php else : ?>
					<?php if ( $errors ) : ?>
						<h3><?php esc_html_e( 'Errors (must fix before exporting)', 'wp-tournament-manager' ); ?></h3>
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
											esc_html( self::suggestion_for( $finding['code'] ) )
										);
										?>
									</span>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
					<?php if ( $warnings ) : ?>
						<h3><?php esc_html_e( 'Warnings (does not block export)', 'wp-tournament-manager' ); ?></h3>
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
											esc_html( self::suggestion_for( $finding['code'] ) )
										);
										?>
									</span>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-wpmtm-guard>
					<?php wp_nonce_field( 'wpmtm_export_uscf_' . $tournament->id, 'wpmtm_export_uscf_nonce' ); ?>
					<input type="hidden" name="action" value="wpmtm_export_uscf">
					<input type="hidden" name="tournament_id" value="<?php echo esc_attr( $tournament->id ); ?>">
					<?php
					$button_attrs = array( 'data-wpmtm-busy-label' => esc_attr__( 'Preparing...', 'wp-tournament-manager' ) );
					if ( $errors ) {
						$button_attrs['disabled'] = 'disabled';
					}
					submit_button(
						__( 'Download USCF export (.zip)', 'wp-tournament-manager' ),
						'primary',
						'submit',
						true,
						$button_attrs
					);
					?>
					<?php if ( $errors ) : ?>
						<p class="description"><?php esc_html_e( 'Fix the errors above first; warnings alone do not block the download.', 'wp-tournament-manager' ); ?></p>
					<?php endif; ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
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
	 * Plain-language suggested action for a validator finding code (see
	 * WPMTM_USCF_Validator, not modified here), rendered as a muted line
	 * under each error/warning message in render_export_box() above. One
	 * entry per code the validator can emit; an unrecognized code (e.g. a
	 * future validator addition this method has not been updated for yet)
	 * falls back to a generic suggestion rather than showing nothing.
	 *
	 * @param string $code
	 * @return string
	 */
	private static function suggestion_for( $code ) {
		$suggestions = array(
			'affiliate_id_invalid'        => __( 'Enter the club USCF affiliate ID (A followed by 7 digits) in Tournament Manager Settings.', 'wp-tournament-manager' ),
			'chief_td_id_invalid'         => __( 'Enter the Chief TD USCF ID (8 digits) in Settings, or set a per-tournament Chief TD ID on this tournament.', 'wp-tournament-manager' ),
			'assistant_td_id_invalid'     => __( 'Enter a valid 8-digit Assistant TD ID in Settings or on the tournament, or clear the field.', 'wp-tournament-manager' ),
			'member_id_blank'             => __( 'Add this player\'s USCF ID in the players editor; for a brand-new member get it from the membership purchase or the uschess.org member lookup.', 'wp-tournament-manager' ),
			'rating_blank'                => __( 'Enter the player\'s USCF rating, or leave it blank only if the player is genuinely unrated.', 'wp-tournament-manager' ),
			'duplicate_member_id'         => __( 'Two players share a USCF ID; fix the incorrect one in the players editor.', 'wp-tournament-manager' ),
			'duplicate_player_name'       => __( 'Two players share a name; confirm they are different people or correct the duplicate.', 'wp-tournament-manager' ),
			'name_format'                 => __( 'Store the name as LAST,FIRST.', 'wp-tournament-manager' ),
			'non_ascii_field'             => __( 'Replace accented or non-English characters with plain ASCII; US Chess accepts ASCII only.', 'wp-tournament-manager' ),
			'pair_num_duplicate'          => __( 'Two players share a pairing number in this section; re-import or renumber.', 'wp-tournament-manager' ),
			'pair_num_noncontiguous'      => __( 'Pairing numbers must run 1 to N with no gaps; re-import the section.', 'wp-tournament-manager' ),
			'rating_system_mismatch'      => __( 'The time control does not match the section rating system; check the time control or the rating system.', 'wp-tournament-manager' ),
			'reciprocity_mismatch'        => __( 'Re-open this round on the event page and re-enter the board; the two players disagree on the result or color.', 'wp-tournament-manager' ),
			'reciprocity_asymmetric'      => __( 'Re-open this round on the event page and re-enter the board; the two players disagree on the result or color.', 'wp-tournament-manager' ),
			'reciprocity_bad_opponent'    => __( 'Re-open this round on the event page and re-enter the board; the two players disagree on the result or color.', 'wp-tournament-manager' ),
			'reciprocity_self_paired'     => __( 'Re-open this round on the event page and re-enter the board; the two players disagree on the result or color.', 'wp-tournament-manager' ),
			'color_mismatch'              => __( 'Re-open this round on the event page and re-enter the board; the two players disagree on the result or color.', 'wp-tournament-manager' ),
			'lst_pair_mismatch'           => __( 'The section\'s last pairing number does not match its player count; re-import the section.', 'wp-tournament-manager' ),
			'round_count_mismatch'        => __( 'Enter this player\'s missing round result on the event page, or withdraw the player from that round on.', 'wp-tournament-manager' ),
			'section_no_results'          => __( 'Enter rounds for this section on the event page before exporting.', 'wp-tournament-manager' ),
			'round_token_invalid'         => __( 'A stored result is malformed; re-enter that board.', 'wp-tournament-manager' ),
			'sec_num_duplicate'           => __( 'Section numbers must be unique and contiguous; this is normally automatic, re-import if it persists.', 'wp-tournament-manager' ),
			'sec_num_noncontiguous'       => __( 'Section numbers must be unique and contiguous; this is normally automatic, re-import if it persists.', 'wp-tournament-manager' ),
			'timectl_below_blitz_minimum' => __( 'The time control is below the blitz minimum; confirm it is correct.', 'wp-tournament-manager' ),
			'timectl_unparseable'         => __( 'The time control could not be read; use a standard form like G/30;d5.', 'wp-tournament-manager' ),
			'trn_type_unsupported'        => __( 'Use Swiss or Round Robin; this pairing type cannot be exported.', 'wp-tournament-manager' ),
			'date_format_invalid'         => __( 'Enter the tournament begin and end dates in YYYY-MM-DD form on the Edit Tournament page.', 'wp-tournament-manager' ),
			'date_range_invalid'          => __( 'The end date is before the begin date; correct the dates on the Edit Tournament page.', 'wp-tournament-manager' ),
			'r_system_invalid'            => __( 'The section rating system is not one of R, Q, or B; set a valid time control so the system can be derived.', 'wp-tournament-manager' ),
		);

		return isset( $suggestions[ $code ] ) ? $suggestions[ $code ] : __( 'Review this item before exporting.', 'wp-tournament-manager' );
	}

	/**
	 * Builds the structured export payload and runs the pre-export
	 * validator against it. Shared by render_export_box() (readiness
	 * report) and handle_export() (server-side gate before generating
	 * files) so both always see the same data and the same checks.
	 *
	 * @return array{data:array,findings:array[]}
	 */
	protected static function build_report( $tournament ) {
		$bundle  = WPMTM_Repository::get_export_bundle( $tournament->id );
		$options = WPMTM_Plugin::instance()->get_opts();

		$tournament_arr = $bundle ? $bundle['tournament'] : array();
		$sections_arr   = $bundle ? $bundle['sections'] : array();

		$data = WPMTM_Export_Builder::build( $tournament_arr, $options, $sections_arr );

		$validator = new WPMTM_USCF_Validator( $data, true );

		return array(
			'data'     => $data,
			'findings' => $validator->validate(),
		);
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

		$redirect_back = add_query_arg( array( 'page' => 'wpmtm-edit', 'id' => $tournament_id ), admin_url( 'admin.php' ) );

		if ( ! $tournament->rated ) {
			$this->set_notice( 'error', __( 'This tournament is marked unrated; there is nothing to export to US Chess.', 'wp-tournament-manager' ) );
			wp_safe_redirect( $redirect_back );
			exit;
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->set_notice( 'error', __( 'The USCF export could not be created because this server\'s PHP does not have the zip extension enabled. Ask your host to enable php-zip, or export from a different server.', 'wp-tournament-manager' ) );
			wp_safe_redirect( $redirect_back );
			exit;
		}

		// Never trust the page state the browser posted back from - re-run
		// the same bundle + build + validate the readiness report used.
		$report = self::build_report( $tournament );
		$errors = array_values(
			array_filter(
				$report['findings'],
				function ( $finding ) {
					return 'error' === $finding['level'];
				}
			)
		);

		if ( $errors ) {
			$shown    = array_slice( $errors, 0, 5 );
			$messages = array_map( array( $this, 'format_finding' ), $shown );
			$message  = __( 'The USCF export could not be created; fix these errors first:', 'wp-tournament-manager' ) . ' ' . implode( ' ', $messages );
			$more     = count( $errors ) - count( $shown );
			if ( $more > 0 ) {
				/* translators: %d: number of additional errors not shown */
				$message .= ' ' . sprintf( __( '+ %d more error(s).', 'wp-tournament-manager' ), $more );
			}
			$this->set_notice( 'error', $message );
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

			nocache_headers();
			header( 'Content-Type: application/zip' );
			header( 'Content-Disposition: attachment; filename="' . $zip_filename . '"' );
			header( 'Content-Length: ' . filesize( $zip_path ) );

			readfile( $zip_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_readfile -- streaming a just-built local temp file to the browser as a download, not fetching a remote URL; WP_Filesystem has no streaming-download equivalent.

			unlink( $zip_path );
			exit;
		} catch ( Throwable $e ) {
			if ( $zip_path && file_exists( $zip_path ) ) {
				unlink( $zip_path );
			}
			$this->set_notice( 'error', __( 'The USCF export could not be created due to an unexpected error. Please try again; contact the plugin maintainer if it keeps happening.', 'wp-tournament-manager' ) );
			wp_safe_redirect( $redirect_back );
			exit;
		}
	}
}
