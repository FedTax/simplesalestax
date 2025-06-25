<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * LTL Freight Quotes - XPO Edition Integration.
 *
 * Handles compatibility between LTL Freight Quotes and Simple Sales Tax.
 *
 * @author  Simple Sales Tax
 * @package SST
 * @since   8.2.4
 */
class SST_LTL_Freight {

	/**
	 * Minimum supported version of LTL Freight Quotes.
	 *
	 * @var string
	 */
	private $min_version = '1.0.0';

	/**
	 * Constructor.
	 */
	private function __construct() {
		// Check if LTL Freight Quotes is active
		if ( ! $this->is_ltl_freight_active() ) {
			return;
		}

		// Allow developers to disable this integration
		if ( ! apply_filters( 'wootax_ltl_freight_integration_enabled', true ) ) {
			return;
		}

		$this->init_hooks();
	}

	/**
	 * Initialize hooks for LTL Freight Quotes integration.
	 */
	private function init_hooks() {
		// Ensure LTL shipping methods are properly recognized
		add_filter( 'wootax_shipping_method_ids', array( $this, 'add_ltl_method_ids' ) );
		
		// Handle LTL shipping package modifications
		add_filter( 'wootax_cart_packages_before_split', array( $this, 'fix_ltl_packages' ), 5 );
		
		// Ensure proper TIC codes for LTL shipping
		add_filter( 'sst_shipping_tic', array( $this, 'set_ltl_shipping_tic' ), 10, 2 );
		
		// Handle timing conflicts with LTL calculations
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'ensure_ltl_compatibility' ), 5 );
	}

	/**
	 * Check if LTL Freight Quotes plugin is active.
	 *
	 * @return bool
	 */
	private function is_ltl_freight_active() {
		return class_exists( 'LTL_Freight_Quotes' ) || 
			   function_exists( 'ltl_freight_quotes_init' ) ||
			   is_plugin_active( 'ltl-freight-quotes/ltl-freight-quotes.php' );
	}

	/**
	 * Add LTL shipping method IDs to the recognized list.
	 *
	 * @param array $method_ids Array of shipping method IDs.
	 * @return array
	 */
	public function add_ltl_method_ids( $method_ids ) {
		$ltl_methods = array(
			'ltl_freight',
			'ltl_freight_xpo',
			'xpo_ltl',
			'freight_ltl',
			// Add any other LTL method IDs used by the plugin
		);

		return array_merge( $method_ids, $ltl_methods );
	}

	/**
	 * Fix LTL shipping packages to ensure compatibility with SST.
	 *
	 * @param array   $packages SST cart packages.
	 * @param WC_Cart $cart     Cart instance.
	 * @return array
	 */
	public function fix_ltl_packages( $packages, $cart ) {
		// Check if any LTL methods are being used
		$chosen_methods = WC()->session->get( 'chosen_shipping_methods', array() );
		$has_ltl = false;

		foreach ( $chosen_methods as $method ) {
			if ( $this->is_ltl_method( $method ) ) {
				$has_ltl = true;
				break;
			}
		}

		if ( ! $has_ltl ) {
			return $packages;
		}

		// Ensure packages have proper structure for LTL methods
		foreach ( $packages as $key => &$package ) {
			if ( isset( $package['rates'] ) ) {
				foreach ( $package['rates'] as $rate_key => $rate ) {
					if ( $this->is_ltl_method( $rate->method_id ) ) {
						// Ensure LTL rates are properly formatted
						$package['rates'][ $rate_key ] = $this->format_ltl_rate( $rate );
					}
				}
			}
		}

		return $packages;
	}

	/**
	 * Set appropriate TIC code for LTL shipping methods.
	 *
	 * @param int    $tic        Current TIC code.
	 * @param string $method_id  Shipping method ID.
	 * @return int
	 */
	public function set_ltl_shipping_tic( $tic, $method_id ) {
		if ( $this->is_ltl_method( $method_id ) ) {
			// Use TIC 11010 for LTL freight (Transportation, shipping, postage, and similar charges)
			return 11010;
		}

		return $tic;
	}

	/**
	 * Ensure compatibility with LTL calculations.
	 *
	 * @param WC_Cart $cart Cart instance.
	 */
	public function ensure_ltl_compatibility( $cart ) {
		// Ensure LTL calculations don't interfere with SST
		remove_action( 'woocommerce_cart_calculate_fees', array( $this, 'ensure_ltl_compatibility' ), 5 );
		
		// Re-add after SST calculations
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'ensure_ltl_compatibility' ), 15 );
	}

	/**
	 * Check if a shipping method is an LTL method.
	 *
	 * @param string $method_id Shipping method ID.
	 * @return bool
	 */
	private function is_ltl_method( $method_id ) {
		$ltl_identifiers = array(
			'ltl',
			'freight',
			'xpo',
			'ltl_freight',
			'ltl_freight_xpo',
			'xpo_ltl',
			'freight_ltl',
		);

		foreach ( $ltl_identifiers as $identifier ) {
			if ( stripos( $method_id, $identifier ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Format LTL shipping rate for SST compatibility.
	 *
	 * @param WC_Shipping_Rate $rate Shipping rate.
	 * @return WC_Shipping_Rate
	 */
	private function format_ltl_rate( $rate ) {
		// Ensure the rate has all required properties
		if ( ! isset( $rate->cost ) ) {
			$rate->cost = 0;
		}

		if ( ! isset( $rate->method_id ) ) {
			$rate->method_id = 'ltl_freight';
		}

		return $rate;
	}

	/**
	 * Get singleton instance.
	 *
	 * @return SST_LTL_Freight
	 */
	public static function instance() {
		static $instance = null;

		if ( is_null( $instance ) ) {
			$instance = new self();
		}

		return $instance;
	}
}

// Initialize the integration
SST_LTL_Freight::instance(); 