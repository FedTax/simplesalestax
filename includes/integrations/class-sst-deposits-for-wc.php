<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deposits for WooCommerce integration for Simple Sales Tax.
 *
 * NOTE: Applying a discount on the full cart total including tax
 * is NOT supported (`deposit_on_total_incl_tax`).
 *
 * @author Taxcloud <bporcelli@taxcloud.com>
 */
class SST_Deposits_For_WC {

	/**
	 * Constructor.
	 */
	public function __construct() {
		// CART HOOKS
		add_filter(
			'woocommerce_get_cart_contents',
			array( $this, 'add_tax_to_totals' )
		);

		$checkout_deposits_enabled = (
			'yes' === get_option( 'checkout_deposits_enabled' ) &&
			'no' !== get_option( 'default_deposits_enabled' )
		);

		if ( $checkout_deposits_enabled ) {
			add_filter(
				'wootax_cart_packages',
				array( $this, 'filter_cart_packages' )
			);
			add_filter(
				'woocommerce_calculated_total',
				array( $this, 'set_post_data' )
			);
			add_filter(
				'woocommerce_calculated_total',
				array( $this, 'fix_cart_deposit_info' ),
				1110,
				2
			);
			add_filter(
				'dfw_total_cart_amount_price_figure_html',
				array( $this, 'fix_total_cart_amount' )
			);
		}

		// ORDER HOOKS
		add_filter(
			'wootax_order_packages',
			array( $this, 'filter_order_packages' ),
			10,
			2
		);
		// Priority set to run before DFW_Manage_Orders::dfw_order_init_action()
		add_action( 'admin_init', array( $this, 'add_pre_option_filters' ), 5 );
		add_action(
			'woocommerce_before_order_object_save',
			array( $this, 'calculate_order_taxes' )
		);
		add_action(
			'woocommerce_after_order_object_save',
			array( $this, 'adjust_stripe_invoice_amount' )
		);
	}

	/**
	 * Calculate taxes on amount due in future.
	 */
	protected function calculate_future_taxes() {
		$filter_packages = function( $packages ) {
			return array_map(
				function( $package ) {
					// Remove shipping and fees (collected with deposit)
					if ( $package['shipping'] ) {
						$package['shipping']->cost = 0;
					}

					$package['fees'] = array();

					foreach ( $package['contents'] as $key => &$item ) {
						if ( empty( $item['has_deposit'] ) ) {
							// Remove items with no deposit
							unset( $package['contents'][ $key ] );
							continue;
						}

						// Update total to reflect amount due in future
						$item['line_total'] =
							$item['quantity'] * ( $item['full_amount'] - $item['deposit'] );
					}

					return $package;
				},
				$packages
			);
		};

		add_filter( 'wootax_cart_packages', $filter_packages );
		add_filter( 'wootax_save_packages_for_capture', '__return_false' );

		WC()->cart->calculate_totals();

		remove_filter( 'wootax_cart_packages', $filter_packages );
		remove_filter( 'wootax_save_packages_for_capture', '__return_false' );

		return array_reduce(
			WC()->cart->get_cart_contents(),
			function( $taxes, $item ) {
				$taxes[ $item['key'] ] = $item['line_tax'];
				return $taxes;
			},
			[]
		);
	}

	/**
	 * Adds tax to the `full_amount` and product price for each item with
	 * a deposit to match Deposits for WooCommerce's expectations.
	 *
	 * @param array $cart_contents WC cart contents
	 *
	 * @return float
	 */
	public function add_tax_to_totals( $cart_contents ) {
		$is_paying_in_full = true;

		foreach ( $cart_contents as $item ) {
			if ( ! empty( $item['has_deposit'] ) ) {
				$is_paying_in_full = false;
				break;
			}
		}

		if ( $is_paying_in_full ) {
			return $cart_contents;
		}

		// Don't filter unless displaying totals in cart or order review
		if (
			! doing_action( 'woocommerce_cart_totals_before_order_total' ) &&
			! doing_action( 'woocommerce_review_order_before_order_total' )
		) {
			return $cart_contents;
		}

		// Remove filter to avoid infinite recursion
		remove_filter(
			'woocommerce_get_cart_contents',
			array( $this, 'add_tax_to_totals' )
		);

		// Calculate tax due in future and update totals
		$future_taxes = $this->calculate_future_taxes();

		foreach ( $cart_contents as &$item ) {
			if ( empty( $item['has_deposit'] ) ) {
				continue;
			}

			$future_tax = $future_taxes[ $item['key'] ] ?? 0;
			$unit_tax   = $future_tax / $item['quantity'];

			$item['full_amount'] = $item['full_amount'] + $unit_tax;
		}

		// Restore tax totals for amount due today
		WC()->cart->calculate_totals();

		// Add removed filter back
		add_filter(
			'woocommerce_get_cart_contents',
			array( $this, 'add_tax_to_totals' )
		);

		return $cart_contents;
	}

