<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TaxCloud\Exceptions\RequestException;

/**
 * TaxCloud v1 client wrapper with retry, connect timeout, and cURL telemetry.
 *
 * @since 8.4.10
 */
class SST_TaxCloud_Client extends TaxCloud\Client {

	/**
	 * Persistent cURL handle.
	 *
	 * @var resource|\CurlHandle|null
	 */
	protected $ch = null;

	/**
	 * cURL errors that are usually caused by transient DNS, TCP, TLS, or socket failures.
	 *
	 * @var int[]
	 */
	protected static $transient_curl_errnos = array( 6, 7, 28, 35, 55, 56 );

	/**
	 * Endpoints that are safe to retry once after a transient transport failure.
	 *
	 * @var array
	 */
	protected static $retryable_endpoints = array(
		'Lookup'                => true,
		'VerifyAddress'         => true,
		'GetLocations'          => true,
		'GetTICs'               => true,
		'GetExemptCertificates' => true,
	);

	/**
	 * Close the persistent cURL handle.
	 */
	public function __destruct() {
		if ( $this->is_curl_handle( $this->ch ) ) {
			curl_close( $this->ch );
		}
	}

	/**
	 * Send a POST request to a TaxCloud API endpoint.
	 *
	 * @param string           $endpoint Endpoint name.
	 * @param JsonSerializable $payload  Request payload.
	 * @return string
	 * @throws RequestException If request fails.
	 */
	protected function post( string $endpoint, JsonSerializable $payload ) {
		$url             = "{$this->base_uri}{$endpoint}";
		$timeout         = $this->get_timeout_from_env( 'PHP_TAXCLOUD_REQUEST_TIMEOUT', 30 );
		$connect_timeout = $this->get_timeout_from_env( 'PHP_TAXCLOUD_CONNECT_TIMEOUT', 5 );
		$body            = json_encode( $payload );

		if ( ! function_exists( 'curl_init' ) ) {
			return $this->wp_remote_post_fallback( $url, $body, $timeout );
		}

		$ch = $this->get_curl_handle( $url );

		curl_setopt_array( $ch, array(
			CURLOPT_URL            => $url,
			CURLOPT_HTTPHEADER     => $this->get_headers(),
			CURLOPT_POST           => true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => $timeout,
			CURLOPT_CONNECTTIMEOUT => $connect_timeout,
			CURLOPT_CAINFO         => dirname( __DIR__ ) . '/includes/vendor/fedtax/php-taxcloud/cacert.pem',
			CURLOPT_POSTFIELDS     => $body,
		) );

		$max_attempts = $this->is_retryable_endpoint( $endpoint ) ? 2 : 1;

		for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
			$result = curl_exec( $ch );

			if ( false !== $result ) {
				if ( $attempt > 1 ) {
					$this->log_curl_telemetry( 'TaxCloud v1 request succeeded after retry.', array(
						'endpoint'      => $endpoint,
						'attempts'      => $attempt,
						'retry_outcome' => 'success',
					) );
				}

				return $result;
			}

			$errno         = curl_errno( $ch );
			$error         = curl_error( $ch );
			$telemetry     = $this->get_curl_telemetry( $ch, $errno, $error );
			$will_retry    = $attempt < $max_attempts && $this->is_transient_curl_error( $errno );
			$retry_outcome = $will_retry ? 'retrying' : ( $attempt > 1 ? 'fail-after-retry' : 'not-retried' );

			$this->log_curl_telemetry( 'TaxCloud v1 request cURL failure.', array_merge( $telemetry, array(
				'endpoint'         => $endpoint,
				'attempt'          => $attempt,
				'attempts_allowed' => $max_attempts,
				'retry_outcome'    => $retry_outcome,
			) ) );

			if ( ! $will_retry ) {
				throw new RequestException(
					$this->format_request_exception_message( $endpoint, $attempt, $retry_outcome, $telemetry )
				);
			}

			usleep( mt_rand( 250000, 500000 ) );
		}

