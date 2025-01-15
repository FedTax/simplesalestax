<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Simple Sales Tax.
 *
 * Main plugin class.
 *
 * @author  Simple Sales Tax
 * @package SST
 * @since   6.0.0
 */
final class SimpleSalesTax {

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	public $version = '8.3.0';

	/**
	 * The singleton plugin instance.
	 *
	 * @var SimpleSalesTax
	 */
	protected static $instance = null;

	/**
	 * Singleton instance accessor.
	 *
	 * @return SimpleSalesTax
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Plugin constructor.
	 */
	private function __construct() {
		$this->define_constants();
		$this->load_text_domain();

		add_action( 'plugins_loaded', array( $this, 'init' ) );
		add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );
		add_action( 'before_woocommerce_init', array( $this, 'declare_cart_block_compatibility' ) );
		add_filter( 'woocommerce_get_query_vars', array( $this, 'add_tax_exemptions_query_var' ) );
	}

	/**
	 * Initializes the plugin on `plugins_loaded` if all requirements are met.
	 */
	public function init() {
		if ( $this->check_environment() ) {
			$this->includes();
			$this->add_hooks();
		}
	}

	/**
	 * Defines plugin constants.
	 */
	protected function define_constants() {
		define( 'SST_DEFAULT_SHIPPING_TIC', 11010 );
		define( 'SST_SHIPPING_ITEM', 'SHIPPING' );
		define( 'SST_DEFAULT_FEE_TIC', 10010 );
		define( 'SST_RATE_ID', get_option( 'wootax_rate_id' ) );
		define( 'SST_DIR', dirname( dirname( __FILE__ ) ) );
		define( 'SST_FILE', SST_DIR . '/simple-sales-tax.php' );
		define( 'SST_PLUGIN_BASENAME', plugin_basename( SST_FILE ) );
		define( 'SST_SINGLE_PURCHASE_CERT_ID', 'single-purchase' );
	}

	/**
	 * Runs on plugin activation.
	 *
	 * Adjusts WooCommerce settings for optimal plugin performance.
	 *
	 * @since 6.0.0
	 */
	public function activate() {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'no' );
		update_option( 'woocommerce_tax_based_on', 'shipping' );
		update_option( 'woocommerce_default_customer_address', 'base' );
		update_option( 'woocommerce_shipping_tax_class', '' );
		update_option( 'woocommerce_tax_round_at_subtotal', false );
		update_option( 'woocommerce_tax_display_shop', 'excl' );
		update_option( 'woocommerce_tax_display_cart', 'excl' );
		update_option( 'woocommerce_tax_total_display', 'itemized' );
	}

	/**
	 * Runs on plugin deactivation.
	 *
	 * @since 6.0.0
	 */
	public function deactivate() {
		SST_Install::deactivate();
	}

	/**
	 * Include required plugin files.
	 *
	 * @since 4.4
	 */
	private function includes() {
		/**
		 * Abstract classes.
		 */
		require_once __DIR__ . '/abstracts/class-sst-abstract-cart.php';

		/**
		 * Core classes.
		 */
		require_once __DIR__ . '/sst-functions.php';
		require_once __DIR__ . '/sst-compatibility-functions.php';
		require_once __DIR__ . '/class-sst-install.php';
		require_once __DIR__ . '/class-sst-settings.php';
		require_once __DIR__ . '/class-sst-logger.php';
		require_once __DIR__ . '/class-sst-ajax.php';
		require_once __DIR__ . '/class-sst-tic.php';
		require_once __DIR__ . '/class-sst-product.php';
		require_once __DIR__ . '/class-sst-shipping.php';
		require_once __DIR__ . '/class-sst-addresses.php';
		require_once __DIR__ . '/class-sst-origin-address.php';
		require_once __DIR__ . '/class-sst-certificates.php';
		require_once __DIR__ . '/class-sst-order.php';
		require_once __DIR__ . '/class-sst-order-controller.php';
		require_once __DIR__ . '/class-sst-assets.php';
		require_once __DIR__ . '/class-sst-marketplaces.php';
		require_once __DIR__ . '/class-sst-blocks.php';

		/**
		 * Third party integrations.
		 */
		$this->load_integrations();

		/**
		 * Admin only.
		 */
		if ( $this->is_request( 'admin' ) ) {
			require_once __DIR__ . '/admin/class-sst-admin.php';
		}

		/**
		 * Frontend only.
		 */
		if ( $this->is_request( 'frontend' ) ) {
			require_once __DIR__ . '/frontend/class-sst-cart-proxy.php';
			require_once __DIR__ . '/frontend/class-sst-checkout.php';
			require_once __DIR__ . '/frontend/class-sst-my-account.php';
		}
	}

	/**
	 * Registers the plugin activation and deactivation hooks.
	 */
	private function add_hooks() {
		register_activation_hook( SST_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( SST_FILE, array( $this, 'deactivate' ) );
	}

	/**
	 * Loads integrations with third party extensions as needed.
	 */
	private function load_integrations() {
		$integrations_dir = __DIR__ . '/integrations';

		// WooCommerce Subscriptions by Prospress.
		if ( sst_subs_active() ) {
			require_once $integrations_dir . '/class-sst-subscriptions.php';
		}

		// WooCommerce Composite Products.
		if ( is_plugin_active( 'woocommerce-composite-products/woocommerce-composite-products.php' ) ) {
			require_once $integrations_dir . '/class-sst-composite-products.php';
		}

		// Deposits for WooCommerce.
		if ( is_plugin_active( 'deposits-for-woocommerce/deposits-for-woocommerce.php' ) ) {
			require_once $integrations_dir . '/class-sst-deposits-for-wc.php';
		}
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
	 * Checks for plugins that conflict with Simple Sales Tax.
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
				'<strong>Simple Sales Tax is inactive.</strong> Simple Sales Tax cannot be used alongside the <a href="https://wordpress.org/plugins/taxjar-simplified-taxes-for-woocommerce/" target="_blank">TaxJar</a> plugin. Please deactivate TaxJar to use Simple Sales Tax.',
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
				'<strong>Simple Sales Tax is inactive.</strong> Simple Sales Tax cannot be used alongside the <a href="https://woocommerce.com/products/woocommerce-avatax/" target="_blank">WooCommerce AvaTax</a> plugin. Please deactivate WooCommerce AvaTax to use Simple Sales Tax.',
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
				'<strong>Simple Sales Tax is inactive.</strong> Simple Sales Tax cannot be used alongside <a href="https://docs.woocommerce.com/document/woocommerce-services/#section-10" target="_blank">WooCommerce Services Automated Taxes</a>. Please disable automated taxes to use Simple Sales Tax.',
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
			__( '<strong>PHP needs to be updated.</strong> Simple Sales Tax requires PHP 7.2+.', 'simple-sales-tax' ) // phpcs:ignore WordPress.Security.EscapeOutput
		);
	}

	/**
	 * Notice displayed if WooCommerce is not installed or inactive.
	 */
	public function woocommerce_required_notice() {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			__( // phpcs:ignore WordPress.Security.EscapeOutput
				'<strong>WooCommerce not detected.</strong> Please install or activate WooCommerce to use Simple Sales Tax.',
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
				'<strong>WooCommerce needs to be updated.</strong> Simple Sales Tax requires WooCommerce 6.9.0+.',
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
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				SST_FILE,
				true
			);
		}
	}

	/**
	 * Declare compatibility with the Cart & Checkout blocks.
	 */
	public function declare_cart_block_compatibility() {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'cart_checkout_blocks',
				SST_FILE,
				true
			);
		}
	}

	/**
	 * Adds a `exemption-certificates` query var/endpoint for WooCommerce.
	 *
	 * @param array $query_vars WC query vars.
	 * @return array Modified query vars
	 */
	public function add_tax_exemptions_query_var( $query_vars ) {
		$query_vars[] = 'exemption-certificates';
		return $query_vars;
	}

}
