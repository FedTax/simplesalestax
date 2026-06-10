<?php
namespace TaxCloud_V3;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared TaxCloud v3 HTTP client with cURL telemetry.
 *
 * @since 8.4.10
 */
class HttpClient {

	const DEFAULT_TIMEOUT         = 30;
	const DEFAULT_CONNECT_TIMEOUT = 5;
	const BACKOFF_MIN_US          = 250000;
	const BACKOFF_MAX_US          = 500000;

	/**
	 * cURL errors that are usually caused by transient DNS, TCP, TLS, or socket failures.
	 *
	 * @var int[]
	 */
	protected static $transient_curl_errnos = array( 6, 7, 28, 35, 55, 56 );

	/**
	 * Send a GET request.
	 *
	 * @param string $url  Request URL.
	 * @param array  $args Request arguments.
	 * @return array|\WP_Error
	 */
	public static function get( $url, $args = array() ) {
		return self::request( 'GET', $url, $args );
	}

	/**
	 * Send a POST request.
	 *
	 * @param string $url  Request URL.
	 * @param array  $args Request arguments.
	 * @return array|\WP_Error
	 */
	public static function post( $url, $args = array() ) {
		return self::request( 'POST', $url, $args );
	}

	/**
	 * Send an HTTP request.
	 *
	 * Supported custom args:
	 * - sst_retry: bool, whether to retry once on transient transport failures.
	 * - connect_timeout: int, cURL connect timeout in seconds.
	 *
	 * @param string $method HTTP method.
	 * @param string $url    Request URL.
	 * @param array  $args   Request arguments.
	 * @return array|\WP_Error
	 */
	public static function request( $method, $url, $args = array() ) {
		$retry = isset( $args['sst_retry'] ) ? (bool) $args['sst_retry'] : true;
		unset( $args['sst_retry'] );

		if ( function_exists( 'curl_init' ) ) {
			return self::curl_request( $method, $url, $args, $retry );
		}

		return self::wp_remote_request( $method, $url, $args, $retry );
	}

	/**
	 * Send a cURL-backed request.
	 *
	 * @param string $method HTTP method.
	 * @param string $url    Request URL.
	 * @param array  $args   Request arguments.
	 * @param bool   $retry  Whether retry is allowed.
	 * @return array|\WP_Error
	 */
	protected static function curl_request( $method, $url, $args, $retry ) {
		$ch = curl_init( $url );

		if ( ! self::is_curl_handle( $ch ) ) {
			return new \WP_Error( 'sst_v3_http_error', 'Unable to initialize cURL for TaxCloud v3 request.' );
		}

		$timeout         = self::get_timeout( $args, 'timeout', self::DEFAULT_TIMEOUT );
		$connect_timeout = self::get_timeout( $args, 'connect_timeout', self::DEFAULT_CONNECT_TIMEOUT );
		$headers         = self::normalize_headers( isset( $args['headers'] ) ? $args['headers'] : array() );
		$body            = isset( $args['body'] ) ? $args['body'] : null;
		$method          = strtoupper( $method );

		$options = array(
			CURLOPT_URL            => $url,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => $timeout,
			CURLOPT_CONNECTTIMEOUT => $connect_timeout,
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
		);

		$cainfo = dirname( __DIR__ ) . '/vendor/fedtax/php-taxcloud/cacert.pem';
		if ( is_readable( $cainfo ) ) {
			$options[ CURLOPT_CAINFO ] = $cainfo;
		}

		if ( null !== $body && 'GET' !== $method ) {
			$options[ CURLOPT_POSTFIELDS ] = $body;
		}

		if ( 'POST' === $method ) {
			$options[ CURLOPT_POST ] = true;
		}

		curl_setopt_array( $ch, $options );

		$max_attempts = $retry ? 2 : 1;

		for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
			$result = curl_exec( $ch );

			if ( false !== $result ) {
				$info = self::get_curl_telemetry( $ch, 0, '' );

				if ( $attempt > 1 ) {
					self::log( 'TaxCloud v3 request succeeded after retry.', array(
						'method'        => $method,
						'url'           => $url,
						'attempts'      => $attempt,
						'retry_outcome' => 'success',
					) );
				}

				curl_close( $ch );

				return array(
					'headers'       => array(),
					'body'          => $result,
					'response'      => array(
						'code'    => isset( $info['http_code'] ) ? (int) $info['http_code'] : 0,
						'message' => '',
					),
					'cookies'       => array(),
					'filename'      => null,
					'sst_curl_info' => $info,
				);
			}

			$errno         = curl_errno( $ch );
			$error         = curl_error( $ch );
			$telemetry     = self::get_curl_telemetry( $ch, $errno, $error );
			$will_retry    = $attempt < $max_attempts && self::is_transient_curl_error( $errno );
			$retry_outcome = $will_retry ? 'retrying' : ( $attempt > 1 ? 'fail-after-retry' : 'not-retried' );

			self::log( 'TaxCloud v3 request cURL failure.', array_merge( $telemetry, array(
				'method'           => $method,
				'url'              => $url,
				'attempt'          => $attempt,
				'attempts_allowed' => $max_attempts,
				'retry_outcome'    => $retry_outcome,
			) ) );

			if ( ! $will_retry ) {
				curl_close( $ch );

				return new \WP_Error(
					'sst_v3_http_error',
					self::format_error_message( $method, $url, $attempt, $retry_outcome, $telemetry ),
					array( 'curl_info' => $telemetry )
				);
			}

			usleep( mt_rand( self::BACKOFF_MIN_US, self::BACKOFF_MAX_US ) );
		}

		curl_close( $ch );

