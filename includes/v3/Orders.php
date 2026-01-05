<?php
namespace TaxCloud_V3;

use SST_Settings;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TaxCloud v3 Orders Class.
 *
 * @author  Simple Sales Tax
 * @package SST
 * @since   8.4.0
 */
class Orders extends RequestBase {
	/**
	 * Initialize the class
	 */
	function __construct() {
		$this->connection_id = SST_Settings::get( 'tc_key' );
		$this->rest_base = 'contacts';
	}

	public function get_api_url( $item_id = null ) {
		return self::API_BASE_URL . '/tax/connections/' . $this->connection_id . '/orders' . ( $item_id ? '/' . $item_id : '' );
	}


// 	// Create Order (POST /tax/connections/:connectionId/orders)
// const response = await fetch("https://api.v3.taxcloud.com/tax/connections/e7d93275-45fd-4dc0-8cae-8767c552ee1d/orders?addressAutocomplete=none", {
//   method: "POST",
//   headers: {
//     "Authorization": "Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJUYXhDbG91ZCB2MyBKV1QiLCJqdGkiOiIwZjY0MjE0MS1iNTY2LTQ5ZTYtYjgwNi1hMjk1ZTZiZDcxZGQiLCJtZXJjaGFudGlkIjoiNjQxOTQiLCJjb250YWN0aWQiOiI2NjcxNiIsImFwaV92ZXJzaW9uIjoidjMiLCJ2M19hcGlfaW50ZWdyYXRpb24iOiJ0cnVlIiwicGVybWlzc2lvbnMiOiJ2M19hcGlfYWNjZXNzIiwiZXhwIjoxNzY3NDcwOTQ5LCJpc3MiOiJUYXhDbG91ZC5TZWN1cml0eS5CZWFyZXIiLCJhdWQiOiJUYXhDbG91ZC5TZWN1cml0eS5CZWFyZXIifQ.FqYqWAfQqgupEKQvUWNPnSfIybhoxS2mCx8MU4TMRIc",
//     "Content-Type": "application/json"
//   },
//   body: JSON.stringify({
//     "completedDate": "2026-01-01T09:30:00Z",
//     "customerId": "customer-453",
//     "destination": {
//       "city": "Minneapolis",
//       "line1": "323 Washington Ave N",
//       "state": "MN",
//       "zip": "55401-2427"
//     },
//     "lineItems": [
//       {
//         "index": 0,
//         "itemId": "item-1",
//         "price": 10.8,
//         "quantity": 1.5,
//         "tax": {
//           "amount": 1.31,
//           "rate": 0.0813
//         }
//       }
//     ],
//     "orderId": "my-order-2",
//     "origin": {
//       "city": "Minneapolis",
//       "line1": "323 Washington Ave N",
//       "state": "MN",
//       "zip": "55401-2427"
//     },
//     "transactionDate": "2026-01-01T09:30:00Z",
//     "currency": {
//       "currencyCode": "USD"
//     }
//   }),
// });

// const body = await response.json();
// console.log(body);

	/**
	 * Create an order in TaxCloud v3 API.	
	 */
	public function create_order( $args ) {
	
		$order = $this->prepare_item_for_request( $args );

		if ( is_wp_error( $order ) ) {
			return $order;
		}

		error_log( 'Order: ' . print_r( $order, true ) );
		exit;


		if ( is_wp_error( $this->get_auth_token() ) ) {
			return $this->get_auth_token();
		}

		$response = wp_remote_post( $this->get_api_url(), array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->get_auth_token(),
				'Content-Type'  => 'application/json',
			),
			'body'    => json_encode( $order ),
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code >= 400 ) {
			return new WP_Error( 'sst_v3_orders_error', 'Failed to create order: ' . $body );
		}

		return json_decode( $body, true );
	}
	
	// prepare_item_for_request
	protected function prepare_item_for_request( $args ) {
		return $args;
	}


	/**
	 * Get order from TaxCloud v3 API.
	 *
	 * @param string $order_id Order ID.
	 * @return array|WP_Error Order array on success, WP_Error on failure.
	 */
	public function get_order( $order_id ) {
		$token = self::get_auth_token();
		

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = wp_remote_get( $this->get_api_url($order_id), array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code >= 400 ) {
			return new WP_Error( 'sst_v3_orders_error', 'Failed to retrieve order: ' . $body );
		}

		return json_decode( $body, true );
	}

}