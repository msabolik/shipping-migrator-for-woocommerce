# WC Shipping Migrator

Move WooCommerce shipping zones, methods, classes and their settings from one site to another — without rebuilding them by hand, and without the per-class costs silently pointing at the wrong classes.

![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b)
![WooCommerce](https://img.shields.io/badge/WooCommerce-7.0%2B-96588a)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4)
![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)

---

## The problem

WooCommerce has no built-in export for shipping configuration, and the reason it's awkward to add one is that the data lives in three different places:

| What | Where it's stored |
|---|---|
| Shipping classes | Terms in the `product_shipping_class` taxonomy |
| Zones, locations, methods | Custom tables (`woocommerce_shipping_zone*`) |
| Method instance settings | `wp_options`, keyed `woocommerce_{method}_{instance_id}_settings` |

A generic content exporter copies posts and terms. It doesn't touch custom tables, and even if it did, the instance IDs would collide with whatever already exists on the target.

**The part that actually bites is shipping class costs.** A flat rate method stores per-class costs as settings keys shaped like this:

```
class_cost_42  =>  "5.00"
```

`42` is the *term ID* of a shipping class **on the source site**. Term IDs come from an auto-increment column, so they're meaningless anywhere else — class 42 on the source might be a different class on the target, or might not exist at all.

Copy those settings verbatim and you get a shipping method that charges the wrong rates. It doesn't error. It doesn't warn. It just quietly bills customers incorrectly until somebody notices.

## How this solves it

Slugs are stable across installations; term IDs aren't. So the plugin exports classes together with their source term IDs, then resolves costs through a two-hop lookup on import:

```
old term ID  →  slug          (from the export file)
slug         →  new term ID   (from the target site)
```

Every rewritten key is reported in the import log:

```
↔ class_cost_42 → class_cost_17 (class "fragile", cost: 5.00)
↔ class_cost_51 → class_cost_23 (class "bulky", cost: 12.00)
⚠ class_cost_88 (class "cold-chain") — no matching class on target site, kept as-is
```

Keys that can't be resolved are **left untouched and flagged** rather than dropped. Dropping them would silently delete a pricing rule; leaving them means the mismatch is visible in the WooCommerce UI and can be fixed by hand.

## What it handles

- **Shipping classes** — the class definitions themselves (name, slug, description), created by slug; existing ones are left alone
- **Shipping zones** — name, sort order and all location rules (country, state, postcode, continent)
- **Zone 0** — the implicit "Locations not covered by your other zones" zone, whose methods are usually a store's fallback rates and are easy to lose in a migration. Guarded against duplication on re-import, since "Skip existing zones" cannot apply to a zone that always exists
- **Shipping methods** — every instance with its full settings, enabled state and sort order
- **Shipping class costs** — remapped as described above
- **General shipping options** — opt-in, off by default
- **Cache invalidation** — WooCommerce shipping transients are flushed after import

## What it deliberately doesn't do

**It never deletes anything.** The import is purely additive. A migration tool that can wipe a live store's shipping setup is a liability, so the destructive half was left out.

**General settings are opt-in.** These options control which countries the store ships to. Importing them by default could stop a live store selling to its own market, so the checkbox starts unchecked.

## Requirements

- WordPress 6.0 or later
- WooCommerce 7.0 or later
- PHP 7.4 or later

## Installation

1. Download the latest release `.zip` from [Releases](https://github.com/msabolik/wc-shipping-migrator/releases)
2. **Plugins → Add New → Upload Plugin**
3. Activate
4. **WooCommerce → Shipping Migrator**

Or clone straight into your plugins directory:

```bash
cd wp-content/plugins
git clone https://github.com/msabolik/wc-shipping-migrator.git
```

## Usage

### Export

Go to **WooCommerce → Shipping Migrator** on the source site and click **Export Shipping Settings**. You get a JSON file named for the source host and timestamp, for example:

```
wc-shipping-export-shop-example-com-2026-08-26-141530.json
```

The file is pretty-printed so you can read and diff it before importing. It contains your store's shipping configuration and its URL — treat it as you would any other configuration backup and don't commit it to a repository.

### Import

On the target site, go to the same screen, upload the file, and choose your options:

| Option | Default | What it does |
|---|---|---|
| **Dry run** | ✅ On | Produces the full log without writing anything |
| **Skip existing zones** | ✅ On | Won't duplicate a zone whose name already exists |
| **Import general shipping settings** | ⬜ Off | Overwrites shipping/selling country restrictions |

**Always dry-run first.** The log is identical to what a real run produces, so it's an exact preview rather than a summary.

### What happens on re-import

Nothing is ever updated in place — the import creates or skips.

| | Skip existing zones ✅ (default) | Skip existing zones ⬜ |
|---|---|---|
| Shipping classes | Skipped if the slug exists (the checkbox does not apply) | Same |
| Named zones | Skipped entirely, methods included | A second zone with the same name is created |
| Zone 0 methods | Skipped if a method of that type is already there | Same — the guard is always on for zone 0 |
| General settings | Overwritten, if opted in | Same |

Because named zones are skipped whole, editing a rate on the source and re-importing will **not** update the target. Delete the zone there first, then import.

## How the remapping works

The interesting code is `WCSM_Importer::remap_class_costs()`.

Import runs in a fixed order, and the order is load-bearing:

1. **Shipping classes are imported first.** Any class in the file that doesn't already exist here (matched by slug) is created.
2. **The slug → term ID map is rebuilt** from the classes now present on this site — including the ones just created. Building this map before step 1 would miss them.
3. **Zones and methods are imported.** As each method's settings are written, every `class_cost_<digits>` key is passed through the two-hop lookup.

The regex is deliberately strict — `/^class_cost_(\d+)$/` — so a setting that merely happens to contain that substring is never touched. Anything that isn't an exact match passes through unmodified.

Zone 0 is special-cased throughout. `WC_Shipping_Zones::get_zones()` omits it because it isn't a real row that can be created or deleted, but it can still hold methods. The exporter adds it explicitly with an `is_zone_zero` flag; the importer sees the flag and attaches methods to the existing zone instead of trying to create one.

## Known limitations

- **Zone matching is by name.** Two zones with the same name on the target can't be told apart. WooCommerce stores no stable cross-site identifier for zones, so name is the only available handle.
- **Shipping classes are matched by slug.** Renaming a class between sites is fine; changing its slug isn't.
- **Product-to-class assignments are not migrated.** The plugin moves shipping *configuration*, not catalogue data. It creates the classes and rewires the per-class costs, but which products belong to which class is a product-level taxonomy relationship, and products are outside this plugin's scope. After importing, assign products to their classes with your usual product import, or in bulk from **Products → All Products**.
- **Existing classes are skipped, not updated.** If a class with the same slug already exists on the target, its name and description are left as they are. The slug is what the cost remapping needs, so a mismatched label is cosmetic — but it won't be reconciled for you.
- **Third-party method settings are copied verbatim.** If a paid shipping plugin stores its own references to local IDs — carrier accounts, package templates — those aren't remapped. Only `class_cost_*` keys are understood.
- **Nothing is ever updated in place.** The import creates or skips; it never reconciles. Re-importing after changing a rate on the source will not update the target — the zone matches by name, gets skipped, and the changed method is never read. To apply changes, delete the zone on the target first, then import.
- **Zone 0 duplicate guard is by method type, not instance.** Zone 0 always exists, so "Skip existing zones" cannot protect it. Instead, a method is skipped if the zone already contains one of that type — which makes re-import safe, but means that if zone 0 already has a flat rate and the file brings two, neither is added. Skipping is the safe direction to fail: a missing method is visible in the shipping settings, a silently duplicated rate is not.
- **Method instances are always added, never updated.** Re-importing into a named zone that is not skipped adds a second copy of each method.
- **Enabled state and sort order are written directly to the WooCommerce table.** No public API exists for setting these on an existing instance. The write is prefix-correct and fully format-bound, but it's a documented deviation from the CRUD layer.

## Project structure

```
wc-shipping-migrator/
├── wc-shipping-migrator.php        Bootstrap: header, constants, guards, boot
├── uninstall.php                   Removes the plugin's transient on deletion
├── includes/
│   ├── class-wcsm-plugin.php       Wires hooks, owns the collaborators
│   ├── class-wcsm-exporter.php     Reads config, streams the JSON download
│   ├── class-wcsm-importer.php     Parses JSON, writes config, remaps IDs
│   ├── class-wcsm-admin-page.php   Renders the settings screen
│   └── class-wcsm-notices.php      One-shot admin notice storage
├── assets/css/admin.css            Admin screen styles
└── languages/                      Translation template
```

## Security notes

- Both handlers verify a nonce **and** the `manage_woocommerce` capability. A nonce proves the request came from the form; the capability check proves the user is allowed to do it. Both are required.
- Uploads are validated with `is_uploaded_file()` before being read, so a crafted request can't point the reader at an arbitrary server path.
- The importer only accepts general option keys from the exporter's allowlist, so a hand-edited JSON file can't be used to write arbitrary rows into `wp_options`.
- The one direct database write is prefix-correct and format-bound.

## Contributing

Issues and pull requests welcome. By opening a pull request you agree that your contribution is licensed under this repository's license, GPL-2.0-or-later. Please don't include code copied from other plugins or from anywhere without a GPL-compatible license; bundled third-party code is listed in [CREDITS.md](CREDITS.md).

The code follows the WordPress Coding Standards (tabs for indentation, Yoda conditions). Before you push, run the same checks as the Lint workflow does on every pull request:

```bash
.github/license-check.sh   # may this be published (license, headers, third-party code)
.github/wpcs.sh            # WordPress Coding Standards (.github/wpcs.sh fix fixes formatting)
```

A monthly Compatibility workflow compares the versions this plugin is tested with against the newest WordPress and WooCommerce and runs WordPress's Plugin Check.

## Security

Please report a security issue privately, not in a public issue: see [SECURITY.md](SECURITY.md).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE). No third-party code is bundled ([CREDITS.md](CREDITS.md)).

Because this plugin calls WordPress and WooCommerce functions it's a derivative work of GPL code, so GPL is the only correct licence here — not a matter of preference.

## Author

**Marko Sabolić** — IT consultant, WooCommerce and Linux infrastructure.
[GitHub](https://github.com/msabolik) · [LinkedIn](https://www.linkedin.com/in/msabolik/)
