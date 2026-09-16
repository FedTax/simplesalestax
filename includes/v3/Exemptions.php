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
		return $this->get_api_base_url() . '/tax/connections/' . rawurlencode( (string) $this->connection_id ) . '/exemption-certificates';
	}

	/**
	 * Get API URL for fetching exemptions.
	 *
	 * @return string API URL.
	 * @since 8.4.2
	 */
	public function get_fetch_api_url() {
		return $this->get_api_base_url() . '/tax/exemption-certificates';
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
		if ( ! is_array( $args ) ) {
			return new \WP_Error( 'sst_v3_exemptions_invalid_request', 'Certificate data must be an array.' );
		}

		foreach ( array( 'address', 'customerBusinessType', 'customerId', 'customerName', 'reason', 'reasonDescription', 'states' ) as $required_field ) {
			if ( ! isset( $args[ $required_field ] ) || '' === $args[ $required_field ] || array() === $args[ $required_field ] ) {
				return new \WP_Error( 'sst_v3_exemptions_invalid_request', sprintf( 'Certificate field %s is required.', $required_field ) );
			}
		}

		$token = $this->get_auth_token();
		if ( \is_wp_error( $token ) ) {
			return $token;
		}

		$response = \wp_remote_post( $this->get_api_url(), array(
			'headers' => $this->get_request_headers( $token ),
			'body'    => \wp_json_encode( $args ),
			'timeout' => 30,
		) );

		return $this->parse_json_response( $response, 'sst_v3_exemptions_error', 'Failed to create certificate: ' );
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
			'headers' => $this->get_request_headers( $token ),
			'timeout' => 30,
		) );

		return $this->parse_json_response( $response, 'sst_v3_exemptions_error', 'Failed to retrieve certificates: ' );
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

		$all_items = array();

		foreach ( $customer_ids as $cid ) {
			$cursor       = '';
			$seen_cursors = array();

			do {
				$query = array(
					'connectionId' => $this->connection_id,
					'customerId'   => $cid,
					'limit'        => 100,
				);

				if ( '' !== $cursor ) {
					$query['cursor'] = $cursor;
				}

				$response = $this->get_certificates( $query );
				if ( is_wp_error( $response ) ) {
					break;
				}

				if ( isset( $response['items'] ) && is_array( $response['items'] ) ) {
					foreach ( $response['items'] as $item ) {
						$all_items[] = $item;
					}
				}

				$next_cursor = isset( $response['nextCursor'] ) ? (string) $response['nextCursor'] : '';
				if ( '' === $next_cursor || isset( $seen_cursors[ $next_cursor ] ) ) {
					break;
				}

				$seen_cursors[ $next_cursor ] = true;
				$cursor = $next_cursor;
			} while ( true );
		}

		return $all_items;
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
		if ( '' === (string) $certificate_id ) {
			return new \WP_Error( 'sst_v3_exemptions_invalid_request', 'A certificate ID is required.' );
		}

		$token = $this->get_auth_token();
		
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$url = $this->get_api_url() . '/' . rawurlencode( (string) $certificate_id );

		$response = wp_remote_get( $url, array(
			'headers' => $this->get_request_headers( $token ),
			'timeout' => 30,
		) );

		return $this->parse_json_response( $response, 'sst_v3_exemptions_error', 'Failed to retrieve certificate: ' );
	}

	/**
	 * Delete a certificate.
	 *
	 * @param string $certificate_id Certificate ID.
	 *
	 * @return bool|\WP_Error
	 */
	public function delete_certificate( $certificate_id ) {
		if ( '' === (string) $certificate_id ) {
			return new \WP_Error( 'sst_v3_exemptions_invalid_request', 'A certificate ID is required.' );
		}

		$token = $this->get_auth_token();
		
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$url = $this->get_api_url() . '/' . rawurlencode( (string) $certificate_id );

		$response = wp_remote_request( $url, array(
			'method'  => 'DELETE',
			'headers' => $this->get_request_headers( $token ),
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error( 'sst_v3_exemptions_error', 'Failed to delete certificate: ' . $body );
		}

		return true;
	}

}
