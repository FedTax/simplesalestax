<?php
/** DEV-10622: php tests/certificate-access-regression.php. No database or external requests. */

namespace TaxCloud {
	class Client {
		public function GetExemptCertificates( $request ) {
			\Certificate_Access_Test::$calls[] = array( 'list-v1', (string) $request->getCustomerID() );
			return array_map( array( 'SST_Certificates', 'build_v1_cert_from_v3' ), \Certificate_Access_Test::find( array( (string) $request->getCustomerID() ) ) );
		}
		public function AddExemptCertificate( $request ) { return \Certificate_Access_Test::create( 'v1', $request->getCustomerID() ); }
		public function DeleteExemptCertificate( $request ) { return \Certificate_Access_Test::delete( 'v1', $request->getCertificateID() ); }
	}
}

namespace TaxCloud_V3 {
	class Exemptions {
		public function get_certificates_for_customer_ids( $ids ) {
			\Certificate_Access_Test::$calls[] = array( 'list-v3', $ids );
			return \Certificate_Access_Test::$legacy_fallback ? array() : \Certificate_Access_Test::find( $ids );
		}
		public function create_certificate( $args ) { return array( 'certificateId' => \Certificate_Access_Test::create( 'v3', $args['customerId'] ) ); }
		public function delete_certificate( $id ) { return \Certificate_Access_Test::delete( 'v3', $id ); }
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'SST_SINGLE_PURCHASE_CERT_ID', 'single-purchase' );
	const OWNED_CERTIFICATE_ID = '11111111-2222-3333-4444-555555555555';
	const FOREIGN_CERTIFICATE_ID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
	const CREATED_CERTIFICATE_ID = '12345678-1234-1234-1234-123456789abc';

	class Certificate_Access_Test {
		public static $actor = 42;
		public static $version = 'v3';
		public static $calls = array();
		public static $transients = array();
		public static $meta = array();
		public static $remote = array();
		public static $localized = array();
		public static $logs = array();
		public static $customer_reads = 0;
		public static $legacy_fallback = false;
		public static $response;
		public static $assertions = 0;

		public static function certificate( $id, $identity ) {
			return array( 'certificateId' => $id, 'customerId' => $identity, 'customerName' => 'Private Customer',
				'customerBusinessType' => 'RetailTrade', 'reason' => 'Resale', 'reasonDescription' => 'Resale exemption',
				'singlePurchase' => false, 'states' => array( array( 'abbreviation' => 'MN' ) ),
				'address' => array( 'line1' => '1 Main St', 'city' => 'Minneapolis', 'state' => 'MN', 'zip' => '55401' ),
				'createdDate' => '2026-01-01T00:00:00Z' );
		}
		public static function reset( $version = 'v3', $actor = 42 ) {
			self::$version = $version;
			self::$actor = $actor;
			self::$calls = self::$transients = self::$meta = self::$localized = self::$logs = array();
			self::$customer_reads = 0;
			self::$legacy_fallback = false;
			self::$response = null;
			self::$remote = array( OWNED_CERTIFICATE_ID => self::certificate( OWNED_CERTIFICATE_ID, '42' ),
				FOREIGN_CERTIFICATE_ID => self::certificate( FOREIGN_CERTIFICATE_ID, 'bob@example.test' ) );
		}
		public static function find( $ids ) {
			return array_values( array_filter( self::$remote, function( $certificate ) use ( $ids ) {
				return in_array( $certificate['customerId'], $ids, true );
			} ) );
		}
		public static function create( $version, $id ) {
			self::$calls[] = array( 'create-' . $version, (string) $id );
			self::$remote[ CREATED_CERTIFICATE_ID ] = self::certificate( CREATED_CERTIFICATE_ID, (string) $id );
			return CREATED_CERTIFICATE_ID;
		}
		public static function delete( $version, $id ) {
			self::$calls[] = array( 'delete-' . $version, $id );
			unset( self::$remote[ $id ] );
			return true;
		}
	}

