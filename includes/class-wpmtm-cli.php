<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI USCF validation commands (docs/SPEC.md, "Design (2026-07-15,
 * v1.2 USCF status at registration, export, and CLI)", section 5).
 * Registered only when WP_CLI is defined (wp-tournament-manager.php),
 * under the `wpmtm validate` namespace:
 *
 *   wp wpmtm validate players    --tournament=<id> [--event=<id>] [--format=json] [--fresh]
 *   wp wpmtm validate tds        --tournament=<id> [--format=json] [--fresh]
 *   wp wpmtm validate affiliate  [--tournament=<id>] [--event=<id>] [--format=json] [--fresh]
 *   wp wpmtm validate all        --tournament=<id> [--format=json] [--fresh]
 *
 * Reuses WPMTM_USCF_Status's evaluate/validate methods exactly as the
 * admin-ajax handlers and the export gate do, so the CLI and the screen
 * never disagree. This file has no top-level WP_CLI:: calls - only
 * inside method bodies, which PHP does not resolve until actually
 * invoked - so it is safe to require unconditionally from
 * tests/run-tests.php to unit-test aggregate_exit_status(), the one pure
 * piece of logic here (docs/SPEC.md: "a pure test for the aggregation /
 * exit-status logic").
 *
 * Every subcommand defaults to a CACHED lookup (docs/SPEC.md, "Decisions
 * (2026-07-16, USCF API traffic reduction)"): the CLI is the one surface
 * most likely to be driven from cron, and a cron job re-hitting the
 * unsupported MUIR API on every run would be exactly the overload this
 * revision fixes elsewhere. `--fresh` bypasses the cache for a one-off,
 * human-run check that wants a fresh answer.
 */
class WPMTM_CLI {

	// -----------------------------------------------------------------
	// Subcommands.
	// -----------------------------------------------------------------

	/**
	 * Validates every rated player's USCF membership.
	 *
	 * ## OPTIONS
	 *
	 * [--tournament=<id>]
	 * : Tournament ID. Players come from this tournament's stored
	 * roster; its end date (else the linked event's end date, else
	 * today) sets the through-date.
	 *
	 * [--event=<id>]
	 * : Event ID. Used directly for the roster (via wp-etr) when
	 * --tournament is omitted, or only for the through-date when both
	 * are given and the tournament has no end date set.
	 *
	 * [--format=<format>]
	 * : Render format. Default: table. Also accepts json, csv, yaml.
	 *
	 * [--fresh]
	 * : Bypass the cache and look up every player fresh. Default: cached
	 * (a lookup up to a day old). Use for a one-off check right after a
	 * membership renewal; leave off for routine/cron use, since the MUIR
	 * API is unsupported by USCF and unnecessary traffic is a risk.
	 *
	 * @when after_wp_load
	 */
	public function players( $args, $assoc_args ) {
		$rows = $this->build_player_rows( $assoc_args );
		$this->emit( $rows, $assoc_args );
	}

	/**
	 * Validates chief + assistant TD membership, TD certification, and
	 * Safe Play.
	 *
	 * ## OPTIONS
	 *
	 * [--tournament=<id>]
	 * : Tournament ID. Its per-tournament TD overrides are used when
	 * set, else the Settings defaults - the same resolution the DBF
	 * export uses. Through-date: the tournament's end date, else today.
	 *
	 * [--format=<format>]
	 * : Render format. Default: table. Also accepts json, csv, yaml.
	 *
	 * [--fresh]
	 * : Bypass the cache and look up both TDs fresh. Default: cached (a
	 * lookup up to a day old). Use for a one-off check right after a
	 * renewal; leave off for routine/cron use.
	 *
	 * @when after_wp_load
	 */
	public function tds( $args, $assoc_args ) {
		$rows = $this->build_td_rows( $assoc_args );
		$this->emit( $rows, $assoc_args );
	}

	/**
	 * Validates the club USCF affiliate.
	 *
	 * ## OPTIONS
	 *
	 * [--tournament=<id>]
	 * : Sets the through-date from this tournament's end date.
	 *
	 * [--event=<id>]
	 * : Sets the through-date from this event's end date when
	 * --tournament is omitted.
	 *
	 * [--format=<format>]
	 * : Render format. Default: table. Also accepts json, csv, yaml.
	 *
	 * [--fresh]
	 * : Bypass the cache and look up the affiliate fresh. Default: cached
	 * (a lookup up to a day old).
	 *
	 * @when after_wp_load
	 */
	public function affiliate( $args, $assoc_args ) {
		$rows = $this->build_affiliate_rows( $assoc_args );
		$this->emit( $rows, $assoc_args );
	}

	/**
	 * Validates players, TDs, and the affiliate together.
	 *
	 * ## OPTIONS
	 *
	 * [--tournament=<id>]
	 * : Tournament ID.
	 *
	 * [--event=<id>]
	 * : Event ID, see players'/affiliate's own option help.
	 *
	 * [--format=<format>]
	 * : Render format. Default: table. Also accepts json, csv, yaml.
	 *
	 * [--fresh]
	 * : Bypass the cache and look up players, TDs, and the affiliate all
	 * fresh. Default: cached (a lookup up to a day old).
	 *
	 * @when after_wp_load
	 */
	public function all( $args, $assoc_args ) {
		$rows = array_merge(
			$this->build_player_rows( $assoc_args, true ),
			$this->build_td_rows( $assoc_args ),
			$this->build_affiliate_rows( $assoc_args )
		);
		$this->emit( $rows, $assoc_args );
	}

	// -----------------------------------------------------------------
	// Row builders (WordPress layer: DB/option reads + WPMTM_USCF_Status
	// calls; not unit-tested, mirrors the existing admin-ajax handlers).
	// -----------------------------------------------------------------

	protected function build_player_rows( array $assoc_args, $lenient = false ) {
		$ctx   = $this->resolve_context( $assoc_args );
		$fresh = $this->is_fresh( $assoc_args );
		$rows  = array();

		if ( $ctx['tournament_id'] ) {
			$bundle = WPMTM_Repository::get_export_bundle( $ctx['tournament_id'] );
			if ( ! $bundle ) {
				WP_CLI::error( "Tournament {$ctx['tournament_id']} not found." );
			}
			foreach ( $bundle['sections'] as $section ) {
				foreach ( $section['players'] as $player ) {
					$name   = isset( $player['name'] ) ? (string) $player['name'] : '';
					$mem_id = isset( $player['mem_id'] ) ? (string) $player['mem_id'] : '';
					$rows[] = $this->player_row( $name, $mem_id, $ctx['through'], $fresh );
				}
			}
			return $rows;
		}

		if ( $ctx['event_id'] && class_exists( '\Etr\Plugin' ) ) {
			foreach ( \Etr\Plugin::instance()->build_sections( $ctx['event_id'] ) as $section_rows ) {
				foreach ( $section_rows as $r ) {
					if ( ! empty( $r['noshow'] ) ) {
						continue;
					}
					$name   = isset( $r['name'] ) ? (string) $r['name'] : '';
					// Same normalization as the admin-ajax "Validate players"
					// handler and the ETR roster import, so this CLI path
					// agrees with both - docs/SPEC.md, "Decisions
					// (2026-07-16, URL-form USCF IDs)".
					$mem_id = isset( $r['uscf_id'] ) ? WPMTM_USCF_Status::normalize_member_id_input( (string) $r['uscf_id'] ) : '';
					$rows[] = $this->player_row( $name, $mem_id, $ctx['through'], $fresh );
				}
			}
			return $rows;
		}

		if ( $lenient ) {
			return $rows; // `validate all` with neither option: nothing to check, not an error.
		}

		WP_CLI::error( 'Provide --tournament=<id> or --event=<id>.' );
	}

	protected function build_td_rows( array $assoc_args ) {
		$ctx       = $this->resolve_context( $assoc_args );
		$fresh     = $this->is_fresh( $assoc_args );
		$opts      = WPMTM_Plugin::instance()->get_opts();
		$chief     = (string) $opts['chief_td_id'];
		$assistant = (string) $opts['assistant_td_id'];

		if ( $ctx['tournament'] ) {
			if ( '' !== trim( (string) $ctx['tournament']->head_td_id ) ) {
				$chief = (string) $ctx['tournament']->head_td_id;
			}
			if ( '' !== trim( (string) $ctx['tournament']->assistant_td_id ) ) {
				$assistant = (string) $ctx['tournament']->assistant_td_id;
			}
		}

		$rows = array();
		if ( '' !== trim( $chief ) ) {
			$rows[] = $this->td_row( 'Chief TD', $chief, $ctx['through'], $fresh );
		}
		if ( '' !== trim( $assistant ) ) {
			$rows[] = $this->td_row( 'Assistant TD', $assistant, $ctx['through'], $fresh );
		}
		return $rows;
	}

	protected function build_affiliate_rows( array $assoc_args ) {
		$ctx       = $this->resolve_context( $assoc_args );
		$fresh     = $this->is_fresh( $assoc_args );
		$opts      = WPMTM_Plugin::instance()->get_opts();
		$affiliate = isset( $opts['affiliate_id'] ) ? (string) $opts['affiliate_id'] : '';
		if ( '' === trim( $affiliate ) ) {
			return array();
		}
		$verdict = WPMTM_USCF_Status::instance()->validate_affiliate( $affiliate, $ctx['through'], $fresh );
		return array( $this->finish_row( 'Affiliate', $verdict ) );
	}

	protected function player_row( $name, $mem_id, $through, $fresh = false ) {
		$verdict = WPMTM_USCF_Status::instance()->validate_member( $mem_id, $through, $fresh );
		$row     = $this->finish_row( 'Player', $verdict );
		if ( '' !== $name ) {
			$row['name'] = $name; // Prefer the roster's own name over the (often blank) API name.
		}
		return $row;
	}

	protected function td_row( $role, $id, $through, $fresh = false ) {
		$verdict = WPMTM_USCF_Status::instance()->validate_td( $id, $through, $fresh );
		return $this->finish_row( $role, $verdict );
	}

	/**
	 * Whether --fresh was passed on the command line (docs/SPEC.md,
	 * 2026-07-16): every subcommand defaults to a cached lookup, since a
	 * cron job hitting the unsupported MUIR API on a schedule is exactly
	 * the traffic this revision is meant to cut. --fresh bypasses the
	 * cache for a one-off, human-run check.
	 *
	 * @param array $assoc_args
	 * @return bool
	 */
	protected function is_fresh( array $assoc_args ) {
		return ! empty( $assoc_args['fresh'] );
	}

	protected function finish_row( $role, array $verdict ) {
		return array(
			'role'       => $role,
			'name'       => isset( $verdict['name'] ) ? $verdict['name'] : '',
			'member_id'  => isset( $verdict['member_id'] ) ? $verdict['member_id'] : '',
			'status'     => isset( $verdict['status'] ) ? $verdict['status'] : '',
			'expiration' => isset( $verdict['expiration'] ) ? $verdict['expiration'] : '',
			'verdict'    => $verdict['verdict'],
			'reason'     => '' !== $verdict['reason'] ? $verdict['reason'] : $verdict['warn'],
		);
	}

	/**
	 * Resolves tournament, event, and through-date from --tournament /
	 * --event, using WPMTM_USCF_Status::resolve_through_date() so the
	 * CLI's through-date logic matches every other v1.2 call site.
	 */
	protected function resolve_context( array $assoc_args ) {
		$tournament_id = isset( $assoc_args['tournament'] ) ? absint( $assoc_args['tournament'] ) : 0;
		$event_id      = isset( $assoc_args['event'] ) ? absint( $assoc_args['event'] ) : 0;

		$tournament = $tournament_id ? WPMTM_Repository::get_tournament( $tournament_id ) : null;
		if ( $tournament_id && ! $tournament ) {
			WP_CLI::error( "Tournament {$tournament_id} not found." );
		}
		if ( $tournament && ! $event_id && ! empty( $tournament->event_post_id ) ) {
			$event_id = (int) $tournament->event_post_id;
		}

		$tournament_end = $tournament && isset( $tournament->end_date ) ? (string) $tournament->end_date : '';
		$event_end      = $event_id ? $this->event_end_date( $event_id ) : '';

		return array(
			'tournament_id' => $tournament_id,
			'tournament'    => $tournament,
			'event_id'      => $event_id,
			'through'       => WPMTM_USCF_Status::resolve_through_date( $tournament_end, $event_end, '' ),
		);
	}

	protected function event_end_date( $event_id ) {
		$end = get_post_meta( $event_id, '_EventEndDate', true );
		return is_string( $end ) ? substr( $end, 0, 10 ) : '';
	}

	/**
	 * Renders rows (table by default, or --format) and exits non-zero
	 * when aggregate_exit_status() finds a FAIL.
	 */
	protected function emit( array $rows, array $assoc_args ) {
		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		$fields = array( 'role', 'name', 'member_id', 'status', 'expiration', 'verdict', 'reason' );

		if ( empty( $rows ) ) {
			WP_CLI::warning( 'Nothing to check - no players, TDs, or affiliate ID found for the given options.' );
		} else {
			\WP_CLI\Utils\format_items( $format, $rows, $fields );
		}

		$unknown = 0;
		foreach ( $rows as $row ) {
			if ( 'UNKNOWN' === $row['verdict'] ) {
				$unknown++;
			}
		}
		if ( $unknown > 0 ) {
			/* translators: %d: number of players/TDs/affiliate that could not be reached */
			WP_CLI::log( sprintf( '%d could not be checked (USCF ratings API unreachable) - not counted as a failure.', $unknown ) );
		}

		if ( 0 !== self::aggregate_exit_status( $rows ) ) {
			WP_CLI::halt( 1 );
		}
	}

	// -----------------------------------------------------------------
	// Pure aggregation logic (unit-tested by tests/run-tests.php).
	// -----------------------------------------------------------------

	/**
	 * Exit status for a list of verdict rows: non-zero when any row's
	 * verdict is FAIL. UNKNOWN (API unreachable) is reported but never
	 * by itself causes a non-zero exit (docs/SPEC.md v1.2 section 5).
	 * An empty list is a clean (0) exit - "nothing failed" - matching
	 * the DBF export/tournament-save rule that an outage or an empty
	 * roster never manufactures a failure.
	 *
	 * @param array $rows Rows each with a 'verdict' key ('PASS'|'FAIL'|'UNKNOWN').
	 * @return int 0 or 1.
	 */
	public static function aggregate_exit_status( array $rows ) {
		foreach ( $rows as $row ) {
			if ( isset( $row['verdict'] ) && 'FAIL' === $row['verdict'] ) {
				return 1;
			}
		}
		return 0;
	}
}
