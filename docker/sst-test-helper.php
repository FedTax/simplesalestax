<?php
/**
 * Plugin Name: SST Test Helper
 * Description: Helpers for running SST e2e tests.
 * Version: 0.1.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Support adding a negative fee during checkout via a query param
add_action(
	'init',
	function() {
		if ( isset( $_GET['add_negative_fee'] ) ) {
			WC()->session->set(
				'add_negative_fee',
				'yes' === $_GET['add_negative_fee']
			);
		}
	}
);

add_action(
	'woocommerce_add_to_cart',
	function() {
		WC()->session->set( 'add_negative_fee', false );
	}
);

add_action(
	'woocommerce_cart_calculate_fees',
	function ( $cart ) {
		if ( WC()->session->get( 'add_negative_fee' ) ) {
			$cart->add_fee( 'Discount', -0.1 * $cart->get_subtotal(), false );
		}
	},
);

// Disable nonce check to enable leveraging Store API in tests
add_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );

// Mark payment complete for orders paid via test payment gateway immediately
add_action(
	'woocommerce_order_status_on-hold',
	function( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( 'cheque' === $order->get_payment_method() ) {
			$order->payment_complete();
		}
	}
);

// Randomize order numbers to avoid collisions
add_filter(
	'sst_package_order_id',
	function ( $_, $order, $package_key ) {
		$order_id = $order->get_meta( 'sst_order_id' );

		if ( ! $order_id ) {
			$order_id = bin2hex( random_bytes( 18 ) );
			$order->update_meta_data( 'sst_order_id', $order_id );
			$order->save();
		}

		return "{$order_id}_{$package_key}";
	},
	10,
	3
);


// Mocking Process

// Enable mock mode during testing via query param, e.g., ?mock_mode=1
add_action('init', function () {
	if (isset($_GET['mock_mode'])) {
		$use_mock = $_GET['mock_mode'] === '1';
		WC()->session->set('mock_mode', $use_mock);
	}
});

