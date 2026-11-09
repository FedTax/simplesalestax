<?php

// Standalone regression test for WooCommerce QIT unconfigured checkout issue:
// php tests/unconfigured-checkout-regression.php
define( 'ABSPATH', __DIR__ . '/' );
define( 'SST_RATE_ID', 999 );

$test_filters  = array();
$test_actions  = array();
$test_settings = array(
	'tc_id'                      => '',
	'tc_key'                     => '',
	'default_origin_addresses'   => array(),
	'addresses'                  => array(),
	'show_exempt'                => 'true',
	'restrict_exempt'            => 'no',
	'disable_integration'        => 'no',
	'capture_orders_in_taxcloud' => 'yes',
	'capture_trigger'            => 'both',
	'show_rate_limit_notice'     => 'yes',
);
$test_notices       = array();
$test_transients    = array();
$test_context       = 'checkout';
$wp                 = (object) array( 'query_vars' => array( 'rest_route' => '/wc/store/v1/checkout' ) );

function add_filter( $hook, $callback, $priority = 10, $accepted = 1 ) {
	global $test_filters;
	$test_filters[ $hook ][ $priority ][] = array( $callback, $accepted );
}
function apply_filters( $hook, $value, ...$args ) {
	global $test_filters;
	$callbacks = $test_filters[ $hook ] ?? array();
	ksort( $callbacks );
	foreach ( $callbacks as $group ) {
		foreach ( $group as list( $callback, $accepted ) ) {
			$value = $callback( ...array_slice( array_merge( array( $value ), $args ), 0, $accepted ) );
		}
	}
	return $value;
}
function add_action( $hook, $callback, $priority = 10, $accepted = 1 ) {
	global $test_actions;
	$test_actions[ $hook ][ $priority ][] = array( $callback, $accepted );
}
function do_action() {}
function __( $message ) { return $message; }
function esc_html( $text ) { return $text; }
function sanitize_text_field( $text ) { return trim( (string) $text ); }
function sanitize_key( $key ) { return strtolower( (string) $key ); }
function wp_unslash( $value ) { return $value; }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, $args ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function maybe_unserialize( $value ) { return is_string( $value ) && 'a:' === substr( $value, 0, 2 ) ? unserialize( $value ) : $value; }
function sst_storefront_active() { return false; }
function sst_subs_active() { return false; }
function is_admin() { return false; }
function is_cart() { global $test_context; return 'cart' === $test_context; }
function is_checkout() { global $test_context; return 'checkout' === $test_context; }
function doing_action() { return false; }
function is_user_logged_in() { return false; }
function wp_get_current_user() { return (object) array( 'roles' => array() ); }
function get_current_user_id() { return 0; }
function get_transient( $key ) {
	global $test_transients;
	return $test_transients[ $key ] ?? array();
}
function set_transient( $key, $value ) {
	global $test_transients;
	$test_transients[ $key ] = $value;
	return true;
}
function wc_get_price_decimals() { return 2; }
function wc_has_notice( $message, $type = 'error' ) {
	global $test_notices;
	return in_array( array( 'message' => $message, 'type' => $type ), $test_notices, true );
}
function wc_add_notice( $message, $type = 'error' ) {
	global $test_notices;
	$test_notices[] = array( 'message' => $message, 'type' => $type );
}
function WC() { global $test_woocommerce; return $test_woocommerce; }

class WP_Error {
	private $errors = array();
	public function add( $code, $message ) { $this->errors[ $code ] = $message; }
	public function has_errors() { return ! empty( $this->errors ); }
	public function get_error_message() { return reset( $this->errors ) ?: ''; }
	public function get_error_codes() { return array_keys( $this->errors ); }
}

class WP_REST_Request {
	private $params = array();
	public function __construct( $params = array() ) { $this->params = $params; }
	public function get_param( $key ) { return $this->params[ $key ] ?? null; }
}

class SST_Settings {
	public static function get( $key, $default = null ) {
		global $test_settings;
		return array_key_exists( $key, $test_settings ) ? $test_settings[ $key ] : $default;
	}
	public static function get_capture_trigger() {
		return self::get( 'capture_trigger', 'completed' );
	}
}

