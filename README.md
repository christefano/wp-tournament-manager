# Tournament Manager

A free, open-source WordPress plugin for running club-level USCF tournaments end to end: setup guide, roster import, a manual pairing aid, round-by-round result entry, standings with USCF tiebreaks, and USCF DBF export for upload to [ratings.uschess.org](https://ratings.uschess.org).

Tournament Manager was built for the [McMinnville Chess Club](https://macchess.org) as a free, truly open-source alternative to online and desktop tournament software, and it's been generalized for clubs that want tournament management on their own WordPress site with unlimited tournaments and unlimited players. No monthly fees and no desktop software needed! You can actually run entire rated and unrated tournaments from your phone using your existing WordPress login.

If you find this plugin useful, consider [making a donation](https://macchess.org/donate) to the McMinnville Chess Club!

## Requirements

- WordPress 5.0 or later
- PHP 7.4 or later
- The `zip` PHP extension (php-zip), to build the USCF export download

Tournament Manager runs stand-alone for manual tournament administration, but for the roster import and displaying results and standings on event pages you will need:

- [The Events Calendar](https://wordpress.org/plugins/the-events-calendar/) (TEC)
- [Event Tickets](https://wordpress.org/plugins/event-tickets/) (ET)
- [Event Tickets Extra Custom Fields](https://github.com/christefano/wp-etecf) (ETECF)
- [Event Tickets Registrations](https://github.com/christefano/wp-etr) (ETR)

Tournament Manager pulls in a club's existing online registration from ET (enhanced with extra costom fields from ETECF) and straight into a tournament manager with sections, airings, wall charts, standings, and final results. 

The "Import to Tournament Manager" button on ETR's "Registrations" tab needs ETR 5.2.3 or later. Older ETR versions will still work with Tournament Manager but only through the manual CSV upload.

## Features

**Registration import**

- Bring a roster in from ETR's "Pairing export" CSV, uploaded by hand, or (with ETR 5.2.3+) pulled straight over with one click with the "Import to Tournament Manager" button on the event's "Registrations" tab.
- Players marked "No-show" (in advance of the import) on their player card in ETR are skipped.
- A USCF ID with anything non-numeric (like a parent requesting a new USCF ID for a youth player) is imported with a blank member ID and a alerts the TD to fill it in once USCF issues their new membership.
- Exact-duplicate rows import just once with a warning instead of doubling a player up. The preview page shows every detected section with create-new / map-to-existing / skip choices and a rated toggle before anything is saved, and the importer warns before a re-import would append onto a section that already has players.
- Sections can be marked rated or unrated individually, and an oversized section can be auto-split into 4-player round robin quads at import time.

**Section types**

- Swiss, Round Robin, and Quads are supported. A round robin section still submits to USCF in ordinary Swiss round-by-round format (the USCF TD / Affiliate FAQ treats the round robin grid and the Crenshaw-Berger Swiss-style report as equivalent for rating purposes, so Tournament Manager doesn't need a separate submission format for it).

**Pairing aid and round entry directly on the event page**

- Score groups, color due, and opponents already played for Swiss sections.
- A "Still to play" list is shown in place of score groups for round robin sections since those pair by a fixed schedule instead of standings.
- Byes and withdrawals: withdrawing a player freezes their score as of the round they left and drops them out of future pairing without deleting any of their play history.
- Saving a round replaces that round's results outright, and re-submitting the same form twice is harmless and doesn't duplicate results.

**Standings**

- Support for all four US Chess rulebook 34E tiebreaks, in order: Modified Median, Solkoff, Cumulative, and Cumulative of Opposition, falling back to rating then name.
- Shown on the linked event page automatically and available anywhere via the `[wpmtm_standings]` shortcode (add `tournament="123"` to point at a specific tournament (or leave it off on the event page itself).
- Note that the event page's "Standings" tab is the official live view and that a shortcode placed elsewhere can lag behind it until that page's own cache entry expires.

**USCF export**

- A clean report exports as a single zip containing `THEXPORT.DBF`, `TSEXPORT.DBF`, and `TDEXPORT.DBF` files, ready to upload in the TD / Affiliate area at [ratings.uschess.org](https://ratings.uschess.org). This is a manual upload in order to keep Tournament Manager lean and mean for v1.0, but submitting directly to USCF may be added in the future if I get access to the USCF MUIR API.
- A readiness report runs a pre-export validator against the tournament's rated sections and explains errors and warnings. Errors block the download and warnings don't.

**Settings**

- One Settings page for the USCF affiliate ID, chief and assistant TD member IDs, default city / state / ZIP for new tournaments, time control presets, and whether tournament data is kept or deleted on uninstall (default is off, so data is kept by default).
- Any individual tournament can override the chief or assistant TD ID for that one event, and leaving either blank pulls in the club default's defaults set up in Settings.

**FIDE flag**

- A section can be marked FIDE rated, which passes a flag through to the USCF export header and adds a reminder that FIDE rating is a separate submission Tournament Manager does not handle. It's a flag only, not a FIDE-format export.

**Profile pictures**

- A per-tournament "Show profile pictures" toggle adds a player profile photo to the public standings table, the wall chart, and the TD's pairing aid.
- Photos come from the registrant's event registration (ETR 5.2.3 / ETECF 5.2.3 or newer), are carried in automatically by the one-click "Import to Tournament Manager" button, and are saved in the WordPress media library for reuse across multiple registrations, tournament recap blog posts, and so on.
- Players imported from a CSV or with no photo on file get a neutral silhouette instead.
- A new tournament created from an event defaults the "Show profile pictures" toggle to match that event's own "Show photos" setting.

**Cache-aware**

- Every write that changes what the public sees (a saved round, a withdrawal, a settings change) flushes that event page across whichever page-cache plugin is active (W3 Total Cache, WP Super Cache, WP Rocket, and LiteSpeed Cache.
- TD-only pages are marked so they are never cached publicly.

**Permissions**

- All tournament admin pages require the `wpmtm_manage_tournaments` permission, and administrators are granted automatically on activation.
- The Settings page requires the `manage_options` permission.

## Installation

1. Upload the plugin to `wp-content/plugins/wp-tournament-manager` (or install the zip through Plugins > Add New > Upload Plugin).
2. Activate it. Activation creates the plugin's database tables and grants the `wpmtm_manage_tournaments` permission to administrators.
3. If you plan to run rated events, visit Tournament Manager > Settings and enter your club's USCF affiliate ID and TD member IDs before your first export.

## Usage

First, an event for the tournament needs to be created (using The Events Calendar, or TEC) and tickets and registrations need to be enabled for it (using Event Tickets, further enhanced by ETECF). Then a typical TD's first tournament, start to finish, goes like this:

1. **Settings**

- Tournament Manager > Settings: set the affiliate ID and TD IDs (rated events only), default city / state / ZIP, and time control presets so you don't retype them per section.

2. **Create or link a tournament**

- Add a tournament, link it to the event's page on TEC, and set rated / unrated and confirm its date range. This is what turns on the pairing aid, wall charts, results, and standings on the event page itself.

3. **Import the roster**

- From the tournament's edit page, in click "Import to Tournament Manager" right on the event's Registrations tab or upload ETR's "Pairing export" CSV. Review the preview (sections, rated flags, no-shows skipped, any blank USCF IDs) and confirm.

4. **Enter rounds**

- On the event's page, use the pairing aid to pair each round by hand, then enter results (or byes or a withdrawal) and save. Standings update immediately for anyone viewing the page. There's a "Suggest pairings" link that pairs all the players and populates the pairing add for you to review and save. Pairings are determined by closeness in rating, and a player is never paired against the same player twice.

5. **Check standings**

- The event page shows live standings with tiebreaks.

6. **Export**

- For rated tournaments, the tournament's edit page runs a readiness report. Review errors and warnings. Errors block the download and warnings don't.
- Once it's error-free, download the DBF zip. Upload the zip file's three DBF files at the USCF TD / Affiliate area at [ratings.uschess.org](https://ratings.uschess.org).

## Troubleshooting

**What about walk-ins?**

The moment that event registrations are imported into Tournament Manager, no new entrants can be added. Tournament Manager doesn't go out of its way to handle situations involving walk-ins or late registrations, and your event registration end date ("ticket sell date") is controlled by Event Tickets.

**Multiple player profile support**

Registrations are viewable / editable by the person who paid during registration (perfect for parents), and this is managed by ETECF outside of Tournament Manager. Note that registration data edited *after* event registrations have been imported by CSV or the "Import into Tournament Manager" button *do* get saved but those changes are reflected in ETR and not in Tournament Manager post-import.

**I don't know what is happening, what just happened, or what to do next**

Welcome to the party! Tournament Manager explains things in admin notices, so you might not see them if you have notices hidden with a third-party plugin like [Admin & Site Enhancements](https://wordpress.org/plugins/admin-site-enhancements/). On the upside, you can enable a third-party plugin like ASE to hide notices if you no longer wish to see them. I recommend something like [Unnotifier](https://wordpress.org/plugins/unnotifier/) to dismiss individual notices rather than the all or nothing approach like what's in ASE and others.

There's also an optional setup guide that will walk you through setting up and managing a tournament. If you accidentally dismissed the setup guide admin notice, click the "Show setup guide" button in Tournament Manager Settings for it to reappear.

## Data

Tournament Manager creates five tables (prefixed with your WordPress table prefix): `wpmtm_tournaments`, `wpmtm_sections`, `wpmtm_players`,
`wpmtm_games`, and `wpmtm_byes`. It also saves the `wpmtm_options` option (affiliate ID, TD IDs, defaults, time control presets, delete-on-uninstall flag) and the `wpmtm_db_version` option used to run schema upgrades.

The first `m` in `wpmtm` is a nod to the McMinnville Chess Club for which Tournament Manager was originally created.

## Uninstall

Uninstalling removes `wpmtm_options`, `wpmtm_db_version`, and the `wpmtm_manage_tournaments` permission from every role. Drops the five
`wpmtm_*` tables only if "On uninstall" in Settings is set to delete tournament data. This is off by default, so a club's tournament history survives an accidental deactivate and delete.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

GPL v2 or later. See https://www.gnu.org/licenses/gpl-2.0.html
