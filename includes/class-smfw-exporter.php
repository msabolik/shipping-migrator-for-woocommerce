<?php
/**
 * Reads the site's shipping configuration and streams it out as JSON.
 *
 * @package Shipping_Migrator_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class SMFW_Exporter
 *
 * Collects three separate things, because WooCommerce keeps them in three
 * different places:
 *
 *   1. Shipping classes  — terms in the `product_shipping_class` taxonomy.
 *   2. Shipping zones    — rows in the `woocommerce_shipping_zone*` tables,
 *                          read through the WC_Shipping_Zone API.
 *   3. General settings  — plain `wp_options` rows.
 *
 * Method *instance* settings are the awkward case: WooCommerce exposes the
 * method object but the configured values live in an option keyed by instance
 * ID, so we read that option directly. See collect_zone().
 */
class SMFW_Exporter {

	/**
	 * General WooCommerce shipping option keys included in an export.
	 *
	 * Deliberately a fixed allowlist rather than a wildcard scan of the
	 * options table: a prefix match on `woocommerce_shipping` would sweep up
	 * per-instance method settings and third-party keys, which are either
	 * handled separately or unsafe to move between sites.
	 *
	 * @var string[]
	 */
	const GENERAL_SETTING_KEYS = array(
		'woocommerce_ship_to_countries',
		'woocommerce_specific_ship_to_countries',
		'woocommerce_shipping_cost_requires_address',
		'woocommerce_shipping_debug_mode',
		'woocommerce_enable_shipping_calc',
		'woocommerce_shipping_tax_class',
	);

	/**
	 * Handle an export request.
	 *
	 * Runs on `admin_init`, which is early enough that no output has been
	 * sent and we can still set download headers. The method returns silently
	 * unless this specific request is a valid, authorised export POST — that
	 * is what keeps it from interfering with every other admin page load.
	 *
	 * @return void
	 */
	public function maybe_handle_request() {
		// Not an export submission at all: nothing to do.
		if ( ! isset( $_POST['smfw_export_nonce'] ) ) {
			return;
		}

		// wp_unslash() first because WordPress adds slashes to all superglobals;
		// verifying the slashed value would fail on some nonces.
		$nonce = sanitize_text_field( wp_unslash( $_POST['smfw_export_nonce'] ) );

		// Nonce proves the request came from our form (CSRF protection).
		if ( ! wp_verify_nonce( $nonce, 'smfw_export' ) ) {
			return;
		}

		// Capability proves the *user* is allowed to do this. Nonces are not
		// authorisation — both checks are required.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$this->stream_export();
	}

	/**
	 * Build the payload and send it as a file download.
	 *
	 * Terminates the request: once we have sent binary-ish output with a
	 * Content-Disposition header, letting WordPress continue rendering the
	 * admin page would corrupt the file.
	 *
	 * @return void
	 */
	private function stream_export() {
		$data = array(
			// Format marker; the importer refuses files without it.
			'plugin'            => SMFW_FORMAT_ID,
			'version'           => SMFW_VERSION,
			'exported_at'       => current_time( 'c' ),
			// Recorded for the operator's benefit when juggling several files.
			'source_site'       => home_url(),
			'shipping_classes'  => $this->collect_shipping_classes(),
			'shipping_zones'    => $this->collect_shipping_zones(),
			'shipping_settings' => $this->collect_general_settings(),
		);

		$host     = wp_parse_url( home_url(), PHP_URL_HOST );
		$filename = sprintf(
			'wc-shipping-export-%s-%s.json',
			sanitize_title( (string) $host ),
			gmdate( 'Y-m-d-His' )
		);

		// Stop proxies and browsers caching a settings dump.
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Expires: 0' );

		// JSON_PRETTY_PRINT keeps the file diffable and human-reviewable, which
		// matters when someone wants to sanity-check it before importing.
		// JSON_UNESCAPED_UNICODE keeps non-Latin zone names readable.
		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/**
	 * Export all shipping class terms.
	 *
	 * The term_id is included even though it is meaningless on the target
	 * site. It has to be: it is the only way to resolve the `class_cost_42`
	 * keys found in method settings back to a slug. The importer builds
	 * old_id → slug → new_id from this array.
	 *
	 * @return array[] List of class arrays.
	 */
	private function collect_shipping_classes() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_shipping_class',
				// A class with no products assigned is still a class worth
				// migrating, so empty terms must be included.
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$classes = array();

		foreach ( $terms as $term ) {
			$classes[] = array(
				'term_id'     => $term->term_id,
				'name'        => $term->name,
				// Slug is the stable cross-site identifier.
				'slug'        => $term->slug,
				'description' => $term->description,
			);
		}

		return $classes;
	}