		throw new RequestException( 'TaxCloud request failed before receiving a response.' );
	}

	/**
	 * WordPress HTTP API fallback used only when PHP cURL is unavailable.
	 *
	 * @param string $url     Request URL.
	 * @param string $body    Request body.
	 * @param int    $timeout Request timeout.
	 * @return string
	 * @throws RequestException If request fails.
	 */
	protected function wp_remote_post_fallback( $url, $body, $timeout ) {
		if ( ! function_exists( 'wp_remote_post' ) ) {
			throw new RequestException( 'PHP cURL extension is not enabled and no fallback HTTP transport is available.' );
		}

		$response = wp_remote_post( $url, array(
			'headers' => $this->get_wp_headers(),
			'body'    => $body,
			'timeout' => $timeout,
		) );

		if ( is_wp_error( $response ) ) {
			throw new RequestException( 'TaxCloud request failed: ' . $response->get_error_message() );
		}

		return wp_remote_retrieve_body( $response );
	}

	/**
	 * Get or initialize the persistent cURL handle.
	 *
	 * @param string $url Request URL.
	 * @return resource|\CurlHandle
	 * @throws RequestException If cURL cannot initialize a handle.
	 */
	protected function get_curl_handle( $url ) {
		if ( ! $this->is_curl_handle( $this->ch ) ) {
			$this->ch = curl_init( $url );

			if ( ! $this->is_curl_handle( $this->ch ) ) {
				throw new RequestException( 'Unable to initialize cURL for TaxCloud request.' );
			}
		}

		return $this->ch;
	}

	/**
	 * Check whether a value is a cURL handle across PHP versions.
	 *
	 * @param mixed $handle Handle candidate.
	 * @return bool
	 */
	protected function is_curl_handle( $handle ) {
		return is_resource( $handle ) || ( class_exists( '\CurlHandle' ) && $handle instanceof \CurlHandle );
	}

	/**
	 * Get v1 cURL headers, preserving existing plugin filters.
	 *
	 * @return array
	 */
	protected function get_headers() {
		$headers = self::$headers;

		if ( function_exists( 'apply_filters' ) ) {
			$headers = apply_filters( 'taxcloud_api_headers', $headers );
		}

		return $headers;
	}

	/**
	 * Convert v1 cURL header strings to WordPress HTTP API headers.
	 *
	 * @return array
	 */
	protected function get_wp_headers() {
		$wp_headers = array();

		foreach ( $this->get_headers() as $header ) {
			$parts = explode( ':', $header, 2 );

			if ( 2 === count( $parts ) ) {
				$wp_headers[ trim( $parts[0] ) ] = trim( $parts[1] );
			}
		}

		return $wp_headers;
	}

	/**
	 * Read a positive timeout value from the environment.
	 *
	 * @param string $name    Environment variable name.
	 * @param int    $default Default timeout in seconds.
	 * @return int
	 */
	protected function get_timeout_from_env( $name, $default ) {
		$value = getenv( $name );

		if ( false === $value || '' === $value || ! is_numeric( $value ) || (int) $value <= 0 ) {
			return $default;
		}

		return (int) $value;
	}

	/**
	 * Whether the endpoint is safe to retry.
	 *
	 * @param string $endpoint Endpoint name.
	 * @return bool
	 */
	protected function is_retryable_endpoint( $endpoint ) {
		return isset( self::$retryable_endpoints[ $endpoint ] );
	}

	/**
	 * Whether a cURL errno represents a transient transport failure.
	 *
	 * @param int $errno cURL errno.
	 * @return bool
	 */
	protected function is_transient_curl_error( $errno ) {
		return in_array( (int) $errno, self::$transient_curl_errnos, true );
	}

	/**
	 * Capture the curl_getinfo bag needed to distinguish DNS/TCP/TLS/backend delays.
	 *
	 * @param resource|\CurlHandle $ch    cURL handle.
	 * @param int                  $errno cURL errno.
	 * @param string               $error cURL error.
	 * @return array
	 */
	protected function get_curl_telemetry( $ch, $errno, $error ) {
		$info   = curl_getinfo( $ch );
		$fields = array(
			'total_time',
			'namelookup_time',
			'connect_time',
			'appconnect_time',
			'pretransfer_time',
			'starttransfer_time',
			'http_code',
			'primary_ip',
		);

		$telemetry = array(
			'errno' => (int) $errno,
			'error' => $error,
		);

		foreach ( $fields as $field ) {
			$telemetry[ $field ] = isset( $info[ $field ] ) ? $info[ $field ] : null;
		}

		return $telemetry;
	}

	/**
	 * Log cURL telemetry when the WooCommerce logger is available.
	 *
	 * @param string $message Log message.
	 * @param array  $context Log context.
	 */
	protected function log_curl_telemetry( $message, array $context ) {
		if ( class_exists( 'SST_Logger' ) && method_exists( 'SST_Logger', 'debug' ) ) {
			SST_Logger::debug( $message, $context );
		}
	}

	/**
	 * Format cURL telemetry into the exception message so call-site logs keep the timing data.
	 *
	 * @param string $endpoint      Endpoint name.
	 * @param int    $attempt       Attempt number.
	 * @param string $retry_outcome Retry result.
	 * @param array  $telemetry     cURL telemetry.
	 * @return string
	 */
	protected function format_request_exception_message( $endpoint, $attempt, $retry_outcome, array $telemetry ) {
		$error = empty( $telemetry['error'] ) ? 'Unknown cURL error' : $telemetry['error'];

		return sprintf(
			'TaxCloud request failed: cURL error %d: %s. endpoint=%s; attempts=%d; retry_outcome=%s; curl_info=%s',
			isset( $telemetry['errno'] ) ? (int) $telemetry['errno'] : 0,
			$error,
			$endpoint,
			$attempt,
			$retry_outcome,
			$this->format_curl_telemetry( $telemetry )
		);
	}

	/**
	 * Convert cURL telemetry into a compact key/value string.
	 *
	 * @param array $telemetry cURL telemetry.
	 * @return string
	 */
	protected function format_curl_telemetry( array $telemetry ) {
		$parts = array();

		foreach ( $telemetry as $key => $value ) {
			if ( is_float( $value ) ) {
				$value = number_format( $value, 6, '.', '' );
			} elseif ( null === $value ) {
				$value = 'null';
			}

			$parts[] = $key . '=' . $value;
		}

		return implode( ', ', $parts );
	}
}
