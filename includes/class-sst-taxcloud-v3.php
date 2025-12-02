<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TaxCloud v3 API Client.
 *
 * Handles authentication and settings retrieval from TaxCloud v3 API.
 *
 * @author  Simple Sales Tax
 * @package SST
 * @since   8.4.0
 */
class SST_TaxCloud_V3 {

	/**
	 * API Base URLs.
	 */
	const STAGING_AUTH_URL = 'https://staging-taxcloudapi.azurewebsites.net/api/v3/auth/token';
	const PROD_AUTH_URL    = 'https://taxcloudapi-appservice-core-prod.azurewebsites.net/api/v3/auth/token';
	const STAGING_MGMT_URL = 'https://api.v3.taxcloud.net/mgmt';
	const PROD_MGMT_URL    = 'https://api.v3.taxcloud.com/mgmt';

	/**
	 * Get the appropriate Auth URL based on environment.
	 *
	 * @return string
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
	 * @return string
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
	 * @param string $api_login_id TaxCloud API Login ID.
	 * @param string $api_key      TaxCloud API Key.
	 * @return string|WP_Error Access token on success, WP_Error on failure.
	 */
	public static function get_auth_token( $api_login_id, $api_key ) {
		$url = self::get_auth_url();

		$response = wp_remote_post( $url, array(
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'body'    => json_encode( array(
				'apiLoginID' => $api_login_id,
				'apiKey'     => $api_key,
			) ),
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code >= 400 ) {
			return new WP_Error( 'sst_v3_auth_error', 'Failed to authenticate with TaxCloud v3 API: ' . ( isset( $data['message'] ) ? $data['message'] : $body ) );
		}

		if ( empty( $data['access_token'] ) ) {
			return new WP_Error( 'sst_v3_auth_error', 'No access token received from TaxCloud v3 API.' );
		}

		return $data['access_token'];
	}

	/**
	 * Get connection settings using Bearer token.
	 *
	 * @param string $api_key      TaxCloud API Key (used as connection ID).
	 * @param string $access_token Bearer token.
	 * @return array|WP_Error Settings array on success, WP_Error on failure.
	 */
	public static function get_connection_settings( $api_key, $access_token ) {
		$url = self::get_mgmt_url() . '/connections/' . $api_key;

		$response = wp_remote_get( $url, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $access_token,
				'Content-Type'  => 'application/json',
			),
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code === 404 ) {
			// Connection settings don't exist yet, which is normal for new connections.
			// Return empty settings.
			return array();
		}

		if ( $code >= 400 ) {
			return new WP_Error( 'sst_v3_settings_error', 'Failed to retrieve connection settings: ' . $body );
		}

		return json_decode( $body, true );
	}

	/**
	 * Get settings using v1 credentials.
	 *
	 * @param string $api_login_id TaxCloud API Login ID.
	 * @param string $api_key      TaxCloud API Key.
	 * @return array|WP_Error Settings array on success, WP_Error on failure.
	 */
	public static function get_settings_with_v1_creds( $api_login_id, $api_key ) {
		$token = self::get_auth_token( $api_login_id, $api_key );

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		return self::get_connection_settings( $api_key, $token );
	}

	/**
	 * Update data mover settings using v1 credentials.
	 *
	 * @param string $api_login_id TaxCloud API Login ID.
	 * @param string $api_key      TaxCloud API Key.
	 * @return array|WP_Error Settings array on success, WP_Error on failure.
	 */
	public static function update_data_mover_settings( $api_login_id = null, $api_key = null ) {
		if ( ! $api_login_id || ! $api_key ) {
			$api_login_id	= SST_Settings::get( 'tc_id' );
			$api_key			= SST_Settings::get( 'tc_key' );
		}

		// Return if empty
		if ( empty( $api_login_id ) || empty( $api_key ) ) {
			SST_Logger::add( 'Failed to update data mover settings: API Login ID or API Key is empty' );
			return;
		}

		// Add to cronjob to check daily
		if ( ! wp_next_scheduled( 'sst_update_data_mover_settings' ) ) {
			wp_schedule_event( time(), 'daily', 'sst_update_data_mover_settings' );
		}

		// Check v3 settings
		$v3_settings = self::get_settings_with_v1_creds( $api_login_id, $api_key );

		if ( ! is_wp_error( $v3_settings ) ) {
			$data_mover = (bool) isset( $v3_settings['options']['data_mover']['flag'] ) && $v3_settings['options']['data_mover']['flag'];
			SST_Settings::set( 'data_mover', $data_mover );
		} else {
			// Log error but don't fail verification if v3 fails (optional, depending on strictness)
			SST_Logger::add( 'Failed to fetch v3 settings: ' . $v3_settings->get_error_message() );
		}
	}
}
