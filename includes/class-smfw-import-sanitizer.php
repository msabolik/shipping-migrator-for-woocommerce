<?php
/**
 * Sanitises and validates the data of an import file.
 *
 * @package Shipping_Migrator_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class SMFW_Import_Sanitizer
 *
 * An export file is untrusted input: it may come from another site, have been
 * edited by hand, or be damaged. The importer handles it in two passes.
 *
 *   1. sanitize_payload(), right after the file is decoded: keeps only the
 *      fields the export format defines, casts each one to its type and
 *      sanitises every string. Entries that cannot be used are dropped.
 *   2. The validate_*() and sanitize_general_setting() methods, just before a
 *      value is written: check it against what exists on THIS site (its
 *      countries, shipping methods, method settings and tax classes), which
 *      the first pass cannot know.
 */
class SMFW_Import_Sanitizer {

	/**
	 * Location types a shipping zone can hold.
	 *
	 * @var string[]
	 */
	const LOCATION_TYPES = array( 'country', 'state', 'postcode', 'continent' );

	/**
	 * How deeply a method setting may nest arrays.
	 *
	 * Core methods store only scalars; one level of arrays covers multiselect
	 * fields of other methods. Anything deeper is not a settings value.
	 *
	 * @var int
	 */
	const MAX_SETTING_DEPTH = 2;

	/**
	 * Sanitise a decoded export file.
	 *
	 * @param array    $data Decoded file, already checked for the format marker.
	 * @param string[] $log  Log lines, appended by reference.
	 * @return array The payload with only known fields, each sanitised.
	 */
	public static function sanitize_payload( array $data, array &$log = array() ) {
		$payload = array(
			'shipping_classes'  => array(),
			'shipping_zones'    => array(),
			'shipping_settings' => array(),
		);
		$dropped = 0;

		foreach ( self::get_list( $data, 'shipping_classes' ) as $class ) {
			$class = is_array( $class ) ? self::sanitize_class( $class ) : null;

			if ( null === $class ) {
				++$dropped;
				continue;
			}

			$payload['shipping_classes'][] = $class;
		}

		foreach ( self::get_list( $data, 'shipping_zones' ) as $zone ) {
			$zone = is_array( $zone ) ? self::sanitize_zone( $zone, $dropped ) : null;

			if ( null === $zone ) {
				++$dropped;
				continue;
			}

			$payload['shipping_zones'][] = $zone;
		}

		foreach ( self::get_list( $data, 'shipping_settings' ) as $key => $value ) {
			$key   = is_string( $key ) ? sanitize_key( $key ) : '';
			$value = self::sanitize_setting_value( $value, 1 );

			if ( '' === $key || null === $value ) {
				++$dropped;
				continue;
			}

			$payload['shipping_settings'][ $key ] = $value;
		}

		if ( $dropped > 0 ) {
			$log[] = sprintf(
				/* translators: %d: number of entries. */
				esc_html( _n( '⚠ %d entry in the file is incomplete or malformed and was ignored.', '⚠ %d entries in the file are incomplete or malformed and were ignored.', $dropped, 'shipping-migrator-for-woocommerce' ) ),
				(int) $dropped
			);
			$log[] = '';
		}

		return $payload;
	}

	/**
	 * Check a zone location against the countries, states and continents
	 * WooCommerce knows on this site.
	 *
	 * @param array $location Sanitised location (`code`, `type`).
	 * @return bool
	 */
	public static function is_valid_location( array $location ) {
		$countries = WC()->countries;
		$code      = $location['code'];

		switch ( $location['type'] ) {
			case 'country':
				return array_key_exists( $code, $countries->get_countries() );

			case 'continent':
				return array_key_exists( $code, $countries->get_continents() );

			case 'state':
				$parts = explode( ':', $code, 2 );

				if ( 2 !== count( $parts ) || ! array_key_exists( $parts[0], $countries->get_countries() ) ) {
					return false;
				}

				$states = $countries->get_states( $parts[0] );

				return is_array( $states ) && array_key_exists( $parts[1], $states );

			case 'postcode':
				// Free text by design (ranges, wildcards); sanitised in the first pass.
				return '' !== $code;
		}

		return false;
	}

