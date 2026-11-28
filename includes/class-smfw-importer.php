<?php
/**
 * Parses an export file and writes the configuration into this site.
 *
 * @package Shipping_Migrator_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class SMFW_Importer
 *
 * The import is additive by design: it creates zones, methods and classes,
 * but never deletes anything that already exists. A migration tool that can
 * silently wipe a live store's shipping setup is a liability, so the
 * destructive half was left out deliberately.
 *
 * Every run builds a human-readable log which is handed to SMFW_Notices and
 * displayed on the next page load. In dry-run mode the same log is produced
 * without any writes, which makes it a preview of exactly what would happen.
 */
class SMFW_Importer {

	/**
	 * Accumulated log lines for this run.
	 *
	 * @var string[]
	 */
	private $log = array();

	/**
	 * Whether this run is a preview with no writes.
	 *
	 * @var bool
	 */
	private $dry_run = true;

	/**
	 * Whether zones whose name already exists should be skipped.
	 *
	 * @var bool
	 */
	private $skip_existing = true;

	/**
	 * Whether general shipping options should be overwritten.
	 *
	 * @var bool
	 */
	private $import_general = false;

	/**
	 * Map of shipping class slug to term ID on THIS site.
	 *
	 * Rebuilt after classes are imported, because newly created classes need
	 * to be in the map before method costs are remapped against it.
	 *
	 * @var array<string,int>
	 */
	private $class_slug_to_id = array();

