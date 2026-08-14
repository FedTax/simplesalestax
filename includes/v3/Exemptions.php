<?php
namespace TaxCloud_V3;

use SST_Settings;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TaxCloud v3 Exemptions Class.
 *
 * @author  Simple Sales Tax
 * @package SST
 * @since   8.4.2
 */
class Exemptions extends RequestBase {

	/**
	 * Initialize the class.
	 *
	 * @since 8.4.2
	 */
	function __construct() {
		$this->connection_id = SST_Settings::get( 'tc_key' ); 
	}

	/**
	 * Get API URL for exemptions.
	 *
	 * @return string API URL.
	 * @since 8.4.2
	 */
	public function get_api_url() {
		return self::API_BASE_URL . 'tax/connections/' . $this->connection_id . '/exemption-certificates';
	}

	/**
	 * Get API URL for fetching exemptions.
	 *
	 * @return string API URL.
	 * @since 8.4.2
	 */
	public function get_fetch_api_url() {
		return self::API_BASE_URL . 'tax/exemption-certificates';
	}

	/**
	 * Create an exemption certificate in TaxCloud v3 API.	
	 *
	 * @param array $args Request arguments.
	 *
	 * @return array|WP_Error Certificate response on success, WP_Error on failure.
	 * @since 8.4.2
	 */
	public function create_certificate( $args ) {
		if ( is_wp_error( $this->get_auth_token() ) ) {
			return $this->get_auth_token();
		}

		$response = wp_remote_post( $this->get_api_url(), array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->get_auth_token(),
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $args ),
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code >= 400 ) {
			return new WP_Error( 'sst_v3_exemptions_error', 'Failed to create certificate: ' . $body );
		}

		return json_decode( $body, true );
	}

	/**
	 * Get exemption certificates from TaxCloud v3 API.
	 *
	 * @param array $args Optional query args.
	 *
	 * @return array|WP_Error Certificates array on success, WP_Error on failure.
	 * @since 8.4.2
	 */
	public function get_certificates( $args = array() ) {
		$token = self::get_auth_token();
		
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$url = $this->get_fetch_api_url();
		if ( ! empty( $args ) ) {
			$url = add_query_arg( $args, $url );
		}

		$response = wp_remote_get( $url, array(
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
			return new \WP_Error( 'sst_v3_exemptions_error', 'Failed to retrieve certificates: ' . $body );
		}

		return json_decode( $body, true );
	}

	/**
	 * Get exemption certificates for multiple customer IDs in parallel.
	 *
	 * @param array $customer_ids Array of customer IDs.
	 *
	 * @return array Combined array of certificate items.
	 * @since 8.4.2
	 */
	public function get_certificates_for_customer_ids( array $customer_ids ) {
		$customer_ids = array_values( array_unique( array_filter( array_map( 'strval', $customer_ids ) ) ) );

		if ( empty( $customer_ids ) ) {
			return array();
		}

		$token = self::get_auth_token();
		if ( is_wp_error( $token ) ) {
			return array();
		}

		$base_url  = $this->get_fetch_api_url();
		$all_items = array();

		// Use curl_multi for parallel execution if available and multiple IDs
		if ( function_exists( 'curl_multi_init' ) && count( $customer_ids ) > 1 ) {
			$mh       = curl_multi_init();
			$channels = array();

			foreach ( $customer_ids as $cid ) {
				$url = add_query_arg( array( 'customerId' => $cid ), $base_url );
				$ch  = curl_init();

				curl_setopt_array( $ch, array(
					CURLOPT_URL            => $url,
					CURLOPT_HTTPHEADER     => array(
						'Authorization: Bearer ' . $token,
						'Content-Type: application/json',
					),
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_TIMEOUT        => 15,
				) );

				curl_multi_add_handle( $mh, $ch );
				$channels[ (string) $cid ] = $ch;
			}

			$active = null;
			do {
				$status = curl_multi_exec( $mh, $active );
				if ( $active ) {
					curl_multi_select( $mh, 0.1 );
				}
			} while ( $active && CURLM_OK === $status );

			foreach ( $channels as $cid => $ch ) {
				$body = curl_multi_getcontent( $ch );
				$code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

				if ( $code >= 200 && $code < 300 && ! empty( $body ) ) {
					$decoded = json_decode( $body, true );
					if ( isset( $decoded['items'] ) && is_array( $decoded['items'] ) ) {
						foreach ( $decoded['items'] as $item ) {
							$all_items[] = $item;
						}
					}
				}

				curl_multi_remove_handle( $mh, $ch );
				curl_close( $ch );
			}

			curl_multi_close( $mh );
			return $all_items;
		}

		// Fallback: Sequential execution
		foreach ( $customer_ids as $cid ) {
			$response = $this->get_certificates( array( 'customerId' => $cid ) );
			if ( ! is_wp_error( $response ) && isset( $response['items'] ) && is_array( $response['items'] ) ) {
				foreach ( $response['items'] as $item ) {
					$all_items[] = $item;
				}
			}
		}

		return $all_items;
	}

	/**
	 * Get exemption certificate by ID from TaxCloud v3 API.
	 *
	 * @param string $certificate_id Certificate ID.
	 *
	 * @return array|WP_Error Certificate array on success, WP_Error on failure.
	 * @since 8.4.2
	 */
	public function get_certificate( $certificate_id ) {
		$token = self::get_auth_token();
		
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$url = $this->get_fetch_api_url() . '/' . $certificate_id;

		$response = wp_remote_get( $url, array(
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
			return new WP_Error( 'sst_v3_exemptions_error', 'Failed to retrieve certificate: ' . $body );
		}

		return json_decode( $body, true );
	}

	/**
	 * Delete a certificate.
	 *
	 * @param string $certificate_id Certificate ID.
	 */
	public function delete_certificate( $certificate_id ) {
		$token = self::get_auth_token();
		
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$url = $this->get_api_url() . '/' . $certificate_id;

		$response = wp_remote_request( $url, array(
			'method'  => 'DELETE',
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
			return new WP_Error( 'sst_v3_exemptions_error', 'Failed to delete certificate: ' . $body );
		}

		return true;
	}

}
