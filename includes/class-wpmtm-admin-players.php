<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-section players editor admin surface: corrects individual players by
 * hand (name, USCF ID, state, rating, withdrawn-after-round, family name
 * first, and family key), plus the save handler. Split out of WPMTM_Admin
 * the same way WPMTM_Admin_Import and WPMTM_Admin_Export are, with the same
 * nonce/capability/escaping discipline. Reached from a section's "Manage
 * (n)" link on WPMTM_Admin_Sections's sections editor screen, via
 * WPMTM_Admin::render_tournament_edit()'s own ?section_id= dispatch.
 *
 * Players are edited as a single bulk form per screen (a repeater table):
 * existing rows can be edited in place, new rows are added client-side
 * (assets/wpmtm-admin.js), and removing a row either drops an unsaved row
 * from the DOM or flags an existing row for server-side deletion via a
 * hidden "removed_players" field. One Save submits the whole set.
 */
class WPMTM_Admin_Players {

	use WPMTM_Admin_Shared;

	private static $instance = null;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_post_wpmtm_save_players', array( $this, 'handle_save_players' ) );
	}

	// -----------------------------------------------------------------
	// Players editor (per section)
	// -----------------------------------------------------------------

	/**
	 * When the tournament's show_photos flag is set, each player row gains a
	 * leading avatar cell, rendered with WPMTM_Frontend_Public::render_avatar()
	 * - that method is public static with no instance state (see its own
	 * docblock), so it is called directly here rather than duplicated; the
	 * column is absent entirely when show_photos is off, matching the public
	 * standings table and the TD's pairing aid.
	 */
	public function render_players_editor( $tournament, $section ) {
		$players     = WPMTM_Repository::get_players( $section->id );
		$tot_rnds    = max( 0, (int) $section->tot_rnds );
		$show_photos = (bool) $tournament->show_photos;
		$back_url    = add_query_arg( array( 'page' => 'wpmtm-edit', 'id' => $tournament->id ), admin_url( 'admin.php' ) );
		?>
		<div class="wrap wpmtm-wrap">
			<h1>
				<?php
				printf(
					/* translators: 1: section name, 2: tournament name */
					esc_html__( 'Players: %1$s - %2$s', 'wp-tournament-manager' ),
					esc_html( $section->sec_name ),
					esc_html( $tournament->name )
				);
				?>
			</h1>
			<p><a href="<?php echo esc_url( $back_url ); ?>">&laquo; <?php esc_html_e( 'Back to sections', 'wp-tournament-manager' ); ?></a></p>
			<?php $this->render_notices(); ?>

			<div class="notice notice-info inline">
				<p>
					<?php esc_html_e( 'The roster for this section normally comes from "Import Registrations" on the tournament screen. Use the form below only to correct individual players by hand (registration is closed once the event starts, so this is a data-correction tool, not a registration path).', 'wp-tournament-manager' ); ?>
				</p>
				<p>
					<?php esc_html_e( 'Pairing numbers (the # column) are assigned automatically, highest rating first and unrated players last, and cannot be manually assigned.', 'wp-tournament-manager' ); ?>
				</p>
				<p>
					<?php esc_html_e( 'Withdrawn marks a player out from a chosen round onward: their score stays frozen where it was, they drop out of pairing and round entry from that point on, and the USCF export fills their remaining rounds with the U (not paired) code automatically. Setting a player back to Active reinstates them safely at any time, since withdrawing never writes any result rows.', 'wp-tournament-manager' ); ?>
				</p>
				<p>
					<?php esc_html_e( 'Family name first is for players whose culture puts the family name first (for example many East Asian names): check this so their name shows family name first everywhere in Tournament Manager. The Name field here still stores LAST,FIRST regardless. This flag only controls how that name is displayed.', 'wp-tournament-manager' ); ?>
				</p>
				<p>
					<?php esc_html_e( 'Players sharing a family key, or sharing a last name, are not paired against each other when suggesting pairings (best effort). The family key is filled in automatically for ETR imports carrying a parent email. Edit it here to clear a false positive (unrelated players who happen to share a surname still avoid each other by last name alone, regardless of family key) or to add a false negative (give siblings with different surnames the same key).', 'wp-tournament-manager' ); ?>
				</p>
				<p>
					<?php esc_html_e( 'Additional info under a player\'s name is imported as-is from the registrant\'s own "Additional information" field. There is no field here to edit it. Registrants sometimes use it to ask for a bye. It also shows next to their name in the Round entry Byes area.', 'wp-tournament-manager' ); ?>
				</p>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wpmtm_save_players_' . $section->id, 'wpmtm_players_nonce' ); ?>
				<input type="hidden" name="action" value="wpmtm_save_players">
				<input type="hidden" name="section_id" value="<?php echo esc_attr( $section->id ); ?>">
				<input type="hidden" id="wpmtm-removed-players" name="removed_players" value="">

				<table class="wp-list-table widefat fixed striped wpmtm-repeater" id="wpmtm-players-table" data-wpmtm-repeater data-removed-input="wpmtm-removed-players" data-wpmtm-remove-confirm="<?php echo esc_attr__( "Remove this player? If they've already played any rounds, this permanently deletes their OPPONENTS' game results for those rounds too, not just this player's. Standings and USCF export will change accordingly. This cannot be undone once saved.", 'wp-tournament-manager' ); ?>">
					<thead>
						<tr>
							<?php if ( $show_photos ) : ?>
								<th class="wpmtm-col-photo"><?php esc_html_e( 'Photo', 'wp-tournament-manager' ); ?></th>
							<?php endif; ?>
							<th class="wpmtm-col-num"><?php esc_html_e( '#', 'wp-tournament-manager' ); ?></th>
							<th><?php esc_html_e( 'Name (LAST,FIRST MIDDLE)', 'wp-tournament-manager' ); ?></th>
							<th><?php esc_html_e( 'USCF ID', 'wp-tournament-manager' ); ?></th>
							<th><?php esc_html_e( 'State', 'wp-tournament-manager' ); ?></th>
							<th><?php esc_html_e( 'Rating', 'wp-tournament-manager' ); ?></th>
							<th><?php esc_html_e( 'Withdrawn', 'wp-tournament-manager' ); ?></th>
							<th title="<?php echo esc_attr__( 'For players whose culture puts the family name first (for example many East Asian names). This only affects display, not how the name is stored.', 'wp-tournament-manager' ); ?>"><?php esc_html_e( 'Family name first', 'wp-tournament-manager' ); ?></th>
							<th title="<?php echo esc_attr__( 'Players sharing a family key, or sharing a last name, are not paired against each other when suggesting pairings (best effort).', 'wp-tournament-manager' ); ?>"><?php esc_html_e( 'Family key', 'wp-tournament-manager' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $players as $player ) : ?>
						<?php $this->render_player_row( $player, null, $tot_rnds, $show_photos ); ?>
					<?php endforeach; ?>
					</tbody>
					<template>
						<?php $this->render_player_row( null, '__INDEX__', $tot_rnds, $show_photos ); ?>
					</template>
				</table>
				<p><button type="button" class="button" data-add-row-for="wpmtm-players-table"><?php esc_html_e( '+ Add player', 'wp-tournament-manager' ); ?></button></p>
				<?php submit_button( __( 'Save Players', 'wp-tournament-manager' ) ); ?>
			</form>
		</div>
		<?php
	}

	protected function render_player_row( $player, $index = null, $tot_rnds = 0, $show_photos = false ) {
		$is_template            = null === $player;
		$key                    = $is_template ? $index : $player->id;
		$pair_num               = $is_template ? '' : $player->pair_num;
		$name                   = $is_template ? '' : $player->name;
		$mem_id                 = $is_template ? '' : $player->mem_id;
		$state                  = $is_template ? '' : $player->state;
		$rating                 = $is_template ? '' : $player->rating;
		$withdrawn_after_round  = $is_template ? '' : $player->withdrawn_after_round;
		$photo_id               = $is_template ? null : $player->photo_id;
		$family_name_first      = $is_template ? false : (bool) $player->family_name_first;
		$family_key             = $is_template ? '' : (string) $player->family_key;
		$rating_source          = $is_template ? '' : (string) $player->rating_source;
		$rating_checked         = $is_template ? 0 : (int) $player->rating_checked;
		$notes                  = $is_template ? '' : (string) $player->notes;
		$tot_rnds               = max( 0, (int) $tot_rnds );
		?>
		<tr<?php echo $is_template ? '' : ' data-existing-id="' . esc_attr( $key ) . '"'; ?>>
			<?php if ( $show_photos ) : ?>
				<td class="wpmtm-avatar-cell">
					<?php
					echo WPMTM_Frontend_Public::render_avatar( $photo_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see WPMTM_Frontend_Public::render_avatar()'s docblock.
					?>
				</td>
			<?php endif; ?>
			<td class="wpmtm-col-num"><?php echo $is_template ? esc_html__( 'auto', 'wp-tournament-manager' ) : esc_html( $pair_num ); ?></td>
			<td>
				<input type="text" name="players[<?php echo esc_attr( $key ); ?>][name]" value="<?php echo esc_attr( $name ); ?>" placeholder="LAST,FIRST">
				<?php if ( '' !== $notes ) : ?>
					<p class="description wpmtm-player-note" title="<?php echo esc_attr__( 'From the registrant\'s Additional information field.', 'wp-tournament-manager' ); ?>">
						<strong><?php esc_html_e( 'Additional info:', 'wp-tournament-manager' ); ?></strong>
						<?php echo esc_html( $notes ); ?>
					</p>
				<?php endif; ?>
			</td>
			<td><input type="text" maxlength="8" name="players[<?php echo esc_attr( $key ); ?>][mem_id]" value="<?php echo esc_attr( $mem_id ); ?>"></td>
			<td><input type="text" maxlength="2" class="small-text" name="players[<?php echo esc_attr( $key ); ?>][state]" value="<?php echo esc_attr( $state ); ?>"></td>
			<td>
				<input type="text" maxlength="4" class="small-text" name="players[<?php echo esc_attr( $key ); ?>][rating]" value="<?php echo esc_attr( $rating ); ?>">
				<?php if ( WPMTM_Registration_Check::RATING_SOURCE_OFFICIAL === $rating_source ) : ?>
					<p class="description wpmtm-rating-provenance">
						<?php
						if ( $rating_checked > 0 ) {
							printf(
								/* translators: %s: human-readable relative time, e.g. "5 hours" */
								esc_html__( 'USCF, checked %s ago', 'wp-tournament-manager' ),
								esc_html( human_time_diff( $rating_checked, current_time( 'timestamp' ) ) ) // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- must match the current_time('timestamp') convention rating_checked is stored under (see WPMTM_Registration_Check::check_attendee()), not raw time().
							);
						} else {
							esc_html_e( 'USCF', 'wp-tournament-manager' );
						}
						?>
					</p>
				<?php endif; ?>
			</td>
			<td>
				<select name="players[<?php echo esc_attr( $key ); ?>][withdrawn_after_round]">
					<option value="" <?php selected( '' === (string) $withdrawn_after_round, true ); ?>><?php esc_html_e( 'Active', 'wp-tournament-manager' ); ?></option>
					<?php for ( $n = 0; $n <= $tot_rnds; $n++ ) : ?>
						<option value="<?php echo esc_attr( $n ); ?>" <?php selected( (string) $withdrawn_after_round, (string) $n ); ?>>
							<?php
							if ( 0 === $n ) {
								esc_html_e( 'Before round 1', 'wp-tournament-manager' );
							} else {
								printf(
									/* translators: %d: round number */
									esc_html__( 'After round %d', 'wp-tournament-manager' ),
									(int) $n
								);
							}
							?>
						</option>
					<?php endfor; ?>
				</select>
			</td>
			<td>
				<label>
					<input type="checkbox" name="players[<?php echo esc_attr( $key ); ?>][family_name_first]" value="1" <?php checked( $family_name_first ); ?>>
					<span class="screen-reader-text"><?php esc_html_e( 'Family name first', 'wp-tournament-manager' ); ?></span>
				</label>
			</td>
			<td><input type="text" class="small-text" name="players[<?php echo esc_attr( $key ); ?>][family_key]" value="<?php echo esc_attr( $family_key ); ?>"></td>
			<td><button type="button" class="button-link-delete" data-remove-row><?php esc_html_e( 'Remove', 'wp-tournament-manager' ); ?></button></td>
		</tr>
		<?php
	}

	public function handle_save_players() {
		$section_id = isset( $_POST['section_id'] ) ? absint( $_POST['section_id'] ) : 0;
		check_admin_referer( 'wpmtm_save_players_' . $section_id, 'wpmtm_players_nonce' );
		$this->require_capability();

		$section = WPMTM_Repository::get_section( $section_id );
		if ( ! $section ) {
			wp_die( esc_html__( 'Section not found.', 'wp-tournament-manager' ) );
		}
		$tournament = WPMTM_Repository::get_tournament( (int) $section->tournament_id );
		if ( ! $tournament || ! WPMTM_Roles::user_can_manage_tournament( $tournament ) ) {
			wp_die( esc_html__( 'No permission to edit this tournament.', 'wp-tournament-manager' ) );
		}

		global $wpdb;
		$table = WPMTM_Schema::table( 'players' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each $row field is individually sanitized in the foreach loop below; this line only unslashes the raw array.
		$rows    = ( isset( $_POST['players'] ) && is_array( $_POST['players'] ) ) ? wp_unslash( $_POST['players'] ) : array();
		$removed = $this->parse_removed_ids( 'removed_players' );

		$failed_rows = 0;

		// Existing ratings, keyed by player id, so a hand-typed change can
		// be told apart from re-posting the same value untouched (docs/
		// SPEC.md, "Decisions (2026-07-17, rating provenance)"): a TD's
		// edit here is a data correction, not a fresh USCF lookup, so any
		// existing "official" provenance must be cleared when the rating
		// actually changes - left in place otherwise, since $data below
		// never touches rating_source/rating_checked directly and a plain
		// $wpdb->update() leaves unmentioned columns alone.
		$existing_ratings = array();
		foreach ( WPMTM_Repository::get_players( $section_id ) as $existing_player ) {
			$existing_ratings[ (int) $existing_player->id ] = null !== $existing_player->rating ? (string) $existing_player->rating : null;
		}

		foreach ( $rows as $key => $row ) {
			$name = isset( $row['name'] ) ? sanitize_text_field( $row['name'] ) : '';
			// Audit item 65: wpmtm_players.name is varchar(191)
			// (class-wpmtm-schema.php), and an over-length value fails the
			// write under MySQL strict mode with no indication length was
			// the cause - the whole row silently lands in $failed_rows.
			// mb_substr() (not substr()) so a multi-byte character is never
			// split mid-character at the cap, matching
			// WPMTM_Admin::handle_save_tournament()'s own cap on its own
			// varchar(191) fields.
			$name = mb_substr( $name, 0, 191 );

			$mem_id = isset( $row['mem_id'] ) ? preg_replace( '/\D+/', '', sanitize_text_field( $row['mem_id'] ) ) : '';
			$mem_id = substr( $mem_id, 0, 8 );

			$state = isset( $row['state'] ) ? strtoupper( sanitize_text_field( $row['state'] ) ) : '';
			$state = substr( preg_replace( '/[^A-Z]/', '', $state ), 0, 2 );

			$rating = isset( $row['rating'] ) ? preg_replace( '/\D+/', '', sanitize_text_field( $row['rating'] ) ) : '';
			$rating = substr( $rating, 0, 4 );

			// Empty string (the "Active" option) means NULL - reinstating a
			// withdrawn player is always safe, since withdrawing never wrote
			// any game/bye rows (docs/SPEC.md, withdrawals).
			$withdrawn_raw         = isset( $row['withdrawn_after_round'] ) ? sanitize_text_field( $row['withdrawn_after_round'] ) : '';
			$withdrawn_after_round = '' !== $withdrawn_raw ? absint( $withdrawn_raw ) : null;

			// Display-only "family name first" flag: never changes how the
			// Name field above is stored - still LAST,FIRST - only how it
			// is later rendered by WPMTM_Name.
			$family_name_first = ! empty( $row['family_name_first'] ) ? 1 : 0;

			// TD override for WPMTM_Pairing_Suggest::same_family()'s
			// heuristics (docs/SPEC.md, 2026-07-14): free-text, not an
			// email field, so sanitize_text_field() rather than
			// sanitize_email() - a TD may type any shared token for
			// siblings with different surnames, not necessarily an email
			// address. Lowercase/trim so it compares the same way
			// WPMTM_Pairing_Suggest::normalize_family_key() does; blank
			// clears it back to NULL (no key).
			$family_key = isset( $row['family_key'] ) ? strtolower( trim( sanitize_text_field( $row['family_key'] ) ) ) : '';

			$is_existing = ctype_digit( (string) $key );

			if ( '' === $name && ! $is_existing ) {
				continue; // unused blank "add" row.
			}

			$data    = array(
				'mem_id'                => '' !== $mem_id ? $mem_id : null,
				'name'                  => $name,
				'state'                 => '' !== $state ? $state : null,
				'rating'                => '' !== $rating ? $rating : null,
				'withdrawn_after_round' => $withdrawn_after_round,
				'family_name_first'     => $family_name_first,
				'family_key'            => '' !== $family_key ? $family_key : null,
			);
			$formats = array( '%s', '%s', '%s', '%s', '%d', '%d', '%s' );

			if ( $is_existing ) {
				$player_id = (int) $key;
				if ( in_array( $player_id, $removed, true ) ) {
					continue;
				}

				// A hand-typed rating that differs from what's already
				// stored is no longer what USCF said - clear any
				// "official" provenance rather than leave it describing a
				// value it no longer matches. Row not previously found (a
				// stale/tampered id) or the value unchanged (including
				// null-to-null, "no rating before, none typed now"): leave
				// rating_source/rating_checked out of $data entirely so the
				// update leaves them untouched. array_key_exists(), not
				// isset(): an existing row with a NULL rating must still
				// count as "found", which isset() would miss.
				$row_found  = array_key_exists( $player_id, $existing_ratings );
				$old_rating = $row_found ? $existing_ratings[ $player_id ] : null;
				$new_rating = '' !== $rating ? $rating : null;
				if ( $row_found && $new_rating !== $old_rating ) {
					$data['rating_source']  = null;
					$data['rating_checked'] = null;
					$formats[]               = '%s';
					$formats[]               = '%d';
				}
				$result = $wpdb->update( $table, $data, array( 'id' => $player_id, 'section_id' => $section_id ), $formats, array( '%d', '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- custom wpmtm_players table, no core API; $wpdb->update() escapes values via the $formats array. Not a cacheable read.
			} else {
				$data['section_id'] = $section_id;
				$data['pair_num']   = WPMTM_Repository::next_pair_num( $section_id );
				$result = $wpdb->insert( $table, $data, array_merge( $formats, array( '%d', '%d' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom wpmtm_players table, no core API; $wpdb->insert() escapes values via the $formats array.
			}

			if ( false === $result ) {
				++$failed_rows;
			}
		}

		foreach ( $removed as $player_id ) {
			WPMTM_Repository::delete_player_cascade( $player_id, $section_id );
		}
		if ( $removed ) {
			WPMTM_Repository::renumber_players( $section_id );
		}

		// $tournament was fetched from this same section->tournament_id at the
		// top of this handler for the ownership gate; this used to re-fetch it
		// into $section_tournament for no reason (audit item 53).
		WPMTM_Cache::flush_event_page( (int) $tournament->event_post_id );

		$message       = __( 'Players saved.', 'wp-tournament-manager' );
		$notice_type   = 'success';
		$failed_notice = $this->failed_rows_notice( $failed_rows );
		if ( '' !== $failed_notice ) {
			$message    .= ' ' . $failed_notice;
			$notice_type = 'warning';
		}
		$this->set_notice( $notice_type, $message );
		wp_safe_redirect( add_query_arg( array( 'page' => 'wpmtm-edit', 'id' => $section->tournament_id, 'section_id' => $section_id ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
