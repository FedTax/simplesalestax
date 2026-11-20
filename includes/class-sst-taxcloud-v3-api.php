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
class SST_TaxCloud_V3_API {

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

		if ( ! empty( $data['connection_id'] ) ) {
			SST_Settings::set( 'tc_integration_id', $data['connection_id'] );
			SST_Settings::set( 'tc_connection_id', $data['connection_id'] );
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

		if ( $code < 200 || $code >= 300 ) {
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
	 * Resolve the connection and key submitted from the WooCommerce settings form.
	 */
	public static function resolve_v3_credentials( $connection_id, $api_key ) {
		if ( ! is_string( $connection_id ) || ! is_string( $api_key ) ) {
			return new WP_Error( 'sst_v3_credentials', __( 'Enter a valid Connection ID and API Key.', 'simple-sales-tax' ) );
		}

		$connection_id = trim( wp_unslash( $connection_id ) );
		$api_key       = trim( wp_unslash( $api_key ) );
		$saved_id      = (string) SST_Settings::get( 'tc_key' );
		$api_key       = '' === $api_key ? (string) SST_Settings::get( 'tc_v3_api_key' ) : $api_key;

		if ( ! preg_match( '/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/i', $connection_id ) ) {
			return new WP_Error( 'sst_v3_connection_id', __( 'Enter the Connection ID shown on your WooCommerce connection in TaxCloud.', 'simple-sales-tax' ) );
		}
		if ( '' !== $saved_id && 0 !== strcasecmp( $saved_id, $connection_id ) ) {
			return new WP_Error( 'sst_v3_connection_id', __( 'Use the same Connection ID as your current TaxCloud connection so existing orders can still be captured or refunded.', 'simple-sales-tax' ) );
		}
		if ( ! preg_match( '/\A[\x21-\x7e]+\z/', $api_key ) ) {
			return new WP_Error( 'sst_v3_api_key', __( 'Enter an API Key from Developer > API in TaxCloud.', 'simple-sales-tax' ) );
		}

		return array(
			'connection_id' => '' === $saved_id ? $connection_id : $saved_id,
			'api_key'       => $api_key,
		);
	}

	/**
	 * Read and validate the mode and origins without changing WooCommerce settings.
	 */
	public static function get_v3_setup( $connection_id, $api_key, $verify = true ) {
		$client = new TaxCloud_V3\Utilities( $connection_id, $api_key );
		if ( $verify ) {
			$ping = $client->ping();
			if ( is_wp_error( $ping ) ) {
				return $ping;
			}
		}

		$settings = $client->get_setup_resource( '/mgmt/connections/' . rawurlencode( $connection_id ) );
		if ( is_wp_error( $settings ) ) {
			return new WP_Error(
				'sst_v3_integration_mode',
				sprintf( __( 'Could not read the WooCommerce integration mode from TaxCloud. %s', 'simple-sales-tax' ), $settings->get_error_message() )
			);
		}
		$data_mover = $settings['options']['data_mover']['flag'] ?? null;
		if ( ! is_bool( $data_mover ) ) {
			return new WP_Error( 'sst_v3_integration_mode', __( 'TaxCloud did not return a supported WooCommerce integration mode.', 'simple-sales-tax' ) );
		}

		// Register a provider only after TaxCloud confirms both WooCommerce setup contracts.
		// It receives the connection ID and a same-host GET callable, and returns SST_Origin_Address objects.
		$provider = apply_filters( 'sst_v3_origin_provider', null );
		if ( ! is_callable( $provider ) ) {
			return new WP_Error( 'sst_v3_origin_unavailable', __( 'TaxCloud origin sync is not available for V3 setup yet. Your current connection settings were kept.', 'simple-sales-tax' ) );
		}

		$locations = $provider( $connection_id, array( $client, 'get_setup_resource' ) );
		if ( ! is_array( $locations ) ) {
			return new WP_Error( 'sst_v3_origin_error', __( 'Could not sync shipping origins from TaxCloud. Try again or check the locations in TaxCloud.', 'simple-sales-tax' ) );
		}

		$origins = array();
		foreach ( $locations as $location ) {
			if ( ! $location instanceof SST_Origin_Address || '' === (string) $location->getID()
				|| '' === (string) $location->getAddress1() || ! SST_Addresses::is_valid( $location )
				|| isset( $origins[ $location->getID() ] )
			) {
				return new WP_Error( 'sst_v3_origin_error', __( 'TaxCloud returned an invalid shipping origin.', 'simple-sales-tax' ) );
			}
			$origins[ $location->getID() ] = $location;
		}
		if ( empty( $origins ) ) {
			return new WP_Error( 'sst_v3_origin_error', __( 'Add a shipping origin in TaxCloud, then verify your settings again.', 'simple-sales-tax' ) );
		}

		return array( 'data_mover' => $data_mover, 'origins' => $origins );
	}

	/**
	 * Keep valid default selections. A sole available origin can be selected automatically.
	 */
	public static function select_v3_origins( $origins, $selected ) {
		$selected = array_values( array_intersect( array_map( 'strval', array_keys( $origins ) ), array_filter( (array) $selected, 'is_scalar' ) ) );
		if ( empty( $selected ) && 1 === count( $origins ) ) {
			$selected = array( (string) array_key_first( $origins ) );
		}
		if ( empty( $selected ) ) {
			return new WP_Error( 'sst_v3_origin_required', __( 'Verify your settings and select at least one TaxCloud shipping origin.', 'simple-sales-tax' ) );
		}

		foreach ( $origins as $origin ) {
			$origin->setDefault( in_array( (string) $origin->getID(), $selected, true ) );
		}

		return array(
			'default_origin_addresses' => $selected,
			'addresses'                => array_map( 'wp_json_encode', $origins ),
		);
	}

	public static function schedule_settings_refresh() {
		if ( ! wp_next_scheduled( 'sst_update_data_mover_settings' ) ) {
			wp_schedule_event( time(), 'daily', 'sst_update_data_mover_settings' );
		}
	}

	/**
	 * Refresh the saved connection settings. Explicit legacy credentials keep the V1 verification flow.
	 *
	 * @param string $api_login_id TaxCloud API Login ID.
	 * @param string $api_key      TaxCloud API Key.
	 * @return true|WP_Error|null True on success, WP_Error on failure, null if unconfigured.
	 */
	public static function update_data_mover_settings( $api_login_id = null, $api_key = null ) {
		if ( null === $api_login_id && null === $api_key && SST_Settings::uses_v3_api_key() ) {
			self::schedule_settings_refresh();
			$connection = SST_Settings::get( 'tc_key' );
			$key        = SST_Settings::get( 'tc_v3_api_key' );
			$selected   = SST_Settings::get( 'default_origin_addresses' );
			$setup      = self::get_v3_setup( $connection, $key, false );
			$origins    = is_wp_error( $setup ) ? $setup : self::select_v3_origins( $setup['origins'], $selected );
			if ( is_wp_error( $origins ) ) {
				SST_Logger::add( $origins->get_error_message() );
				return $origins;
			}

			SST_Settings::load_settings();
			if ( ! SST_Settings::uses_v3_api_key() || $connection !== SST_Settings::get( 'tc_key' )
				|| $key !== SST_Settings::get( 'tc_v3_api_key' ) || $selected !== SST_Settings::get( 'default_origin_addresses' )
			) {
				return new WP_Error( 'sst_v3_settings_changed', __( 'TaxCloud settings changed during the refresh. Try again.', 'simple-sales-tax' ) );
			}

			SST_Settings::set_many( $origins + array( 'data_mover' => $setup['data_mover'] ) );
			return true;
		}

		if ( !$api_login_id || !$api_key ) {
			$api_login_id	= SST_Settings::get( 'tc_id' );
			$api_key			= SST_Settings::get( 'tc_key' );
		}

		// Return if empty
		if ( empty( $api_login_id ) || empty( $api_key ) ) {
			SST_Logger::add( 'Failed to update data mover settings: API Login ID or API Key is empty' );
			return;
		}

		self::schedule_settings_refresh();

		// Check v3 settings
		$v3_settings = self::get_settings_with_v1_creds( $api_login_id, $api_key );

		if ( is_array( $v3_settings ) ) {
			$data_mover = (bool) isset( $v3_settings['options']['data_mover']['flag'] ) && $v3_settings['options']['data_mover']['flag'];
			SST_Settings::set( 'data_mover', $data_mover );
			return true;
		}
		if ( ! is_wp_error( $v3_settings ) ) {
			$v3_settings = new WP_Error( 'sst_v3_settings_error', __( 'TaxCloud returned invalid connection settings.', 'simple-sales-tax' ) );
		}
		SST_Logger::add( 'Failed to fetch v3 settings: ' . $v3_settings->get_error_message() );
		return $v3_settings;
	}
}
