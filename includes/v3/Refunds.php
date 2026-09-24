<?php
namespace TaxCloud_V3;

use SST_Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TaxCloud v3 Refunds Class.
 *
 * @author  Simple Sales Tax
 * @package SST
 * @since   8.4.1
 */
class Refunds extends RequestBase {

	/**
	 * Initialize the class.
	 *
	 * @since 8.4.1
	 */
	function __construct() {
		$this->connection_id = SST_Settings::get( 'tc_key' );
	}

	/**
	 * Get API URL for refunds.
	 *
	 * @param string $order_id Order ID.
	 *
	 * @return string API URL.
	 * @since 8.4.1
	 */
	public function get_api_url( $order_id ) {
		return $this->get_api_base_url() . '/tax/connections/' . rawurlencode( $this->connection_id ) . '/orders/refunds/' . rawurlencode( (string) $order_id );
	}

	/**
	 * Refund an order in TaxCloud v3 API.
	 *
	 * @param string $order_id Order ID.
	 * @param array  $args     Request arguments.
	 *
	 * @return array|\WP_Error Refund response on success, WP_Error on failure.
	 * @since 8.4.1
	 */
	public function refund_order( $order_id, $args = array() ) {
		if ( '' === (string) $order_id ) {
			return new \WP_Error( 'sst_v3_refunds_invalid_request', 'An order ID is required.' );
		}

		if ( ! is_array( $args ) ) {
			return new \WP_Error( 'sst_v3_refunds_invalid_request', 'Refund data must be an array.' );
		}
	
		$refund = $this->prepare_item_for_request( $args );

		if ( is_wp_error( $refund ) ) {
			return $refund;
		}

		$token = $this->get_auth_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = wp_remote_post( $this->get_api_url( $order_id ), array(
			'headers' => $this->get_request_headers( $token ),
			'body'    => wp_json_encode( $refund ),
			'timeout' => 30,
		) );

		return $this->parse_json_response( $response, 'sst_v3_refunds_error', 'Failed to refund order: ' );
	}

	/**
	 * Prepare refund item for request.
	 *
	 * @param array $args Request arguments.
	 *
	 * @return array Prepared refund data.
	 * @since 8.4.1
	 */
	public function prepare_item_for_request( $args ) {
		if ( ! is_array( $args ) ) {
			return new \WP_Error( 'sst_v3_refunds_invalid_request', 'Refund data must be an array.' );
		}

		$refund_args = array();

		if ( array_key_exists( 'items', $args ) && ! is_array( $args['items'] ) ) {
			return new \WP_Error( 'sst_v3_refunds_invalid_request', 'Refund items must be an array.' );
		}

		if ( isset( $args['items'] ) && ! empty( $args['items'] ) ) {
			$refund_args['items'] = array();
			foreach ( $args['items'] as $item ) {
				if ( ! is_array( $item ) || ! array_key_exists( 'itemId', $item ) || ! array_key_exists( 'quantity', $item ) ) {
					return new \WP_Error( 'sst_v3_refunds_invalid_request', 'Each refund item must include itemId and quantity.' );
				}

				$refund_item = array(
					'itemId'   => (string) $item['itemId'],
					'quantity' => (float) $item['quantity'],
				);

				if ( array_key_exists( 'cartItemIndex', $item ) ) {
					$refund_item['cartItemIndex'] = (int) $item['cartItemIndex'];
				}

				$refund_args['items'][] = (object) $refund_item;
			}
		}

		foreach ( array( 'batchId', 'idempotencyKey', 'returnedDate' ) as $optional_field ) {
			if ( array_key_exists( $optional_field, $args ) ) {
				$refund_args[ $optional_field ] = $args[ $optional_field ];
			}
		}

		return (object) $refund_args;
	}
}
