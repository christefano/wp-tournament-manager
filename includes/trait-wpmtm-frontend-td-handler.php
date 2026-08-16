<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TD round-save handling (handle_save_round, its nopriv sibling, and the
 * return-URL builder), extracted verbatim from WPMTM_Frontend_TD (2026-07-29
 * segmentation). handle_save_round is registered in the class constructor and
 * still resolves through the composed trait. Behavior is identical.
 */
trait WPMTM_Frontend_TD_Handler {
	// -----------------------------------------------------------------
	// Save handler.
	// -----------------------------------------------------------------

	public function handle_save_round() {
		$section_id = isset( $_POST['section_id'] ) ? absint( $_POST['section_id'] ) : 0;
		$round      = isset( $_POST['round'] ) ? absint( $_POST['round'] ) : 0;
		check_admin_referer( 'wpmtm_save_round_' . $section_id . '_' . $round, 'wpmtm_round_nonce' );

		$tournament_id = isset( $_POST['tournament_id'] ) ? absint( $_POST['tournament_id'] ) : 0;
		$round_param   = isset( $_POST['wpmtm_return_round_param'] ) ? sanitize_key( wp_unslash( $_POST['wpmtm_return_round_param'] ) ) : '';
		// Audit item 60: posted by render_round_entry_form() (the hidden
		// wpmtm_mode field) but never read back until now, so every save
		// redirect dropped the mode and rounds_mode() fell through to its
		// default - which, the moment a pairing save had just written game
		// rows, resolved to 'results'. A TD pairing several rounds in one
		// sitting was bounced out of Pair mode after every single save.
		// Only 'pair'/'results' are ever posted (see the two hidden
		// wpmtm_mode inputs in render_round_entry_form()); anything else is
		// dropped here rather than carried into the redirect.
		$mode = isset( $_POST['wpmtm_mode'] ) ? sanitize_key( wp_unslash( $_POST['wpmtm_mode'] ) ) : '';
		if ( 'pair' !== $mode && 'results' !== $mode ) {
			$mode = '';
		}

		$section    = $section_id ? WPMTM_Repository::get_section( $section_id ) : null;
		$tournament = $section ? WPMTM_Repository::get_tournament( $section->tournament_id ) : null;

		if ( ! $section || ! $tournament || (int) $tournament->id !== $tournament_id ) {
			wp_die( esc_html__( 'Section not found, or it does not belong to the posted tournament.', 'wp-tournament-manager' ) );
		}

		if ( ! WPMTM_Roles::user_can_manage_tournament( $tournament ) ) {
			wp_die( esc_html__( 'No permission to perform this action.', 'wp-tournament-manager' ) );
		}

		// The section slug is recomputed here from the just-fetched, real DB
		// rows - never trusted from the POST - the same way $tournament's
		// locked state a few lines below is always re-fetched fresh rather
		// than carried over from render time. '' (no slug) for a single-
		// section tournament, matching render_section_td_panel()'s own
		// round_entry_hash( '' ) call, so the redirect hash is unchanged for
		// the common single-section case (docs/SPEC.md, 2026-07-18).
		$all_sections = WPMTM_Repository::get_sections( $tournament->id );
		$slug         = '';
		if ( count( $all_sections ) > 1 ) {
			$slugs = $this->build_section_slugs( $all_sections );
			$slug  = isset( $slugs[ $section_id ] ) ? $slugs[ $section_id ] : '';
		}

		$redirect_back = $this->build_return_url( $tournament, $round_param, $round, $slug, $mode );

		// Change 6 ("conclude and lock a tournament"): $tournament above
		// was just re-fetched fresh from the database a few lines up (not
		// carried over from render time), so this is always the current
		// locked state, not a stale one from whenever the form was
		// rendered - the real protection against a locked tournament being
		// edited; the round-entry form's disabled selects and missing Save
		// button (render_round_entry_form() above) are only the visual cue.
		if ( (bool) $tournament->locked ) {
			$this->set_notice( 'error', __( 'This tournament is locked. Unlock it to enter results.', 'wp-tournament-manager' ) );
			wp_safe_redirect( $redirect_back );
			exit;
		}

		if ( $round <= 0 ) {
			$this->set_notice( 'error', __( 'Invalid round number. Nothing was saved.', 'wp-tournament-manager' ) );
			wp_safe_redirect( $redirect_back );
			exit;
		}

		// Rounds run in order. Writing round 5 while round 3 is still blank
		// means either the wrong round is open or round 3 was never finished,
		// and for a Swiss section it also means round 5 was drawn from
		// standings that do not exist yet. The form hides the controls for
		// this case, but the form is only the cue: this is the gate, checked
		// against the database rather than anything posted. Editing an earlier
		// round is never blocked, since only rounds BEFORE $round are examined.
		$rounds_scored = WPMTM_Repository::rounds_fully_scored( $section_id );
		$gap           = WPMTM_Round_Selector::first_unscored_before( $round, $rounds_scored );
		$pairing_only_post = ! empty( $_POST['wpmtm_pairings_only'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by check_admin_referer() at the top of this handler.

		if ( $gap > 0 && ( ! $pairing_only_post || ! WPMTM_Round_Selector::can_pair_round( $section->trn_type, $round, $rounds_scored ) ) ) {
			$this->set_notice(
				'error',
				sprintf(
					/* translators: 1: round being saved, 2: the earlier unfinished round */
					__( 'Round %1$d cannot be saved yet because round %2$d does not have a result on every board. Finish round %2$d first. Nothing was saved.', 'wp-tournament-manager' ),
					$round,
					$gap
				)
			);
			wp_safe_redirect( $redirect_back );
			exit;
		}

		// Reuses WPMTM_Frontend_Public's mapping helper rather than
		// building this array inline a second time - see that method's
		// docblock for why it is the one place this mapping lives.
		$players = WPMTM_Frontend_Public::instance()->map_players( $section_id );

		// Boards are auto-numbered from posted row order (see
		// render_board_row()); a fully blank row (the unused trailing "Add
		// board" row) is skipped rather than counted.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each element is sanitized below (absint() for player ids, sanitize_text_field() for the result code) as it is read out of the array; nonce already verified above via check_admin_referer().
		$posted_white  = ( isset( $_POST['board_white'] ) && is_array( $_POST['board_white'] ) ) ? wp_unslash( $_POST['board_white'] ) : array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- see note above.
		$posted_black  = ( isset( $_POST['board_black'] ) && is_array( $_POST['board_black'] ) ) ? wp_unslash( $_POST['board_black'] ) : array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- see note above.
		$posted_result = ( isset( $_POST['board_result'] ) && is_array( $_POST['board_result'] ) ) ? wp_unslash( $_POST['board_result'] ) : array();

		$posted_white  = array_values( $posted_white );
		$posted_black  = array_values( $posted_black );
		$posted_result = array_values( $posted_result );

		$boards    = array();
		$board_num = 0;
		foreach ( $posted_white as $i => $white_raw ) {
			$black_raw  = isset( $posted_black[ $i ] ) ? $posted_black[ $i ] : '';
			$result_raw = isset( $posted_result[ $i ] ) ? $posted_result[ $i ] : '';

			if ( '' === $white_raw && '' === $black_raw ) {
				continue; // unused blank "add board" row.
			}

			++$board_num;
			$boards[] = array(
				'board'           => $board_num,
				'white_player_id' => absint( $white_raw ),
				'black_player_id' => absint( $black_raw ),
				'result'          => strtoupper( trim( sanitize_text_field( $result_raw ) ) ),
			);
		}

		// "Save pairings" (docs/SPEC.md, "Decisions (2026-08-14, saving
		// pairings before results)"): the pairing aid's own submit button posts
		// this flag, meaning "record who plays whom; the results are still to
		// come". Read here rather than further down because the result-carrying
		// block immediately below depends on it. The same nonce, lock, and
		// capability gates above have already run, since this is the same form
		// and the same handler as an ordinary save.
		$pairings_only = ! empty( $_POST['wpmtm_pairings_only'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by check_admin_referer() at the top of this handler.

		// "Save pairings" posts no result fields at all, and replace_round()
		// rewrites the whole round, so without this a TD who re-saved the
		// pairings of a round they had already scored would silently wipe every
		// result in it. Carry each existing result forward onto the board that
		// still has the SAME two players; a board whose pairing changed keeps
		// its blank, because the old result described a game that is no longer
		// scheduled. Keyed on the player pair rather than the board number,
		// since renumbering a board must not move a result onto a different
		// game.
		if ( $pairings_only ) {
			$existing_by_pair = array();
			foreach ( WPMTM_Repository::get_games( $section_id, $round ) as $existing ) {
				$key                      = (int) $existing->white_player_id . '-' . (int) $existing->black_player_id;
				$existing_by_pair[ $key ] = isset( $existing->result ) ? (string) $existing->result : '';
			}
			foreach ( $boards as $i => $board ) {
				if ( '' !== $board['result'] ) {
					continue;
				}
				$key = $board['white_player_id'] . '-' . $board['black_player_id'];
				if ( isset( $existing_by_pair[ $key ] ) ) {
					$boards[ $i ]['result'] = $existing_by_pair[ $key ];
				}
			}
		}

		// Roster membership for this section, built once here because three
		// separate posted sets are filtered against it below: the withdrawals
		// (audit item 36), the carried byes (item 35), and - indirectly, via
		// its own $known_ids pass - validate_round()'s boards and byes.
		$known_player_ids = array();
		foreach ( $players as $roster_player ) {
			if ( isset( $roster_player['id'] ) ) {
				$known_player_ids[ (int) $roster_player['id'] ] = true;
			}
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each $type is sanitized below (sanitize_text_field()) as it is read out of the array; nonce already verified above via check_admin_referer().
		$posted_byes    = ( isset( $_POST['byes'] ) && is_array( $_POST['byes'] ) ) ? wp_unslash( $_POST['byes'] ) : array();
		$byes           = array();
		$posted_wd_ids  = array(); // player ids posted as 'WD' - not a bye type, filtered and applied after a successful save below.
		foreach ( $posted_byes as $player_id => $type ) {
			$type = strtoupper( trim( sanitize_text_field( $type ) ) );
			if ( '' === $type ) {
				continue; // "None" selected.
			}
			if ( 'WD' === $type ) {
				// Withdrawals are not bye rows (docs/SPEC.md, withdrawals):
				// stripped from $byes here so neither validate_round() nor
				// replace_round() ever sees 'WD' as a bye type.
				$posted_wd_ids[] = absint( $player_id );
				continue;
			}
			$byes[] = array(
				'player_id' => absint( $player_id ),
				'type'      => $type,
			);
		}

		// Audit item 36: because 'WD' is stripped before validate_round()
		// runs, these ids reached WPMTM_Repository::set_player_withdrawn() -
		// an unscoped `WHERE id = %d` update - without ever being checked
		// against this section's roster. Filter them the same way the carried
		// byes below are, and re-apply the byes area's own withdraw-offered
		// rule server-side rather than trusting that the form only rendered
		// the option when it was allowed.
		$withdrawals = WPMTM_Round_Entry::filter_withdrawals(
			$posted_wd_ids,
			$known_player_ids,
			WPMTM_Round_Selector::withdraw_offered( $round, $section->tot_rnds )
		);

		// Guard: a player cannot both play a board and withdraw in the same round.
		$boards_player_ids = array();
		foreach ( $boards as $b ) {
			if ( $b['white_player_id'] ) {
				$boards_player_ids[] = $b['white_player_id'];
			}
			if ( $b['black_player_id'] ) {
				$boards_player_ids[] = $b['black_player_id'];
			}
		}
		$conflict = array_intersect( $withdrawals, $boards_player_ids );
		if ( ! empty( $conflict ) ) {
			$this->set_notice( 'error', __( 'A player cannot both play a board and withdraw in the same round. Remove one of the two and save again.', 'wp-tournament-manager' ) );
			wp_safe_redirect( $redirect_back );
			exit;
		}

		// Item 35 (docs/SPEC.md): read-only "carried" byes for players
		// withdrawn as of before this round who still hold a saved bye in it
		// (render_byes_area() renders them as read-only rows with a hidden
		// carried_byes[] input). They arrive under a separate field name
		// deliberately: validate_round() correctly rejects a bye for a
		// withdrawn player, but these are historical data preserved verbatim on
		// resave, not a new pairing the TD is making, so they bypass that check
		// and are merged into the written byes only after the editable set has
		// validated below.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each $type is sanitized below (sanitize_text_field()); nonce already verified above via check_admin_referer().
		$posted_carried = ( isset( $_POST['carried_byes'] ) && is_array( $_POST['carried_byes'] ) ) ? wp_unslash( $_POST['carried_byes'] ) : array();

		$editable_bye_ids = array();
		foreach ( $byes as $editable_bye ) {
			$editable_bye_ids[ (int) $editable_bye['player_id'] ] = true;
		}

		$carried_byes = WPMTM_Round_Entry::filter_carried_byes( $posted_carried, $known_player_ids, $boards_player_ids, $editable_bye_ids );

		// Blank results are accepted on BOTH paths, not just the pairings one.
		// A board that has been paired but not played has no result, and games
		// in a round finish at different times, so a TD entering the three
		// results they have so far must not be forced to invent the fourth.
		// "Every board has a result" is still enforced, but as the definition
		// of a COMPLETE round (the round selector's check mark, the sequential
		// gate below, and the export readiness report all key off it) rather
		// than as a condition of saving. A blank never reaches the USCF export:
		// WPMTM_Export_Builder::derive_rounds() skips any result outside
		// WPMTM_Scoring::RESULT_TOKEN_MAP, and the validator's round-count
		// check then reports the gap.
		$validation = WPMTM_Round_Entry::validate_round( $players, $boards, $byes, $round, true );

		if ( ! $validation['ok'] ) {
			$this->set_notice( 'error', $this->format_round_errors( $validation['errors'] ), count( $validation['errors'] ) > 1 );
			wp_safe_redirect( $redirect_back );
			exit;
		}

		$saved = WPMTM_Repository::replace_round( $section_id, $round, $boards, array_merge( $byes, $carried_byes ) );

		if ( ! $saved ) {
			$this->set_notice( 'error', __( 'The round could not be saved due to a database error. Nothing was changed.', 'wp-tournament-manager' ) );
			wp_safe_redirect( $redirect_back );
			exit;
		}

		// Withdrawals are only applied after the round itself saved
		// successfully - player P withdrawing as of round R means they
		// played through round R - 1, so that is the value recorded.
		$withdrawn_count = 0;
		foreach ( $withdrawals as $player_id ) {
			if ( WPMTM_Repository::set_player_withdrawn( $player_id, $round - 1 ) ) {
				++$withdrawn_count;
			}
		}

		WPMTM_Cache::flush_event_page( (int) $tournament->event_post_id );

		$message = $pairings_only
			? __( 'Pairings saved. Enter results below as the games finish.', 'wp-tournament-manager' )
			: __( 'Round saved. Standings and results above update immediately.', 'wp-tournament-manager' );
		if ( $withdrawn_count > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of players marked withdrawn */
				_n( '%d player was marked withdrawn.', '%d players were marked withdrawn.', $withdrawn_count, 'wp-tournament-manager' ),
				$withdrawn_count
			);
		}
		$this->set_notice( 'success', $message );
		wp_safe_redirect( $redirect_back );
		exit;
	}

	/**
	 * Round-entry validation errors, formatted for the notice pipeline.
	 *
	 * Audit item 55: these used to be implode( ' ' )'d into one paragraph, so
	 * a round with a duplicate board number AND a player on two boards AND a
	 * missing result arrived as a single run-on sentence - on a phone, which
	 * is the TD persona's primary device (docs/TD-PERSONA.md). A single error
	 * still reads best as one sentence; two or more become a list, matching
	 * how WPMTM_Admin_Export::render_findings() already presents the export
	 * readiness report.
	 *
	 * The multi-error return is HTML, so callers pass $is_html = true to
	 * set_notice() and render_notices() runs it through wp_kses_post()
	 * instead of esc_html(). Every error string is esc_html()'d here first,
	 * so the only markup that survives is this method's own <p>/<ul>/<li>.
	 * The <p> is supplied here rather than by render_notices(), which wraps
	 * plain-text messages only - see its own note on why a <ul> cannot live
	 * inside that wrapper.
	 *
	 * @param string[] $errors WPMTM_Round_Entry::validate_round()'s error list.
	 * @return string Plain text for one error, safe HTML for two or more.
	 */
	protected function format_round_errors( array $errors ) {
		$intro = __( 'The round could not be saved:', 'wp-tournament-manager' );

		if ( count( $errors ) < 2 ) {
			return $intro . ' ' . implode( ' ', $errors );
		}

		$items = '';
		foreach ( $errors as $error ) {
			$items .= '<li>' . esc_html( $error ) . '</li>';
		}
		return '<p>' . esc_html( $intro ) . '</p><ul class="wpmtm-round-errors">' . $items . '</ul>';
	}

	/** Logged-out POSTs to this action are always rejected outright. */
	public function handle_save_round_nopriv() {
		wp_die( esc_html__( 'Forbidden', 'wp-tournament-manager' ), 403 );
	}

	/**
	 * Redirect target after a save attempt: the event's own page (with the
	 * round GET param preserved so the TD lands back on the round they just
	 * worked on), or the admin tournament edit screen if the tournament has
	 * no linked event.
	 *
	 * When redirecting to the event page, appends round_entry_hash( $slug )
	 * (plain string concat after add_query_arg(), same as
	 * render_suggest_link() / render_round_selector() above) so saving a
	 * round lands the TD back on the wp-etr Round entry tab - and, for a
	 * multi-section tournament, the same section sub-tab they were already
	 * working (docs/SPEC.md, 2026-07-18) - instead of whichever tab/section
	 * wp-etr and this plugin would otherwise default to. The hash takes
	 * precedence over assets/wpmtm-frontend.js's sessionStorage fallback,
	 * which is still there and still used when no hash is present. Corrected
	 * 2026-08-10 (audit item 42): this said the hash had replaced that
	 * fallback outright, which was never true. Not appended for the admin edit screen
	 * fallback - that screen has no such tab.
	 *
	 * @param string $slug '' for single-section, else the just-saved
	 *                     section's build_section_slugs() value (see
	 *                     handle_save_round()'s own recompute-from-DB note).
	 * @param string $mode Audit item 60: 'pair' or 'results', carried
	 *                     forward from the just-posted form so rounds_mode()
	 *                     reads the TD's actual mode on the redirect instead
	 *                     of falling through to its own default. '' (the
	 *                     admin-edit fallback, or a posted value that was not
	 *                     one of the two known modes) adds no query arg,
	 *                     leaving rounds_mode() to fall through exactly as it
	 *                     always did before this parameter existed.
	 */
	protected function build_return_url( $tournament, $round_param, $round, $slug = '', $mode = '' ) {
		$is_event_page = (bool) $tournament->event_post_id;
		$base          = $is_event_page ? get_permalink( $tournament->event_post_id ) : '';
		if ( ! $base ) {
			$is_event_page = false;
			$base          = add_query_arg( array( 'page' => 'wpmtm-edit', 'id' => $tournament->id ), admin_url( 'admin.php' ) );
		}
		if ( '' !== $round_param ) {
			$base = add_query_arg( $round_param, $round, $base );
		}
		if ( '' !== $mode ) {
			$base = add_query_arg( 'wpmtm_mode', $mode, $base );
		}
		return $is_event_page ? $base . $this->round_entry_hash( $slug ) : $base;
	}
}
