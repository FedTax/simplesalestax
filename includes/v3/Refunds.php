<?php
namespace TaxCloud_V3;

use SST_Settings;
use WP_Error;

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
		return rtrim( self::API_BASE_URL, '/' ) . '/tax/connections/' . $this->connection_id . '/orders/refunds/' . $order_id;
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
	
		$refund = $this->prepare_item_for_request( $args );

		if ( is_wp_error( $refund ) ) {
			return $refund;
		}

		$token = $this->get_auth_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = wp_remote_post( $this->get_api_url( $order_id ), array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => json_encode( $refund ),
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code >= 400 ) {
			return new \WP_Error( 'sst_v3_refunds_error', 'Failed to refund order: ' . $body );
		}

		return json_decode( $body, true );
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
		$refund_args = array();

		if ( isset( $args['items'] ) && ! empty( $args['items'] ) ) {
			$refund_args['items'] = array();
			foreach ( $args['items'] as $item ) {
				$refund_args['items'][] = (object) array(
					'itemId'   => (string) $item['itemId'],
					'quantity' => (float) $item['quantity'],
				);
			}
		}

		if ( isset( $args['returnedDate'] ) ) {
			$refund_args['returnedDate'] = $args['returnedDate'];
		}

		return (object) $refund_args;
	}
}
