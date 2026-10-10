<?php

class SST_V3_Fixtures {
	public static function address() {
		return array( 'line1' => '123 Main St', 'line2' => 'Suite 2', 'city' => 'Seattle', 'state' => 'WA', 'zip' => '98101', 'countryCode' => 'US' );
	}

	public static function cart() {
		return array(
			'cartId' => 'cart-1',
			'customerId' => 'customer-1',
			'currencyCode' => 'USD',
			'deliveredBySeller' => false,
			'origin' => self::address(),
			'destination' => self::address(),
			'lineItems' => array( array( 'index' => 0, 'itemId' => 'product-1', 'price' => 12.5, 'quantity' => 2, 'tic' => 10010 ) ),
		);
	}

	public static function order() {
		$order = self::cart();
		unset( $order['cartId'] );
		$order['orderId'] = 'order-1';
		$order['completedDate'] = '2026-09-24T12:00:00Z';
		$order['transactionDate'] = '2026-09-24T12:00:00Z';
		$order['lineItems'][0]['tax'] = array( 'amount' => 2.03, 'rate' => 0.08125 );
		return $order;
	}

	public static function certificate() {
		return array(
			'address' => self::address(),
			'customerBusinessType' => 'RetailTrade',
			'customerId' => 'customer-1',
			'customerName' => 'Test Buyer',
			'reason' => 'Resale',
			'reasonDescription' => 'Resale',
			'states' => array( array( 'abbreviation' => 'WA' ) ),
		);
	}

	/** Independent endpoint manifest for the common authentication/error matrix. */
	public static function endpoints() {
		$connection = '/tax/connections/test-connection';
		return array(
			'Calculate carts' => array( 'POST', $connection . '/carts', function () { return ( new TaxCloud_V3\Carts() )->calculate_tax( array( 'items' => array( self::cart() ) ) ); }, 'sst_v3_carts_error' ),
			'Create order from cart' => array( 'POST', $connection . '/carts/orders', function () { return ( new TaxCloud_V3\Carts() )->create_order( 'cart-1', 'order-1', true ); }, 'sst_v3_carts_error' ),
			'Get cart' => array( 'GET', $connection . '/carts/cart-1', function () { return ( new TaxCloud_V3\Carts() )->get_cart( 'cart-1' ); }, 'sst_v3_carts_error' ),
			'Create order' => array( 'POST', $connection . '/orders', function () { return ( new TaxCloud_V3\Orders() )->create_order( self::order() ); }, 'sst_v3_orders_error' ),
			'Get order' => array( 'GET', $connection . '/orders/order-1', function () { return ( new TaxCloud_V3\Orders() )->get_order( 'order-1' ); }, 'sst_v3_orders_error' ),
			'Update order' => array( 'PATCH', $connection . '/orders/order-1', function () { return ( new TaxCloud_V3\Orders() )->update_order( 'order-1', '2026-09-24T12:00:00Z' ); }, 'sst_v3_orders_error' ),
			'Refund order' => array( 'POST', $connection . '/orders/refunds/order-1', function () { return ( new TaxCloud_V3\Refunds() )->refund_order( 'order-1' ); }, 'sst_v3_refunds_error' ),
			'Create certificate' => array( 'POST', $connection . '/exemption-certificates', function () { return ( new TaxCloud_V3\Exemptions() )->create_certificate( self::certificate() ); }, 'sst_v3_exemptions_error' ),
			'Get certificate' => array( 'GET', $connection . '/exemption-certificates/cert-1', function () { return ( new TaxCloud_V3\Exemptions() )->get_certificate( 'cert-1' ); }, 'sst_v3_exemptions_error' ),
			'Disable certificate' => array( 'DELETE', $connection . '/exemption-certificates/cert-1', function () { return ( new TaxCloud_V3\Exemptions() )->delete_certificate( 'cert-1' ); }, 'sst_v3_exemptions_error' ),
			'List certificates' => array( 'GET', '/tax/exemption-certificates', function () { return ( new TaxCloud_V3\Exemptions() )->get_certificates(); }, 'sst_v3_exemptions_error' ),
			'Ping' => array( 'GET', $connection . '/ping', function () { return ( new TaxCloud_V3\Utilities() )->ping(); }, 'sst_v3_ping_error' ),
			'Search TICs' => array( 'POST', '/tax/tic/search', function () { return ( new TaxCloud_V3\Utilities() )->search_tics( 'shoes' ); }, 'sst_v3_tic_search_error' ),
			'Verify address' => array( 'POST', '/tax/verify-address', function () { return ( new TaxCloud_V3\Utilities() )->verify_address( self::address() ); }, 'sst_v3_verify_address_error' ),
		);
	}
}
