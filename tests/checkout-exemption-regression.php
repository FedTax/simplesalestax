<?php
/** DEV-10620: php tests/checkout-exemption-regression.php. No external requests. */

namespace TaxCloud {
	class Client {
		public function AddExemptCertificate( $request ) { return \Checkout_Exemption_API::create(); }
		public function GetExemptCertificates( $request ) { return array(); }
	}
}

namespace TaxCloud_V3 {
	class Exemptions {
		public function create_certificate( $args ) { return array( 'certificateId' => \Checkout_Exemption_API::create() ); }
		public function get_certificates_for_customer_ids( $ids ) { return array(); }
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	const OWNED_CERTIFICATE_ID = '11111111-2222-3333-4444-555555555555';
	const OTHER_CERTIFICATE_ID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
	const CREATED_CERTIFICATE_ID = '12345678-1234-1234-1234-123456789abc';

	$test_user_id = 19151;
	$test_settings = array( 'tc_id' => 'test-login', 'tc_key' => 'test-key', 'show_exempt' => 'true' );
	$test_transients = array();
	$test_assertions = 0;

	function add_action() {}
	function add_filter() {}
	function apply_filters( $hook, $value ) { return $value; }
	function __( $message ) { return $message; }
	function esc_html( $text ) { return $text; }
	function sanitize_text_field( $text ) { return is_scalar( $text ) ? trim( (string) $text ) : ''; }
	function wp_unslash( $value ) { return $value; }
	function wp_json_encode( $value ) { return json_encode( $value ); }
	function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, $args ); }
	function wp_date( $format, $timestamp ) { return date( $format, $timestamp ); }
	function current_time( $format ) { return '20261105'; }
	function sst_storefront_active() { return false; }
	function get_current_user_id() { global $test_user_id; return $test_user_id; }
	function is_user_logged_in() { return 0 !== get_current_user_id(); }
	function wp_get_current_user() { return (object) array( 'ID' => get_current_user_id(), 'roles' => array( 'customer' ), 'user_email' => 'test@example.test', 'user_login' => 'test' ); }
	function get_user_by() { return wp_get_current_user(); }
	function get_user_meta() { return ''; }
	function update_user_meta() {}
	function get_transient( $key ) { global $test_transients; return $test_transients[ $key ] ?? false; }
	function set_transient( $key, $value ) { global $test_transients; $test_transients[ $key ] = $value; }
	function delete_transient( $key ) { global $test_transients; unset( $test_transients[ $key ] ); }
	function WC() { global $test_woocommerce; return $test_woocommerce; }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }

	class WP_Error {
		private $errors = array();
		public function add( $code, $message ) { $this->errors[ $code ] = $message; }
		public function has_errors() { return ! empty( $this->errors ); }
		public function get_error_message() { return reset( $this->errors ) ?: ''; }
	}

	class SST_Settings {
		public static function get( $key, $default = null ) { global $test_settings; return $test_settings[ $key ] ?? $default; }
	}
	class SST_Logger {
		public static function add() {}
		public static function debug() {}
	}
	class SST_Product {
		public static function get_tic() { return '90505'; }
		public static function is_gun_or_ammo_tic( $tic ) { return '90505' === $tic; }
	}
	class Checkout_Exemption_API {
		public static $result = CREATED_CERTIFICATE_ID;
		public static $calls = 0;
		public static function create() {
			++self::$calls;
			if ( self::$result instanceof \Throwable ) { throw self::$result; }
			return self::$result;
		}
	}
	class Checkout_Exemption_Session {
		private $data = array();
		public function get( $key, $default = null ) { return $this->data[ $key ] ?? $default; }
		public function set( $key, $value ) { $this->data[ $key ] = $value; }
	}

	// Keep the real checkout/certificate code; replace the WooCommerce order boundary.
	class SST_Order {
		private $order;
		public function __construct( $order ) { $this->order = $order; }
		public function __call( $method, $args ) { return $this->order->$method( ...$args ); }
	}
	class Checkout_Exemption_Order {
		public $certificate_id = '';
		public $certificate;
		public $packages = array();
		public $lookup_success = true;
		public $lookup_calls = 0;
		public $saved = false;
		public function get_certificate_id() { return $this->certificate_id; }
		public function set_certificate_id( $id ) { $this->certificate_id = $id; }
		public function set_single_purchase_certificate( $certificate ) { $this->certificate = $certificate; $this->certificate_id = 'single-purchase'; }
		public function set_packages( $packages ) { $this->packages = $packages; }
		public function get_created_via() { return 'checkout'; }
		public function calculate_taxes() { ++$this->lookup_calls; return $this->lookup_success; }
		public function calculate_totals( $calculate_taxes ) { $this->saved = true; }
		public function save() { $this->saved = true; }
		public function get_id() { return 123; }
		public function __call( $method, $args ) {
			$billing = array( 'first_name' => 'Test', 'last_name' => 'Buyer', 'address_1' => '1 Main St', 'address_2' => '', 'city' => 'Denver', 'state' => 'CO', 'postcode' => '80202' );
			if ( 0 === strpos( $method, 'get_billing_' ) ) { return $billing[ substr( $method, 12 ) ]; }
			throw new \RuntimeException( 'Unexpected order method: ' . $method );
		}
	}

	require dirname( __DIR__ ) . '/includes/vendor/autoload.php';
	require dirname( __DIR__ ) . '/includes/sst-functions.php';
	require dirname( __DIR__ ) . '/includes/class-sst-addresses.php';
	require dirname( __DIR__ ) . '/includes/class-sst-certificates.php';
	require dirname( __DIR__ ) . '/includes/abstracts/class-sst-abstract-cart.php';
	require dirname( __DIR__ ) . '/includes/frontend/class-sst-cart-proxy.php';
	require dirname( __DIR__ ) . '/includes/frontend/class-sst-checkout.php';
	require dirname( __DIR__ ) . '/includes/class-sst-blocks.php';

	class Checkout_Exemption_Test extends SST_Checkout {
		public $tax = 0.0;
		public $lookup_calls = 0;
		public $default_id = '';
		public $state = 'CO';
		public function __construct() {}
		protected function get_default_certificate_id() { return $this->default_id; }
		protected function create_packages() { return array( array( 'destination' => new \TaxCloud\Address( '1 Main St', '', 'Denver', $this->state, '80202' ) ) ); }
		protected function do_lookup() {
			++$this->lookup_calls;
			$packages = array( array( 'response' => array( 'test-cart' => array( 10.0 ) ), 'cart_items' => array( array( 'type' => 'line_item', 'cart_id' => 123 ) ) ) );
			$this->set_packages( $packages );
			return $packages;
		}
		protected function reset_taxes() { $this->tax = 0.0; }
		protected function update_taxes() {}
		protected function set_product_tax( $id, $tax ) { $this->tax += $tax; }
		public function excise_tax() { return $this->calculate_excise_tax( array( array( 'product_id' => 123, 'line_total' => 100 ) ), 'CO' ); }
		public function validate( $data ) { $errors = new WP_Error(); $this->validate_exemption_certificate( $data, $errors ); return $errors; }
		public function process( $data, $order ) { $this->process_checkout( $data, $order ); }
	}

	function expect_same( $expected, $actual, $message ) {
		global $test_assertions;
		++$test_assertions;
		if ( $expected !== $actual ) { throw new \RuntimeException( $message . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) ); }
	}
	function expect_failure( $callback, $message ) {
		$failed = false;
		try { $callback(); } catch ( \Exception $ex ) { $failed = true; }
		expect_same( true, $failed, $message );
	}
	function reset_checkout( $version ) {
		global $test_woocommerce, $test_user_id, $test_settings, $test_transients;
		$_POST = array();
		$test_user_id = 19151;
		$test_settings = array( 'api_version' => $version, 'tc_id' => 'test-login', 'tc_key' => 'test-key', 'show_exempt' => 'true' );
		$test_woocommerce = (object) array( 'session' => new Checkout_Exemption_Session(), 'cart' => new \stdClass() );
		$certificate = SST_Certificates::build_certificate( certificate_fields(), purchaser_fields() )->jsonSerialize();
		$certificate['CertificateID'] = OWNED_CERTIFICATE_ID;
		$test_transients = array( '_sst_certificates_19151' => wp_json_encode( array( OWNED_CERTIFICATE_ID => $certificate ) ) );
		$certificate['CertificateID'] = OTHER_CERTIFICATE_ID;
		$test_transients['_sst_certificates_19152'] = wp_json_encode( array( OTHER_CERTIFICATE_ID => $certificate ) );
		Checkout_Exemption_API::$result = CREATED_CERTIFICATE_ID;
		Checkout_Exemption_API::$calls = 0;
		return new Checkout_Exemption_Test();
	}
	function certificate_fields() {
		return array( 'ExemptState' => 'CO', 'TaxType' => 'FEIN', 'StateOfIssue' => '', 'IDNumber' => 'TEST-ID',
			'PurchaserBusinessType' => 'RetailTrade', 'PurchaserBusinessTypeOtherValue' => '',
			'PurchaserExemptionReason' => 'Resale', 'PurchaserExemptionReasonOtherValue' => 'Resale exemption' );
	}
	function purchaser_fields() {
		return array( 'first_name' => 'Test', 'last_name' => 'Buyer', 'address_1' => '1 Main St', 'address_2' => '', 'city' => 'Denver', 'state' => 'CO', 'postcode' => '80202' );
	}

	foreach ( array( 'v1', 'v3' ) as $version ) {
		// Guests cannot set exemptions, even if the form setting is enabled.
		foreach ( array( 'false', 'true' ) as $show_exempt ) {
			$checkout = reset_checkout( $version );
			$test_user_id = 0;
			$test_settings['show_exempt'] = $show_exempt;
			$_POST = array( 'certificate_id' => 'new', 'certificate' => array( 'ExemptState' => 'CO' ) );
			$checkout->init_certificate_id();
			expect_same( '', $checkout->get_certificate_id(), "$version: guest cannot select new certificate ($show_exempt)" );
			$checkout->calculate_taxes();
			expect_same( 10.0, $checkout->tax, "$version: guest order retains sales tax" );
			expect_same( 6.5, $checkout->excise_tax(), "$version: guest order retains excise tax" );
			$order = new Checkout_Exemption_Order();
			$checkout->process( $_POST, $order );
			expect_same( '', $order->certificate_id, "$version: guest order has no certificate" );
			expect_same( 0, Checkout_Exemption_API::$calls, "$version: guest cannot create certificate" );
		}

		foreach ( array( 'disabled', 'restricted', 'data-import', 'logout' ) as $context ) {
			$checkout = reset_checkout( $version );
			WC()->session->set( 'sst_certificate_id', 'new' );
			WC()->session->set( 'sst_packages', array( 'stale-exempt-package' ) );
			if ( 'disabled' === $context ) { $test_settings['show_exempt'] = 'false'; }
			if ( 'restricted' === $context ) { $test_settings['restrict_exempt'] = 'yes'; }
			if ( 'data-import' === $context ) { $test_settings['data_mover'] = true; }
			if ( 'logout' === $context ) { $test_user_id = 0; }
			$checkout->init_certificate_id();
			expect_same( '', WC()->session->get( 'sst_certificate_id' ), "$version: $context clears stale selection" );
			expect_same( array(), WC()->session->get( 'sst_packages' ), "$version: $context clears exempt packages" );
		}

		foreach ( array( 'missing', OTHER_CERTIFICATE_ID, array( 'new' ) ) as $invalid_id ) {
			$checkout = reset_checkout( $version );
			$_POST = array( 'certificate_id' => $invalid_id );
			$checkout->init_certificate_id();
			expect_same( '', $checkout->get_certificate_id(), "$version: invalid posted selection is ignored" );
			WC()->session->set( 'sst_certificate_id', $invalid_id );
			expect_same( null, $checkout->get_certificate(), "$version: stale invalid selection cannot produce a certificate" );
			expect_same( 6.5, $checkout->excise_tax(), "$version: invalid selection cannot suppress excise" );
			SST_Blocks::instance()->handle_cart_update( array( 'action' => 'set_certificate_id', 'certificate_id' => $invalid_id ) );
			expect_same( '', $checkout->get_certificate_id(), "$version: Blocks rejects invalid selection" );
		}

		// DEV-10621: even a matching cached certificate/package must not make a
		// serialized object or a query-string payload into a valid certificate ID.
		foreach ( array( 'O:8:"stdClass":0:{}', OWNED_CERTIFICATE_ID . '&order_id=999', OWNED_CERTIFICATE_ID . "\n", 'not-a-guid' ) as $payload ) {
			foreach ( array( 'classic', 'blocks' ) as $path ) {
				$checkout = reset_checkout( $version );
				$certificates = json_decode( $test_transients['_sst_certificates_19151'], true );
				$certificate = $certificates[ OWNED_CERTIFICATE_ID ];
				$certificate['CertificateID'] = $payload;
				$certificates[ $payload ] = $certificate;
				$test_transients['_sst_certificates_19151'] = wp_json_encode( $certificates );
				if ( 'classic' === $path ) {
					$_POST = array( 'post_data' => http_build_query( array( 'certificate_id' => $payload ) ) );
					$checkout->init_certificate_id();
				} else {
					SST_Blocks::instance()->handle_cart_update( array( 'action' => 'set_certificate_id', 'certificate_id' => $payload ) );
				}
				expect_same( '', WC()->session->get( 'sst_certificate_id', '' ), "$version $path: malformed cached ID is rejected at selection" );
				WC()->session->set( 'sst_certificate_id', $payload );
				WC()->session->set( 'sst_packages', array( array( 'certificate_id' => $payload ) ) );
				$order = new Checkout_Exemption_Order();
				if ( 'classic' === $path ) {
					$_POST = array( 'certificate_id' => $payload );
					$checkout->handle_legacy_checkout( $order );
				} else {
					$request = new class( $payload ) {
						private $id;
						public function __construct( $id ) { $this->id = $id; }
						public function get_param( $key ) { return array( 'simple-sales-tax' => array( 'certificate_id' => $this->id ) ); }
					};
					$checkout->handle_checkout( $order, $request );
				}
				expect_same( '', $order->certificate_id, "$version $path: malformed session ID cannot be persisted" );
				expect_same( array(), $order->packages, "$version $path: matching malformed packages are discarded" );
				expect_same( 0, Checkout_Exemption_API::$calls, "$version $path: malformed ID never registers a certificate" );
			}
		}

		foreach ( array( 'none', '' ) as $cleared_id ) {
			$checkout = reset_checkout( $version );
			$checkout->default_id = OWNED_CERTIFICATE_ID;
			WC()->session->set( 'sst_certificate_id', OWNED_CERTIFICATE_ID );
			WC()->session->set( 'sst_packages', array( 'stale-exempt-package' ) );
			$_POST = array( 'certificate_id' => $cleared_id );
			$checkout->init_certificate_id();
			$_POST = array();
			$checkout->init_certificate_id();
			expect_same( '', $checkout->get_certificate_id(), "$version: explicit clearing survives requests without a selection" );
			expect_same( array(), WC()->session->get( 'sst_packages' ), "$version: clearing removes exempt packages" );
		}

		$checkout = reset_checkout( $version );
		$checkout->default_id = OWNED_CERTIFICATE_ID;
		$checkout->init_certificate_id();
		expect_same( OWNED_CERTIFICATE_ID, $checkout->get_certificate_id(), "$version: authorized default certificate remains supported" );
		WC()->session->set( 'sst_packages', array( array( 'certificate_id' => OWNED_CERTIFICATE_ID ) ) );
		$order = new Checkout_Exemption_Order();
		$checkout->process( array(), $order );
		expect_same( OWNED_CERTIFICATE_ID, $order->certificate_id, "$version: saved selection persists without final posted ID" );

		$checkout = reset_checkout( $version );
		$data = json_decode( $test_transients['_sst_certificates_19151'], true );
		$data[ OWNED_CERTIFICATE_ID ]['Detail']['ExemptStates'][0]['StateAbbr'] = 'NC';
		$test_transients['_sst_certificates_19151'] = wp_json_encode( $data );
		WC()->session->set( 'sst_certificate_id', OWNED_CERTIFICATE_ID );
		expect_same( 6.5, $checkout->excise_tax(), "$version: saved NC certificate cannot suppress CO excise" );

		$checkout = reset_checkout( $version );
		$_POST = array( 'post_data' => http_build_query( array( 'certificate_id' => OWNED_CERTIFICATE_ID ) ) );
		$checkout->init_certificate_id();
		expect_same( OWNED_CERTIFICATE_ID, $checkout->get_certificate()->getCertificateID(), "$version: owned certificate accepted from classic cart refresh" );
		expect_same( 0, $checkout->excise_tax(), "$version: owned CO certificate exempts excise" );
		$test_user_id = 19153;
		$test_transients['_sst_certificates_19153'] = '[]';
		expect_same( null, $checkout->get_certificate(), "$version: selection cannot transfer to a different customer" );

		$checkout = reset_checkout( $version );
		$test_user_id = 0;
		SST_Blocks::instance()->handle_cart_update( array( 'action' => 'set_certificate_id', 'certificate_id' => 'new' ) );
		expect_same( '', $checkout->get_certificate_id(), "$version: Blocks rejects guest selections" );
		$test_user_id = 19151;
		$test_settings['show_exempt'] = 'false';
		SST_Blocks::instance()->handle_cart_update( array( 'action' => 'set_certificate_id', 'certificate_id' => 'new' ) );
		expect_same( '', $checkout->get_certificate_id(), "$version: Blocks rejects disabled exemptions" );

		$checkout = reset_checkout( $version );
		$order = new Checkout_Exemption_Order();
		$checkout->process( array( 'certificate_id' => 'new', 'certificate' => certificate_fields() ), $order );
		expect_same( 0, Checkout_Exemption_API::$calls, "$version: final request cannot override an empty session selection" );

		// Both classic and Blocks must validate and create from the session selection.
		foreach ( array( 'classic', 'blocks' ) as $path ) {
			$checkout = reset_checkout( $version );
			if ( 'classic' === $path ) {
				$_POST = array( 'post_data' => http_build_query( array( 'certificate_id' => 'new', 'certificate' => array( 'ExemptState' => 'CO' ) ) ) );
				$checkout->init_certificate_id();
			} else {
				SST_Blocks::instance()->handle_cart_update( array( 'action' => 'set_certificate_id', 'certificate_id' => 'new' ) );
				SST_Blocks::instance()->handle_cart_update( array( 'action' => 'set_new_certificate_state', 'state' => 'CO' ) );
			}
			$checkout->calculate_taxes();
			expect_same( 0.0, $checkout->tax, "$version $path: eligible new certificate previews zero tax" );
			expect_same( 0, $checkout->excise_tax(), "$version $path: eligible new CO certificate previews zero excise" );
			expect_same( null, $checkout->get_certificate(), "$version $path: new marker is never a saved certificate" );
			$_POST = array( 'certificate' => array( 'ExemptState' => 'CO' ) );
			$checkout->init_certificate_id();
			expect_same( true, $checkout->validate( $_POST )->has_errors(), "$version $path: omitting final selection cannot skip required fields" );
			expect_failure( function () use ( $checkout ) { $checkout->process( $_POST, new Checkout_Exemption_Order() ); }, "$version $path: incomplete certificate rejects checkout" );
			expect_same( 0, Checkout_Exemption_API::$calls, "$version $path: incomplete data is never registered" );
			expect_same( true, $checkout->validate( array() )->has_errors(), "$version $path: omitting all certificate fields is rejected" );
			$data = array( 'certificate' => certificate_fields() );
			expect_same( false, $checkout->validate( $data )->has_errors(), "$version $path: complete certificate accepted without posted selection" );
			$order = new Checkout_Exemption_Order();
			$checkout->process( $data, $order );
			expect_same( CREATED_CERTIFICATE_ID, $order->certificate_id, "$version $path: session selection registers and saves certificate" );
			expect_same( 1, Checkout_Exemption_API::$calls, "$version $path: certificate registered once" );
		}

		foreach ( array( '', 'O:8:"stdClass":0:{}', new \RuntimeException( 'TaxCloud unavailable' ) ) as $failure ) {
			$checkout = reset_checkout( $version );
			WC()->session->set( 'sst_certificate_id', 'new' );
			Checkout_Exemption_API::$result = $failure;
			$order = new Checkout_Exemption_Order();
			expect_failure( function () use ( $checkout, $order ) { $checkout->process( array( 'certificate' => certificate_fields() ), $order ); }, "$version: registration failure rejects checkout" );
			expect_same( false, $order->saved, "$version: failed registration cannot finish order" );
		}

		$checkout = reset_checkout( $version );
		WC()->session->set( 'sst_certificate_id', 'new' );
		WC()->session->set( 'sst_new_certificate_state', 'NC' );
		expect_same( 6.5, $checkout->excise_tax(), "$version: new NC certificate cannot suppress CO excise" );

		$checkout = reset_checkout( $version );
		WC()->session->set( 'sst_certificate_id', 'new' );
		$order = new Checkout_Exemption_Order();
		$order->lookup_success = false;
		expect_failure( function () use ( $checkout, $order ) { $checkout->process( array( 'certificate' => certificate_fields() ), $order ); }, "$version: failed exempt lookup rejects checkout" );

		$checkout = reset_checkout( $version );
		WC()->session->set( 'sst_certificate_id', 'new' );
		$order = new Checkout_Exemption_Order();
		$checkout->process( array( 'certificate' => array_merge( certificate_fields(), array( 'SinglePurchase' => true ) ) ), $order );
		expect_same( 'single-purchase', $order->certificate_id, "$version: single-purchase certificate saved from session selection" );
		expect_same( 1, $order->lookup_calls, "$version: single-purchase exemption is recalculated" );
	}

	echo "Checkout exemption security regression checks passed ($test_assertions assertions).\n";
}
