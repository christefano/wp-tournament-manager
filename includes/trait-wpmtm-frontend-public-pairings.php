<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public pairings tab, extracted verbatim from WPMTM_Frontend_Public
 * (2026-07-29 segmentation): the entry point, per-section rendering, the round
 * selector, and the result-label helper. Composed back via a use statement;
 * behavior is identical.
 */
trait WPMTM_Frontend_Public_Pairings {
	/**
	 * The public Pairings tab: a view-only board list per section for the
	 * latest fully-paired ("published") round, with a read-only selector back
	 * to earlier published rounds. Unlike the TD Rounds tab this renders for
	 * every visitor, so it never exposes an entry form, the pairing aid, or
	 * any command that changes state - just who plays whom, on which board,
	 * with which color, and the result once the TD has entered it.
	 *
	 * A round only appears here once WPMTM_Round_Selector::published_rounds()
	 * considers it fully paired, so a draft the TD is mid-entry never flashes
	 * to players. When nothing is published yet, each section shows an empty
	 * state rather than a blank tab.
	 *
	 * @param object $tournament
	 */
	public function render_pairings_only( $tournament ) {
		$this->ensure_attendee_ids_linked( $tournament );
		$sections = WPMTM_Repository::get_sections( $tournament->id );
		if ( empty( $sections ) ) {
			echo '<p>' . esc_html__( 'No sections have been set up yet.', 'wp-tournament-manager' ) . '</p>';
			return;
		}

		// Full TD command bar (2026-07-23), same call Standings/Wall chart use:
		// self-gates on WPMTM_CAPABILITY, so a public visitor sees no toolbar
		// here at all (previously the "Print pairings" button was shown to
		// everyone). Scope "pairings" clones #etr-panel-pairings with
		// .no-print / .wpmtm-toolbar stripped for the print, same as before -
		// only the board tables print, not the toolbar or round selector.
		$this->render_td_command_row( $tournament, 'pairings' );

		// Same tournament-level flag the Standings and Wall chart tabs read
		// (render_standings_only() / render_wall_chart_only()), so profile
		// pictures appear on all three tabs or none, and the printed pairing
		// sheet can be used to identify players at the board.
		$show_photos = (bool) $tournament->show_photos;

		$multi = count( $sections ) > 1;
		foreach ( $sections as $section ) {
			$this->render_pairings_section( $section, $multi, $show_photos );
		}
	}

