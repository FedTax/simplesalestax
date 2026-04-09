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
 * TaxCloud v3 Carts Class.
 *
 * @author  Simple Sales Tax
 * @package SST
 * @since   8.4.1
 */
class Carts extends RequestBase {

	/**
	 * Initialize the class.
	 *
	 * @since 8.4.1
	 */
	function __construct() {
		$this->connection_id = SST_Settings::get( 'tc_connection_id' );
		if ( ! $this->connection_id ) {
			$this->connection_id = SST_Settings::get( 'tc_key' ); // Fallback
		}
	}

	/**
	 * Get API URL for carts.
	 *
	 * @return string API URL.
	 * @since 8.4.1
	 */
	public function get_api_url() {
		return rtrim( self::API_BASE_URL, '/' ) . '/tax/connections/' . $this->connection_id . '/carts';
	}

	/**
	 * Get API URL for creating orders from carts.
	 *
	 * @return string API URL.
	 * @since 8.4.1
	 */
	public function get_order_api_url() {
		return $this->get_api_url() . '/orders';
	}

	/**
	 * Calculate sales tax for one or more carts.
	 *
	 * @param array $args Request arguments.
	 *
	 * @return array|WP_Error Response on success, WP_Error on failure.
	 * @since 8.4.1
	 */
	public function calculate_tax( $args ) {
		$payload = $this->prepare_item_for_request( $args );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$token = $this->get_auth_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = wp_remote_post( $this->get_api_url(), array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => json_encode( $payload ),
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code >= 400 ) {
			return new WP_Error( 'sst_v3_carts_error', 'Failed to calculate tax: ' . $body );
		}

		return json_decode( $body, true );
	}

	/**
	 * Create an order from a cartID.
	 *
	 * @param string $cart_id   ID of the cart.
	 * @param string $order_id  ID of the order in external system.
	 * @param bool   $completed Whether the order is completed/shipped.
	 *
	 * @return array|WP_Error Response on success, WP_Error on failure.
	 * @since 8.4.1
	 */
	public function create_order( $cart_id, $order_id, $completed = false ) {
		$token = $this->get_auth_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$payload = array(
			'cartId'    => $cart_id,
			'orderId'   => $order_id,
			'completed' => $completed,
		);

		$response = wp_remote_post( $this->get_order_api_url(), array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => json_encode( $payload ),
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code >= 400 ) {
			return new WP_Error( 'sst_v3_carts_error', 'Failed to create order from cart: ' . $body );
		}

		return json_decode( $body, true );
	}

	/**
	 * Prepare item for request.
	 *
	 * @param array $args Request arguments.
	 *
	 * @return array|WP_Error Prepared item on success, WP_Error on failure.
	 * @since 8.4.1
	 */
	public function prepare_item_for_request( $args ) {
		$items = array();

		foreach ( $args['items'] as $cart ) {
			$prepared_cart = array(
				'cartId'            => $cart['cartId'],
				'customerId'        => $cart['customerId'],
				'deliveredBySeller' => isset( $cart['deliveredBySeller'] ) ? $cart['deliveredBySeller'] : false,
			);

			// Currency
			$prepared_cart['currency'] = new Currency( isset( $cart['currencyCode'] ) ? $cart['currencyCode'] : 'USD' );

			// Destination
			if ( isset( $cart['destination'] ) ) {
				$destination = $cart['destination'];
				$prepared_cart['destination'] = new Address( array(
					'city' => $destination['city'],
					'line1' => $destination['line1'],
					'state' => $destination['state'],
					'zip' => $destination['zip'],
				) );
			}

			// Origin
			if ( isset( $cart['origin'] ) ) {
				$origin = $cart['origin'];
				$prepared_cart['origin'] = new Address( array(
					'city' => $origin['city'],
					'line1' => $origin['line1'],
					'state' => $origin['state'],
					'zip' => $origin['zip'],
				) );
			}

			// Line Items
			$prepared_cart['lineItems'] = array();
			foreach ( $cart['lineItems'] as $item ) {
				$prepared_cart['lineItems'][] = new CartItem( $item );
			}

			// Exemption
			if ( isset( $cart['exemption'] ) ) {
				$prepared_cart['exemption'] = new Exemption( $cart['exemption'] );
			}

			$items[] = (object) $prepared_cart;
		}

		return array(
			'items'           => $items,
			'transactionDate' => isset( $args['transactionDate'] ) ? $args['transactionDate'] : gmdate( 'c' ),
		);
	}
}
