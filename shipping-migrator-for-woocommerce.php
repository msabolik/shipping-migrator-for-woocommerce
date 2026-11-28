<?php
/**
 * Plugin Name:          Shipping Migrator for WooCommerce
 * Plugin URI:           https://github.com/msabolik/shipping-migrator-for-woocommerce
 * Description:          Export and import WooCommerce shipping zones, methods, classes and settings between sites. Remaps shipping class cost keys so per-class rates survive the transfer.
 * Version:              2.0.1
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Author:               Marko Sabolić
 * Author URI:           https://github.com/msabolik
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          shipping-migrator-for-woocommerce
 * Domain Path:          /languages
 * Requires Plugins:     woocommerce
 * WC requires at least: 7.0
 * WC tested up to:      11.3
 *
 * @package Shipping_Migrator_For_WooCommerce
 *
 * Shipping Migrator for WooCommerce is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by the Free
 * Software Foundation, either version 2 of the License, or (at your option) any
 * later version.
 *
 * Shipping Migrator for WooCommerce is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more
 * details.
 */

/*
 * ---------------------------------------------------------------------------
 * WHY THIS PLUGIN EXISTS
 * ---------------------------------------------------------------------------
 *
 * WooCommerce has no built-in export for shipping configuration. Zones,
 * methods and their per-instance settings live partly in custom tables
 * (`woocommerce_shipping_zones`, `woocommerce_shipping_zone_locations`,
 * `woocommerce_shipping_zone_methods`) and partly in `wp_options` under keys
 * shaped like `woocommerce_{method_id}_{instance_id}_settings`.
 *
 * That split is why a generic content exporter cannot move shipping setup:
 * it copies posts and terms, not custom tables, and even if it did, the
 * instance IDs it copied would collide with whatever already exists on the
 * target site.
 *
 * The genuinely hard part is shipping class costs. A flat rate method stores
 * per-class costs under keys like `class_cost_42`, where 42 is the *term ID*
 * of a shipping class on the source site. Term IDs are assigned by
 * auto-increment and are meaningless across installations — class 42 on the
 * source might be class 17 on the target, or might not exist at all.
 *
 * This plugin exports the shipping classes alongside their term IDs, then on
 * import rebuilds the mapping via slug (which IS stable across sites) and
 * rewrites every `class_cost_XX` key to point at the correct local term.
 * See SMFW_Importer::remap_class_costs() for that logic.
 *
 * ---------------------------------------------------------------------------
 * FILE MAP
 * ---------------------------------------------------------------------------
 *
 * shipping-migrator-for-woocommerce.php      This file. Guards, constants, autoload, boot.
 * includes/class-smfw-plugin.php       Wires hooks; owns the admin menu entry.
 * includes/class-smfw-exporter.php     Reads config, streams the JSON download.
 * includes/class-smfw-importer.php     Parses JSON, writes config, remaps IDs.
 * includes/class-smfw-import-sanitizer.php  Sanitises and validates the imported data.
 * includes/class-smfw-admin-page.php   Renders the settings screen markup.
 * includes/class-smfw-notices.php      Stores/reads the one-shot admin notice.
 * uninstall.php                        Removes the transient on deletion.
 */

// Block direct file access. Every PHP file in the plugin carries this guard:
// without it, hitting the file's URL directly would execute it outside of
// WordPress, where none of the security context exists.
defined( 'ABSPATH' ) || exit;

/**
 * Plugin version. Kept in sync with the header above and with CHANGELOG.md.
 * Used for cache busting the admin stylesheet and stamped into export files
 * so an importer can tell which version produced the data.
 */
define( 'SMFW_VERSION', '2.0.1' );

/** Absolute path to the main plugin file, for hooks that need it. */
define( 'SMFW_PLUGIN_FILE', __FILE__ );

/** Absolute filesystem path to the plugin directory, with trailing slash. */
define( 'SMFW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/** Public URL of the plugin directory, with trailing slash. */
define( 'SMFW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * The admin page slug. Referenced by the menu registration and by any code
 * that needs to build a link back to the screen.
 */
define( 'SMFW_PAGE_SLUG', 'shipping-migrator-for-woocommerce' );

/**
 * Format identifier written into every export file and verified on import.
 * It is the plugin's slug before 2.0 (when the plugin was "WC Shipping
 * Migrator") and stays so, so files move between all versions. If the format
 * ever changes shape incompatibly, change the value so old files are rejected
 * cleanly rather than half-imported.
 */
define( 'SMFW_FORMAT_ID', 'wc-shipping-migrator' );

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage and
 * with the Cart and Checkout blocks.
 *
 * This plugin never touches orders, the cart or the checkout, so it is
 * compatible with both by definition — but WooCommerce lists any plugin that
 * has not explicitly declared support as "incompatible" (under Settings →
 * Advanced → Features, and in the editor of the Cart and Checkout pages).
 * Declaring it keeps those screens clean.
 *
 * @return void
 */
function smfw_declare_woocommerce_compatibility() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		foreach ( array( 'custom_order_tables', 'cart_checkout_blocks' ) as $feature ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( $feature, SMFW_PLUGIN_FILE, true );
		}
	}
}
add_action( 'before_woocommerce_init', 'smfw_declare_woocommerce_compatibility' );

/**
 * Boot the plugin once all plugins are loaded.
 *
 * WooCommerce must be present before we can reference WC_Shipping_Zone and
 * friends. The `Requires Plugins: woocommerce` header stops WordPress 6.5+
 * from activating this plugin without WooCommerce, but that header is
 * advisory on older installs and does not protect against WooCommerce being
 * deactivated afterwards — hence the runtime check as well.
 *
 * @return void
 */
function smfw_bootstrap() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'smfw_missing_woocommerce_notice' );
		return;
	}

	require_once SMFW_PLUGIN_DIR . 'includes/class-smfw-notices.php';
	require_once SMFW_PLUGIN_DIR . 'includes/class-smfw-exporter.php';
	require_once SMFW_PLUGIN_DIR . 'includes/class-smfw-import-sanitizer.php';
	require_once SMFW_PLUGIN_DIR . 'includes/class-smfw-importer.php';
	require_once SMFW_PLUGIN_DIR . 'includes/class-smfw-admin-page.php';
	require_once SMFW_PLUGIN_DIR . 'includes/class-smfw-plugin.php';

	SMFW_Plugin::instance();
}
add_action( 'plugins_loaded', 'smfw_bootstrap' );

/**
 * Admin notice shown when WooCommerce is missing or inactive.
 *
 * @return void
 */
function smfw_missing_woocommerce_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__(
			'Shipping Migrator for WooCommerce requires WooCommerce to be installed and active.',
			'shipping-migrator-for-woocommerce'
		)
	);
}
