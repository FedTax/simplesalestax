<?php

/**
 * Adds support for the WooCommerce Subscriptions plugin by Brent Shepard
 * @see PLUGIN_DIR/woocommerce-subscriptions/woocommerce-subscriptions.php
 *
 * @package WooCommerce TaxCloud
 * @since 4.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Prevent direct access
}

class WC_WooTax_Subscriptions {
	/**
	 * Constructor
	 *
	 * Hook into WordPress/WooCommerce
	 *
	 * @since 4.2
	 */
	public function __construct() {
		// Change WooCommerce tax key so recurring taxes are displayed correctly
		add_filter( 'woocommerce_get_order_item_totals', array( $this, 'get_order_item_totals' ), 5, 2 );

		// Handle renewal orders
		add_action( 'woocommerce_renewal_order_payment_complete', array( $this, 'handle_renewal_order' ), 10, 1 );

		// Fix recurring taxes: add "cart_tax" and "shipping_tax" meta keys
		add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'fix_recurring_taxes' ), 15, 2 );

		// Remove duplicate tax columns from renewal orders
		add_action( 'woocommerce_subscriptions_renewal_order_created', array( $this, 'remove_duplicate_renewal_taxes' ), 10, 2 );
	}

	/**
	 * Modify WooTax item in order total rows; Change key from wootax-rate-do-not-remove to sales-tax
	 *
	 * @since 4.2
	 */
	public function get_order_item_totals( $total_rows, $order ) {
		if ( WC_Subscriptions_Order::order_contains_subscription( $order ) && WC_Subscriptions_Order::get_recurring_total_tax( $order ) > 0 && 'incl' !== $order->tax_display_cart ) {				
			$new_total_rows = array();

			foreach ( $total_rows as $row_key => $data ) {
				if ( $row_key == strtolower( apply_filters( 'wootax_rate_code', 'WOOTAX-RATE-DO-NOT-REMOVE' ) ) ) {
					$row_key = 'sales-tax';
				}

				$new_total_rows[ $row_key ] = $data;
			}
			return $new_total_rows;
		}

		return $total_rows;
	}

	/**
	 * Notify TaxCloud of tax collected on renewals
	 *
	 * @since 4.0
	 * @param (int) $order_id the ID of the renewal order
	 */
	public function handle_renewal_order( $order_id ) {
		$order = WT_Orders::get_order( $order_id );

		// Set destination address based on original order
		$renewal_parent = get_post_meta( $order_id, '_original_order', true );
		$original_order = new WC_Order( $renewal_parent );

		$order->destination_address = $this->get_destination_address( $original_order );
		
		// Reset order meta values to default 
		foreach ( WT_Orders::$defaults as $key => $val ) {
			WT_Orders::update_meta( $order_id, $key, $val );
		}

		$tax_based_on = WC_WooTax::get_option( 'tax_based_on' );

		// Build order items array
		$order_items = $order->order->get_items() + $order->order->get_fees();

		if ( version_compare( WOOCOMMERCE_VERSION, '2.2', '<' ) ) {
			$shipping_method = array( 
				'type' => 'shipping',
				'cost' => $order->order->get_total_shipping(),
			);

			$order_items = $order_items + array( WT_SHIPPING_ITEM => $shipping_method );
		} else {
			$order_items = $order_items + $order->order->get_shipping_methods();
		}
		
		// Prepare items for lookup
		$final_items = $type_array = array();

		foreach ( $order_items as $item_id => $item ) {
			$type = $item['type'];
			$qty  = isset( $item['qty'] ) ? $item['qty'] : 1;
			$cost = isset( $item['cost'] ) ? $item['cost'] : $item['line_total']; // 'cost' key used by shipping methods in 2.2

			switch ( $type ) {
				case 'shipping':
					$tic = WT_SHIPPING_TIC;
					break;
				case 'fee':
					$tic = WT_FEE_TIC;
					break;
				case 'line_item':
					$tic  = wt_get_product_tic( $item['product_id'], $item['variation_id'] );
					$type = 'cart';
					break;
			}

			// Only add an item if its cost is nonzero
			if ( $cost != 0 ) {
				$unit_price = $cost / $qty;

				if ( $tax_based_on == 'item-price' || !$tax_based_on ) {
					$price = $unit_price; 
				} else {
					$qty   = 1;
					$price = $cost; 
				}

				$item_data = array(
					'Index'  => '',
					'ItemID' => $item_id,
					'Qty'    => $qty,
					'Price'  => $price,
					'Type'   => $type,
				);	

				if ( $tic )
					$item_data['TIC'] = $tic;

				$final_items[] = $item_data;

				$type_array[ $item_id ] = $type;
			}
		}

		// Perform a tax lookup with the renewal prices
		$result = $order->do_lookup( $final_items, $type_array );

		// Add errors as notes if necessary
		if ( !is_array( $result ) ) {
			$original_order->add_order_note( sprintf( __( 'Tax lookup for renewal order %s failed. Reason: '. $result, 'woocommerce-subscriptions' ), $order_id ) );
		} else {
			// Mark order as captured
			$order->order->update_status( 'completed' );

			// Add success note
			$original_order->add_order_note( sprintf( __( 'TaxCloud was successfully notified of renewal order %s.', 'woocommerce-subscriptions' ), $order_id ) );
		}
	}

	/**
	 * Add cart_tax/shipping_tax key to any recurring taxes
	 *
	 * @since 4.2
	 * @param $order_id a WooCommerce order ID
	 */
	public function fix_recurring_taxes( $order_id, $posted ) {
		$order = new WC_Order( $order_id );

		if ( WC_Subscriptions_Order::order_contains_subscription( $order ) && WC_Subscriptions_Order::get_recurring_total_tax( $order ) > 0 && 'incl' !== $order->tax_display_cart ) {			
			if ( count( $order->get_items( 'recurring_tax' ) ) > 0 ) {
				foreach ( $order->get_items( 'recurring_tax' ) as $item_id => $item ) {
					wc_update_order_item_meta( $item_id, 'cart_tax', $item['tax_amount'] );
					wc_update_order_item_meta( $item_id, 'shipping_tax', $item['shipping_tax_amount'] );
					wc_update_order_item_meta( $item_id, 'compound', true );
				}
			}
		}
	}

	/**
	 * Get destination address information from original order
	 *
	 * @since 4.2
	 */
	public function get_destination_address( $order ) {
		// Initialize blank address array
		$address = array();
		
		// Construct final address arraya
		$parsed_zip = parse_zip( $order->shipping_postcode );

		$address['Address1'] = $order->shipping_address_1;
		$address['Address2'] = $order->shipping_address_2;
		$address['Country']  = $order->shipping_country;
		$address['State']    = $order->shipping_state;
		$address['City']     = $order->shipping_city;
		$address['Zip5']     = $parsed_zip['zip5'];
		$address['Zip4']     = $parsed_zip['zip4']; 

		// Return final address
		return $address;
	}

	/**
	 * Remove duplicate tax column from renewal orders
	 *
	 * @since 4.2
	 */
	public function remove_duplicate_renewal_taxes( $renewal_order, $original_order ) {
		global $wpdb;

		$original_taxes = $original_order->get_items( 'recurring_tax' );
		$new_taxes      = $renewal_order->get_taxes();
		$to_remove      = array();

		foreach ( $original_taxes as $tax_item_id => $data ) {
			if ( $data['rate_id'] != WT_RATE_ID ) {
				continue;
			}

			foreach ( $new_taxes as $tax_id => $tax_data ) {
				if ( $tax_data['tax_amount'] == $data['tax_amount'] && $tax_data['rate_id'] == $data['rate_id'] ) {
					$to_remove[] = $tax_id;
				}
			}
		}	

		foreach ( $to_remove as $tax_item_id ) {
			wc_delete_order_item( $tax_item_id );
		}
	}	
}

new WC_WooTax_Subscriptions();