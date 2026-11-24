# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] — unreleased

### Changed
- Renamed from "WC Shipping Migrator" to "Shipping Migrator for WooCommerce" for
  the WordPress.org plugin directory, whose rules keep "WC" out of plugin names
  and slugs. The plugin folder and main file, the text domain and the admin
  page are now `shipping-migrator-for-woocommerce`, and the code prefix is
  `smfw` (was `wcsm`). A site with 1.x installed deletes it and installs 2.0:
  the plugin stores no settings of its own, so nothing is lost.
- Export files are unchanged and move between 1.x and 2.0 in both directions.

### Removed
- The plugin no longer loads its translations itself: WordPress loads them
  (from translate.wordpress.org) on its own.

## [1.2.1] — 2026-11-24

### Added
- Compatibility with the WooCommerce Cart and Checkout blocks is declared (the
  plugin does not touch them), so WooCommerce no longer lists it as possibly
  incompatible there.
- `readme.txt` in the WordPress plugin format, `SECURITY.md` (how to report a
  vulnerability privately) and `CREDITS.md` (no third-party code is bundled).

### Changed
- Tested with WordPress 7.1 and WooCommerce 11.3 (`WC tested up to: 11.3`).
- The import reads the uploaded file's `$_FILES` entry right after its nonce and
  capability checks and passes it on, instead of reading it in a helper.

## [1.2.0] — 2026-08-26

First public release.

### Added
- Full internationalisation: all user-facing strings are translatable, with a
  `.pot` template in `languages/`.
- `uninstall.php` to clean up the plugin's transient on deletion.
- Explicit WooCommerce HPOS compatibility declaration, so the plugin is no
  longer listed as incompatible under Settings → Advanced → Features.
- Runtime guard and admin notice when WooCommerce is missing or inactive.
- "Open Migrator" shortcut on the Plugins screen.
- Allowlist check on import for general shipping options, so a hand-edited
  export file cannot write arbitrary `wp_options` rows.
- Upload hardening: `is_uploaded_file()` validation and PHP upload error
  reporting.

### Changed
- Split the single-file plugin into a class-per-responsibility structure under
  `includes/`.
- Moved the inline `<style>` block to `assets/css/admin.css`, enqueued only on
  the plugin's own admin screen.
- Nonce values are now unslashed and sanitised before verification.
- `date()` replaced with `gmdate()` for export filenames.
- Capability check added to the page renderer as defence in depth.

### Fixed
- Export filename no longer depends on server timezone configuration.
- Zone 0 methods are no longer duplicated on re-import. Zone 0 always exists,
  so the "Skip existing zones" option cannot protect it; a method is now
  skipped when the zone already contains one of that type. The check runs
  against a snapshot taken before the import loop, so a file that legitimately
  contains two methods of the same type into an empty zone still imports both.

## [1.1.0] — Internal

### Added
- Shipping class cost remapping (`class_cost_XX`) via slug matching.
- Dry-run mode.
- Skip-existing-zones option.
- Opt-in import of general shipping settings.

## [1.0.0] — Internal

Initial working version: export and import of shipping zones, methods,
classes and settings.
