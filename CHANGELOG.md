# Changelog

## 1.5

Double round robins and double quads, new **Pair rounds** and **Enter results** modes, several performance improvements, and quality of life enhancements for TDs.

Updating from v1.4 .1 and earlier is recommended.

As of v1.5, Tournament Manager is considered feature-complete, and new feature development has been paused. The focus now is on stability, bug fixes, security patches, and performance improvements. New feature development will pick up again with Tournament Manager v2.0.

If you have a feature request for v2.0, please [open an issue](https://github.com/christefano/wp-tournament-manager/issues). Co-maintainers welcome!

- Feature: The Rounds tab now splits pairing from results entry into two separate modes, **Pair rounds** and **Enter results**. Round Robins and Quads can still be paired as many rounds ahead as needed, and a Swiss round still waits for the round before it to be fully scored.

- Feature: Round Robin and Quad sections can now run a double cycle.

- Performance: Event page for TDs are about 31% smaller. Player cards were being displayed twice (once for the Standings table and once for the Wall chart), and this has been fixed.

- Enhancement: The round selector marks completed round with a checkmark, so a TD can see which rounds are complete without opening each one.

- Enhancement: The round entry form now shows when a round was last saved in a small line under the Save button, so a TD can tell at a glance whether the last entry went through.

- Enhancement: The "Save"" button now switches to "Update round N" if a round already has games entered instead of always saying "Save".

- Enhancement: "Validate with USCF" on the tournament edit screen now disables itself the moment any field changes, with a note that it checks saved values. Saving re-enables it.

- Enhancement: The "Validate players" button now displays a message when no tournament is linked to the event yet.

- Enhancement: Locking or unlocking a tournament properly returns to the tab it was performed on (instead of the event page's default tab).

- Enhancement: Removing a saved section now asks for confirmation first.

- Bug fix: "Suggest pairings" now works reliably after the first click. Clicking it a second time used to leave the button stuck on "Preparing suggestions..." simply because the page didn't reload. Now reloading the page also jumps straight to the pairing aid, so the boards it just prefilled are displayed right away.

- Bug fix: "Suggest pairings" now works on a round that already has pairings (including previously suggested pairings), so a TD can get a different set of suggestions instead of being stuck with the first one.

- Bug fix: A Quad section is now warned about when it does not hold exactly four players.

- Bug fix: Sections created by an event import's automatic "Split into quads" are now correctly labeled as Quad sections instead of Round Robin sections.

- Bug fix: Grand Prix points are now stored as zero whenever the Grand Prix box is unchecked, which fixes USCF exports if the GP box was unchecked but GP points had been entered.

- Bug fix: A printed pairing sheet for a completed round no longer prints its heading as "Round 3 (completed)".

- Bug fix: TDs used to see "this tournament is locked" after clicking "Suggest pairings" if a Swiss round had an earlier unscored round (even on an unlocked tournament). It now names the actual earlier round that needs a result first.

## 1.4.1

Private test release. Many thanks to everyone's feedback and testing.

Security and bug fixes from a full top-down audit of 1.4, plus performance improvements. Updating from v1.4 is recommended.

- Stability: The tiebreak calculations were re-checked against several sources, including WinTD and the US Chess rulebook. Everything checks out.

- Enhancement: The All Tournaments list is now paged at 50 per page.

- Enhancement: "Validate players" no longer risks timing out on a really big event when checking with the USCF API. "Validate players" now has a time limit, reports which players it got to, and prompts for another click to continue with the rest.

- Enhancement: Round entry errors are now shown as a list instead of one run-on paragraph, which is a big improvement for TDs using a phone or tablet.

- Enhancement: The setup guide now checks tournament ownership instead of trusting the address of the page that the setup guide was displayed on.

- Enhancement: The USCF membership check that runs during event registration now gives up after 3 seconds instead of 10, so a slow USCF API cannot hold up a registration.

- Enhancement: The player card's "no linked registration" note now says what will actually link the player. It used to suggest re-importing (which actually cannot work for a player with no USCF ID).

- Bug fix: Printed pairing sheets show the round number again.

- Bug fix: Confirming a roster import works reliably again, fixing the dreaded "The import could not be completed. Please try confirming again." error.

- Bug fix: Deleting a tournament now also clears its "already exported" state. A leftover state could make a later tournament's setup guide skip its Export step.

- Bug fix: A roster import that could not write a player now reports it instead of counting that player as imported.

- Bug fix: The club affiliate ID on the Settings page and a tournament's own affiliate ID are now validated the same way.

- Performance: A TD loading an event page now runs way fewer database queries. Linking imported players to their registrations is also a single database write instead of one per player.

- Performance: A TD with the "Tournament Manager"" role no longer loads every tournament in the database when viewing the All Tournaments page (even though only their own tournaments were displayed).

- Security fix: Hardened the ownership and access system so that a tournament director can't withdraw or view the player card of a player in another TD's tournament.

## 1.4

Bug fixes, several enhancements, a few security fixes, some internal refactoring, and corrections to the README throughout. Updating from 1.3.1 or earlier is recommended.

- Enhancement: TDs can now click any player's name on an event's Standings, Pairings, or Wall chart tabs to open a player card (like the one on the event's Registrations tab) showing a player's registration details, an Email button to contact them, and any admin notes. The public sees just the names and not any links to player cards.

- Enhancement: TDs can now see admin notes (if there's an `admin_note` field attached to events using ETECF **and** an admin note exists) on the event's attendee list, not just full WordPress administrators. Editing a note still requires an administrator.

- Enhancement: Imported players now remember which registration they came from so a player can be linked back to their registration. Players imported from a CSV or added manually start out unlinked. Tournament Manager links them on its own by USCF ID the next time a TD opens the event page, so no re-import is needed. A player with no USCF ID, or one whose ID matches two registrants, stays unlinked. Their player card still opens, just without the registration links.

- Enhancement: Removing a board that already has a saved result now asks for confirmation since saving the round afterward deletes both players' recorded result for that board.

- Enhancement: The setup guide's Finish step now has a link to draft a tournament recap post (if the tournament is locked and marked as complete).

- Enhancement: Tournament Manager now tells you when a new version is available, using [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker). Updates appear under Dashboard -> Updates and on the Plugins page like any other plugin, and your tournaments, sections, players, and results are untouched by an update. Nothing extra to install. Add `define( 'WPMTM_DISABLE_UPDATE_CHECK', true );` to `wp-config.php` on a development or staging copy that should never check.

- Enhancement: The Pairings tab now shows profile pictures next to each player when the tournament has "Show profile pictures" turned on, matching the Standings and Wall chart tabs. They appear on the printed pairing sheet too, so a TD can match a name to a face at the board.

- Bug fix: Standings tiebreaks now follow the USCF rulebook's 34E rules in four places that they didn't: byes, forfeits, and rounds missed *after a withdrawal* now count as unplayed games. Plus, minus, and even scores are judged against the section's maximum possible score. Sections of nine or more rounds discard two opponent scores per side. Cumulative deducts for an unplayed draw. Players who withdrew or entered late were affected most.

- Bug fix: Re-saving a round no longer drops a withdrawn player's saved bye. If a player was given a bye in a round and then later withdrawn as of an earlier round, that bye is now shown as a read-only "saved bye" line and kept when the round is saved again instead of silently disappearing.

- Bug fix: A withdrawal backdated to before a round the player actually played no longer blanks that player's name out of the round's White / Black dropdowns. The recorded games were always intact, but the round-entry form showed "-- select --" as if the pairing had been lost.

- Security fix: Tournaments created with the one-click "Create tournament" button are now owned by the TD who clicked it. Previously they were created without an owner, which made them manageable by every TD.

- Security fix: The roster import, USCF / CSV export, sections editor, and roster editor handlers now verify that the TD can manage the target tournament. Previously, any user with the Tournament Manager permission could import into, export, or edit the sections and players of another TD's tournament.

- Security fix: CSV export cells that begin with `=`, `+`, `-`, or `@` now get a leading apostrophe so a registrant-supplied name can't be run as a spreadsheet formula when the export is opened.

- Refactoring: Code for the setup guide, the round-entry panel, and the public standings, wall chart, and pairings tabs has been split into smaller files. Several small duplicated blocks have been consolidated into shared helpers. The pairing aid now builds its lookups in a single pass. The section and player editors' Remove buttons now share one event listener instead of binding a separate one to every row.

## 1.3.1

- README changes only (no new features or bug fixes) clarifying CSV export, how to handle disqualifications, and that Tournament Manager can be run locally, on your own server, or on cheap shared hosting.

## 1.3

Many, many bug fixes and UI enhancements, better validation with the USCF API, a new Pairings tab, a "Lock this tournament and mark it as complete" feature, a better TD command bar (Create tournament, Export, Print, etc.) on event pages, and the setup guide has been completely rebuilt.

I hope you enjoy.

Please note: The only game-breaking change when upgrading from an earlier version is that you may need ensure your affiliate ID, chief TD ID, and assistant TD ID (if you have them set) are accurate on the Tournament Manager settings page. The TD's Safe Play certificate status is unchanged. Be sure to update to the latest versions of [ETECF](https://github.com/christefano/wp-etecf) and [ETR](https://github.com/christefano/wp-etr), as well.

On to the updates:

- Updated documentation and screenshots. Who doesn't love screenshots?

- Feature: The setup guide has been massively upgraded. The setup guide's steps are now Settings, Tournament, Roster, Sections, Rounds, Export, and Finish, and they all include hover tips and an info icon link to open a small browser window to the README "Setup guide" section with more detail.

- Feature: New CSV export on a tournament's edit page to save a CSV for historical purposes or to import tournament results (rated and unrated) into desktop tournament software like SwissSys or WinTD.

- Feature: The Rounds tab's pairing aid and round entry are now wrapped in a native `<details>` tag creating an accordian effect that neatly separates the two sections.

- Feature: Tournament Manager can now be used for multiple clubs and their tournaments all in one shared WordPress install, which has been requested by clubs on a tight budget. An individual tournament can now have its own USCF affiliate ID on the tournament edit page, and this overrides the club-wide settings but just for that tournament only.

- Feature: The section name field in the sections editor now suggests the distinct section names that registrants already chose in ETECF fields, e.g. Open, Scholastic Youth Unrated, etc.

- Feature: "Suggest pairings" pairing engine now supports a shared family key (in the tournament's sections editor). Enter a family key to help the pairing engine go out of its way not to pair players who share the same family key. This is done by checking for siblings who share the last name and parents and children based on the purchaser of a ticket and (supposed) parents and children where the parent is a purchaser of an Events Tickets ticket and the child has a parent email attached to their registration via ETECF. This isn't perfect, so check the pairing aid, especially if the ticket purchaser is a group leader or not a parent of children participating in a tournament.

- Feature: The tournaments listed on the All Tournament page now show their current status ("Rounds not yet all entered", "Ready to lock and mark as complete", etc.). **Be sure to lock your tournaments when they've been completed** so their status no longer gets checked or there may be performance issues with dozens or hundreds of tournaments on this page.

- Enhancement: Updated screenshots!

- Enhancement: The setup guide now shows a checkmark and helpful "Status" and "Next step" guidance to help orient newcomers to Tournament Manager.

- Enhancement: The "Show setup guide" / "Exit setup guide" button now sits in the same place on every Tournament Manager admin screen next to the page title.

- Enhancement: The setup guide's final "Finish" step covers locking the tournament from the edit page once results are final to mark it complete and prevent further round edits.

- Enhancement: If the USCF export has warnings due to a expired USCF membership, it now adds a handy link in the warnings text to the player's profile on ratings.uschess.org.

- Enhancement: The "Validate TDs" button has been renamed to "Validate with USCF" and now also checks the club's USCF affiliate status. The check is informational and doesn't block exporting tournament results, so a a TD can renew their credentials immediately after (or even during) the tournament but before submitting results to USCF.

- Bug fix: Clicking on a past or future step in the setup guide now shows what a tournament director (TD) has already done or is supposed to do next. This was previously included in v1.1, but it didn't always work (sometimes it just toggled the setup guide open and closed).

- Bug fix: Editing a tournament with a date that has passed silently unlinked it from the calendar event on save. Oops, sorry! The dropdown now lists past events, too.

- Bug fix: The USCF membership check during registration and ratings update on the event's Registrations tab used to call the USCF MUIR API even before a tournament page exists. Sorry, USCF… This was tested in v1.2 with private testers, and it's been fixed for everyone using v1.3.

- Bug fix: Standings now show tied ranks correctly. The wall chart is unaffected (it already listed players by pairing number, not by rank).

- Performance: Tournaments on the All Tournaments page that have been locked have no performance hit. **Be sure to lock your tournaments when they've been completed** or the All Tournaments page runs 3 database queries per tournament when loading this page.

## 1.2

- Private test release. Features, bug fixes, and performance fixes for chess clubs who've shown interest.

- Feature: New WP-CLI commands, `wp wpmtm validate players|tds|affiliate|all`, accepting --tournament, --event, and --format=json

- Feature: The tournament edit page now shows when TDs were last validated (.eg. "Last checked 5 minutes ago", or "Never checked") next to the "Validate TDs"" button.

- Feature: Automatic USCF membership check for registrants during registration warns the registrant on the order confirmation page when their membership isn't active through the tournament's last day.

- Feature: Real-time rating verification during registration looks up the official USCF rating and writes it into ETECF's rating field (which overwrites their self-entered rating, but this is relevant for the pairing engine if the section they registered for is unrated).

- Feature: DBF export now re-checks TDs and blocks the download when a Chief TD or Assistant TD's USCF membership or Safe Play certification is not current through the last day of the tournament.

- Feature: Round Robin and Quad section rounds now auto-fill from the current roster size (players minus 1 for even, players for odd; Quad stays fixed at 3), recomputing as the roster changes until the TD types an override.

- Performance: Many performance fixes. Fixed the tournament edit page silently hammering the USCF ratings API on every page load (sorry!). The USCF export download now checks on demand during a USCF export download.

## 1.1

- Feature: USCF MUIR API v1 integration verifies club affiliate status, player and TD active memberships, and TD Safe Play certifications
- Feature: Pairing engine tries to avoid pairing siblings and pairing parents with kids (requires ETECF 5.2.5+)
- Bug fix: Enforce DBF width for event names (max 35 characters), city (21 characters), ZIP (10), section name (30), and time control (40) so that DBF uploads don't fail
- Bug fix: Round selector shows the correct number of rounds

## 1.0.1

- Updated README (including more troubleshooting info) and now shows off some screenshots. No functional or new changes, just added documentation.

## 1.0

- First public release