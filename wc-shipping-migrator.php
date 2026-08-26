<?php
/**
 * Plugin Name:          WC Shipping Migrator
 * Plugin URI:           https://github.com/msabolik/wc-shipping-migrator
 * Description:          Export and import WooCommerce shipping zones, methods, classes and settings between sites. Remaps shipping class cost keys so per-class rates survive the transfer.
 * Version:              1.2.0
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Author:               Marko Sabolić
 * Author URI:           https://github.com/msabolik
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          wc-shipping-migrator
 * Domain Path:          /languages
 * Requires Plugins:     woocommerce
 * WC requires at least: 7.0
 * WC tested up to:      9.4
 *
 * @package WC_Shipping_Migrator
 *
 * WC Shipping Migrator is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by the Free
 * Software Foundation, either version 2 of the License, or (at your option) any
 * later version.
 *
 * WC Shipping Migrator is distributed in the hope that it will be useful, but
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
 * See WCSM_Importer::remap_class_costs() for that logic.
 *
 * ---------------------------------------------------------------------------
 * FILE MAP
 * ---------------------------------------------------------------------------
 *
 * wc-shipping-migrator.php      This file. Guards, constants, autoload, boot.
 * includes/class-wcsm-plugin.php       Wires hooks; owns the admin menu entry.
 * includes/class-wcsm-exporter.php     Reads config, streams the JSON download.
 * includes/class-wcsm-importer.php     Parses JSON, writes config, remaps IDs.
 * includes/class-wcsm-admin-page.php   Renders the settings screen markup.
 * includes/class-wcsm-notices.php      Stores/reads the one-shot admin notice.
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
define( 'WCSM_VERSION', '1.2.0' );

/** Absolute path to the main plugin file, for hooks that need it. */
define( 'WCSM_PLUGIN_FILE', __FILE__ );

/** Absolute filesystem path to the plugin directory, with trailing slash. */
define( 'WCSM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/** Public URL of the plugin directory, with trailing slash. */
define( 'WCSM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * The admin page slug. Referenced by the menu registration and by any code
 * that needs to build a link back to the screen.
 */
define( 'WCSM_PAGE_SLUG', 'wc-shipping-migrator' );

/**
 * Format identifier written into every export file and verified on import.
 * If this ever changes shape incompatibly, bump the value so old files are
 * rejected cleanly rather than half-imported.
 */
define( 'WCSM_FORMAT_ID', 'wc-shipping-migrator' );

/**
 * Load the plugin's translations.
 *
 * Hooked late-ish on `init` because WordPress 6.7 warns when a text domain is
 * loaded before `init` runs.
 *
 * @return void
 */
function wcsm_load_textdomain() {
	load_plugin_textdomain(
		'wc-shipping-migrator',
		false,
		dirname( plugin_basename( WCSM_PLUGIN_FILE ) ) . '/languages'
	);
}
add_action( 'init', 'wcsm_load_textdomain' );

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage.
 *
 * This plugin never touches orders, so it is compatible by definition — but
 * WooCommerce lists any plugin that has not explicitly declared support as
 * "incompatible" under Settings → Advanced → Features. Declaring it keeps the
 * store's compatibility screen clean.
 *
 * @return void
 */
function wcsm_declare_hpos_compatibility() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			WCSM_PLUGIN_FILE,
			true
		);
	}
}
add_action( 'before_woocommerce_init', 'wcsm_declare_hpos_compatibility' );

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
function wcsm_bootstrap() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'wcsm_missing_woocommerce_notice' );
		return;
	}

	require_once WCSM_PLUGIN_DIR . 'includes/class-wcsm-notices.php';
	require_once WCSM_PLUGIN_DIR . 'includes/class-wcsm-exporter.php';
	require_once WCSM_PLUGIN_DIR . 'includes/class-wcsm-importer.php';
	require_once WCSM_PLUGIN_DIR . 'includes/class-wcsm-admin-page.php';
	require_once WCSM_PLUGIN_DIR . 'includes/class-wcsm-plugin.php';

	WCSM_Plugin::instance();
}
add_action( 'plugins_loaded', 'wcsm_bootstrap' );

/**
 * Admin notice shown when WooCommerce is missing or inactive.
 *
 * @return void
 */
function wcsm_missing_woocommerce_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__(
			'WC Shipping Migrator requires WooCommerce to be installed and active.',
			'wc-shipping-migrator'
		)
	);
}