class SST_Logger {
	public static function add() {}
	public static function order_log() {}
}

class SST_Product {
	public static function get_origin_addresses() { return SST_Addresses::get_default_addresses(); }
	public static function get_tic() { return null; }
}

class SST_Rate_Limit {
	public function limit_reached() { return true; }
	public static function get_notice_message() { return 'Rate limit reached'; }
}

class WC_Tax {
	public static function get_tax_total( $taxes ) { return array_sum( $taxes ); }
}

class WC_Order {
	public $id = 101;
	public $created_via = 'store-api';
	public $meta = array();
	public $removed_item_types = array();
	public $calculate_totals_calls = 0;
	public $saves = 0;
	public function get_id() { return $this->id; }
	public function get_created_via() { return $this->created_via; }
	public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function get_total_refunded() { return 0; }
	public function remove_order_items( $type ) { $this->removed_item_types[] = $type; }
	public function calculate_totals() { ++$this->calculate_totals_calls; }
	public function save() { ++$this->saves; }
}
class WC_Order_Refund extends WC_Order {}

class QIT_Test_Session {
	public $data = array(
		'chosen_shipping_methods' => array( 0 => 'local_pickup:1' ),
	);
	public function get( $key, $default = null ) {
		return array_key_exists( $key, $this->data ) ? $this->data[ $key ] : $default;
	}
	public function set( $key, $value ) {
		$this->data[ $key ] = $value;
	}
}

class QIT_Test_Cart {
	public $reset_calls = 0;
	public $added_fees = array();
	public $sst_shipping_taxes = array( 0 => 1.25 );
	public function is_empty() { return false; }
	public function get_cart() {
		return array(
			'item_1' => array(
				'key'        => 'item_1',
				'product_id' => 12,
				'variation_id' => 0,
				'quantity'   => 1,
				'line_total' => 10.00,
				'data'       => new class {
					public function get_id() { return 12; }
					public function needs_shipping() { return true; }
				},
			),
		);
	}
	public function get_fees() { return array(); }
	public function add_fee( $name, $amount, $taxable ) {
		$this->added_fees[] = array( 'name' => $name, 'amount' => $amount, 'taxable' => $taxable );
	}
	public function get_taxes() { return array( 1 => 0.85 ); }
	public function set_total_tax() {}
	public function get_shipping_packages() {
		$rate = (object) array(
			'id'        => 'local_pickup:1',
			'method_id' => 'local_pickup',
			'cost'      => '0.00',
		);
		return array(
			0 => array(
				'contents'    => $this->get_cart(),
				'destination' => array(
					'country'   => 'US',
					'address'   => '742 Evergreen Terrace',
					'address_1' => '742 Evergreen Terrace',
					'address_2' => '',
					'city'      => 'Springfield',
					'state'     => 'CA',
					'postcode'  => '94016',
				),
				'rates'       => array( 'local_pickup:1' => $rate ),
				'shipping'    => $rate,
				'user'        => array( 'ID' => 0 ),
			),
		);
	}
}

require dirname( __DIR__ ) . '/includes/vendor/autoload.php';
require dirname( __DIR__ ) . '/includes/sst-functions.php';
require dirname( __DIR__ ) . '/includes/class-sst-origin-address.php';
require dirname( __DIR__ ) . '/includes/class-sst-addresses.php';
require dirname( __DIR__ ) . '/includes/class-sst-certificates.php';
require dirname( __DIR__ ) . '/includes/class-sst-shipping.php';
require dirname( __DIR__ ) . '/includes/abstracts/class-sst-abstract-cart.php';
require dirname( __DIR__ ) . '/includes/frontend/class-sst-cart-proxy.php';
require dirname( __DIR__ ) . '/includes/frontend/class-sst-checkout.php';
require dirname( __DIR__ ) . '/includes/class-sst-order.php';
require dirname( __DIR__ ) . '/includes/class-sst-order-controller.php';
require dirname( __DIR__ ) . '/includes/class-sst-blocks.php';

class QIT_Testable_Checkout extends SST_Checkout {
	public $create_packages_calls = 0;
	public $reset_taxes_calls     = 0;
	public $update_taxes_calls    = 0;
	public $do_lookup_calls       = 0;

