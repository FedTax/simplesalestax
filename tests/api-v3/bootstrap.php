<?php
/**
 * Isolated test boundary for the real TaxCloud V3 clients.
 *
 * Deliberately does not bootstrap WordPress, WooCommerce, or the V1 SDK. All
 * settings and transients live in memory; every HTTP request must be expected.
 */

if ( defined( 'ABSPATH' ) ) {
	throw new RuntimeException( 'Run these tests with PHP CLI, outside WordPress.' );
}

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

class SST_V3_Test {
	public static function same( $expected, $actual, $message = '' ) {
		if ( $expected !== $actual ) {
			throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
		}
	}

	public static function true( $condition, $message = '' ) {
		self::same( true, (bool) $condition, $message );
	}

	public static function error( $result, $code = null ) {
		self::true( $result instanceof WP_Error, 'Expected a WP_Error.' );
		if ( null !== $code ) {
			self::same( $code, $result->get_error_code(), 'Error code.' );
		}
		return $result;
	}

	public static function body( $args ) {
		self::true( isset( $args['body'] ) && is_string( $args['body'] ), 'Expected a JSON string request body.' );
		$data = json_decode( $args['body'], true );
		self::same( JSON_ERROR_NONE, json_last_error(), 'Request body must be valid JSON.' );
		return $data;
	}
}

class SST_V3_Test_Http {
	private static $expected = array();
	private static $requests = array();
	private static $transients = array();

	public static function reset() {
		self::$expected = array();
		self::$requests = array();
		self::$transients = array();
		SST_Settings::$values = array( 'tc_id' => 'test-login', 'tc_key' => 'test-connection' );
	}

	public static function api_base() {
		return SST_TAXCLOUD_STAGING ? 'https://api.v3.taxcloud.net' : 'https://api.v3.taxcloud.com';
	}

	public static function auth_url() {
		return SST_TAXCLOUD_STAGING
			? 'https://staging-taxcloudapi.azurewebsites.net/api/v3/auth/token'
			: 'https://taxcloudapi-appservice-core-prod.azurewebsites.net/api/v3/auth/token';
	}

	public static function mgmt_base() {
		return self::api_base() . '/mgmt';
	}

	public static function expect( $method, $url, $response, $inspect = null ) {
		self::$expected[] = compact( 'method', 'url', 'response', 'inspect' );
	}

	public static function request( $method, $url, $args ) {
		self::$requests[] = compact( 'method', 'url', 'args' );
		if ( empty( self::$expected ) ) {
			throw new RuntimeException( 'Unexpected HTTP request: ' . $method . ' ' . $url . '. No network request was sent.' );
		}
		$expected = array_shift( self::$expected );
		SST_V3_Test::same( $expected['method'], $method, 'HTTP method.' );
		SST_V3_Test::same( $expected['url'], $url, 'HTTP URL.' );
		if ( null !== $expected['inspect'] ) {
			call_user_func( $expected['inspect'], $args );
		}
		return $expected['response'];
	}

	public static function verify() {
		SST_V3_Test::same( 0, count( self::$expected ), 'Not all expected HTTP requests were made.' );
	}

	public static function requests() {
		return self::$requests;
	}

	public static function raw( $body, $status = 200 ) {
		return array( 'response' => array( 'code' => $status ), 'body' => $body, 'headers' => array() );
	}

	public static function json( $data, $status = 200 ) {
		return self::raw( json_encode( $data ), $status );
	}

	public static function cache_token( $token = 'test-token' ) {
		$key = 'sst_tc_v3_token_' . md5( SST_Settings::get( 'tc_id' ) . ':' . SST_Settings::get( 'tc_key' ) );
		self::set_transient( $key, $token, 12 * HOUR_IN_SECONDS );
	}

	public static function set_transient( $key, $value, $expiration ) {
		self::$transients[ $key ] = array( 'value' => $value, 'expiration' => $expiration );
		return true;
	}

	public static function get_transient( $key ) {
		return isset( self::$transients[ $key ] ) ? self::$transients[ $key ]['value'] : false;
	}

	public static function transients() {
		return self::$transients;
	}
}

class SST_V3_Test_Suite {
	private $tests = array();

	public function test( $name, $test ) {
		if ( isset( $this->tests[ $name ] ) ) {
			throw new RuntimeException( 'Duplicate test name: ' . $name );
		}
		$this->tests[ $name ] = $test;
	}

