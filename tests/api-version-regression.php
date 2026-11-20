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
			if ( 'GetTICs' === $method ) { return array( 0 => 'Uncategorized' ); }
			if ( in_array( $method, array( 'GetExemptCertificates', 'GetLocations' ), true ) ) { return array(); }
			if ( 'AddExemptCertificate' === $method ) { return 'legacy-certificate'; }
			if ( 'DeleteExemptCertificate' === $method ) { return true; }
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
	define( 'SST_SINGLE_PURCHASE_CERT_ID', 'single-purchase' );
	define( 'SST_SHIPPING_ITEM', 'SHIPPING' );
	define( 'DB_NAME', 'modeled_order_store' );

	$wpdb = new class {
		public $prefix = 'test_';
		private $locked = false;
		public function prepare( $query, $value ) { return $query; }
		public function get_var( $query ) {
			if ( false !== strpos( $query, 'GET_LOCK' ) ) {
				if ( $this->locked ) { return 0; }
				$this->locked = true;
				return 1;
			}
			$this->locked = false;
			return 1;
		}
	};

	class Routing_Http {
		public static $v1 = array();
		public static $v3 = array();
		public static $fail = false;
		public static $body = array();
		public static $options = array();
		public static $transients = array();
		public static $user_meta = array();
		public static $assertions = 0;
		public static $import = false;
		public static $remote = array();
		public static $import_fault = '';
		public static $during_upload;
		public static $capture_allowed = true;
		public static $denied_cap = '';
		public static $origins;
		public static $setup_mode = false;
		public static $setup_failure;

		public static function reset( $version = null ) {
			self::$v1 = self::$v3 = self::$transients = array();
			self::$user_meta = array();
			SST_Rate_Limit::$count = 0;
			self::$fail = false;
			self::$body = array();
			self::$import = false;
			self::$remote = array();
			self::$import_fault = '';
			self::$during_upload = null;
			self::$capture_allowed = true;
			self::$denied_cap = '';
			self::$origins = null;
			self::$setup_mode = false;
			self::$setup_failure = null;
			WC_Order::$persisted_meta = array();
			WC_Order::$fees = 1;
			WC_Order::$status = 'completed';
			WC_Order::$refunds = array();
			WC_Order::$date_completed = null;
			WC_Order::$city = 'Raleigh';
			WC_Order::$fee_price = 5;
			Routing_Order::$package_builds = 0;
			self::$options = array( 'tc_id' => 'test-login', 'tc_key' => 'test-connection', 'data_mover' => false );
			if ( null !== $version ) { self::$options['api_version'] = $version; }
			SST_Settings::load_settings();
			self::$transients['sst_tc_v3_token_' . md5( 'test-login:test-connection' )] = 'test-token';
		}

		public static function request( $method, $url, $args ) {
			self::$v3[] = array( $method, $url, $args );
			$path = parse_url( $url, PHP_URL_PATH );
			if ( isset( $args['headers']['X-API-KEY'] ) ) {
				if ( preg_match( '#^/mgmt/connections/[^/]+$#', $path ) ) {
					return array(
						'response' => array( 'code' => self::$setup_failure ?? 200 ),
						'body' => json_encode( array( 'options' => array( 'data_mover' => array( 'flag' => self::$setup_mode ) ) ) ),
					);
				}
				if ( '/ping' === substr( $path, -5 ) && 'rotated-bad-key' === $args['headers']['X-API-KEY'] ) {
					return array( 'response' => array( 'code' => 401 ), 'body' => '{"detail":"Rejected rotated-bad-key"}' );
				}
			}
			if ( self::$import && preg_match( '#/orders(?:/([^/]+))?$#', $path, $match ) ) {
				$status = 200;
				if ( 'GET' === $method ) {
					$body = self::$remote[ $match[1] ] ?? array();
					$status = $body ? 200 : 404;
					if ( 'lookup' === self::$import_fault ) { $status = 503; }
				} else {
					if ( self::$during_upload ) { ( self::$during_upload )(); }
					$body = json_decode( $args['body'], true );
					$body['connectionId'] = SST_Settings::get( 'tc_key' );
					if ( in_array( self::$import_fault, array( 'reject', 'validation' ), true )
						|| ( 'partial' === self::$import_fault && '42_1' === $body['orderId'] ) ) {
						$status = 'validation' === self::$import_fault ? 422 : 503;
					} else {
						self::$remote[ $body['orderId'] ] = $body;
						$status = 201;
						if ( 'lost' === self::$import_fault ) { return new WP_Error( 'timeout', 'Timed out after creating order' ); }
						if ( 'malformed' === self::$import_fault ) { $body = array(); }
					}
				}
				return array( 'response' => array( 'code' => $status ), 'body' => json_encode( $body ) );
			}
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
	function do_action() {}
	function add_filter() {}
	function apply_filters( $hook, $value ) {
		if ( 'sst_should_capture_order' === $hook ) { return Routing_Http::$capture_allowed; }
		if ( 'sst_v3_origin_provider' === $hook && is_array( Routing_Http::$origins ) ) {
			return static function () { return Routing_Http::$origins; };
		}
		return $value;
	}
	function did_action() { return 0; }
	function get_option( $key, $default = null ) { return 'woocommerce_wootax_settings' === $key ? Routing_Http::$options : $default; }
	function update_option( $key, $value ) { Routing_Http::$options = $value; }
	function get_transient( $key ) { return Routing_Http::$transients[ $key ] ?? false; }
	function set_transient( $key, $value, $ttl = 0 ) { Routing_Http::$transients[ $key ] = $value; }
	function wp_next_scheduled() { return true; }
	function get_current_user_id() { return 42; }
	function get_user_by( $field, $id ) { return new WP_User( $id ); }
	function wp_get_current_user() { return new WP_User( 42 ); }
	function delete_transient( $key ) { unset( Routing_Http::$transients[ $key ] ); }
	function wc_get_price_decimals() { return 2; }
	function current_user_can( $capability ) { return Routing_Http::$denied_cap !== $capability; }
	function check_ajax_referer( $action, $field, $stop ) { return $action === ( $_POST[ $field ] ?? null ); }
	function wp_send_json_error( $body, $status ) { throw new Routing_Ajax_Response( $body['message'], $status ); }
	function sanitize_title( $value ) { return strtolower( $value ); }
	function wc_get_order( $id ) { return $GLOBALS['routing_order']; }
	function wp_unslash( $value ) { return $value; }
	function sanitize_text_field( $value ) { return trim( (string) $value ); }
	function get_user_meta( $user_id, $key, $single = false ) { return Routing_Http::$user_meta[ $user_id ][ $key ] ?? ''; }
	function update_user_meta( $user_id, $key, $value ) { Routing_Http::$user_meta[ $user_id ][ $key ] = $value; }
	function delete_user_meta( $user_id, $key ) { unset( Routing_Http::$user_meta[ $user_id ][ $key ] ); }
	function wp_date( $format, $timestamp = null ) { return date( $format, $timestamp ?? time() ); }
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
		public function get_error_data() { return $this->data; }
		public function get_error_code() { return $this->code; }
		private $message;
		private $data;
		private $code;
		public function __construct( $code, $message, $data = null ) { $this->message = $message; $this->code = $code; $this->data = $data; }
		public function get_error_message() { return $this->message; }
	}
	class Routing_Ajax_Response extends RuntimeException {}
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
		public static $count = 0;
		public function limit_reached() { return false; }
		public function increment_count() { ++self::$count; }
	}
	class SST_Product {
		public static function get_tic() { return 0; }
	}
	class Routing_Product {
		public function get_id() { return 7; }
	}
	class SST_Shipping {
		public static function is_local_delivery( $method ) { return 'local_delivery' === $method; }
	}
	class WC_Order {
		public static $persisted_meta = array();
		public static $fees = 1;
		public static $status = 'completed';
		public static $refunds = array();
		public static $date_completed;
		public static $city = 'Raleigh';
		public static $fee_price = 5;
		public function __construct( $id = 42 ) {
			if ( Routing_Http::$import ) { $this->meta = self::$persisted_meta; }
		}
		public function read_meta_data( $force_read = false ) {
			if ( Routing_Http::$import ) { $this->meta = self::$persisted_meta; }
		}
		public function save_meta_data() {
			if ( Routing_Http::$import ) { self::$persisted_meta = $this->meta; }
		}
		public $meta = array();
		public $notes = array();
		public function get_id() { return 42; }
		public function get_type() { return 'shop_order'; }
		public function has_status( $statuses ) { return in_array( self::$status, $statuses, true ); }
		public function get_refunds() { return self::$refunds; }
		public function get_data() {
			return array( 'total' => self::$fee_price * self::$fees, 'date_completed' => self::$date_completed, 'billing' => array( 'city' => self::$city ) );
		}
		public function get_items( $types ) {
			if ( 'line_item' === $types ) {
				return array();
			}
			return array_map( function( $id ) { return new Routing_Fee_Item( $id ); }, range( 1, self::$fees ) );
		}
		public function delete_meta_data( $key ) { unset( $this->meta[ $key ] ); }
		public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
		public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
		public function get_date_completed() { return self::$date_completed; }
		public function get_date_created() { return null; }
		public function get_currency() { return 'USD'; }
		public function get_total_refunded() { return 0; }
		public function get_remaining_refund_amount() { return 10; }
		public function add_order_note( $message ) { $this->notes[] = $message; }
		public function save() {}
	}
	class Routing_Fee_Item {
		private $id;
		public function __construct( $id ) { $this->id = $id; }
		public function get_id() { return $this->id; }
		public function get_type() { return 'fee'; }
		public function get_data() { return array( 'total' => WC_Order::$fee_price, 'total_tax' => 0.5 ); }
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
	class Routing_Fee_Refund {
		public function get_id() { return 101; }
		public function get_items( $types ) { return array( new Routing_Fee_Refund_Item() ); }
	}
	class Routing_Fee_Refund_Item {
		public function get_type() { return 'fee'; }
		public function get_name() { return 'handling'; }
		public function get_total() { return -2.50; }
	}

	require dirname( __DIR__ ) . '/includes/vendor/autoload.php';
	// Optional snapshot mode compares the same legacy requests against an exported
	// develop tree without checking out or running that branch on the store.
	$plugin_root = getenv( 'SST_ROUTING_PLUGIN_ROOT' ) ?: dirname( __DIR__ );
	foreach ( array( 'class-sst-settings.php', 'sst-functions.php', 'class-sst-taxcloud-v3-api.php', 'class-sst-certificates.php',
		'class-sst-origin-address.php', 'class-sst-addresses.php', 'class-sst-tic.php',
		'abstracts/class-sst-abstract-cart.php', 'class-sst-order.php', 'class-sst-order-controller.php', 'class-sst-ajax.php' ) as $file ) {
		require $plugin_root . '/includes/' . $file;
	}

	class Routing_Order extends SST_Order {
		public static $package_builds = 0;
		public $errors = array();
		public function create_packages() {
			++self::$package_builds;
			$address = new TaxCloud\Address( '1 Main St', '', WC_Order::$city, 'NC', '27601' );
			return array_fill( 0, WC_Order::$fees, array(
				'contents' => array(),
				'fees' => array( (object) array( 'id' => 'handling', 'amount' => WC_Order::$fee_price, 'tax' => 0.5 ) ),
				'shipping' => null, 'map' => array(), 'user' => array( 'ID' => 42 ),
				'origin' => $address, 'destination' => $address, 'certificate' => null,
			) );
		}
		public function v3_request( &$package ) { return $this->get_v3_lookup_for_package( $package, 0 ); }
		public function import_package( $package ) {
			$package['request'] = $this->get_lookup_for_package( $package );
			return $this->compress_package_data( $package );
		}
		public function lookup( $package ) {
			if ( 'v1' === sst_get_api_version() ) { $package['request'] = $this->get_lookup_for_package( $package ); }
			return $this->do_package_lookup( $package, 0 );
		}
		protected function handle_error( $message ) { $this->errors[] = $message; }
	}
	class Routing_Calculation extends Routing_Order {
		public $test_package;
		public $updates = 0;
		protected function do_lookup() { return array( $this->test_package ); }
		protected function reset_taxes() {}
		protected function update_taxes() { ++$this->updates; }
	}

	function legacy_snapshot() {
		$snapshot = array();
		foreach ( array( 'item-price', 'line-subtotal' ) as $basis ) {
			foreach ( array( null, 'saved', 'single-purchase' ) as $kind ) {
				Routing_Http::reset( 'v1' );
				SST_Settings::set( 'tax_based_on', $basis );
				SST_Settings::set( 'shipping_tic', 11010 );
				$address = new TaxCloud\Address( '1 Main St', '', 'Raleigh', 'NC', '27601' );
				$certificate = new TaxCloud\ExemptionCertificate( array( new TaxCloud\ExemptState( 'NC', 'Resale', 'QA-ONLY' ) ),
					'single-purchase' === $kind, '42', 'QA', 'Buyer', '', '1 Main St', '', 'Raleigh', 'NC', '27601',
					new TaxCloud\TaxID( 'FEIN', 'QA-ONLY', 'NC' ), 'RetailTrade', '', 'Resale', 'QA resale', '2026-01-01T00:00:00Z' );
				$data = $certificate->jsonSerialize();
				$data['CertificateID'] = 'legacy-certificate';
				SST_Certificates::set_certificates( 42, array( 'legacy-certificate' => TaxCloud\ExemptionCertificate::fromArray( $data ) ) );
				$package = array( 'contents' => array( 'item' => array( 'key' => 'item', 'line_total' => 25.50, 'quantity' => 3,
					'product_id' => 7, 'variation_id' => 0, 'data' => new Routing_Product() ) ),
					'fees' => array( (object) array( 'id' => 'handling', 'amount' => 2.50 ) ),
					'shipping' => (object) array( 'id' => 'flat_rate:7', 'method_id' => 'flat_rate:7', 'cost' => 4.25 ), 'map' => array(),
					'user' => array( 'ID' => 42 ), 'origin' => $address, 'destination' => $address,
					'certificate' => 'saved' === $kind ? new TaxCloud\ExemptionCertificateBase( 'legacy-certificate' ) : ( $kind ? $certificate : null ) );
				$order = new Routing_Order( new WC_Order() );
				$result = $order->lookup( $package );
				expect_same( false, is_wp_error( $result['response'] ), 'V1 rich cart lookup succeeds' );
				assert_route( 'v1', 'Lookup', '', 'Products, fees, shipping and exemptions remain on V1' );
				$compressed = $order->compress_package_data( $result );
				unset( $compressed['api_version'] ); // New ownership metadata does not change V1 API behavior.
				$snapshot[ $basis . ':' . ( $kind ?: 'taxable' ) ] = array( Routing_Http::$v1, $compressed );
			}
		}
		Routing_Http::reset( 'v1' );
		$snapshot['address'] = array( SST_Addresses::verify_address( $address ), Routing_Http::$v1 );
		Routing_Http::reset( 'v1' );
		$snapshot['tics'] = array( sst_get_tics(), Routing_Http::$v1 );
		Routing_Http::reset( 'v1' );
		$snapshot['create-certificate'] = array( SST_Certificates::add_certificate_object( $certificate, 42 ), Routing_Http::$v1 );
		Routing_Http::reset( 'v1' );
		SST_Certificates::set_certificates( 42, array( 'legacy-certificate' => TaxCloud\ExemptionCertificate::fromArray( $data ) ) );
		SST_Certificates::delete_certificate( 'legacy-certificate', 42 );
		$snapshot['delete-certificate'] = Routing_Http::$v1;
		Routing_Http::reset( 'v1' );
		SST_Settings::set( 'addresses', array() );
		$snapshot['origins'] = array( SST_Addresses::get_origin_addresses( true ), Routing_Http::$v1 );
		foreach ( array( false, true ) as $fail ) {
			Routing_Http::reset( 'v1' );
			Routing_Http::$fail = $fail;
			$order = new Routing_Order( new WC_Order() );
			$order->set_packages( array( $compressed ) );
			$snapshot['capture:' . (int) $fail] = array( $order->do_capture(), Routing_Http::$v1, $order->get_taxcloud_status() );
			Routing_Http::$v1 = array();
			$snapshot['refund:' . (int) $fail] = array( $order->do_refund( new Routing_Refund() ), Routing_Http::$v1, $order->get_taxcloud_status() );
		}
		// Capture/return timestamps are intentionally generated at execution time.
		$normalize = function( $value ) use ( &$normalize ) {
			if ( is_object( $value ) ) { $value = json_decode( wp_json_encode( $value ), true ); }
			if ( is_array( $value ) ) { return array_map( $normalize, $value ); }
			return is_string( $value ) && preg_match( '/^\d{4}-\d\d-\d\dT/', $value ) ? '<timestamp>' : $value;
		};
		return $normalize( $snapshot );
	}

	$snapshot = legacy_snapshot();
	if ( in_array( '--legacy-snapshot', $argv, true ) ) {
		echo wp_json_encode( $snapshot ) . "\n";
		exit;
	}
	expect_same( 15, count( $snapshot ), 'Legacy parity covers six rich carts, addresses, origins, TICs, certificates, capture and refund outcomes' );

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

	// Native setup reads live contracts without changing the saved profile.
	Routing_Http::reset( 'v1' );
	$connection_id = '11111111-1111-4111-8111-111111111111';
	SST_Settings::set_many( array( 'tc_id' => '', 'tc_key' => '', 'addresses' => array() ) );
	$before_setup = Routing_Http::$options;
	$credentials = SST_TaxCloud_V3_API::resolve_v3_credentials( $connection_id, 'developer-key' );
	$unavailable = SST_TaxCloud_V3_API::get_v3_setup( $credentials['connection_id'], $credentials['api_key'] );
	expect_same( 'sst_v3_origin_unavailable', $unavailable->get_error_code(), 'Unconfirmed origin contract gates native activation' );
	expect_same( $before_setup, Routing_Http::$options, 'Read-only setup leaves the original store unchanged' );
	expect_same( array( '/tax/connections/' . $connection_id . '/ping', '/mgmt/connections/' . $connection_id ), array_map( static function ( $request ) {
		return parse_url( $request[1], PHP_URL_PATH );
	}, Routing_Http::$v3 ), 'Native credentials verify Ping then integration mode' );
	foreach ( Routing_Http::$v3 as $request ) {
		expect_same( 'developer-key', $request[2]['headers']['X-API-KEY'], 'Both setup requests use the form key' );
		expect_same( false, isset( $request[2]['headers']['Authorization'] ), 'Native setup skips bearer authentication' );
	}

	$origin = new SST_Origin_Address( 'origin-1', false, '1 Main St', '', 'Raleigh', 'NC', '27601' );
	Routing_Http::$origins = array( $origin );
	$setup = SST_TaxCloud_V3_API::get_v3_setup( $connection_id, 'developer-key' );
	expect_same( false, $setup['data_mover'], 'Real Time comes from TaxCloud' );
	$addresses = SST_TaxCloud_V3_API::select_v3_origins( $setup['origins'], array() );
	expect_same( array( 'origin-1' ), $addresses['default_origin_addresses'], 'A sole origin is selected automatically' );
	Routing_Http::$origins[] = new SST_Origin_Address( 'origin-2', false, '2 Main St', '', 'Raleigh', 'NC', '27601' );
	$setup = SST_TaxCloud_V3_API::get_v3_setup( $connection_id, 'developer-key' );
	expect_same( 'sst_v3_origin_required', SST_TaxCloud_V3_API::select_v3_origins( $setup['origins'], array() )->get_error_code(), 'Multiple origins require a choice' );
	expect_same( array( 'origin-1' ), SST_TaxCloud_V3_API::select_v3_origins( $setup['origins'], array( 'origin-1', 'removed' ) )['default_origin_addresses'], 'Previously selected valid origins are retained' );
	SST_Settings::set_many( $addresses + array( 'api_version' => 'v3', 'tc_key' => $connection_id, 'tc_v3_api_key' => 'developer-key' ) );
	expect_same( true, SST_Settings::has_api_credentials(), 'V3-only store is ready without a login ID' );
	expect_same( 'developer-key', SST_TaxCloud_V3_API::resolve_v3_credentials( $connection_id, '' )['api_key'], 'Blank key preserves the saved key' );
	expect_same( 'sst_v3_connection_id', SST_TaxCloud_V3_API::resolve_v3_credentials( '22222222-2222-4222-8222-222222222222', 'new-key' )->get_error_code(), 'Rebinding an existing connection is blocked' );
	$rejected = SST_TaxCloud_V3_API::get_v3_setup( $connection_id, 'rotated-bad-key' );
	expect_same( 'TaxCloud authentication failed: Rejected [redacted]', $rejected->get_error_message(), 'Rejected replacement keys are redacted' );
	expect_same( 'developer-key', SST_Settings::get( 'tc_v3_api_key' ), 'Rejected replacement does not clobber the old key' );
	Routing_Http::$setup_mode = true;
	expect_same( true, SST_TaxCloud_V3_API::update_data_mover_settings(), 'Native refresh syncs mode and origins using saved credentials' );
	expect_same( true, SST_Settings::get( 'data_mover' ), 'Data Import comes from TaxCloud' );
	$before_refresh = Routing_Http::$options;
	Routing_Http::$setup_failure = 403;
	expect_same( 'sst_v3_integration_mode', SST_TaxCloud_V3_API::update_data_mover_settings()->get_error_code(), 'Mode refresh reports denied access' );
	expect_same( $before_refresh, Routing_Http::$options, 'Failed refresh preserves both the mode and origins' );
	SST_Settings::set( 'api_version', 'v1' );
	expect_same( false, SST_Settings::has_api_credentials(), 'V1 still requires a V1 login ID' );
	SST_Settings::set( 'tc_id', 'legacy-id' );
	expect_same( true, SST_Settings::has_api_credentials(), 'Switching to V1 retains its credential checks' );
	expect_same( 'developer-key', SST_Settings::get( 'tc_v3_api_key' ), 'Switching versions preserves the saved V3 key' );

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
			Routing_Http::$body = array( 'items' => array( array( 'cartId' => '42_0', 'lineItems' => array( array( 'index' => 0, 'tax' => array( 'amount' => 1.0 ) ) ) ) ) );
			$address = new TaxCloud\Address( '1 Main St', '', 'Raleigh', 'NC', '27601' );
			$package = array( 'contents' => array(), 'fees' => array( (object) array( 'id' => 'test-fee', 'amount' => 10 ) ), 'shipping' => null, 'map' => array(), 'user' => array( 'ID' => 42 ), 'origin' => $address, 'destination' => $address, 'certificate' => null );
			$result = ( new Routing_Order( new WC_Order() ) )->lookup( $package );
			expect_same( $fail, is_wp_error( $result['response'] ), 'Lookup propagates failure' );
			expect_same( 1, SST_Rate_Limit::$count, 'Successful and rejected lookup attempts consume one request for ' . $version );
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
			expect_same( true, count( Routing_Http::$v3 ) > 0, 'Saved certificates retain develop\'s shared V3 lookup in both settings' );
			if ( 'v1' === $version ) {
				expect_same( true, count( Routing_Http::$v1 ) > 0, 'V1 sites retain develop\'s legacy certificate fallback' );
			} else {
				expect_same( array(), Routing_Http::$v1, 'Explicit V3 certificate lookup does not fall back to V1' );
			}
		}
	}

	// HTTP 200 is only a valid quote when it contains exactly the requested
	// cart and a finite tax amount for each line. Zero tax is a valid result.
	$address = new TaxCloud\Address( '1 Main St', '', 'Raleigh', 'NC', '27601' );
	$quote_package = array( 'contents' => array(), 'fees' => array( (object) array( 'id' => 'fee-a', 'amount' => 10 ), (object) array( 'id' => 'fee-b', 'amount' => 5 ) ),
		'shipping' => null, 'user' => array( 'ID' => 42 ), 'origin' => $address, 'destination' => $address, 'certificate' => null );
	$valid_cart = array( 'cartId' => '42_0', 'lineItems' => array( array( 'index' => 0, 'tax' => array( 'amount' => 0 ) ), array( 'index' => 1, 'tax' => array( 'amount' => 1.25 ) ) ) );
	$invalid_quotes = array(
		'missing items' => array( 'unexpected' => true ),
		'empty items' => array( 'items' => array() ),
		'scalar items' => array( 'items' => 'invalid' ),
		'scalar cart' => array( 'items' => array( 'invalid' ) ),
		'extra cart' => array( 'items' => array( $valid_cart, $valid_cart ) ),
	);
	foreach ( array( null, '', 'another-cart' ) as $cart_id ) {
		$cart = $valid_cart; $cart['cartId'] = $cart_id;
		$invalid_quotes['wrong cart ID ' . var_export( $cart_id, true )] = array( 'items' => array( $cart ) );
	}
	foreach ( array( null, 'invalid', array(), array( $valid_cart['lineItems'][0] ), array( $valid_cart['lineItems'][0], $valid_cart['lineItems'][0] ),
		array( array( 'index' => 9, 'tax' => array( 'amount' => 0 ) ), $valid_cart['lineItems'][1] ), array( 'invalid', $valid_cart['lineItems'][1] ) ) as $i => $lines ) {
		$cart = $valid_cart; $cart['lineItems'] = $lines;
		$invalid_quotes['invalid line collection ' . $i] = array( 'items' => array( $cart ) );
	}
	foreach ( array( null, false, 'invalid', array() ) as $i => $tax ) {
		$cart = $valid_cart; $cart['lineItems'][0]['tax']['amount'] = $tax;
		$invalid_quotes['invalid tax ' . $i] = array( 'items' => array( $cart ) );
	}
	foreach ( $invalid_quotes as $name => $response ) {
		Routing_Http::reset( 'v3' );
		Routing_Http::$body = $response;
		$result = ( new Routing_Order( new WC_Order() ) )->lookup( $quote_package );
		expect_same( true, is_wp_error( $result['response'] ), $name . ' must report a lookup error' );
		expect_same( 1, SST_Rate_Limit::$count, $name . ' counts the attempted request' );
		expect_same( array(), Routing_Http::$v1, $name . ' never falls back to V1' );
		$calculation = new Routing_Calculation( new WC_Order() );
		$calculation->test_package = $result;
		expect_same( false, $calculation->calculate_taxes(), $name . ' cannot be treated as a successful zero-tax calculation' );
		expect_same( 1, count( $calculation->errors ), $name . ' reaches the checkout/order error handler' );
		expect_same( 0, $calculation->updates, $name . ' never finalizes tax totals' );
	}
	Routing_Http::reset( 'v3' );
	$valid_cart['lineItems'] = array_reverse( $valid_cart['lineItems'] );
	Routing_Http::$body = array( 'items' => array( $valid_cart ) );
	$result = ( new Routing_Order( new WC_Order() ) )->lookup( $quote_package );
	expect_same( array( '42_0' => array( 1 => 1.25, 0 => 0 ) ), $result['response'], 'Valid zero tax and reordered line results retain their indices' );

	// The shared certificate mapper must reject missing V3 descriptions before
	// authentication/HTTP, while retaining V1's existing optional Resale value.
	foreach ( array( 'v1', 'v3' ) as $version ) {
		Routing_Http::reset( $version );
		$certificate = new TaxCloud\ExemptionCertificate( array( new TaxCloud\ExemptState( 'NC', 'Resale', 'QA-ONLY' ) ), false, '',
			'QA', 'Buyer', '', '1 Main St', '', 'Raleigh', 'NC', '27601', new TaxCloud\TaxID( 'FEIN', 'QA-ONLY', 'NC' ), 'RetailTrade', '', 'Resale', '   ' );
		try {
			$id = SST_Certificates::add_certificate_object( $certificate, 42 );
			expect_same( 'v1', $version, 'Blank descriptions are accepted only by legacy certificate registration' );
			expect_same( 'legacy-certificate', $id, 'V1 certificate registration remains unchanged' );
		} catch ( \InvalidArgumentException $error ) {
			expect_same( 'v3', $version, 'V3 validates the missing description' );
			expect_same( 'Please enter the exemption reason description.', $error->getMessage(), 'Missing description gives an actionable validation message' );
			expect_same( array(), Routing_Http::$v3, 'Invalid certificate does not reach HTTP' );
		}
	}

	// develop already reads saved certificates using V3, even with V1 selected.
	foreach ( array( 'v1', 'v3' ) as $version ) {
		Routing_Http::reset( $version );
		Routing_Http::$body = array( 'items' => array( array( 'certificateId' => 'shared-v3-certificate',
			'customerId' => '42', 'customerName' => 'QA Buyer', 'customerBusinessType' => 'RetailTrade',
			'reason' => 'Resale', 'reasonDescription' => 'QA resale', 'singlePurchase' => false,
			'address' => array( 'line1' => '1 Main St', 'city' => 'Raleigh', 'state' => 'NC', 'zip' => '27601' ),
			'states' => array( array( 'abbreviation' => 'NC' ) ), 'createdDate' => '2026-01-01T00:00:00Z' ) ) );
		$certificates = SST_Certificates::get_certificates( 42 );
		expect_same( array( 'shared-v3-certificate' ), array_keys( $certificates ), 'Both settings retain develop\'s existing V3 saved certificates' );
		assert_route( 'v3', '', '/tax/exemption-certificates', 'Existing V3 certificates avoid unnecessary legacy fallback' );
		expect_same( true, SST_Certificates::certificate_covers_state( $certificates['shared-v3-certificate'], 'NC', 42 ), 'Shared certificate state coverage remains usable' );
	}

	// Submitted tax IDs survive V3's intentionally smaller schema, cache expiry,
	// refreshes and API switches. Local metadata must never invent availability.
	foreach ( array( 'v1', 'v3' ) as $created_version ) {
		foreach ( array( 'FEIN', 'StateIssued' ) as $tax_type ) {
			Routing_Http::reset( $created_version );
			Routing_Http::$body = array( 'certificateId' => 'v3-certificate' );
			$submitted = new TaxCloud\TaxID( $tax_type, 'QA-TAX-ID', 'NC' );
			$certificate = new TaxCloud\ExemptionCertificate( array( new TaxCloud\ExemptState( 'NC', 'Other', 'QA-TAX-ID' ) ), false, '',
				'QA', 'Buyer', '', '1 Main St', '', 'Raleigh', 'NC', '27601', $submitted, 'Other', 'QA business', 'Other', 'QA test' );
			$id = SST_Certificates::add_certificate_object( $certificate, 42 );
			expect_same( 1, count( Routing_Http::$user_meta[42] ), 'Successful reusable creation retains tax ID details' );
			$remote = array( 'certificateId' => $id, 'customerId' => '42', 'customerName' => 'QA Buyer',
				'customerBusinessType' => 'Other', 'reason' => 'Other', 'states' => array( array( 'abbreviation' => 'NC' ) ) );
			Routing_Http::$body = array( 'items' => array( $remote ) );
			foreach ( array( 'v1', 'v3' ) as $read_version ) {
				SST_Settings::set( 'api_version', $read_version );
				SST_Certificates::delete_certificates( 42 );
				$fetched = SST_Certificates::get_certificate( $id, 42 );
				expect_same( $submitted->jsonSerialize(), $fetched->getDetail()->getPurchaserTaxID()->jsonSerialize(), "$created_version to $read_version: tax type, number and issuing state survive refresh" );
				$requests = count( Routing_Http::$v3 );
				$cached = SST_Certificates::get_certificates( 42, false );
				expect_same( $submitted->jsonSerialize(), $cached[$id]->getDetail()->getPurchaserTaxID()->jsonSerialize(), 'Cached reads retain the same tax ID details' );
				expect_same( $requests, count( Routing_Http::$v3 ), 'Cached reads do not issue HTTP requests' );
				$display = SST_Certificates::format_certificate( $fetched );
				expect_same( sst_prettify( $tax_type ), $display['TaxType'], 'View displays the submitted tax ID type' );
				expect_same( 'QA-TAX-ID', $display['IDNumber'], 'View displays the submitted tax ID number' );
			}
			expect_same( '', SST_Certificates::get_certificate( $id, 43 )->getDetail()->getPurchaserTaxID()->getIDNumber(), 'Tax IDs are private to their customer' );
			SST_Settings::set( 'tc_key', 'other-connection' );
			expect_same( '', SST_Certificates::get_certificate( $id, 42 )->getDetail()->getPurchaserTaxID()->getIDNumber(), 'Tax IDs are private to their connection even with a cached matching ID' );
			SST_Settings::set( 'tc_key', 'test-connection' );
			Routing_Http::$body = array( 'items' => array() );
			SST_Certificates::delete_certificates( 42 );
			expect_same( array(), SST_Certificates::get_certificates( 42 ), 'Retained tax IDs cannot resurrect a certificate removed remotely' );
			Routing_Http::$body = array( 'items' => array( $remote ) );
			SST_Certificates::delete_certificates( 42 );
			SST_Certificates::get_certificates( 42 );
			Routing_Http::$fail = true;
			try { SST_Certificates::delete_certificate( $id, 42 ); } catch ( \Throwable $error ) {}
			expect_same( 1, count( Routing_Http::$user_meta[42] ), 'Failed remote deletion retains the local tax ID' );
			Routing_Http::$fail = false;
			SST_Certificates::delete_certificates( 42 );
			SST_Certificates::delete_certificate( $id, 42 );
			expect_same( array(), Routing_Http::$user_meta[42], 'Successful remote deletion removes the local tax ID' );
		}
	}
	$unknown = SST_Certificates::format_certificate( SST_Certificates::build_v1_cert_from_v3( $remote ) );
	expect_same( 'Not available', $unknown['TaxType'], 'Unknown tax ID type is not fabricated as FEIN' );
	expect_same( 'Not available', $unknown['IDNumber'], 'Unavailable tax ID is explicitly identified' );
	Routing_Http::reset( 'v3' );
	Routing_Http::$fail = true;
	try { SST_Certificates::add_certificate_object( $certificate, 42 ); } catch ( \Throwable $error ) {}
	expect_same( array(), Routing_Http::$user_meta, 'Failed registration does not retain tax ID details' );

	// Data Import was already a V3 integration on develop. V1 selection must
	// neither disable it nor send the imported order to AuthorizedWithCapture.
	foreach ( array( 'v1', 'v3' ) as $version ) {
		Routing_Http::reset( $version );
		SST_Settings::set( 'data_mover', true );
		Routing_Http::$import = true;
		$address = new TaxCloud\Address( '1 Main St', '', 'Raleigh', 'NC', '27601' );
		$order = new Routing_Order( new WC_Order() );
		$package = $order->import_package( array( 'contents' => array(), 'fees' => array( (object) array( 'id' => 'handling', 'amount' => 5, 'tax' => 0.5 ) ),
			'shipping' => null, 'map' => array(), 'user' => array( 'ID' => 42 ), 'origin' => $address, 'destination' => $address, 'certificate' => null ) );
		expect_same( 'v3', $package['api_version'], 'Data Import packages belong to V3 in both settings' );
		$order->set_packages( array( $package ) );
		$order->save_meta_data();
		expect_same( true, $order->do_capture(), 'Existing V3 Data Import succeeds with either selection' );
		expect_same( 'GET', Routing_Http::$v3[0][0], 'Check for an existing import before posting' );
		array_shift( Routing_Http::$v3 );
		assert_route( 'v3', '', '/tax/connections/test-connection/orders', 'Existing Data Import remains V3' );
		$payload = json_decode( Routing_Http::$v3[0][2]['body'], true );
		expect_same( 0.5, $payload['lineItems'][0]['tax']['amount'], 'Data Import preserves the WooCommerce fee tax' );
		Routing_Http::$v3 = array();
		Routing_Http::$body = array();
		expect_same( true, $order->do_refund( new Routing_Fee_Refund() ), 'Existing Data Import refund succeeds in both settings' );
		assert_route( 'v3', '', '/tax/connections/test-connection/orders/refunds/42_0', 'Existing Data Import refunds remain V3' );
	}

	foreach ( array( 'lookup', 'lost', 'malformed', 'partial' ) as $fault ) {
		Routing_Http::reset( 'v3' );
		SST_Settings::set( 'data_mover', true );
		SST_Settings::set( 'capture_orders_in_taxcloud', 'no' );
		Routing_Http::$import = true;
		Routing_Http::$import_fault = $fault;
		WC_Order::$fees = 'partial' === $fault ? 2 : 1;
		$order = new Routing_Order( new WC_Order() );
		$order->set_packages( array_fill( 0, WC_Order::$fees, $package ) );
		$order->save_meta_data();

		expect_same( false, $order->do_capture(), 'Automatic capture respects the capture setting' );
		Routing_Http::$capture_allowed = false;
		expect_same( 'sst_capture_unavailable', $order->capture_data_import_manually()->get_error_code(), 'Filter can veto a manual import' );
		expect_same( array(), Routing_Http::$v3, 'Skipped captures never reach TaxCloud' );
		Routing_Http::$capture_allowed = true;
		Routing_Http::$during_upload = function() {
			SST_Settings::set( 'capture_orders_in_taxcloud', 'yes' );
			$other = new Routing_Order( new WC_Order() );
			expect_same( false, $other->do_capture(), 'Overlapping automatic capture cannot start' );
			SST_Settings::set( 'capture_orders_in_taxcloud', 'no' );
		};

		expect_same( true, is_wp_error( $order->capture_data_import_manually() ), 'Failed response is reported for ' . $fault );
		expect_same( 'pending', $order->get_taxcloud_status(), 'Failure leaves TaxCloud status pending' );
		$posts = array_values( array_filter( Routing_Http::$v3, function( $request ) { return 'POST' === $request[0]; } ) );
		expect_same( 'lookup' === $fault ? 0 : WC_Order::$fees, count( $posts ), 'Upload posts before retry' );
		Routing_Http::$v3 = array();
		Routing_Http::$import_fault = '';
		if ( 'lookup' !== $fault ) {
			SST_Settings::set( 'tc_key', 'other-connection' );
			expect_same( 'sst_capture_changed', $order->capture_data_import_manually()->get_error_code(), 'Changed connection cannot resume a frozen upload' );
			SST_Settings::set( 'tc_key', 'test-connection' );
			++WC_Order::$fees;
			expect_same( 'sst_capture_changed', $order->capture_data_import_manually()->get_error_code(), 'Changed order cannot resume a frozen upload' );
			--WC_Order::$fees;
			$posts = array_filter( Routing_Http::$v3, function( $request ) { return 'POST' === $request[0]; } );
			expect_same( array(), $posts, 'Changed uploaded order sends no POST' );
		}
		if ( 'lost' === $fault ) {
			Routing_Http::$v3 = array();
			SST_Settings::set( 'tc_v3_api_key', 'new-key-for-the-same-connection' );
		}
		expect_same( true, $order->capture_data_import_manually(), 'Unchanged order can retry ' . $fault );
		if ( 'lost' === $fault ) {
			expect_same( 'new-key-for-the-same-connection', Routing_Http::$v3[0][2]['headers']['X-API-KEY'], 'An interrupted upload can be reconciled with a key for the same connection' );
		}
		expect_same( 'captured', $order->get_taxcloud_status(), 'Only a complete upload marks the order captured' );
		$posts = array_values( array_filter( Routing_Http::$v3, function( $request ) { return 'POST' === $request[0]; } ) );
		expect_same( in_array( $fault, array( 'lookup', 'partial' ), true ) ? 1 : 0, count( $posts ), 'Retry only posts the package missing from TaxCloud' );
		if ( 'partial' === $fault ) {
			expect_same( '42_1', json_decode( $posts[0][2]['body'], true )['orderId'], 'Retry preserves the original second package ID' );
		}
		expect_same( WC_Order::$fees, count( Routing_Http::$remote ), 'One order per package after retries' );
	}

	// Rejected Processing uploads can be completed or corrected before retry.
	// Only a complete GET/404 check permits discarding the original snapshot.
	foreach ( array( 'reject', 'validation' ) as $fault ) {
		foreach ( array( false, true ) as $automatic ) {
			Routing_Http::reset( 'v3' );
			SST_Settings::set( 'data_mover', true );
			Routing_Http::$import = true;
			Routing_Http::$import_fault = $fault;
			WC_Order::$status = 'processing';
			WC_Order::$fees = 2;
			$order = new Routing_Order( new WC_Order() );
			$order->set_packages( array_fill( 0, 2, $package ) );
			$order->save_meta_data();
			$capture = function() use ( $automatic, $order ) {
				return $automatic ? $order->do_capture() : $order->capture_data_import_manually();
			};
			$result = $capture();
			expect_same( true, $automatic ? false === $result : is_wp_error( $result ), 'Initial upload is rejected' );
			expect_same( array(), Routing_Http::$remote, 'Rejected upload created no packages' );
			$original = WC_Order::$persisted_meta['_wootax_data_import_upload'];

			WC_Order::$status = 'completed';
			WC_Order::$date_completed = new DateTimeImmutable( '2026-11-17T12:00:00+00:00' );
			WC_Order::$city = 'Durham';
			WC_Order::$fee_price = 12;
			Routing_Http::$import_fault = 'lookup';
			$result = $capture();
			expect_same( true, $automatic ? false === $result : 'sst_capture_lookup' === $result->get_error_code(), 'Unavailable GET cannot clear the frozen upload' );
			expect_same( $original, WC_Order::$persisted_meta['_wootax_data_import_upload'], 'Lookup outage preserves the original snapshot' );
			expect_same( 0, Routing_Order::$package_builds, 'No rebuild without proof that all remote IDs are missing' );

			Routing_Http::$v3 = array();
			Routing_Http::$import_fault = '';
			expect_same( true, $capture(), 'Completing and correcting a rejected order permits retry' );
			expect_same( 1, Routing_Order::$package_builds, 'Retry rebuilds from persisted WooCommerce data' );
			expect_same( 'GET', Routing_Http::$v3[0][0], 'First original package ID is checked before rebuilding' );
			expect_same( 'GET', Routing_Http::$v3[1][0], 'Second original package ID is checked before any POST' );
			expect_same( 'captured', $order->get_taxcloud_status(), 'Corrected order is captured' );
			foreach ( Routing_Http::$remote as $remote ) {
				expect_same( 'Durham', $remote['destination']['city'], 'Corrected address replaces stale checkout packages' );
				expect_same( 12, $remote['lineItems'][0]['price'], 'Corrected item price is uploaded' );
				expect_same( '2026-11-17T12:00:00+00:00', $remote['completedDate'], 'Completion date is refreshed after rejection' );
			}
			expect_same( false, isset( WC_Order::$persisted_meta['_wootax_data_import_upload'] ), 'Successful retry clears recovery metadata' );
		}
	}

	// Even an unconfirmed later remote package prevents rebuilding the upload.
	Routing_Http::reset( 'v3' );
	SST_Settings::set( 'data_mover', true );
	Routing_Http::$import = true;
	Routing_Http::$import_fault = 'reject';
	WC_Order::$fees = 2;
	$order = new Routing_Order( new WC_Order() );
	$order->set_packages( array_fill( 0, 2, $package ) );
	$order->save_meta_data();
	expect_same( true, is_wp_error( $order->capture_data_import_manually() ), 'Two-package upload starts with an unconfirmed failure' );
	$saved_upload = WC_Order::$persisted_meta['_wootax_data_import_upload'];
	$upload = json_decode( $saved_upload, true );
	// Model a second remote package whose success was never confirmed locally.
	$remote = $upload['payloads']['42_1'];
	$remote['connectionId'] = 'test-connection';
	$remote['currency'] = array( 'currencyCode' => 'USD' );
	Routing_Http::$remote['42_1'] = $remote;
	Routing_Http::$import_fault = '';
	Routing_Http::$v3 = array();
	WC_Order::$city = 'Durham';
	expect_same( 'sst_capture_changed', $order->capture_data_import_manually()->get_error_code(), 'A later remote package blocks rebuilding even when the first is missing' );
	expect_same( 2, count( Routing_Http::$v3 ), 'Every original package must be checked before discarding the snapshot' );
	expect_same( $saved_upload, WC_Order::$persisted_meta['_wootax_data_import_upload'], 'Remote partial upload retains its original snapshot' );
	expect_same( 0, Routing_Order::$package_builds, 'Partial remote success never rebuilds the order' );

	// Real Time checkout evidence excludes the manual button, but automatic
	// capture must still upload the saved WC order if the store changes modes.
	foreach ( array( 'cart_id', 'response', 'api_version', 'request' ) as $evidence ) {
		Routing_Http::reset( 'v3' );
		SST_Settings::set( 'data_mover', true );
		Routing_Http::$import = true;
		$order = new Routing_Order( new WC_Order() );
		$realtime = $package;
		$realtime[ $evidence ] = array(
			'cart_id' => 'previous-realtime-cart', 'response' => array( 'previous-realtime-cart' => array( 1 ) ),
			'api_version' => 'v1', 'request' => new TaxCloud\Request\Lookup( 'test', 'test', 42, null, array(), $address, $address ),
		)[ $evidence ];
		$order->set_packages( array( $realtime ) );
		$order->save_meta_data();
		expect_same( false, $order->is_data_import_capture_candidate(), 'Real Time evidence keeps the manual button hidden' );
		expect_same( 'sst_capture_unavailable', $order->capture_data_import_manually()->get_error_code(), 'Direct manual request is rejected' );
		expect_same( true, $order->do_capture(), 'Automatic capture continues after the store changes to Data Import' );
		expect_same( 1, Routing_Order::$package_builds, 'Real Time packages are rebuilt as order upload packages' );
		expect_same( array(), Routing_Http::$v1, 'Data Import uses no V1 capture' );
		expect_same( 2, count( Routing_Http::$v3 ), 'One existence check and one order upload' );
		expect_same( '/tax/connections/test-connection/orders', parse_url( Routing_Http::$v3[1][1], PHP_URL_PATH ), 'Automatic capture uses Orders API' );
		expect_same( 'captured', $order->get_taxcloud_status(), 'Mode-switched order is captured' );
	}

	Routing_Http::reset( 'v3' );
	SST_Settings::set( 'data_mover', true );
	Routing_Http::$import = true;
	$order = new Routing_Order( new WC_Order() );
	foreach ( array( 'pending', 'on-hold', 'cancelled', 'failed', 'refunded' ) as $status ) {
		WC_Order::$status = $status;
		expect_same( false, $order->is_data_import_capture_candidate(), 'WooCommerce ' . $status . ' order is ineligible' );
	}
	WC_Order::$status = 'processing';
	expect_same( true, $order->is_data_import_capture_candidate(), 'Processing order can be captured' );
	WC_Order::$refunds = array( new Routing_Refund() );
	expect_same( false, $order->is_data_import_capture_candidate(), 'An existing WooCommerce refund blocks capture' );
	WC_Order::$refunds = array();
	$order->update_meta( 'status', 'partially_refunded' );
	expect_same( false, $order->is_data_import_capture_candidate(), 'A partially refunded TaxCloud order cannot be captured' );
	$order->update_meta( 'status', 'pending' );
	SST_Settings::set( 'disable_integration', 'yes' );
	expect_same( false, $order->can_capture_data_import_order(), 'Disabled integration blocks manual capture' );

	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST = array( 'order_id' => '42', 'nonce' => 'sst_capture_order_42' );
	foreach ( array( 'manage_woocommerce', 'edit_shop_order' ) as $denied ) {
		Routing_Http::$denied_cap = $denied;
		try {
			SST_Ajax::capture_order();
			throw new RuntimeException( 'Permission check must end the request' );
		} catch ( Routing_Ajax_Response $result ) {
			expect_same( 403, $result->getCode(), 'Missing ' . $denied . ' denies a request with a valid nonce' );
		}
	}
	$_POST = array();
	expect_same( array(), Routing_Http::$v3, 'Ineligible requests do not reach TaxCloud' );
	Routing_Http::reset( 'v3' );

	// Register the real SDK single-purchase object through the plugin's V3 mapper,
	// then reload order metadata and force another quote. It must not register twice.
	foreach ( array( 'Software QA test — certificate review', str_repeat( 'é', 19 ) . '😀more' ) as $description ) {
		Routing_Http::reset( 'v3' );
		Routing_Http::$body = array( 'certificateId' => 'registered-certificate' );
		$certificate = new TaxCloud\ExemptionCertificate(
			array( new TaxCloud\ExemptState( 'TX', 'Other', 'QA-TEST-ONLY' ) ), true, '42',
			'QA', 'Test', '', '1100 Congress Ave', '', 'Austin', 'TX', '78701',
			new TaxCloud\TaxID( 'StateIssued', 'QA-TEST-ONLY', 'TX' ), 'Other', $description, 'Other', $description
		);
		$address = new TaxCloud_V3\Model\Address( array( 'line1' => '1100 Congress Ave', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78701' ) );
		$package = array( 'contents' => array(), 'fees' => array(), 'shipping' => null, 'user' => array( 'ID' => 42 ), 'origin' => $address, 'destination' => $address, 'certificate' => $certificate );
		$order = new WC_Order();
		$sst = new Routing_Order( $order );
		$request = $sst->v3_request( $package );
		$payload = json_decode( Routing_Http::$v3[0][2]['body'], true );
		foreach ( array( 'customerBusinessDescription', 'reasonDescription' ) as $field ) {
			expect_same( mb_substr( $description, 0, 20, 'UTF-8' ), $payload[ $field ], 'V3 descriptions fit the character limit without corrupting UTF-8' );
		}
		expect_same( 'registered-certificate', $request['items'][0]['exemption']['exemptionId'], 'Quote applies the newly registered certificate' );
		expect_same( 'registered-certificate', $package['certificate']->getCertificateID(), 'Package retains the remote certificate ID' );
		$reloaded = new Routing_Order( $order );
		$package['certificate'] = $reloaded->get_single_purchase_certificate();
		expect_same( 'registered-certificate', $package['certificate']->getCertificateID(), 'Order metadata persists the certificate ID' );
		$request = $reloaded->v3_request( $package );
		expect_same( 1, count( Routing_Http::$v3 ), 'Recalculation reuses the certificate without registration or customer-list lookup' );
		expect_same( 'registered-certificate', $request['items'][0]['exemption']['exemptionId'], 'Recalculation remains exempt' );
		$package['destination'] = new TaxCloud_V3\Model\Address( array( 'line1' => '1 Main St', 'city' => 'Raleigh', 'state' => 'NC', 'zip' => '27601' ) );
		$request = $reloaded->v3_request( $package );
		expect_same( false, isset( $request['items'][0]['exemption'] ), 'Persisted single-purchase certificate cannot exempt a different state' );

		// Failure must stop lookup rather than silently calculating a taxable cart.
		Routing_Http::reset( 'v3' );
		Routing_Http::$fail = true;
		$package['destination'] = $address;
		$package['certificate'] = $certificate;
		$result = ( new Routing_Order( new WC_Order() ) )->lookup( $package );
		expect_same( true, is_wp_error( $result['response'] ), 'Registration failure propagates to the checkout tax error handler' );
		assert_route( 'v3', '', '/tax/connections/test-connection/exemption-certificates', 'Failed registration never submits a taxable cart' );

		Routing_Http::reset( 'v3' );
		$failed_order = new Routing_Order( new WC_Order() );
		$result = $failed_order->lookup( $package );
		expect_same( true, is_wp_error( $result['response'] ), 'A successful HTTP response without a certificate ID is still a registration failure' );
		expect_same( null, $failed_order->get_single_purchase_certificate(), 'An invalid certificate response is not persisted as registered' );
		assert_route( 'v3', '', '/tax/connections/test-connection/exemption-certificates', 'Missing ID never submits an unexempted cart' );
	}

	// A disabled admin selector is not posted when changing a Processing order
	// back to Pending payment. Preserve the certificate unless explicitly cleared.
	$routing_order = new WC_Order();
	$routing_order->update_meta_data( '_wootax_exempt_cert', SST_SINGLE_PURCHASE_CERT_ID );
	$controller = new SST_Order_Controller();
	$_POST = array( 'order_status' => 'wc-pending' );
	$controller->save_certficiate( 42 );
	expect_same( SST_SINGLE_PURCHASE_CERT_ID, $routing_order->get_meta( '_wootax_exempt_cert' ), 'Saving an unrelated field preserves a disabled exemption selection' );
	$_POST['exempt_cert'] = '';
	$controller->save_certficiate( 42 );
	expect_same( '', $routing_order->get_meta( '_wootax_exempt_cert' ), 'An explicitly cleared selector removes the exemption' );
	$_POST = array();

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
