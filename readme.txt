=== Shipping Migrator for WooCommerce ===
Contributors: msabolik
Tags: woocommerce, shipping, shipping zones, export, import
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Export and import WooCommerce shipping zones, methods, classes and settings between sites, with per-class costs remapped to the target's classes.

== Description ==

WooCommerce has no built-in export for its shipping configuration. Shipping Migrator for WooCommerce moves it from one site to another: shipping classes, zones with their locations (the "Locations not covered by your other zones" zone included), every shipping method instance with its settings, enabled state and order, and, if you opt in, the general shipping options.

Flat rate costs per shipping class are stored under the class's term ID, which differs from site to site. The import maps each one through the class's slug to the class on the target site, and lists every rewritten key in its log. A cost whose class does not exist on the target is kept as it is and flagged, never dropped.

The import only adds: it never deletes or updates anything in place. A dry run, on by default, shows the full log without writing anything.

The plugin works only for users who can manage WooCommerce, and it stores nothing of its own beyond a short-lived admin notice.

== Installation ==

1. In WordPress, go to Plugins > Add New Plugin, search for "Shipping Migrator for WooCommerce", then Install Now and Activate. WooCommerce must be active.
2. Open WooCommerce > Shipping Migrator.

You can also download the zip of any release from https://github.com/msabolik/shipping-migrator-for-woocommerce/releases and upload it under Plugins > Add New Plugin > Upload Plugin.

== Frequently Asked Questions ==

= Does it copy which products belong to which shipping class? =

No. It moves the shipping configuration, not the catalogue: it creates the classes and remaps the per-class costs, but assigning products to classes is part of your product import.

= What happens when I import the same file twice? =

With "Skip existing zones" on (the default), zones whose name already exists are skipped whole, and the fallback zone gets no second method of a type it already has. Nothing is updated in place, so to apply a changed rate, delete the zone on the target first, then import.

= Is the export file safe to share? =

It contains your store's shipping configuration and its address. Treat it like any other configuration backup.

== Changelog ==

= 2.0.0 =
* Renamed from "WC Shipping Migrator" to "Shipping Migrator for WooCommerce" for the WordPress.org plugin directory, whose rules keep "WC" out of plugin names. The plugin folder, file and text domain are now shipping-migrator-for-woocommerce. Export files still move between all versions.
* Translations come from translate.wordpress.org.

= 1.2.1 =
* Declares compatibility with the WooCommerce Cart and Checkout blocks (the plugin does not touch them), so WooCommerce no longer lists it as possibly incompatible there.
* Tested with WordPress 7.1 and WooCommerce 11.3.

= 1.2.0 =
* First public release.