	/**
	 * Check whether DFW created an order.
	 *
	 * @param WC_Order $order Order object
	 * @return bool Did DFW create the order?
	 */
	public function is_dfw_order( $order ) {
		return (
			$order->get_meta( '_is_child_order' ) &&
			$order->get_meta( '_original_order_id' ) &&
			$order->get_meta( '_payment_number' )
		);
	}

	/**
	 * Check whether an order is a DFW duplicate order.
	 *
	 * @param WC_Order $order Order object
	 * @return bool
	 */
	public function is_dfw_duplicate_order( $order ) {
		return (
			$this->is_dfw_order( $order ) &&
			'' !== $order->get_meta( '_child_total' )
		);
	}

	/**
	 * Check whether DFW is creating a new order.
	 */
	public function is_dfw_creating_order() {
		return (
			! empty( $_GET['invoice_remaining_balance'] ) ||
			! empty( $_GET['invoice_order_remaining_balance'] )
		);
	}

	/**
	 * Set a non-zero GMT offset when calculating taxes so current_time
	 * returns a timestamp in the future.
	 *
	 * Without this, Stripes throws an error that the invoice due_date
	 * is in the past if the tax calculation takes more than 1s to
	 * to complete.
	 */
	public function filter_gmt_offset() {
		return 1;
	}

	/**
	 * Adds pre_option filters to fix various issues with DFW calculations.
	 * These are removed promptly once the problematic DFW code runs.
	 */
	public function add_pre_option_filters() {
		if ( ! $this->is_dfw_creating_order() ) {
			return;
		}

		// Prevents DFW from setting item total to 0 when creating orders for future payments
		add_filter(
			'pre_option_woocommerce_calc_taxes',
			'__return_empty_string'
		);

		if ( 'yes' === get_option( 'enable_auto_charge_stripe' ) ) {
			// Prevents Stripe from throwing error about due_date in the past
			add_filter(
				'pre_option_gmt_offset',
				array( $this, 'filter_gmt_offset' )
			);
		}

		add_action(
			'woocommerce_order_before_calculate_totals',
			array( $this, 'remove_pre_option_filters' )
		);
	}

	/**
	 * Remove pre_option filters after problematic DFW code runs.
	 */
	public function remove_pre_option_filters() {
		remove_filter(
			'pre_option_woocommerce_calc_taxes',
			'__return_empty_string'
		);
		remove_filter(
			'pre_option_gmt_offset',
			array( $this, 'filter_gmt_offset' )
		);
	}

	/**
	 * Calculate tax for DFW orders before save.
	 *
	 * @param WC_Order $order Order object
	 */
	public function calculate_order_taxes( $order ) {
		if (
			! $this->is_dfw_order( $order ) ||
			! $this->is_dfw_creating_order()
		) {
			return;
		}

		// Remove action temporarily to avoid infinite recursion
		remove_action(
			'woocommerce_before_order_object_save',
			array( $this, 'calculate_order_taxes' )
		);

		sst_order_calculate_taxes( $order );

		if ( '' !== $order->get_meta( '_child_total' ) ) {
			$order->set_total( $order->get_meta( '_child_total' ) );
		}

		add_action(
			'woocommerce_before_order_object_save',
			array( $this, 'calculate_order_taxes' )
		);
	}