	/**
	 * One section's board list for its selected published round. The selected
	 * round defaults to the latest published one; a ?wpmtm_pround_{id}=N query
	 * param (the read-only selector's own links) picks an earlier published
	 * round, and is ignored unless it names a genuinely published round, so a
	 * hand-edited URL can never surface an unpublished draft.
	 *
	 * @param object $section
	 * @param bool   $multi   Whether the tournament has more than one section
	 *                        (adds the section-name heading, matching
	 *                        render_standings_only()'s per-section layout).
	 * @param bool   $show_photos Tournament's show_photos flag. Unlike the
	 *                        standings and wall chart, which add a leading
	 *                        avatar COLUMN, the avatar here goes inline beside
	 *                        the name inside the existing White and Black
	 *                        cells: those are two separate player columns, so a
	 *                        photo column per side would make a four-column
	 *                        board list into six and stop looking like a
	 *                        pairing sheet.
	 */
	protected function render_pairings_section( $section, $multi, $show_photos = false ) {
		list( $players, $games, $byes ) = $this->section_data_arrays( $section );
		$published = WPMTM_Round_Selector::published_rounds( $players, $games, $byes, (int) $section->tot_rnds );
		?>
		<div class="wpmtm-pairings-section">
			<?php if ( $multi ) : ?>
				<h3><?php echo esc_html( $section->sec_name ); ?></h3>
			<?php endif; ?>

			<?php
			if ( empty( $published ) ) {
				echo '<p class="wpmtm-pairings-empty">' . esc_html__( 'Pairings for the next round will appear here once they are posted.', 'wp-tournament-manager' ) . '</p>';
				echo '</div>';
				return;
			}

			$latest        = (int) max( $published );
			$round_param   = 'wpmtm_pround_' . (int) $section->id;
			$selected      = $latest;
			if ( isset( $_GET[ $round_param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only round selector, not a state change; no writes happen on this public tab.
				$requested = absint( wp_unslash( $_GET[ $round_param ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, see note above.
				if ( in_array( $requested, $published, true ) ) {
					$selected = $requested;
				}
			}

			$this->render_pairings_round_selector( $published, $selected, $round_param );

			// Player-id lookups for the name/color rendering below; the pure
			// helpers work in ids, the WP layer resolves them to display names.
			$name_by_id    = array();
			$family_by_id  = array();
			$players_by_id = array();
			foreach ( $players as $p ) {
				$name_by_id[ (int) $p['id'] ]    = $p['name'];
				$family_by_id[ (int) $p['id'] ]  = ! empty( $p['family_name_first'] );
				$players_by_id[ (int) $p['id'] ] = $p;
			}
			// Editor-only player cards (empty for the public), collected as
			// names are rendered and emitted after this section's markup.
			$cards      = '';
			$card_added = array();
			$add_card   = function ( $pid ) use ( &$cards, &$card_added, $players_by_id ) {
				$pid = (int) $pid;
				if ( isset( $players_by_id[ $pid ] ) && empty( $card_added[ $pid ] ) ) {
					$cards .= $this->render_player_card( $players_by_id[ $pid ], 'wpmtm-card-pair' );
					$card_added[ $pid ] = true;
				}
			};

			// One player's cell content: the name (a plain escaped name for the
			// public, a card-opening button for a TD - player_name_html() decides),
			// optionally preceded by their avatar. With photos off this returns
			// exactly the markup this table emitted before, so the no-photo
			// layout is unchanged.
			$player_cell = function ( $pid ) use ( $name_by_id, $family_by_id, $players_by_id, $show_photos, $add_card ) {
				$pid = (int) $pid;
				if ( ! isset( $name_by_id[ $pid ] ) ) {
					return '';
				}
				$add_card( $pid );
				$name = $this->player_name_html( $name_by_id[ $pid ], ! empty( $family_by_id[ $pid ] ), $pid, 'wpmtm-card-pair' );
				if ( ! $show_photos ) {
					return $name;
				}
				$photo_id = isset( $players_by_id[ $pid ]['photo_id'] ) ? (int) $players_by_id[ $pid ]['photo_id'] : 0;
				return '<span class="wpmtm-pairing-player">' . self::render_avatar( $photo_id ) . '<span class="wpmtm-pairing-player-name">' . $name . '</span></span>';
			};

			$round_games = array_filter( $games, function ( $g ) use ( $selected ) {
				return (int) $g['round'] === (int) $selected;
			} );
			usort( $round_games, function ( $a, $b ) {
				return (int) $a['board'] <=> (int) $b['board'];
			} );

			$round_byes = array_filter( $byes, function ( $b ) use ( $selected ) {
				return (int) $b['round'] === (int) $selected;
			} );
			?>

			<table class="wpmtm-table wpmtm-pairings-table">
				<caption>
					<?php
					/* translators: %d: round number */
					printf( esc_html__( 'Round %d', 'wp-tournament-manager' ), (int) $selected );
					?>
				</caption>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Board', 'wp-tournament-manager' ); ?></th>
						<th scope="col"><?php esc_html_e( 'White', 'wp-tournament-manager' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Black', 'wp-tournament-manager' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Result', 'wp-tournament-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $round_games as $g ) : ?>
						<?php
						$white_id = (int) $g['white_player_id'];
						$black_id = (int) $g['black_player_id'];
						?>
						<tr>
							<td><?php echo esc_html( (int) $g['board'] ); ?></td>
							<td><?php echo $player_cell( $white_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see $player_cell above: name from player_name_html() (PII gated inside), avatar from render_avatar() (esc_attr'd, no user input). ?></td>
							<td><?php echo $player_cell( $black_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see $player_cell above: name from player_name_html() (PII gated inside), avatar from render_avatar() (esc_attr'd, no user input). ?></td>
							<td><?php echo esc_html( self::pairing_result_label( $g['result'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( ! empty( $round_byes ) ) : ?>
				<?php
				$bye_names = array();
				foreach ( $round_byes as $b ) {
					$cell = $player_cell( (int) $b['player_id'] );
					if ( '' !== $cell ) {
						$bye_names[] = $cell;
					}
				}
				?>
				<?php if ( ! empty( $bye_names ) ) : ?>
					<p class="wpmtm-pairings-byes">
						<strong><?php esc_html_e( 'Byes:', 'wp-tournament-manager' ); ?></strong>
						<?php echo implode( ', ', $bye_names ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each name is escaped inside player_name_html(). ?>
					</p>
				<?php endif; ?>
			<?php endif; ?>
		<?php echo $cards; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each card is escaped inside render_player_card(); empty string for the public. ?>
		</div>
		<?php
	}

	/**
	 * The read-only round selector for the Pairings tab, using the same
	 * pill-band markup as the TD round selector
	 * (WPMTM_Frontend_TD::render_round_selector(): .wpmtm-round-selector-band
	 * / .wpmtm-round-tab, styled by assets/wpmtm-frontend.css, already loaded
	 * on this public tab) - the two previously diverged, this tab still
	 * showing the older plain "Round: 1 2 3" text-link version. Lists only
	 * published rounds (unlike the TD selector's display_rounds(), which also
	 * lists the in-progress round) and links each with the pairings query
	 * param and #tab-pairings hash, so a click reloads on the same tab and
	 * section round. Marked .no-print so it drops out of the printed sheet
	 * (the round number is carried by each table's <caption> instead).
	 *
	 * @param int[]  $published    Published round numbers (ascending).
	 * @param int    $selected     Currently shown round.
	 * @param string $round_param  The wpmtm_pround_{id} query param name.
	 */
	protected function render_pairings_round_selector( array $published, $selected, $round_param ) {
		if ( count( $published ) < 2 ) {
			return; // Only one published round; nothing to switch between.
		}
		?>
		<div class="wpmtm-round-selector-band wpmtm-pairings-round-selector no-print">
			<span class="wpmtm-round-selector-label"><?php esc_html_e( 'Round', 'wp-tournament-manager' ); ?></span>
			<div class="wpmtm-round-selector" role="group" aria-label="<?php esc_attr_e( 'Rounds', 'wp-tournament-manager' ); ?>">
				<?php foreach ( $published as $r ) : ?>
					<?php if ( (int) $r === (int) $selected ) : ?>
						<span class="wpmtm-round-tab" aria-selected="true"><?php echo esc_html( $r ); ?></span>
					<?php else : ?>
						<a class="wpmtm-round-tab" aria-selected="false" href="<?php echo esc_url( add_query_arg( $round_param, $r ) . '#tab-pairings' ); ?>"><?php echo esc_html( $r ); ?></a>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * A player-friendly label for a game's stored result code (the
	 * WPMTM_Round_Entry::GAME_RESULTS set), in the universal score notation
	 * players recognize on a wall chart. An empty result (not yet entered)
	 * shows a dash, so an upcoming board reads as unplayed rather than blank.
	 *
	 * @param string $result Stored wpmtm_games.result code.
	 * @return string
	 */
	protected static function pairing_result_label( $result ) {
		switch ( (string) $result ) {
			case 'W':
				return '1-0';
			case 'B':
				return '0-1';
			case 'D':
				return '½-½';
			case 'FW':
				return __( '1-0 (F)', 'wp-tournament-manager' );
			case 'FB':
				return __( '0-1 (F)', 'wp-tournament-manager' );
			case 'FD':
				return __( '0-0 (F)', 'wp-tournament-manager' );
			default:
				return ''; // Not yet entered; an upcoming board reads as blank.
		}
	}
}
