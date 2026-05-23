<?php
namespace TaxCloud_V3;

use SST_Settings;

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
		return rtrim( self::API_BASE_URL, '/' ) . '/tax/connections/' . $this->connection_id . '/exemption-certificates';
	}

	/**
	 * Get API URL for fetching exemptions.
	 *
	 * @return string API URL.
	 * @since 8.4.2
	 */
	public function get_fetch_api_url() {
		return rtrim( self::API_BASE_URL, '/' ) . '/tax/exemption-certificates';
	}

	/**
	 * Create an exemption certificate in TaxCloud v3 API.	
	 *
	 * @param array $args Request arguments.
	 *
	 * @return array|\WP_Error Certificate response on success, \WP_Error on failure.
	 * @since 8.4.2
	 */
	public function create_certificate( $args ) {
		$token = $this->get_auth_token();
		if ( \is_wp_error( $token ) ) {
			return $token;
		}

		$response = \wp_remote_post( $this->get_api_url(), array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => \wp_json_encode( $args ),
			'timeout' => 30,
		) );

		if ( \is_wp_error( $response ) ) {
			return $response;
		}

		$code = \wp_remote_retrieve_response_code( $response );
		$body = \wp_remote_retrieve_body( $response );

		if ( $code >= 400 ) {
			return new \WP_Error( 'sst_v3_exemptions_error', 'Failed to create certificate: ' . $body );
		}

		return json_decode( $body, true );
	}

	/**
	 * Get exemption certificates from TaxCloud v3 API.
	 *
	 * @param array $args Optional query args.
	 *
	 * @return array|\WP_Error Certificates array on success, WP_Error on failure.
	 * @since 8.4.2
	 */
	public function get_certificates( $args = array() ) {
		$token = $this->get_auth_token();
		
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
	 * Get exemption certificate by ID from TaxCloud v3 API.
	 *
	 * @param string $certificate_id Certificate ID.
	 *
	 * @return array|\WP_Error Certificate array on success, WP_Error on failure.
	 * @since 8.4.2
	 */
	public function get_certificate( $certificate_id ) {
		$token = $this->get_auth_token();
		
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
			return new \WP_Error( 'sst_v3_exemptions_error', 'Failed to retrieve certificate: ' . $body );
		}

		return json_decode( $body, true );
	}

	/**
	 * Delete a certificate.
	 *
	 * @param string $certificate_id Certificate ID.
	 *
	 * @return bool|\WP_Error
	 */
	public function delete_certificate( $certificate_id ) {
		$token = $this->get_auth_token();
		
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
			return new \WP_Error( 'sst_v3_exemptions_error', 'Failed to delete certificate: ' . $body );
		}

		return true;
	}

}
