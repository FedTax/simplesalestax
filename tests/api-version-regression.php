<?php
/** Run with: php tests/api-version-regression.php. No external requests or database writes. */

namespace TaxCloud {
	// Replace only the legacy network client; requests and plugin routing are real.
	class Client {
		public function __call( $method, $args ) {
			\Routing_Http::$v1[] = array( $method, $args[0] );
			if ( \Routing_Http::$fail ) {
				throw new \RuntimeException( 'TaxCloud rejected the request.' );
			}
			if ( 'Lookup' === $method ) { return array( 'test-cart' => array( 0 => 1.0 ) ); }
			if ( 'VerifyAddress' === $method ) { return new Address( '1 Main St', '', 'Raleigh', 'NC', '27601' ); }
			if ( in_array( $method, array( 'GetExemptCertificates', 'GetTICs' ), true ) ) { return array(); }
			if ( 'AuthorizedWithCapture' === $method || 'Returned' === $method ) { return true; }
			throw new \RuntimeException( 'Unexpected V1 operation: ' . $method );
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'WEEK_IN_SECONDS', 604800 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'SST_DEFAULT_FEE_TIC', 10010 );

	class Routing_Http {
		public static $v1 = array();
		public static $v3 = array();
		public static $fail = false;
		public static $body = array();
		public static $options = array();
		public static $transients = array();
		public static $assertions = 0;

		public static function reset( $version = null ) {
			self::$v1 = self::$v3 = self::$transients = array();
			self::$fail = false;
			self::$body = array();
			self::$options = array( 'tc_id' => 'test-login', 'tc_key' => 'test-connection', 'data_mover' => false );
			if ( null !== $version ) { self::$options['api_version'] = $version; }
			SST_Settings::load_settings();
			self::$transients['sst_tc_v3_token_' . md5( 'test-login:test-connection' )] = 'test-token';
		}

		public static function request( $method, $url, $args ) {
			self::$v3[] = array( $method, $url, $args );
			return array(
				'response' => array( 'code' => self::$fail ? 503 : 200 ),
				'body' => json_encode( self::$fail ? array( 'detail' => 'TaxCloud rejected the request.' ) : self::$body ),
			);
		}
	}

