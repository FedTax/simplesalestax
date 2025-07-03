<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main SimpleSalesTax class.
 *
 * @package SST
 * @author  TaxCloud
 * @since   1.0
 */
final class SimpleSalesTax {

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	const VERSION = '8.2.4';

	/**
	 * Plugin instance.
	 *
	 * @var SimpleSalesTax
	 */
	private static $instance = null;

	/**
	 * Returns the plugin instance.
	 *
	 * @return SimpleSalesTax
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->define_constants();
		$this->load_files_safely();
		$this->add_hooks();
	}

	/**
	 * Defines plugin constants.
	 */
	protected function define_constants() {
		define( 'SST_VERSION', self::VERSION );
		define( 'SST_FILE', __FILE__ );
		define( 'SST_PATH', plugin_dir_path( SST_FILE ) );
		define( 'SST_URL', plugin_dir_url( SST_FILE ) );
		define( 'SST_BASENAME', plugin_basename( SST_FILE ) );
	}

	/**
	 * Loads files safely without dependencies.
	 */
	private function load_files_safely() {
		// Get the plugin root directory (one level up from includes).
		$plugin_root = dirname( SST_PATH );

		// Abstract classes (must be loaded first).
		require_once $plugin_root . '/includes/abstracts/class-sst-abstract-cart.php';
		require_once $plugin_root . '/includes/abstracts/class-sst-marketplace-integration.php';

		// Core classes that don't depend on WooCommerce.
		require_once $plugin_root . '/includes/class-sst-settings.php';
		require_once $plugin_root . '/includes/class-sst-product.php';
		require_once $plugin_root . '/includes/class-sst-order.php';
		require_once $plugin_root . '/includes/class-sst-order-controller.php';
		require_once $plugin_root . '/includes/class-sst-certificates.php';
		require_once $plugin_root . '/includes/class-sst-addresses.php';
		require_once $plugin_root . '/includes/class-sst-origin-address.php';
		require_once $plugin_root . '/includes/class-sst-tic.php';
		require_once $plugin_root . '/includes/class-sst-shipping.php';
		require_once $plugin_root . '/includes/class-sst-assets.php';
		require_once $plugin_root . '/includes/class-sst-ajax.php';
		require_once $plugin_root . '/includes/class-sst-logger.php';
		require_once $plugin_root . '/includes/class-sst-updater.php';
		require_once $plugin_root . '/includes/class-sst-blocks.php';
		require_once $plugin_root . '/includes/class-sst-blocks-integration.php';

		// Functions.
		require_once $plugin_root . '/includes/sst-functions.php';
		require_once $plugin_root . '/includes/sst-message-functions.php';
		require_once $plugin_root . '/includes/sst-update-functions.php';
		require_once $plugin_root . '/includes/sst-compatibility-functions.php';

		// Admin classes.
		if ( is_admin() ) {
			require_once $plugin_root . '/includes/admin/class-sst-admin.php';
			require_once $plugin_root . '/includes/admin/class-sst-integration.php';
		}

		// Frontend classes.
		if ( ! is_admin() || defined( 'DOING_AJAX' ) ) {
			require_once $plugin_root . '/includes/frontend/class-sst-checkout.php';
			require_once $plugin_root . '/includes/frontend/class-sst-my-account.php';
			require_once $plugin_root . '/includes/frontend/class-sst-cart-proxy.php';
		}
	}