	/**
	 * Export every shipping zone, including the implicit zone 0.
	 *
	 * WC_Shipping_Zones::get_zones() deliberately omits zone 0 ("Locations not
	 * covered by your other zones") because it is not a real row that can be
	 * created or deleted — it always exists. It can still hold methods,
	 * though, and those methods are usually the store's fallback rates, so
	 * skipping it would silently lose configuration.
	 *
	 * @return array[] List of zone arrays, zone 0 first.
	 */
	private function collect_shipping_zones() {
		$zones_data = array();

		// Zone 0 first, flagged so the importer knows not to create it.
		$zones_data[] = $this->collect_zone( new WC_Shipping_Zone( 0 ), true );

		foreach ( WC_Shipping_Zones::get_zones() as $zone_array ) {
			$zones_data[] = $this->collect_zone( new WC_Shipping_Zone( $zone_array['id'] ), false );
		}

		return $zones_data;
	}

	/**
	 * Serialise a single zone and its methods.
	 *
	 * @param WC_Shipping_Zone $zone         Zone to read.
	 * @param bool             $is_zone_zero Whether this is the catch-all zone.
	 * @return array Zone data.
	 */
	private function collect_zone( WC_Shipping_Zone $zone, $is_zone_zero ) {
		$locations = array();

		// Zone 0 has no locations by definition — it is whatever is left over.
		// Asking for its locations returns nothing useful, so skip the call.
		if ( ! $is_zone_zero ) {
			foreach ( $zone->get_zone_locations() as $location ) {
				$locations[] = array(
					'code' => $location->code,
					// One of: country, state, postcode, continent.
					'type' => $location->type,
				);
			}
		}

		$methods = array();

		foreach ( $zone->get_shipping_methods() as $method ) {
			/*
			 * The method object exposes its ID and title, but the *configured
			 * values* (cost formulas, per-class costs, tax status, min amount)
			 * live in a separate option row keyed by instance ID:
			 *
			 *   woocommerce_flat_rate_12_settings
			 *              └ method id   └ instance id
			 *
			 * $method->instance_settings is populated lazily and can be stale
			 * depending on when the object was built, so read the option
			 * directly — it is the source of truth.
			 */
			$option_key      = 'woocommerce_' . $method->id . '_' . $method->instance_id . '_settings';
			$instance_option = get_option( $option_key, array() );

			$methods[] = array(
				// e.g. flat_rate, free_shipping, local_pickup.
				'method_id' => $method->id,
				'title'     => $method->title,
				// WooCommerce stores this as the string 'yes'/'no' in some
				// paths and as a boolean in others; the importer accepts both.
				'enabled'   => $method->enabled,
				'order'     => $method->method_order,
				'settings'  => is_array( $instance_option ) ? $instance_option : array(),
			);
		}

		return array(
			'is_zone_zero' => $is_zone_zero,
			'name'         => $zone->get_zone_name(),
			'order'        => $zone->get_zone_order(),
			'locations'    => $locations,
			'methods'      => $methods,
		);
	}

	/**
	 * Export the general shipping options from the allowlist.
	 *
	 * Options that do not exist on this site are omitted rather than exported
	 * as null, so importing the file cannot blank out a setting that the
	 * source site simply never configured.
	 *
	 * @return array Map of option name to value.
	 */
	private function collect_general_settings() {
		$settings = array();

		foreach ( self::GENERAL_SETTING_KEYS as $key ) {
			$value = get_option( $key, null );

			if ( null !== $value ) {
				$settings[ $key ] = $value;
			}
		}

		return $settings;
	}

	/**
	 * Count the shipping zones on this site, including zone 0.
	 *
	 * Used by the admin screen's summary tiles.
	 *
	 * @return int
	 */
	public static function count_zones() {
		// +1 for zone 0, which get_zones() excludes.
		return count( WC_Shipping_Zones::get_zones() ) + 1;
	}

	/**
	 * Count the shipping classes on this site.
	 *
	 * @return int
	 */
	public static function count_classes() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_shipping_class',
				'hide_empty' => false,
			)
		);

		return is_wp_error( $terms ) ? 0 : count( $terms );
	}
}
