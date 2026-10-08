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
		$this->connection_id = SST_Settings::get( 'tc_key' );
	}

	/**
	 * Get API URL for carts.
	 *
	 * @param string|null $cart_id Optional cart ID.
	 *
	 * @return string API URL.
	 * @since 8.4.1
	 */
	public function get_api_url( $cart_id = null ) {
		$url = $this->get_api_base_url() . '/tax/connections/' . rawurlencode( (string) $this->connection_id ) . '/carts';

		if ( null !== $cart_id && '' !== (string) $cart_id ) {
			$url .= '/' . rawurlencode( (string) $cart_id );
		}

		return $url;
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
	 * @return array|\WP_Error Response on success, \WP_Error on failure.
	 * @since 8.4.1
	 */
	public function calculate_tax( $args ) {
		$payload = $this->prepare_item_for_request( $args );

		if ( \is_wp_error( $payload ) ) {
			return $payload;
		}

		$token = $this->get_auth_token();
		if ( \is_wp_error( $token ) ) {
			return $token;
		}

		$response = \wp_remote_post( $this->get_api_url(), array(
			'headers' => $this->get_request_headers( $token ),
			'body'    => \wp_json_encode( $payload ),
			'timeout' => 30,
		) );

		return $this->parse_json_response( $response, 'sst_v3_carts_error', 'Failed to calculate tax: ' );
	}

	/**
	 * Create an order from a cartID.
	 *
	 * @param string $cart_id   ID of the cart.
	 * @param string $order_id  ID of the order in external system.
	 * @param bool   $completed Whether the order is completed/shipped.
	 * @param array  $args      Optional completedDate and kind fields.
	 *
	 * @return array|\WP_Error Response on success, \WP_Error on failure.
	 * @since 8.4.1
	 */
	public function create_order( $cart_id, $order_id, $completed = false, $args = array() ) {
		if ( '' === (string) $cart_id || '' === (string) $order_id ) {
			return new \WP_Error( 'sst_v3_carts_invalid_request', 'A cart ID and order ID are required.' );
		}

		if ( ! is_array( $args ) ) {
			return new \WP_Error( 'sst_v3_carts_invalid_request', 'Optional cart order fields must be an array.' );
		}

		$token = $this->get_auth_token();
		if ( \is_wp_error( $token ) ) {
			return $token;
		}

		$payload = array(
			'cartId'    => (string) $cart_id,
			'orderId'   => (string) $order_id,
			'completed' => (bool) $completed,
		);

		if ( ! empty( $args['completedDate'] ) ) {
			$payload['completedDate'] = $args['completedDate'];
		}

		if ( ! empty( $args['kind'] ) ) {
			$payload['kind'] = $args['kind'];
		}

		$response = \wp_remote_post( $this->get_order_api_url(), array(
			'headers' => $this->get_request_headers( $token ),
			'body'    => \wp_json_encode( $payload ),
			'timeout' => 30,
		) );

		return $this->parse_json_response( $response, 'sst_v3_carts_error', 'Failed to create order from cart: ' );
	}

	/**
	 * Get a cart by ID.
	 *
	 * @param string $cart_id Cart ID.
	 *
	 * @return array|\WP_Error Cart response on success, WP_Error on failure.
	 * @since 8.4.17
	 */
	public function get_cart( $cart_id ) {
		if ( '' === (string) $cart_id ) {
			return new \WP_Error( 'sst_v3_carts_error', 'A cart ID is required.' );
		}

		$token = $this->get_auth_token();
		if ( \is_wp_error( $token ) ) {
			return $token;
		}

		$response = \wp_remote_get( $this->get_api_url( $cart_id ), array(
			'headers' => $this->get_request_headers( $token ),
			'timeout' => 30,
		) );

		return $this->parse_json_response( $response, 'sst_v3_carts_error', 'Failed to retrieve cart: ' );
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
		if ( ! is_array( $args ) || empty( $args['items'] ) || ! is_array( $args['items'] ) ) {
			return new \WP_Error( 'sst_v3_carts_invalid_request', 'At least one cart is required.' );
		}

		$items = array();

		foreach ( $args['items'] as $cart ) {
			if ( ! is_array( $cart ) ) {
				return new \WP_Error( 'sst_v3_carts_invalid_request', 'Each cart must be an array.' );
			}

			foreach ( array( 'customerId', 'destination', 'lineItems', 'origin' ) as $required_field ) {
				if ( ! isset( $cart[ $required_field ] ) || '' === $cart[ $required_field ] || array() === $cart[ $required_field ] ) {
					return new \WP_Error( 'sst_v3_carts_invalid_request', sprintf( 'Cart field %s is required.', $required_field ) );
				}
			}

			if ( ! is_array( $cart['lineItems'] ) ) {
				return new \WP_Error( 'sst_v3_carts_invalid_request', 'Cart lineItems must be an array.' );
			}

			$prepared_cart = array(
				'customerId'        => (string) $cart['customerId'],
				'deliveredBySeller' => isset( $cart['deliveredBySeller'] ) ? $cart['deliveredBySeller'] : false,
			);

			if ( isset( $cart['cartId'] ) && '' !== (string) $cart['cartId'] ) {
				$prepared_cart['cartId'] = (string) $cart['cartId'];
			}

			// Currency
			$currency_code = isset( $cart['currency']['currencyCode'] ) ? $cart['currency']['currencyCode'] : ( isset( $cart['currencyCode'] ) ? $cart['currencyCode'] : 'USD' );
			$prepared_cart['currency'] = new Currency( $currency_code );

			// Destination
			$prepared_cart['destination'] = new Address( $cart['destination'] );

			// Origin
			$prepared_cart['origin'] = new Address( $cart['origin'] );

			// Line Items
			$prepared_cart['lineItems'] = array();
			foreach ( $cart['lineItems'] as $item ) {
				foreach ( array( 'index', 'itemId', 'price', 'quantity' ) as $required_field ) {
					if ( ! is_array( $item ) || ! array_key_exists( $required_field, $item ) ) {
						return new \WP_Error( 'sst_v3_carts_invalid_request', sprintf( 'Cart line item field %s is required.', $required_field ) );
					}
				}

				$prepared_cart['lineItems'][] = new CartItem( $item );
			}

			// Exemption
			if ( isset( $cart['exemption'] ) ) {
				$prepared_cart['exemption'] = new Exemption( $cart['exemption'] );
			}

			if ( isset( $cart['discounts'] ) && is_array( $cart['discounts'] ) ) {
				$prepared_cart['discounts'] = $cart['discounts'];
			}

			$items[] = (object) $prepared_cart;
		}

		$payload = array( 'items' => $items );

		if ( ! empty( $args['transactionDate'] ) ) {
			$payload['transactionDate'] = $args['transactionDate'];
		}

		return $payload;
	}
}