	/**
	 * Adds WordPress hooks.
	 */
	private function add_hooks() {
		add_action( 'plugins_loaded', array( $this, 'init' ) );
		add_action( 'woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );
		add_action( 'woocommerce_init', array( $this, 'declare_cart_block_compatibility' ) );
		add_action( 'woocommerce_init', array( $this, 'init_logger' ) );
		add_action( 'woocommerce_init', array( $this, 'init_updater' ) );
		add_filter( 'query_vars', array( $this, 'add_tax_exemptions_query_var' ) );
	}

	/**
	 * Loads integration classes after WooCommerce is available.
	 */
	private function load_integrations() {
		$plugin_root = dirname( SST_PATH );
		$integrations_dir = $plugin_root . '/includes/integrations';

		// Make sure is_plugin_active() is defined before using it.
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// WooCommerce Subscriptions.
		if ( class_exists( 'WC_Subscriptions' ) ) {
			require_once $integrations_dir . '/class-sst-subscriptions.php';
		}

		// Dokan.
		if ( class_exists( 'WeDevs_Dokan' ) ) {
			require_once $integrations_dir . '/class-sst-dokan.php';
		}

		// WCFM Marketplace.
		if ( class_exists( 'WCFMmp' ) ) {
			require_once $integrations_dir . '/class-sst-wcfm.php';
		}

		// WC Marketplace.
		if ( class_exists( 'WCMp' ) ) {
			require_once $integrations_dir . '/class-sst-wcmp.php';
		}

		// Composite Products.
		if ( class_exists( 'WC_Composite_Products' ) ) {
			require_once $integrations_dir . '/class-sst-composite-products.php';
		}

		// Deposits for WooCommerce.
		if ( is_plugin_active( 'deposits-for-woocommerce/deposits-for-woocommerce.php' ) ) {
			require_once $integrations_dir . '/class-sst-deposits-for-wc.php';
		}
	}

	/**
	 * Initializes the plugin.
	 */
	public function init() {
		if ( ! $this->check_environment() ) {
			return;
		}

		$this->load_text_domain();
		$this->load_integrations();
	}

	/**
	 * Activates the plugin.
	 */
	public function activate() {
		if ( ! $this->check_environment() ) {
			return;
		}

		require_once dirname( SST_PATH ) . '/includes/class-sst-install.php';
		SST_Install::install();
	}

	/**
	 * Deactivates the plugin.
	 */
	public function deactivate() {
		// Cleanup if needed.
	}

	/**
	 * What type of request is this?
	 *
	 * @param string $type Request type to check for. Can be 'ajax', 'frontend', or 'admin'.
	 *
	 * @return bool
	 * @since 4.4
	 */
	private function is_request( $type ) {
		switch ( $type ) {
			case 'admin':
				return is_admin();
			case 'ajax':
				return defined( 'DOING_AJAX' );
			case 'cron':
				return defined( 'DOING_CRON' );
			case 'frontend':
				return ( ! is_admin() || defined( 'DOING_AJAX' ) ) && ! defined( 'DOING_CRON' );
		}

		return false;
	}

	/**
	 * Loads the plugin text domain.
	 */
	public function load_text_domain() {
		load_plugin_textdomain( 'simple-sales-tax', false, basename( dirname( SST_FILE ) ) . '/languages' );
	}

	/**
	 * Checks the environment for compatible versions of PHP and WooCommerce.
	 *
	 * @return bool True if the installed PHP and WooCommerce are compatible, false otherwise.
	 */
	private function check_environment() {
		// Make sure is_plugin_active() is defined.
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		// Check PHP version.
		if ( version_compare( phpversion(), '7.2', '<' ) ) {
			add_action( 'admin_notices', array( $this, 'php_version_notice' ) );

			return false;
		}

		// Check WooCommerce version.
		if ( ! is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_required_notice' ) );

			return false;
		} elseif ( ! defined( 'WC_VERSION' ) || version_compare( WC_VERSION, '6.9', '<' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_version_notice' ) );

			return false;
		}

		if ( $this->detect_plugin_conflicts() ) {
			return false;
		}

		return true;
	}

	/**
	 * Checks for plugins that conflict with TaxCloud for WooCommerce.
	 *
	 * @return bool Were any conflicting plugins detected?
	 */
	private function detect_plugin_conflicts() {
		if ( class_exists( 'WC_TaxJar' ) ) {
			// TaxJar.
			add_action( 'admin_notices', array( $this, 'taxjar_conflict_notice' ) );
			return true;
		} elseif ( class_exists( 'WC_AvaTax_Loader' ) ) {
			// WooCommerce AvaTax.
			add_action( 'admin_notices', array( $this, 'avatax_conflict_notice' ) );
			return true;
		} elseif ( class_exists( 'WC_Connect_Loader' ) && 'yes' === get_option( 'wc_connect_taxes_enabled' ) ) {
			// WooCommerce Services Automated Taxes.
			add_action( 'admin_notices', array( $this, 'woocommerce_services_notice' ) );
			return true;
		}

		return false;
	}

	/**
	 * Notice displayed when the TaxJar plugin is activated.
	 */
	public function taxjar_conflict_notice() {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			__( // phpcs:ignore WordPress.Security.EscapeOutput
				'<strong>TaxCloud for WooCommerce is inactive.</strong> TaxCloud for WooCommerce cannot be used alongside the <a href="https://wordpress.org/plugins/taxjar-simplified-taxes-for-woocommerce/" target="_blank">TaxJar</a> plugin. Please deactivate TaxJar to use TaxCloud for WooCommerce.',
				'simple-sales-tax'
			)
		);
	}

	/**
	 * Notice displayed when the WooCommerce AvaTax plugin is activated.
	 */
	public function avatax_conflict_notice() {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			__( // phpcs:ignore WordPress.Security.EscapeOutput
				'<strong>TaxCloud for WooCommerce is inactive.</strong> TaxCloud for WooCommerce cannot be used alongside the <a href="https://woocommerce.com/products/woocommerce-avatax/" target="_blank">WooCommerce AvaTax</a> plugin. Please deactivate WooCommerce AvaTax to use TaxCloud for WooCommerce.',
				'simple-sales-tax'
			)
		);
	}

