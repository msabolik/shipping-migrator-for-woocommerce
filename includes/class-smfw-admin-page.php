<?php
/**
 * Renders the Shipping Migrator admin screen.
 *
 * @package Shipping_Migrator_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class SMFW_Admin_Page
 *
 * Pure presentation. All state changes happen earlier in the request on
 * `admin_init` (see SMFW_Exporter and SMFW_Importer); by the time this runs,
 * the work is done and there is only a queued notice to display.
 */
class SMFW_Admin_Page {

	/**
	 * Register the submenu entry under WooCommerce.
	 *
	 * `manage_woocommerce` is the right capability here rather than
	 * `manage_options`: shop managers configure shipping, and they do not
	 * hold `manage_options`.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Shipping Migrator', 'shipping-migrator-for-woocommerce' ),
			__( 'Shipping Migrator', 'shipping-migrator-for-woocommerce' ),
			'manage_woocommerce',
			SMFW_PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Enqueue the admin stylesheet, but only on our own screen.
	 *
	 * Loading it globally would push plugin CSS into every admin page, which
	 * is the single most common way plugins break unrelated screens.
	 *
	 * @param string $hook_suffix Current admin page identifier.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'woocommerce_page_' . SMFW_PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'smfw-admin',
			SMFW_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			SMFW_VERSION
		);
	}

	/**
	 * Output the screen.
	 *
	 * @return void
	 */
	public function render() {
		// Defence in depth: the menu already restricts access, but a direct
		// hit on the callback should not bypass the capability check.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'shipping-migrator-for-woocommerce' ) );
		}

