# Handoff

Updated by every pull request. The next Claude session starts here. This repository is public: write for any reader.

## State

- Version 1.2.1 (unreleased): the repository has its rules for Claude (`CLAUDE.md`) and its checks. Lint on every
  pull request: `.github/license-check.sh`, PHP syntax on PHP 7.4 (the header's `Requires PHP`) and the newest PHP,
  the WordPress Coding Standards with an empty baseline (the 8 findings of 1.2.0 are fixed). The Compatibility
  workflow runs on the 3rd of every month.
- 1.2.1 declares compatibility with the Cart and Checkout blocks besides HPOS, and is tested up to WordPress 7.1 and
  WooCommerce 11.3. Read for that: the WordPress 7.0 and 7.1 field guides, and the WooCommerce developer advisories
  from WooCommerce 9.0 to 11.3 (nothing touches the shipping zone, method or class APIs, the shipping zone tables,
  `WC_Cache_Helper` or `FeaturesUtil`; WordPress 7.0 needs PHP 7.4, as this plugin already does).
- `readme.txt` (WordPress format), `SECURITY.md`, `CREDITS.md` (nothing bundled) were added.

## Next steps

1. WordPress 7.2 is due on 2026-12-08: the next Compatibility run opens the issue "Compatibility check" for it.
2. WooCommerce 11.6 will require PHP 8.1 (developer advisory of 2026-09-29). This plugin can keep supporting older
   WooCommerce on PHP 7.4; raising `Requires PHP`, `Requires at least` (6.0) or `WC requires at least` (7.0) is the
   owner's decision.
3. At the release: the date of 1.2.1 in `CHANGELOG.md`, then the owner tags `v1.2.1`.

## Open questions for the owner

- `Contributors` in `readme.txt` is `msabolik`. On WordPress.org it must be the owner's WordPress.org username (only
  matters if the plugin is published there).
