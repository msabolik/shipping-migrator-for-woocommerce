<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * The plugin stores almost nothing of its own: shipping zones, methods and
 * classes are WooCommerce data and must survive uninstallation. The only
 * plugin-owned artefact is a short-lived admin notice transient, which is
 * cleaned up here.
 *
 * Nothing else is removed. Deleting a migration tool must never delete the
 * configuration it migrated.
 *
 * @package WC_Shipping_Migrator
 */

// WordPress defines this constant only when it is genuinely running an
// uninstall. Without the guard, hitting this file directly would execute it.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_transient( 'wcsm_admin_notice' );

// Multisite: the transient is per-site, so clear it on each site in the network.
if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		delete_transient( 'wcsm_admin_notice' );
		restore_current_blog();
	}
}