	/**
	 * Whether a shipping method type is registered on this site.
	 *
	 * @param string $method_id Method type, e.g. flat_rate.
	 * @return bool
	 */
	public static function is_known_method( $method_id ) {
		return array_key_exists( $method_id, WC()->shipping()->get_shipping_method_class_names() );
	}

	/**
	 * Validate imported settings against a method instance's own fields.
	 *
	 * Each value goes through the same validator WooCommerce uses when the
	 * method's settings form is saved, so a cost formula, a price or a select
	 * value is checked exactly as if it had been typed in. Keys that are not a
	 * field of this method on this site are skipped, as are values the
	 * validator rejects. Fields the file does not set keep their defaults,
	 * which is what WooCommerce stores for a new method too.
	 *
	 * @param WC_Shipping_Method $method   New method instance, not yet configured.
	 * @param array              $settings Sanitised (and remapped) settings from the file.
	 * @param string[]           $log      Log lines, appended by reference.
	 * @return array Complete settings for the instance.
	 */
	public static function validate_method_settings( WC_Shipping_Method $method, array $settings, array &$log = array() ) {
		$fields = $method->get_instance_form_fields();

		// A new instance has no settings yet, so this loads the field defaults.
		$method->init_instance_settings();
		$clean = is_array( $method->instance_settings ) ? $method->instance_settings : array();

		foreach ( $settings as $key => $value ) {
			if ( ! isset( $fields[ $key ] ) || 'title' === $method->get_field_type( $fields[ $key ] ) ) {
				$log[] = sprintf(
					/* translators: %s: settings key. */
					esc_html__( '⚠ %s — not a setting of this method on this site, skipped', 'shipping-migrator-for-woocommerce' ),
					esc_html( $key )
				);
				continue;
			}

			try {
				$clean[ $key ] = self::validate_field( $method, $key, $fields[ $key ], $value );
			} catch ( Exception $e ) {
				$log[] = sprintf(
					/* translators: 1: settings key, 2: reason. */
					esc_html__( '⚠ %1$s — invalid value (%2$s), skipped', 'shipping-migrator-for-woocommerce' ),
					esc_html( $key ),
					esc_html( wp_strip_all_tags( $e->getMessage() ) )
				);
			}
		}

		return $clean;
	}

	/**
	 * Validate a general shipping option against the values WooCommerce allows.
	 *
	 * @param string $key   Option name from SMFW_Exporter::GENERAL_SETTING_KEYS.
	 * @param mixed  $value Sanitised value from the file.
	 * @return mixed|null The value to store, or null when it is not valid here.
	 */
	public static function sanitize_general_setting( $key, $value ) {
		switch ( $key ) {
			case 'woocommerce_ship_to_countries':
				return in_array( $value, array( '', 'all', 'specific', 'disabled' ), true ) ? $value : null;

			case 'woocommerce_specific_ship_to_countries':
				// WooCommerce stores '' until a country is chosen.
				if ( '' === $value ) {
					return array();
				}

				if ( ! is_array( $value ) ) {
					return null;
				}

				$countries = WC()->countries->get_countries();
				$codes     = array();

				foreach ( $value as $code ) {
					if ( is_string( $code ) && array_key_exists( $code, $countries ) ) {
						$codes[] = $code;
					}
				}

				return count( $codes ) === count( $value ) ? $codes : null;

			case 'woocommerce_shipping_cost_requires_address':
			case 'woocommerce_shipping_debug_mode':
			case 'woocommerce_enable_shipping_calc':
				return in_array( $value, array( 'yes', 'no' ), true ) ? $value : null;

			case 'woocommerce_shipping_tax_class':
				// "inherit" follows the cart items, '' is the standard rate.
				$allowed = array_merge( array( 'inherit', '' ), WC_Tax::get_tax_class_slugs() );

				return in_array( $value, $allowed, true ) ? $value : null;
		}

		return null;
	}

