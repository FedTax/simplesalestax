<?php
/** DEV-10623: php tests/rate-limit-regression.php. No database or external requests. */

namespace Automattic\WooCommerce\StoreApi\Exceptions {
	class RouteException extends \Exception {
		public $status;
		public function __construct( $code, $message, $status ) { parent::__construct( $message ); $this->status = $status; }
	}
}
namespace TaxCloud {
	class Client {
		public function Lookup( $request ) {
			++\Rate_Limit_API::$calls;
			$taxes = array();
			foreach ( $request->getCartItems() as $item ) { $taxes[] = $item->getPrice() * $item->getQty() * \Rate_Limit_API::$rate; }
			return array( 'quoted-cart' => $taxes );
		}
	}
}
namespace TaxCloud_V3 {
	class Carts {
		public function calculate_tax( $request ) {
			++\Rate_Limit_API::$calls;
			foreach ( $request['items'][0]['lineItems'] as &$item ) { $item['tax'] = array( 'amount' => $item['price'] * $item['quantity'] * \Rate_Limit_API::$rate ); }
			return $request;
		}
	}
}
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'MINUTE_IN_SECONDS', 60 );
	$settings = $transients = $notices = array();
	$user_id = 0;
	$assertions = 0;
	function __( $message ) { return $message; }
	function add_action() {}
	function add_filter() {}
	function apply_filters( $hook, $value ) { return $value; }
	function esc_html( $value ) { return $value; }
	function sanitize_text_field( $value ) { return $value; }
	function wp_json_encode( $value ) { return json_encode( $value ); }
	function wc_get_price_decimals() { return 2; }
	function get_woocommerce_currency() { return 'USD'; }
	function sst_storefront_active() { return false; }
	function sst_get_api_version() { return SST_Settings::get( 'api_version' ); }
	function sst_integration_mode() { return SST_Settings::get( 'data_mover' ) ? 'data_mover' : 'realtime'; }
	function is_user_logged_in() { return get_current_user_id() > 0; }
	function get_current_user_id() { global $user_id; return $user_id; }
	function is_admin() { return false; }
	function current_user_can() { return false; }
	function get_transient( $key ) { global $transients; return $transients[ $key ] ?? false; }
	function set_transient( $key, $value, $expiration ) { global $transients; $transients[ $key ] = $value; }
	function WC() { global $wc; return $wc; }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function wc_has_notice( $message ) { global $notices; return in_array( $message, $notices, true ); }
	function wc_add_notice( $message ) { global $notices; $notices[] = $message; }
	function TaxCloud() { return new \TaxCloud\Client(); }
	class WP_Error {
		private $errors = array();
		public function __construct( $code = '', $message = '' ) { if ( $code ) { $this->add( $code, $message ); } }
		public function add( $code, $message ) { $this->errors[ $code ] = $message; }
		public function has_errors() { return (bool) $this->errors; }
		public function get_error_message() { return reset( $this->errors ) ?: ''; }
		public function get_error_codes() { return array_keys( $this->errors ); }
	}
	class SST_Settings { public static function get( $key, $default = null ) { global $settings; return $settings[ $key ] ?? $default; } }
	class SST_Logger { public static function add() {} public static function debug() {} public static function error() {} }
	class SST_Product { public static function get_tic() { return 0; } }
	class WC_Cache_Helper { public static function get_transient_version() { return '1'; } }
	class Rate_Limit_API { public static $calls = 0; public static $rate = 0.1; }
	class Rate_Limit_Product { public function get_id() { return 123; } }
	class Rate_Limit_Session {
		private $data = array();
		public function get_customer_id() { return 'guest-session'; }
		public function get( $key, $default = null ) { return $this->data[ $key ] ?? $default; }
		public function set( $key, $value ) { $this->data[ $key ] = $value; }
	}
	class Rate_Limit_Request {
		private $method;
		public function __construct( $method = 'POST' ) { $this->method = $method; }
		public function get_method() { return $this->method; }
		public function get_param( $key ) { return array(); }
	}
	require dirname( __DIR__ ) . '/includes/vendor/autoload.php';
	require dirname( __DIR__ ) . '/includes/class-sst-addresses.php';
	require dirname( __DIR__ ) . '/includes/class-sst-rate-limit.php';
	require dirname( __DIR__ ) . '/includes/abstracts/class-sst-abstract-cart.php';
	require dirname( __DIR__ ) . '/includes/frontend/class-sst-cart-proxy.php';
	require dirname( __DIR__ ) . '/includes/frontend/class-sst-checkout.php';
	class Rate_Limit_Checkout extends SST_Checkout {
		public $quantity = 1;
		public $tax = 0.0;
		public $processed = 0;
		public $package_count = 1;
		public function get_certificate_id() { return ''; }
		protected function process_checkout( $data, $order ) { ++$this->processed; }
		protected function create_packages() {
			$packages = array();
			for ( $index = 0; $index < $this->package_count; ++$index ) {
				$packages[] = array(
					'user' => array( 'ID' => get_current_user_id() ), 'certificate' => null, 'shipping' => null, 'fees' => array(),
					'origin' => new \TaxCloud\Address( '323 Washington Ave N', '', 'Minneapolis', 'MN', '55401' ),
					'destination' => new \TaxCloud\Address( '1 Main St', '', 'Minneapolis', 'MN', '55401' ),
					'contents' => array( array( 'key' => 'item-' . $index, 'data' => new Rate_Limit_Product(), 'product_id' => 123,
						'variation_id' => 0, 'quantity' => $this->quantity, 'line_total' => 100 * $this->quantity ) ),
				);
			}
			return $packages;
		}
		protected function is_destination_valid( $package ) { return true; }
		protected function is_origin_valid( $package ) { return true; }
		protected function reset_taxes() { $this->tax = 0.0; }
		protected function set_product_tax( $id, $tax ) { $this->tax += $tax; }
		protected function update_taxes() {}
	}
	function expect_same( $expected, $actual, $message ) {
		global $assertions;
		++$assertions;
		if ( $expected !== $actual ) { throw new \RuntimeException( $message . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) ); }
	}
	function reset_checkout( $version, $customer ) {
		global $wc, $settings, $transients, $notices, $user_id;
		$settings = array( 'api_version' => $version, 'tc_id' => 'fixture', 'tc_key' => 'fixture', 'enable_taxcloud_rate_limit' => 'yes',
			'taxcloud_rate_limit_requests' => 1, 'taxcloud_rate_limit_skip_admin' => 'no', 'show_rate_limit_notice' => 'no' );
		$transients = $notices = $_POST = array();
		$user_id = $customer;
		$wc = (object) array( 'session' => new Rate_Limit_Session(), 'cart' => new \stdClass() );
		Rate_Limit_API::$calls = 0;
		Rate_Limit_API::$rate = 0.1;
		return new Rate_Limit_Checkout();
	}
	function validate_classic( $checkout ) {
		$errors = new WP_Error();
		$checkout->validate_legacy_checkout( array(), $errors );
		return $errors;
	}
	function expect_blocked( $checkout, $message ) {
		expect_same( array( 'sst_tax_rate_limit' ), validate_classic( $checkout )->get_error_codes(), $message . ': classic stops before order creation' );
		$status = null;
		try { $checkout->handle_checkout( new \stdClass(), new Rate_Limit_Request() ); }
		catch ( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException $error ) { $status = $error->status; }
		expect_same( 400, $status, $message . ': Store API rejects checkout' );
		expect_same( 0, $checkout->processed, $message . ': no order processing' );
	}
	foreach ( array( 'v1', 'v3' ) as $version ) {
		foreach ( array( 0, 42 ) as $customer ) {
			$label = "$version customer $customer";
			$checkout = reset_checkout( $version, $customer );
			( new SST_Rate_Limit() )->increment_count();
			expect_blocked( $checkout, "$label without a prior estimate" );
			expect_same( true, $checkout->calculate_taxes(), "$label estimate remains available" );
			expect_same( 0.0, $checkout->tax, "$label estimate may skip tax" );
			expect_same( array(), $notices, "$label no estimate error notice" );
			expect_same( array(), WC()->session->get( 'sst_package_cache', array() ), "$label skipped lookup is not cached" );
			expect_blocked( $checkout, "$label uncached cart" );
			expect_same( 0, Rate_Limit_API::$calls, "$label no remote calls after limit" );
			$checkout->handle_checkout( new \stdClass(), new Rate_Limit_Request( 'PATCH' ) );
			expect_same( 1, $checkout->processed, "$label Store API estimate is not blocked" );

			$checkout = reset_checkout( $version, $customer );
			$checkout->calculate_taxes();
			expect_same( 10.0, $checkout->tax, "$label successful lookup applies tax" );
			expect_same( true, ( new SST_Rate_Limit() )->limit_reached(), "$label quote consumes last request" );
			expect_same( false, validate_classic( $checkout )->has_errors(), "$label cached classic checkout accepted" );
			$checkout->handle_checkout( new \stdClass(), new Rate_Limit_Request() );
			expect_same( 1, $checkout->processed, "$label cached Store API checkout accepted" );
			$settings['force_tax_lookup'] = 'yes';
			$checkout->calculate_taxes();
			expect_same( 10.0, $checkout->tax, "$label forced refresh keeps cached tax at limit" );
			expect_same( 1, Rate_Limit_API::$calls, "$label cached quote makes no extra API call" );
			$checkout->quantity = 2;
			$checkout->processed = 0;
			expect_blocked( $checkout, "$label changed cart cannot reuse stale session packages" );
			$checkout->calculate_taxes();
			expect_same( 0.0, $checkout->tax, "$label changed estimate still skips tax" );
			$checkout->quantity = 1;
			$checkout->calculate_taxes();
			expect_same( 10.0, $checkout->tax, "$label returning to cached cart restores tax" );
			expect_same( false, validate_classic( $checkout )->has_errors(), "$label returning to cached cart permits checkout" );

			$cache = WC()->session->get( 'sst_package_cache' );
			foreach ( $cache as &$package ) { unset( $package['response'] ); }
			unset( $package );
			WC()->session->set( 'sst_package_cache', $cache );
			expect_blocked( $checkout, "$label response-less legacy cache" );
			$transients = array(); // Model expiration of the rate-limit window.
			$checkout->calculate_taxes();
			expect_same( 10.0, $checkout->tax, "$label recovers after quota resets" );
			expect_same( 2, Rate_Limit_API::$calls, "$label recovery performs a fresh lookup" );

			$checkout = reset_checkout( $version, $customer );
			Rate_Limit_API::$rate = 0.0;
			$checkout->calculate_taxes();
			expect_same( false, validate_classic( $checkout )->has_errors(), "$label legitimate cached zero tax accepted" );
			$checkout->handle_checkout( new \stdClass(), new Rate_Limit_Request() );
			expect_same( 1, $checkout->processed, "$label zero-tax Store API quote accepted" );

			$checkout = reset_checkout( $version, $customer );
			$checkout->package_count = 2;
			$checkout->calculate_taxes();
			expect_same( 1, Rate_Limit_API::$calls, "$label limit reached between packages" );
			expect_blocked( $checkout, "$label partial quote cannot place order" );
			$settings['data_mover'] = true;
			expect_same( false, validate_classic( $checkout )->has_errors(), "$label Data Import bypasses realtime guard" );
			$settings['data_mover'] = false;
			WC()->session->set( 'is_tax_exempt', true );
			expect_same( false, validate_classic( $checkout )->has_errors(), "$label external exemption bypasses realtime guard" );
			WC()->session->set( 'is_tax_exempt', false );
			$settings['enable_taxcloud_rate_limit'] = 'no';
			expect_same( false, validate_classic( $checkout )->has_errors(), "$label disabled limiter does not block" );
			$settings['enable_taxcloud_rate_limit'] = 'yes';
			$settings['disable_integration'] = 'yes';
			expect_same( false, validate_classic( $checkout )->has_errors(), "$label disabled integration does not block" );
		}
	}
	echo "Rate limit regression checks passed ($assertions assertions).\n";
}
