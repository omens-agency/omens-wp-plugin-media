<img src="assets/icon-128x128.png" width="64" height="64" alt="" align="left"> 

# Omens Alt Text

*Named Omens Media Metadata until 1.0.6.*

Turns upload filenames into readable media metadata. Fills **alt text** and **title** on upload, **caption** and **description** only when a project asks for them, and never overwrites a value a human wrote.

Built 2026-08-31 to replace WPGO **SEO Media Manager** on custom builds. The house rule is that 30 lines of owned code beat a 30-feature plugin, and this is about 300. Repo: `github.com/omens-agency/omens-wp-plugin-media` (public, decided 2026-11-02). Plugin folder and slug: `omens-wp-plugin-media`.

## Why it is a plugin, not theme code

Alt text is content. It has to survive a redesign, so it belongs outside the theme. Not an mu-plugin either: mu-plugins skip activation hooks, are not auto-updated, and do not travel with a normal site sync.

## What it does

| Field | Default | Where it is stored |
|---|---|---|
| Alt text | on | `_wp_attachment_image_alt` post meta |
| Title | on | `post_title` |
| Caption | off | `post_excerpt` |
| Description | off | `post_content` |

```
hero_image-01.jpg               ->  Hero Image 01
about-the-studio-1024x768.png   ->  About the Studio
heroImageDark.png               ->  Hero Image Dark
a-day-in-the-life.jpg           ->  A Day in the Life
kitchen_remodel@2x.jpg          ->  Kitchen Remodel
IMG_4523.jpg                    ->  (skipped)
```

## The part that is not just string cleanup

**A meaningless filename produces no alt text at all.** Camera defaults, screenshots, UUIDs, Unsplash hashes and generic names (`IMG_4523`, `DSC01234`, `_MG_1234`, `PXL_20230101_...`, `unnamed.jpg`) are detected and skipped, not formatted.

This is the one place the plugin deliberately does less than the paid one. Alt text of "Img 4523" is worse than empty alt text: a screen reader announces it as though it described the image, so the user gets noise instead of silence and cannot tell the difference. WCAG 2.2 AA is a launch gate for every build, and this is the difference between passing the automated check and actually being accessible.

Skipped files are surfaced rather than buried, in two places: the CLI reports their IDs, and the Media library gets a **Alt text** column that shows "Needs alt text" in red. Those are the images a human still has to write.

## Configuration

Everything is a filter. There is no settings screen, on purpose: the configuration belongs in the project's theme, in version control, not in a database row that does not survive a migration.

```php
// Turn caption on for a photography-led site.
add_filter( 'omens_media/fields', function ( $fields ) {
	$fields['caption'] = true;
	return $fields;
} );

// Teach it the client's vocabulary.
add_filter( 'omens_media/vocabulary', function ( $v ) {
	$v['acronyms']['rsf']  = 'RSF';   // Rancho Santa Fe
	$v['acronyms']['bcca'] = 'BCCA';
	return $v;
} );

// Sentence case instead of Title Case.
add_filter( 'omens_media/fields', fn( $f ) => $f + array( 'case' => 'sentence' ) );

// Images only; leave PDFs and video alone.
add_filter( 'omens_media/fields', fn( $f ) => array_merge( $f, array( 'images_only' => true ) ) );
```

Keep the acronym map conservative. Two-letter entries collide with ordinary words: `la => LA` was tried and removed because it turned a client's La Jolla shoot into "LA Jolla". A wrong expansion is worse than a missing one.

Other filters: `omens_media/meaningless_patterns`, `omens_media/humanize`, `omens_media/alt_column`, and the `omens_media/filled` action.

## Backfilling an existing library

Dry run is the default. Nothing is written until `--apply`.

```bash
wp omens-media backfill                                  # report only
wp omens-media backfill --post_type=image --limit=50     # sample a big library first
wp omens-media backfill --fields=alt --apply             # write alt text only
wp omens-media test hero_image-01.jpg IMG_4523.jpg       # try filenames, touch nothing
```