	/**
	 * Read one top-level list from the file, or an empty one.
	 *
	 * @param array  $data Decoded file.
	 * @param string $key  List name.
	 * @return array
	 */
	private static function get_list( array $data, $key ) {
		return isset( $data[ $key ] ) && is_array( $data[ $key ] ) ? $data[ $key ] : array();
	}

	/**
	 * Read one field as a string, or '' when it is missing or not a scalar.
	 *
	 * @param array  $data Entry from the file.
	 * @param string $key  Field name.
	 * @return string
	 */
	private static function get_string( array $data, $key ) {
		return isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ? (string) $data[ $key ] : '';
	}

	/**
	 * Sanitise one shipping class.
	 *
	 * @param array $entry Class entry from the file.
	 * @return array|null Sanitised class, or null without a name or slug.
	 */
	private static function sanitize_class( array $entry ) {
		$name = sanitize_text_field( self::get_string( $entry, 'name' ) );
		$slug = sanitize_title( self::get_string( $entry, 'slug' ) );

		if ( '' === $name || '' === $slug ) {
			return null;
		}

		return array(
			// Only used to resolve class_cost_<id> keys, never written.
			'term_id'     => absint( self::get_string( $entry, 'term_id' ) ),
			'name'        => $name,
			'slug'        => $slug,
			'description' => sanitize_textarea_field( self::get_string( $entry, 'description' ) ),
		);
	}

	/**
	 * Sanitise one shipping zone with its locations and methods.
	 *
	 * @param array $zone    Zone entry from the file.
	 * @param int   $dropped Count of dropped entries, increased by reference.
	 * @return array|null Sanitised zone, or null for a named zone without a name.
	 */
	private static function sanitize_zone( array $zone, &$dropped ) {
		$is_zone_zero = ! empty( $zone['is_zone_zero'] );
		$name         = sanitize_text_field( self::get_string( $zone, 'name' ) );

		if ( '' === $name && ! $is_zone_zero ) {
			return null;
		}

		$locations = array();

		foreach ( self::get_list( $zone, 'locations' ) as $location ) {
			$type = is_array( $location ) ? sanitize_key( self::get_string( $location, 'type' ) ) : '';
			$code = is_array( $location ) ? sanitize_text_field( self::get_string( $location, 'code' ) ) : '';

			if ( '' === $code || ! in_array( $type, self::LOCATION_TYPES, true ) ) {
				++$dropped;
				continue;
			}

			$locations[] = array(
				'code' => $code,
				'type' => $type,
			);
		}

		$methods = array();

		foreach ( self::get_list( $zone, 'methods' ) as $method ) {
			$method = is_array( $method ) ? self::sanitize_method( $method ) : null;

			if ( null === $method ) {
				++$dropped;
				continue;
			}

			$methods[] = $method;
		}

		return array(
			'is_zone_zero' => $is_zone_zero,
			'name'         => $name,
			'order'        => (int) self::get_string( $zone, 'order' ),
			'locations'    => $locations,
			'methods'      => $methods,
		);
	}

	/**
	 * Sanitise one shipping method.
	 *
	 * @param array $method Method entry from the file.
	 * @return array|null Sanitised method, or null without a valid method ID.
	 */
	private static function sanitize_method( array $method ) {
		$method_id = self::get_string( $method, 'method_id' );

		// Method IDs are keys; one that sanitize_key() would change is not valid.
		if ( '' === $method_id || sanitize_key( $method_id ) !== $method_id ) {
			return null;
		}

		$enabled = isset( $method['enabled'] ) ? $method['enabled'] : false;
		$title   = sanitize_text_field( self::get_string( $method, 'title' ) );

		$settings = array();

		foreach ( self::get_list( $method, 'settings' ) as $key => $value ) {
			$value = is_string( $key ) && sanitize_key( $key ) === $key ? self::sanitize_setting_value( $value, 1 ) : null;

			// Keys and values that cannot be a WooCommerce setting are left out;
			// validate_method_settings() checks the rest against the method.
			if ( null !== $value ) {
				$settings[ $key ] = $value;
			}
		}

		return array(
			'method_id' => $method_id,
			'title'     => '' !== $title ? $title : $method_id,
			// WooCommerce has used both 'yes' and true for this.
			'enabled'   => 'yes' === $enabled || true === $enabled,
			'order'     => absint( self::get_string( $method, 'order' ) ),
			'settings'  => $settings,
		);
	}

