<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sections editor admin surface: adds, edits, and removes a tournament's
 * sections (name, rating system, time control, rounds, type, Grand
 * Prix/scholastic advanced fields, and the Rated flag), plus the save
 * handler. Split out of WPMTM_Admin the same way WPMTM_Admin_Import and
 * WPMTM_Admin_Export are, with the same nonce/capability/escaping
 * discipline. Rendered from WPMTM_Admin::render_tournament_edit() below the
 * tournament form; each row's "Manage (n)" link leads to
 * WPMTM_Admin_Players's per-section players editor.
 *
 * Sections are edited as a single bulk form per screen (a repeater table):
 * existing rows can be edited in place, new rows are added client-side
 * (assets/wpmtm-admin.js), and removing a row either drops an unsaved row
 * from the DOM or flags an existing row for server-side deletion via a
 * hidden "removed_sections" field. One Save submits the whole set.
 */
class WPMTM_Admin_Sections {

	use WPMTM_Admin_Shared;

	private static $instance = null;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_post_wpmtm_save_sections', array( $this, 'handle_save_sections' ) );
	}

	// -----------------------------------------------------------------
	// Sections editor
	// -----------------------------------------------------------------

	public function render_sections_editor( $tournament ) {
		$sections        = WPMTM_Repository::get_sections( $tournament->id );
		$counts          = WPMTM_Repository::player_counts_by_section( $tournament->id );
		$presets         = WPMTM_Plugin::instance()->get_timectl_presets();
		$section_labels  = WPMTM_Admin_Import::instance()->event_section_labels( $tournament->event_post_id );
		?>
		<div class="wrap wpmtm-wrap" id="wpmtm-sections">
			<h2><?php esc_html_e( 'Sections', 'wp-tournament-manager' ); ?></h2>

			<div class="notice notice-info inline">
				<p><?php esc_html_e( 'Each row is one section - a separate group of players, such as Open or Reserve.', 'wp-tournament-manager' ); ?></p>
				<ul>
					<li><?php esc_html_e( '# is assigned automatically and cannot be edited.', 'wp-tournament-manager' ); ?></li>
					<li><?php esc_html_e( 'Per USCF rules, the rating system is derived from the time control (Regular, Quick, Blitz). A warning appears when sections are saved if a rating system and time control do not match.', 'wp-tournament-manager' ); ?></li>
					<li><?php esc_html_e( 'Time control uses USCF notation, e.g. G/30;d0 - start typing to see suggestions from Settings.', 'wp-tournament-manager' ); ?></li>
					<li><?php esc_html_e( 'Rounds is the total number of rounds planned for the section.', 'wp-tournament-manager' ); ?></li>
					<li><?php esc_html_e( 'Type is Swiss by default. Round Robin pairs every player against every other player once. The pairing aid and USCF submission both adapt automatically (see the note under Type when it is selected).', 'wp-tournament-manager' ); ?></li>
					<li><?php esc_html_e( 'Quad is a 4-player round robin, 3 rounds. Use exactly 4 players per Quad section. For a larger field, make several Quad sections of 4, or let the import screen split it into quads automatically.', 'wp-tournament-manager' ); ?></li>
					<li><?php esc_html_e( 'Advanced holds optional Grand Prix and scholastic settings.', 'wp-tournament-manager' ); ?></li>
					<li><?php esc_html_e( 'Rated controls whether the section goes into the USCF export.', 'wp-tournament-manager' ); ?></li>
					<li><?php esc_html_e( '"Manage (n)" opens that section\'s player list, where pairing numbers are assigned automatically by rating.', 'wp-tournament-manager' ); ?></li>
					<li><?php esc_html_e( 'Remove deletes the section, and when saved all of its players, games, and byes are removed, too.', 'wp-tournament-manager' ); ?></li>
				</ul>
			</div>

			<?php if ( $presets ) : ?>
				<datalist id="wpmtm-timectl-presets">
					<?php foreach ( $presets as $preset ) : ?>
						<option value="<?php echo esc_attr( $preset ); ?>"></option>
					<?php endforeach; ?>
				</datalist>
			<?php endif; ?>

			<?php if ( $section_labels ) : ?>
				<datalist id="wpmtm-section-name-suggestions">
					<?php foreach ( $section_labels as $section_label ) : ?>
						<option value="<?php echo esc_attr( $section_label ); ?>"></option>
					<?php endforeach; ?>
				</datalist>
				<p class="description"><?php esc_html_e( 'Section names from the linked calendar event\'s registrations are offered as suggestions while typing the Name field below.', 'wp-tournament-manager' ); ?></p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wpmtm_save_sections_' . $tournament->id, 'wpmtm_sections_nonce' ); ?>
				<input type="hidden" name="action" value="wpmtm_save_sections">
				<input type="hidden" name="tournament_id" value="<?php echo esc_attr( $tournament->id ); ?>">
				<input type="hidden" id="wpmtm-removed-sections" name="removed_sections" value="">

				<table class="wp-list-table widefat fixed striped wpmtm-repeater" id="wpmtm-sections-table" data-wpmtm-repeater data-removed-input="wpmtm-removed-sections" data-wpmtm-remove-confirm="<?php echo esc_attr__( 'Remove this section? Removing a saved section permanently deletes its players, pairings, results, and byes on save. This cannot be undone.', 'wp-tournament-manager' ); ?>">
					<thead>
						<tr>
							<th class="wpmtm-col-num"><?php esc_html_e( '#', 'wp-tournament-manager' ); ?></th>
							<th><?php esc_html_e( 'Name', 'wp-tournament-manager' ); ?></th>
							<th><?php esc_html_e( 'Rating system', 'wp-tournament-manager' ); ?></th>
							<th><?php esc_html_e( 'Time control', 'wp-tournament-manager' ); ?></th>
							<th><?php esc_html_e( 'Rounds', 'wp-tournament-manager' ); ?></th>
							<th><?php esc_html_e( 'Type', 'wp-tournament-manager' ); ?></th>
							<th><?php esc_html_e( 'Advanced', 'wp-tournament-manager' ); ?></th>
							<th><?php esc_html_e( 'Rated', 'wp-tournament-manager' ); ?></th>
							<th><?php esc_html_e( 'Players', 'wp-tournament-manager' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $sections as $section ) : ?>
						<?php $this->render_section_row( $section, $tournament->id, null, isset( $counts[ $section->id ] ) ? $counts[ $section->id ] : 0 ); ?>
					<?php endforeach; ?>
					</tbody>
					<template>
						<?php $this->render_section_row( null, $tournament->id, '__INDEX__' ); ?>
					</template>
				</table>
				<p><button type="button" class="button" data-add-row-for="wpmtm-sections-table"><?php esc_html_e( '+ Add section', 'wp-tournament-manager' ); ?></button></p>
				<p class="description"><?php esc_html_e( 'Unrated sections are never included in the USCF export.', 'wp-tournament-manager' ); ?></p>
				<?php submit_button( __( 'Save Sections', 'wp-tournament-manager' ) ); ?>
			</form>
		</div>
		<?php
	}

	protected function render_section_row( $section, $tournament_id, $index = null, $player_count = 0 ) {
		$is_template = null === $section;
		$key         = $is_template ? $index : $section->id;
		$sec_num     = $is_template ? '' : $section->sec_num;
		$sec_name    = $is_template ? '' : $section->sec_name;
		$r_system    = $is_template ? 'R' : $section->r_system;
		$timectl     = $is_template ? '' : $section->timectl;
		$tot_rnds    = $is_template ? '' : $section->tot_rnds;
		$trn_type    = $is_template ? 'S' : $section->trn_type;
		$cycles      = $is_template ? 1 : WPMTM_Pairing_Aid::normalize_cycles( isset( $section->cycles ) ? $section->cycles : 1 );
		$sch_lvl     = $is_template ? '' : $section->sch_lvl;
		$gr_prix     = ! $is_template && 'Y' === $section->gr_prix;
		$gp_pts      = $is_template ? '' : $section->gp_pts;
		$rated       = $is_template ? true : (bool) $section->rated;

		$players_link = '';
		if ( ! $is_template ) {
			$players_link = add_query_arg( array( 'page' => 'wpmtm-edit', 'id' => $tournament_id, 'section_id' => $section->id ), admin_url( 'admin.php' ) );
		}

		// Auto-set Round Robin / Quad rounds (docs/SPEC.md, "Decisions
		// (2026-07-16, auto-set Round Robin / Quad rounds)"): preview, at
		// render time, exactly what handle_save_sections() would compute
		// if the TD saves this field unchanged - so the number the TD sees
		// is never a surprise. Only offered when the Rounds field is empty
		// (0) or still equals the section's stored auto_rounds_hint (the
		// value we last auto-suggested); a TD-typed value is never
		// overwritten.
		$auto_rounds_note = '';
		if ( ! $is_template && WPMTM_Pairing_Aid::is_round_robin_type( $trn_type ) ) {
			$prev_hint = isset( $section->auto_rounds_hint ) && null !== $section->auto_rounds_hint
				? (int) $section->auto_rounds_hint
				: null;
			$current = (int) $tot_rnds;
			if ( 'Q' === $trn_type || 0 === $current || ( null !== $prev_hint && $prev_hint === $current ) ) {
				$tot_rnds         = WPMTM_Pairing_Aid::suggested_rounds( $trn_type, $player_count, $cycles );
				$auto_rounds_note = $cycles > 1
					? sprintf(
						/* translators: %d: number of players currently in the section */
						__( 'Set automatically for %d players, doubled for two cycles. Change it to override.', 'wp-tournament-manager' ),
						$player_count
					)
					: sprintf(
						/* translators: %d: number of players currently in the section */
						__( 'Set automatically for %d players. Change it to override.', 'wp-tournament-manager' ),
						$player_count
					);
			}
		}

		// Quad size warning: a quad is by definition exactly 4 players, but the
		// Quad type hard-locks its round count to 3 regardless of roster size,
		// so a section with any other count silently under- or over-schedules
		// (an 8-player Quad runs only 3 rounds, not a real round robin). Warn
		// once the section has a roster; an empty new section is not yet wrong.
		$quad_size_warning = '';
		if ( ! $is_template && 'Q' === $trn_type && $player_count > 0 && 4 !== (int) $player_count ) {
			$quad_size_warning = sprintf(
				/* translators: %d: number of players currently in the section */
				__( 'A quad is exactly 4 players. This section has %d. Use Round Robin, or split the field into sections of 4.', 'wp-tournament-manager' ),
				$player_count
			);
		}
		?>
		<tr<?php echo $is_template ? '' : ' data-existing-id="' . esc_attr( $key ) . '"'; ?>>
			<td class="wpmtm-col-num"><?php echo $is_template ? esc_html__( 'auto', 'wp-tournament-manager' ) : esc_html( $sec_num ); ?></td>
			<td><input type="text" list="wpmtm-section-name-suggestions" maxlength="30" name="sections[<?php echo esc_attr( $key ); ?>][sec_name]" value="<?php echo esc_attr( $sec_name ); ?>" placeholder="<?php esc_attr_e( 'e.g. Open', 'wp-tournament-manager' ); ?>"></td>
			<td>
				<select name="sections[<?php echo esc_attr( $key ); ?>][r_system]">
					<?php
					$systems = array(
						'R' => __( 'Regular', 'wp-tournament-manager' ),
						'Q' => __( 'Quick', 'wp-tournament-manager' ),
						'B' => __( 'Blitz', 'wp-tournament-manager' ),
					);
					foreach ( $systems as $code => $label ) :
						?>
						<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $r_system, $code ); ?>><?php echo esc_html( $label . ' (' . $code . ')' ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td><input type="text" list="wpmtm-timectl-presets" maxlength="40" name="sections[<?php echo esc_attr( $key ); ?>][timectl]" value="<?php echo esc_attr( $timectl ); ?>" placeholder="G/30;d0"></td>
			<td>
				<input type="number" min="0" max="99" class="small-text" name="sections[<?php echo esc_attr( $key ); ?>][tot_rnds]" value="<?php echo esc_attr( $tot_rnds ); ?>">
				<?php if ( '' !== $auto_rounds_note ) : ?>
					<p class="description wpmtm-auto-rounds-hint"><?php echo esc_html( $auto_rounds_note ); ?></p>
				<?php endif; ?>
			</td>
			<td>
				<select name="sections[<?php echo esc_attr( $key ); ?>][trn_type]" data-wpmtm-trn-type>
					<option value="S" <?php selected( $trn_type, 'S' ); ?>><?php esc_html_e( 'Swiss', 'wp-tournament-manager' ); ?></option>
					<option value="R" <?php selected( $trn_type, 'R' ); ?>><?php esc_html_e( 'Round Robin', 'wp-tournament-manager' ); ?></option>
					<option value="Q" <?php selected( $trn_type, 'Q' ); ?>><?php esc_html_e( 'Quad (4-player)', 'wp-tournament-manager' ); ?></option>
					<option value="TS" disabled><?php esc_html_e( 'Team Swiss (not yet supported)', 'wp-tournament-manager' ); ?></option>
					<option value="M" disabled><?php esc_html_e( 'Match (not yet supported)', 'wp-tournament-manager' ); ?></option>
				</select>
				<?php if ( '' !== $quad_size_warning ) : ?>
					<p class="description wpmtm-quad-size-warning"><?php echo esc_html( $quad_size_warning ); ?></p>
				<?php endif; ?>
				<p class="wpmtm-rr-hint" data-wpmtm-rr-hint <?php echo in_array( $trn_type, WPMTM_Pairing_Aid::RR_TYPES, true ) ? '' : 'hidden'; ?>>
					<label>
						<?php esc_html_e( 'Cycles', 'wp-tournament-manager' ); ?>
						<select name="sections[<?php echo esc_attr( $key ); ?>][cycles]" data-wpmtm-cycles>
							<option value="1" <?php selected( $cycles, 1 ); ?>><?php esc_html_e( 'Single (play everyone once)', 'wp-tournament-manager' ); ?></option>
							<option value="2" <?php selected( $cycles, 2 ); ?>><?php esc_html_e( 'Double (play everyone twice, colors reversed)', 'wp-tournament-manager' ); ?></option>
						</select>
					</label>
				</p>
			</td>
			<td class="wpmtm-col-advanced">
				<details>
					<summary><?php esc_html_e( 'Advanced', 'wp-tournament-manager' ); ?></summary>
					<div class="wpmtm-advanced-panel">
						<p><label><input type="checkbox" name="sections[<?php echo esc_attr( $key ); ?>][gr_prix]" value="1" <?php checked( $gr_prix ); ?> data-wpmtm-gr-prix> <?php esc_html_e( 'Grand Prix', 'wp-tournament-manager' ); ?></label></p>
						<p><label><?php esc_html_e( 'GP points', 'wp-tournament-manager' ); ?> <input type="number" min="0" max="999" class="small-text" name="sections[<?php echo esc_attr( $key ); ?>][gp_pts]" value="<?php echo esc_attr( $gp_pts ); ?>" data-wpmtm-gp-pts <?php disabled( ! $gr_prix ); ?>></label></p>
						<p><label><?php esc_html_e( 'Scholastic level', 'wp-tournament-manager' ); ?> <input type="text" maxlength="1" class="small-text" name="sections[<?php echo esc_attr( $key ); ?>][sch_lvl]" value="<?php echo esc_attr( $sch_lvl ); ?>"></label></p>
					</div>
				</details>
			</td>
			<td><label><input type="checkbox" name="sections[<?php echo esc_attr( $key ); ?>][rated]" value="1" <?php checked( $rated ); ?>> <?php esc_html_e( 'Rated', 'wp-tournament-manager' ); ?></label></td>
			<td>
				<?php if ( ! $is_template ) : ?>
					<a href="<?php echo esc_url( $players_link ); ?>">
						<?php
						printf(
							/* translators: %d: number of players in the section */
							esc_html__( 'Manage (%d)', 'wp-tournament-manager' ),
							(int) $player_count
						);
						?>
					</a>
				<?php endif; ?>
			</td>
			<td><button type="button" class="button-link-delete" data-remove-row><?php esc_html_e( 'Remove', 'wp-tournament-manager' ); ?></button></td>
		</tr>
		<?php
	}

	public function handle_save_sections() {
		$tournament_id = isset( $_POST['tournament_id'] ) ? absint( $_POST['tournament_id'] ) : 0;
		check_admin_referer( 'wpmtm_save_sections_' . $tournament_id, 'wpmtm_sections_nonce' );
		$this->require_capability();

		$tournament = WPMTM_Repository::get_tournament( $tournament_id );
		if ( ! $tournament ) {
			wp_die( esc_html__( 'Tournament not found.', 'wp-tournament-manager' ) );
		}
		if ( ! WPMTM_Roles::user_can_manage_tournament( $tournament ) ) {
			wp_die( esc_html__( 'No permission to edit this tournament.', 'wp-tournament-manager' ) );
		}

		global $wpdb;
		$table = WPMTM_Schema::table( 'sections' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each $row field is individually sanitized (sanitize_text_field()/absint()) in the foreach loop below; this line only unslashes the raw array.
		$rows    = ( isset( $_POST['sections'] ) && is_array( $_POST['sections'] ) ) ? wp_unslash( $_POST['sections'] ) : array();
		$removed = $this->parse_removed_ids( 'removed_sections' );

		$mismatched  = array();
		$failed_rows = 0;

		foreach ( $rows as $key => $row ) {
			$sec_name = isset( $row['sec_name'] ) ? sanitize_text_field( $row['sec_name'] ) : '';
			// Audit item 65: wpmtm_sections.sec_name is varchar(191)
			// (class-wpmtm-schema.php); cap it the same way
			// class-wpmtm-admin-players.php now caps player names, so an
			// over-length section name fails cleanly at this point rather
			// than tripping MySQL strict mode on the write with no
			// indication length was the cause.
			$sec_name = mb_substr( $sec_name, 0, 191 );
			$r_system = isset( $row['r_system'] ) ? strtoupper( sanitize_text_field( $row['r_system'] ) ) : 'R';
			if ( ! in_array( $r_system, array( 'R', 'Q', 'B' ), true ) ) {
				$r_system = 'R';
			}
			$timectl  = isset( $row['timectl'] ) ? sanitize_text_field( $row['timectl'] ) : '';
			$tot_rnds = isset( $row['tot_rnds'] ) ? max( 0, absint( $row['tot_rnds'] ) ) : 0;
			// Swiss, Round Robin, and Quad are the real selectable values in
			// this version (docs/SPEC.md, "Decisions (2026-07-09, round
			// robin and quads)" and "Decisions (2026-07-10, quads
			// selectable)"); the other <option>s in the Type select are
			// rendered disabled, so any other posted value is a
			// tampered/stale form and falls back to Swiss.
			$trn_type = isset( $row['trn_type'] ) ? strtoupper( sanitize_text_field( $row['trn_type'] ) ) : 'S';
			if ( 'S' !== $trn_type && ! WPMTM_Pairing_Aid::is_round_robin_type( $trn_type ) ) {
				$trn_type = 'S';
			}
			// Cycles only mean anything for a round-robin-like section, so a
			// Swiss section is always stored as a single cycle no matter what
			// the form posted. normalize_cycles() clamps anything else.
			$cycles = WPMTM_Pairing_Aid::is_round_robin_type( $trn_type )
				? WPMTM_Pairing_Aid::normalize_cycles( isset( $row['cycles'] ) ? $row['cycles'] : 1 )
				: 1;
			$sch_lvl  = isset( $row['sch_lvl'] ) ? sanitize_text_field( $row['sch_lvl'] ) : '';
			$sch_lvl  = '' !== $sch_lvl ? strtoupper( substr( $sch_lvl, 0, 1 ) ) : null;
			$gr_prix  = ! empty( $row['gr_prix'] ) ? 'Y' : 'N';
			// GP points only mean anything for a Grand Prix section, so a
			// section with the box unchecked is always stored at 0 no matter
			// what the form posted. Without this the two Advanced fields could
			// contradict each other all the way into the DBF - S_GR_PRIX 'N'
			// alongside a non-zero S_GP_PTS - which is exactly the sort of
			// inconsistency the export validator would have to explain later.
			// Same "the flag decides, the detail follows" rule the cycles field
			// above uses for Swiss sections.
			$gp_pts   = ( 'Y' === $gr_prix && isset( $row['gp_pts'] ) ) ? max( 0, absint( $row['gp_pts'] ) ) : 0;
			$rated    = ! empty( $row['rated'] ) ? 1 : 0;
			// No FIDE support (owner decision 2026-07-10, docs/SPEC.md
			// "FIDE flag passthrough - REVERTED"). Always 'N'; the 'fide'
			// schema column stays in place but is dormant.
			$fide     = 'N';

			if ( '' === $sec_name && '' === $timectl && 0 === $tot_rnds && ! ctype_digit( (string) $key ) ) {
				continue; // an unused blank "add" row.
			}

			$derived = WPMTM_Plugin::derive_r_system( $timectl );
			if ( null !== $derived && $derived !== $r_system ) {
				$mismatched[] = '' !== $sec_name ? $sec_name : ( '#' . $key );
			}

			// Auto-set Round Robin / Quad rounds (docs/SPEC.md, "Decisions
			// (2026-07-16, auto-set Round Robin / Quad rounds)"). Swiss
			// sections never auto-fill and always clear the hint.
			//
			// Round Robin only auto-fills when the posted Rounds field is
			// empty (0) or still equals the value we last auto-suggested for
			// this section (auto_rounds_hint), so a TD's deliberate override
			// is never clobbered, but an untouched auto-filled value keeps
			// tracking the roster as it changes.
			//
			// Corrected 2026-08-14 (audit item 63): this comment used to
			// describe Quad the same way, which the code never did - the
			// leading 'Q' === $trn_type clause always auto-fills, with no
			// override check at all. That is deliberate: a quad is fixed at
			// 3 rounds (x cycles) by definition regardless of player count,
			// so there is no "TD's deliberate override" to protect - any
			// value a quad section carries other than 3 x cycles is stale by
			// construction and worth overwriting on every save.
			$existing_section = ctype_digit( (string) $key ) ? WPMTM_Repository::get_section( (int) $key ) : null;
			$auto_rounds_hint = null;
			if ( WPMTM_Pairing_Aid::is_round_robin_type( $trn_type ) ) {
				$prev_hint = ( $existing_section && null !== $existing_section->auto_rounds_hint )
					? (int) $existing_section->auto_rounds_hint
					: null;
				if ( 'Q' === $trn_type || 0 === $tot_rnds || ( null !== $prev_hint && $prev_hint === $tot_rnds ) ) {
					$player_count     = $existing_section ? WPMTM_Repository::count_players( (int) $existing_section->id ) : 0;
					$tot_rnds         = WPMTM_Pairing_Aid::suggested_rounds( $trn_type, $player_count, $cycles );
					$auto_rounds_hint = $tot_rnds;
				}
			}

			$data    = array(
				'sec_name'         => $sec_name,
				'r_system'         => $r_system,
				'timectl'          => $timectl,
				'trn_type'         => $trn_type,
				'tot_rnds'         => $tot_rnds,
				'auto_rounds_hint' => $auto_rounds_hint,
				'cycles'           => $cycles,
				'sch_lvl'          => $sch_lvl,
				'gr_prix'          => $gr_prix,
				'gp_pts'           => $gp_pts,
				'fide'             => $fide,
				'rated'            => $rated,
			);
			// Positional: this list must stay in the same order as $data above.
			$formats = array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%d' );

			if ( ctype_digit( (string) $key ) ) {
				$section_id = (int) $key;
				if ( in_array( $section_id, $removed, true ) ) {
					continue;
				}
				$result = $wpdb->update( $table, $data, array( 'id' => $section_id, 'tournament_id' => $tournament_id ), $formats, array( '%d', '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- custom wpmtm_sections table, no core API; $wpdb->update() escapes values via the $formats array. Not a cacheable read.
			} else {
				$data['tournament_id'] = $tournament_id;
				$data['sec_num']       = WPMTM_Repository::next_sec_num( $tournament_id );
				$result = $wpdb->insert( $table, $data, array_merge( $formats, array( '%d', '%d' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom wpmtm_sections table, no core API; $wpdb->insert() escapes values via the $formats array.
			}

			if ( false === $result ) {
				++$failed_rows;
			}
		}

		// This handler writes wpmtm_sections with its own $wpdb calls in the
		// loop above, so it drops the repository's per-request read memo itself
		// (audit item 48). delete_section_cascade() and renumber_sections()
		// below flush again on their own.
		WPMTM_Repository::flush_memo();

		foreach ( $removed as $section_id ) {
			WPMTM_Repository::delete_section_cascade( $section_id, $tournament_id );
		}
		if ( $removed ) {
			WPMTM_Repository::renumber_sections( $tournament_id );
		}

		WPMTM_Cache::flush_event_page( (int) $tournament->event_post_id );

		$notice_parts = array();
		if ( $mismatched ) {
			// Section names link back to the sections editor on this same
			// page (the notice renders above it) so a TD can jump straight
			// to fixing the mismatch instead of scrolling to find it.
			$sections_url = esc_url( add_query_arg( array( 'page' => 'wpmtm-edit', 'id' => $tournament_id ), admin_url( 'admin.php' ) ) . '#wpmtm-sections' );
			$notice_parts[] = sprintf(
				/* translators: %s: comma-separated list of section names, linked to the sections editor */
				__( 'Sections saved, but the declared rating system does not match the time control for: %s. Double-check before exporting a rated tournament.', 'wp-tournament-manager' ),
				'<a href="' . $sections_url . '">' . esc_html( implode( ', ', $mismatched ) ) . '</a>'
			);
		} else {
			$notice_parts[] = __( 'Sections saved.', 'wp-tournament-manager' );
		}
		$failed_notice = $this->failed_rows_notice( $failed_rows );
		if ( '' !== $failed_notice ) {
			$notice_parts[] = $failed_notice;
		}
		$notice_type = $failed_rows > 0 ? 'warning' : ( $mismatched ? 'warning' : 'success' );
		// The mismatch branch above builds real markup (a linked section-name
		// list), so it goes through the notice pipeline's HTML path. That path
		// emits the message as-is - see WPMTM_Admin_Shared::render_notices() -
		// so supply the paragraph wrapper here rather than relying on it.
		$notice_message = $mismatched
			? '<p>' . implode( ' ', $notice_parts ) . '</p>'
			: implode( ' ', $notice_parts );
		$this->set_notice( $notice_type, $notice_message, (bool) $mismatched );

		wp_safe_redirect( add_query_arg( array( 'page' => 'wpmtm-edit', 'id' => $tournament_id ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