	protected function create_packages() {
		++$this->create_packages_calls;
		return parent::create_packages();
	}

	protected function reset_taxes() {
		++$this->reset_taxes_calls;
	}

	protected function update_taxes() {
		++$this->update_taxes_calls;
	}

	protected function do_lookup() {
		++$this->do_lookup_calls;
		return array();
	}
}

function expect_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}

$test_woocommerce = new class {
	public $session;
	public $cart;
	public $customer;
	public $shipping;
	public function __construct() {
		$this->session  = new QIT_Test_Session();
		$this->cart     = new QIT_Test_Cart();
		$this->customer = new class {
			public function get_shipping_state() { return 'CO'; }
			public function get_billing_state() { return 'CO'; }
		};
		$cart           = $this->cart;
		$this->shipping = new class( $cart ) {
			private $cart;
			public function __construct( $cart ) { $this->cart = $cart; }
			public function get_packages() { return $this->cart->get_shipping_packages(); }
		};
	}
	public function shipping() {
		return $this->shipping;
	}
	public function is_rest_api_request() { return true; }
};

// 1. Reproduce the exact WooCommerce QIT scenario:
// TaxCloud is active, Taxes enabled, Local Pickup selected, US destination address
// (742 Evergreen Terrace, Springfield, CA 94016), but TaxCloud is unconfigured
// (empty API Login ID, empty API Key, no origin addresses).
$checkout = new QIT_Testable_Checkout();

foreach (
	array(
		'both credentials empty' => array( 'tc_id' => '', 'tc_key' => '' ),
		'missing API key'        => array( 'tc_id' => 'login-only', 'tc_key' => '' ),
		'missing API login ID'   => array( 'tc_id' => '', 'tc_key' => 'key-only' ),
	) as $label => $creds
) {
	$test_settings['tc_id']  = $creds['tc_id'];
	$test_settings['tc_key'] = $creds['tc_key'];
	$test_notices            = array();

	$calculated = $checkout->calculate_tax_totals( 10.85, $test_woocommerce->cart );
	expect_same( 10.85, $calculated, "$label: calculate_tax_totals preserves cart total" );
	expect_same( 0, $checkout->create_packages_calls, "$label: calculate_tax_totals does not create packages" );
	expect_same( 0, $checkout->reset_taxes_calls, "$label: calculate_tax_totals does not reset manual WooCommerce taxes" );
	expect_same( array(), $test_notices, "$label: no origin address error notice added during calculate_tax_totals" );

	$tax_result = $checkout->calculate_taxes();
	expect_same( false, $tax_result, "$label: direct calculate_taxes returns false when unconfigured" );
	expect_same( 0, $checkout->create_packages_calls, "$label: direct calculate_taxes does not create or split packages" );
	expect_same( 0, $checkout->reset_taxes_calls, "$label: direct calculate_taxes does not reset taxes before credential check" );
	expect_same( array(), $test_notices, "$label: direct calculate_taxes emits no checkout error notice" );
}

// 2. Verify Block Checkout (Store API) and Classic Checkout hooks do not run when unconfigured.
$test_settings['tc_id']  = '';
$test_settings['tc_key'] = '';

$block_order = new WC_Order();
$request     = new WP_REST_Request(
	array(
		'extensions' => array(
			'simple-sales-tax' => array(
				'certificate_id' => 'new',
				'certificate'    => array(),
			),
		),
	)
);
$checkout->handle_checkout( $block_order, $request );
expect_same( array(), $block_order->removed_item_types, 'Unconfigured Block Checkout does not remove/recreate order items' );
expect_same( 0, $block_order->saves, 'Unconfigured Block Checkout does not re-save order' );
expect_same( array(), $block_order->meta, 'Unconfigured Block Checkout writes no TaxCloud order meta' );

$legacy_errors = new WP_Error();
$checkout->validate_legacy_checkout( array( 'certificate_id' => 'new' ), $legacy_errors );
expect_same( false, $legacy_errors->has_errors(), 'Unconfigured Classic Checkout validation adds no errors' );