	/**
	 * Sanitise a settings value: a scalar, or an array of them.
	 *
	 * Strings get wp_kses_post(), which is what WooCommerce applies to text
	 * settings itself: it keeps cost formulas such as `[fee percent="10"]` and
	 * the limited HTML that some descriptions use, and removes everything else.
	 *
	 * @param mixed $value Value from the file.
	 * @param int   $depth Current nesting level, starting at 1.
	 * @return mixed|null Sanitised value, or null when it cannot be a setting.
	 */
	private static function sanitize_setting_value( $value, $depth ) {
		if ( is_string( $value ) ) {
			return wp_kses_post( $value );
		}

		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}

		if ( ! is_array( $value ) || $depth >= self::MAX_SETTING_DEPTH ) {
			return null;
		}

		$clean = array();

		foreach ( $value as $key => $item ) {
			$item = self::sanitize_setting_value( $item, $depth + 1 );

			if ( null !== $item ) {
				$clean[ is_int( $key ) ? $key : sanitize_key( $key ) ] = $item;
			}
		}

		return $clean;
	}

	/**
	 * Validate one value with the method's own field validator.
	 *
	 * @param WC_Shipping_Method $method Method instance.
	 * @param string             $key    Field key.
	 * @param array              $field  Field definition.
	 * @param mixed              $value  Sanitised value from the file.
	 * @return mixed Validated value.
	 * @throws Exception When the value is not valid for the field.
	 */
	private static function validate_field( WC_Shipping_Method $method, $key, array $field, $value ) {
		$type = $method->get_field_type( $field );

		// WooCommerce's checkbox validator reads "present in the form" as
		// "checked", so a file's 'no' would turn into 'yes'. Map it directly.
		if ( 'checkbox' === $type ) {
			return ( 'yes' === $value || true === $value ) ? 'yes' : 'no';
		}

		if ( is_array( $value ) !== ( 'multiselect' === $type ) ) {
			throw new Exception( esc_html__( 'wrong type', 'shipping-migrator-for-woocommerce' ) );
		}

		// The validators expect request data, which WordPress delivers slashed.
		$validated = $method->get_field_value( $key, $field, array( $method->get_field_key( $key ) => wp_slash( $value ) ) );

		// WooCommerce cleans select values but does not check them against the
		// field's options, so a value no option offers is caught here.
		if ( in_array( $type, array( 'select', 'multiselect' ), true ) && ! empty( $field['options'] ) ) {
			$allowed = self::get_option_keys( $field['options'] );

			foreach ( (array) $validated as $choice ) {
				if ( ! in_array( (string) $choice, $allowed, true ) ) {
					throw new Exception( esc_html__( 'not one of the field\'s options', 'shipping-migrator-for-woocommerce' ) );
				}
			}
		}

		return $validated;
	}

	/**
	 * List the values a select field offers, including those in option groups.
	 *
	 * @param array $options Field options: value => label, or group => options.
	 * @return string[]
	 */
	private static function get_option_keys( array $options ) {
		$keys = array();

		foreach ( $options as $key => $label ) {
			if ( is_array( $label ) ) {
				$keys = array_merge( $keys, array_map( 'strval', array_keys( $label ) ) );
			} else {
				$keys[] = (string) $key;
			}
		}

		return $keys;
	}
}
