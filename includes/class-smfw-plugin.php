<?php
/**
 * Plugin orchestrator: owns the collaborators and wires the hooks.
 *
 * @package Shipping_Migrator_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class SMFW_Plugin
 *
 * Deliberately thin. It creates the three collaborators and connects them to
 * WordPress; all behaviour lives in those classes. Keeping the wiring in one
 * small file means you can see the plugin's entire surface area — every hook
 * it registers — at a glance.
 */
class SMFW_Plugin {

	/**
	 * Singleton instance.
	 *
	 * A singleton rather than a global because the hooks below must be
	 * registered exactly once; registering them twice would run an import
	 * twice against the same POST.
	 *
	 * @var SMFW_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Export handler.
	 *
	 * @var SMFW_Exporter
	 */
	private $exporter;

	/**
	 * Import handler.
	 *
	 * @var SMFW_Importer
	 */
	private $importer;

	/**
	 * Admin screen renderer.
	 *
	 * @var SMFW_Admin_Page
	 */
	private $admin_page;

	/**
	 * Get (and lazily create) the singleton.
	 *
	 * @return SMFW_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor: build collaborators and register hooks.
	 */
	private function __construct() {
		$this->exporter   = new SMFW_Exporter();
		$this->importer   = new SMFW_Importer();
		$this->admin_page = new SMFW_Admin_Page();

		$this->register_hooks();
	}

	/**
	 * Register every hook the plugin uses.
	 *
	 * Export and import both listen on `admin_init` rather than on a form
	 * action, because `admin_init` runs before any output — which the export
	 * needs in order to send download headers. Each handler checks its own
	 * nonce and returns immediately when the request is not for it, so the
	 * cost on an unrelated admin page load is one isset() call.
	 *
	 * @return void
	 */
	private function register_hooks() {
		add_action( 'admin_menu', array( $this->admin_page, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this->admin_page, 'enqueue_assets' ) );

		add_action( 'admin_init', array( $this->exporter, 'maybe_handle_request' ) );
		add_action( 'admin_init', array( $this->importer, 'maybe_handle_request' ) );

		// "Settings" shortcut on the Plugins screen.
		add_filter(
			'plugin_action_links_' . plugin_basename( SMFW_PLUGIN_FILE ),
			array( $this, 'add_action_links' )
		);
	}

	/**
	 * Add a direct link to the tool from the Plugins list.
	 *
	 * @param string[] $links Existing action links.
	 * @return string[] Modified links.
	 */
	public function add_action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . SMFW_PAGE_SLUG ) ),
			esc_html__( 'Open Migrator', 'shipping-migrator-for-woocommerce' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Prevent cloning of the singleton.
	 *
	 * @return void
	 */
	private function __clone() {}

	/**
	 * Prevent unserialising of the singleton.
	 *
	 * @throws Exception Always.
	 * @return void
	 */
	public function __wakeup() {
		throw new Exception( 'SMFW_Plugin cannot be unserialized.' );
	}
}