	function expect_same( $expected, $actual, $message ) {
		++Routing_Http::$assertions;
		if ( $expected !== $actual ) {
			throw new \RuntimeException( $message . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
		}
	}
	function assert_route( $version, $v1_method, $v3_path, $message ) {
		if ( 'v1' === $version ) {
			expect_same( array(), Routing_Http::$v3, $message . ': no V3 call' );
			expect_same( true, count( Routing_Http::$v1 ) > 0, $message . ': V1 called' );
			foreach ( Routing_Http::$v1 as $request ) { expect_same( $v1_method, $request[0], $message ); }
		} else {
			expect_same( array(), Routing_Http::$v1, $message . ': no V1 call' );
			expect_same( true, count( Routing_Http::$v3 ) > 0, $message . ': V3 called' );
			foreach ( Routing_Http::$v3 as $request ) {
				expect_same( $v3_path, parse_url( $request[1], PHP_URL_PATH ), $message );
			}
		}
	}

	function __( $message ) { return $message; }
	function add_action() {}
	function add_filter() {}
	function apply_filters( $hook, $value ) { return $value; }
	function did_action() { return 0; }
	function get_option( $key, $default = null ) { return 'woocommerce_wootax_settings' === $key ? Routing_Http::$options : $default; }
	function update_option( $key, $value ) { Routing_Http::$options = $value; }
	function get_transient( $key ) { return Routing_Http::$transients[ $key ] ?? false; }
	function set_transient( $key, $value, $ttl = 0 ) { Routing_Http::$transients[ $key ] = $value; }
	function get_current_user_id() { return 42; }
	function get_user_meta() { return ''; }
	function wp_json_encode( $value ) { return json_encode( $value ); }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function maybe_unserialize( $value ) { return 'a:' === substr( $value, 0, 2 ) ? unserialize( $value ) : $value; }
	function get_woocommerce_currency() { return 'USD'; }
	function SST() { return (object) array( 'version' => 'test' ); }
	function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
	function wp_remote_post( $url, $args ) { return Routing_Http::request( 'POST', $url, $args ); }
	function wp_remote_get( $url, $args ) { return Routing_Http::request( 'GET', $url, $args ); }
	function wp_remote_request( $url, $args ) { return Routing_Http::request( $args['method'], $url, $args ); }
	function wp_remote_retrieve_body( $response ) { return $response['body']; }
	function wp_remote_retrieve_response_code( $response ) { return $response['response']['code']; }

	class WP_Error {
		private $message;
		public function __construct( $code, $message ) { $this->message = $message; }
		public function get_error_message() { return $this->message; }
	}
	class WP_User {
		public $ID;
		public $user_email = '';
		public $user_login = '';
		public function __construct( $id ) { $this->ID = $id; }
	}
	class SST_Logger {
		public static function add() {}
		public static function debug() {}
		public static function order_log() {}
	}
	class SST_Rate_Limit {
		public function limit_reached() { return false; }
		public function increment_count() {}
	}
	class WC_Order {
		public $meta = array();
		public $notes = array();
		public function get_id() { return 42; }
		public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
		public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
		public function get_date_completed() { return null; }
		public function get_total_refunded() { return 0; }
		public function get_remaining_refund_amount() { return 10; }
		public function add_order_note( $message ) { $this->notes[] = $message; }
		public function save() {}
	}
	class Routing_Refund {
		public function get_id() { return 100; }
		public function get_items( $types ) { return array( new Routing_Refund_Item() ); }
	}
	class Routing_Refund_Item {
		public function get_type() { return 'shipping'; }
		public function get_method_id() { return 'flat_rate:7'; }
		public function get_total() { return -2.50; }
	}

	require dirname( __DIR__ ) . '/includes/vendor/autoload.php';
	require dirname( __DIR__ ) . '/includes/class-sst-settings.php';
	require dirname( __DIR__ ) . '/includes/sst-functions.php';
	require dirname( __DIR__ ) . '/includes/class-sst-certificates.php';
	require dirname( __DIR__ ) . '/includes/class-sst-origin-address.php';
	require dirname( __DIR__ ) . '/includes/class-sst-addresses.php';
	require dirname( __DIR__ ) . '/includes/abstracts/class-sst-abstract-cart.php';
	require dirname( __DIR__ ) . '/includes/class-sst-order.php';

	class Routing_Order extends SST_Order {
		public $errors = array();
		public function lookup( $package ) {
			if ( 'v1' === sst_get_api_version() ) { $package['request'] = $this->get_lookup_for_package( $package ); }
			return $this->do_package_lookup( $package, 0 );
		}
		protected function handle_error( $message ) { $this->errors[] = $message; }
	}

	// Use the real settings reader and writer, including sites upgraded without a saved version.
	foreach ( array( null, '', 'invalid', 'V3', 'v1', 'v3' ) as $value ) {
		Routing_Http::reset( $value );
		expect_same( 'v3' === $value ? 'v3' : 'v1', sst_get_api_version(), 'Only explicit V3 opts in' );
	}
	Routing_Http::reset();
	foreach ( array( 'v3', 'v1' ) as $value ) {
		SST_Settings::set( 'api_version', $value );
		SST_Settings::load_settings();
		expect_same( $value, sst_get_api_version(), 'Saved selection survives settings reload' );
	}

	// Saved origins may contain an empty ZIP+4. V3 rejects a trailing hyphen.
	foreach ( array( 'v1', 'v3' ) as $version ) {
		Routing_Http::reset( $version );
		foreach ( array( null, '', '2427', '0000' ) as $zip4 ) {
			$origin = new SST_Origin_Address( 'origin', true, '323 Washington Ave N', '', 'Minneapolis', 'MN', '55401', $zip4 );
			$converted = SST_Addresses::to_address( $origin );
			expect_same( '55401', $converted->getZip5(), 'Origin conversion preserves ZIP5 for ' . $version );
			if ( 'v3' === $version ) {
				expect_same( '' === (string) $zip4 ? '55401' : '55401-' . $zip4, $converted->getZip(), 'V3 origin uses a valid ZIP with optional extension' );
			} else {
				expect_same( $origin->getZip4(), $converted->getZip4(), 'V1 origin preserves ZIP4' );
			}
		}
	}

	foreach ( array( 'v1', 'v3' ) as $version ) {
		foreach ( array( false, true ) as $fail ) {
			Routing_Http::reset( $version );
			Routing_Http::$fail = $fail;
			Routing_Http::$body = array( 'items' => array( array( 'cartId' => 'test-cart', 'lineItems' => array( array( 'index' => 0, 'tax' => array( 'amount' => 1.0 ) ) ) ) ) );
			$address = new TaxCloud\Address( '1 Main St', '', 'Raleigh', 'NC', '27601' );
			$package = array( 'contents' => array(), 'fees' => array( (object) array( 'id' => 'test-fee', 'amount' => 10 ) ), 'shipping' => null, 'map' => array(), 'user' => array( 'ID' => 42 ), 'origin' => $address, 'destination' => $address, 'certificate' => null );
			$result = ( new Routing_Order( new WC_Order() ) )->lookup( $package );
			expect_same( $fail, is_wp_error( $result['response'] ), 'Lookup propagates failure' );
			assert_route( $version, 'Lookup', '/tax/connections/test-connection/carts', 'Lookup uses selected version, including failure' );

			Routing_Http::reset( $version );
			Routing_Http::$fail = $fail;
			Routing_Http::$body = array( 'line1' => '1 Main St', 'city' => 'Raleigh', 'state' => 'NC', 'zip' => '27601' );
			SST_Addresses::verify_address( $address );
			assert_route( $version, 'VerifyAddress', '/tax/verify-address', 'Address verification uses selected version, including failure' );

			Routing_Http::reset( $version );
			Routing_Http::$fail = $fail;
			Routing_Http::$body = array( 'items' => array() );
			expect_same( array(), SST_Certificates::get_certificates( 42 ), 'Empty or failed certificate lookup returns no certificates' );
			assert_route( $version, 'GetExemptCertificates', '/tax/exemption-certificates', 'Empty or failed certificates never fall back' );
		}
	}

	// Capture/refund must use the API that owns the stored cart, even after an option switch.
	// Existing unmarked packages remain V1; this is historical ownership, not failure fallback.
	foreach ( array( 'v1', 'v3' ) as $selected ) {
		foreach ( array( 'legacy', 'v1', 'v3' ) as $stored ) {
			foreach ( array( false, true ) as $fail ) {
				$owner = 'legacy' === $stored ? 'v1' : $stored;
				Routing_Http::reset( $selected );
				Routing_Http::$fail = $fail;
				$package = array( 'cart_id' => 'stored-cart', 'customer_id' => 42, 'shipping_method' => 'flat_rate:7', 'cart_items' => array( array( 'type' => 'shipping', 'id' => 'SHIPPING', 'tic' => 11010, 'price' => 10, 'qty' => 1 ) ) );
				if ( 'legacy' !== $stored ) { $package['api_version'] = $stored; }
				if ( 'v3' === $owner ) { $package['cart_items'][0]['itemId'] = 'SHIPPING'; }
				$order = new WC_Order();
				$sst = new Routing_Order( $order );
				$sst->set_packages( array( $package ) );
				expect_same( ! $fail, $sst->do_capture(), 'Capture success/failure returned' );
				assert_route( $owner, 'AuthorizedWithCapture', '/tax/connections/test-connection/carts/orders', 'Capture preserves package owner after switch' );
				expect_same( $fail ? 'pending' : 'captured', $sst->get_taxcloud_status(), 'Capture changes status only on success' );

				Routing_Http::reset( $selected );
				Routing_Http::$fail = $fail;
				$sst->update_meta( 'status', 'partially_refunded' );
				expect_same( ! $fail, $sst->do_refund( new Routing_Refund() ), 'Repeated partial refund success/failure returned' );
				assert_route( $owner, 'Returned', '/tax/connections/test-connection/orders/refunds/42_0', 'Refund preserves package owner after switch' );
				expect_same( $fail ? 1 : 0, count( $order->notes ), 'Failed refund leaves an actionable note for either API' );
			}
		}
	}

	Routing_Http::reset( 'v3' );
	SST_Settings::set( 'data_mover', true );
	$result = ( new Routing_Order( new WC_Order() ) )->lookup( array() );
	expect_same( array(), $result, 'Existing Data Import mode still skips realtime lookup' );
	expect_same( array(), Routing_Http::$v1, 'Data Import does not call V1 lookup' );
	expect_same( array(), Routing_Http::$v3, 'Data Import does not call V3 lookup' );

	echo 'API version regression checks passed (' . Routing_Http::$assertions . " assertions).\n";
}
