<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure setup-guide step derivation, extracted verbatim from WPMTM_Wizard
 * (2026-07-29 segmentation, audit item 13-21). Zero WordPress calls and no
 * instance state: every method is static and self-contained, calling only
 * its siblings and other WPMTM_* classes. Composed into WPMTM_Wizard via
 * `use`, so callers still reach these as WPMTM_Wizard::derive_step() etc.
 * (docs/wizard-tests.php unchanged). Split out only to shrink the 1,700-line
 * wizard file; behavior is identical.
 */
trait WPMTM_Wizard_Steps {
	// -----------------------------------------------------------------
	// Pure step derivation. Zero WordPress calls, unit-tested directly by
	// tests/wizard-tests.php.
	//
	// $state keys: has_tournament (bool), rated (bool),
	// effective_ids_present (bool), settings_configured (bool, 2026-07-21),
	// tournament_configured (bool, 2026-07-21 step rename pass),
	// player_count (int), section_count (int),
	// sections_configured (bool, 2026-07-21), sections_complete (bool),
	// exported (bool, 2026-07-21 stepper hardening), locked (bool,
	// 2026-07-21 Lock/Verify rework).
	// -----------------------------------------------------------------

	public static function derive_step( array $state ) {
		$has_tournament        = ! empty( $state['has_tournament'] );
		$rated                 = ! empty( $state['rated'] );
		$player_count          = isset( $state['player_count'] ) ? (int) $state['player_count'] : 0;
		$section_count         = isset( $state['section_count'] ) ? (int) $state['section_count'] : 0;
		$settings_configured   = ! empty( $state['settings_configured'] );
		$tournament_configured = ! empty( $state['tournament_configured'] );
		$sections_configured   = ! empty( $state['sections_configured'] );
		$sections_complete     = ! empty( $state['sections_complete'] );
		$exported              = ! empty( $state['exported'] );
		$locked                = ! empty( $state['locked'] );

		if ( ! $has_tournament ) {
			$slug = 'create';
		} elseif ( $locked ) {
			// A TD's explicit lock action always wins, even over an
			// otherwise-incomplete tournament (docs/SPEC.md, 2026-07-21,
			// Lock/Verify steps): locking is a deliberate, out-of-band act
			// (WPMTM_Repository::set_tournament_locked()'s own docblock -
			// "nothing in this plugin sets or clears this flag on its
			// own"), so once it happens the guide should not keep telling
			// a TD to do something they have already declared done with.
			$slug = 'finish';
		} elseif ( ! $settings_configured ) {
			// Checked before roster/sections (docs/SPEC.md, 2026-07-21,
			// setup guide steps rework): the club-wide affiliate/chief TD
			// defaults make every later "Use default" button useful, so
			// this is worth surfacing early, but only for a tournament that
			// already exists - a brand-new install's very first prompt is
			// still "create a tournament" (the 'create' branch above), not
			// a detour through Settings before that tournament exists.
			$slug = 'settings';
		} elseif ( ! $tournament_configured ) {
			// 'tournament' (2026-07-21 step rename pass): this tournament's
			// OWN record - name, dates, the rated flag, and for a rated
			// tournament its Chief TD and affiliate. Before this step
			// existed the stepper jumped Settings straight to Roster, as
			// though a tournament configured itself, and a rated tournament
			// missing its Chief TD surfaced only as a warning banner rather
			// than as something the TD could see they still owed. This step
			// is what surfaces it now. Distinct from 'settings', which is
			// the club-wide defaults set once and reused by every tournament.
			$slug = 'tournament';
		} elseif ( 0 === $player_count ) {
			$slug = 'roster';
		} elseif ( 0 === $section_count || ! $sections_configured ) {
			// 'sections' vs 'rounds' (docs/SPEC.md, 2026-07-21): sections
			// existing with a round count set on each is a different,
			// earlier milestone than rounds actually being ENTERED
			// ($sections_complete below) - in practice a one-click import
			// creates sections and players together, so player_count and
			// section_count almost always clear the 'roster' check
			// together too, but $sections_configured (every section has
			// tot_rnds >= 1) is the real distinguishing signal: it is
			// false right after import until the TD confirms/sets each
			// section's round count, which is a genuinely separate action
			// from either importing or entering results.
			$slug = 'sections';
		} elseif ( ! $sections_complete ) {
			$slug = 'rounds';
		} elseif ( $rated && ! $exported ) {
			$slug = 'export';
		} else {
			$slug = 'finish';
		}

		// 'review' is never returned here, and is no longer a chip at all
		// (2026-07-22, owner request). It briefly was a returnable step and
		// that made it a dead end: nothing stored can prove a TD has read the
		// standings, so no action ever advanced past it. It was then demoted to
		// an advisory chip, and finally removed - its "check before you submit"
		// guidance now lives in the export step's README-linked documentation
		// (rated) and the finish step's copy (unrated), where it sits on an
		// action a TD can actually take. Reintroducing it as a hard gate would
		// require a stored "reviewed" flag set by an explicit TD click, the
		// same category of self-reported state as locking - a deliberate
		// product decision, not something to add back by accident.

		$step         = self::step_copy( $slug, $state );
		$step['slug'] = $slug;
		return $step;
	}

