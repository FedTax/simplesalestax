<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Subscriptions.
 *
 * Enables WooCommerce Subscriptions support.
 *
 * @author  Simple Sales Tax
 * @package SST
 * @since   5.0
 */
class SST_Subscriptions {

	/**
	 * Constructor.
	 *
	 * @since 5.0
	 */
	public function __construct() {
		add_filter( 'wootax_add_fees', array( $this, 'exclude_fees' ) );
		add_filter( 'wootax_cart_packages_before_split', array( $this, 'add_package_for_no_ship_subs' ), 10, 2 );
		add_filter( 'wootax_order_packages_before_split', array( $this, 'add_order_package_for_no_ship_subs' ), 10, 2 );
		add_filter( 'wootax_product_tic', array( $this, 'set_signup_fee_tic' ), 10, 3 );
		add_filter( 'woocommerce_calculated_total', array( $this, 'save_shipping_taxes' ), 1200, 2 );
		add_action( 'woocommerce_cart_updated', array( $this, 'restore_shipping_taxes' ) );
		add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'destroy_session' ) );
		add_filter( 'wcs_renewal_order_created', array( $this, 'recalc_taxes_for_renewal' ), 1, 2 );
		add_filter( 'wootax_save_packages_for_capture', array( $this, 'should_save_packages_for_capture' ) );
		add_filter( 'woocommerce_subscriptions_calculated_total', array( $this, 'filter_calculated_total' ) );
	}

	/**
	 * Exclude fees from tax lookups when subscription there is no subscription
	 * sign up fee and all subscriptions qualify for a free trial.
	 *
	 * @param bool $add_fees Whether to include fees in the tax lookup.
	 *
	 * @return bool
	 * @since 5.0
	 */
	public function exclude_fees( $add_fees ) {
		if ( ! did_action( 'woocommerce_calculate_totals' ) ) {
			return $add_fees; /* In backend; TODO: better way to handle? */
		} else {
			$calc_type           = WC_Subscriptions_Cart::get_calculation_type();
			$sign_up_fee         = WC_Subscriptions_Cart::get_cart_subscription_sign_up_fee();
			$all_have_free_trial = WC_Subscriptions_Cart::all_cart_items_have_free_trial();

			if ( 'recurring_total' !== $calc_type && 0 === (int) $sign_up_fee && $all_have_free_trial ) {
				return false;
			}
		}

		return $add_fees;
	}

	/**
	 * This function is executed when a new renewal order is created. It
	 * recalculates the sales tax for the order to account for the fact
	 * that the customer address (and tax rates) may have changed.
	 *
	 * @param WC_Order        $renewal_order The renewal order that was just created.
	 * @param WC_Subscription $subscription  The subscription associated with the renewal order.
	 *
	 * @return WC_Order
	 * @since 5.0
	 */
	public function recalc_taxes_for_renewal( $renewal_order, $subscription ) {
		$order = new SST_Order( $renewal_order );

		/* Reset packages to force recalc */
		$order->set_packages( array() );

		/* Reset status to ensure Lookup isn't blocked */
		$order->update_meta( 'status', 'pending' );

		/* Recalc taxes */
		try {
			$order->calculate_taxes();
			$order->calculate_totals( false );
		} catch ( Exception $ex ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1 - Renewal order ID, 2 - Error message */
					__( 'Failed to calculate sales tax for renewal order %1$d: %2$s.', 'simple-sales-tax' ),
					$order->get_id(),
					$ex->getMessage()
				)
			);
		}

		return $renewal_order;
	}

	/**
	 * Subscriptions will recalculate the shipping totals for the main cart
	 * after calculating the recurring totals. When this is done, the shipping
	 * taxes for the main cart will be reset. This function saves the
	 * computed shipping taxes before the recurring totals are calculated so
	 * they can be restored later.
	 *
	 * IMPORTANT: This hook needs to run after SST_Checkout::calculate_tax_totals().
	 *
	 * @param double  $total Current cart total.
	 * @param WC_Cart $cart  Cart object.
	 *
	 * @return double
	 * @since 5.0
	 */
	public function save_shipping_taxes( $total, $cart ) {
		$calc_type = WC_Subscriptions_Cart::get_calculation_type();

		if ( in_array( $calc_type, array( 'none', 'recurring_total' ), true ) ) {
			$saved_taxes = WC()->session->get( 'sst_saved_shipping_taxes', array() );

			$saved_taxes[ $calc_type ] = $cart->get_shipping_taxes();

			WC()->session->set( 'sst_saved_shipping_taxes', $saved_taxes );
		}

		return $total;
	}

	/**
	 * Restore shipping taxes after recurring order totals are updated.
	 *
	 * @since 5.0
	 */
	public function restore_shipping_taxes() {
		$calc_type = WC_Subscriptions_Cart::get_calculation_type();

		if ( in_array( $calc_type, array( 'none', 'recurring_total' ), true ) ) {
			$saved_taxes = WC()->session->get( 'sst_saved_shipping_taxes', array() );

			if ( array_key_exists( $calc_type, $saved_taxes ) ) {
				WC()->cart->set_shipping_taxes( $saved_taxes[ $calc_type ] );
			}
		}
	}

	/**
	 * Delete session data post-checkout.
	 *
	 * @since 5.0
	 */
	public function destroy_session() {
		WC()->session->set( 'sst_saved_shipping_taxes', array() );
	}

	/**
	 * When the cart calculation type is 'none,' Subscriptions removes all
	 * subscriptions with free trials from the cart packages. Similarly, when
	 * the calculation type is 'recurring_total,' it removes all subscriptions
	 * that have one time shipping. As a consequence, the fees for these subs
	 * (if any) will not be included in lookups. To avoid this, we hook
	 * wootax_cart_packages_before_split and add a special package containing
	 * all removed subs. Since all subs in this package do not ship, we use the
	 * customer billing address as the destination address.
	 *
	 * @param array   $packages SST packages for cart.
	 * @param WC_Cart $cart     WooCommerce cart object.
	 *
	 * @return array
	 * @since 5.0
	 */
	public function add_package_for_no_ship_subs( $packages, $cart ) {
		$contents  = array();
		$calc_type = WC_Subscriptions_Cart::get_calculation_type();

		if ( 'none' === $calc_type && WC_Subscriptions_Cart::cart_contains_free_trial() ) {
			foreach ( $cart->get_cart() as $key => $cart_item ) {
				if ( WC_Subscriptions_Product::get_trial_length( $cart_item['data'] ) > 0 ) {
					$contents[ $key ] = $cart_item;
				}
			}
		} elseif ( 'recurring_total' === $calc_type ) {
			foreach ( $cart->get_cart() as $key => $cart_item ) {
				if ( WC_Subscriptions_Product::needs_one_time_shipping( $cart_item['data'] ) ) {
					$contents[ $key ] = $cart_item;
				}
			}
		}

		if ( ! empty( $contents ) ) {   /* Add package */
			$packages[] = sst_create_package(
				array(
					'contents'    => $contents,
					'user'        => array(
						'ID' => get_current_user_id(),
					),
					'destination' => array(
						'address'   => WC()->customer->get_billing_address(),
						'address_2' => WC()->customer->get_billing_address_2(),
						'city'      => WC()->customer->get_billing_city(),
						'state'     => WC()->customer->get_billing_state(),
						'postcode'  => WC()->customer->get_billing_postcode(),
					),
				)
			);
		}

		return $packages;
	}

	/**
	 * Same as add_package_for_no_ship_subs, but for when we're calculating the
	 * tax for an order from the Edit Order screen.
	 *
	 * @param array    $packages SST packages for order.
	 * @param WC_Order $order    WooCommerce order object.
	 *
	 * @return array
	 * @since 6.0.13
	 */
	public function add_order_package_for_no_ship_subs( $packages, $order ) {
		$contents = array();

		/** @var WC_Order_Item_Product $item */
		if ( ! wcs_order_contains_renewal( $order ) ) {
			foreach ( $order->get_items() as $item_id => $item ) {
				if ( WC_Subscriptions_Product::get_trial_length( $item->get_product() ) > 0 ) {
					$contents[ $item_id ] = $item;
				}
			}
		} else {
			foreach ( $order->get_items() as $item_id => $item ) {
				if ( WC_Subscriptions_Product::needs_one_time_shipping( $item->get_product() ) ) {
					$contents[ $item_id ] = $item;
				}
			}
		}

		if ( ! empty( $contents ) ) {   /* Add package */
			$contents   = sst_format_order_items( $contents );
			$packages[] = sst_create_package(
				array(
					'contents'    => $contents,
					'user'        => array(
						'ID' => get_current_user_id(),
					),
					'destination' => array(
						'address'   => $order->get_billing_address_1(),
						'address_2' => $order->get_billing_address_2(),
						'city'      => $order->get_billing_city(),
						'state'     => $order->get_billing_state(),
						'postcode'  => $order->get_billing_postcode(),
					),
				)
			);
		}

		return $packages;
	}

	/**
	 * If we are calculating tax for the initial order (i.e. the calculation
	 * type is 'none'), set the TIC for all subscription products with a free
	 * trial period and sign up fee to "Membership fees" (91070). If this isn't
	 * done, sign-up fees will be taxed as if they are subscriptions.
	 *
	 * @param int $tic          TIC to use for product.
	 * @param int $product_id   Product ID.
	 * @param int $variation_id Variation ID (default: 0).
	 *
	 * @return int
	 * @since 5.0
	 */
	public function set_signup_fee_tic( $tic, $product_id, $variation_id = 0 ) {
		if ( ! $this->is_initial_subscription_order() ) {
			return $tic;
		}

		$has_free_trial = WC_Subscriptions_Product::get_trial_length( $product_id ) > 0;
		$has_fee        = WC_Subscriptions_Product::get_sign_up_fee( $product_id );

		if ( $has_free_trial && $has_fee ) {
			return apply_filters( 'wootax_sign_up_fee_tic', 91070 ); // Default is "Membership fees" (91070).
		}

		return $tic;
	}

	/**
	 * Checks whether we're calculating the tax for an initial subscription order.
	 *
	 * @return bool
	 */
	private function is_initial_subscription_order() {
		if ( isset( $_REQUEST['order_id'] ) ) { // phpcs:ignore WordPress.CSRF.NonceVerification
			// Re-calculating totals in WP admin.
			return ! wcs_order_contains_renewal( absint( $_REQUEST['order_id'] ) ); // phpcs:ignore WordPress.CSRF.NonceVerification
		} elseif ( doing_filter( 'wcs_renewal_order_created' ) ) {
			return false;
		}

		return 'none' === WC_Subscriptions_Cart::get_calculation_type();
	}

	/**
	 * Filters `wootax_save_packages_for_capture` to prevent Simple Sales Tax from sending recurring totals to TaxCloud.
	 *
	 * @return bool
	 */
	public function should_save_packages_for_capture() {
		return 'none' === WC_Subscriptions_Cart::get_calculation_type();
	}

	/**
	 * Filters calculated total to fix double tax on initial cart with
	 * WC 9.2+.
	 *
	 * @param float $total Cart total
	 *
	 * @return float
	 */
	public function filter_calculated_total( $total ) {
		$cart_tax = WC()->cart->get_cart_contents_tax();

		return max( 0, round( $total - $cart_tax, wc_get_price_decimals() ) );
	}

}

new SST_Subscriptions();
