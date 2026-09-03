# The Church Online Bible Reader

WordPress plugin: 6 translations (NIV, KJV, NKJV, NLT, YLT, GNT), 66 books, 185,000+ verses bundled. Shortcode `[church_bible]`.

## Installing on a site
Download `the-church-online-bible-reader.zip` from the latest [Release](https://github.com/TheChurchOnline/bibleapp/releases) and upload it under Plugins → Add New → Upload. After that, updates appear on the Plugins page automatically.

## Publishing a release
1. Edit `the-church-online-bible-reader.php`: bump `Version:` in the header **and** `CBR_VERSION`. Add a changelog entry to `readme.txt`.
2. Commit, then tag and push:
   ```
   git tag v1.8.1
   git push && git push --tags
   ```
3. GitHub Actions builds the zip and publishes the release. Every site sees the update within 6 hours (or immediately via "Check for updates" on the Plugins page).

The workflow refuses to publish if the tag doesn't match the plugin header version.

## Layout
- `the-church-online-bible-reader.php` — bootstrap, activation, data repair, updater wiring
- `includes/` — database, importer (chunked/resumable), AJAX, frontend, admin, GitHub updater
- `assets/` — frontend + admin CSS/JS
- `data/verses.tsv.gz` — bundled verse data (version, book, chapter, verse, text)