	/**
	 * Notice displayed when the WooCommerce Services Automated Tax service
	 * is enabled.
	 */
	public function woocommerce_services_notice() {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			__( // phpcs:ignore WordPress.Security.EscapeOutput
				'<strong>TaxCloud for WooCommerce is inactive.</strong> TaxCloud for WooCommerce cannot be used alongside <a href="https://docs.woocommerce.com/document/woocommerce-services/#section-10" target="_blank">WooCommerce Services Automated Taxes</a>. Please disable automated taxes to use TaxCloud for WooCommerce.',
				'simple-sales-tax'
			)
		);
	}

	/**
	 * Notice displayed when the installed version of PHP is not compatible.
	 */
	public function php_version_notice() {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			__( '<strong>PHP needs to be updated.</strong> TaxCloud for WooCommerce requires PHP 7.2+.', 'simple-sales-tax' ) // phpcs:ignore WordPress.Security.EscapeOutput
		);
	}

	/**
	 * Notice displayed if WooCommerce is not installed or inactive.
	 */
	public function woocommerce_required_notice() {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			__( // phpcs:ignore WordPress.Security.EscapeOutput
				'<strong>WooCommerce not detected.</strong> Please install or activate WooCommerce to use TaxCloud for WooCommerce.',
				'simple-sales-tax'
			)
		);
	}

	/**
	 * Notice displayed if the installed version of WooCommerce is not compatible.
	 */
	public function woocommerce_version_notice() {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			__( // phpcs:ignore WordPress.Security.EscapeOutput
				'<strong>WooCommerce needs to be updated.</strong> TaxCloud for WooCommerce requires WooCommerce 6.9.0+.',
				'simple-sales-tax'
			)
		);
	}

	/**
	 * Gets the full path to a file or directory in the plugin directory.
	 *
	 * @param string $path Relative path to file or directory.
	 *
	 * @return string
	 */
	public function path( $path = '' ) {
		return plugin_dir_path( SST_FILE ) . $path;
	}

	/**
	 * Gets the URL of a file or directory in the plugin directory.
	 *
	 * @param string $path Relative path to file or directory.
	 *
	 * @return string
	 */
	public function url( $path ) {
		return plugin_dir_url( SST_FILE ) . $path;
	}

	/**
	 * Declare compatibility with WooCommerce's High-Performance Order Storage.
	 */
	public function declare_hpos_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}

	/**
	 * Declare compatibility with WooCommerce's Cart and Checkout Blocks.
	 */
	public function declare_cart_block_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}

	/**
	 * Adds the tax_exemptions query var.
	 *
	 * @param array $query_vars Query vars.
	 *
	 * @return array
	 */
	public function add_tax_exemptions_query_var( $query_vars ) {
		$query_vars[] = 'tax_exemptions';

		return $query_vars;
	}

	/**
	 * Initializes the logger after WooCommerce is loaded.
	 */
	public function init_logger() {
		if ( class_exists( 'SST_Logger' ) ) {
			SST_Logger::init();
		}
	}

	/**
	 * Initializes the updater after WooCommerce is loaded.
	 */
	public function init_updater() {
		if ( function_exists( 'sst_init_wc_dependencies' ) ) {
			sst_init_wc_dependencies();
		}
	}
}
