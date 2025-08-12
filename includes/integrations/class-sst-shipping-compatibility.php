<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Generic Shipping Compatibility Integration.
 *
 * Handles compatibility between various shipping plugins and Simple Sales Tax.
 * Supports LTL Freight Quotes, Small Package Quotes - UPS Edition, and other
 * shipping plugins that may conflict with SST tax calculations.
 *
 * @author  Simple Sales Tax
 * @package SST
 * @since   8.2.4
 */
class SST_Shipping_Compatibility {

	/**
	 * Supported shipping plugins and their detection methods.
	 *
	 * @var array
	 */
	private $supported_plugins = array(
		'ltl_freight_quotes' => array(
			'name' => 'LTL Freight Quotes - XPO Edition',
			'detection' => array(
				'class' => 'LTL_Freight_Quotes',
				'function' => 'ltl_freight_quotes_init',
				'plugin_files' => array(
					'ltl-freight-quotes-xpo/ltl-freight-quotes-xpo.php',
					'ltl-freight-quotes/ltl-freight-quotes.php',
					'woocommerce-ltl-freight/woocommerce-ltl-freight.php'
				)
			),
			'method_identifiers' => array(
				'ltl', 'freight', 'xpo', 'ltl_freight', 'ltl_freight_xpo', 'xpo_ltl', 'freight_ltl'
			),
			'tic_code' => 11010, // Transportation, shipping, postage, and similar charges
			'priority' => 10
		),
		'small_package_quotes' => array(
			'name' => 'Small Package Quotes - UPS Edition',
			'detection' => array(
				'class' => 'Small_Package_Quotes_UPS',
				'function' => 'small_package_quotes_ups_init',
				'plugin_files' => array(
					'small-package-quotes-ups/small-package-quotes-ups.php',
					'woocommerce-small-package-quotes/woocommerce-small-package-quotes.php',
					'ups-small-package-quotes/ups-small-package-quotes.php'
				)
			),
			'method_identifiers' => array(
				'small_package', 'ups_quotes', 'ups_small_package', 'package_quotes', 'ups_package'
			),
			'tic_code' => 11010, // Transportation, shipping, postage, and similar charges
			'priority' => 10
		),
		'freight_quotes_general' => array(
			'name' => 'General Freight Quotes',
			'detection' => array(
				'class' => 'Freight_Quotes',
				'function' => 'freight_quotes_init',
				'plugin_files' => array(
					'freight-quotes/freight-quotes.php',
					'woocommerce-freight-quotes/woocommerce-freight-quotes.php'
				)
			),
			'method_identifiers' => array(
				'freight', 'freight_quotes', 'freight_shipping', 'ltl', 'trucking'
			),
			'tic_code' => 11010,
			'priority' => 10
		)
	);

	/**
	 * Active plugins detected.
	 *
	 * @var array
	 */
	private $active_plugins = array();

	/**
	 * Constructor.
	 */
	private function __construct() {
		// Detect active shipping plugins
		$this->detect_active_plugins();

		// Only proceed if we have active plugins
		if ( empty( $this->active_plugins ) ) {
			return;
		}

		// Allow developers to disable this integration
		if ( ! apply_filters( 'wootax_shipping_compatibility_enabled', true ) ) {
			return;
		}

		$this->init_hooks();
	}

	/**
	 * Detect which shipping plugins are active.
	 */
	private function detect_active_plugins() {
		foreach ( $this->supported_plugins as $plugin_key => $plugin_config ) {
			if ( $this->is_plugin_active( $plugin_config['detection'] ) ) {
				$this->active_plugins[ $plugin_key ] = $plugin_config;
			}
		}
	}

