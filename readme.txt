=== The Church Online Bible Reader ===
Contributors: thechurchonline
Tags: bible, scripture, church, reader, kjv, niv, nlt
Requires at least: 5.6
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.9.1
License: GPLv2 or later

A modern, customizable Bible reader plugin with 6 translations, full-text search, AJAX navigation, and fully themeable fonts.

== Description ==

The Church Online Bible Reader turns your WordPress site into a full-featured online Bible with:

* **6 Translations**: NIV, KJV, NKJV, NLT, YLT, GNT
* **All 66 Books** with chapter/verse navigation
* **Full-Text Search** with passage lookup (e.g. "John 3:16")
* **AJAX Navigation** — no page reloads
* **Dark Mode** toggle
* **Customizable** colors, fonts, and layout via admin settings
* **Any Google Font** — pick from a curated list of 30+ families or type your own
* **3 Layout Styles**: Classic, Modern, Minimal
* **Responsive** design for mobile/tablet
* **Font Size** controls
* **Keyboard Navigation** (arrow keys)
* **SQL Importer** for your existing database

== Installation ==

1. Upload the `the-church-online-bible-reader` folder to `/wp-content/plugins/`
2. Activate the plugin in WordPress
3. All 185,000+ verses import automatically on activation
4. Customize colors/fonts at **Bible Reader → Settings**
5. Add `[church_bible]` to any page or post

== Shortcode ==

Basic: `[church_bible]`

With options:
`[church_bible version="3" book="43" chapter="3"]` — Opens to John 3 in KJV
`[church_bible layout="modern"]` — Card-based layout
`[church_bible dark="1"]` — Dark mode by default

== Changelog ==

= 1.9.0 =
* NEW: Pagination — chapters display 7 verses at a time with Previous / Next controls. Now the default Long Chapters mode.
* Paging past the last page moves to the next chapter; paging back from page 1 opens the previous chapter's last page
* Left / Right arrow keys page through verses; Prev / Next chapter buttons still work as before
* Deep links carry the page: #Genesis.1.p3
* "View full chapter" from a verse search opens the page containing that verse
* NEW: Verses per Page setting (default 7) and shortcode attribute per_page="10"
* Scroll box, Continue reading, and Full modes remain available


= 1.8.0 =
* NEW: Automatic updates from GitHub Releases. Every install checks the repo (cached 6h), shows the standard "update available" notice, and updates with one click.
* NEW: "Check for updates" and "Releases on GitHub" links on the Plugins page row
* Added Update URI, Requires at least, Requires PHP headers


= 1.7.1 =
* Settings page redesigned: General / Colors / Fonts tabs, consistent widths, live preview docked beside the color fields, sticky Save bar
* Auto-match colors now enforces contrast (toolbar vs background, text vs background) so dark or low-contrast theme palettes produce a readable reader
* Preview button now renders as a real filled button


= 1.7.0 =
* NEW: Settings → Fonts shows the active WordPress theme's font families with live samples
* NEW: "Auto-match fonts to theme" sets heading and verse fonts from the theme in one click
* NEW: "Inherit page font" option uses whatever font the page already uses (works on any theme)
* NEW: Both font dropdowns have a "Match this website" group listing theme fonts
* Shortcode: heading_font="inherit" or heading_font="theme:<slug>" (same for body_font)
* Google Fonts are only loaded when a Google family is actually selected
* Live preview on Settings now reflects font choices


= 1.6.1 =
* Search field no longer resizes on focus (fixed width)
* All filters, buttons, and navigation are disabled while content is loading and re-enabled when it arrives; prevents changing the reference mid-load
* Network failure while loading now shows a retry message instead of leaving controls locked


= 1.6.0 =
* NEW: Deep links — the reader opens to the reference in the URL hash: #John.3.16, #Genesis.8.15-20, #1+Corinthians.13. Editing the hash or using back/forward navigates too.
* NEW: Clear-all (reset) button in the toolbar returns to the page's default reference and clears search/verse filters
* URL hash is kept in sync as you navigate, so any view can be copied and shared


= 1.5.2 =
* FIX: An interrupted import could leave the verse table empty ("No verses found") because all inserts were held in one transaction. Import is now chunked and resumable: every batch is committed, progress is checkpointed, and WP-Cron / the admin page pick up where it left off.
* NEW: Manage Data shows a live progress bar during import
* Front end shows "Bible data is still loading (xx%)" and auto-refreshes instead of "No verses found" while an import is running
* Data repair no longer falls back to a synchronous truncate-and-import


= 1.5.1 =
* FIX: Dark mode was unreadable (dark text on dark background) since 1.4.0 — color variable scoping regression
* FIX: Data repair could be skipped if the plugin was deactivated/reactivated during the 1.5.0 update; it now verifies the data on every load until confirmed correct
* NEW: Manage Data shows a data-health check and a "Repair Verse Data Now" button


= 1.5.0 =
* CRITICAL FIX: Bundled verse data had book_id and verse_id reversed, so chapters displayed the wrong scripture (e.g. "Genesis 1" showed verse 1 of many different books). Bundled data regenerated correctly.
* Existing installs are repaired automatically on the next admin page load — no re-import needed. A one-time admin notice confirms it ran.
* The manual SQL importer had the same reversal; fixed.
* NEW: Verse dropdown in the toolbar — pick "All verses" or jump to a single verse of the current chapter


= 1.4.1 =
* NEW: "Long Chapters" setting with three modes — Scroll box (default), Continue reading, Full
* NEW: Continue reading mode shows a short preview (default 15 verses) with a one-click expand — no nested scrolling
* NEW: Shortcode attributes `mode="scroll|expand|full"` and `preview="15"`
* Passage searches (e.g. Genesis 8:15-20) always show in full regardless of mode


= 1.4.0 =
* NEW: Settings → Colors shows the active WordPress theme's own palette as clickable swatches
* NEW: "Auto-match reader to theme" maps the theme palette onto the reader in one click
* NEW: 8 color presets (Forest, Slate Neutral, Navy & Gold, Burgundy, Royal Purple, Ocean, Charcoal Modern, Warm Sand)
* NEW: Live preview on the Settings page — see color changes before saving
* NEW: Per-shortcode overrides: theme, primary, secondary, accent, bg, text_color, heading_font, body_font, font_size
* Highlight color now derives from the accent color instead of a fixed green tint
* Color values are validated (hex, rgb/hsl, named) before output


= 1.3.0 =
* FIX: Searching a specific verse (e.g. "Genesis 8:15") now shows ONLY that verse, not the whole chapter
* FIX: Verse ranges (e.g. "Genesis 8:15-20") now work — previously fell through to keyword search and returned nothing
* Range separators accepted: hyphen, en-dash, em-dash, with or without spaces
* Partial-passage view includes a "View full chapter" link
* Clicking a keyword search result now opens that single verse
* NEW: Reader Height setting (default 600px) — long chapters scroll inside the reader instead of stretching the page
* NEW: `height` shortcode attribute, e.g. [church_bible height="400"] or height="auto"
* Theme CSS variables are now scoped to the reader element instead of :root


= 1.1.0 =
* Renamed to "The Church Online Bible Reader"
* Expanded font picker with 30+ curated Google Fonts (serif, sans-serif, display)
* Added "Custom…" option so any Google Fonts family can be entered by name
* Font choices flow through CSS variables for both heading and body type

= 1.0.0 =
* Initial release