$classic_order               = new WC_Order();
$classic_order->created_via  = 'checkout';
$checkout->handle_legacy_checkout( $classic_order );
expect_same( 0, $classic_order->saves, 'Unconfigured Classic Checkout does not re-save order' );
expect_same( array(), $classic_order->meta, 'Unconfigured Classic Checkout writes no TaxCloud order meta' );

// 3. Verify auxiliary frontend hooks are inert when unconfigured.
expect_same( false, sst_should_show_tax_exemption_form(), 'Exemption form hidden when credentials are empty' );

$checkout->init_certificate_id();
expect_same( null, $test_woocommerce->session->get( 'sst_packages' ), 'init_certificate_id does not initialize session packages when unconfigured' );

$checkout->add_rate_limit_notice();
expect_same( array(), $test_notices, 'Rate limit notice is not added when unconfigured' );

$blocks_data = SST_Blocks::instance()->get_cart_data();
expect_same( '', $blocks_data['rate_limit_notice'], 'Store API cart data rate_limit_notice is empty when unconfigured' );

$shipping_item = new class {
	public $taxes_set = false;
	public function get_taxes() { return array( 'total' => array() ); }
	public function set_taxes() { $this->taxes_set = true; }
};
$checkout->add_shipping_meta( $shipping_item, 0, array() );
expect_same( false, $shipping_item->taxes_set, 'add_shipping_meta does not overwrite shipping item taxes when unconfigured' );

$checkout->add_co_excise_tax_fee( $test_woocommerce->cart );
expect_same( array(), $test_woocommerce->cart->added_fees, 'add_co_excise_tax_fee does not run when unconfigured' );

// 4. Verify order capture and REST order tax calculation hooks skip unconfigured stores
// (e.g. when a COD order transitions directly to processing during checkout).
$controller = new SST_Order_Controller();
$cod_order  = new WC_Order();
expect_same( false, $controller->capture_processing_order( $cod_order->get_id(), $cod_order ), 'Unconfigured processing order skips capture' );
expect_same( false, $controller->capture_order( $cod_order->get_id(), $cod_order ), 'Unconfigured completed order skips capture' );

$sst_order = new SST_Order( $cod_order );
expect_same( false, $sst_order->do_capture(), 'Direct SST_Order::do_capture returns false when unconfigured' );
expect_same( array(), $test_notices, 'Unconfigured order capture emits no error notice' );

$rest_order              = new WC_Order();
$rest_order->created_via = 'rest-api';
$controller->calculate_order_tax( true, $rest_order );
expect_same( 0, $rest_order->calculate_totals_calls, 'Unconfigured REST order skips TaxCloud tax calculation' );

// 5. Verify that once credentials and origin address are configured, TaxCloud resumes normal operation.
$origin      = new SST_Origin_Address( 'origin-1', true, '742 Evergreen Terrace', '', 'Springfield', 'CA', '94016' );
$destination = new TaxCloud\Address( '742 Evergreen Terrace', '', 'Springfield', 'CA', '94016' );
$test_transients['sst_verified_addresses'] = array(
	'v2:v1:' . md5( wp_json_encode( $destination ) ) => array( 'address' => json_decode( wp_json_encode( $destination ), true ), 'outcome' => array( 'input_hash' => md5( wp_json_encode( $destination ) ), 'status' => 'verified', 'reason' => '', 'changes' => array() ) ),
);
$test_settings['tc_id']                    = 'configured-login-id';
$test_settings['tc_key']                   = 'configured-api-key';
$test_settings['addresses']                = array( 'origin-1' => wp_json_encode( $origin ) );
$test_settings['default_origin_addresses'] = array( 'origin-1' );

expect_same( true, sst_should_show_tax_exemption_form(), 'Exemption form enabled once credentials are configured' );
$checkout->calculate_tax_totals( 10.00, $test_woocommerce->cart );
expect_same( 1, $checkout->create_packages_calls, 'Configured checkout creates packages' );
expect_same( 1, $checkout->reset_taxes_calls, 'Configured checkout resets taxes before lookup' );
expect_same( 1, $checkout->do_lookup_calls, 'Configured checkout performs TaxCloud lookup' );
expect_same( array(), $test_notices, 'Configured checkout with valid origin adds no error notices' );

echo "Unconfigured checkout QIT regression checks passed.\n";