	/**
	 * Check if a plugin is active based on its detection configuration.
	 *
	 * @param array $detection Detection configuration.
	 * @return bool
	 */
	private function is_plugin_active( $detection ) {
		// Check for class existence
		if ( ! empty( $detection['class'] ) && class_exists( $detection['class'] ) ) {
			return true;
		}

		// Check for function existence
		if ( ! empty( $detection['function'] ) && function_exists( $detection['function'] ) ) {
			return true;
		}

		// Check for plugin file activation
		if ( ! empty( $detection['plugin_files'] ) ) {
			foreach ( $detection['plugin_files'] as $plugin_file ) {
				if ( is_plugin_active( $plugin_file ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Initialize hooks for shipping compatibility.
	 */
	private function init_hooks() {
		// Ensure shipping methods are properly recognized
		add_filter( 'wootax_shipping_method_ids', array( $this, 'add_shipping_method_ids' ) );
		
		// Handle shipping package modifications
		add_filter( 'wootax_cart_packages_before_split', array( $this, 'fix_shipping_packages' ), 5 );
		
		// Ensure proper TIC codes for shipping
		add_filter( 'sst_shipping_tic', array( $this, 'set_shipping_tic' ), 10, 2 );
		
		// Handle timing conflicts with shipping calculations
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'ensure_shipping_compatibility' ), 5 );
		
		// Add admin notice for detected plugins
		add_action( 'admin_notices', array( $this, 'admin_notice' ) );
	}

	/**
	 * Add shipping method IDs to the recognized list.
	 *
	 * @param array $method_ids Array of shipping method IDs.
	 * @return array
	 */
	public function add_shipping_method_ids( $method_ids ) {
		$additional_methods = array();

		foreach ( $this->active_plugins as $plugin_config ) {
			$additional_methods = array_merge( $additional_methods, $plugin_config['method_identifiers'] );
		}

		return array_merge( $method_ids, $additional_methods );
	}

	/**
	 * Fix shipping packages to ensure compatibility with SST.
	 *
	 * @param array   $packages SST cart packages.
	 * @param WC_Cart $cart     Cart instance.
	 * @return array
	 */
	public function fix_shipping_packages( $packages, $cart ) {
		// Check if any supported shipping methods are being used
		$chosen_methods = WC()->session->get( 'chosen_shipping_methods', array() );
		$has_compatible_method = false;

		foreach ( $chosen_methods as $method ) {
			if ( $this->is_compatible_method( $method ) ) {
				$has_compatible_method = true;
				break;
			}
		}

		if ( ! $has_compatible_method ) {
			return $packages;
		}

		// Ensure packages have proper structure for compatible methods
		foreach ( $packages as $key => &$package ) {
			if ( isset( $package['rates'] ) ) {
				foreach ( $package['rates'] as $rate_key => $rate ) {
					if ( $this->is_compatible_method( $rate->method_id ) ) {
						// Ensure rates are properly formatted
						$package['rates'][ $rate_key ] = $this->format_shipping_rate( $rate );
					}
				}
			}
		}

		return $packages;
	}

	/**
	 * Set appropriate TIC code for shipping methods.
	 *
	 * @param int    $tic        Current TIC code.
	 * @param string $method_id  Shipping method ID.
	 * @return int
	 */
	public function set_shipping_tic( $tic, $method_id ) {
		foreach ( $this->active_plugins as $plugin_config ) {
			if ( $this->method_matches_identifiers( $method_id, $plugin_config['method_identifiers'] ) ) {
				return $plugin_config['tic_code'];
			}
		}

		return $tic;
	}

	/**
	 * Ensure compatibility with shipping calculations.
	 *
	 * @param WC_Cart $cart Cart instance.
	 */
	public function ensure_shipping_compatibility( $cart ) {
		// Ensure shipping calculations don't interfere with SST
		remove_action( 'woocommerce_cart_calculate_fees', array( $this, 'ensure_shipping_compatibility' ), 5 );
		
		// Re-add after SST calculations
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'ensure_shipping_compatibility' ), 15 );
	}

	/**
	 * Check if a shipping method is a compatible method.
	 *
	 * @param string $method_id Shipping method ID.
	 * @return bool
	 */
	private function is_compatible_method( $method_id ) {
		foreach ( $this->active_plugins as $plugin_config ) {
			if ( $this->method_matches_identifiers( $method_id, $plugin_config['method_identifiers'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if a method ID matches any of the given identifiers.
	 *
	 * @param string $method_id    Shipping method ID.
	 * @param array  $identifiers  Array of identifiers to check against.
	 * @return bool
	 */
	private function method_matches_identifiers( $method_id, $identifiers ) {
		foreach ( $identifiers as $identifier ) {
			if ( stripos( $method_id, $identifier ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Format shipping rate for SST compatibility.
	 *
	 * @param WC_Shipping_Rate $rate Shipping rate.
	 * @return WC_Shipping_Rate
	 */
	private function format_shipping_rate( $rate ) {
		// Ensure the rate has all required properties
		if ( ! isset( $rate->cost ) ) {
			$rate->cost = 0;
		}

		if ( ! isset( $rate->method_id ) ) {
			$rate->method_id = 'compatible_shipping';
		}

		return $rate;
	}

	/**
	 * Display admin notice about detected shipping plugins.
	 */
	public function admin_notice() {
		if ( empty( $this->active_plugins ) ) {
			return;
		}

		$plugin_names = array();
		foreach ( $this->active_plugins as $plugin_config ) {
			$plugin_names[] = $plugin_config['name'];
		}

		$message = sprintf(
			__( 'Simple Sales Tax has detected and is now compatible with: %s. Tax calculations for shipping charges from these plugins will be handled automatically.', 'simple-sales-tax' ),
			implode( ', ', $plugin_names )
		);

		printf(
			'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
			esc_html( $message )
		);
	}

	/**
	 * Get singleton instance.
	 *
	 * @return SST_Shipping_Compatibility
	 */
	public static function instance() {
		static $instance = null;

		if ( is_null( $instance ) ) {
			$instance = new self();
		}

		return $instance;
	}
}

// Initialize the integration only if WooCommerce is loaded
if ( class_exists( 'WooCommerce' ) ) {
	SST_Shipping_Compatibility::instance();
} 