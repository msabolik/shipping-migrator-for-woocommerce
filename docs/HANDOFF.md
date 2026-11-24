# Handoff

Updated by every pull request. The next Claude session starts here. This repository is public: write for any reader.

## State

- Version 2.0.0 (released 2026-11-24): renamed from "WC Shipping Migrator" (slug `wc-shipping-migrator`, prefix `wcsm`) to
  "Shipping Migrator for WooCommerce" (slug and text domain `shipping-migrator-for-woocommerce`, prefix `smfw`), for
  the WordPress.org plugin directory, whose rules keep "WC" out of plugin names and slugs. The export format id stays
  `wc-shipping-migrator`, so files move between 1.x and 2.0. The plugin no longer calls `load_plugin_textdomain()`:
  WordPress loads its translations. `CREDITS.md` stays out of the release zip (Plugin Check expects no such file).
- Checks: Lint on every pull request (`.github/license-check.sh`, PHP syntax on PHP 7.4 and the newest PHP, the
  WordPress Coding Standards with an empty baseline); the Compatibility workflow on the 3rd of every month (tested-up-to
  values, WPCS, WordPress's Plugin Check on the release zip).
- Tested up to WordPress 7.1 and WooCommerce 11.3 (read: the WordPress 7.0 and 7.1 field guides and the WooCommerce
  developer advisories from 9.0 to 11.3; nothing touches the APIs this plugin uses).

## Next steps

1. Release 2.0.0 (the owner's): tag `v2.0.0` and a GitHub release with the zip `git archive` makes.
2. Submission to WordPress.org (the owner's): that zip, uploaded at https://wordpress.org/plugins/developers/add/
   with the owner's WordPress.org account (`msabolik`, the `Contributors` of `readme.txt`). The review can ask for
   changes; the slug is confirmed with the approval. After approval, releases go to the directory's SVN: a workflow
   for that comes once the plugin is approved.
3. WordPress 7.2 is due on 2026-12-08: the next Compatibility run opens the issue "Compatibility check" for it.
4. WooCommerce 11.6 will require PHP 8.1 (developer advisory of 2026-09-29). Raising `Requires PHP`, `Requires at
   least` (6.0) or `WC requires at least` (7.0) is the owner's decision.

## Open questions for the owner

- None.