	/**
	 * Single source for the short step LABEL (the stepper chip caption and the
	 * step_copy() title, which are the same word per step). Keyed by slug;
	 * 'create' is intentionally absent - the stepper never shows it, and its
	 * step_copy() title is a full sentence ("Create your tournament"), not this
	 * one-word label. Untranslated, matching the rest of this pure section.
	 *
	 * @return array<string,string> slug => label.
	 */
	private static function step_labels() {
		return array(
			'settings'   => 'Settings',
			'tournament' => 'Tournament',
			'roster'     => 'Roster',
			'sections'   => 'Sections',
			'rounds'     => 'Rounds',
			'export'     => 'Export',
			'finish'     => 'Finish',
		);
	}

	/**
	 * Plain-language two-line copy for a step: "Status" (where the TD is
	 * right now) and "Next step" (the action to take, naming the exact
	 * control, plus why it matters - one sentence). Kept next to
	 * derive_step() so both are covered by the same pure unit tests.
	 * Untranslated plain PHP strings, not __() calls, matching the
	 * pure/no-WordPress-calls contract the rest of this section needs -
	 * the rendering methods below escape and print this copy but do not
	 * localize it.
	 *
	 * @param string $slug
	 * @param array  $state Same shape derive_step() takes; used only to
	 *                       interpolate the dynamic 'roster imported, N
	 *                       players in M sections' status line.
	 * @return array{title:string,status:string,next:string}
	 */
	private static function step_copy( $slug, array $state ) {
		$player_count  = isset( $state['player_count'] ) ? (int) $state['player_count'] : 0;
		$section_count = isset( $state['section_count'] ) ? (int) $state['section_count'] : 0;
		// Owner's own copy rewrite (2026-07-24): $state['rated'] is the single
		// per-tournament flag (already read at build_state()'s 'rated' key,
		// same flag settings_step_is_met() gates on) - branches here on the
		// same signal the rest of this class already trusts, even though
		// individual SECTIONS carry their own independent 'rated' column too
		// (a tournament can genuinely mix rated and unrated sections, see the
		// "RATED + UNRATED" test fixture). This copy is tournament-scoped, so
		// it follows the tournament's own flag, not a per-section one.
		$rated = ! empty( $state['rated'] );

		// Every step below carries TWO status lines, chosen by the same
		// step_is_met() signal that puts the checkmark on the chip (bug found
		// in a real browser 2026-07-22). Before this, each step had one status
		// written in the "you are here, not done yet" voice, which reads
		// correctly for the CURRENT step and is simply false for any completed
		// one: a TD clicking the ticked Roster chip was told "no players
		// imported yet" while the ticked Sections chip, in the same panel,
		// said "10 players in 2 sections". 'create' is never met and 'finish'
		// already branches on locked (its locked arm IS its done state), so
		// those two keep a single status each.
		$done = self::step_is_met( $slug, $state );

		// The chip label doubles as this step's title for every slug except
		// 'create' (see step_labels()); shared so the two never drift.
		$labels     = self::step_labels();
		$chip_label = isset( $labels[ $slug ] ) ? $labels[ $slug ] : '';

		switch ( $slug ) {
			case 'create':
				return array(
					'title'  => 'Create your tournament',
					'status' => 'Nothing is set up for this event yet.',
					'next'   => 'Use "Create tournament" on the event\'s Registrations tab, or enter players manually later during the Sections step.',
				);

			case 'settings':
				return array(
					'title'  => $chip_label,
					'status' => $done
						? 'The club-wide USCF affiliate ID and Chief TD ID are set.'
						: 'The club\'s USCF affiliate ID and Chief TD ID are not yet set.',
					'next'   => $rated
						? 'Open {settings_link} and (if you plan to run rated tournaments) at a minimum set the club\'s default USCF affiliate ID and Chief TD ID.'
						: 'Open {settings_link} and at a minimum set the club\'s default USCF affiliate ID and Chief TD ID. If you plan to run unrated tournaments, this step is not necessary.',
				);

			case 'tournament':
				return array(
					'title'  => $chip_label,
					'status' => $done
						? 'This tournament\'s minimum required details are set.'
						: 'This tournament\'s minimum required details have not yet been set.',
					'next'   => '{edit_link} and enter the tournament name, link the calendar event, add the tournament location, and (if it\'s USCF-rated) the club affiliate and Chief TD IDs.',
				);

			case 'roster':
				return array(
					'title'  => $chip_label,
					'status' => $done
						? sprintf(
							'Roster imported, %d player%s in %d section%s.',
							$player_count,
							1 === $player_count ? '' : 's',
							$section_count,
							1 === $section_count ? '' : 's'
						)
						: 'Tournament created, but players have not yet been imported from the event\'s Registration tab.',
					'next'   => 'Import the roster. {edit_link} and upload the Pairing export CSV from the event\'s Registrations tab, or {import_link} directly on the Registrations tab.',
				);

			case 'sections':
				return array(
					'title'  => $chip_label,
					'status' => $done
						? sprintf(
							'All %d section%s have at least one round. Note that most tournaments have at least 3 rounds.',
							$section_count,
							1 === $section_count ? '' : 's'
						)
						: sprintf(
							'Roster imported, %d player%s in %d section%s, but at least one section needs at least one round.',
							$player_count,
							1 === $player_count ? '' : 's',
							$section_count,
							1 === $section_count ? '' : 's'
						),
					'next'   => '{edit_link} and set each section\'s number of rounds in the {sections_link}. Note that most tournaments have at least 3 rounds.',
				);

			case 'rounds':
				// $rounds_started (2026-07-24): whether at least one round has
				// results entered anywhere in the tournament, distinct from
				// $done ($state['sections_complete'], every round in every
				// section). Gives a TD mid-tournament a "you're underway"
				// status instead of the same "roster imported" line they saw
				// before entering anything, and points the Next step at
				// pairing/entering the NEXT round rather than repeating the
				// generic "enter each round" copy shown before any rounds
				// exist.
				$rounds_started = ! empty( $state['rounds_started'] );
				return array(
					'title'  => $chip_label,
					'status' => $done
						? 'All rounds have been entered.'
						: ( $rounds_started
							? 'Rounds are underway.'
							: sprintf(
								'Roster imported. %d player%s in %d section%s.',
								$player_count,
								1 === $player_count ? '' : 's',
								$section_count,
								1 === $section_count ? '' : 's'
							) ),
					'next'   => ( $rounds_started && ! $done )
						? 'Open the event\'s Rounds tab to {pair_next_link} and {enter_results_link}.'
						: 'Open the event\'s Rounds tab and {rounds_link} with the pairing aid.',
				);

			case 'export':
				// Two done arms, because step_is_met() accepts either: an
				// export that was actually generated, or a lock, which settles
				// the question without one. The review guidance the old
				// standalone 'review' step carried lives in the plugin README
				// now (the panel's Documentation link), not inline here, to keep
				// this line lean (2026-07-22, owner request).
				// $rounds_complete (2026-07-24): distinct from this step's own
				// $done (exported||locked, per step_is_met() above) - an open
				// tournament where every round has been entered has finished
				// PLAYING but not necessarily exported/locked yet. Prefixed
				// onto the not-done status lines so a TD sees "the tournament
				// is finished, export is what's left" rather than the bare
				// "not downloaded yet" that reads the same whether round 1 or
				// the last round was just entered.
				$rounds_complete = ! empty( $state['sections_complete'] );
				return array(
					'title'  => $chip_label,
					'status' => $done
						? ( ! empty( $state['exported'] )
							? 'The USCF export has been downloaded.'
							: ( $rated
								? 'This tournament is locked, and results can be downloaded as a USCF export. Note that club affiliate status, TD membership status, and TD safe play certificate status will be checked during export.'
								: 'This tournament is locked, but results do not need to be downloaded as a USCF export. Note that an export is not necessary for unrated tournaments, and this step can be skipped. Tournament results already appear on the Standings tab of the event.' ) )
						: ( $rated
							? ( $rounds_complete
								? 'All rounds have been entered. The USCF export has not been downloaded yet.'
								: 'The USCF export has not been downloaded yet.' )
							: ( $rounds_complete
								? 'All rounds have been entered. The USCF export has not been downloaded yet. Note that an export is not necessary for unrated tournaments, and this step can be skipped. Tournament results already appear on the Standings tab of the event.'
								: 'The USCF export has not been downloaded yet. Note that an export is not necessary for unrated tournaments, and this step can be skipped. Tournament results already appear on the Standings tab of the event.' ) ),
					// The USCF zip drops unrated sections entirely
					// (WPMTM_Export_Builder::build(), see build_csv_report()'s
					// docblock), so the validator never raises a finding for
					// one - a rated tournament's export step only ever needs
					// its RATED sections' errors cleared. The former wording
					// here ("clear any validator errors for unrated
					// sections") pointed a TD at sections that can never be
					// the cause (fixed 2026-07-24, found while checking a
					// mixed rated+unrated tournament's guide text).
					'next'   => $rated
						? '{export_link}, but first clear any validator errors. Upload the USCF export zip contents to ratings.uschess.org.'
						: '{export_link}, but first clear any validator errors if you have any rated sections. Uploading the USCF export zip to ratings.uschess.org is not necessary for unrated tournaments, and this step can be skipped.',
				);

			case 'finish':
			default:
				// Reachable as the CURRENT step only once locked, but a TD
				// can click this chip at any time to preview it, so the copy
				// has to read correctly while the tournament is still open.
				// The lock/unlock action stays clickable (owner request,
				// 2026-07-23): {lock_link}/{unlock_link} render as a nonced
				// button, same wording rated or unrated.
				if ( ! empty( $state['locked'] ) ) {
					return array(
						'title'  => $chip_label,
						'status' => 'This tournament is locked and marked as complete. A "FINAL" status is now shown on the tournament\'s event page to indicate the tournament has been concluded. Congratulations!',
						'next'   => '{unlock_link} to reopen round entry if you need to correct a result, or {recap_link}.',
					);
				}
				return array(
					'title'  => $chip_label,
					'status' => ! empty( $state['exported'] )
						? ( $rated
							? 'The USCF export has been downloaded. This tournament is still open for edits, but after any edits another USCF export is recommended.'
							: 'The USCF export has been downloaded. This tournament is still open for edits, but note that an export is not necessary for unrated tournaments, and this step can be skipped.' )
						: 'This tournament is still open for edits.',
					'next'   => 'When the tournament is over, {lock_link} to close round entry and mark the tournament as completed.',
				);
		}
	}