	/**
	 * Adjust amount of invoices created by DFW to include tax.
	 *
	 * @param WC_Order $order Order object
	 */
	public function adjust_stripe_invoice_amount( $order ) {
		if ( ! $this->is_dfw_order( $order ) ) {
			return;
		}

		$invoice_id = $order->get_meta( '_invoice_id' );

		if ( ! $invoice_id ) {
			return;
		}

		$rate_code  = sst_get_rate_code();
		$tax_totals = $order->get_tax_totals();
		$tax_total  = $tax_totals[ $rate_code ]->amount ?? 0;

		if ( $tax_total <= 0 ) {
			return;
		}

		try {
			$stripe = new \Stripe\StripeClient( DFW_CLIENT_STRIPE_KEY );

			// Disable automatic taxes so Stripe doesn't add extra tax
			$stripe->invoices->update(
				$invoice_id,
				array(
					'automatic_tax' => array(
						'enabled' => false,
					),
				)
			);

			// NOTE: DFW only creates a single item, so limit 1 is fine
			$line_items = $stripe->invoices->allLines( $invoice_id );

			if ( ! $line_items ) {
				return;
			}

			// DFW only creates 1 line item, so that's all we have to worry about
			$line_item  = $line_items->data[0];
			$tax_amount = (int) str_replace(
				array( '.', ',' ),
				'',
				number_format( $tax_total, 2 )
			);

			$stripe->request(
				'POST',
				"/v1/invoices/{$invoice_id}/lines/{$line_item->id}",
				array(
					'tax_amounts' => array(
						array(
							'amount'         => $tax_amount,
							'taxable_amount' => $line_item->amount,
							'tax_rate_data'  => array(
								'display_name' => sst_get_rate_label(),
								'inclusive'    => false,
								'percentage'   => 0,
								'tax_type'     => 'sales_tax',
								'country'      => $order->get_shipping_country(),
								'state'        => $order->get_shipping_state(),
							),
						),
					),
				),
				array()
			);
		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			SST_Logger::add(
				'[DFW] Failed to add tax to Stripe invoice: ' . $e->getMessage()
			);
		}
	}

	/**
	 * Set deposit info on cart _after_ SST calculates tax totals.
	 * Without this, totals for initial cart when checkout based
	 * deposits are enabled will be wrong.
	 *
	 * @param float   $total Cart total
	 * @param WC_Cart $cart  Cart Object
	 */
	public function fix_cart_deposit_info( $total, $cart ) {
		$total_tax = WC()->cart->get_total_tax();

		if ( ! is_array( WC()->cart->deposit_info ) ) {
			return $total;
		}

		// Remove filter temporarily to avoid infinite recursion
		remove_filter(
			'woocommerce_calculated_total',
			array( $this, 'fix_cart_deposit_info' ),
			1110
		);

		$future_tax = $this->calculate_second_payment_tax();

		add_filter(
			'woocommerce_calculated_total',
			array( $this, 'fix_cart_deposit_info' ),
			1110,
			2
		);

		WC()->cart->deposit_info['deposit_amount']             += $total_tax;
		WC()->cart->deposit_info['second_payment']             += $future_tax;
		WC()->cart->deposit_info['deposit_breakdown']['taxes'] = $total_tax;

		return $total;
	}

	/**
	 * Set $_POST['wcd_option'] so DFW_Cart_Deposit::dfw_calculated_total()
	 * calculates correctly.
	 *
	 * @param float $total Cart total
	 * @return float
	 */
	public function set_post_data( $total ) {
		if ( ! isset( $_POST['post_data'] ) ) {
			return $total;
		}

		$post_data = array();
		parse_str( $_POST['post_data'], $post_data );

		$_POST['wcd_option'] = $post_data['wcd_option'] ?? '';

		return $total;
	}