	class WP_User {
		public $ID;
		public $user_email;
		public $user_login;
		public function __construct( $id ) {
			$this->ID = in_array( (int) $id, array( 1, 2, 3, 4, 42, 43 ), true ) ? (int) $id : 0;
			$this->user_login = 42 === $this->ID ? 'alice' : 'bob';
			$this->user_email = $this->user_login . '@example.test';
		}
	}
	class WC_Customer {
		public function __construct( $id ) { ++Certificate_Access_Test::$customer_reads; }
		public function __call( $method, $args ) { return ''; }
	}
	class SST_Settings {
		public static function get( $key, $default = null ) {
			$settings = array( 'api_version' => Certificate_Access_Test::$version, 'tc_id' => 'test', 'tc_key' => 'test', 'show_exempt' => 'true' );
			return $settings[ $key ] ?? $default;
		}
	}
	class SST_Logger {
		public static function add() {}
		public static function debug() {}
		public static function order_log( ...$args ) { Certificate_Access_Test::$logs[] = $args; }
	}
	class Certificate_Ajax_Response extends \Error {}
	class Certificate_UI_Plugin {
		public function path( $path ) {
			// Render the real order panel; the other templates have no role in this check.
			return 'includes/views/html-meta-box.php' === $path ? dirname( __DIR__ ) . '/' . $path : __DIR__ . '/unused-template';
		}
	}
	class Certificate_UI_Order {
		public $customer_id;
		public $detail_reads = 0;
		public function __construct( $customer_id ) { $this->customer_id = $customer_id; }
		public function get_user_id() { return $this->customer_id; }
		public function get_customer_id() { return $this->customer_id; }
		public function get_id() { return 123; }
		public function get_taxcloud_status() { return 'pending'; }
		public function get_certificate_id() { return OWNED_CERTIFICATE_ID; }
		public function get_single_purchase_certificate() {
			++$this->detail_reads;
			return SST_Certificates::build_v1_cert_from_v3( Certificate_Access_Test::certificate( OWNED_CERTIFICATE_ID, (string) $this->customer_id ) );
		}
	}

	function get_current_user_id() { return Certificate_Access_Test::$actor; }
	function is_user_logged_in() { return 0 !== get_current_user_id(); }
	function get_user_by( $field, $id ) { $user = new WP_User( $id ); return $user->ID ? $user : false; }
	function wp_get_current_user() { return new WP_User( get_current_user_id() ); }
	function current_user_can( $cap, $target = 0 ) {
		$actor = get_current_user_id();
		if ( 'manage_options' === $cap ) { return 1 === $actor; }
		if ( 'manage_woocommerce' === $cap ) { return in_array( $actor, array( 2, 4 ), true ); }
		if ( 'edit_shop_order' === $cap ) { return in_array( $actor, array( 1, 2, 3, 4 ), true ); }
		return 'edit_user' === $cap && ( 1 === $actor || 3 === $actor || ( 2 === $actor && 1 !== $target ) || $actor === $target );
	}
	function get_transient( $key ) { return Certificate_Access_Test::$transients[ $key ] ?? false; }
	function set_transient( $key, $value, $ttl = 0 ) { Certificate_Access_Test::$transients[ $key ] = $value; }
	function delete_transient( $key ) { unset( Certificate_Access_Test::$transients[ $key ] ); }
	function get_user_meta( $id, $key, $single = true ) { return Certificate_Access_Test::$meta[ $id ][ $key ] ?? ''; }
	function update_user_meta( $id, $key, $value ) { Certificate_Access_Test::$meta[ $id ][ $key ] = $value; }
	function delete_user_meta( $id, $key ) { unset( Certificate_Access_Test::$meta[ $id ][ $key ] ); }
	function __( $message ) { return $message; }
	function esc_html__( $message ) { return $message; }
	function esc_html_e( $message ) { echo $message; }
	function esc_html( $value ) { return $value; }
	function absint( $value ) { return abs( (int) $value ); }
	function wp_unslash( $value ) { return $value; }
	function sanitize_text_field( $value ) { return trim( (string) $value ); }
	function wp_json_encode( $value ) { return json_encode( $value ); }
	function wp_date( $format, $timestamp = null ) { return date( $format, $timestamp ?? time() ); }
	function get_option( $key, $default = null ) { return $default; }
	function wc_get_order( $id ) { return $GLOBALS['debug_order']; }
	function is_wp_error() { return false; }
	function add_action() {}
	function add_filter() {}
	function apply_filters( $hook, $value ) { return $value; }
	function is_admin() { return true; }
	function SST() { return new Certificate_UI_Plugin(); }
	function wc_help_tip() { return ''; }
	function admin_url( $path ) { return '/wp-admin/' . $path; }
	function add_query_arg( ...$args ) { return end( $args ); }
	function wp_enqueue_script() {}
	function wp_enqueue_style() {}
	function wp_localize_script( $handle, $name, $data ) { Certificate_Access_Test::$localized[ $name ] = $data; }
	function wp_create_nonce( $action ) { return $action; }
	function wp_verify_nonce( $nonce, $action ) { return $nonce === $action; }
	function check_ajax_referer( $action, $key = '_ajax_nonce' ) {
		if ( ! wp_verify_nonce( $_REQUEST[ $key ] ?? '', $action ) ) { wp_send_json_error( 'Invalid nonce.', 403 ); }
	}
	function wp_send_json_error( $data, $status = 200 ) {
		Certificate_Access_Test::$response = array( 'success' => false, 'data' => $data, 'status' => $status );
		throw new Certificate_Ajax_Response();
	}
	function wp_send_json_success( $data ) {
		// Success is the final statement in these handlers; returning avoids their
		// Throwable handlers catching a simulated wp_die exception as an API error.
		Certificate_Access_Test::$response = array( 'success' => true, 'data' => $data, 'status' => 200 );
	}

