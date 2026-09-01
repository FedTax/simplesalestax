<?php
namespace TaxCloud_V3;

use SST_Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TaxCloud v3 API Request Base Class.
 *
 * Handles authentication and settings retrieval from TaxCloud v3 API.
 *
 * @author  Simple Sales Tax
 * @package SST
 * @since   8.4.1
 */
abstract class RequestBase {

	/**
	 * The base of this controller's route.
	 *
	 * @var string
	 * @since 8.4.1
	 */
	protected $rest_base;

	/**
	 * The connection ID.
	 *
	 * @var string
	 * @since 8.4.1
	 */
	protected $connection_id;

	/**
	 * API Base URLs.
	 */
	const STAGING_AUTH_URL = 'https://staging-taxcloudapi.azurewebsites.net/api/v3/auth/token';
	const PROD_AUTH_URL    = 'https://taxcloudapi-appservice-core-prod.azurewebsites.net/api/v3/auth/token';
	const STAGING_MGMT_URL = 'https://api.v3.taxcloud.net/mgmt';
	const PROD_MGMT_URL    = 'https://api.v3.taxcloud.com/mgmt';
	const API_BASE_URL		 = 'https://api.v3.taxcloud.com/';

	/**
	 * Get the appropriate Auth URL based on environment.
	 *
	 * @return string Auth URL.
	 * @since 8.4.1
	 */
	private static function get_auth_url() {
		// For now, we'll default to PROD unless a constant is defined for staging.
		// In the future, this could be a setting.
		if ( defined( 'SST_TAXCLOUD_STAGING' ) && SST_TAXCLOUD_STAGING ) {
			return self::STAGING_AUTH_URL;
		}
		return self::PROD_AUTH_URL;
	}

	/**
	 * Get the appropriate Management URL based on environment.
	 *
	 * @return string Management URL.
	 * @since 8.4.1
	 */
	private static function get_mgmt_url() {
		if ( defined( 'SST_TAXCLOUD_STAGING' ) && SST_TAXCLOUD_STAGING ) {
			return self::STAGING_MGMT_URL;
		}
		return self::PROD_MGMT_URL;
	}

	/**
	 * Exchange v1 credentials for v3 Bearer token.
	 *
	 * @return string|\WP_Error Access token on success, WP_Error on failure.
	 * @since 8.4.1
	 */
	public function get_auth_token( $api_login_id = null, $api_key = null ) {
		if ( ! $api_login_id || ! $api_key ) {
			$api_login_id = SST_Settings::get( 'tc_id' );
			$api_key      = SST_Settings::get( 'tc_key' );
		}

		if ( empty( $api_login_id ) || empty( $api_key ) ) {
			return new \WP_Error( 'sst_v3_auth_error', 'Missing TaxCloud API credentials.' );
		}

		$transient_key = 'sst_tc_v3_token_' . md5( $api_login_id . ':' . $api_key );
		$cached_token  = get_transient( $transient_key );

		if ( ! empty( $cached_token ) && is_string( $cached_token ) ) {
			return $cached_token;
		}

		$response = \wp_remote_post( self::get_auth_url(), array(
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'body'    => \json_encode( array(
				'apiLoginID' => $api_login_id,
				'apiKey'     => $api_key,
			) ),
			'timeout' => 30,
		) );

		if ( \is_wp_error( $response ) ) {
			return $response;
		}

		$code = \wp_remote_retrieve_response_code( $response );
		$body = \wp_remote_retrieve_body( $response );
		$data = \json_decode( $body, true );

		if ( $code >= 400 ) {
			return new \WP_Error( 'sst_v3_auth_error', 'Failed to authenticate with TaxCloud v3 API: ' . ( isset( $data['message'] ) ? $data['message'] : $body ) );
		}

		if ( empty( $data['access_token'] ) ) {
			return new \WP_Error( 'sst_v3_auth_error', 'No access token received from TaxCloud v3 API.' );
		}

		if ( ! empty( $data['connection_id'] ) && strlen( $data['connection_id'] ) > 10 ) {
			SST_Settings::set( 'tc_integration_id', $data['connection_id'] ); // Set integration id which returns from taxcloud
		}

		// Cache token for 12 hours (tokens are valid for 24 hours).
		set_transient( $transient_key, $data['access_token'], 12 * HOUR_IN_SECONDS );

		return $data['access_token'];
	}

	/**
	 * Get connection settings using Bearer token.
	 *
	 * @param string $access_token Bearer token.
	 *
	 * @return array|\WP_Error Settings array on success, \WP_Error on failure.
	 * @since 8.4.1
	 */
	public static function get_connection_settings( $api_key, $access_token ) {
		$url = self::get_mgmt_url() . '/connections/' . $api_key;

		$response = \wp_remote_get( $url, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $access_token,
				'Content-Type'  => 'application/json',
			),
			'timeout' => 30,
		) );

		if ( \is_wp_error( $response ) ) {
			return $response;
		}

		$code = \wp_remote_retrieve_response_code( $response );
		$body = \wp_remote_retrieve_body( $response );

		if ( $code === 404 ) {
			// Connection settings don't exist yet, which is normal for new connections.
			// Return empty settings.
			return array();
		}

		if ( $code >= 400 ) {
			return new \WP_Error( 'sst_v3_settings_error', 'Failed to retrieve connection settings: ' . $body );
		}

		return json_decode( $body, true );
	}

	/**
	 * Prepares the item for the API request.
	 *
	 * @param array $args Request arguments.
	 *
	 * @return array|\WP_Error Response object on success, or WP_Error object on failure.
	 * @since 8.4.1
	 */
	public function prepare_item_for_request( $args ) {
		return $args;
	}

}
