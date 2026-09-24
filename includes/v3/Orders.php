<?php
namespace TaxCloud_V3;

use SST_Settings;
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
 * @since   8.4.1
 */
class Orders extends RequestBase {

	/**
	 * Initialize the class.
	 *
	 * @since 8.4.1
	 */
	function __construct() {
		$this->connection_id = SST_Settings::get( 'tc_key' );
	}

	/**
	 * Get API URL for orders.
	 *
	 * @param string|null $item_id Optional order ID.
	 *
	 * @return string API URL.
	 * @since 8.4.1
	 */
	public function get_api_url( $item_id = null ) {
		$url = $this->get_api_base_url() . '/tax/connections/' . rawurlencode( (string) $this->connection_id ) . '/orders';

		if ( null !== $item_id && '' !== (string) $item_id ) {
			$url .= '/' . rawurlencode( (string) $item_id );
		}

		return $url;
	}

	/**
	 * Create an order in TaxCloud v3 API.	
	 *
	 * @param array $args Request arguments.
	 *
	 * @return array|\WP_Error Order response on success, WP_Error on failure.
	 * @since 8.4.1
	 */
	public function create_order( $args ) {
	
		$order = $this->prepare_item_for_request( $args );

		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$token = $this->get_auth_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$url = $this->get_api_url();
		if ( ! empty( $args['addressAutocomplete'] ) ) {
			$url = add_query_arg( 'addressAutocomplete', $args['addressAutocomplete'], $url );
		}

		$response = wp_remote_post( $url, array(
			'headers' => $this->get_request_headers( $token ),
			'body'    => wp_json_encode( $order ),
			'timeout' => 30,
		) );

		return $this->parse_json_response( $response, 'sst_v3_orders_error', 'Failed to create order: ' );
	}
	
	/**
	 * Prepare item for request.
	 *
	 * @param array $args Request arguments.
	 *
	 * @return array|\WP_Error Prepared item on success, WP_Error on failure.
	 * @since 8.4.1
	 */
	public function prepare_item_for_request( $args ) {
		if ( ! is_array( $args ) ) {
			return new \WP_Error( 'sst_v3_orders_invalid_request', 'Order data must be an array.' );
		}

		foreach ( array( 'completedDate', 'customerId', 'destination', 'lineItems', 'orderId', 'origin', 'transactionDate' ) as $required_field ) {
			if ( ! isset( $args[ $required_field ] ) || '' === $args[ $required_field ] || array() === $args[ $required_field ] ) {
				return new \WP_Error( 'sst_v3_orders_invalid_request', sprintf( 'Order field %s is required.', $required_field ) );
			}
		}

		if ( ! is_array( $args['lineItems'] ) ) {
			return new \WP_Error( 'sst_v3_orders_invalid_request', 'Order lineItems must be an array.' );
		}

		$request_args = array(
			'channel' => isset( $args['channel'] ) ? $args['channel'] : 'woocommerce',
			'completedDate' => $args['completedDate'],
			'customerId' => (string) $args['customerId'],
			'orderId' => (string) $args['orderId'],
			'transactionDate' => $args['transactionDate'],
		);

		// Prepare line items
		$lineItems = array();
		if ( isset( $args['lineItems'] ) && ! empty( $args['lineItems'] ) ) {
			foreach ( $args['lineItems'] as $lineItem ) {
				foreach ( array( 'index', 'itemId', 'price', 'quantity', 'tax' ) as $required_field ) {
					if ( ! is_array( $lineItem ) || ! array_key_exists( $required_field, $lineItem ) ) {
						return new \WP_Error( 'sst_v3_orders_invalid_request', sprintf( 'Order line item field %s is required.', $required_field ) );
					}
				}

				if ( ! is_array( $lineItem['tax'] ) || ! array_key_exists( 'amount', $lineItem['tax'] ) || ! array_key_exists( 'rate', $lineItem['tax'] ) ) {
					return new \WP_Error( 'sst_v3_orders_invalid_request', 'Each order line item tax must include amount and rate.' );
				}

				// Tax amounts are currency values. Rates must retain API precision (for example, 0.08125).
				$lineItem['tax']['amount'] = round( (float) $lineItem['tax']['amount'], 2 );
				
				$lineItems[] = new CartItem( $lineItem );
			}
			$request_args['lineItems'] = $lineItems;
		}
		
		// Prepare currency
		$currency_code = isset( $args['currency']['currencyCode'] ) ? $args['currency']['currencyCode'] : ( isset( $args['currencyCode'] ) ? $args['currencyCode'] : 'USD' );
		$request_args['currency'] = new Currency( $currency_code );
		
		// Prepare exemption
		if ( isset( $args['exemption'] ) ) {
			$request_args['exemption'] = new Exemption( $args['exemption'] );
		}

		// Prepare destination
		if ( isset( $args['destination'] ) && ! empty( $args['destination'] ) ) {
			$request_args['destination'] = new Address( $args['destination'] );
		}

		// Prepare origin
		if ( isset( $args['origin'] ) && ! empty( $args['origin'] ) ) {
			$request_args['origin'] = new Address( $args['origin'] );
		}

		foreach ( array( 'batchId', 'deliveredBySeller', 'discounts', 'excludeFromFiling', 'kind' ) as $optional_field ) {
			if ( array_key_exists( $optional_field, $args ) ) {
				$request_args[ $optional_field ] = $args[ $optional_field ];
			}
		}

		return $request_args;
	}

	/**
	 * Get order from TaxCloud v3 API.
	 *
	 * @param string $order_id Order ID.
	 * @param string $expand   Optional comma-separated fields to expand.
	 *
	 * @return array|\WP_Error Order array on success, WP_Error on failure.
	 * @since 8.4.1
	 */
	public function get_order( $order_id, $expand = '' ) {
		if ( '' === (string) $order_id ) {
			return new \WP_Error( 'sst_v3_orders_error', 'An order ID is required.' );
		}

		$token = $this->get_auth_token();
		
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$url = $this->get_api_url( $order_id );
		if ( '' !== $expand ) {
			$url = add_query_arg( 'expand', $expand, $url );
		}

		$response = wp_remote_get( $url, array(
			'headers' => $this->get_request_headers( $token ),
			'timeout' => 30,
		) );

		return $this->parse_json_response( $response, 'sst_v3_orders_error', 'Failed to retrieve order: ' );
	}

	/**
	 * Update an order's completed date.
	 *
	 * @param string      $order_id      Order ID.
	 * @param string|null $completed_date RFC3339 completed date, or null to send an empty update.
	 *
	 * @return array|\WP_Error Order response on success, WP_Error on failure.
	 * @since 8.4.17
	 */
	public function update_order( $order_id, $completed_date = null ) {
		if ( '' === (string) $order_id ) {
			return new \WP_Error( 'sst_v3_orders_error', 'An order ID is required.' );
		}

		$token = $this->get_auth_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$payload = array();
		if ( null !== $completed_date && '' !== $completed_date ) {
			$payload['completedDate'] = $completed_date;
		}

		$response = wp_remote_request( $this->get_api_url( $order_id ), array(
			'method'  => 'PATCH',
			'headers' => $this->get_request_headers( $token ),
			'body'    => wp_json_encode( (object) $payload ),
			'timeout' => 30,
		) );

		return $this->parse_json_response( $response, 'sst_v3_orders_error', 'Failed to update order: ' );
	}

}
