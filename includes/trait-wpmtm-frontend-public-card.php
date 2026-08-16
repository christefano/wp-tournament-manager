<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Editor-only "player card" for the public event-page tabs (Standings, Wall
 * chart, Pairings). Composed into WPMTM_Frontend_Public.
 *
 * PII rule (docs/SPEC.md; event-tickets-etecf-plugins skill): the card exposes
 * registration data (contact email, the ETECF admin note, the edit-registration
 * link), so it is rendered ONLY for a viewer who can manage THIS tournament -
 * WPMTM_Roles::user_can_manage_tournament(), not a bare WPMTM_CAPABILITY check.
 * For everyone else a name is plain escaped text - no button, no card, and none
 * of that data ever reaches the HTML. When a privileged viewer IS served, the
 * page is marked DONOTCACHEPAGE so a page cache can never store that response
 * and later serve it to the public.
 *
 * Audit item 37: the gate was originally capability-only, which was wrong on a
 * shared multi-club install (docs/SPEC.md, 2026-07-18, per-tournament USCF
 * affiliate ID - one WordPress running several clubs' tournaments). The
 * Standings, Pairings, and Wall chart tabs render for every visitor, so a TD
 * from another club opening this event page got a card button on every row and
 * another club's registrant email and admin note behind each one. Every sibling
 * surface on the same page - the TD command row, the Rounds tab, the setup
 * guide panel - already gated on ownership; this now matches them.
 *
 * The tournament is remembered rather than passed to each render call: the four
 * tab entry points all resolve it and hand it to ensure_attendee_ids_linked()
 * before rendering a single row, while the per-row helpers (player_name_html(),
 * render_player_card()) are called from deep inside the standings/wall-chart/
 * pairings loops, which have players in hand but not the tournament.
 *
 * Popover ids are scoped per tab ($id_prefix), because the same player appears
 * on all three tabs of one event page and a global id would collide and break
 * the native popovertarget wiring.
 */
trait WPMTM_Frontend_Public_Card {

	/**
	 * Popover ids whose card markup has already been written this request, so
	 * render_player_card() emits each card once instead of once per table.
	 * See that method's docblock for why this is keyed by the full prefixed id
	 * rather than by player id alone.
	 *
	 * @var array<string,bool>
	 */
	protected $emitted_card_ids = array();

	/**
	 * Per-request card verdict, keyed by tournament id, plus the id of the
	 * tournament currently being rendered. Keyed rather than a single flag
	 * because one request can render more than one tournament: the
	 * [wpmtm_standings] shortcode can appear several times on one page, each
	 * naming a different tournament, and this class is a singleton shared
	 * across all of them.
	 */
	private $wpmtm_card_verdicts     = array();
	private $wpmtm_card_tournament_id = 0;

	/** Tournament ids whose attendee_id backfill already ran this request. */
	private static $wpmtm_backfilled = array();

	/**
	 * Records which tournament the rows about to be rendered belong to, so the
	 * per-row card helpers can answer viewer_can_see_cards() without each
	 * standings/wall-chart/pairings loop having to thread the tournament down
	 * to them. Called by every entry point before it renders anything.
	 */
	protected function set_card_tournament( $tournament ) {
		$this->wpmtm_card_tournament_id = ( $tournament && ! empty( $tournament->id ) ) ? (int) $tournament->id : 0;

		if ( ! $this->wpmtm_card_tournament_id ) {
			return;
		}
		if ( ! isset( $this->wpmtm_card_verdicts[ $this->wpmtm_card_tournament_id ] ) ) {
			$allowed = WPMTM_Roles::user_can_manage_tournament( $tournament );
			$this->wpmtm_card_verdicts[ $this->wpmtm_card_tournament_id ] = $allowed;
			// Marked here, the moment a PII-bearing render is authorized, so a
			// page cache can never store that response and later serve it to
			// the public. This is the shortcode path's only such guard -
			// WPMTM_Frontend::render_standings_shortcode() deliberately defines
			// nothing itself (see its docblock).
			if ( $allowed && ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- DONOTCACHEPAGE is a well-known cache-control signal constant recognized by W3 Total Cache and other caching plugins, not a constant of ours that needs the WPMTM_ prefix.
			}
		}
	}

	/**
	 * Opportunistically links a tournament's imported players to their
	 * registrations (attendee_id) the first time a card-viewer loads the event
	 * page, so rosters imported before the attendee_id column existed (or from
	 * a CSV) gain the registration fields/links/note in the card without a
	 * manual re-import. Runs at most once per tournament per request, only for
	 * a viewer who can see this tournament's cards, and only when there are
	 * unlinked players.
	 *
	 * Also the single place every tab entry point announces which tournament it
	 * is about to render (set_card_tournament() above), which is why it is
	 * called even on paths that have nothing to backfill.
	 *
	 * Audit item 38: this writes rows during a GET render. It stays here (the
	 * alternative, a manual "link registrations" action, is a worse experience
	 * for a TD who does not know the column exists), but it is now reachable
	 * only by a viewer who can manage this tournament, and the write itself is
	 * one statement per section rather than one per player - see
	 * WPMTM_Repository::backfill_attendee_ids().
	 */
	protected function ensure_attendee_ids_linked( $tournament ) {
		$this->set_card_tournament( $tournament );

		if ( ! $this->viewer_can_see_cards() ) {
			return;
		}
		$tid = $this->wpmtm_card_tournament_id;
		if ( isset( self::$wpmtm_backfilled[ $tid ] ) ) {
			return;
		}
		self::$wpmtm_backfilled[ $tid ] = true;
		if ( WPMTM_Repository::count_unlinked_players( $tid ) > 0 ) {
			WPMTM_Repository::backfill_attendee_ids( $tournament );
		}
	}

	/**
	 * Whether the current viewer may see the editor-only player cards for the
	 * tournament currently being rendered. False until an entry point has
	 * announced a tournament (set_card_tournament()), so a caller that forgets
	 * to fails closed to the public rendering rather than open to PII.
	 */
	public function viewer_can_see_cards() {
		if ( ! $this->wpmtm_card_tournament_id ) {
			return false;
		}
		return ! empty( $this->wpmtm_card_verdicts[ $this->wpmtm_card_tournament_id ] );
	}

	/**
	 * A player name for a tab cell. For a card-viewer it is a button that opens
	 * that player's card (per-tab-scoped popover id); for the public it is the
	 * same plain escaped display name as before, with no button or card.
	 *
	 * @param string $name         Stored LAST,FIRST name.
	 * @param bool   $family_first  Family-name-first display flag.
	 * @param int    $player_id     wpmtm_players id.
	 * @param string $id_prefix     Per-tab popover id prefix (e.g. wpmtm-card-std).
	 * @return string Safe HTML.
	 */
	public function player_name_html( $name, $family_first, $player_id, $id_prefix ) {
		$display = WPMTM_Name::display( $name, ! empty( $family_first ) );
		if ( ! $this->viewer_can_see_cards() ) {
			return esc_html( $display );
		}
		// Reuse wp-etr's own name-button class so the name text and the card
		// below match the event's Registrations-tab player card exactly (that
		// stylesheet is already enqueued on the event page). Falls back to
		// unstyled-but-functional native popover if wp-etr is ever absent.
		return sprintf(
			'<button type="button" class="etr-name-btn wpmtm-name-btn" popovertarget="%s">%s</button>',
			esc_attr( $id_prefix . '-' . (int) $player_id ),
			esc_html( $display )
		);
	}

	/**
	 * The editor-only player-card popover for one player, or '' for the public.
	 * Shows the name/rating from Tournament Manager plus, when the player is
	 * linked to a registration (attendee_id), the ETECF admin note, an
	 * Edit-registration link, and an Email (mailto) - all guarded so a missing
	 * ETECF degrades to nothing. A player with no attendee_id (CSV/manual)
	 * shows a short "no linked registration" note instead.
	 *
	 * Emitted at most ONCE per popover id per request. The Standings table and
	 * the Wall chart share one prefix and sit in the same tab panel, so before
	 * this guard every player's card was rendered twice, byte for byte:
	 * measured at 68,007 wasted bytes on a 49-player event (tournament 55,
	 * "World Chess Day!", 220,250 bytes down to 152,243 - see CHANGELOG.md
	 * 1.5.0's Performance entry, and docs/tm-audit-2026-08-14.md item C1 /
	 * ~/Desktop/Claude/tmp/measure-tm-page-weight.php for how this number is
	 * reproduced). Corrected 2026-08-14 (audit item C1): this said 67,713,
	 * which was docs/SPEC.md's pre-implementation ESTIMATE from the wall
	 * chart's card set alone, carried over here without being re-measured
	 * against the actual shipped fix - a live re-run against the same
	 * tournament now confirms 68,007 is the real number. The second table's
	 * name buttons simply point at the card the first one already emitted.
	 *
	 * The prefix is deliberately NOT unified across tabs. A popover inside a
	 * `display:none` tab panel cannot be shown at all, so a card shared with a
	 * different panel would be a dead button whenever that panel is the hidden
	 * one. Sharing is therefore only safe between blocks rendered into the SAME
	 * panel, which is exactly Standings and its Wall chart.
	 *
	 * @param array  $player    A map_players() row (carries id, name, rating,
	 *                          family_name_first, attendee_id).
	 * @param string $id_prefix Per-panel popover id prefix.
	 * @return string
	 */
	public function render_player_card( array $player, $id_prefix ) {
		if ( ! $this->viewer_can_see_cards() ) {
			return '';
		}
		$pid     = (int) $player['id'];
		$card_id = $id_prefix . '-' . $pid;

		if ( isset( $this->emitted_card_ids[ $card_id ] ) ) {
			return ''; // already on the page; the name button targets that copy.
		}
		$this->emitted_card_ids[ $card_id ] = true;

		$name        = WPMTM_Name::display( $player['name'], ! empty( $player['family_name_first'] ) );
		$attendee_id = ! empty( $player['attendee_id'] ) ? (int) $player['attendee_id'] : 0;
		$rating      = isset( $player['rating'] ) ? (string) $player['rating'] : '';

		$edit_url = $attendee_id ? $this->registration_edit_url( $attendee_id ) : '';
		$mailto   = $attendee_id ? $this->attendee_mailto( $attendee_id ) : '';
		$note     = $attendee_id ? $this->attendee_admin_note( $attendee_id ) : '';

		// The same field list wp-etr's own card shows, read the same way, so the
		// two cards are identical. Only when the player is linked to a
		// registration and wp-etr is present; otherwise fall back to the
		// Tournament Manager rating below.
		$card_fields = ( $attendee_id && class_exists( '\Etr\Plugin' ) && method_exists( \Etr\Plugin::instance(), 'get_attendee_card_fields' ) )
			? (array) \Etr\Plugin::instance()->get_attendee_card_fields()
			: array();

		// Uses wp-etr's own card classes (.etr-card / .etr-card-name /
		// .etr-card-fields / .etr-card-actions / .etr-btn / .etr-card-note) so
		// the card matches the event's Registrations-tab player card exactly -
		// that stylesheet is already on the event page. Same native-popover
		// mechanism as wp-etr's card.
		ob_start();
		?>
		<div class="etr-card wpmtm-card" id="<?php echo esc_attr( $card_id ); ?>" popover aria-label="<?php esc_attr_e( 'Player details', 'wp-tournament-manager' ); ?>">
			<button type="button" class="etr-card-close" popovertarget="<?php echo esc_attr( $card_id ); ?>" popovertargetaction="hide" aria-label="<?php esc_attr_e( 'Close', 'wp-tournament-manager' ); ?>">&times;</button>
			<h4 class="etr-card-name"><?php echo esc_html( $name ); ?></h4>
			<?php if ( ! empty( $card_fields ) ) : ?>
				<dl class="etr-card-fields">
					<?php
					foreach ( $card_fields as $key => $f ) :
						$raw  = (string) get_post_meta( $attendee_id, $key, true );
						$disp = ( ( $f['type'] ?? '' ) === 'checkbox' )
							? ( $raw === '1' ? __( 'Yes', 'wp-tournament-manager' ) : __( 'No', 'wp-tournament-manager' ) )
							: $raw;
						?>
						<dt><?php echo esc_html( ! empty( $f['label'] ) ? $f['label'] : $key ); ?></dt>
						<dd><?php echo $disp !== '' ? esc_html( $disp ) : '&mdash;'; ?></dd>
					<?php endforeach; ?>
				</dl>
			<?php elseif ( '' !== $rating ) : ?>
				<dl class="etr-card-fields">
					<dt><?php esc_html_e( 'Rating', 'wp-tournament-manager' ); ?></dt>
					<dd><?php echo esc_html( $rating ); ?></dd>
				</dl>
			<?php endif; ?>
			<?php if ( $edit_url || $mailto ) : ?>
				<div class="etr-card-actions">
					<?php if ( $edit_url ) : ?>
						<a class="etr-btn" href="<?php echo esc_url( $edit_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Edit registration details', 'wp-tournament-manager' ); ?></a>
					<?php endif; ?>
					<?php if ( $mailto ) : ?>
						<a class="etr-btn" href="<?php echo esc_url( $mailto ); ?>"><?php esc_html_e( 'Email', 'wp-tournament-manager' ); ?></a>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<?php if ( $note !== '' ) : ?>
				<div class="etr-card-note">
					<strong><?php esc_html_e( 'Admin note', 'wp-tournament-manager' ); ?></strong>
					<p><?php echo esc_html( $note ); ?></p>
				</div>
			<?php elseif ( ! $attendee_id ) : ?>
				<div class="etr-card-note">
					<?php
					// Audit item 56: this used to say "Re-import from the event
					// to link", advice that cannot work in the case it appears
					// in most. WPMTM_Repository::backfill_attendee_ids() matches
					// on USCF member id alone and skips an id shared by two
					// registrants, so a player with no member id never links no
					// matter how many times the TD re-imports. Name the actual
					// condition instead of sending them round a loop.
					$has_mem_id = '' !== trim( (string) ( isset( $player['mem_id'] ) ? $player['mem_id'] : '' ) );
					?>
					<?php if ( ! $has_mem_id ) : ?>
						<p class="description"><?php esc_html_e( 'No linked registration. Registrations are matched by USCF ID and this player has none on file, so adding their USCF ID in the roster editor is what links them.', 'wp-tournament-manager' ); ?></p>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'No linked registration (imported from CSV or added manually). Loading this page as a tournament director links it automatically, as long as this player\'s USCF ID matches exactly one registrant.', 'wp-tournament-manager' ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * ETECF front-end edit-registration URL for an attendee's order, or ''.
	 * Guarded so a missing/older ETECF degrades to no link. Mirrors wp-etr's
	 * own etecf_edit_url(); the salted token is minted on this site at render
	 * time (see the event-tickets-etecf-plugins skill).
	 */
	protected function registration_edit_url( $attendee_id ) {
		if ( ! class_exists( '\Etecf\Plugin' ) ) {
			return '';
		}
		$order_id = wp_get_post_parent_id( (int) $attendee_id );
		if ( ! $order_id ) {
			return '';
		}
		$etecf = \Etecf\Plugin::instance();
		if ( ! method_exists( $etecf, 'registration_url_for_order' ) ) {
			return '';
		}
		return (string) $etecf->registration_url_for_order( $order_id );
	}

	/**
	 * A mailto: to the registrant's contact email (attendee email, falling back
	 * to the order purchaser email), seeded with the event name as the subject.
	 * Returns '' when there is no valid email.
	 */
	protected function attendee_mailto( $attendee_id ) {
		$attendee_id = (int) $attendee_id;
		$email = (string) get_post_meta( $attendee_id, '_tec_tickets_commerce_email', true );
		if ( $email === '' ) {
			$order_id = wp_get_post_parent_id( $attendee_id );
			if ( $order_id ) {
				$email = (string) get_post_meta( $order_id, '_tec_tc_order_purchaser_email', true );
			}
		}
		if ( $email === '' || ! is_email( $email ) ) {
			return '';
		}
		$mailto   = 'mailto:' . $email;
		$event_id = (int) get_post_meta( $attendee_id, '_tec_tickets_commerce_event', true );
		if ( $event_id ) {
			$subject = html_entity_decode( (string) get_the_title( $event_id ), ENT_QUOTES, 'UTF-8' );
			if ( $subject !== '' ) {
				$mailto .= '?subject=' . rawurlencode( $subject );
			}
		}
		return $mailto;
	}

	/**
	 * This attendee's ETECF admin note, guarded so a missing/older ETECF
	 * degrades to ''. The card itself is already gated on
	 * WPMTM_Roles::user_can_manage_tournament(), the same ownership check
	 * every other PII-bearing surface in this trait relies on (see this
	 * file's own header), so a card-viewer is authorized to read it.
	 *
	 * Corrected 2026-08-14 (audit item 68): this said "gated on
	 * WPMTM_CAPABILITY", which stopped being true the moment item 37 (this
	 * same file's header note, 2026-08-10) tightened the gate from a bare
	 * capability check to the ownership check above.
	 */
	protected function attendee_admin_note( $attendee_id ) {
		if ( ! class_exists( '\Etecf\Plugin' ) ) {
			return '';
		}
		$etecf = \Etecf\Plugin::instance();
		if ( ! method_exists( $etecf, 'get_attendee_admin_note' ) ) {
			return '';
		}
		return (string) $etecf->get_attendee_admin_note( (int) $attendee_id );
	}
}
