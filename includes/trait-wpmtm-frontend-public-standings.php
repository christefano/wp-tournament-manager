<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public per-section standings table, extracted verbatim from
 * WPMTM_Frontend_Public (2026-07-29 segmentation). render_section_standings is
 * composed back via a use statement and reaches the class helpers
 * (section_data_arrays, render_avatar, the wall-chart trait) unchanged.
 * Behavior is identical.
 */
trait WPMTM_Frontend_Public_Standings {
	/**
	 * @param object $section
	 * @param bool   $show_photos         Tournament's show_photos flag; when
	 *                                    true, the standings table and the
	 *                                    wall chart below it each gain a
	 *                                    leading avatar cell per row (see
	 *                                    render_avatar()). When false the
	 *                                    column is not emitted at all -
	 *                                    today's exact layout.
	 * @param bool   $include_wall_chart  Whether to render the wall chart
	 *                                    (wrapped in its <details> disclosure)
	 *                                    directly under the standings table.
	 *                                    False from render_standings_only()
	 *                                    above, which puts the wall chart in
	 *                                    its own tab instead; true (default)
	 *                                    everywhere else, preserving the
	 *                                    original combined layout.
	 * @param bool   $show_td_note        Whether the section-not-complete
	 *                                    note is allowed to render for a
	 *                                    WPMTM_CAPABILITY user here. Default
	 *                                    false; both callers of
	 *                                    render_public_block() and the sole
	 *                                    caller of render_standings_only()
	 *                                    decide the right value for their
	 *                                    own caching guarantees - see those
	 *                                    methods' docblocks. Never shown to
	 *                                    the public regardless of this flag:
	 *                                    still gated on current_user_can()
	 *                                    below.
	 */
	protected function render_section_standings( $section, $show_photos = false, $include_wall_chart = true, $show_td_note = false ) {
		list( $players, $games, $byes ) = $this->section_data_arrays( $section );
		?>
		<div class="wpmtm-section-standings">
			<h3><?php echo esc_html( $section->sec_name ); ?></h3>
			<?php if ( empty( $games ) && empty( $byes ) ) : ?>
				<p><?php esc_html_e( 'No results yet.', 'wp-tournament-manager' ); ?></p>
			<?php else : ?>
				<?php
				$standings = WPMTM_Scoring::standings( $players, $games, $byes, (int) $section->tot_rnds );

				// Same completeness test as WPMTM_Wizard::build_state() and
				// WPMTM_Frontend_Public::rated_and_complete(), and since audit
				// item 54 literally the same function rather than a third copy
				// of the rule (docs/SPEC.md, "Decisions (2026-07-18, rank by
				// score until complete)"). Mid-tournament, tiebreaks are noise
				// (a round-1 "leader" is really just the player who happened to
				// draw the strongest opponent so far), so ranks group on score
				// alone until then - the tiebreak columns keep displaying
				// throughout.
				// Fully SCORED, not merely paired: a round whose boards exist but
				// carry no result yet must not make the section look finished
				// and switch the ranking over to tiebreaks.
				$rounds_done       = WPMTM_Repository::rounds_fully_scored( $section->id );
				$section_complete  = WPMTM_Round_Selector::section_complete( $section->tot_rnds, $rounds_done );
				$ranks             = WPMTM_Scoring::ranks_for( $standings, $section_complete );

				$pair_num_by_id = array();
				$players_by_id  = array();
				$max_round      = (int) $section->tot_rnds;
				foreach ( $players as $p ) {
					$pair_num_by_id[ $p['id'] ] = $p['pair_num'];
					$players_by_id[ $p['id'] ]  = $p;
				}
				// Editor-only player-card popovers, collected during the row
				// loop and emitted after the table (a <div popover> cannot live
				// inside <tbody>). Empty string for the public - render_player_card()
				// returns '' unless the viewer can see cards.
				$cards = '';
				foreach ( $standings as $row ) {
					foreach ( array_keys( $row['rounds'] ) as $r ) {
						$max_round = max( $max_round, (int) $r );
					}
				}
				?>
				<table class="wpmtm-standings-table wpmtm-table">
					<thead>
						<tr>
							<?php if ( $show_photos ) : ?>
								<th class="wpmtm-col-photo"><span class="screen-reader-text"><?php esc_html_e( 'Photo', 'wp-tournament-manager' ); ?></span></th>
							<?php endif; ?>
							<th><?php esc_html_e( 'Rank', 'wp-tournament-manager' ); ?></th>
							<th><?php esc_html_e( 'Name', 'wp-tournament-manager' ); ?></th>
							<th><?php esc_html_e( 'Score', 'wp-tournament-manager' ); ?></th>
							<th title="<?php esc_attr_e( 'Modified Median', 'wp-tournament-manager' ); ?>"><?php esc_html_e( 'MM', 'wp-tournament-manager' ); ?></th>
							<th title="<?php esc_attr_e( 'Solkoff', 'wp-tournament-manager' ); ?>"><?php esc_html_e( 'Sol', 'wp-tournament-manager' ); ?></th>
							<th title="<?php esc_attr_e( 'Cumulative', 'wp-tournament-manager' ); ?>"><?php esc_html_e( 'Cum', 'wp-tournament-manager' ); ?></th>
							<th title="<?php esc_attr_e( 'Cumulative of Opposition', 'wp-tournament-manager' ); ?>"><?php esc_html_e( 'CO', 'wp-tournament-manager' ); ?></th>
							<?php for ( $r = 1; $r <= $max_round; $r++ ) : ?>
								<th>
									<?php
									printf(
										/* translators: %d: round number */
										esc_html__( 'Rd %d', 'wp-tournament-manager' ),
										(int) $r
									);
									?>
								</th>
							<?php endfor; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $standings as $i => $row ) : ?>
							<?php if ( isset( $players_by_id[ $row['id'] ] ) ) { $cards .= $this->render_player_card( $players_by_id[ $row['id'] ], 'wpmtm-card-std' ); } ?>
							<tr>
								<?php if ( $show_photos ) : ?>
									<td class="wpmtm-avatar-cell">
										<?php
										echo self::render_avatar( isset( $row['photo_id'] ) ? $row['photo_id'] : null ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_avatar() returns either wp_get_attachment_image()'s own escaped markup or a static, hardcoded silhouette SVG with an escaped aria-label; see that method's docblock.
										?>
									</td>
								<?php endif; ?>
								<td><?php echo esc_html( $ranks[ $i ] ); ?></td>
								<td>
									<?php echo $this->player_name_html( $row['name'], ! empty( $row['family_name_first'] ), (int) $row['id'], 'wpmtm-card-std' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- player_name_html() returns esc_html()'d text (public) or a button built from esc_attr/esc_html (card viewer); PII is gated inside. ?>
									<?php if ( isset( $row['withdrawn_after_round'] ) && null !== $row['withdrawn_after_round'] ) : ?>
										<?php
										printf(
											/* translators: %d: round number after which the player withdrew */
											' ' . esc_html__( '(withdrew after round %d)', 'wp-tournament-manager' ),
											(int) $row['withdrawn_after_round']
										);
										?>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( self::format_score( $row['score'] ) ); ?></td>
								<td><?php echo esc_html( self::format_score( $row['modified_median'] ) ); ?></td>
								<td><?php echo esc_html( self::format_score( $row['solkoff'] ) ); ?></td>
								<td><?php echo esc_html( self::format_score( $row['cumulative'] ) ); ?></td>
								<td><?php echo esc_html( self::format_score( $row['cumulative_opp'] ) ); ?></td>
								<?php for ( $r = 1; $r <= $max_round; $r++ ) : ?>
									<?php $cell = isset( $row['rounds'][ $r ] ) ? $row['rounds'][ $r ] : null; ?>
									<td><?php echo esc_html( $this->compact_round_result( $cell, $pair_num_by_id ) ); ?></td>
								<?php endfor; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php echo $cards; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each card is built and escaped inside render_player_card(); empty string for the public. ?>
					<?php if ( $show_td_note && current_user_can( WPMTM_CAPABILITY ) ) : ?>
						<div class="wpmtm-td-note-banner">
							<?php if ( ! $section_complete ) : ?>
								<p><?php esc_html_e( 'Ranked by score alone until every round in this section is entered. The tiebreak columns above will then decide placement.', 'wp-tournament-manager' ); ?></p>
							<?php endif; ?>
							<p><?php esc_html_e( 'Ties in score are broken left to right by Modified Median, then Solkoff, then Cumulative, then Cumulative of Opposition (USCF rule 34E).', 'wp-tournament-manager' ); ?></p>
						</div>
					<?php endif; ?>
				<?php if ( $include_wall_chart ) : ?>
					<?php $this->render_wall_chart( $players, $games, $byes, $max_round, $show_photos ); ?>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
