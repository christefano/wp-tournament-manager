# Changelog

## 1.3.1

README changes only (no new features or bug fixes) clarifying CSV export, how to handle disqualifications, and that Tournament Manager can be run locally, on your own server, or on cheap shared hosting.

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

- ## 1.2

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