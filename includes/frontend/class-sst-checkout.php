<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use \Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use \TaxCloud\ExemptionCertificate;
use \TaxCloud\ExemptionCertificateBase;

/**
 * Checkout.
 *
 * Responsible for computing the sales tax due during checkout.
 *
 * @author  Simple Sales Tax
 * @package SST
 * @since   5.0
 */
class SST_Checkout extends SST_Abstract_Cart {

	/**
	 * The cart we are calculating taxes for.
	 *
	 * @var WC_Cart
	 */
	private $cart = null;

	/**
	 * Cart validation errors.
	 *
	 * @var array
	 */
	private $errors = array();

	/**
	 * Constructor: Initialize hooks.
	 *
	 * @since 5.0
	 */
	public function __construct() {
		add_filter( 'woocommerce_calculated_total', array( $this, 'calculate_tax_totals' ), 1100, 2 );
		add_filter( 'woocommerce_cart_hide_zero_taxes', array( $this, 'hide_zero_taxes' ) );
		add_action( 'woocommerce_checkout_order_created', array( $this, 'handle_legacy_checkout' ) );
		add_action( 'woocommerce_cart_emptied', array( $this, 'clear_session_data' ) );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_legacy_checkout' ), 10, 2 );
		add_filter( 'woocommerce_add_cart_item', array( $this, 'set_key_for_cart_item' ), 10, 2 );
		add_filter( 'wootax_cart_packages', array( $this, 'handle_negative_fees' ), PHP_INT_MAX - 1 );
		add_action( 'woocommerce_init', array( $this, 'init_certificate_id' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'handle_checkout' ), 10, 2 );
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'add_co_excise_tax_fee' ) );
		add_action( 'template_redirect', array( $this, 'add_rate_limit_notice' ), 10 );

		if ( sst_storefront_active() ) {
			add_action( 'woocommerce_checkout_shipping', array( $this, 'output_exemption_form' ), 15 );
		} else {
			add_action( 'woocommerce_checkout_after_customer_details', array( $this, 'output_exemption_form' ) );
		}

		add_action( 'woocommerce_checkout_create_order_shipping_item', array( $this, 'add_shipping_meta' ), 10, 3 );

		parent::__construct();

	}

	/**
	 * Perform a tax lookup and update the sales tax for all items.
	 *
	 * IMPORTANT: This hook needs to run after WC_Subscriptions_Cart::calculate_subscription_totals().
	 *
	 * @param float   $total Calculated total for cart.
	 * @param WC_Cart $cart  Cart object.
	 *
	 * @return float
	 * @since 5.0
	 */
	public function calculate_tax_totals( $total, $cart ) {
		// Disable tax calculation if integration is disabled
		if ( 'yes' === SST_Settings::get( 'disable_integration', 'no' ) ) {
			return $total;
		}

		// The Tax Exemption for WooCommerce (PRO) plugin sets the
		// is_tax_exempt session variable, and we need to respect that so we
		// can allow customers that aren't logged in to show no tax when
		// they're tax-exempt.
		if ( WC()->session->get( 'is_tax_exempt', false ) ) {
			return $total;
		}

		// Add session readiness check to prevent tax calculation failures
		if ( ! WC()->session ) {
			// Session not ready or no packages, skip tax calculation this time
			// This prevents the refresh requirement issue
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				SST_Logger::add( __( 'SST: Session not ready for tax calculation, skipping to prevent refresh requirement.', 'simple-sales-tax' ) );
			}
			return $total;
		}

		$tax_total = 0;

		$this->cart = new SST_Cart_Proxy( $cart );

		$should_calculate = (
			is_cart() ||
			is_checkout() ||
			doing_action( 'wc_ajax_square_digital_wallet_recalculate_totals' ) ||
			$this->is_store_api_request() ||
			$this->is_edit_subscription_request() ||
			$this->is_stripe_express_checkout_request()
		);

		if ( apply_filters( 'sst_calculate_tax_totals', $should_calculate ) ) {
			$this->calculate_taxes();

			/**
			 * Skip tax calculation if real-time tax calculation is disabled. [Data Import Mode]
			 * @since 8.4.1
			 */
			if ( 'data_mover' === sst_integration_mode() ) {
				SST_Logger::add( __( 'Data mover mode. Skipping tax calculation.', 'simple-sales-tax' ) );
				return $total;
			}

			/**
			 * Woo won't include the taxes calculated by SST in the total so
			 * we add them in here.
			 */
			foreach ( $this->cart->get_taxes() as $rate_id => $tax ) {
				if ( (int) SST_RATE_ID === (int) $rate_id ) {
					$tax_total += $tax;
				}
			}

			$this->cart->set_total_tax( WC_Tax::get_tax_total( $this->cart->get_taxes() ) );
		}

		return $total + $tax_total;
	}

	/**
	 * Check if is request to the Store API.
	 *
	 * @return bool
	 */
	protected function is_store_api_request() {
		global $wp;
		$rest_route = $wp->query_vars['rest_route'] ?? '';
		return 0 === strpos( $rest_route, '/wc/store/' );
	}

	/**
	 * Check come from edit-subscription
	 * @return bool
	 */
	protected function is_edit_subscription_request() {
		global $wp;
		$subscriptionId = $wp->query_vars['edit-subscription'] ?? '';
		if( !$subscriptionId ){
			$subscriptionId = $wp->query_vars['view-subscription'] ?? '';
		}
		return $subscriptionId > 0;
	}

	/**
	 * Check if is request to the Stripe Express Checkout.
	 *
	 * @return bool
	 * @since 8.3.5
	 */
	protected function is_stripe_express_checkout_request() {
		global $wp_query;

		$wc_ajax = '';
		if ( ! empty( $_GET['wc-ajax'] ) ) {
			$wc_ajax = sanitize_text_field( wp_unslash( $_GET['wc-ajax'] ) );
		} elseif ( ! empty( $_POST['wc-ajax'] ) ) {
			$wc_ajax = sanitize_text_field( wp_unslash( $_POST['wc-ajax'] ) );
		} elseif ( ! empty( $_REQUEST['wc-ajax'] ) ) {
			$wc_ajax = sanitize_text_field( wp_unslash( $_REQUEST['wc-ajax'] ) );
		} elseif ( $wp_query && is_object( $wp_query ) && ! empty( $wp_query->get( 'wc-ajax' ) ) ) {
			$wc_ajax = sanitize_text_field( $wp_query->get( 'wc-ajax' ) );
		}

		if ( ! empty( $wc_ajax ) && 0 === strpos( $wc_ajax, 'wc_stripe_' ) ) {
			return true;
		}

		$stripe_actions = array(
			'wc_ajax_wc_stripe_get_shipping_options',
			'wc_ajax_wc_stripe_update_shipping_method',
			'wc_ajax_wc_stripe_get_cart_details',
			'wc_ajax_wc_stripe_get_selected_product_data',
			'wc_ajax_wc_stripe_add_to_cart',
			'wc_ajax_wc_stripe_create_payment_intent',
			'wc_ajax_wc_stripe_update_payment_intent',
			'wc_ajax_wc_stripe_create_checkout_session',
			'wc_ajax_wc_stripe_update_checkout_session',
			'wc_ajax_wc_stripe_checkout',
		);

		foreach ( $stripe_actions as $action ) {
			if ( doing_action( $action ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Calculates the tax due for the cart.
	 */
	public function calculate_taxes() {
		$this->errors = array();

		if ( 'new' === $this->get_certificate_id() ) {
			// Assume 0 tax until checkout is processed and new certificate is saved
			$this->reset_taxes();
			$this->update_taxes();

			return true;
		}

		// Try to use cached packages first, create new ones if needed
		if ( ! $this->get_packages() ) {
			// No cached packages, create new ones to prevent tax calculation failures
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				SST_Logger::add( 'SST: No cached packages found, creating new packages to prevent tax calculation failures.' );
			}
			$this->create_packages();
		}

		return parent::calculate_taxes();
	}

	/**
	 * Should the Sales Tax line item be hidden if no tax is due?
	 *
	 * @return bool
	 * @since 5.0
	 */
	public function hide_zero_taxes() {
		return 'true' !== SST_Settings::get( 'show_zero_tax', 'true' );
	}

	/**
	 * Get saved packages for this cart.
	 *
	 * @return array
	 * @since 5.0
	 */
	protected function get_packages() {
		return WC()->session->get( 'sst_packages', array() );
	}

	/**
	 * Set saved packages for this cart.
	 *
	 * @param array $packages Packages to save for cart (default: array()).
	 *
	 * @since 5.0
	 */
	protected function set_packages( $packages = array() ) {
		WC()->session->set( 'sst_packages', $packages );
	}

	/**
	 * Filter items not needing shipping callback.
	 *
	 * @param array $item WooCommerce cart item.
	 *
	 * @return bool
	 * @since 5.0
	 */
	protected function filter_items_not_needing_shipping( $item ) {
		return $item['data'] && ! $item['data']->needs_shipping();
	}

	/**
	 * Get only items that don't need shipping.
	 *
	 * @return array
	 * @since 5.0
	 */
	protected function get_items_not_needing_shipping() {
		return array_filter( $this->cart->get_cart(), array( $this, 'filter_items_not_needing_shipping' ) );
	}

	/**
	 * Get the shipping rate for a package.
	 *
	 * @param int   $key     Package key.
	 * @param array $package Package.
	 *
	 * @return WC_Shipping_Rate | NULL
	 * @since 5.0
	 */
	protected function get_package_shipping_rate( $key, $package ) {
		$chosen_methods = WC()->session->get( 'chosen_shipping_methods' );

		/* WC Multiple Shipping doesn't use chosen_shipping_methods -_- */
		if ( function_exists( 'wcms_session_isset' ) && wcms_session_isset( 'shipping_methods' ) ) {
			$chosen_methods = array();

			foreach ( wcms_session_get( 'shipping_methods' ) as $package_key => $method ) {
				$chosen_methods[ $package_key ] = $method['id'];
			}
		}

		if ( isset( $chosen_methods[ $key ], $package['rates'][ $chosen_methods[ $key ] ] ) ) {
			return $package['rates'][ $chosen_methods[ $key ] ];
		}

		return null;
	}

	/**
	 * Get the base shipping packages for this cart.
	 *
	 * @return array
	 * @since 5.5
	 */
	protected function get_base_packages() {
		$packages = WC()->shipping->get_packages();

		// Debug Logging
		if ( empty( $packages ) ) {
			SST_Logger::add( __( 'Missing shipping packages', 'simple-sales-tax' ) );
		} 

		/*
		 * After WooCommerce 3.0, items that do not need shipping are excluded
		 * from shipping packages. To ensure that these products are taxed, we
		 * create a special package for them unless disable_virtual_split is enabled.
		 */
		if ( 'yes' === SST_Settings::get( 'disable_virtual_split' ) && ! empty( $packages ) ) {
			$digital_items = $this->get_items_not_needing_shipping();
			if ( ! empty( $digital_items ) ) {
				$first_key = key( $packages );
				$packages[ $first_key ]['contents'] = array_merge(
					$packages[ $first_key ]['contents'],
					$digital_items
				);
			}
		} else {
			$virtual_package = $this->create_virtual_package();

			// Debug Logging
			if ( empty( $virtual_package ) ) {
				SST_Logger::add( __( 'Missing virtual packages', 'simple-sales-tax' ) );
			} 

			if ( $virtual_package ) {
				$packages[] = $virtual_package;
			}
		}

		return $packages;
	}

	/**
	 * Creates a virtual shipping package for all items that don't need shipping.
	 *
	 * @return array|false Package or false if all cart items need shipping.
	 */
	protected function create_virtual_package() {
		$digital_items = $this->get_items_not_needing_shipping();

		if ( ! empty( $digital_items ) ) {
			$destination = array(
				'country'   => WC()->customer->get_billing_country(),
				'address'   => WC()->customer->get_billing_address(),
				'address_2' => WC()->customer->get_billing_address_2(),
				'city'      => WC()->customer->get_billing_city(),
				'state'     => WC()->customer->get_billing_state(),
				'postcode'  => WC()->customer->get_billing_postcode(),
			);

			if ( 'yes' === SST_Settings::get( 'disable_virtual_split' ) ) {
				$shipping_country   = WC()->customer->get_shipping_country();
				$shipping_address   = WC()->customer->get_shipping_address();
				$shipping_address_2 = WC()->customer->get_shipping_address_2();
				$shipping_city      = WC()->customer->get_shipping_city();
				$shipping_state     = WC()->customer->get_shipping_state();
				$shipping_postcode  = WC()->customer->get_shipping_postcode();

				if ( ! empty( $shipping_country ) && ! empty( $shipping_state ) ) {
					$destination = array(
						'country'   => $shipping_country,
						'address'   => $shipping_address,
						'address_2' => $shipping_address_2,
						'city'      => $shipping_city,
						'state'     => $shipping_state,
						'postcode'  => $shipping_postcode,
					);
				}
			}

			return sst_create_package(
				array(
					'contents'    => $digital_items,
					'destination' => $destination,
					'user'        => array(
						'ID' => get_current_user_id(),
					),
				)
			);
		}

		return false;
	}

	/**
	 * Create shipping packages for this cart.
	 *
	 * @return array
	 * @since 5.0
	 */
	protected function create_packages() {
		$packages = array();

		// Let devs change the packages before we split them.
		$raw_packages = apply_filters(
			'wootax_cart_packages_before_split',
			$this->get_base_packages(),
			$this->cart
		);

		// Set shipping method for each package and change destination address
		// if method is Local Pickup.
		foreach ( $raw_packages as $key => $package ) {
			$method = $this->get_package_shipping_rate( $key, $package );

			if ( is_null( $method ) ) {
				continue;
			}

			$method     = clone $method;    /* IMPORTANT: preserve original */
			$method->id = $key;

			$raw_packages[ $key ]['shipping'] = $method;

			if ( SST_Shipping::is_local_pickup( array( $method->method_id ) ) ) {
				$pickup_address = apply_filters( 'wootax_pickup_address', SST_Addresses::get_default_address(), null );

				$raw_packages[ $key ]['destination'] = array(
					'country'   => 'US',
					'address'   => $pickup_address->getAddress1(),
					'address_2' => $pickup_address->getAddress2(),
					'city'      => $pickup_address->getCity(),
					'state'     => $pickup_address->getState(),
					'postcode'  => $pickup_address->getZip5(),
				);
			}
		}

		// Filter out packages with invalid destinations.
		$raw_packages = array_filter( $raw_packages, array( $this, 'is_package_destination_valid' ) );

		// Split packages by origin address.
		foreach ( $raw_packages as $raw_package ) {
			$packages = array_merge( $packages, $this->split_package( $raw_package ) );
		}

		// Add fees to packages.
		if ( ! empty( $packages ) && apply_filters( 'wootax_add_fees', true ) ) {
			foreach ( $this->cart->get_fees() as $key => $fee ) {
				$target_package_key = key( $packages );

				if ( 'co-excise-tax' === $fee->id || 'CO EXCISE TAX' === strtoupper( $fee->name ) ) {
					foreach ( $packages as $pkg_key => $pkg ) {
						foreach ( $pkg['contents'] as $content ) {
							$tic = SST_Product::get_tic( $content['product_id'], $content['variation_id'] );
							if ( SST_Product::is_gun_or_ammo_tic( $tic ) ) {
								$target_package_key = $pkg_key;
								break 2;
							}
						}
					}
				}

				if ( ! isset( $packages[ $target_package_key ] ) ) {
					$target_package_key = key( $packages );
				}

				$packages[ $target_package_key ]['fees'][ $key ] = $fee;
			}
		}

		// Debug Logging
		if ( ! empty( $packages )  ) {
			SST_Logger::add( __( 'Packages created successfully', 'simple-sales-tax' ), $packages );
		} else {
			SST_Logger::add( __( 'No packages created.', 'simple-sales-tax' ) );
		}

		return apply_filters( 'wootax_cart_packages', $packages, $this->cart );
	}

	/**
	 * Reset sales tax totals.
	 *
	 * @since 5.0
	 */
	protected function reset_taxes() {
		foreach ( $this->cart->get_cart() as $cart_key => $item ) {
			$this->set_product_tax( $cart_key, 0 );
		}

		foreach ( $this->cart->get_fees() as $key => $fee ) {
			$this->set_fee_tax( $key, 0 );
		}

		$this->cart->reset_shipping_taxes();

		$this->update_taxes();
	}

	/**
	 * Update sales tax totals.
	 *
	 * @since 5.0
	 */
	protected function update_taxes() {
		$cart_tax_total = 0;

		foreach ( $this->cart->get_cart() as $item ) {
			$tax_data = $item['line_tax_data'];

			if ( isset( $tax_data['total'], $tax_data['total'][ SST_RATE_ID ] ) ) {
				$cart_tax_total += $tax_data['total'][ SST_RATE_ID ];
			}
		}

		foreach ( $this->cart->get_fees() as $fee ) {
			if ( isset( $fee->tax_data[ SST_RATE_ID ] ) ) {
				$cart_tax_total += $fee->tax_data[ SST_RATE_ID ];
			}
		}

		$shipping_tax_total = WC_Tax::get_tax_total( $this->cart->sst_shipping_taxes );

		$this->cart->set_tax_amount( SST_RATE_ID, $cart_tax_total );
		$this->cart->set_shipping_tax_amount( SST_RATE_ID, $shipping_tax_total );

		$this->cart->update_tax_totals();
	}

	/**
	 * Set the tax for a product.
	 *
	 * @param mixed $id  Product ID.
	 * @param float $tax Sales tax for product.
	 *
	 * @since 5.0
	 */
	protected function set_product_tax( $id, $tax ) {
		$this->cart->set_cart_item_tax( $id, $tax );
	}

	/**
	 * Set the tax for a shipping package.
	 *
	 * @param mixed $id  Package key.
	 * @param float $tax Sales tax for package.
	 *
	 * @since 5.0
	 */
	protected function set_shipping_tax( $id, $tax ) {
		$this->cart->set_package_tax( $id, $tax );
	}

	/**
	 * Set the tax for a fee.
	 *
	 * @param mixed $id  Fee ID.
	 * @param float $tax Sales tax for fee.
	 *
	 * @since 5.0
	 */
	protected function set_fee_tax( $id, $tax ) {
		$this->cart->set_fee_item_tax( $id, $tax );
	}

	/**
	 * Get the POST data from the checkout page.
	 *
	 * @return array
	 */
	protected function get_post_data() {
		if ( ! isset( $_POST['post_data'] ) ) { // phpcs:ignore WordPress.CSRF.NonceVerification
			$post_data = $_POST; // phpcs:ignore WordPress.CSRF.NonceVerification
		} else {
			$post_data = array();
			parse_str( $_POST['post_data'], $post_data ); // phpcs:ignore WordPress.CSRF.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		}

		return $post_data;
	}

	/**
	 * Get the exemption certificate for the customer.
	 *
	 * @return ExemptionCertificateBase
	 */
	public function get_certificate() {
		if ( 'data_mover' === sst_integration_mode() ) {
			return null;
		}

		$certificate_id = $this->get_certificate_id();

		if ( $certificate_id && 'none' !== $certificate_id ) {
			// Apply saved entity-based cert
			return new ExemptionCertificateBase( $certificate_id );
		}

		return null;
	}

	/**
	 * Get the ID of the exemption certificate to apply by default
	 * for the current user.
	 *
	 * @return string
	 */
	protected function get_default_certificate_id() {
		if ( ! sst_is_user_tax_exempt() ) {
			// For users who don't have an exempt user role, nothing is selected by default
			return '';
		}

		// For users with an exempt role, select the first available certificate
		$certificates   = SST_Certificates::get_certificates_formatted();
		$certificate_id = count( $certificates ) > 0
			? current( array_keys( $certificates ) )
			: '';

		return $certificate_id;
	}

	/**
	 * Initialize certificate ID in session.
	 */
	public function init_certificate_id() {
		if ( ! WC()->session ) {
			return;
		}

		if ( 'data_mover' === sst_integration_mode() ) {
			WC()->session->set( 'sst_certificate_id', '' );
			return;
		}

		$post_data = $this->get_post_data();

		if ( isset( $post_data['certificate_id'] ) ) {
			$certificate_id = sanitize_text_field(
				wp_unslash( $post_data['certificate_id'] )
			);

			if ( 'none' === $certificate_id ) {
				$certificate_id = '';
				WC()->session->set( 'sst_cert_explicitly_cleared', true );
			} else {
				WC()->session->set( 'sst_cert_explicitly_cleared', false );
			}

			if ( $certificate_id !== WC()->session->get( 'sst_certificate_id' ) ) {
				WC()->session->set( 'sst_certificate_id', $certificate_id );
				WC()->session->set( 'sst_packages', array() ); // Clear cache on selection change
			}
		} else {
			$current_cert_id = WC()->session->get( 'sst_certificate_id' );
			$is_cleared      = (bool) WC()->session->get( 'sst_cert_explicitly_cleared', false );

			if ( ( is_null( $current_cert_id ) || '' === $current_cert_id ) && ! $is_cleared ) {
				$default_id = $this->get_default_certificate_id();
				if ( ! empty( $default_id ) ) {
					WC()->session->set( 'sst_certificate_id', $default_id );
				}
			}
		}

		// Ensure SST packages are initialized in session
		if ( ! WC()->session->get( 'sst_packages' ) ) {
			WC()->session->set( 'sst_packages', array() );
		}
	}

	/**
	 * Get the ID of the applied exemption certificate.
	 *
	 * @return string Exemption certificate ID.
	 *
	 * @since 7.0.0
	 */
	public function get_certificate_id() {
		return WC()->session->get( 'sst_certificate_id', '' );
	}

	/**
	 * Adds a notice to the cart/checkout page if the TaxCloud rate limit has been reached.
	 *
	 * @return void
	 */
	public function add_rate_limit_notice() {
		
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		if ( ! is_cart() && ! is_checkout() ) {
			return;
		}

		if ( 'yes' !== SST_Settings::get( 'show_rate_limit_notice', 'no' ) ) {
			return;
		}

		$rate_limit = new SST_Rate_Limit();

		if ( $rate_limit->limit_reached() ) {
			$message = SST_Rate_Limit::get_notice_message();

			if ( ! wc_has_notice( $message, 'notice' ) ) {
				wc_add_notice( $message, 'notice' );
			}
		}
	}

	/**
	 * Display an error message to the user.
	 *
	 * @param string $message Message describing the error.
	 *
	 * @since 5.0
	 */
	protected function handle_error( $message ) {
		$action = $this->get_error_action( $message );

		switch ( $action ) {
			case 'show':
				if ( ! wc_has_notice( $message ) ) {
					wc_add_notice( $message, 'error' );
				}
				break;
			case 'defer':
				$this->errors[] = $message;
				break;
		}

		SST_Logger::add( $message );
	}

	/**
	 * Determines whether the given error message should be shown immediately,
	 * suppressed, or deferred until checkout is complete.
	 *
	 * @param string $error The error message.
	 *
	 * @return string What do to with the error message - valid return values
	 *                are 'show', 'suppress', and 'defer'.
	 */
	protected function get_error_action( $error ) {
		$error_actions = array(
			'API Login ID not set.' => 'suppress',
			'API Key not set.'      => 'suppress',
			'The Ship To zip code'  => 'defer',
		);

		foreach ( $error_actions as $error_message => $action ) {
			if ( false !== strpos( $error, $error_message ) ) {
				return $action;
			}
		}

		return 'show';
	}

	/**
	 * Create a new exemption certificate based on checkout data and
	 * associate it with the newly created order.
	 *
	 * If an exemption certificate was already created during a previous
	 * failed checkout attempt, it will be deleted and recreated.
	 *
	 * @param array     $data  Checkout data
	 * @param SST_Order $order New order object
	 */
	protected function create_exemption_certificate( $data, $order ) {
		$certificate = $data['certificate'] ?? [];

		if ( ! $certificate ) {
			return;
		}

		if ( $order->get_certificate_id() ) {
			try {
				SST_Certificates::delete_certificate(
					$order->get_certificate_id()
				);

				$order->set_certificate_id( '' );
			} catch ( Throwable $ex ) {
				SST_Logger::add(
					"Failed to delete certificate during checkout: {$ex->getMessage()}"
				);
			}
		}

		$certificate = array_map(
			'sanitize_text_field',
			wp_unslash( $certificate )
		);

		$purchaser = array(
			'first_name' => $order->get_billing_first_name(),
			'last_name'  => $order->get_billing_last_name(),
			'address_1'  => $order->get_billing_address_1(),
			'address_2'  => $order->get_billing_address_2(),
			'city'       => $order->get_billing_city(),
			'state'      => $order->get_billing_state(),
			'postcode'   => $order->get_billing_postcode(),
		);

		try {
			// Build single purchase cert or save entity-based cert
			if ( empty( $certificate['SinglePurchase'] ) ) {
				$certificate_id = SST_Certificates::add_certificate(
					$certificate,
					$purchaser
				);

				$order->set_certificate_id( $certificate_id );
			} else {
				$certificate = SST_Certificates::build_certificate(
					array_merge(
						$certificate,
						array(
							'SinglePurchase'             => true,
							'SinglePurchaserOrderNumber' => $order->get_id(),
						)
					),
					$purchaser
				);

				$order->set_single_purchase_certificate( $certificate );
			}

			// Do a tax lookup
			$order->calculate_taxes();
			$order->calculate_totals( false );
		} catch ( Throwable $ex ) {
			SST_Logger::add(
				sprintf(
					/* translators: 1 - error message */
					__(
						'Failed to add exemption certificate during checkout. Error was: %1$s',
						'simple-sales-tax'
					),
					$ex->getMessage()
				)
			);

			throw new Exception( esc_html( $ex->getMessage() ) );
		}
	}

	/**
	 * Process checkout and save order meta.
	 *
	 * @param array    $data     Checkout data
	 * @param WC_Order $wc_order Order object
	 */
	protected function process_checkout( $data, $wc_order ) {
		$order = new SST_Order( $wc_order );

		if ( 'data_mover' === sst_integration_mode() ) {
			$certificate_id = '';
		} else {
			$certificate_id = $data['certificate_id'] ?? '';
		}

		if ( 'new' === $certificate_id ) {
			$this->create_exemption_certificate( $data, $order );
			return;
		}

		// Ensure we save packages from the 'main' cart
		$this->cart = new SST_Cart_Proxy( WC()->cart );

		$order->set_certificate_id( $certificate_id );
		$order->set_packages( $this->get_packages() );

		// Fix tax totals for orders created via store API
		if ( 'store-api' === $order->get_created_via() ) {
			$order->remove_order_items( 'line_item' );
			$order->remove_order_items( 'shipping' );
			$order->remove_order_items( 'fee' );

			WC()->checkout->create_order_line_items( $order, WC()->cart );
			WC()->checkout->create_order_shipping_lines(
				$order,
				WC()->session->get( 'chosen_shipping_methods' ),
				WC()->shipping()->get_packages()
			);
			WC()->checkout->create_order_fee_lines( $order, WC()->cart );

			$order->update_taxes();
			$order->calculate_totals( false );
		}

		$order->save();
	}

	/**
	 * Handles processing of the legacy shortcode based checkout.
	 *
	 * @param WC_Order $order New order object
	 */
	public function handle_legacy_checkout( $order ) {
		$this->process_checkout( $this->get_post_data(), $order );
	}

	/**
	 * Given a package key, return the shipping tax for the package.
	 *
	 * @param string $package_key Package key.
	 *
	 * @return float -1 if no shipping tax, otherwise shipping tax.
	 * @since 5.0
	 */
	protected function get_package_shipping_tax( $package_key ) {
		$cart          = WC()->cart;
		$package_index = $package_key;
		$cart_key      = '';

		if ( sst_subs_active() ) {
			$last_underscore_i = strrpos( $package_key, '_' );

			if ( false !== $last_underscore_i ) {
				$cart_key      = substr( $package_key, 0, $last_underscore_i );
				$package_index = substr( $package_key, $last_underscore_i + 1 );
			}
		}

		if ( ! empty( $cart_key ) ) {
			$cart = WC()->cart->recurring_carts[ $cart_key ];
		}

		if ( isset( $cart->sst_shipping_taxes[ $package_index ] ) ) {
			return $cart->sst_shipping_taxes[ $package_index ];
		} else {
			return -1;
		}
	}

	/**
	 * Add shipping meta for newly created shipping items.
	 *
	 * @param WC_Order_Item_Shipping $item        The shipping item that was just created.
	 * @param int                    $package_key Cart shipping package key.
	 * @param array                  $package     Cart shipping package.
	 *
	 * @throws WC_Data_Exception If the tax data passed to WooCommerce is invalid.
	 * @since 5.0
	 */
	public function add_shipping_meta( $item, $package_key, $package ) {
		$shipping_tax = $this->get_package_shipping_tax( $package_key );

		if ( $shipping_tax >= 0 ) {
			$taxes                         = $item->get_taxes();
			$taxes['total'][ SST_RATE_ID ] = $shipping_tax;
			$item->set_taxes( $taxes );
		}
	}

	/**
	 * Output Tax Details section of checkout form.
	 *
	 * @since 5.0
	 */
	public function output_exemption_form() {
		if ( ! sst_should_show_tax_exemption_form() ) {
			return;
		}

		$certificates = SST_Certificates::get_certificates_formatted();
		$options      = array(
			'none' => __( 'Select a exemption certificate', 'simple-sales-tax' ),
			'new'  => __( 'Add new certificate', 'simple-sales-tax' ),
		);

		foreach ( $certificates as $cert ) {
			$options[ $cert['CertificateID'] ] = $cert['Description'];
		}

		$selected = $this->get_certificate_id();

		wp_enqueue_script( 'sst-checkout' );
		wp_enqueue_style( 'sst-checkout-css' );
		wp_enqueue_style( 'sst-certificate-form-css' );

		wc_get_template(
			'html-checkout.php',
			array(
				'options'  => $options,
				'selected' => $selected,
			),
			'sst/',
			SST()->path( 'includes/frontend/views/' )
		);
	}

	/**
	 * Clear SST session data when the cart is emptied.
	 *
	 * @since 5.7
	 */
	public function clear_session_data() {
		WC()->session->set( 'sst_packages', array() );
		WC()->session->set( 'sst_package_cache', array() );

		unset( WC()->session->sst_certificate_id );
	}

	/**
	 * Validate checkout.
	 *
	 * @param array    $data   POST data.
	 * @param WP_Error $errors Checkout errors.
	 */
	protected function validate_checkout( $data, $errors ) {
		foreach ( $this->errors as $error_message ) {
			$errors->add( 'tax', $error_message );
		}

		$this->validate_exemption_certificate( $data, $errors );
	}

	/**
	 * Validate legacy checkout on {@see 'woocommerce_after_checkout_validation'}
	 *
	 * @param array    $data   POST data.
	 * @param WP_Error $errors Checkout errors.
	 */
	public function validate_legacy_checkout( $data, $errors ) {
		$this->validate_checkout( $this->get_post_data(), $errors );
	}

	/**
	 * Validate exemption certificate checkout fields.
	 *
	 * @param array    $data   Checkout data.
	 * @param WP_Error $errors Checkout errors.
	 */
	protected function validate_exemption_certificate( $data, $errors ) {
		if ( 'data_mover' === sst_integration_mode() ) {
			return;
		}

		$certificate_id = $data['certificate_id'] ?? '';
		$certificate    = $data['certificate'] ?? [];

		if ( 'new' !== $certificate_id ) {
			return;
		}

		// Field key => Condition when field is required
		$required_fields = [
			'ExemptState'                        => [],
			'TaxType'                            => [],
			'IDNumber'                           => [],
			'StateOfIssue'                       => [
				'field' => 'TaxType',
				'value' => 'StateIssued',
			],
			'PurchaserBusinessType'              => [],
			'PurchaserBusinessTypeOtherValue'    => [
				'field' => 'PurchaserBusinessType',
				'value' => 'Other',
			],
			'PurchaserExemptionReason'           => [],
			'PurchaserExemptionReasonOtherValue' => [
				'field' => 'PurchaserExemptionReason',
				'value' => 'Other',
			],
		];

		foreach ( $required_fields as $field_key => $condition ) {
			$required = true;

			if ( isset( $condition['field'], $condition['value'] ) ) {
				$field_value = $certificate[ $condition['field'] ] ?? '';
				$required    = $field_value === $condition['value'];
			}

			if ( $required && empty( $certificate[ $field_key ] ) ) {
				$errors->add(
					$field_key . '_required',
					__( 'Required exemption certificate fields are missing', 'simple-sales-tax' )
				);
				break;
			}
		}
	}

	/**
	 * Gets a saved package by its package hash.
	 *
	 * @param string $hash Package hash.
	 *
	 * @return array|bool The saved package with the given hash, or false if no such package exists.
	 */
	protected function get_saved_package( $hash ) {
		// Add session readiness check
		if ( ! WC()->session ) {
			return false;
		}

		$saved_packages = WC()->session->get( 'sst_package_cache', array() );

		// Validate package data integrity before returning
		if ( isset( $saved_packages[ $hash ] ) && $this->is_package_valid( $saved_packages[ $hash ] ) ) {
			return $saved_packages[ $hash ];
		}

		return false;
	}

	/**
	 * Saves a package.
	 *
	 * @param string $hash    Package hash.
	 * @param array  $package Package.
	 */
	protected function save_package( $hash, $package ) {
		$saved_packages          = WC()->session->get( 'sst_package_cache', array() );
		$saved_packages[ $hash ] = $package;

		WC()->session->set( 'sst_package_cache', $saved_packages );
	}

	/**
	 * Validates package data integrity to prevent using corrupted packages.
	 *
	 * @param array $package Package to validate.
	 * @return bool True if package is valid, false otherwise.
	 */
	protected function is_package_valid( $package ) {
		// Check if package has required keys
		$required_keys = array( 'cart_items', 'customer_id' );
		
		foreach ( $required_keys as $key ) {
			if ( ! isset( $package[ $key ] ) ) {
				return false;
			}
		}

		// Check if cart_items is an array and not empty
		if ( ! is_array( $package['cart_items'] ) || empty( $package['cart_items'] ) ) {
			return false;
		}

		// Check if customer_id is valid
		if ( empty( $package['customer_id'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Ensures that the 'key' property is set for all WooCommerce cart items.
	 *
	 * @param array  $cart_item_data Data for cart item being added.
	 * @param string $cart_item_key  Cart item key.
	 *
	 * @return array
	 */
	public function set_key_for_cart_item( $cart_item_data, $cart_item_key ) {
		if ( ! isset( $cart_item_data['key'] ) ) {
			$cart_item_data['key'] = $cart_item_key;
		}

		return $cart_item_data;
	}

	/**
	 * Handle negative fees by spreading them evenly across all cart package
	 * items as discounts.
	 *
	 * This is required to support extensions like Deposits for WooCommerce
	 * that apply discounts using negative fees instead of coupons. Without
	 * it, tax lookups will fail due to the inclusion of line items with
	 * negative prices.
	 *
	 * @param array $packages Cart packages
	 * @return array Cart packages with negative fees converted to discounts
	 */
	public function handle_negative_fees( $packages ) {
		$cart_total         = 0;
		$negative_fee_total = 0;

		foreach ( $packages as &$package ) {
			foreach ( $package['contents'] as $item ) {
				$cart_total += $item['line_total'];
			}

			foreach ( $package['fees'] as $key => $fee ) {
				if ( $fee->amount < 0 ) {
					$negative_fee_total += abs( $fee->amount );
					unset( $package['fees'][ $key ] );
				}
			}
		}

		if ( 0 === $negative_fee_total ) {
			return $packages;
		}

		foreach ( $packages as &$package ) {
			foreach ( $package['contents'] as &$item ) {
				if ( $negative_fee_total <= 0 || $cart_total <= 0 ) {
					break 2;
				}

				$item_total = $item['line_total'];
				$discount   = $negative_fee_total * ( $item_total / $cart_total );

				$item['line_total'] -= $discount;

				$negative_fee_total -= $discount;
				$cart_total         -= $item_total;
			}
		}

		return $packages;
	}

	/**
	 * Handle processing of block based checkout.
	 *
	 * @param WC_Order        $order   Order object
	 * @param WP_REST_Request $request Request
	 */
	public function handle_checkout( $order, $request ) {
		$extension_data = $request->get_param( 'extensions' );
		$data           = isset( $extension_data['simple-sales-tax'] ) ? $extension_data['simple-sales-tax'] : array();

		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$error = new WP_Error();
		$this->validate_checkout( $data, $error );

		if ( $error->has_errors() ) {
			throw new RouteException(
				'sst_checkout_invalid_fields',
				esc_html( sanitize_text_field( $error->get_error_message() ) ),
				400
			);
		}

		$this->process_checkout( $data, $order );
	}

	/**
	 * Adds the Colorado excise tax fee to the cart.
	 *
	 * @param WC_Cart $cart Cart object.
	 *
	 * @since 8.4.3
	 */
	public function add_co_excise_tax_fee( $cart ) {
		if ( $cart->is_empty() ) {
			return;
		}

		$state = WC()->customer->get_shipping_state() ?: WC()->customer->get_billing_state();

		if ( ! $state ) {
			return;
		}

		$excise_tax = $this->calculate_excise_tax( $cart->get_cart(), $state );

		if ( $excise_tax <= 0 ) {
			return;
		}

		// Prevent duplicate fees
		foreach ( $cart->get_fees() as $fee ) {
			if ( 'co-excise-tax' === $fee->id ) {
				return;
			}
		}

		if ( $excise_tax > 0 ) {
			$cart->add_fee( __( 'CO EXCISE TAX', 'simple-sales-tax' ), $excise_tax, false );
		}
	}
}

new SST_Checkout();
