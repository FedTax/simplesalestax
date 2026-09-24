<?php
/**
 * Request/response contracts for the seven V3 cart, order, and refund endpoints.
 *
 * These tests exercise the production clients with a blocked, in-memory HTTP
 * transport. They never create carts, orders, or refunds in a TaxCloud account.
 */

return function ( SST_V3_Test_Suite $suite ) {

	$suite->test( 'Carts: calculate posts multiple carts and preserves nested values', function () {
		SST_V3_Test_Http::cache_token();
		$first = SST_V3_Fixtures::cart();
		$first['currency'] = array( 'currencyCode' => 'usd' );
		$first['exemption'] = array( 'exemptionId' => 'certificate-1', 'isExempt' => false );
		$first['lineItems'][0]['index'] = '0';
		$first['lineItems'][0]['price'] = '0';
		$first['lineItems'][0]['quantity'] = '1.5';
		$first['lineItems'][0]['tic'] = '0';
		$first['lineItems'][0]['productId'] = 42;
		$second = SST_V3_Fixtures::cart();
		$second['cartId'] = 'cart-2';
		$second['customerId'] = 123;
		$second['deliveredBySeller'] = true;
		$second['exemption'] = array( 'isExempt' => true );
		$response = array(
			'connectionId' => 'test-connection',
			'items' => array(
				array( 'cartId' => 'cart-1', 'lineItems' => array( array( 'index' => 0, 'tax' => array( 'amount' => 0, 'rate' => 0 ) ) ) ),
				array( 'cartId' => 'cart-2', 'lineItems' => array( array( 'index' => 0, 'tax' => array( 'amount' => 2.03, 'rate' => 0.08125 ) ) ) ),
			),
			'transactionDate' => '2026-09-24T12:00:00Z',
		);
		SST_V3_Test_Http::expect(
			'POST',
			SST_V3_Test_Http::api_base() . '/tax/connections/test-connection/carts',
			SST_V3_Test_Http::json( $response ),
			function ( $args ) {
				$body = SST_V3_Test::body( $args );
				SST_V3_Test::same( 2, count( $body['items'] ) );
				SST_V3_Test::same( '2026-09-24T12:00:00Z', $body['transactionDate'] );
				$cart = $body['items'][0];
				SST_V3_Test::same( 'cart-1', $cart['cartId'] );
				SST_V3_Test::same( 'customer-1', $cart['customerId'] );
				SST_V3_Test::same( false, $cart['deliveredBySeller'] );
				SST_V3_Test::same( array( 'currencyCode' => 'USD' ), $cart['currency'] );
				SST_V3_Test::true( ! array_key_exists( 'currencyCode', $cart ), 'Currency must be nested.' );
				SST_V3_Test::same( array( 'exemptionId' => 'certificate-1', 'isExempt' => false ), $cart['exemption'] );
				foreach ( array( 'origin', 'destination' ) as $field ) {
					foreach ( SST_V3_Fixtures::address() as $key => $value ) {
						SST_V3_Test::same( $value, $cart[ $field ][ $key ], $field . '.' . $key );
					}
				}
				$item = $cart['lineItems'][0];
				SST_V3_Test::same( 0, $item['index'] );
				SST_V3_Test::same( 0, $item['price'] );
				SST_V3_Test::same( 1.5, $item['quantity'] );
				SST_V3_Test::same( 0, $item['tic'] );
				SST_V3_Test::same( '42', $item['productId'] );
				SST_V3_Test::true( ! array_key_exists( 'tax', $item ), 'An unset tax must not become null in the request.' );
				SST_V3_Test::same( 'cart-2', $body['items'][1]['cartId'] );
				SST_V3_Test::same( '123', $body['items'][1]['customerId'] );
				SST_V3_Test::same( true, $body['items'][1]['deliveredBySeller'] );
				SST_V3_Test::same( array( 'isExempt' => true ), $body['items'][1]['exemption'] );
			}
		);
		$result = ( new TaxCloud_V3\Carts() )->calculate_tax( array(
			'items' => array( $first, $second ),
			'transactionDate' => '2026-09-24T12:00:00Z',
		) );
		SST_V3_Test::same( $response, $result );
	} );

	$suite->test( 'Carts: calculate allows the server to assign a cart ID and date', function () {
		SST_V3_Test_Http::cache_token();
		$cart = SST_V3_Fixtures::cart();
		unset( $cart['cartId'], $cart['currencyCode'], $cart['deliveredBySeller'] );
		SST_V3_Test_Http::expect(
			'POST', SST_V3_Test_Http::api_base() . '/tax/connections/test-connection/carts',
			SST_V3_Test_Http::json( array( 'items' => array( array( 'cartId' => 'assigned-cart' ) ) ) ),
			function ( $args ) {
				$body = SST_V3_Test::body( $args );
				SST_V3_Test::true( ! array_key_exists( 'transactionDate', $body ) );
				SST_V3_Test::true( ! array_key_exists( 'cartId', $body['items'][0] ) );
				SST_V3_Test::true( ! array_key_exists( 'exemption', $body['items'][0] ) );
				SST_V3_Test::same( false, $body['items'][0]['deliveredBySeller'] );
				SST_V3_Test::same( array( 'currencyCode' => 'USD' ), $body['items'][0]['currency'] );
			}
		);
		$result = ( new TaxCloud_V3\Carts() )->calculate_tax( array( 'items' => array( $cart ) ) );
		SST_V3_Test::same( 'assigned-cart', $result['items'][0]['cartId'] );
	} );

	$suite->test( 'Carts: create order posts IDs and an explicit false completed flag', function () {
		SST_V3_Test_Http::cache_token();
		$response = array( 'orderId' => 'order-1', 'completedDate' => null );
		SST_V3_Test_Http::expect(
			'POST', SST_V3_Test_Http::api_base() . '/tax/connections/test-connection/carts/orders',
			SST_V3_Test_Http::json( $response, 201 ),
			function ( $args ) {
				SST_V3_Test::same( array( 'cartId' => 'cart-1', 'orderId' => 'order-1', 'completed' => false ), SST_V3_Test::body( $args ) );
			}
		);
		SST_V3_Test::same( $response, ( new TaxCloud_V3\Carts() )->create_order( 'cart-1', 'order-1' ) );
	} );

	$suite->test( 'Carts: create order preserves completion date and credit kind', function () {
		SST_V3_Test_Http::cache_token();
		$response = array( 'orderId' => 'order-1', 'kind' => 'credit' );
		SST_V3_Test_Http::expect(
			'POST', SST_V3_Test_Http::api_base() . '/tax/connections/test-connection/carts/orders',
			SST_V3_Test_Http::json( $response, 201 ),
			function ( $args ) {
				SST_V3_Test::same( array(
					'cartId' => 'cart-1', 'orderId' => 'order-1', 'completed' => true,
					'completedDate' => '2026-09-24T12:00:00Z', 'kind' => 'credit',
				), SST_V3_Test::body( $args ) );
			}
		);
		SST_V3_Test::same( $response, ( new TaxCloud_V3\Carts() )->create_order( 'cart-1', 'order-1', true, array(
			'completedDate' => '2026-09-24T12:00:00Z', 'kind' => 'credit', 'unrecognized' => 'omit-me',
		) ) );
	} );

	$suite->test( 'Carts: get encodes the connection and cart IDs as path segments', function () {
		SST_Settings::set( 'tc_key', 'connection /?#' );
		SST_V3_Test_Http::cache_token();
		$response = array( 'cartId' => 'cart /?#', 'lineItems' => array() );
		SST_V3_Test_Http::expect(
			'GET', SST_V3_Test_Http::api_base() . '/tax/connections/connection%20%2F%3F%23/carts/cart%20%2F%3F%23',
			SST_V3_Test_Http::json( $response ),
			function ( $args ) {
				SST_V3_Test::true( ! array_key_exists( 'body', $args ), 'GET must not send a JSON body.' );
			}
		);
		SST_V3_Test::same( $response, ( new TaxCloud_V3\Carts() )->get_cart( 'cart /?#' ) );
	} );

	$suite->test( 'Carts: invalid requests stop before authentication or HTTP', function () {
		$client = new TaxCloud_V3\Carts();
		foreach ( array( null, 'invalid', array(), array( 'items' => array() ), array( 'items' => 'invalid' ), array( 'items' => array( 'invalid' ) ) ) as $input ) {
			SST_V3_Test::error( $client->calculate_tax( $input ), 'sst_v3_carts_invalid_request' );
		}
		foreach ( array( 'customerId', 'destination', 'lineItems', 'origin' ) as $field ) {
			$cart = SST_V3_Fixtures::cart();
			unset( $cart[ $field ] );
			SST_V3_Test::error( $client->calculate_tax( array( 'items' => array( $cart ) ) ), 'sst_v3_carts_invalid_request' );
		}
		foreach ( array( 'index', 'itemId', 'price', 'quantity' ) as $field ) {
			$cart = SST_V3_Fixtures::cart();
			unset( $cart['lineItems'][0][ $field ] );
			SST_V3_Test::error( $client->calculate_tax( array( 'items' => array( $cart ) ) ), 'sst_v3_carts_invalid_request' );
		}
		SST_V3_Test::error( $client->create_order( '', 'order-1' ), 'sst_v3_carts_invalid_request' );
		SST_V3_Test::error( $client->create_order( 'cart-1', '' ), 'sst_v3_carts_invalid_request' );
		SST_V3_Test::error( $client->create_order( 'cart-1', 'order-1', false, 'invalid' ), 'sst_v3_carts_invalid_request' );
		SST_V3_Test::error( $client->get_cart( '' ), 'sst_v3_carts_error' );
		SST_V3_Test::same( array(), SST_V3_Test_Http::requests() );
	} );

	$suite->test( 'Carts: rejects a scalar lineItems collection without HTTP', function () {
		foreach ( array( 'invalid', 42, false ) as $items ) {
			$cart = SST_V3_Fixtures::cart();
			$cart['lineItems'] = $items;
			SST_V3_Test::error( ( new TaxCloud_V3\Carts() )->calculate_tax( array( 'items' => array( $cart ) ) ), 'sst_v3_carts_invalid_request' );
		}
		SST_V3_Test::same( array(), SST_V3_Test_Http::requests() );
	} );

	$suite->test( 'Orders: create posts the documented object and keeps tax-rate precision', function () {
		SST_V3_Test_Http::cache_token();
		$order = SST_V3_Fixtures::order();
		$order['currency'] = array( 'currencyCode' => 'usd' );
		$order['channel'] = 'amazon';
		$order['batchId'] = 'batch-1';
		$order['kind'] = 'order';
		$order['excludeFromFiling'] = false;
		$order['exemption'] = array( 'exemptionId' => 'certificate-1', 'isExempt' => false );
		$order['addressAutocomplete'] = 'destination';
		$order['lineItems'][0]['tax'] = array( 'amount' => 2.03125, 'rate' => 0.08125 );
		$order['lineItems'][] = array( 'index' => 1, 'itemId' => 'free-item', 'price' => 0, 'quantity' => 1, 'tax' => array( 'amount' => 0, 'rate' => 0 ), 'tic' => 0 );
		$response = array( 'orderId' => 'order-1', 'lineItems' => array( array( 'tax' => array( 'amount' => 2.03, 'rate' => 0.08125 ) ) ) );
		SST_V3_Test_Http::expect(
			'POST', SST_V3_Test_Http::api_base() . '/tax/connections/test-connection/orders?addressAutocomplete=destination',
			SST_V3_Test_Http::json( $response, 201 ),
			function ( $args ) {
				$body = SST_V3_Test::body( $args );
				SST_V3_Test::same( 'order-1', $body['orderId'] );
				SST_V3_Test::same( 'customer-1', $body['customerId'] );
				SST_V3_Test::same( '2026-09-24T12:00:00Z', $body['completedDate'] );
				SST_V3_Test::same( '2026-09-24T12:00:00Z', $body['transactionDate'] );
				SST_V3_Test::same( 'amazon', $body['channel'] );
				SST_V3_Test::same( 'batch-1', $body['batchId'] );
				SST_V3_Test::same( 'order', $body['kind'] );
				SST_V3_Test::same( false, $body['deliveredBySeller'] );
				SST_V3_Test::same( false, $body['excludeFromFiling'] );
				SST_V3_Test::same( array( 'currencyCode' => 'USD' ), $body['currency'] );
				SST_V3_Test::same( array( 'exemptionId' => 'certificate-1', 'isExempt' => false ), $body['exemption'] );
				SST_V3_Test::same( array( 'amount' => 2.03, 'rate' => 0.08125 ), $body['lineItems'][0]['tax'] );
				SST_V3_Test::same( array( 'amount' => 0, 'rate' => 0 ), $body['lineItems'][1]['tax'] );
				SST_V3_Test::same( 0, $body['lineItems'][1]['price'] );
				SST_V3_Test::same( 0, $body['lineItems'][1]['tic'] );
				SST_V3_Test::true( ! array_key_exists( 'currencyCode', $body ) );
				SST_V3_Test::true( ! array_key_exists( 'addressAutocomplete', $body ), 'Address autocomplete belongs in the query.' );
				foreach ( array( 'origin', 'destination' ) as $field ) {
					foreach ( SST_V3_Fixtures::address() as $key => $value ) {
						SST_V3_Test::same( $value, $body[ $field ][ $key ], $field . '.' . $key );
					}
				}
			}
		);
		SST_V3_Test::same( $response, ( new TaxCloud_V3\Orders() )->create_order( $order ) );
	} );

	$suite->test( 'Orders: create uses USD and WooCommerce defaults with no optional query', function () {
		SST_V3_Test_Http::cache_token();
		$order = SST_V3_Fixtures::order();
		unset( $order['currencyCode'], $order['deliveredBySeller'] );
		SST_V3_Test_Http::expect(
			'POST', SST_V3_Test_Http::api_base() . '/tax/connections/test-connection/orders',
			SST_V3_Test_Http::json( array( 'orderId' => 'order-1' ), 201 ),
			function ( $args ) {
				$body = SST_V3_Test::body( $args );
				SST_V3_Test::same( array( 'currencyCode' => 'USD' ), $body['currency'] );
				SST_V3_Test::same( 'woocommerce', $body['channel'] );
				foreach ( array( 'deliveredBySeller', 'excludeFromFiling', 'exemption', 'batchId', 'kind', 'discounts' ) as $field ) {
					SST_V3_Test::true( ! array_key_exists( $field, $body ), 'Absent optional field: ' . $field );
				}
			}
		);
		SST_V3_Test::same( array( 'orderId' => 'order-1' ), ( new TaxCloud_V3\Orders() )->create_order( $order ) );
	} );

	$suite->test( 'Orders: get encodes the ID and requests refund expansion', function () {
		SST_V3_Test_Http::cache_token();
		$response = array( 'orderId' => 'order /?#', 'refunds' => array( array( 'idempotencyKey' => 'refund-1' ) ) );
		SST_V3_Test_Http::expect(
			'GET', SST_V3_Test_Http::api_base() . '/tax/connections/test-connection/orders/order%20%2F%3F%23?expand=refunds',
			SST_V3_Test_Http::json( $response ),
			function ( $args ) {
				SST_V3_Test::true( ! array_key_exists( 'body', $args ) );
			}
		);
		SST_V3_Test::same( $response, ( new TaxCloud_V3\Orders() )->get_order( 'order /?#', 'refunds' ) );
	} );

	$suite->test( 'Orders: get omits expansion when none is requested', function () {
		SST_V3_Test_Http::cache_token();
		$response = array( 'orderId' => 'order-1' );
		SST_V3_Test_Http::expect( 'GET', SST_V3_Test_Http::api_base() . '/tax/connections/test-connection/orders/order-1', SST_V3_Test_Http::json( $response ) );
		SST_V3_Test::same( $response, ( new TaxCloud_V3\Orders() )->get_order( 'order-1' ) );
	} );

	$suite->test( 'Orders: update patches only the completed date with an encoded ID', function () {
		SST_V3_Test_Http::cache_token();
		$response = array( 'orderId' => 'order /?#', 'completedDate' => '2026-09-25T12:00:00Z' );
		SST_V3_Test_Http::expect(
			'PATCH', SST_V3_Test_Http::api_base() . '/tax/connections/test-connection/orders/order%20%2F%3F%23',
			SST_V3_Test_Http::json( $response ),
			function ( $args ) {
				SST_V3_Test::same( array( 'completedDate' => '2026-09-25T12:00:00Z' ), SST_V3_Test::body( $args ) );
			}
		);
		SST_V3_Test::same( $response, ( new TaxCloud_V3\Orders() )->update_order( 'order /?#', '2026-09-25T12:00:00Z' ) );
	} );

	$suite->test( 'Orders: an empty update is a JSON object, not a JSON array', function () {
		SST_V3_Test_Http::cache_token();
		SST_V3_Test_Http::expect(
			'PATCH', SST_V3_Test_Http::api_base() . '/tax/connections/test-connection/orders/order-1',
			SST_V3_Test_Http::json( array( 'orderId' => 'order-1' ) ),
			function ( $args ) { SST_V3_Test::same( '{}', $args['body'] ); }
		);
		SST_V3_Test::same( array( 'orderId' => 'order-1' ), ( new TaxCloud_V3\Orders() )->update_order( 'order-1' ) );
	} );

	$suite->test( 'Orders: invalid requests stop before authentication or HTTP', function () {
		$client = new TaxCloud_V3\Orders();
		SST_V3_Test::error( $client->create_order( 'invalid' ), 'sst_v3_orders_invalid_request' );
		foreach ( array( 'completedDate', 'customerId', 'destination', 'lineItems', 'orderId', 'origin', 'transactionDate' ) as $field ) {
			$order = SST_V3_Fixtures::order();
			unset( $order[ $field ] );
			SST_V3_Test::error( $client->create_order( $order ), 'sst_v3_orders_invalid_request' );
		}
		foreach ( array( 'index', 'itemId', 'price', 'quantity', 'tax' ) as $field ) {
			$order = SST_V3_Fixtures::order();
			unset( $order['lineItems'][0][ $field ] );
			SST_V3_Test::error( $client->create_order( $order ), 'sst_v3_orders_invalid_request' );
		}
		foreach ( array( 'invalid', array(), array( 'amount' => 0 ), array( 'rate' => 0 ) ) as $tax ) {
			$order = SST_V3_Fixtures::order();
			$order['lineItems'][0]['tax'] = $tax;
			SST_V3_Test::error( $client->create_order( $order ), 'sst_v3_orders_invalid_request' );
		}
		SST_V3_Test::error( $client->get_order( '' ), 'sst_v3_orders_error' );
		SST_V3_Test::error( $client->update_order( '' ), 'sst_v3_orders_error' );
		SST_V3_Test::same( array(), SST_V3_Test_Http::requests() );
	} );

	$suite->test( 'Orders: rejects a scalar lineItems collection without HTTP', function () {
		foreach ( array( 'invalid', 42, false ) as $items ) {
			$order = SST_V3_Fixtures::order();
			$order['lineItems'] = $items;
			SST_V3_Test::error( ( new TaxCloud_V3\Orders() )->create_order( $order ), 'sst_v3_orders_invalid_request' );
		}
		SST_V3_Test::same( array(), SST_V3_Test_Http::requests() );
	} );

	$suite->test( 'Refunds: full refund posts an empty JSON object and returns the list', function () {
		SST_V3_Test_Http::cache_token();
		$response = array( array( 'items' => array( array( 'itemId' => 'product-1', 'quantity' => 2, 'tax' => array( 'amount' => 2.03 ) ) ) ) );
		SST_V3_Test_Http::expect(
			'POST', SST_V3_Test_Http::api_base() . '/tax/connections/test-connection/orders/refunds/order-1',
			SST_V3_Test_Http::json( $response, 201 ),
			function ( $args ) { SST_V3_Test::same( '{}', $args['body'] ); }
		);
		SST_V3_Test::same( $response, ( new TaxCloud_V3\Refunds() )->refund_order( 'order-1' ) );
	} );

	$suite->test( 'Refunds: partial refund preserves item indices and excludes client tax and price', function () {
		SST_V3_Test_Http::cache_token();
		$response = array( array( 'idempotencyKey' => 'refund-1', 'items' => array( array( 'itemId' => '123', 'quantity' => 0.5 ) ) ) );
		SST_V3_Test_Http::expect(
			'POST', SST_V3_Test_Http::api_base() . '/tax/connections/test-connection/orders/refunds/order%20%2F%3F%23',
			SST_V3_Test_Http::json( $response, 201 ),
			function ( $args ) {
				SST_V3_Test::same( array(
					'items' => array(
						array( 'itemId' => '123', 'quantity' => 0.5, 'cartItemIndex' => 0 ),
						array( 'itemId' => 'product-2', 'quantity' => 1 ),
					),
					'batchId' => 'batch-1', 'idempotencyKey' => 'refund-1', 'returnedDate' => '2026-09-25T12:00:00Z',
				), SST_V3_Test::body( $args ) );
			}
		);
		SST_V3_Test::same( $response, ( new TaxCloud_V3\Refunds() )->refund_order( 'order /?#', array(
			'items' => array(
				array( 'itemId' => 123, 'quantity' => '0.5', 'cartItemIndex' => '0', 'price' => 99, 'tax' => array( 'amount' => 99, 'rate' => 1 ) ),
				array( 'itemId' => 'product-2', 'quantity' => 1 ),
			),
			'batchId' => 'batch-1', 'idempotencyKey' => 'refund-1', 'returnedDate' => '2026-09-25T12:00:00Z',
			'unrecognized' => 'omit-me',
		) ) );
	} );

	$suite->test( 'Refunds: caller retries retain the idempotency key and accept HTTP 200', function () {
		SST_V3_Test_Http::cache_token();
		$response = array( array( 'idempotencyKey' => 'refund-retry', 'items' => array() ) );
		$inspect = function ( $args ) {
			SST_V3_Test::same( array( 'idempotencyKey' => 'refund-retry' ), SST_V3_Test::body( $args ) );
		};
		$url = SST_V3_Test_Http::api_base() . '/tax/connections/test-connection/orders/refunds/order-1';
		SST_V3_Test_Http::expect( 'POST', $url, SST_V3_Test_Http::json( $response, 201 ), $inspect );
		SST_V3_Test_Http::expect( 'POST', $url, SST_V3_Test_Http::json( $response, 200 ), $inspect );
		$client = new TaxCloud_V3\Refunds();
		SST_V3_Test::same( $response, $client->refund_order( 'order-1', array( 'idempotencyKey' => 'refund-retry' ) ) );
		SST_V3_Test::same( $response, $client->refund_order( 'order-1', array( 'idempotencyKey' => 'refund-retry' ) ) );
	} );

	$suite->test( 'Refunds: invalid requests stop before authentication or HTTP', function () {
		$client = new TaxCloud_V3\Refunds();
		SST_V3_Test::error( $client->refund_order( '' ), 'sst_v3_refunds_invalid_request' );
		SST_V3_Test::error( $client->refund_order( 'order-1', 'invalid' ), 'sst_v3_refunds_invalid_request' );
		foreach ( array( 'invalid', array(), array( 'itemId' => 'product-1' ), array( 'quantity' => 1 ) ) as $item ) {
			SST_V3_Test::error( $client->refund_order( 'order-1', array( 'items' => array( $item ) ) ), 'sst_v3_refunds_invalid_request' );
		}
		SST_V3_Test::same( array(), SST_V3_Test_Http::requests() );
	} );

	$suite->test( 'Refunds: rejects scalar items instead of turning them into a full refund', function () {
		foreach ( array( 'invalid', 42, '', 0, false ) as $items ) {
			SST_V3_Test::error( ( new TaxCloud_V3\Refunds() )->refund_order( 'order-1', array( 'items' => $items ) ), 'sst_v3_refunds_invalid_request' );
		}
		SST_V3_Test::same( array(), SST_V3_Test_Http::requests() );
	} );
};
