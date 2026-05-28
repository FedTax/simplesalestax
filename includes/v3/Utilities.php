<?php
namespace TaxCloud_V3;

use SST_Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TaxCloud v3 Utilities Class.
 *
 * @author  Simple Sales Tax
 * @package SST
 * @since   8.4.9
 */
class Utilities extends RequestBase {

	/**
	 * Initialize the class.
	 *
	 * @since 8.4.9
	 */
	public function __construct() {
		$this->connection_id = SST_Settings::get( 'tc_key' );
	}

	/**
	 * Search TICs via TaxCloud v3 API.
	 *
	 * @param string $query  Search query.
	 * @param int    $limit  Maximum number of results (1-100).
	 * @param string $cursor Pagination cursor.
	 *
	 * @return array|\WP_Error Response on success, \WP_Error on failure.
	 * @since 8.4.9
	 */
	public function search_tics( $query, $limit = 20, $cursor = '' ) {
		$token = $this->get_auth_token();
		if ( \is_wp_error( $token ) ) {
			return $token;
		}

		$base_url = ( defined( 'SST_TAXCLOUD_STAGING' ) && \SST_TAXCLOUD_STAGING ) 
			? 'https://api.v3.taxcloud.net' 
			: 'https://api.v3.taxcloud.com';

		$url = $base_url . '/tax/tic/search';

		$payload = array(
			'query' => $query,
			'limit' => (int) $limit,
		);

		if ( ! empty( $cursor ) ) {
			$payload['cursor'] = $cursor;
		}

		$response = \wp_remote_post( $url, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => \wp_json_encode( $payload ),
			'timeout' => 30,
		) );

		if ( \is_wp_error( $response ) ) {
			return $response;
		}

		$code = \wp_remote_retrieve_response_code( $response );
		$body = \wp_remote_retrieve_body( $response );

		if ( $code >= 400 ) {
			$data = \json_decode( $body, true );
			$error_msg = $data['detail'] ?? $data['message'] ?? $body;
			return new \WP_Error( 'sst_v3_tic_search_error', 'TaxCloud API Error: ' . $error_msg );
		}

		return \json_decode( $body, true );
	}
}