		$notice      = SMFW_Notices::pull();
		$zone_count  = SMFW_Exporter::count_zones();
		$class_count = SMFW_Exporter::count_classes();
		?>
		<div class="wrap smfw-wrap">
			<h1><?php esc_html_e( '🚚 Shipping Migrator for WooCommerce', 'shipping-migrator-for-woocommerce' ); ?></h1>

			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible smfw-notice-log">
					<?php
					/*
					 * The import log is assembled from translated strings that
					 * were escaped as they were built, plus <br> and <strong>
					 * formatting. wp_kses_post() permits that formatting while
					 * stripping anything unexpected.
					 */
					?>
					<p><?php echo wp_kses_post( $notice['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<div class="smfw-card">
				<h2><?php esc_html_e( '📤 Export Shipping Settings', 'shipping-migrator-for-woocommerce' ); ?></h2>
				<p>
					<?php
					esc_html_e(
						'Download a JSON file containing all shipping zones, methods, classes and settings from this site.',
						'shipping-migrator-for-woocommerce'
					);
					?>
				</p>

				<div class="smfw-stats">
					<div class="smfw-stat">
						<span class="num"><?php echo esc_html( (string) $zone_count ); ?></span>
						<span class="label"><?php esc_html_e( 'Shipping Zones', 'shipping-migrator-for-woocommerce' ); ?></span>
					</div>
					<div class="smfw-stat">
						<span class="num"><?php echo esc_html( (string) $class_count ); ?></span>
						<span class="label"><?php esc_html_e( 'Shipping Classes', 'shipping-migrator-for-woocommerce' ); ?></span>
					</div>
				</div>

				<form method="post">
					<?php wp_nonce_field( 'smfw_export', 'smfw_export_nonce' ); ?>
					<?php
					submit_button(
						__( 'Export Shipping Settings', 'shipping-migrator-for-woocommerce' ),
						'primary',
						'smfw_export',
						false
					);
					?>
				</form>
			</div>

			<div class="smfw-card">
				<h2><?php esc_html_e( '📥 Import Shipping Settings', 'shipping-migrator-for-woocommerce' ); ?></h2>
				<p>
					<?php
					esc_html_e(
						'Upload a JSON file previously exported from another site to import its shipping configuration here.',
						'shipping-migrator-for-woocommerce'
					);
					?>
				</p>

				<?php // enctype is required or the browser will not send the file. ?>
				<form method="post" enctype="multipart/form-data">
					<?php wp_nonce_field( 'smfw_import', 'smfw_import_nonce' ); ?>

					<p>
						<label for="smfw_import_file">
							<strong><?php esc_html_e( 'Select export file (.json):', 'shipping-migrator-for-woocommerce' ); ?></strong>
						</label>
						<br>
						<input type="file" name="smfw_import_file" id="smfw_import_file" accept=".json" required>
					</p>

					<div class="smfw-options">
						<label>
							<input type="checkbox" name="smfw_dry_run" value="1" checked>
							<strong><?php esc_html_e( 'Dry run', 'shipping-migrator-for-woocommerce' ); ?></strong>
							<?php esc_html_e( '— preview what will be imported without making changes', 'shipping-migrator-for-woocommerce' ); ?>
						</label>
						<label>
							<input type="checkbox" name="smfw_skip_existing" value="1" checked>
							<strong><?php esc_html_e( 'Skip existing zones', 'shipping-migrator-for-woocommerce' ); ?></strong>
							<?php esc_html_e( "— don't duplicate zones that already exist (matched by name)", 'shipping-migrator-for-woocommerce' ); ?>
						</label>
						<label>
							<input type="checkbox" name="smfw_import_general" value="1">
							<strong><?php esc_html_e( 'Import general shipping settings', 'shipping-migrator-for-woocommerce' ); ?></strong>
							<?php
							esc_html_e(
								"— overwrite this site's selling/shipping country restrictions and other general options (leave unchecked to import only zones, methods and classes)",
								'shipping-migrator-for-woocommerce'
							);
							?>
						</label>
					</div>

					<?php
					submit_button(
						__( 'Import Shipping Settings', 'shipping-migrator-for-woocommerce' ),
						'primary',
						'smfw_import',
						false
					);
					?>
				</form>
			</div>

			<div class="smfw-card">
				<h2><?php esc_html_e( 'ℹ️ Notes', 'shipping-migrator-for-woocommerce' ); ?></h2>
				<ul class="smfw-notes">
					<li>
						<?php
						printf(
							/* translators: %s: the words "dry run", wrapped in <strong>. */
							esc_html__( 'Always run a %s first to preview changes before committing.', 'shipping-migrator-for-woocommerce' ),
							'<strong>' . esc_html__( 'dry run', 'shipping-migrator-for-woocommerce' ) . '</strong>'
						);
						?>
					</li>
					<li>
						<strong><?php esc_html_e( 'Shipping class costs', 'shipping-migrator-for-woocommerce' ); ?></strong>
						<?php
						esc_html_e(
							'are automatically remapped between sites using slug matching. The import log shows each cost key that was rewritten. If a class cannot be matched, the key is kept as-is and flagged with a warning.',
							'shipping-migrator-for-woocommerce'
						);
						?>
					</li>
					<li>
						<strong><?php esc_html_e( 'Zone 0', 'shipping-migrator-for-woocommerce' ); ?></strong>
						<?php
						esc_html_e(
							'("Locations not covered by your other zones") always exists — the plugin imports its methods but will not duplicate the zone itself.',
							'shipping-migrator-for-woocommerce'
						);
						?>
					</li>
					<li><?php esc_html_e( 'WooCommerce shipping transients are cleared automatically after an import.', 'shipping-migrator-for-woocommerce' ); ?></li>
					<li>
						<?php
						printf(
							/* translators: %s: the word "not", wrapped in <strong>. */
							esc_html__( 'This plugin does %s delete any existing shipping configuration — it only adds to it.', 'shipping-migrator-for-woocommerce' ),
							'<strong>' . esc_html__( 'not', 'shipping-migrator-for-woocommerce' ) . '</strong>'
						);
						?>
					</li>
				</ul>
			</div>
		</div>
		<?php
	}
}
