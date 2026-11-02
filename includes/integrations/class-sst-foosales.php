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
		add_action( 'sst_before_order_capture', array( $this, 'check_collected_tax' ), 10, 2 );
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

		foreach ( $packages as $key => &$package ) {
			$package['destination'] = $destination;
			// create_packages merges by origin ID; keep virtual and physical
			// base packages distinct even though both use the storefront.
			$package['origin'] = clone $origin;
			$package['origin']->setID( 'foosales-store-' . $key );
		}
		unset( $package );

		return $packages;
	}

	/**
	 * Flag stale POS rates without changing the amount already collected.
	 * Both real-time APIs capture the lookup's tax, not WooCommerce's tax.
	 *
	 * @param WC_Order $order    Order about to be captured.
	 * @param array    $packages Validated lookup packages used for capture.
	 */
	public function check_collected_tax( $order, $packages ) {
		if ( 'foosales_app' !== $order->get_meta( '_foosales_order_source' ) ) {
			return;
		}

		$taxcloud_tax = 0.0;
		foreach ( $packages as $package ) {
			$response = $package['response'] ?? null;
			// Historical packages may not include quote amounts. Do not treat
			// an unavailable comparison as a zero-tax quote.
			if ( ! is_array( $response ) || ! $response ) {
				return;
			}
			$amounts = reset( $response );
			if ( ! is_array( $amounts ) || ! $amounts ) {
				return;
			}
			foreach ( $amounts as $amount ) {
				if ( ! is_numeric( $amount ) || ! is_finite( (float) $amount ) ) {
					return;
				}
				$taxcloud_tax += (float) $amount;
			}
		}

		$decimals  = wc_get_price_decimals();
		$precision = pow( 10, $decimals );
		$collected = (int) round( (float) $order->get_total_tax() * $precision );
		$quoted    = (int) round( $taxcloud_tax * $precision );
		if ( $collected === $quoted ) {
			return;
		}

		$notice = array(
			'collected' => number_format( $collected / $precision, $decimals, '.', '' ),
			'quoted'    => number_format( $quoted / $precision, $decimals, '.', '' ),
			'currency'  => $order->get_currency(),
		);
		if ( $notice === $order->get_meta( '_sst_foosales_tax_mismatch_notice' ) ) {
			return;
		}

		$message = sprintf(
			/* translators: 1: tax collected by POS, 2: currency code, 3: TaxCloud tax. */
			__( 'FooSales tax mismatch: POS collected %1$s %2$s, but TaxCloud calculated %3$s %2$s. TaxCloud capture uses its calculated tax; the paid WooCommerce total has not been changed. Review the POS tax rates and product tax classes, then synchronize FooSales.', 'simple-sales-tax' ),
			$notice['collected'],
			$notice['currency'],
			$notice['quoted']
		);
		$order->add_order_note( $message );
		SST_Logger::order_log( $message, $order->get_id() );
		// Persist the notice even if capture fails so a retry does not add
		// the same warning again. Only metadata is saved, not paid totals.
		$order->update_meta_data( '_sst_foosales_tax_mismatch_notice', $notice );
		$order->save_meta_data();
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