	/**
	 * JSON-encode the per-step data blob for the stepper's
	 * `data-wpmtm-steps-data` attribute.
	 *
	 * The JSON_HEX_* flags are load-bearing, not decoration (bug found and
	 * fixed 2026-07-21, when clicking a chip had silently done nothing for
	 * as long as the feature had existed). The copy in $steps_data has
	 * already been through esc_html(), so a step whose text contains a
	 * literal double quote - the "Pairing export" CSV, the "Lock tournament"
	 * button, the ETECF "Section" field - carries a `&quot;` entity INSIDE
	 * the JSON string. esc_attr() runs _wp_specialchars() with
	 * double_encode = false, so it deliberately leaves that existing entity
	 * alone, and the browser then decodes it back into a raw double quote
	 * that terminates the JSON string early. One corrupted entry breaks
	 * JSON.parse for the WHOLE blob, so EVERY chip click died, not just the
	 * one with the quote. Escaping & " ' < > as \uXXXX means nothing
	 * esc_attr() transforms survives into the attribute at all.
	 *
	 * Deliberately json_encode() rather than wp_json_encode(): this stays a
	 * pure method with no WordPress calls, so tests/wizard-tests.php can
	 * exercise the REAL encoding rather than a stub, which is the whole
	 * point of having a regression test for this at all. wp_json_encode()'s
	 * extras buy little here, since nothing in $steps_data is user-supplied
	 * text - it is this class's own copy, admin URLs, and integer counts -
	 * and the one thing they would catch (invalid UTF-8 making json_encode()
	 * return false) is handled explicitly below, degrading to an empty
	 * object so the chips still render and a click is merely inert, rather
	 * than to an empty attribute that would throw in JSON.parse.
	 *
	 * @param array $steps_data Keyed by step slug, as built by render_panel().
	 * @return string JSON safe to place inside an esc_attr()'d attribute.
	 */
	public static function encode_steps_data( array $steps_data ) {
		$json = json_encode( $steps_data, JSON_HEX_QUOT | JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- deliberate, see this method's docblock: it must stay WordPress-free so the pure test suite can exercise the real encoding, and the false-return case wp_json_encode() would absorb is handled on the next line.
		return false === $json ? '{}' : $json;
	}

	/**
	 * Whether the panel should open by itself for a TD who has never
	 * expressed a preference (2026-07-21).
	 *
	 * True only for the early steps. Those are where a TD is still learning
	 * the flow and the guide is worth the screen space unprompted. By the
	 * time they are entering rounds they know what they are doing and want
	 * the room back, so it stays collapsed from 'sections' onward. An
	 * explicit choice always wins over this - see render_panel(), which
	 * consults is_panel_choice_made() first.
	 *
	 * Pure, no WordPress calls; unit-tested directly by tests/wizard-tests.php.
	 *
	 * @param string $slug A step slug, including 'create' for the Add
	 *                      Tournament screen where no tournament exists yet.
	 * @return bool
	 */
	public static function should_auto_expand( $slug ) {
		return in_array( $slug, array( 'create', 'settings', 'tournament', 'roster' ), true );
	}

	/**
	 * Whether a step's OWN completion criterion is satisfied, independent of
	 * where the TD currently is in the flow.
	 *
	 * Added 2026-07-21 to replace stepper_steps()'s original position-based
	 * rule ("every chip left of the current one is complete"), which had a
	 * real bug: on the Add Tournament page the current step is 'create',
	 * which is deliberately not IN the stepper list, so array_search()
	 * returned false and the completed branch became unreachable - every
	 * chip rendered 'upcoming' and the Settings chip showed no checkmark
	 * even for a club whose settings were fully configured. Asking each step
	 * about itself fixes that case and is honest in general: club-level
	 * Settings really is complete before any tournament exists.
	 *
	 * 'export' rides on `locked`: TM knows it generated a zip, but not that
	 * USCF accepted it, so a lock settles the step when no export was produced.
	 * (The former 'review' step, removed 2026-07-22, had no criterion of its
	 * own either - nothing stored can prove a TD looked at the standings - so
	 * it was folded into 'export'/'finish' rather than kept as a dead chip.)
	 *
	 * Pure, no WordPress calls; unit-tested directly by tests/wizard-tests.php.
	 *
	 * @param string $slug
	 * @param array  $state Same shape derive_step() takes.
	 * @return bool
	 */
	public static function step_is_met( $slug, array $state ) {
		switch ( $slug ) {
			case 'settings':
				return ! empty( $state['settings_configured'] );

			case 'tournament':
				return ! empty( $state['tournament_configured'] );

			case 'roster':
				return ( isset( $state['player_count'] ) ? (int) $state['player_count'] : 0 ) > 0;

			case 'sections':
				return ( isset( $state['section_count'] ) ? (int) $state['section_count'] : 0 ) > 0
					&& ! empty( $state['sections_configured'] );

			case 'rounds':
				return ! empty( $state['sections_complete'] );

			case 'export':
				// Either an export was actually generated, or the TD locked
				// the tournament, which settles the question either way - a
				// locked tournament is not waiting on an export, whether they
				// produced one or decided not to. Without the locked arm a
				// TD who locks without exporting would be left staring at a
				// permanently blank Export chip on a finished tournament.
				return ! empty( $state['exported'] ) || ! empty( $state['locked'] );

			case 'finish':
				return ! empty( $state['locked'] );

			default:
				return false;
		}
	}

	/**
	 * A short progress indicator like "Step 5 of 8" describing the current
	 * step's position in the stepper order. Pure, no WordPress calls,
	 * matching the contract step_tip() and step_copy() document.
	 *
	 * Two cases to handle deliberately:
	 * - When every step is complete (locked tournament), return "All steps
	 *   complete" rather than "Step 9 of 8".
	 * - When the derived step is not in the stepper at all (e.g., 'create'
	 *   on the Add Tournament screen), return an empty string and have the
	 *   render site skip the indicator entirely rather than printing
	 *   something meaningless.
	 *
	 * Returns STRUCTURED data, never a built sentence, so the render site can
	 * translate with literal strings. An earlier version returned English
	 * prose and the render site called `__( $progress )` on it, which is a
	 * translation call gettext can never satisfy: strings are extracted from
	 * source at parse time, so a variable argument is never collected and the
	 * text stays untranslatable no matter how many locales are installed.
	 * Plugin Check flags it. Keep the numbers here and the wording there.
	 *
	 * @param array $state Same shape derive_step()/stepper_steps() take.
	 * @return array{position:int,total:int,complete:bool}|null null when the
	 *         derived step is not in the stepper at all, so the caller renders
	 *         nothing.
	 */
	public static function progress_label( array $state ) {
		$current_slug = self::derive_step( $state )['slug'];
		$steps        = self::stepper_steps( $state );

		// Find the position of the current step in the stepper.
		$position = null;
		foreach ( $steps as $i => $step ) {
			if ( $step['slug'] === $current_slug ) {
				$position = $i + 1; // Position is 1-indexed.
				break;
			}
		}

		// The current step is not in the stepper at all ('create', on the Add
		// Tournament screen), so there is no position to report.
		if ( null === $position ) {
			return null;
		}

		$all_complete = true;
		foreach ( $steps as $step ) {
			if ( 'completed' !== $step['status'] ) {
				$all_complete = false;
				break;
			}
		}

		return array(
			'position' => $position,
			'total'    => count( $steps ),
			'complete' => $all_complete,
		);
	}

	/**
	 * One-sentence hover-tip description of what a step is for, shown on
	 * every stepper chip (render_panel()) so a TD can preview what each
	 * step does without clicking through them. Kept next to step_copy()
	 * so both are covered by the same pure unit tests. Untranslated plain
	 * PHP string, not a __() call, matching the pure/no-WordPress-calls
	 * contract step_copy() documents above - the rendering methods below
	 * escape and print this text but do not localize it. Public, unlike
	 * step_copy(): tests/wizard-tests.php exercises every slug the
	 * stepper can emit directly by name, the same way it already does for
	 * derive_step()/stepper_steps(), rather than only indirectly through
	 * another method's return value.
	 *
	 * @param string $slug
	 * @param bool   $rated Owner copy rewrite (2026-07-24): Settings and
	 *                       Export read differently for a rated vs. unrated
	 *                       tournament (every other slug's tip is identical
	 *                       either way). Defaults true so every pre-existing
	 *                       caller/test that only ever passed $slug keeps
	 *                       seeing the rated wording, unchanged.
	 * @return string Empty string for a slug with no reviewed tip copy
	 *                 (including 'create': stepper_steps() never emits that
	 *                 slug as a chip, so it has nothing to attach a tip to
	 *                 yet - see the "Create" step chip decision, still open).
	 */
	public static function step_tip( $slug, $rated = true ) {
		switch ( $slug ) {
			case 'settings':
				return $rated
					? 'Set the club\'s USCF affiliate ID and Chief TD ID so every new tournament can reuse them.'
					: 'Set the club\'s USCF affiliate ID and Chief TD ID so every new tournament can reuse them. Optional for unrated tournaments.';

			case 'tournament':
				return 'Name this tournament, set its dates and location, and whether it\'s USCF-rated.';

			case 'roster':
				return 'Import players from the linked calendar event\'s registrations, or upload a pairing CSV.';

			case 'sections':
				return 'Confirm each section\'s name and set each section\'s number of rounds.';

			case 'rounds':
				return 'Pair each round using the pairing aid, and enter round results.';

			case 'export':
				return $rated
					? 'Download the USCF zip and upload its contents at ratings.uschess.org to get the tournament rated.'
					: 'Download the USCF zip. Optional for unrated tournaments, but useful for importing results into other tournament software.';

			case 'finish':
				return 'Lock this tournament to mark it as complete and to prevent further edits.';

			default:
				return '';
		}
	}

	/**
	 * The compact horizontal stepper (SPEC section 1): Settings -> Tournament
	 * -> Roster -> Sections -> Rounds -> Export -> Finish for a rated
	 * tournament, or the same without Export for an unrated one (docs/SPEC.md,
	 * 2026-07-21 setup guide steps rework; 2026-07-22 Review removal). 'Finish'
	 * is the terminal milestone, tracked off the tournament's own `locked`
	 * flag rather than a step no state ever confirms. "Create your tournament"
	 * is never in this list - the panel this feeds only ever renders for an
	 * existing tournament, so it is done by definition. 'Review' is no longer
	 * a chip (2026-07-22): see the note in the body below. Pure, no WordPress
	 * calls; unit-tested directly by tests/wizard-tests.php.
	 *
	 * @param array $state Same shape derive_step() takes.
	 * @return array<int, array{slug:string,label:string,status:string}> status is 'completed'|'current'|'upcoming'.
	 */
	public static function stepper_steps( array $state ) {
		$rated = ! empty( $state['rated'] );

		// 'export' appears ONLY for a rated tournament - an unrated one has
		// nothing to submit, so the chip would be permanently dead weight.
		// This single conditional is the whole of how the stepper differs by
		// rated status: 7 chips rated, 6 unrated, every other chip identical
		// in both.
		//
		// 'review' was removed as a standalone chip (2026-07-22, owner
		// request): it never had a completion signal of its own (it rode on
		// $sections_complete, same as 'rounds') and overlapped 'export' on a
		// rated tournament. Its "check the standings and wall chart before you
		// finish" guidance is now folded into the 'export' step's copy for a
		// rated tournament and into the 'finish' step's copy for an unrated
		// one, where it sits on an action a TD can actually take.
		$order = $rated
			? array( 'settings', 'tournament', 'roster', 'sections', 'rounds', 'export', 'finish' )
			: array( 'settings', 'tournament', 'roster', 'sections', 'rounds', 'finish' );

		$labels = self::step_labels();

		$current = self::derive_step( $state )['slug'];

		$steps = array();
		foreach ( $order as $slug ) {
			// 'completed' is checked BEFORE 'current' (2026-07-21, stepper
			// hardening). The other way round, a finished tournament could
			// never show a full row of checkmarks: derive_step() returns
			// 'finish' for a locked tournament, so the final chip rendered
			// 'current' forever and the all-done state was unreachable by
			// construction. The only slug that can be met AND current is
			// 'finish' on a locked tournament, which is genuinely done, so
			// preferring 'completed' cannot hide an action a TD still owes.
			if ( self::step_is_met( $slug, $state ) ) {
				$status = 'completed';
			} elseif ( $slug === $current ) {
				$status = 'current';
			} else {
				$status = 'upcoming';
			}
			$steps[] = array(
				'slug'   => $slug,
				'label'  => $labels[ $slug ],
				'status' => $status,
			);
		}
		return $steps;
	}
}
