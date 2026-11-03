# Changelog

## 1.0.5 (2026-11-03)

Hardening round from the 2026-11-03 Dev Team audit. Upload-time behavior is unchanged except where noted; the backfill gets the fixes.

- **Updates require the release zip.** The update checker now only accepts the `omens-wp-plugin-media.zip` asset of a release and skips a release that lacks it. Before, a release with no zip (an upload that failed after the release was created, or one made in the GitHub UI) would have fallen back to GitHub's source tarball, which unpacks to a folder named after the commit; auto-installing that would have deactivated the plugin on every site within a day.
- **Auto-update switch anchored and named.** `omens_media_auto_update()` accepts only this plugin's own release-asset URLs instead of any github.com path containing the name. New `omens/media/auto_update` filter lets a site opt out of unattended updates.
- **Backfill counts real work.** A title that already equals what the plugin would write (one-word filenames with a leading capital, acronyms, sites on `case => none`) is now "already set", not "would fill". Those were the attachments that stayed in the "Would fill" count on every run with nothing to write. `omens_media_fill()` returns a new `written` list of fields actually saved, and `backfill --apply` reports from it, with a warning listing any attachment whose write failed.
- **Scaled and renamed images are recognized.** Core sets the title from the original filename and then renames large images to `-scaled` and colliding ones to `-1`, so the old placeholder test (title equals current filename) saw every large photo as human-edited and left its raw filename as the title. The test now also matches the filename with those suffixes removed, exactly or after slugifying both.
- **File-less attachments are skipped.** An attachment with no `_wp_attached_file` (offloaded media, broken imports) used to have its title fed back in as a filename, which made a human-written title look like a placeholder and re-cased it. The GUID's filename is used when it has one; otherwise the attachment is skipped as `no-file`.
- **Text is sanitized before it is written.** `sanitize_text_field()` on the humanized text, matching what core does on its own alt and title writes, so a filename set by an importer or offload plugin cannot carry markup into `post_title` or alt.
- **SVGs take alt text.** `omens_media_takes_alt()` covers `wp_attachment_is_image()` plus `image/svg+xml`, and is used for the alt write, the `images_only` switch and the media-library column, so SVG logos show "Needs alt text" and non-images (PDFs, video) no longer get alt meta.
- Filenames in any script count as meaningful (`\p{L}`), not only Latin letters.
- `backfill --fields=` with an unknown field name errors instead of silently turning every field off.
- `tools/release.sh` lints every shipped PHP file, requires a `## <version>` CHANGELOG section (used as the release notes), refuses to run off `main` or out of sync with origin, builds the zip in a temp dir and checks its contents, pushes only `main` and the new tag, and publishes draft-then-upload-then-pre-release. New `tools/promote.sh` flips a verified pre-release to the full release and refuses one without its zip asset.
- README and code comments no longer name a client or link to internal documents (the repo is public).

## 1.0.4 (2026-11-02)

- Plugin icon (`assets/icon-128x128.png`, `icon-256x256.png`, `icon.svg`), read by the update checker from the installed plugin's own folder.

## 1.0.3 (2026-11-02)

- Releases apply themselves: an `auto_update_plugin` filter answers yes for this slug when the package is one of our GitHub release assets, so purchased-theme sites that do not run the starter theme's filter still take updates unattended. `PRERELEASE=1 tools/release.sh` for the staging-first cadence. README rewritten for a public repo.

## 1.0.2 (2026-10-02)

- The description on the Plugins screen is written for the people who use the site.
- A copy installed in the wrong folder (GitHub's "Download ZIP" unpacks to `omens-wp-plugin-media-main`) says so on the Plugins screen, with the fix.

## 1.0.1 (2026-09-23)

- `Update URI` header, so wordpress.org never serves updates for this slug.
- The update checker is exposed as `$GLOBALS['omens_media_updater']` for manual probes from WP-CLI.

## 1.0.0 (2026-09-23)

- Omens Media Metadata as `omens-wp-plugin-media`: alt text and title from the filename on upload, caption and description behind filters, the meaningless-filename gate, the media-library alt column, `wp omens-media backfill` and `test`, self-updating from GitHub releases through Plugin Update Checker.
