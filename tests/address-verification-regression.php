<?php
/** DEV-10605: php tests/address-verification-regression.php (no network or database). */
namespace Automattic\WooCommerce\StoreApi\Exceptions {
	class RouteException extends \RuntimeException {
		public function __construct( $code, $message, $status ) { parent::__construct( $message, $status ); }
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'HOUR_IN_SECONDS', 3600 );
	$settings = array( 'api_version' => 'v3', 'tc_id' => 'fixture', 'tc_key' => 'fixture', 'require_verified_shipping_address' => 'no' );
	$transients = $hooks = $copy_filters = array();
	$mode = 'corrected';
	$calls = $assertions = 0;
	function __( $value ) { return $value; }
	function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
	function esc_html( $value ) { return $value; }
	function add_action( $hook, $callback ) { $GLOBALS['hooks'][ $hook ] = $callback; }
	function add_filter( $hook, $callback ) {
		if ( in_array( $hook, array( 'wc_subscriptions_subscription_data', 'wc_subscriptions_renewal_order_data', 'wc_subscriptions_resubscribe_order_data' ), true ) ) {
			$GLOBALS['copy_filters'][ $hook ][] = $callback;
		}
	}
	function apply_filters( $hook, $value ) {
		foreach ( $GLOBALS['copy_filters'][ $hook ] ?? array() as $callback ) {
			$value = $callback( $value );
		}
		return 'wootax_add_fees' === $hook ? false : $value;
	}
	function sst_storefront_active() { return false; }
	function sst_get_api_version() { return SST_Settings::get( 'api_version' ); }
	function wp_json_encode( $value ) { return json_encode( $value ); }
	function wp_parse_args( $value, $defaults ) { return array_merge( $defaults, $value ); }
	function sst_create_package( $value ) { return wp_parse_args( $value, array( 'contents' => array(), 'fees' => array(), 'shipping' => null, 'certificate' => null, 'user' => array( 'ID' => 0 ) ) ); }
	function get_current_user_id() { return 0; }
	function wc_get_price_decimals() { return 2; }
	function wp_unslash( $value ) { return $value; }
	function get_transient( $key ) { return $GLOBALS['transients'][ $key ] ?? false; }
	function set_transient( $key, $value, $ttl ) { $GLOBALS['transients'][ $key ] = $value; }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function WC() { return $GLOBALS['wc']; }
	function wp_remote_retrieve_response_code( $response ) { return $response['response']['code']; }
	function wp_remote_retrieve_body( $response ) { return $response['body']; }
	function wp_remote_post( $url, $args ) {
		if ( false !== strpos( $url, '/auth/' ) ) {
			return 'auth' === $GLOBALS['mode'] ? new WP_Error( 'sst_v3_auth_error', 'Invalid API key', array( 'status' => 400 ) ) : array( 'response' => array( 'code' => 200 ), 'body' => '{"access_token":"fixture"}' );
		}
		++$GLOBALS['calls'];
		$mode = $GLOBALS['mode'];
		if ( 'timeout' === $mode ) { return new WP_Error( 'http_request_failed', 'cURL error 28: timeout' ); }
		if ( is_int( $mode ) ) { return array( 'response' => array( 'code' => $mode ), 'body' => '{"detail":"Address not found"}' ); }
		if ( 'mismatch' === $mode ) { return array( 'response' => array( 'code' => 422 ), 'body' => '{"detail":"City/ZIP mismatch"}' ); }
		if ( 'endpoint-auth' === $mode ) { return array( 'response' => array( 'code' => 400 ), 'body' => '{"detail":"Invalid API key"}' ); }
		$data = json_decode( $args['body'], true );
		if ( 'malformed' === $mode ) { $data = array( 'message' => 'not an address' ); }
		elseif ( 'corrected' === $mode ) { $data['city'] = 'Riverside'; $data['zip'] = '92508-8114'; }
		elseif ( 'zip' === $mode ) { $data['zip'] = '92509-1234'; }
		elseif ( 'state' === $mode ) { $data['state'] = 'NV'; }
		elseif ( 'standardized' === $mode ) { $data['line1'] = '1 Main Dr'; $data['city'] = strtoupper( $data['city'] ); $data['zip'] = '92508-8114'; }
		return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $data ) );
	}
	function TaxCloud() {
		return new class {
			public function VerifyAddress( $request ) {
				++$GLOBALS['calls'];
				$mode = $GLOBALS['mode'];
				if ( in_array( $mode, array( 'reject', 'no-delivery-point', 'mismatch', 'timeout', 'auth', 'server', 'rate' ), true ) ) {
					$messages = array(
						'reject'            => 'Error -2147219401: Address Not Found.',
						'no-delivery-point' => 'Error 97: Address has no delivery point value',
						'mismatch'          => 'Error: City State Mismatch.',
						'timeout'           => 'cURL error 28: Operation timed out',
						'auth'              => 'API Key not set.',
						'server'            => 'HTTP 503 Server error: Address not found',
						'rate'              => 'Too many requests',
					);
					throw new \TaxCloud\Exceptions\VerifyAddressException( $messages[ $mode ] );
				}
				return new \TaxCloud\Address( '1 Main Dr', '', 'corrected' === $mode ? 'Riverside' : ( 'standardized' === $mode ? strtoupper( $request->getCity() ) : $request->getCity() ), 'state' === $mode ? 'NV' : $request->getState(), 'zip' === $mode ? '92509' : $request->getZip5(), '8114' );
			}
		};
	}
	class WP_Error {
		private $errors = array();
		private $data;
		public function __construct( $code = '', $message = '', $data = null ) { if ( $code ) { $this->add( $code, $message ); } $this->data = $data; }
		public function add( $code, $message ) { $this->errors[ $code ] = $message; }
		public function has_errors() { return (bool) $this->errors; }
		public function get_error_message() { return reset( $this->errors ) ?: ''; }
		public function get_error_code() { return key( $this->errors ); }
		public function get_error_data() { return $this->data; }
	}
	class SST_Settings { public static function get( $key, $default = null ) { return $GLOBALS['settings'][ $key ] ?? $default; } }
	class SST_Logger { public static function add() {} public static function order_log() {} }
	class SST_Rate_Limit {
		public static $limited = false;
		public function limit_reached() { return self::$limited; }
		public function log_limit_reached() {}
		public function increment_count() {}
	}
	class WC_Order {
		public $meta = array();
		public $notes = array();
		public $id = 123;
		public $created_via = '';
		public function get_id() { return $this->id; }
		public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
		public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
		public function add_order_note( $note ) { $this->notes[] = $note; }
		public function save_meta_data() {}
	}
	class Fixture_Session {
		public $data = array();
		public function get( $key, $default = null ) { return $this->data[ $key ] ?? $default; }
		public function set( $key, $value ) { $this->data[ $key ] = $value; }
	}
	require dirname( __DIR__ ) . '/includes/vendor/autoload.php';
	require dirname( __DIR__ ) . '/includes/class-sst-addresses.php';
	require dirname( __DIR__ ) . '/includes/class-sst-origin-address.php';
	require dirname( __DIR__ ) . '/includes/class-sst-shipping.php';
	require dirname( __DIR__ ) . '/includes/abstracts/class-sst-abstract-cart.php';
	require dirname( __DIR__ ) . '/includes/frontend/class-sst-cart-proxy.php';
	require dirname( __DIR__ ) . '/includes/frontend/class-sst-checkout.php';
	require dirname( __DIR__ ) . '/includes/class-sst-order.php';
	require dirname( __DIR__ ) . '/includes/class-sst-order-controller.php';
	class Fixture_Checkout extends SST_Checkout {
		public $raw = array();
		public $processed = 0;
		public function record( $outcome ) { $this->handle_address_verification( $outcome ); }
		public function packages() { return $this->create_packages(); }
		protected function get_base_packages() { return $this->raw; }
		public function get_certificate() { return null; }
		protected function validate_exemption_certificate( $data, $errors ) {}
		protected function process_checkout( $data, $order ) { ++$this->processed; SST_Addresses::save_order_verification( $order, WC()->session->get( 'sst_address_verification', array() ) ); }
	}
	class Fixture_Order extends SST_Order {
		public function record( $outcome ) { $this->handle_address_verification( $outcome ); }
	}
	function same( $expected, $actual, $message ) {
		++$GLOBALS['assertions'];
		if ( $expected !== $actual ) { throw new \RuntimeException( "$message: expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) ); }
	}
	function address( $city = 'Riverisde', $street = '1 Main Drive', $zip = '92508' ) {
		return 'v3' === sst_get_api_version() ? new \TaxCloud_V3\Model\Address( array( 'line1' => $street, 'line2' => '', 'city' => $city, 'state' => 'CA', 'zip' => $zip ) ) : new \TaxCloud\Address( $street, '', $city, 'CA', $zip );
	}
	$wc = (object) array( 'session' => new Fixture_Session(), 'cart' => new class { public function calculate_totals() { $GLOBALS['checkout']->packages(); } } );
	$checkout = new Fixture_Checkout();
	$request = new class { public function get_param( $key ) { return array(); } public function get_method() { return 'POST'; } };
	foreach ( array( 'v1', 'v3' ) as $version ) {
		$settings['api_version'] = $version;
		$mode = 'corrected'; $transients = array(); $before = $calls;
		$input = address();
		$result = SST_Addresses::verify_address( $input, $outcome );
		same( 'Riverside', $result->getCity(), "$version corrected city used for tax" );
		same( '8114', $result->getZip4(), "$version ZIP+4 used for tax" );
		same( 'corrected', $outcome['status'], "$version correction recorded" );
		same( array( 'city', 'zip' ), array_keys( $outcome['changes'] ), "$version city and ZIP appear in note" );
		same( true, false !== strpos( SST_Addresses::get_verification_note( $outcome ), '"Riverisde" → "Riverside"' ), "$version note lists correction" );
		same( 'TaxCloud verified the shipping address: city "Riverisde" → "Riverside", ZIP 92508 → 92508-8114', SST_Addresses::get_verification_note( $outcome ), "$version exact correction note" );
		SST_Addresses::verify_address( $input, $cached );
		same( $before + 1, $calls, "$version successful verification cached" );
		same( $outcome, $cached, "$version cached correction retains outcome" );
		foreach ( array( 'verified', 'standardized' ) as $mode ) {
			$transients = array(); SST_Addresses::verify_address( address( 'Riverside' ), $quiet );
			same( 'verified', $quiet['status'], "$version $mode ignores street/case/ZIP+4" );
			same( '', SST_Addresses::get_verification_note( $quiet ), "$version $mode no note" );
		}
		$mode = 'zip'; $transients = array(); SST_Addresses::verify_address( address( 'Riverside' ), $zip );
		same( 'v1' === $version ? '92509-8114' : '92509-1234', $zip['changes']['zip']['to'], "$version five-digit ZIP correction noted" );
		$mode = 'state'; $transients = array(); SST_Addresses::verify_address( address( 'Riverside' ), $state );
		same( array( 'from' => 'CA', 'to' => 'NV' ), $state['changes']['state'], "$version corrected state noted" );
		$failure_modes = 'v1' === $version
			? array( 'reject', 'no-delivery-point', 'mismatch', 'timeout', 'auth', 'server', 'rate' )
			: array( 400, 'mismatch', 'timeout', 'auth', 503, 429 );
		foreach ( $failure_modes as $mode ) {
			$transients = array(); $before = $calls;
			$returned = SST_Addresses::verify_address( $input, $failure );
			$rejected = in_array( $mode, array( 'reject', 'no-delivery-point', 400, 'mismatch' ), true );
			same( $input, $returned, "$version $mode falls back to typed address" );
			same( $rejected ? 'rejected' : 'transient_failure', $failure['status'], "$version $mode classified" );
			if ( 'mismatch' === $mode ) { same( 'city_zip_mismatch', $failure['reason'], "$version mismatch reason" ); }
			if ( 'no-delivery-point' === $mode ) { same( 'address_not_found', $failure['reason'], 'Live V1 error 97 is an address-not-found rejection' ); }
			SST_Addresses::verify_address( $input, $retry );
			same( $rejected ? 1 : ( 'auth' === $mode && 'v3' === $version ? 0 : 2 ), $calls - $before, "$version $mode retry/cache policy" );
			same( $rejected, ! empty( $transients['sst_verified_addresses'] ), "$version $mode only rejections cached" );
			$wc->session->set( 'sst_address_verification', array() ); $checkout->record( $failure );
			foreach ( array( 'no', 'yes' ) as $required ) {
				$settings['require_verified_shipping_address'] = $required;
				$errors = new WP_Error(); $checkout->validate_legacy_checkout( array(), $errors );
				same( $rejected && 'yes' === $required, $errors->has_errors(), "$version $mode classic blocking=$required" );
				$blocked = false;
				try { $checkout->handle_checkout( new WC_Order(), $request ); } catch ( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException $ex ) { $blocked = true; }
				same( $rejected && 'yes' === $required, $blocked, "$version $mode block checkout blocking=$required" );
			}
			foreach ( array( 'admin', 'rest-api', 'renewal' ) as $context ) {
				$order = new WC_Order(); $order->created_via = $context; $sst_order = new Fixture_Order( $order );
				$sst_order->record( $failure ); $sst_order->record( $failure );
				same( 1, count( $order->notes ), "$version $mode $context gets one nonblocking note" );
			}
		}
		$mode = 'corrected'; $transients = array(); SST_Rate_Limit::$limited = true; $before = $calls;
		SST_Addresses::verify_address( $input, $limited ); SST_Addresses::verify_address( $input, $limited );
		same( 'rate_limit_reached', $limited['reason'], "$version local rate limit reason" );
		same( $before, $calls, "$version rate limit no request" ); same( array(), $transients, "$version rate skip not cached" );
		SST_Rate_Limit::$limited = false;
		SST_Addresses::verify_address( address( 'Riverside', '' ), $missing );
		same( 'missing_street_or_zip', $missing['reason'], "$version missing street reason" );
		same( $before, $calls, "$version incomplete address no request" );
		// Legacy entries may be cached outages; none are trusted as verified.
		$transients = array( 'sst_verified_addresses' => array( md5( wp_json_encode( $input ) ) => wp_json_encode( $input ) ) );
		SST_Addresses::verify_address( $input, $migrated );
		same( $before + 1, $calls, "$version old ambiguous cache is reverified" );
		same( 'corrected', $migrated['status'], "$version legacy cache recovers" );
	}
	// Malformed successful responses and non-rejection HTTP errors fail open.
	foreach ( array( 'malformed', 'endpoint-auth', 401, 403, 404, 500 ) as $mode ) {
		$transients = array(); SST_Addresses::verify_address( address(), $outcome );
		same( 'transient_failure', $outcome['status'], "v3 $mode never rejected" );
		same( false, isset( $transients['sst_verified_addresses'] ), "v3 $mode not cached" );
	}
	$before = $calls; SST_Addresses::verify_address( address( 'Riverside', '1 Main St', '' ), $missing_zip );
	same( 'missing_street_or_zip', $missing_zip['reason'], 'Missing ZIP is skipped' );
	same( $before, $calls, 'Missing ZIP never reaches HTTP' );
	// POST validation uses a freshly submitted destination before order creation.
	$mode = 422; $transients = array();
	$origin = new SST_Origin_Address( 'fixture', true, '2 Main St', '', 'Riverside', 'CA', '92508' );
	$checkout->raw = array( array( 'origin' => $origin, 'destination' => array( 'address_1' => '1 Main Drive', 'city' => 'Riverisde', 'state' => 'CA', 'postcode' => '92508', 'country' => 'US' ), 'contents' => array() ) );
	$blocked = false;
	try { $hooks['woocommerce_store_api_checkout_update_customer_from_request']( null, $request ); } catch ( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException $ex ) { $blocked = true; }
	same( true, $blocked, 'Block checkout validates before a new draft is created' );
	$checkout->raw = array(); $checkout->packages(); $errors = new WP_Error(); $checkout->validate_legacy_checkout( array(), $errors );
	same( false, $errors->has_errors(), 'Removing a destination clears its stale rejection' );
	// New REST orders can calculate before they have an ID. Flush their note on save.
	$order = new WC_Order(); $order->id = 0;
	SST_Addresses::save_order_verification( $order, array( $outcome ) );
	same( 0, count( $order->notes ), 'No orphan notes before order exists' );
	$order->id = 999; ( new SST_Order_Controller() )->add_address_verification_notes( 999, $order );
	same( 1, count( $order->notes ), 'New order receives pending verification note' );

	// Subscriptions copies metadata, but order notes belong to the original order.
	require dirname( __DIR__ ) . '/includes/integrations/class-sst-subscriptions.php';
	$mode       = 'timeout';
	$transients = array();
	SST_Addresses::verify_address( address(), $failure );
	$parent     = new WC_Order();
	$parent->id = 500;
	$parent->update_meta_data( '_fixture_subscription_meta', 'keep' );
	( new Fixture_Order( $parent ) )->record( $failure );
	$subscription_meta = apply_filters( 'wc_subscriptions_subscription_data', $parent->meta );
	foreach ( array( 'new' => $subscription_meta, 'existing' => $parent->meta ) as $type => $meta ) {
		$renewal              = new WC_Order();
		$renewal->id          = 501;
		$renewal->created_via = 'subscription';
		$renewal->meta        = apply_filters( 'wc_subscriptions_renewal_order_data', $meta );
		$sst_renewal = new Fixture_Order( $renewal );
		$sst_renewal->record( $failure );
		same( $parent->notes, $renewal->notes, "Renewal from $type subscription gets its own verification note" );
		$sst_renewal->record( $failure );
		same( 1, count( $renewal->notes ), "Recalculation of renewal from $type subscription does not duplicate its note" );
	}
	$unrelated_meta = array( '_fixture_subscription_meta' => 'keep' );
	same( $unrelated_meta, $subscription_meta, 'Parent-to-subscription copy keeps only unrelated metadata' );
	same( $unrelated_meta, apply_filters( 'wc_subscriptions_renewal_order_data', $parent->meta ), 'Existing subscription-to-renewal copy keeps only unrelated metadata' );
	$resubscribe       = new WC_Order();
	$resubscribe->id   = 502;
	$resubscribe->meta = apply_filters( 'wc_subscriptions_resubscribe_order_data', $parent->meta );
	$sst_resubscribe   = new Fixture_Order( $resubscribe );
	$sst_resubscribe->record( $failure );
	same( $parent->notes, $resubscribe->notes, 'Resubscribe order gets its own verification note' );
	$sst_resubscribe->record( $failure );
	same( 1, count( $resubscribe->notes ), 'Recalculation of resubscribe order does not duplicate its note' );
	same( $unrelated_meta, apply_filters( 'wc_subscriptions_resubscribe_order_data', $parent->meta ), 'Resubscribe copy keeps only unrelated metadata' );
	same( 1, count( $parent->notes ), 'Parent keeps its verification note' );
	echo "Address verification regression checks passed ($assertions assertions).\n";
}
