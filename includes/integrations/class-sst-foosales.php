<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FooSales collects WooCommerce tax in the POS before submitting an order.
 */
class SST_FooSales {

	public function __construct() {
		add_filter( 'sst_should_calculate_order_tax', array( $this, 'preserve_collected_tax' ), 10, 2 );
		add_filter( 'woocommerce_order_get_tax_location', array( $this, 'use_store_tax_location' ), 10, 2 );
		add_filter( 'wootax_order_packages_before_split', array( $this, 'use_store_address' ), 10, 2 );
	}

	/**
	 * Recalculating at submission could increase the total after POS payment.
	 * TaxCloud can still look up the order when preparing it for capture.
	 *
	 * @param bool     $calculate Whether to calculate tax.
	 * @param WC_Order $order     Order being calculated.
	 * @return bool
	 */
	public function preserve_collected_tax( $calculate, $order ) {
		return 'foosales_app' === $order->get_meta( '_foosales_order_source' ) ? false : $calculate;
	}

	/**
	 * Orders without shipping are in-store sales, even for an existing customer
	 * whose saved billing address is in another state.
	 *
	 * @param array    $location Tax location.
	 * @param WC_Order $order    Order being calculated.
	 * @return array
	 */
	public function use_store_tax_location( $location, $order ) {
		if ( ! $this->is_in_store_order( $order ) ) {
			return $location;
		}

		return array(
			'country'  => WC()->countries->get_base_country(),
			'state'    => WC()->countries->get_base_state(),
			'postcode' => WC()->countries->get_base_postcode(),
			'city'     => WC()->countries->get_base_city(),
		);
	}

	/**
	 * Give TaxCloud the physical store as both origin and destination for
	 * in-store sales. Guest POS orders normally have no customer addresses.
	 *
	 * @param array    $packages Order packages before origin splitting.
	 * @param WC_Order $order    Order being reported.
	 * @return array
	 */
	public function use_store_address( $packages, $order ) {
		if ( ! $this->is_in_store_order( $order ) ) {
			return $packages;
		}

		$location = $this->use_store_tax_location( array(), $order );
		$address  = get_option( 'woocommerce_store_address', '' );
		$address2 = get_option( 'woocommerce_store_address_2', '' );

		if ( 'US' !== $location['country'] || ! $address || ! $location['state'] || ! $location['city'] || ! $location['postcode'] ) {
			return $packages;
		}

		$destination = array_merge( $location, array( 'address' => $address, 'address_2' => $address2 ) );
		$origin      = new SST_Origin_Address(
			'foosales-store',
			true,
			$address,
			$address2,
			$location['city'],
			$location['state'],
			$location['postcode']
		);

		foreach ( $packages as &$package ) {
			$package['destination'] = $destination;
			$package['origin']      = $origin;
		}
		unset( $package );

		return $packages;
	}

	/**
	 * @param WC_Order $order Order to inspect.
	 * @return bool
	 */
	private function is_in_store_order( $order ) {
		return 'foosales_app' === $order->get_meta( '_foosales_order_source' ) && ! $order->get_shipping_methods();
	}
}

new SST_FooSales();
