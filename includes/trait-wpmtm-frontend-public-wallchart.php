<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public wall-chart rendering, extracted verbatim from WPMTM_Frontend_Public
 * (2026-07-29 segmentation): the bare table, its details-disclosure wrapper,
 * and the compact per-round cell. Composed back via a use statement; behavior
 * is identical.
 */
trait WPMTM_Frontend_Public_WallChart {
	/**
	 * Bare wall chart <table>: WPMTM_Scoring::crosstable() rendered as one
	 * row per player in pair_num order (not rank), with the per-round
	 * compact result cell plus the running score under it and a final total
	 * column. No wrapper of any kind - callers decide how (or whether) to
	 * frame it: render_wall_chart() below wraps it in a <details> disclosure
	 * for the no-tabs paths, while render_wall_chart_only() renders it
	 * directly, since the Wall chart tab itself is already the disclosure.
	 * Public data, never gated by WPMTM_CAPABILITY; nothing here writes
	 * anything (docs/SPEC.md, "Decisions (2026-07-10, wall chart)").
	 *
	 * @param bool $show_photos Tournament's show_photos flag; see
	 *                          render_section_standings() above.
	 */
	protected function render_wall_chart_table( array $players, array $games, array $byes, $max_round, $show_photos = false ) {
		$crosstable = WPMTM_Scoring::crosstable( $players, $games, $byes );
		if ( empty( $crosstable ) ) {
			return;
		}
		$players_by_id = array();
		foreach ( $players as $p ) {
			$players_by_id[ $p['id'] ] = $p;
		}
		// Editor-only player-card popovers (empty for the public); emitted
		// after the table since a <div popover> cannot live inside <tbody>.
		$cards = '';
		?>
		<table class="wpmtm-wall-chart-table wpmtm-table">
			<thead>
				<tr>
					<?php if ( $show_photos ) : ?>
						<th class="wpmtm-col-photo"><span class="screen-reader-text"><?php esc_html_e( 'Photo', 'wp-tournament-manager' ); ?></span></th>
					<?php endif; ?>
					<th><?php esc_html_e( '#', 'wp-tournament-manager' ); ?></th>
					<th><?php esc_html_e( 'Name', 'wp-tournament-manager' ); ?></th>
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
					<th><?php esc_html_e( 'Total', 'wp-tournament-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $crosstable as $row ) : ?>
					<?php if ( isset( $players_by_id[ $row['id'] ] ) ) { $cards .= $this->render_player_card( $players_by_id[ $row['id'] ], 'wpmtm-card-std' ); } ?>
					<tr>
						<?php if ( $show_photos ) : ?>
							<td class="wpmtm-avatar-cell">
								<?php
								echo self::render_avatar( isset( $row['photo_id'] ) ? $row['photo_id'] : null ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_avatar() returns either wp_get_attachment_image()'s own escaped markup or a static, hardcoded silhouette SVG with an escaped aria-label; see that method's docblock.
								?>
							</td>
						<?php endif; ?>
						<td><?php echo esc_html( $row['pair_num'] ); ?></td>
						<td><?php echo $this->player_name_html( $row['name'], ! empty( $row['family_name_first'] ), (int) $row['id'], 'wpmtm-card-std' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see player_name_html(); PII gated inside. ?></td>
						<?php for ( $r = 1; $r <= $max_round; $r++ ) : ?>
							<?php $cell = isset( $row['rounds'][ $r ] ) ? $row['rounds'][ $r ] : null; ?>
							<td>
								<?php if ( null !== $cell ) : ?>
									<?php echo esc_html( $cell['cell'] ); ?><br>
									<span class="wpmtm-wall-chart-running"><?php echo esc_html( self::format_score( $cell['running'] ) ); ?></span>
								<?php endif; ?>
							</td>
						<?php endfor; ?>
						<td><?php echo esc_html( self::format_score( $row['score'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php echo $cards; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each card is escaped inside render_player_card(); empty string for the public. ?>
		<?php
	}

	/**
	 * Public, read-only wall chart, collapsed by default behind a native
	 * <details> element so it does not compete with the standings table
	 * above it on the no-tabs paths (render_public_block(): the
	 * [wpmtm_standings] shortcode and the "wp-etr absent, or its filter
	 * never fired this request" inline fallback). When wp-etr tabs are
	 * active the wall chart gets its own tab instead and uses
	 * render_wall_chart_table() directly - see render_wall_chart_only().
	 *
	 * @param bool $show_photos Tournament's show_photos flag; see
	 *                          render_section_standings() above.
	 */
	protected function render_wall_chart( array $players, array $games, array $byes, $max_round, $show_photos = false ) {
		ob_start();
		$this->render_wall_chart_table( $players, $games, $byes, $max_round, $show_photos );
		$table_html = trim( ob_get_clean() );
		if ( '' === $table_html ) {
			return; // crosstable was empty - see render_wall_chart_table()'s guard.
		}
		?>
		<details class="wpmtm-wall-chart">
			<summary><?php esc_html_e( 'Wall chart', 'wp-tournament-manager' ); ?></summary>
			<?php echo $table_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $table_html is entirely render_wall_chart_table()'s own escaped output, captured above. ?>
		</details>
		<?php
	}

	/** Compact per-round cell: token letter + opponent pair_num (no color letter), e.g. "W12", "L3", "B". */
	protected function compact_round_result( $round_data, array $pair_num_by_id ) {
		if ( null === $round_data ) {
			return '';
		}
		$text = $round_data['token_result'];
		if ( null !== $round_data['opponent_player_id'] && isset( $pair_num_by_id[ $round_data['opponent_player_id'] ] ) ) {
			$text .= $pair_num_by_id[ $round_data['opponent_player_id'] ];
		}
		return $text;
	}
}
