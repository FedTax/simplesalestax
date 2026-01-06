<?php
namespace TaxCloud_V3;

use SST_Settings;
use WP_Error;
use TaxCloud_V3\Model\Address;
use TaxCloud_V3\Model\CartItem;
use TaxCloud_V3\Model\Currency;
use TaxCloud_V3\Model\Exemption;

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

	/**
	 * Create an order in TaxCloud v3 API.	
	 */
	public function create_order( $args ) {
	
		$order = $this->prepare_item_for_request( $args );

		if ( is_wp_error( $order ) ) {
			return $order;
		}

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
	
	/**
	 * Prepare item for request.
	 *
	 * @param array $args Request arguments.
	 * @return array|WP_Error Prepared item on success, WP_Error on failure.
	 */
	public function prepare_item_for_request( $args ) {

		$request_args = array(
			'channel' => 'woocommerce',
			'completedDate' => $args['completedDate'],
			'customerId' => $args['customerId'],
			'orderId' => $args['orderId'],
			'transactionDate' => $args['transactionDate'],
		);

		// Prepare line items
		$lineItems = array();
		if ( isset( $args['lineItems'] ) && ! empty( $args['lineItems'] ) ) {
			foreach ( $args['lineItems'] as $lineItem ) {
				$lineItems[] = new CartItem( $lineItem );
			}
			$request_args['lineItems'] = $lineItems;
		}
		
		// Prepare currency
		if ( isset( $args['currencyCode'] ) ) {
			$request_args['currency'] = new Currency( $args['currencyCode'] );
		}
		
		// Prepare exemption
		if ( isset( $args['exemption'] ) ) {
			$request_args['exemption'] = new Exemption( $args['exemption'] );
		}

		// Prepare destination
		if ( isset( $args['destination'] ) && ! empty( $args['destination'] ) ) {
			$destination = $args['destination'];
			$request_args['destination'] = new Address(array(
				'city' => $destination['city'],
				'line1' => $destination['line1'],
				'state' => $destination['state'],
				'zip' => $destination['zip'],
			));
		}

		// Prepare origin
		if ( isset( $args['origin'] ) && ! empty( $args['origin'] ) ) {
			$origin = $args['origin'];
			$request_args['origin'] = new Address(array(
				'city' => $origin['city'],
				'line1' => $origin['line1'],
				'state' => $origin['state'],
				'zip' => $origin['zip'],
			));
		}
		
		return $request_args;
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