		return new \WP_Error( 'sst_v3_http_error', 'TaxCloud v3 request failed before receiving a response.' );
	}

	/**
	 * Fall back to the WordPress HTTP API when the cURL extension is unavailable.
	 *
	 * @param string $method HTTP method.
	 * @param string $url    Request URL.
	 * @param array  $args   Request arguments.
	 * @param bool   $retry  Whether retry is allowed.
	 * @return array|\WP_Error
	 */
	protected static function wp_remote_request( $method, $url, $args, $retry ) {
		if ( ! function_exists( 'wp_remote_request' ) ) {
			return new \WP_Error( 'sst_v3_http_error', 'PHP cURL extension is not enabled and no fallback HTTP transport is available.' );
		}

		$args['method']  = strtoupper( $method );
		$args['timeout'] = self::get_timeout( $args, 'timeout', self::DEFAULT_TIMEOUT );
		unset( $args['connect_timeout'] );

		$max_attempts = $retry ? 2 : 1;

		for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
			$response = wp_remote_request( $url, $args );

			if ( ! is_wp_error( $response ) ) {
				return $response;
			}

			$retry_outcome = $attempt < $max_attempts ? 'retrying' : ( $attempt > 1 ? 'fail-after-retry' : 'not-retried' );

			self::log( 'TaxCloud v3 request failed via WordPress HTTP API.', array(
				'method'        => $method,
				'url'           => $url,
				'attempt'       => $attempt,
				'retry_outcome' => $retry_outcome,
				'error'         => $response->get_error_message(),
			) );

			if ( $attempt >= $max_attempts ) {
				return $response;
			}

			usleep( mt_rand( self::BACKOFF_MIN_US, self::BACKOFF_MAX_US ) );
		}

		return new \WP_Error( 'sst_v3_http_error', 'TaxCloud v3 request failed before receiving a response.' );
	}

	/**
	 * Normalize request headers for cURL.
	 *
	 * @param array $headers Headers.
	 * @return array
	 */
	protected static function normalize_headers( $headers ) {
		$normalized = array();
		$has_user_agent = false;

		foreach ( (array) $headers as $key => $value ) {
			if ( is_int( $key ) ) {
				if ( stripos( $value, 'User-Agent:' ) === 0 ) {
					$has_user_agent = true;
				}
				$normalized[] = $value;
				continue;
			}

			if ( 0 === strcasecmp( $key, 'User-Agent' ) ) {
				$has_user_agent = true;
			}

			$normalized[] = $key . ': ' . $value;
		}

		if ( ! $has_user_agent && function_exists( 'sst_get_user_agent' ) ) {
			$normalized[] = 'User-Agent: ' . sst_get_user_agent();
		}

		return $normalized;
	}

	/**
	 * Get a positive timeout from args or environment.
	 *
	 * @param array  $args    Request arguments.
	 * @param string $key     Argument key.
	 * @param int    $default Default timeout.
	 * @return int
	 */
	protected static function get_timeout( $args, $key, $default ) {
		$env_name = 'connect_timeout' === $key ? 'PHP_TAXCLOUD_CONNECT_TIMEOUT' : 'PHP_TAXCLOUD_REQUEST_TIMEOUT';
		$value    = isset( $args[ $key ] ) ? $args[ $key ] : getenv( $env_name );

		if ( false === $value || '' === $value || ! is_numeric( $value ) || (int) $value <= 0 ) {
			return $default;
		}

		return (int) $value;
	}

	/**
	 * Check whether a value is a cURL handle across PHP versions.
	 *
	 * @param mixed $handle Handle candidate.
	 * @return bool
	 */
	protected static function is_curl_handle( $handle ) {
		return is_resource( $handle ) || ( class_exists( '\CurlHandle' ) && $handle instanceof \CurlHandle );
	}

	/**
	 * Whether a cURL errno represents a transient transport failure.
	 *
	 * @param int $errno cURL errno.
	 * @return bool
	 */
	protected static function is_transient_curl_error( $errno ) {
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
	protected static function get_curl_telemetry( $ch, $errno, $error ) {
		$info = curl_getinfo( $ch );
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
	 * Format the transport failure with telemetry for caller logs.
	 *
	 * @param string $method        HTTP method.
	 * @param string $url           Request URL.
	 * @param int    $attempt       Attempt number.
	 * @param string $retry_outcome Retry result.
	 * @param array  $telemetry     cURL telemetry.
	 * @return string
	 */
	protected static function format_error_message( $method, $url, $attempt, $retry_outcome, $telemetry ) {
		$error = empty( $telemetry['error'] ) ? 'Unknown cURL error' : $telemetry['error'];

		return sprintf(
			'TaxCloud v3 request failed: cURL error %d: %s. method=%s; url=%s; attempts=%d; retry_outcome=%s; curl_info=%s',
			isset( $telemetry['errno'] ) ? (int) $telemetry['errno'] : 0,
			$error,
			$method,
			$url,
			$attempt,
			$retry_outcome,
			self::format_telemetry( $telemetry )
		);
	}

	/**
	 * Convert cURL telemetry into a compact key/value string.
	 *
	 * @param array $telemetry cURL telemetry.
	 * @return string
	 */
	protected static function format_telemetry( $telemetry ) {
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

	/**
	 * Log context when the WooCommerce logger is available.
	 *
	 * @param string $message Log message.
	 * @param array  $context Log context.
	 */
	protected static function log( $message, $context ) {
		if ( class_exists( '\SST_Logger' ) && method_exists( '\SST_Logger', 'debug' ) ) {
			\SST_Logger::debug( $message, $context );
		}
	}
}
