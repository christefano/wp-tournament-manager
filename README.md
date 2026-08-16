# Tournament Manager

A free, truly open-source WordPress plugin for running club-level USCF chess tournaments end to end: setup guide, roster import, pairing aid, round-by-round result entry, standings with USCF tiebreaks, USCF DBF export for upload to [ratings.uschess.org](https://ratings.uschess.org), and optional CSV export of tournament results.

No monthly fees and no desktop software needed. Run locally, on your own server, or on cheap shared hosting. You can actually run entire rated and unrated tournaments from your phone using your existing WordPress login, and multiple tournament directors can run multiple tournaments simultaneously.

Tournament Manager was built for the [McMinnville Chess Club](https://macchess.org) as an alternative to online and desktop tournament software, and it's been generalized for clubs that want tournament management on their own WordPress site with unlimited tournaments and unlimited players.

If you find this plugin useful, consider [making a donation](https://macchess.org/donate) to the McMinnville Chess Club!

## Requirements

- WordPress 5.0 or later
- PHP 7.4 or later
- The `zip` PHP extension (php-zip), to build the USCF export download

Along with WordPress itself, Tournament Manager uses several open source plugins for events and registration, and it needs all four of these:

- [The Events Calendar](https://wordpress.org/plugins/the-events-calendar/) (TEC)
- [Event Tickets](https://wordpress.org/plugins/event-tickets/) (ET)
- [Event Tickets Extra Custom Fields](https://github.com/christefano/wp-etecf) (ETECF)
- [Event Tickets Registrations](https://github.com/christefano/wp-etr) (ETR)

Tournament Manager pulls event registrations from Event Tickets (enhanced with extra custom fields from ETECF) straight into a tournament manager with a roster editor, sections editor, pairings, wall charts, standings, and final results. ETECF and ETR are standalone plugins by [the same developer](https://github.com/christefano/) as Tournament Manager (TM), and TM builds on top of them.

## Design decisions, and a little history

The decision to use WordPress for a full-blown tournament management system came
about because the McMinnville Chess Club already uses WordPress for its public
event calendar and event registrations using popular plugins including The Event
Calendar and Event Tickets plugins. (We also decided on WordPress so there's an easy
on-ramp for anyone in our club to be able to volunteer in a meaninful way and create posts, manage the calendar, etc.)

While we could already export our tournament registrations in a clean, simple way into SwissSys, I had an older, proof-of-concept PHP tournament management app l'd created but had put on the shelf, and I wanted to see if it could be refactored to work within WordPress. As it turns out, and with the help of Claude Al, I connected the two systems, and Tournament Manager was born.

By leveraging the WordPress APl and a few popular, free plugins, Tournament Manager inherits capabilities and features I didnt want to reinvent, like calendars and events, event registrations with free and paid tickets, access control for multiple tournament director accounts, and live, web-based results during a tournament.

## Features

**Registration import**

- Bring a roster in from CSV (using the "Pairing export" button on the event's Registrations tab) or pulled straight over with one click using the "Create tournament" button on the event's Registrations tab.

- Players marked "No-show" (in advance of the import) on their player card popup on the event's Registrations tab are skipped and aren't included in the tournament roster.

- A USCF ID can be entered during event registration. Ratings can optionally be automatically updated later with USCF's latest ratings data.

- Tournaments can have sections that are rated or unrated (or a combination of both for a mixed event), and sections can be automatically split into 4-player Quads during import.

- A registrant's details entered during registration are shown to the tournament
  director (TD) in the roster editor.

- Registrants can request a bye using an "Additional info" field during registration.

**Section types**

- Swiss, Round Robin, and Quads are supported. Round Robins and Quads also support doubles where everyone plays everyone else twice with colors reversed.

**Pairing aid and round entry directly on the event page**

- Score groups, color due, and opponents already played for Swiss sections.

- A "Still to play" list is shown in place of score groups for Round Robin sections since
  those pair by a fixed schedule instead of standings.

- Byes and withdrawals: withdrawing a player freezes their score as of the round
  they left and drops them out of future pairing without deleting any of their play
  history. Withdrawals also handle disqualified players for future rounds (see the Troubleshooting section below).

**Standings**

- Standings become visible to everyone on the event page's Standings tab immediately after a TD saves it, and standings can be displayed anywhere via the `[wpmtm_standings]` shortcode (add `tournament="123"`, e.g. `[wpmtm_standings tournament="123"]` to point display a specific tournament).
- Support for all four USCF rulebook 34E tiebreaks, in order: Modified Median, Solkoff, Cumulative, and Cumulative of Opposition. Anyone still tied after all four is genuinely tied, leaving the to outcome to a TD's judgement.

- Note that the event page's Standings tab is the official live view and that a shortcode placed elsewhere can lag behind it until that page's own cache entry expires.
- Unplayed games follow the USCF rulebook. For Modified Median and Solkoff, a bye, a forfeit, or a round missed after a withdrawal counts as 0.5 toward an opponent's score and as 0 toward the player's own. Plus, minus, and even scores are judged against the section's maximum possible score, so a player who withdrew or entered late still lands in the right band. Sections of nine or more rounds discard two opponent scores per side instead of one. Cumulative and Cumulative of Opposition apply their own unplayed-game deductions.

**Pairings**

- Tournament directors can print a pairing sheet, and a Pairings tab on the public event page provides the same information and lets players look up their own board, opponent, and color for the next round. Each section (whether printed or online) shows a board list with board number, White, Black, the result once it's in, and any byes listed.
- A round's pairings show which players are seated at a board or given a bye. Pairings become visible to everyone on the event page's Pairings tab immediately after a TD saves it.
- A tournament director can click a player's name on the Standings, Pairings, or Wall chart tabs to open a player card popup (the public sees plain names only) with a link to edit their registration, email the player, and see any admin notes.

**USCF export**

- Tournament Manager provides a clean export as a zip containing `THEXPORT.DBF`, `TSEXPORT.DBF`, and `TDEXPORT.DBF` files that are ready to upload to [ratings.uschess.org](https://ratings.uschess.org). This is a manual upload in order to keep Tournament Manager lean and mean, but submitting directly to USCF may be added in the future if I get access to the USCF MUIR API v2 currently under development.
- A readiness scan runs a pre-export validator against the tournament's rated sections and helpfully explains errors and warnings. Errors block the USCF and CSV downloads, and warnings don't.
- The readiness scan also re-checks the Chief and Assistant TD's USCF membership and Safe Play certification with the USCF API on download. A lapsed TD or an expired Safe Play certification for a rated tournament will result in an error that blocks the download, since USCF would reject a submission anyway. A player membership problem stays as a warning to give a player time to renew their USCF membership before tournament results get submitted to the USCF.
- If the USCF API can't be reached to check a TD, a notice is displayed but the USCF export doesn't get blocked, so an outage or spotty WiFi at a tournament venue never strands a tournament that has a valid TD.

**CSV export**

- CSV export of tournament results is supported and is in the Tournament Export section on the tournament edit page. The CSV file contains every section's results, rated and unrated alike, ready to import into other tournament software.
- The CSV's readiness scan is the same as the USCF export but skips everything specific to a USCF submission (club affiliate ID, TD IDs, and rating system), so an all-unrated tournament with no club affiliate ID set at all can still export a clean CSV.

**USCF status validation**

- Player registrations are checked automatically during player registration when "Automatically check registrant memberships and ratings with USCF" is checked on the Settings page and the tournament has rated sections. If their USCF membership isn't active through the event's last day, they see a heads up on the order confirmation page but their registration doesn't get blocked.
- If "Automatically check registrant memberships and ratings" is off, checks are skipped entirely. This is also skipped silently if the USCF API is slow or unresponsive, so it doesn't affect a player's registration and checkout experience.
- The same toggle also controls the ratings sync: when on, the registrant's self-entered USCF rating is replaced with the official USCF rating (Regular preferred, Quick as a fallback).
- A "Validate with USCF" button in Settings and on the tournament edit page can check the club affiliate ID plus each TD's membership, TD certification, and Safe Play certification status. Results show PASS or FAIL per person with the reason given ("Expired 2026-01-01", "Not a certified TD", "No Safe Play certification on file") with a "renew soon" heads up when something passes but expires within 30 days of the tournament's last day.
- Other than the DBF export, nothing in Tournament Manager is ever blocked by a validation result. Saving a tournament with a lapsed TD shows a warning and export isn't blocked, which gives a TD time to renew their club affiliate ID, USCF membership, or Safe Play certification.
- The USCF MUIR API v1 isn't officially supported, and it may stop working in the future if gets replaced by the MUIR API v2 currently in development. If it ever misbehaves, turn "This tournament has USCF-rated sections" off on the tournament edit page. The manual "Validate players" and "Validate with USCF" buttons also check on demand regardless of this setting, so a club that turns this off is never without a way to check.

**Settings**

- One Settings page for the USCF club affiliate ID, default Chief TD member ID, Assistant TD member ID, default city / state / ZIP for new tournaments, time control presets, whether registrants are automatically checked (memberships and ratings) with USCF at registration, and whether tournament data is deleted or kept on uninstall (default is off, so data is kept by default).
- "Automatically check registrant memberships and ratings with USCF" is on by default and does two things: it warns a registrant on their ticket order confirmation page if their USCF membership isn't active through the event's last day, and it pulls their official USCF rating (Regular preferred, Quick as a fallback) into the registrant's rating field.

- In each individual tournament's settings, a different club affiliate ID and Chief and Assistant TD IDs can be set, and this allows Tournament Manager to run rated tournaments for multiple clubs. There's a "Use default" button that copies the matching Settings into that field with one click. The DBF export and the TD status checks use what's set on the tournament, and a blank field is genuinely blank.

**FIDE flag**

- Sometimes a tournament is both USCF- and FIDE-rated. In this case, a section can be marked FIDE rated, which passes a flag through to the USCF export header and adds a reminder that FIDE rating is a separate submission that Tournament Manager does not handle. It's a flag only, though, and not a FIDE-format export.

**Profile pictures**

- A per-tournament "Show profile pictures" toggle adds a player profile photo to the public standings and wall chart tables, and the TD's pairing aid.
- Photos are off by default and can be toggled on and off (useful for youth tournaments or if registrants upload inappropriate photos).
- Photos come from the registrant's event registration, are carried in automatically by the one-click "Create tournament" button on an event's Registrations tab, and are saved as first-class media items in the WordPress media library so they can be reused in tournament recap blog posts.
- Players imported from a CSV or with no photo on file get a boring, default profile picture instead.

**WP-CLI**

- `wp wpmtm validate players|tds|affiliate|all`, each accepting `--tournament=<id>` (or `--event=<id>` for players/affiliate) and `--format=json`, runs the same USCF checks but from the command line.
- This is handy for a cron job, a pre-USCF submission sanity check, or if a roster is particularly large.
- Any check that fails exits with a non-zero exit code. If the USCF API is unreachable then TM will say so, but this doesn't by itself fail the command.

**Performance**

- Assuming Tournament Manager is running on a publicly-accessible website, everything the general public sees (pairings, a saved round, a withdrawal, a settings change, etc.) flushes that event page across whichever page caching plugin is active. TD-only pages are never cached. The public always sees a cached page so long as a supported caching plugin is active (W3 Total Cache, WP Super Cache, WP Rocket, and LiteSpeed Cache).

- TM has been performance-tested with up to 200 test players with 5 test rounds. In a test with 100 test players and 5 test rounds, database queries were under 10ms (40ms for the USCF export), memory around 2MB per page load, and compressed page size was 81KB.
- Note that putting hundreds of players in the same section will have a performance cost for logged-in TDs. Tournaments don't really ever have mega sections, though, so this would never happen in reality.
- The All Tournaments page is the only truly heavy page because it checks the status of every tournament (3 database queries per unlocked tournament) and suggests next steps for the TD. For this reason, it's important to **lock tournaments when they're over** so the All Tournaments page doesn't unnecessarily check their status.

**USCF API calls and caching**

The USCF MUIR API v1 behind all of the player membership, club affiliate ID, and TD checks is officially unsupported by USCF, so Tournament Manager deliberately keeps its calls to the API to as few as it can. Here's exactly when a lookup happens and what gets cached:

- An attendee's fields saving (at checkout or on edit) triggers one lookup per registrant, cached for a day. This is a best-effort sync, and player registrations aren't blocked if the API is ever slow or unreachable.
- The tournament edit page makes no API calls at all. It shows the last known result and says so when player or TD status hasn't been checked yet.
- The "Validate players" and "Validate with USCF" buttons call the API on demand and are unaffected by the automatic-checking Settings toggle.
- The USCF export download always checks the TDs and the players with the API in order to block a bad submission.
- Saving a tournament runs one cached check of the TD IDs.
- WP-CLI's `validate` commands are cached by default, so a cron job can't hammer the API on its own. Force a live check with the `--fresh` flag.
- Cache lifetime is one day, and a 404 (usually from a bad or mistyped ID) is cached, too, so a typo doesn't re-hit the API on every click.

**Test player pool**

- Tournament Manager provides a test pool of 50 real, currently-active USCF members with their real USCF IDs and current Regular ratings. These are the highest-rated tournament players, at least according to the USCF ratings API, and every ID and rating was verified individually against the API as of June, 2026.

- Test registrants generated from this pool make it possible to test the same player management, pairings and round entry, USCF membership and rating verification, and export validation that a real roster does.

**Permissions**

- All tournament admin pages require the `wpmtm_manage_tournaments` permission, and administrators are granted it automatically on activation.
- The `wpmtm_manage_tournaments` permission enables simultaneous management of multiple tournaments by multiple TDs without ever showing a TD the tournaments belonging to another TD.
- The Settings page requires the `manage_options` permission.

## Screenshots

A quick tour of a tournament with test data from setup to USCF upload. Click any image for the full-size version. Please note that TM development moves fast, and screenshots may be slightly out of date.

[![Setup guide](screenshots/setup-guide.png)](screenshots/setup-guide.png) _Setup guide - progressively walks through Settings, Tournament, Roster, Sections, Rounds, Export, and Finish, always naming the exact control to click next._

[![Tournament Manager Settings](screenshots/tm-settings.png)](screenshots/tm-settings.png) _Settings - USCF affiliate ID, default chief and assistant TD member IDs, default city / state / ZIP, time control presets, the delete-on-uninstall switch, and the optional "Tournament Manager" role for a volunteer TD._

[![Registrations tab](screenshots/registration-import.png)](screenshots/registration-import.png) _Registrations tab - the event's roster with USCF ID and rating per player, plus Download CSV, Pairing export, Validate players, and the one-click "Create tournament" button to import into Tournament Manager (and switches to "Open tournament" afterward)._

[![Sections editor](screenshots/sections-editor.png)](screenshots/sections-editor.png) _Sections editor - each section carries its own rating system (Swiss, Round Robin, or Quad), time control, round count, and rated flag, so one event can mix rated and unrated sections. (Please note that form elements don't actually overlap in the sections editor and that they do only in this screenshot.)_

[![Sections roster management](screenshots/sections-player-management.png)](screenshots/sections-player-management.png) _Sections editor's registrant manager - Preview and optionally edit the roster, change Withdrawn status, and show Family name first everywhere a name is displayed. (Please note that the form elements don't actually overlap in the registrant manager and that they do only in this screenshot.)_

[![Pairing aid](screenshots/pairing-aid.png)](screenshots/pairing-aid.png) _Pairing aid - score groups with the color due and the opponents already played, so a TD can manually pair each round from or let the "Suggest pairings" button prefill everything._

[![Round entry](screenshots/round-entry.png)](screenshots/round-entry.png) _Round entry - In Pair rounds and Enter results modes, a round selector shows every round at a glance, and set each board's result, assign a bye, or withdraw a player._

[![Standings](screenshots/standings.png)](screenshots/standings.png) _Standings - Standings with all four USCF 34E tiebreaks in order (Modified Median, Solkoff, Cumulative, Cumulative of Opposition), shown live on the event page._

[![Wall chart](screenshots/wall-chart.png)](screenshots/wall-chart.png) _Wall chart - each player's opponent and result with a running score, round by round._

[![USCF export](screenshots/uscf-export.png)](screenshots/uscf-export.png) _CSV and USCF export - The CSV export contains the results for all sections (rated and unrated) and can be imported into other tournament management software. The USCF export runs a readiness scan and validates the rated sections, then downloads the three DBF files (THEXPORT, TSEXPORT, and TDEXPORT) that can each be uploaded to [ratings.uschess.org](https://ratings.uschess.org)._

## Installation

1. Get Tournament Manager from the [releases](https://github.com/christefano/wp-tournament-manager/releases) page, and upload the plugin to `wp-content/plugins/wp-tournament-manager` (or install the zip through Plugins -> Add New -> Upload Plugin).
2. Activate it. Activation creates the plugin's database tables and grants the `wpmtm_manage_tournaments` permission to administrators.
3. To run rated events, visit Tournament Manager -> Settings and enter your club's USCF affiliate ID and TD member IDs before your first export.

## Usage

First, an event for the tournament needs to exist (using The Events Calendar, or TEC), and tickets and registrations need to be enabled for it (using Event Tickets, further enhanced by ETECF). Then a typical TD's first tournament, start to finish, goes like this:

1. **Settings**
- Tournament Manager -> Settings: set the affiliate ID and TD IDs (rated events only), default city / state / ZIP, and time control presets so you don't retype them per section.
2. **Create a tournament**
- Add a tournament, link it to the public calendar event's page, set rated / unrated, and confirm its date range. This is what turns on the pairing aid, pairings, wall charts, results, and standings on the event page itself.
3. **Import the roster**
- Click "Create tournament" right on the event's Registrations tab, or upload the "Pairing export" CSV on the tournament's edit page. Review everything (sections, rated flags, no-shows skipped, any blank USCF IDs), and confirm.
- A "Family name first" option is available to reverse the display of First and Last names for individual registrants.
4. **Enter rounds**
- On the event's page, use the pairing aid under the Rounds tab to pair each round manually or with with the "Suggest pairings" button, then enter results (or byes or a withdrawal) and save. Standings are updated immediately for anyone viewing the page. Pairings are determined by closeness in rating, a player is never paired against the same opponent twice (Swiss), and family members (players sharing a last name or a family key configured in the section editor's registrant manager) are not paired against each other when an alternative exists.
5. **Check standings**
- The event page shows live standings with tiebreaks under the Standings tab.
6. **Export**
- For rated tournaments, the tournament's edit page runs a readiness scan. Review any errors and warnings (e.g. a registrant's USCF ID is missing due to them registering before getting one). Errors block the download, and warnings don't.
- Once it's error-free, download the DBF zip and upload its contents to [ratings.uschess.org](https://ratings.uschess.org).
- USCF export wouldn't make sense for unrated tournaments, and there's a CSV export for historical purposes or to import into other tournament software.

## Setup guide steps

The setup guide on the tournament edit page and on the event page walks a TD through the steps below. The setup guide's "Next step" gives a short version, and more detail is in this README help section.

### Settings step

Under Tournament Manager -> Settings, set at least the club's default USCF affiliate ID and Chief TD ID. Every tournament's own affiliate / TD fields can then copy these in with a "Use default" button instead of having to manually retype them. The city / state / ZIP defaults and time-control presets are optional conveniences here, too.

### Tournament step

Create a tournament with "Add New" under Tournament Manager's admin menu, or use the "Create tournament" button on the event's Registrations tab to create a tournament automatically from Event Tickets registrations.

### Roster step

Round entry, pairings, and standings need a roster to work with. Import the players from the public calendar event using the "Create tournament" button on the event's Registrations tab, or open the tournament's edit page and upload the event's "Pairing export" CSV under Import Registrations. Review the preview (sections, rated flags, no-shows skipped, blank USCF IDs), and confirm.

### Sections step

On the tournament edit page, set each section's number of rounds in the sections editor. Most tournaments run at least 3 rounds. A section imported from the event's own ETECF "Section" field prefills with a suggested name, so this is usually just selecting the tournament type and confirming the round count. A section with no round count set is treated as unconfigured and keeps the guide on this step.

### Rounds step

On the event page's Rounds tab, pair each round with the pairing aid (either manually or with the "Suggest pairings" button), then enter results, byes, or a withdrawal and save. Standings update immediately for anyone viewing the page. Standings rank by score until every round is entered, after which tiebreaks settle final rankings.

### Export step

For a rated tournament, check the standings and wall chart first: any mistakes are cheap to fix now but expensive once USCF has validated the tournament results. Look for a result on the wrong board, a player who withdrew but still shows a bye, or a name or USCF ID that came in wrong during registration.

Then download the USCF export (for rated tournaments) or the CSV export (for unrated tournaments) on the edit page, clear any validator errors, and download. For rated tournaments, upload the contents of the USCF export zip to [ratings.uschess.org](https://ratings.uschess.org). A lapsed Chief TD or expired Safe Play certification blocks the download, and a player membership problem is only a warning and still lets you export.

### Finish step

Finishing is technically optional but it's highly recommended. When the tournament is over, use "Lock the tournament" to close round entry and mark it as complete. This is TM's counterpart to packing up the boards.

A locked tournament puts a "FINAL" badge on the public event page and marks it as complete on the All Tournaments page (and actually makes the All Tournaments page load faster).

Unlock the tournament again if you ever need to correct a result. Sharing results in a blog post is optional, too, but this is common practice. The standings and wall chart are already public on the event page, and the Print button on either gives a print-optimized copy for the club noticeboard.

## Troubleshooting

**How do I update Tournament Manager?**

Tournament Manager uses [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker). A new version shows up under Dashboard -> Updates and on the Plugins page with the usual "Update now" link, and your tournaments, sections, players, and results are untouched by an update.

Checks run a couple of times a day, so a release can take a few hours to appear. Clicking "Check again" on the Updates screen checks immediately.

If you'd prefer not to have version checks, add `define( 'WPMTM_DISABLE_UPDATE_CHECK', true );` to `wp-config.php`. This is worth doing on a development or staging site.

**What's the difference between an event and a tournament?**

An "event" and a "tournament" are two different things managed by two different plugins. The *event* is the public listing that players see and register for using The Events Calendar (TEC), with registration handled by Event Tickets (ET), further enhanced by Event Tickets Extra Custom Fields (ETECF), and Event Tickets Registrations (ETR).

The *tournament* is created by Tournament Manager (TM) and has all the pairing, round entry, standings, and CSV / USCF export data. A *tournament* links to a TEC calendar event: TEC / ET / ETECF / ETR does all the event page and registration, and TM handles everything for actually running the tournament once registration closes.

**What about walk-ins and late entries?**

Tournament Manager doesn't create registrations (Event Tickets does). To add a player after online registration has closed, a TD can register them using the event's front-end registration form with the TD as the ticket purchaser. Set up a 100% off coupon in Event Tickets for zero-cost entries (handy for a cash-at-the-door player, or someone who registered by email or over the phone), and they show up on the event's Registrations tab like anyone else and are ready to import.

Once they're registered, a TD can see them on the event's Registrations tab and edit their details through their registration form link. That link isn't in Tournament Manager: go to "Registration Link Lookup" under Event Tickets Extra Custom Fields -> Tools, and enter the order ID (or an attendee ID, which resolves to the order), and it generates a link.

Note that whether front-end registrations are open at all on the day is decided by Event Tickets' event registration end date ("ticket sell date") **and** ETR's global checkbox for allowing registrations *after* an event has started.

The "+ Add player" button in a section's roster editor is for data corrections, not registrations. A player added that way has no Event Tickets registration behind them, so the automatic USCF membership check at registration never runs for them.

**When should I delete a player instead of marking them Withdrawn?**

Withdrawn is for an ordinary no-show or if a player voluntarily leaves during a tournament. "Remove" in a section's roster editor is a **hard delete** and not a status change.

Use "Remove" only for cleanup, e.g.:

- A duplicate roster row (a re-import, or a manually re-typed row) created a second entry for the same person.
- A player imported into the wrong section (e.g. Open instead of Reserve or vice versa).
- A no-show discovered *after* import. The "No-show" flag only skips a player if it's set *before* import. If a TD finds out after the roster's already in Tournament Manager, deleting is the only way to pull them back out.
- A player cancelled and dropped out after the import. Only a TD would know that, so the roster needs a manual correction.
- A mismerged or badly mistyped record where starting over is simpler than editing five fields.
- A mistaken manual "+ Add player" row.

**What happens if a player being deleted has already played rounds?**

Danger! Deleting a player deletes their opponents' recorded games for those rounds, too, not just the deleted player's own data. A board result data is one shared row, and removing either side of it removes the whole row.

- Every opponent they've already played would have a *missing* result for that round. Re-exporting or re-checking readiness will flag it, and a rated export can be blocked until the TD enters a new result, a bye, or a withdrawal for that opponent.
- Standings and tiebreaks (Modified Median, Solkoff, Cumulative, Cumulative of Opposition) recompute immediately without that round for every opponent affected, and their numbers can change.
- The deleted player disappears from pairings, the wall chart, and standings entirely. Their Event Tickets / ETECF registration is untouched, though, and could reappear on a future re-import.

Tournament Manager shows a confirmation warning before deleting an already-saved player for exactly this reason. **There's no undo** once saved.

**Withdrawn vs. disqualification (DQ)**

Tournament Manager can handle the rare situation of a player who needs to be disqualified.

First, **determine what happened**. Is the disqualified player simply out of the tournament going forward (dress code violation, unsportsmanlike behavior, being chronically late to games, etc.) and therefore excluded from future rounds, or is there proof that cheating occurred during an earlier round? These two scenarios need to be handled differently.

1. In either DQ scenario, open Round entry for the round when the DQ takes effect, set that player to **"Withdraw (out from this round on)"**, and save. That's it for most scenarios. Tournament Manager puts in a `U` (non-participant) for every later round in both standings and USCF export.
2. **Record the reason** for the DQ as an admin note (if an `admin_note` field has been created using Event Tickets Extra Custom Fields, or ETECF). Click "Edit registration details" on the disqualified player's player card to view, add, or edit the admin note. Any TD can view admin notes, and to add or edit them a WordPress administrator is required. This is managed by Event Tickets Extra Custom Fields (ETECF) and not Tournament Manager.
3. **Don't delete a board** to remove the disqualified player (this also deletes the disqualified player's opponent's results), and **don't backdate** with "withdraw after round" earlier than rounds they actually played (this breaks that round's dropdown display, though the underlying data stays intact).

If there's proof that a player cheated during a past game:

1. **Report the cheating separately and in writing to the US Chess office** as an Ethics Committee complaint.
2. Go to the event's Rounds tab, use the round selector to open the affected round (keeping in mind that "Lock the tournament" blocks edits, so the tournament may need to be unlocked first).
3. Find the board and change the Result dropdown to change the result: pick **`W`** if the disqualified player's opponent was White, or pick **`B`** if the disqualified player's opponent was Black. This automatically gives the disqualified player a reciprocal loss.
4. **Don't pick `FW` / `FB`** (forfeit). Per the USCF rulebook, a game where both sides actually made at least one move stays rated even when the loss is for a rules violation. Forfeit codes mark the game unrated, and `W` / `B` keeps it a normal, rated result.
5. Save, and standings recompute immediately off the new result via the existing scoring engine.
6. Repeat per affected round / board only. This is a targeted, one-board-at-a-time edit and not a bulk operation.

**Editing registration data before or after a tournament already has a roster**

Registration data is viewable / editable by the person who paid during registration (perfect for parents), and this is managed by Event Tickets Extra Custom Fields (ETECF) and not Tournament Manager.

Note that once registrations have been imported into Tournament Manager (via CSV or the "Create tournament" button), any later edits a registrant makes to their registration data through ETECF *are* saved and reflected on the Registrations tab but not in Tournament Manager's already-imported roster.

**The "Create tournament" button is missing**

The "Create tournament" button needs ETR 5.2.4 or later. Older ETR versions will still work with Tournament Manager but only through the manual "Pairing export" CSV upload.

**The USCF export worked, but importing to USCF doesn't**

Check that all players in rated sections have active USCF memberships and that your **club's affiliate ID**, your **TD's USCF membership**, and your **TD's Safe Play certifications** are active and up to date. If any of these are incorrect or expired, the USCF import won't work. According to USCF guidelines, a USCF membership must be active *up to and including* the date of the last day of your tournament.

This can be a show-stopper, so Tournament Manager handles a lot of this checking automatically, and it's controlled by one Settings toggle, "Automatically check registrant memberships and ratings with USCF" (on by default). When it's on, player memberships are checked automatically the moment someone registers, but only once a rated tournament is linked to the calendar event (a heads up appears on their order confirmation page if a membership isn't active through the event's last day, and nothing is saved or blocked in the process).

A tournament with no linked calendar event yet, or one linked to an unrated tournament, skips this automatic check entirely since casual unrated nights don't need any USCF setup.

Please note that the USCF MUIR API v1 that Tournament Manager's checks use is unsupported by USCF, and v2 is currently in development. What a fun time for tournament software developers!

**I don't know what is happening, what just happened, or what to do next**

There's a setup guide that walks you through setting up and managing a tournament. Click the "Show setup guide" button.

Tournament Manager explains other things as you go in admin notices, and you might not see them if you have notices hidden with a third-party plugin like [Admin & Site Enhancements](https://wordpress.org/plugins/admin-site-enhancements/) (ASE).

On the upside, you can enable a third-party plugin like ASE to hide notices if you no longer wish to see them. I recommend something like [Unnotifier](https://wordpress.org/plugins/unnotifier/) to dismiss individual notices rather than the all or nothing method that ASE and others use.

## Data

Tournament Manager creates five tables (prefixed with WordPress's table prefix): `wpmtm_tournaments`, `wpmtm_sections`, `wpmtm_players`,
`wpmtm_games`, and `wpmtm_byes`. It also saves the `wpmtm_options` option (affiliate ID, TD IDs, defaults, time control presets, and delete-on-uninstall flag) and the `wpmtm_db_version` option used to run schema upgrades.

The first `m` in `wpmtm` is a nod to the McMinnville Chess Club for which Tournament Manager was originally created.

## Uninstall

Uninstalling always removes:

- `wpmtm_options`, `wpmtm_db_version`, and `wpmtm_role_decision` options.

- Every per-tournament `wpmtm_td_check_{id}` and `wpmtm_exported_{id}` option.

- The `_wpmtm_rating_source` and `_wpmtm_rating_checked` attendee postmeta TM wrote on ETECF's attendee posts.

- TM's cached USCF, registration-warning, and notice transients.

- The `wpmtm_manage_tournaments` permission from every role.

- The optional `wpmtm_tournament_manager` role if it was ever created.

TM drops the five `wpmtm_*` tables only if "On uninstall" in Settings is set to delete tournament data. That setting is off by default, so a club's tournament history survives an accidental deactivate and delete.

## Acknowledgements

Tournament Manager and many of its features wouldn't exist without support from the [McMinnville Chess Club](https://macchess.org) and its members, and Mike Terrill, one of the club's founding members and tournament directors, in particular. Also Mike Nolan for giving me access to the USCF MUIR API v1, John Burroughs for making helpful suggestions and asking sharp questions, and Troy Chambers for his ongoing support and endless enthusiasm. I'm also grateful to my students at [Chess Zendō](https://chesszendo.com) who let me see chess through their eyes and keep me in touch with my love of the game. You are the future of chess.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

GPL v2 or later. See https://www.gnu.org/licenses/gpl-2.0.html
