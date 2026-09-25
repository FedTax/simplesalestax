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
	 * Get API URL for TaxCloud v3 tax utility endpoints.
	 *
	 * @param string $path Endpoint path under /tax.
	 *
	 * @return string API URL.
	 * @since 8.4.10
	 */
	public function get_api_url( $path = '' ) {
		return $this->get_api_base_url() . '/tax' . $path;
	}

	/**
	 * Verify authentication for the configured connection.
	 *
	 * @param string|null $api_login_id Optional TaxCloud API Login ID override.
	 * @param string|null $api_key      Optional TaxCloud API key override.
	 * @param string|null $connection_id Optional connection ID override. Defaults to the API key.
	 *
	 * @return array|\WP_Error Ping response on success, WP_Error on failure.
	 * @since 8.4.17
	 */
	public function ping( $api_login_id = null, $api_key = null, $connection_id = null ) {
		$token = $this->get_auth_token( $api_login_id, $api_key );
		if ( \is_wp_error( $token ) ) {
			return $token;
		}

		if ( null === $connection_id || '' === (string) $connection_id ) {
			$connection_id = ! empty( $api_key ) ? $api_key : $this->connection_id;
		}

		if ( '' === (string) $connection_id ) {
			return new \WP_Error( 'sst_v3_ping_error', 'A TaxCloud connection ID is required.' );
		}

		$url = $this->get_api_base_url() . '/tax/connections/' . rawurlencode( (string) $connection_id ) . '/ping';
		$response = \wp_remote_get( $url, array(
			'headers' => $this->get_request_headers( $token ),
			'timeout' => 30,
		) );

		return $this->parse_json_response( $response, 'sst_v3_ping_error', 'TaxCloud authentication failed: ' );
	}

	/**
	 * Verify an address via TaxCloud v3 API.
	 *
	 * @param array|\TaxCloud_V3\Model\Address|\JsonSerializable $address Address to verify.
	 *
	 * @return array|\WP_Error Verified address response on success, \WP_Error on failure.
	 * @since 8.4.10
	 */
	public function verify_address( $address ) {
		$payload = $this->prepare_address_for_request( $address );

		if ( \is_wp_error( $payload ) ) {
			return $payload;
		}

		$token = $this->get_auth_token();
		if ( \is_wp_error( $token ) ) {
			return $token;
		}

		$response = \wp_remote_post( $this->get_api_url( '/verify-address' ), array(
			'headers' => $this->get_request_headers( $token ),
			'body'    => \wp_json_encode( $payload ),
			'timeout' => 30,
		) );

		return $this->parse_json_response( $response, 'sst_v3_verify_address_error', 'Failed to verify address: ' );
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
		$query = trim( (string) $query );
		if ( '' === $query ) {
			return new \WP_Error( 'sst_v3_tic_search_invalid_request', 'A TIC search query is required.' );
		}

		$token = $this->get_auth_token();
		if ( \is_wp_error( $token ) ) {
			return $token;
		}

		$payload = array(
			'query' => $query,
			'limit' => min( 100, max( 1, (int) $limit ) ),
		);

		if ( ! empty( $cursor ) ) {
			$payload['cursor'] = $cursor;
		}

		$response = \wp_remote_post( $this->get_api_url( '/tic/search' ), array(
			'headers' => $this->get_request_headers( $token ),
			'body'    => \wp_json_encode( $payload ),
			'timeout' => 30,
		) );

		return $this->parse_json_response( $response, 'sst_v3_tic_search_error', 'TaxCloud API error: ' );
	}

	/**
	 * Prepare an address for TaxCloud v3 Verify Address API.
	 *
	 * @param array|\TaxCloud_V3\Model\Address|\JsonSerializable $address Address data.
	 *
	 * @return array|\WP_Error Prepared payload on success, \WP_Error on failure.
	 * @since 8.4.10
	 */
	protected function prepare_address_for_request( $address ) {
		if ( $address instanceof \JsonSerializable ) {
			$payload = $address->jsonSerialize();
		} elseif ( is_array( $address ) ) {
			$payload = $address;
		} else {
			return new \WP_Error( 'sst_v3_verify_address_invalid_address', 'Address must be an array or address object.' );
		}

		$payload = $this->normalize_address_payload( $payload );

		foreach ( array( 'city', 'line1', 'state', 'zip' ) as $required_field ) {
			if ( empty( $payload[ $required_field ] ) ) {
				return new \WP_Error( 'sst_v3_verify_address_invalid_address', 'Address must include line1, city, state, and zip.' );
			}
		}

		return $payload;
	}

	/**
	 * Normalize v1/WooCommerce address keys to TaxCloud v3 address keys.
	 *
	 * @param array $address Address data.
	 *
	 * @return array Normalized v3 payload.
	 * @since 8.4.10
	 */
	protected function normalize_address_payload( $address ) {
		$payload = array(
			'city'        => $this->first_non_empty( $address, array( 'city', 'City' ) ),
			'countryCode' => $this->first_non_empty( $address, array( 'countryCode', 'country', 'Country' ), 'US' ),
			'line1'       => $this->first_non_empty( $address, array( 'line1', 'address1', 'address_1', 'address', 'Address1' ) ),
			'state'       => $this->first_non_empty( $address, array( 'state', 'State' ) ),
			'zip'         => $this->first_non_empty( $address, array( 'zip', 'postcode', 'Zip', 'Zip5' ) ),
		);

		$line2 = $this->first_non_empty( $address, array( 'line2', 'address2', 'address_2', 'Address2' ) );
		if ( '' !== $line2 ) {
			$payload['line2'] = $line2;
		}

		if ( isset( $address['Zip5'] ) && ! empty( $address['Zip4'] ) ) {
			$payload['zip'] = $this->format_zip( $address['Zip5'], $address['Zip4'] );
		}

		return $payload;
	}

	/**
	 * Return the first non-empty value for a set of possible keys.
	 *
	 * @param array  $data    Data to inspect.
	 * @param array  $keys    Candidate keys.
	 * @param string $default Default value.
	 *
	 * @return string
	 * @since 8.4.10
	 */
	protected function first_non_empty( $data, $keys, $default = '' ) {
		foreach ( $keys as $key ) {
			if ( isset( $data[ $key ] ) && '' !== $data[ $key ] && null !== $data[ $key ] ) {
				return (string) $data[ $key ];
			}
		}

		return $default;
	}

	/**
	 * Format ZIP code for TaxCloud v3 requests.
	 *
	 * @param string      $zip5 ZIP5.
	 * @param string|null $zip4 ZIP4.
	 *
	 * @return string
	 * @since 8.4.10
	 */
	protected function format_zip( $zip5, $zip4 = null ) {
		$zip5 = trim( (string) $zip5, '-' );
		$zip4 = trim( (string) $zip4, '-' );

		return '' !== $zip4 ? $zip5 . '-' . $zip4 : $zip5;
	}

}