Safe to re-run: every field is written only when empty, so a second pass tops up what is missing and leaves edited values alone.

## Migrating a site off SEO Media Manager

1. Install this, leave SEO Media Manager active. They do not conflict: both only ever write to empty fields.
2. `wp omens-media backfill` and read the report, particularly the meaningless-filename count.
3. `--apply` when it looks right.
4. Deactivate and delete SEO Media Manager. Existing metadata is plain post data and stays.
5. Record it in the site's decision log. The licence stops when the last site is off, not before.

## Installing and updating on a site

First install is by zip: the latest release asset on GitHub, uploaded at Plugins > Add New > Upload, installed with `wp plugin install <asset-url> --activate`, or pushed through Pressable's plugin installer. After that the plugin updates itself.

Updates come from GitHub releases through Plugin Update Checker (`includes/updates.php`, library vendored in `vendor/`). The repo is public (decided 2026-11-02), so no token is needed; it went public so sites on purchased themes (which never got `OMENS_PLUGIN_UPDATE_TOKEN`) could take it over from SEO Media Manager. If a site defines the token for the private `omens-wp-plugin-*` repos it is still sent.

From 1.0.3 the plugin also **applies** its own updates: an `auto_update_plugin` filter answers yes for this slug when the package is one of our GitHub release assets. The starter theme does the same for every `omens-wp-plugin-*` slug (`library/php/omens.php`); the plugin carries the switch itself so purchased-theme sites get it too. A release lands on every site within about a day through WordPress's update cron. Sites on 1.0.2 or older need one manual update to get the switch; a site that wants to review updates instead opts out with `add_filter( 'omens/media/auto_update', '__return_false' )`.

The switch accepts only this plugin's own release asset (`.../omens-wp-plugin-media/releases/download/...` or the API asset URL), and the update checker is told to require that asset, so a release with no zip attached is skipped rather than replaced by GitHub's source tarball, which unpacks to a folder named after the commit and would deactivate the plugin.

Because releases apply themselves, they are gated: `tools/release.sh <version>` publishes a pre-release, which the update checker ignores. Install that zip by hand on a staging site, check an upload and a `backfill` dry run, then `VERIFIED=1 tools/promote.sh <version>` makes it the full release. See "Releasing".

## Releasing

Every full release applies itself on every site within about a day, so the pipeline is gated and the gate is the default.

1. Write the `## 1.1.0` section in `CHANGELOG.md`. It becomes the release notes WordPress shows under "View version details"; the script refuses to run without it.
2. `tools/release.sh 1.1.0`. Refuses unless you are on `main`, in sync with `origin/main`, with a clean tree. Syntax-checks every PHP file we ship, bumps the header and constant, commits, tags `v1.1.0`, builds the zip from the tag (`tools/`, `branding/` and dotfiles excluded by `.gitattributes`) and checks it contains the main file and `vendor/plugin-update-checker/`, pushes `main` and the tag, then publishes a **pre-release** as a draft, uploads the zip, and un-drafts it, so a release without its zip never exists for the update checker to see.
3. Install the pre-release zip on a staging site (`wp plugin install <asset-url> --force`), upload an image, run `wp omens-media backfill` (dry run).
4. `VERIFIED=1 tools/promote.sh 1.1.0`. Refuses a draft, an already-full release, or a release without the `omens-wp-plugin-media.zip` asset; then flips it to the full, latest release.

`FULL=1 tools/release.sh 1.1.0` skips the gate and publishes the full release at once. Only for a fix that has already run on a staging site, because the fleet applies it unattended.

## Testing

The formatter is pure string work in `includes/format.php` with no WordPress state, so it can be exercised directly:

```bash
wp eval 'echo omens_media_humanize( "hero_image-01.jpg" );'
```

The behavior above was checked against a real client media library (several hundred megabytes of Canon R5 masters plus web derivatives), which is what caught the La Jolla and frame-number bugs. Use a real client library, not invented filenames: invented ones are always too tidy.