	/**
	 * Handle an import request.
	 *
	 * Same guard pattern as the exporter: return silently unless this exact
	 * request is an authorised import POST.
	 *
	 * @return void
	 */
	public function maybe_handle_request() {
		if ( ! isset( $_POST['smfw_import_nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['smfw_import_nonce'] ) );

		if ( ! wp_verify_nonce( $nonce, 'smfw_import' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Checkbox state. Unchecked boxes are absent from $_POST entirely,
		// so presence is the test.
		$this->dry_run        = ! empty( $_POST['smfw_dry_run'] );
		$this->skip_existing  = ! empty( $_POST['smfw_skip_existing'] );
		$this->import_general = ! empty( $_POST['smfw_import_general'] );
		$this->log            = array();

		// Only the two fields this import uses are read, each as its type.
		$file = null;

		if ( isset( $_FILES['smfw_import_file']['tmp_name'], $_FILES['smfw_import_file']['error'] ) && is_string( $_FILES['smfw_import_file']['tmp_name'] ) ) {
			$file = array(
				// A path PHP chose, checked with is_uploaded_file() before it is
				// read; text sanitising could only alter a valid path.
				'tmp_name' => $_FILES['smfw_import_file']['tmp_name'], // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated by is_uploaded_file() in read_uploaded_file().
				'error'    => absint( $_FILES['smfw_import_file']['error'] ),
			);
		}

		$data = $this->read_uploaded_file( $file );

		if ( null === $data ) {
			// read_uploaded_file() has already queued an explanatory notice.
			return;
		}

		$this->run( $data );
	}

	/**
	 * Validate, decode and sanitise the uploaded JSON file.
	 *
	 * The caller reads $_FILES only after it has verified the nonce and the
	 * capability, and passes the entry in.
	 *
	 * @param array|null $file The upload's `tmp_name` and `error`, or null when there is none.
	 * @return array|null Sanitised payload, or null when the upload is unusable.
	 */
	private function read_uploaded_file( $file ) {
		if ( empty( $file ) || ! isset( $file['tmp_name'] ) || '' === $file['tmp_name'] ) {
			SMFW_Notices::add( __( 'No file uploaded.', 'shipping-migrator-for-woocommerce' ), 'error' );
			return null;
		}

		// PHP reports upload problems (size limits, partial writes, missing
		// temp dir) through this code rather than by omitting the entry.
		if ( isset( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
			SMFW_Notices::add(
				sprintf(
					/* translators: %d: PHP upload error code. */
					__( 'File upload failed with error code %d.', 'shipping-migrator-for-woocommerce' ),
					(int) $file['error']
				),
				'error'
			);
			return null;
		}

		$tmp_name = $file['tmp_name'];

		// Confirms the path really is a PHP upload and not an attacker-supplied
		// local path smuggled in to read an arbitrary server file.
		if ( ! is_uploaded_file( $tmp_name ) ) {
			SMFW_Notices::add( __( 'Upload validation failed.', 'shipping-migrator-for-woocommerce' ), 'error' );
			return null;
		}

		// WP_Filesystem would be the purist choice, but this reads a temp file
		// that PHP itself just wrote, where direct access is safe and avoids
		// requesting filesystem credentials mid-request.
		$json = file_get_contents( $tmp_name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $json || '' === $json ) {
			SMFW_Notices::add( __( 'Uploaded file was empty.', 'shipping-migrator-for-woocommerce' ), 'error' );
			return null;
		}

		$data = json_decode( $json, true );

		if ( ! is_array( $data ) || empty( $data['plugin'] ) || SMFW_FORMAT_ID !== $data['plugin'] ) {
			SMFW_Notices::add(
				__( 'Invalid export file. Make sure it was generated by Shipping Migrator for WooCommerce.', 'shipping-migrator-for-woocommerce' ),
				'error'
			);
			return null;
		}

		// Every field is sanitised here, before anything else reads the file.
		return SMFW_Import_Sanitizer::sanitize_payload( $data, $this->log );
	}

	/**
	 * Execute the import in order: classes, then zones, then general settings.
	 *
	 * Order matters. Shipping classes must exist before method settings are
	 * written, because the cost remapping resolves against the classes present
	 * on this site at that moment.
	 *
	 * @param array $data Decoded export payload.
	 * @return void
	 */
	private function run( array $data ) {
		$this->import_shipping_classes( $data );
		$this->refresh_class_slug_map();
		$this->import_shipping_zones( $data );
		$this->import_general_settings( $data );
		$this->flush_shipping_caches();

		$prefix = $this->dry_run
			? __( '🔍 DRY RUN complete — nothing was changed.', 'shipping-migrator-for-woocommerce' )
			: __( '✅ Import complete!', 'shipping-migrator-for-woocommerce' );

		SMFW_Notices::add(
			$prefix . '<br><br>' . implode( '<br>', $this->log ),
			$this->dry_run ? 'info' : 'success'
		);
	}

	/**
	 * Create any shipping classes that do not already exist here.
	 *
	 * Matching is by slug, not name: names are human-editable and may have
	 * been translated or retyped, whereas slugs are what the rest of the
	 * remapping depends on.
	 *
	 * @param array $data Decoded export payload.
	 * @return void
	 */
	private function import_shipping_classes( array $data ) {
		$this->log[] = '<strong>' . esc_html__( '— Shipping Classes —', 'shipping-migrator-for-woocommerce' ) . '</strong>';

		if ( empty( $data['shipping_classes'] ) ) {
			$this->log[] = esc_html__( 'No shipping classes in export file.', 'shipping-migrator-for-woocommerce' );
			return;
		}

		foreach ( $data['shipping_classes'] as $class ) {
			$existing = get_term_by( 'slug', $class['slug'], 'product_shipping_class' );

			if ( $existing ) {
				$this->log[] = sprintf(
					/* translators: 1: class name, 2: class slug. */
					esc_html__( '⏭ Class "%1$s" already exists (slug: %2$s) — skipped.', 'shipping-migrator-for-woocommerce' ),
					esc_html( $class['name'] ),
					esc_html( $class['slug'] )
				);
				continue;
			}

			if ( $this->dry_run ) {
				$this->log[] = sprintf(
					/* translators: 1: class name, 2: class slug. */
					esc_html__( '🔍 Would create class "%1$s" (slug: %2$s)', 'shipping-migrator-for-woocommerce' ),
					esc_html( $class['name'] ),
					esc_html( $class['slug'] )
				);
				continue;
			}

			$result = wp_insert_term(
				$class['name'],
				'product_shipping_class',
				array(
					'slug'        => $class['slug'],
					'description' => $class['description'],
				)
			);

			if ( is_wp_error( $result ) ) {
				$this->log[] = sprintf(
					/* translators: 1: class name, 2: error message. */
					esc_html__( '❌ Failed to create class "%1$s": %2$s', 'shipping-migrator-for-woocommerce' ),
					esc_html( $class['name'] ),
					esc_html( $result->get_error_message() )
				);
			} else {
				$this->log[] = sprintf(
					/* translators: 1: class name, 2: class slug. */
					esc_html__( '✅ Created class "%1$s" (slug: %2$s)', 'shipping-migrator-for-woocommerce' ),
					esc_html( $class['name'] ),
					esc_html( $class['slug'] )
				);
			}
		}
	}

	/**
	 * Rebuild the slug → term ID map from the classes now present on this site.
	 *
	 * Called after class import so freshly created classes are included.
	 *
	 * @return void
	 */
	private function refresh_class_slug_map() {
		$this->class_slug_to_id = array();

		// WooCommerce caches the class list for the request, and the flat rate
		// method builds its class_cost_<id> fields from it. Classes created a
		// moment ago must be in it before methods are validated.
		WC()->shipping()->shipping_classes = array();

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_shipping_class',
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return;
		}

		foreach ( $terms as $term ) {
			$this->class_slug_to_id[ $term->slug ] = $term->term_id;
		}
	}

	/**
	 * Create zones and attach their methods.
	 *
	 * @param array $data Decoded export payload.
	 * @return void
	 */
	private function import_shipping_zones( array $data ) {
		$this->log[] = '';
		$this->log[] = '<strong>' . esc_html__( '— Shipping Zones —', 'shipping-migrator-for-woocommerce' ) . '</strong>';

		if ( empty( $data['shipping_zones'] ) ) {
			$this->log[] = esc_html__( 'No shipping zones in export file.', 'shipping-migrator-for-woocommerce' );
			return;
		}

		foreach ( $data['shipping_zones'] as $zone_data ) {
			$is_zone_zero = $zone_data['is_zone_zero'];
			$methods      = $zone_data['methods'];

			if ( $is_zone_zero ) {
				// Zone 0 cannot be created or duplicated — it is always there.
				// Only its methods are importable.
				$zone = new WC_Shipping_Zone( 0 );

				$this->log[] = sprintf(
					/* translators: %s: zone name. */
					esc_html__( 'ℹ Zone 0 ("%s") — default zone, importing methods only.', 'shipping-migrator-for-woocommerce' ),
					esc_html( $zone_data['name'] )
				);
			} else {
				if ( $this->skip_existing ) {
					$existing_zone = $this->find_zone_by_name( $zone_data['name'] );

					if ( $existing_zone ) {
						$this->log[] = sprintf(
							/* translators: 1: zone name, 2: zone ID. */
							esc_html__( '⏭ Zone "%1$s" already exists (ID %2$d) — skipped.', 'shipping-migrator-for-woocommerce' ),
							esc_html( $zone_data['name'] ),
							(int) $existing_zone->get_id()
						);
						continue;
					}
				}

				$locations = $this->filter_locations( $zone_data['name'], $zone_data['locations'] );

				if ( $this->dry_run ) {
					$this->log[] = sprintf(
						/* translators: 1: zone name, 2: location count, 3: method count. */
						esc_html__( '🔍 Would create zone "%1$s" with %2$d location(s), %3$d method(s)', 'shipping-migrator-for-woocommerce' ),
						esc_html( $zone_data['name'] ),
						count( $locations ),
						count( $methods )
					);
					continue;
				}

				$zone = new WC_Shipping_Zone();
				$zone->set_zone_name( $zone_data['name'] );
				$zone->set_zone_order( $zone_data['order'] );

				// First save assigns the zone an ID, which add_location() needs.
				$zone->save();

				if ( ! empty( $locations ) ) {
					// The zone is new, so this clears nothing — but it guards
					// against a partially built zone if save() is ever retried.
					$zone->clear_locations();

					foreach ( $locations as $location ) {
						$zone->add_location( $location['code'], $location['type'] );
					}

					$zone->save();
				}

				$this->log[] = sprintf(
					/* translators: 1: zone name, 2: zone ID, 3: location count. */
					esc_html__( '✅ Created zone "%1$s" (ID %2$d) with %3$d location(s)', 'shipping-migrator-for-woocommerce' ),
					esc_html( $zone_data['name'] ),
					(int) $zone->get_id(),
					count( $locations )
				);
			}

			$this->import_zone_methods( $zone, $methods, $data, $is_zone_zero );
		}
	}

	/**
	 * Attach the exported methods to a zone and write their settings.
	 *
	 * @param WC_Shipping_Zone $zone             Target zone.
	 * @param array            $methods          Exported method definitions.
	 * @param array            $data             Full payload, needed for the class list.
	 * @param bool             $guard_duplicates Skip methods whose type is already
	 *                                           present in the zone. Used for zone 0.
	 * @return void
	 */
	private function import_zone_methods( WC_Shipping_Zone $zone, array $methods, array $data, $guard_duplicates = false ) {
		if ( empty( $methods ) ) {
			return;
		}

		/*
		 * Zone 0 needs a duplicate guard that the other zones do not.
		 *
		 * Named zones are protected by the "Skip existing zones" option: if the
		 * zone already exists, the whole iteration is skipped before we get
		 * here. Zone 0 cannot work that way — it always exists, so it is never
		 * "already there" in a way the option can detect, and without a guard
		 * every re-import would append another copy of its methods. Since zone
		 * 0 usually holds the store's fallback rates, that accumulation is both
		 * easy to trigger and hard to notice.
		 *
		 * The snapshot is taken once, BEFORE the loop, so that methods added
		 * during this run do not cause later methods from the same file to be
		 * skipped. Without that, a file legitimately containing two flat rates
		 * would only ever import the first.
		 *
		 * Known trade-off: matching is by method type, not by instance. If the
		 * zone already has a flat rate and the file brings two, neither is
		 * added. Skipping is the safe direction to fail — a missing method is
		 * visible in the shipping settings, whereas a silently duplicated rate
		 * is not.
		 */
		$existing_method_ids = array();

		if ( $guard_duplicates ) {
			// No arguments: returns disabled methods too, so a disabled
			// instance still counts as present and is not duplicated.
			foreach ( $zone->get_shipping_methods() as $existing_method ) {
				$existing_method_ids[] = $existing_method->id;
			}
		}

		foreach ( $methods as $method_data ) {
			// These checks come before the dry-run branch so the preview
			// reports exactly what a real run would do.
			if ( ! SMFW_Import_Sanitizer::is_known_method( $method_data['method_id'] ) ) {
				$this->log[] = sprintf(
					/* translators: 1: method title, 2: method ID. */
					esc_html__( '  ⏭ Method "%1$s" (%2$s) is not available on this site — skipped.', 'shipping-migrator-for-woocommerce' ),
					esc_html( $method_data['title'] ),
					esc_html( $method_data['method_id'] )
				);
				continue;
			}

			if ( $guard_duplicates && in_array( $method_data['method_id'], $existing_method_ids, true ) ) {
				$this->log[] = sprintf(
					/* translators: 1: method title, 2: method ID. */
					esc_html__( '  ⏭ Method "%1$s" (%2$s) already present in this zone — skipped to avoid a duplicate.', 'shipping-migrator-for-woocommerce' ),
					esc_html( $method_data['title'] ),
					esc_html( $method_data['method_id'] )
				);
				continue;
			}

			if ( $this->dry_run ) {
				$this->log[] = sprintf(
					/* translators: 1: method ID, 2: method title. */
					esc_html__( '  🔍 Would add method "%1$s" — %2$s', 'shipping-migrator-for-woocommerce' ),
					esc_html( $method_data['method_id'] ),
					esc_html( $method_data['title'] )
				);
				continue;
			}

			// Creates the row and returns the new instance ID, which is what
			// every subsequent write is keyed on.
			$instance_id = $zone->add_shipping_method( $method_data['method_id'] );

			if ( ! $instance_id ) {
				$this->log[] = sprintf(
					/* translators: %s: method ID. */
					esc_html__( '  ❌ Failed to add method "%s"', 'shipping-migrator-for-woocommerce' ),
					esc_html( $method_data['method_id'] )
				);
				continue;
			}

			$method = WC_Shipping_Zones::get_shipping_method( $instance_id );

			if ( ! empty( $method_data['settings'] ) && $method instanceof WC_Shipping_Method ) {
				$remap_log = array();

				// The heart of the plugin: rewrite class_cost_XX keys so they
				// reference this site's term IDs rather than the source site's.
				$settings = $this->remap_class_costs(
					$method_data['settings'],
					$data['shipping_classes'],
					$remap_log
				);

				// Then check every value with the method's own field validators.
				$settings = SMFW_Import_Sanitizer::validate_method_settings( $method, $settings, $remap_log );

				foreach ( $remap_log as $entry ) {
					$this->log[] = '      ↔ ' . $entry;
				}

				update_option( $method->get_instance_option_key(), $settings, 'yes' );
			}

			$this->set_method_state( $instance_id, $method_data );

			$this->log[] = sprintf(
				/* translators: 1: method title, 2: method ID, 3: enabled or disabled. */
				esc_html__( '  ✅ Added method "%1$s" (%2$s) — %3$s', 'shipping-migrator-for-woocommerce' ),
				esc_html( $method_data['title'] ),
				esc_html( $method_data['method_id'] ),
				$this->is_method_enabled( $method_data )
					? esc_html__( 'enabled', 'shipping-migrator-for-woocommerce' )
					: esc_html__( 'disabled', 'shipping-migrator-for-woocommerce' )
			);
		}
	}

	/**
	 * Write a method instance's enabled flag and sort order.
	 *
	 * WooCommerce exposes no public API for setting these on an existing
	 * instance — add_shipping_method() always creates the row enabled and at
	 * the end of the order. A direct table write is the available option; the
	 * table name is prefixed properly and every value is format-bound, so this
	 * is safe despite bypassing the CRUD layer.
	 *
	 * @param int   $instance_id Method instance ID.
	 * @param array $method_data Exported method definition.
	 * @return void
	 */
	private function set_method_state( $instance_id, array $method_data ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- No WooCommerce API exists for this; see docblock.
		$wpdb->update(
			$wpdb->prefix . 'woocommerce_shipping_zone_methods',
			array(
				'is_enabled'   => $this->is_method_enabled( $method_data ) ? 1 : 0,
				'method_order' => isset( $method_data['order'] ) ? (int) $method_data['order'] : 0,
			),
			array( 'instance_id' => (int) $instance_id ),
			array( '%d', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Whether the exported method was enabled.
	 *
	 * The file may hold 'yes' or a boolean; SMFW_Import_Sanitizer has already
	 * turned either into a boolean.
	 *
	 * @param array $method_data Sanitised method definition.
	 * @return bool
	 */
	private function is_method_enabled( array $method_data ) {
		return true === $method_data['enabled'];
	}

	/**
	 * Rewrite per-shipping-class cost keys to this site's term IDs.
	 *
	 * This is the reason the plugin exists. A flat rate method stores its
	 * per-class costs as settings keys shaped like:
	 *
	 *     class_cost_42 => "5.00"
	 *
	 * where 42 is the term ID of a shipping class ON THE SOURCE SITE. Term IDs
	 * come from an auto-increment column, so on the target site 42 might be a
	 * different class, or no class at all. Copying the settings verbatim gives
	 * you a method that charges the wrong rates, silently.
	 *
	 * Slugs, unlike IDs, are stable. So the fix is a two-hop lookup:
	 *
	 *     old term ID → slug        (from the exported classes array)
	 *     slug        → new term ID (from this site's classes)
	 *
	 * Keys that cannot be resolved are left out and flagged in the log. Kept
	 * as they are, they would point at whatever class has that term ID on
	 * this site, if any, and charge its products the wrong cost.
	 *
	 * @param array $settings         Sanitised method settings from the file.
	 * @param array $exported_classes The `shipping_classes` array from the file.
	 * @param array $remap_log        Log lines, appended by reference.
	 * @return array Settings with remapped keys.
	 */
	private function remap_class_costs( array $settings, array $exported_classes, array &$remap_log = array() ) {
		// Hop one: build old term ID → slug from the export file.
		$old_id_to_slug = array();

		foreach ( $exported_classes as $class ) {
			if ( ! empty( $class['term_id'] ) && ! empty( $class['slug'] ) ) {
				$old_id_to_slug[ (int) $class['term_id'] ] = $class['slug'];
			}
		}

		$remapped = array();

		foreach ( $settings as $key => $value ) {
			// Only keys of the exact form class_cost_<digits> are candidates.
			if ( preg_match( '/^class_cost_(\d+)$/', $key, $matches ) ) {
				$old_id = (int) $matches[1];

				if ( isset( $old_id_to_slug[ $old_id ] ) ) {
					$slug = $old_id_to_slug[ $old_id ];

					// Hop two: slug → this site's term ID.
					if ( isset( $this->class_slug_to_id[ $slug ] ) ) {
						$new_key              = 'class_cost_' . $this->class_slug_to_id[ $slug ];
						$remapped[ $new_key ] = $value;

						$remap_log[] = sprintf(
							/* translators: 1: original key, 2: new key, 3: class slug, 4: cost value. */
							esc_html__( '%1$s → %2$s (class "%3$s", cost: %4$s)', 'shipping-migrator-for-woocommerce' ),
							esc_html( $key ),
							esc_html( $new_key ),
							esc_html( $slug ),
							esc_html( (string) $value )
						);

						// Key rewritten; do not also copy the original.
						continue;
					}

					$remap_log[] = sprintf(
						/* translators: 1: settings key, 2: class slug. */
						esc_html__( '⚠ %1$s (class "%2$s") — no matching class on target site, skipped', 'shipping-migrator-for-woocommerce' ),
						esc_html( $key ),
						esc_html( $slug )
					);
				} else {
					$remap_log[] = sprintf(
						/* translators: %s: settings key. */
						esc_html__( '⚠ %s — unknown source class ID, skipped', 'shipping-migrator-for-woocommerce' ),
						esc_html( $key )
					);
				}

				continue;
			}

			// Every other setting passes through to validation.
			$remapped[ $key ] = $value;
		}

		return $remapped;
	}

	/**
	 * Overwrite the general shipping options, if the operator opted in.
	 *
	 * Opt-in rather than default because these options govern which countries
	 * the store will ship to. Silently importing them could stop a live store
	 * selling to its own market.
	 *
	 * @param array $data Decoded export payload.
	 * @return void
	 */
	private function import_general_settings( array $data ) {
		$this->log[] = '';
		$this->log[] = '<strong>' . esc_html__( '— General Shipping Settings —', 'shipping-migrator-for-woocommerce' ) . '</strong>';

		if ( ! $this->import_general ) {
			$this->log[] = esc_html__( '⏭ Skipped — "Import general shipping settings" was not checked.', 'shipping-migrator-for-woocommerce' );
			return;
		}

		if ( empty( $data['shipping_settings'] ) ) {
			$this->log[] = esc_html__( 'No general shipping settings in export file.', 'shipping-migrator-for-woocommerce' );
			return;
		}

		foreach ( $data['shipping_settings'] as $key => $value ) {
			// Only options from the exporter's allowlist are accepted, so a
			// hand-edited file cannot use this path to write arbitrary options.
			if ( ! in_array( $key, SMFW_Exporter::GENERAL_SETTING_KEYS, true ) ) {
				$this->log[] = sprintf(
					/* translators: %s: option name. */
					esc_html__( '⚠ %s — not a recognised shipping option, skipped.', 'shipping-migrator-for-woocommerce' ),
					esc_html( $key )
				);
				continue;
			}

			$value = SMFW_Import_Sanitizer::sanitize_general_setting( $key, $value );

			if ( null === $value ) {
				$this->log[] = sprintf(
					/* translators: %s: option name. */
					esc_html__( '⚠ %s — not a valid value for this option on this site, skipped.', 'shipping-migrator-for-woocommerce' ),
					esc_html( $key )
				);
				continue;
			}

			if ( $this->dry_run ) {
				$this->log[] = sprintf(
					/* translators: %s: option name. */
					esc_html__( '🔍 Would set %s', 'shipping-migrator-for-woocommerce' ),
					esc_html( $key )
				);
				continue;
			}

			update_option( $key, $value );

			$this->log[] = sprintf(
				/* translators: %s: option name. */
				esc_html__( '✅ Updated %s', 'shipping-migrator-for-woocommerce' ),
				esc_html( $key )
			);
		}
	}

	/**
	 * Invalidate WooCommerce's cached shipping data.
	 *
	 * WooCommerce caches available rates keyed by a "transient version"
	 * string. Without bumping it, a shopper's cached rates would keep being
	 * served from before the import and the new zones would appear to have
	 * done nothing.
	 *
	 * @return void
	 */
	private function flush_shipping_caches() {
		if ( $this->dry_run ) {
			return;
		}

		WC_Cache_Helper::get_transient_version( 'shipping', true );
		delete_transient( 'wc_shipping_method_count' );

		$this->log[] = '';
		$this->log[] = esc_html__( '🧹 WooCommerce shipping transients cleared.', 'shipping-migrator-for-woocommerce' );
	}

	/**
	 * Keep the zone locations that exist on this site, logging the others.
	 *
	 * @param string $zone_name Zone name, for the log.
	 * @param array  $locations Sanitised locations from the file.
	 * @return array Valid locations.
	 */
	private function filter_locations( $zone_name, array $locations ) {
		$valid = array();

		foreach ( $locations as $location ) {
			if ( SMFW_Import_Sanitizer::is_valid_location( $location ) ) {
				$valid[] = $location;
				continue;
			}

			$this->log[] = sprintf(
				/* translators: 1: location type, 2: location code, 3: zone name. */
				esc_html__( '⚠ Location %1$s "%2$s" of zone "%3$s" does not exist on this site — skipped.', 'shipping-migrator-for-woocommerce' ),
				esc_html( $location['type'] ),
				esc_html( $location['code'] ),
				esc_html( $zone_name )
			);
		}

		return $valid;
	}

	/**
	 * Find an existing zone by name, case-insensitively.
	 *
	 * Name is the only practical handle: zone IDs differ between sites and
	 * WooCommerce stores no stable identifier of its own.
	 *
	 * @param string $name Zone name to look for.
	 * @return WC_Shipping_Zone|null
	 */
	private function find_zone_by_name( $name ) {
		foreach ( WC_Shipping_Zones::get_zones() as $zone ) {
			if ( strtolower( $zone['zone_name'] ) === strtolower( $name ) ) {
				return new WC_Shipping_Zone( $zone['id'] );
			}
		}

		return null;
	}
}