	require dirname( __DIR__ ) . '/includes/vendor/autoload.php';
	require dirname( __DIR__ ) . '/includes/sst-functions.php';
	require dirname( __DIR__ ) . '/includes/class-sst-certificates.php';
	require dirname( __DIR__ ) . '/includes/class-sst-ajax.php';

	function expect_same( $expected, $actual, $message ) {
		++Certificate_Access_Test::$assertions;
		if ( $expected !== $actual ) { throw new \RuntimeException( $message . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) ); }
	}
	function request_certificate( $method, $target = 42, $valid_nonce = true, $target_key = 'user_id' ) {
		$_POST = array( 'nonce' => $valid_nonce ? 'sst_' . $method : 'invalid', 'certificate_id' => OWNED_CERTIFICATE_ID,
			'certificate' => array( 'ExemptState' => 'MN', 'TaxType' => 'FEIN', 'IDNumber' => 'TEST-ID', 'StateOfIssue' => '',
				'PurchaserBusinessType' => 'RetailTrade', 'PurchaserBusinessTypeOtherValue' => '',
				'PurchaserExemptionReason' => 'Resale', 'PurchaserExemptionReasonOtherValue' => 'Resale exemption' ),
			'address' => array( 'first_name' => 'Test', 'last_name' => 'Buyer', 'address_1' => '1 Main St', 'address_2' => '',
				'city' => 'Minneapolis', 'state' => 'MN', 'postcode' => '55401' ) );
		if ( null !== $target ) { $_POST[ $target_key ] = $target; }
		$_REQUEST = $_POST;
		Certificate_Access_Test::$response = null;
		try { SST_Ajax::$method(); } catch ( Certificate_Ajax_Response $response ) {}
		return Certificate_Access_Test::$response;
	}

	$methods = array( 'add_certificate', 'get_certificates', 'refresh_certificates', 'delete_certificate' );
	foreach ( array( 'v1', 'v3' ) as $version ) {
		foreach ( array( array( 42, 42, true ), array( 1, 42, true ), array( 2, 42, true ), array( 43, 42, false ),
			array( 3, 42, false ), array( 4, 42, false ), array( 2, 1, false ), array( 1, 999, false ), array( 0, 42, false ), array( 0, 0, false ) ) as $case ) {
			list( $actor, $target, $allowed ) = $case;
			foreach ( $methods as $method ) {
				Certificate_Access_Test::reset( $version, $actor );
				SST_Certificates::set_certificates( 42, array( OWNED_CERTIFICATE_ID => SST_Certificates::build_v1_cert_from_v3( Certificate_Access_Test::$remote[ OWNED_CERTIFICATE_ID ] ) ) );
				$cache = Certificate_Access_Test::$transients;
				$remote = Certificate_Access_Test::$remote;
				$response = request_certificate( $method, $target, true, 'get_certificates' === $method ? 'customerId' : 'user_id' );
				$label = "$version $method actor=$actor target=$target";
				expect_same( $allowed, $response['success'], $label );
				if ( ! $allowed ) {
					expect_same( array( 'success' => false, 'data' => 'Unauthorized.', 'status' => 403 ), $response, "$label: no certificate data" );
					expect_same( array(), Certificate_Access_Test::$calls, "$label: no API calls" );
					expect_same( $cache, Certificate_Access_Test::$transients, "$label: no cache changes" );
					expect_same( $remote, Certificate_Access_Test::$remote, "$label: no remote changes" );
				} else {
					$certificates = 'get_certificates' === $method ? $response['data'] : $response['data']['certificates'];
					expect_same( 'delete_certificate' !== $method, isset( $certificates[ OWNED_CERTIFICATE_ID ] ), "$label: successful response reflects the customer's records" );
				}
			}
		}
		foreach ( $methods as $method ) {
			foreach ( array( 42, 0, null ) as $target ) {
				Certificate_Access_Test::reset( $version );
				$response = request_certificate( $method, $target );
				expect_same( true, $response['success'], "$version $method: own, zero and omitted targets work" );
				if ( 'add_certificate' === $method ) {
					expect_same( array( 'create-' . $version, '42' ), Certificate_Access_Test::$calls[0], 'Creation targets the effective owner' );
				}
			}
			Certificate_Access_Test::reset( $version );
			request_certificate( $method, 42, false );
			expect_same( array(), Certificate_Access_Test::$calls, "$version $method: invalid nonce never reaches API" );
			expect_same( false, Certificate_Access_Test::$response['success'] ?? false, "$version $method: invalid nonce never succeeds" );
		}

		// Exercise V3 discovery and the real V1 fallback with a certificate stored
		// only under Bob's email, then try to adopt it through Alice's billing email.
		foreach ( 'v1' === $version ? array( false, true ) : array( false ) as $fallback ) {
			Certificate_Access_Test::reset( $version );
			Certificate_Access_Test::$legacy_fallback = $fallback;
			$before = array_keys( SST_Certificates::get_certificates( 42 ) );
			Certificate_Access_Test::$meta[42]['billing_email'] = 'bob@example.test';
			Certificate_Access_Test::$transients['_sst_certificates_42'] = wp_json_encode( array(
				FOREIGN_CERTIFICATE_ID => SST_Certificates::build_v1_cert_from_v3( Certificate_Access_Test::$remote[ FOREIGN_CERTIFICATE_ID ] ),
			) );
			SST_Certificates::delete_certificates( 42 );
			expect_same( false, SST_Certificates::has_cached_certificates( 42 ), 'Old cache is not recognized after upgrade' );
			expect_same( array(), SST_Certificates::get_certificates( 42, false ), 'Cache-only rendering ignores old foreign certificates' );
			expect_same( $before, array_keys( SST_Certificates::get_certificates( 42 ) ), "$version: billing-email change cannot change certificate list" );
			expect_same( array( OWNED_CERTIFICATE_ID ), $before, 'Only the account owner certificate is returned' );
			expect_same( array( 'list-v3', array( '42', 'alice@example.test', 'alice', 'customer-42' ) ), Certificate_Access_Test::$calls[0], 'All supported account lookup identities remain available' );
			if ( $fallback ) {
				$lookups = array_values( array_filter( Certificate_Access_Test::$calls, function( $call ) { return 'list-v1' === $call[0]; } ) );
				expect_same( array( '42', 'alice@example.test', 'alice', 'customer-42' ), array_column( array_slice( $lookups, 0, 4 ), 1 ), 'Legacy fallback uses the same safe identities' );
			}
			expect_same( '', SST_Certificates::get_checkout_certificate_id( FOREIGN_CERTIFICATE_ID ), 'A saved foreign checkout selection is rejected' );
			$calls = Certificate_Access_Test::$calls;
			try { SST_Certificates::delete_certificate( FOREIGN_CERTIFICATE_ID, 42 ); throw new \RuntimeException( 'Foreign deletion succeeded' ); }
			catch ( \Exception $error ) { expect_same( 'Unauthorized', $error->getMessage(), 'Deletion still checks certificate membership' ); }
			expect_same( $calls, Certificate_Access_Test::$calls, 'Rejected deletion sends no remote delete' );
			// Account email still discovers the email-linked record for its actual owner.
			expect_same( array( FOREIGN_CERTIFICATE_ID ), array_keys( SST_Certificates::get_certificates( 43 ) ), 'Account-email compatibility remains supported' );
		}
		Certificate_Access_Test::reset( $version, 3 );
		try { SST_Certificates::delete_certificate( OWNED_CERTIFICATE_ID, 42 ); throw new \RuntimeException( 'Deletion without management capability succeeded' ); }
		catch ( \Exception $error ) { expect_same( 'Unauthorized', $error->getMessage(), 'Internal deletion also requires the shared access rule' ); }
		expect_same( array(), Certificate_Access_Test::$calls, 'Unauthorized internal deletion never fetches or deletes certificates' );
	}

	// Render the real order panel as well as certificate tables: no private data
	// may bypass the endpoint checks through localized script variables.
	foreach ( array( array( 43, 42, false ), array( 3, 42, false ), array( 2, 1, false ), array( 0, 42, false ),
		array( 1, 42, true ), array( 2, 42, true ), array( 42, 42, true ), array( 3, 0, false ), array( 1, 0, true ) ) as $case ) {
		list( $actor, $target, $allowed ) = $case;
		Certificate_Access_Test::reset( 'v3', $actor );
		$order = new Certificate_UI_Order( $target );
		ob_start();
		sst_render_tax_meta_box( $order );
		$html = ob_get_clean();
		expect_same( $allowed, isset( Certificate_Access_Test::$localized['SSTMetaBox'] ), 'Order details are localized only for authorized viewers' );
		expect_same( $allowed ? 1 : 0, $order->detail_reads, 'Unauthorized order viewers never read certificate details' );
		expect_same( $allowed, false !== strpos( $html, 'id="exempt-cert-select"' ), 'Order certificate controls follow access rules' );
		if ( $target ) {
			Certificate_Access_Test::$localized = array();
			sst_render_certificate_table( $target );
			expect_same( $allowed, isset( Certificate_Access_Test::$localized['SST_Certificate_Table_Data'] ), 'Table localization follows access rules' );
			expect_same( $allowed ? 1 : 0, Certificate_Access_Test::$customer_reads, 'Unauthorized table viewers never read billing details' );
		}
	}

	foreach ( array( array( 43, 42 ), array( 3, 42 ), array( 2, 1 ), array( 3, 0 ), array( 0, 42 ), array( 42, 42 ) ) as $case ) {
		list( $actor, $target ) = $case;
		Certificate_Access_Test::reset( 'v3', $actor );
		$debug_order = new Certificate_UI_Order( $target );
		$_POST = array( 'order_id' => 123 );
		$_REQUEST = array( '_ajax_nonce' => 'sst-debug-order-nonce' );
		try { SST_Ajax::get_order_log(); } catch ( Certificate_Ajax_Response $response ) {}
		expect_same( 403, Certificate_Access_Test::$response['status'], 'Order debug logging requires certificate and order access' );
		expect_same( array(), Certificate_Access_Test::$logs, 'Unauthorized order debug requests cannot write certificate details into logs' );
	}

	// Internal order processing must remain usable without an interactive actor.
	Certificate_Access_Test::reset( 'v3', 0 );
	expect_same( array( OWNED_CERTIFICATE_ID ), array_keys( SST_Certificates::get_certificates( 42 ) ), 'Background reads still work' );
	$certificate = SST_Certificates::build_v1_cert_from_v3( Certificate_Access_Test::$remote[ OWNED_CERTIFICATE_ID ] );
	expect_same( CREATED_CERTIFICATE_ID, SST_Certificates::add_certificate_object( $certificate, 42 ), 'Background creation still works' );

	echo 'Certificate access security regression checks passed (' . Certificate_Access_Test::$assertions . " assertions).\n";
}