	public function run( $filter = '' ) {
		$passed = 0;
		$failed = 0;
		foreach ( $this->tests as $name => $test ) {
			if ( '' !== $filter && false === stripos( $name, $filter ) ) {
				continue;
			}
			SST_V3_Test_Http::reset();
			set_error_handler( function ( $severity, $message, $file, $line ) {
				if ( error_reporting() & $severity ) {
					throw new ErrorException( $message, 0, $severity, $file, $line );
				}
				return false;
			} );
			try {
				call_user_func( $test );
				SST_V3_Test_Http::verify();
				++$passed;
				fwrite( STDOUT, 'PASS ' . $name . PHP_EOL );
			} catch ( Throwable $error ) {
				++$failed;
				fwrite( STDERR, 'FAIL ' . $name . PHP_EOL . '  ' . $error->getMessage() . PHP_EOL . '  ' . $error->getFile() . ':' . $error->getLine() . PHP_EOL );
			} finally {
				restore_error_handler();
			}
		}
		fwrite( STDOUT, sprintf( "\n%d passed, %d failed (%s URL configuration).\n", $passed, $failed, SST_TAXCLOUD_STAGING ? 'staging' : 'production' ) );
		if ( 0 === $passed + $failed ) {
			fwrite( STDERR, "No tests matched.\n" );
			return 1;
		}
		return $failed ? 1 : 0;
	}
}

// Minimal WordPress boundary doubles, not replacements for the API clients.
class WP_Error {
	private $code;
	private $message;
	private $data;

	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code = $code;
		$this->message = $message;
		$this->data = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}

class SST_Settings {
	public static $values = array();
	public static function get( $key, $default = '' ) { return self::$values[ $key ] ?? $default; }
	public static function set( $key, $value ) { self::$values[ $key ] = $value; }
}

function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value, $flags = 0, $depth = 512 ) { return json_encode( $value, $flags, $depth ); }
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code'] ?? ''; }
function wp_remote_retrieve_body( $response ) { return $response['body'] ?? ''; }
function wp_remote_post( $url, $args = array() ) { return SST_V3_Test_Http::request( 'POST', $url, $args ); }
function wp_remote_get( $url, $args = array() ) { return SST_V3_Test_Http::request( 'GET', $url, $args ); }
function wp_remote_request( $url, $args = array() ) { return SST_V3_Test_Http::request( $args['method'] ?? 'GET', $url, $args ); }
function get_transient( $key ) { return SST_V3_Test_Http::get_transient( $key ); }
function set_transient( $key, $value, $expiration = 0 ) { return SST_V3_Test_Http::set_transient( $key, $value, $expiration ); }
function sst_get_user_agent() { return 'SimpleSalesTax/V3-contract-tests'; }

/**
 * Implement the scalar add_query_arg forms used by these clients.
 * WordPress re-encodes existing query values but expects NEW values to have
 * been encoded by the caller. Automatically encoding here would hide bugs.
 */
function add_query_arg( $key, $value, $url = null ) {
	$args = is_array( $key ) ? $key : array( $key => $value );
	$url = is_array( $key ) ? $value : $url;
	$fragment = '';
	if ( false !== strpos( $url, '#' ) ) {
		list( $url, $fragment ) = explode( '#', $url, 2 );
		$fragment = '#' . $fragment;
	}
	$parts = explode( '?', $url, 2 );
	$query = array();
	if ( isset( $parts[1] ) ) {
		parse_str( $parts[1], $query );
		$query = array_map( 'urlencode', $query );
	}
	foreach ( $args as $name => $entry ) {
		if ( false === $entry ) {
			unset( $query[ $name ] );
		} else {
			$query[ $name ] = $entry;
		}
	}
	$pairs = array();
	foreach ( $query as $name => $entry ) {
		if ( null !== $entry ) {
			if ( ! is_scalar( $entry ) ) {
				throw new RuntimeException( 'Test add_query_arg supports scalar query values only.' );
			}
			$pairs[] = $name . '=' . $entry;
		}
	}
	$encoded = preg_replace( '#=(&|$)#', '$1', implode( '&', $pairs ) );
	return $parts[0] . ( '' === $encoded ? '' : '?' . $encoded ) . $fragment;
}

spl_autoload_register( function ( $class ) {
	$prefix = 'TaxCloud_V3\\';
	if ( 0 === strpos( $class, $prefix ) ) {
		$path = dirname( __DIR__, 2 ) . '/includes/v3/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_file( $path ) ) {
			require_once $path;
		}
	}
} );
