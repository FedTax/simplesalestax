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
	function get_user_by( $field, $id ) { return new WP_User( $id ); }
	function wp_get_current_user() { return new WP_User( 42 ); }
	function delete_transient( $key ) { unset( Routing_Http::$transients[ $key ] ); }
	function wc_get_price_decimals() { return 2; }
	function current_user_can() { return true; }
	function sanitize_title( $value ) { return strtolower( $value ); }
	function wc_get_order( $id ) { return $GLOBALS['routing_order']; }
	function wp_unslash( $value ) { return $value; }
	function sanitize_text_field( $value ) { return trim( (string) $value ); }
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
		public $meta = array();
		public $notes = array();
		public function get_id() { return 42; }
		public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
		public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
		public function get_date_completed() { return null; }
		public function get_date_created() { return null; }
		public function get_currency() { return 'USD'; }
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
	foreach ( array( 'class-sst-settings.php', 'sst-functions.php', 'class-sst-certificates.php',
		'class-sst-origin-address.php', 'class-sst-addresses.php', 'class-sst-tic.php',
		'abstracts/class-sst-abstract-cart.php', 'class-sst-order.php', 'class-sst-order-controller.php' ) as $file ) {
		require $plugin_root . '/includes/' . $file;
	}

	class Routing_Order extends SST_Order {
		public $errors = array();
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
			expect_same( true, count( Routing_Http::$v3 ) > 0, 'Saved certificates retain develop\'s shared V3 lookup in both settings' );
			if ( 'v1' === $version ) {
				expect_same( true, count( Routing_Http::$v1 ) > 0, 'V1 sites retain develop\'s legacy certificate fallback' );
			} else {
				expect_same( array(), Routing_Http::$v1, 'Explicit V3 certificate lookup does not fall back to V1' );
			}
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

	// Data Import was already a V3 integration on develop. V1 selection must
	// neither disable it nor send the imported order to AuthorizedWithCapture.
	foreach ( array( 'v1', 'v3' ) as $version ) {
		Routing_Http::reset( $version );
		SST_Settings::set( 'data_mover', true );
		Routing_Http::$body = array( 'orderId' => '42_0' );
		$address = new TaxCloud\Address( '1 Main St', '', 'Raleigh', 'NC', '27601' );
		$order = new Routing_Order( new WC_Order() );
		$package = $order->import_package( array( 'contents' => array(), 'fees' => array( (object) array( 'id' => 'handling', 'amount' => 5, 'tax' => 0.5 ) ),
			'shipping' => null, 'map' => array(), 'user' => array( 'ID' => 42 ), 'origin' => $address, 'destination' => $address, 'certificate' => null ) );
		expect_same( 'v3', $package['api_version'], 'Data Import packages belong to V3 in both settings' );
		$order->set_packages( array( $package ) );
		expect_same( true, $order->do_capture(), 'Existing V3 Data Import succeeds with either selection' );
		assert_route( 'v3', '', '/tax/connections/test-connection/orders', 'Existing Data Import remains V3' );
		$payload = json_decode( Routing_Http::$v3[0][2]['body'], true );
		expect_same( 0.5, $payload['lineItems'][0]['tax']['amount'], 'Data Import preserves the WooCommerce fee tax' );
		Routing_Http::$v3 = array();
		Routing_Http::$body = array();
		expect_same( true, $order->do_refund( new Routing_Fee_Refund() ), 'Existing Data Import refund succeeds in both settings' );
		assert_route( 'v3', '', '/tax/connections/test-connection/orders/refunds/42_0', 'Existing Data Import refunds remain V3' );
	}

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