	/**
	 * Adjust the total of the items in a set of cart packages to match
	 * a discounted target total by proportionally decreasing the cost
	 * of all items.
	 *
	 * @param array $packages     Cart packages
	 * @param float $target_total Discounted target total
	 * @return array Packages with item totals adjusted to match target
	 */
	protected function adjust_package_totals( $packages, $target_total ) {
		$cart_total = 0;

		foreach ( $packages as $package ) {
			foreach ( $package['contents'] as $item ) {
				$cart_total += $item['line_total'];
			}
		}

		$discount = max( $cart_total - $target_total, 0 );

		if ( $discount <= 0 ) {
			return $packages;
		}

		foreach ( $packages as &$package ) {
			foreach ( $package['contents'] as &$item ) {
				if ( $discount <= 0 || $cart_total <= 0 ) {
					break 2;
				}

				$item_total    = $item['line_total'];
				$item_discount = $discount * ( $item_total / $cart_total );

				$item['line_total'] -= $item_discount;

				$discount   -= $item_discount;
				$cart_total -= $item_total;
			}
		}

		return $packages;
	}

	/**
	 * Fix cart package item totals when checkout based deposits are
	 * enabled.
	 *
	 * @param array $packages Cart packages
	 * @return array
	 */
	public function filter_cart_packages( $packages ) {
		$deposit_info = WC()->cart->deposit_info;

		if (
			! is_array( $deposit_info ) ||
			empty( $deposit_info['deposit_enabled'] ) ||
			empty( $deposit_info['deposit_breakdown'] )
		) {
			return $packages;
		}

		$shipping_amount = $deposit_info['deposit_breakdown']['shipping'];
		$tax_amount      = $deposit_info['deposit_breakdown']['taxes'];
		$deposit_ex_tax  =
			$deposit_info['deposit_amount'] - $shipping_amount - $tax_amount;

		return $this->adjust_package_totals( $packages, $deposit_ex_tax );
	}

	/**
	 * Calculate taxes on amount due in future for checkout based deposits.
	 */
	protected function calculate_second_payment_tax() {
		// Calculate second payment tax
		$filter_packages = function( $packages ) {
			$future_total      = WC()->cart->deposit_info['second_payment'];
			$adjusted_packages = $this->adjust_package_totals(
				$packages,
				$future_total
			);

			// Future payments are exclusive of fees/shipping
			return array_map(
				function( $package ) {
					return array_merge(
						$package,
						array(
							'fees'     => 0,
							'shipping' => null,
						),
					);
				},
				$adjusted_packages
			);
		};

		remove_filter(
			'wootax_cart_packages',
			array( $this, 'filter_cart_packages' )
		);
		add_filter( 'wootax_cart_packages', $filter_packages );
		add_filter( 'wootax_save_packages_for_capture', '__return_false' );

		WC()->cart->calculate_totals();

		add_filter(
			'wootax_cart_packages',
			array( $this, 'filter_cart_packages' )
		);
		remove_filter( 'wootax_cart_packages', $filter_packages );
		remove_filter( 'wootax_save_packages_for_capture', '__return_false' );

		$tax = WC()->cart->get_total_tax();

		// Restore original totals
		WC()->cart->calculate_totals();

		return $tax;
	}

	/**
	 * Remove extra tax for total cart amount calculated by DFW.
	 *
	 * @param string $price_html Total Cart Amounts price HTML
	 */
	public function fix_total_cart_amount( $price_html ) {
		$info = WC()->cart->deposit_info;

		if ( ! is_array( $info ) ) {
			return $price_html;
		}

		return wc_price( $info['deposit_amount'] + $info['second_payment'] );
	}

	/**
	 * Fix order package totals for duplicate orders when checkout based
	 * deposits are enabled.
	 *
	 * @param array    $packages Cart packages
	 * @param WC_Order $order    Order object
	 * @return array
	 */
	public function filter_order_packages( $packages, $order ) {
		if ( ! $this->is_dfw_duplicate_order( $order ) ) {
			return $packages;
		}

		$parent_id = $order->get_meta( '_original_order_id' );
		$parent    = wc_get_order( $parent_id );

		if ( ! $parent ) {
			SST_Logger::add(
				'[DFW] Could not find parent order for duplicate order ' . $order->get_id()
			);
			return $packages;
		}

		$parent_total       = $parent->get_subtotal() + $parent->get_shipping_total() + $parent->get_total_tax();
		$child_total_ex_tax = $parent_total - $parent->get_meta( '_deposit' );

		return $this->adjust_package_totals( $packages, $child_total_ex_tax );
	}

}

new SST_Deposits_For_WC();
