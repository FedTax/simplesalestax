<?php

// Standalone regression test: php tests/foosales-regression.php
// Set SST_CHECKOUT_TEST_FILE to compare against an earlier checkout class.
define( 'ABSPATH', __DIR__ . '/' );

$filters = array();
$settings = array( 'tc_id' => 'test', 'tc_key' => 'test' );
$options = array( 'woocommerce_store_address' => '1399 Horizon Ave', 'woocommerce_store_address_2' => '' );
$context = '';
$mode = 'realtime';
$calculations = 0;
$wp = (object) array( 'query_vars' => array() );

function add_filter( $hook, $callback, $priority = 10, $accepted = 1 ) {
	global $filters;
	$filters[ $hook ][ $priority ][] = array( $callback, $accepted );
}
function apply_filters( $hook, $value, ...$args ) {
	global $filters;
	$callbacks = $filters[ $hook ] ?? array();
	ksort( $callbacks );
	foreach ( $callbacks as $group ) {
		foreach ( $group as list( $callback, $accepted ) ) {
			$value = $callback( ...array_slice( array_merge( array( $value ), $args ), 0, $accepted ) );
		}
	}
	return $value;
}
function add_action() {}
function __( $text ) { return $text; }
function sst_storefront_active() { return false; }
function WC() { global $woocommerce; return $woocommerce; }
function is_cart() { global $context; return 'cart' === $context; }
function is_checkout() { global $context; return 'checkout' === $context; }
function doing_action( $action ) { global $context; return $action === $context; }
function sst_integration_mode() { global $mode; return $mode; }
function get_option( $key, $default = false ) { global $options; return $options[ $key ] ?? $default; }
function sst_order_calculate_taxes() { global $calculations; ++$calculations; }

class SST_Settings {
	public static function get( $key, $default = null ) { global $settings; return $settings[ $key ] ?? $default; }
}
class SST_Logger {
	public static function order_log() {}
}
class WC_Order {
	public $source = '';
	public $via = 'rest-api';
	public $shipping = array();
	public function get_meta() { return $this->source; }
	public function get_created_via() { return $this->via; }
	public function get_shipping_methods() { return $this->shipping; }
	public function get_id() { return 603; }
}
class WC_Order_Refund extends WC_Order {}

$countries = new class {
	public $country = 'US';
	public function get_base_country() { return $this->country; }
	public function get_base_state() { return 'CO'; }
	public function get_base_city() { return 'Lafayette'; }
	public function get_base_postcode() { return '80026'; }
};
$woocommerce = new class {
	public $session = true;
	public $countries;
	public function is_rest_api_request() { return true; }
};
$woocommerce->countries = $countries;

require dirname( __DIR__ ) . '/includes/vendor/autoload.php';
require dirname( __DIR__ ) . '/includes/abstracts/class-sst-abstract-cart.php';
require dirname( __DIR__ ) . '/includes/class-sst-origin-address.php';
require getenv( 'SST_CHECKOUT_TEST_FILE' ) ?: dirname( __DIR__ ) . '/includes/frontend/class-sst-checkout.php';
require dirname( __DIR__ ) . '/includes/class-sst-order-controller.php';
require dirname( __DIR__ ) . '/includes/integrations/class-sst-foosales.php';

function expect_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}

$manual_rates = array( 42 => array( 'rate' => '9.2100', 'label' => 'Lafayette POS', 'shipping' => 'yes', 'compound' => 'no' ) );
$location = array( 'country' => 'US', 'state' => 'CO', 'postcode' => '80026', 'city' => 'Lafayette', 'tax_class' => '' );

// The same configured rate is available to POS and ordinary REST order totals,
// but must not be added to TaxCloud tax at any supported online checkout.
foreach ( array( 'cart', 'checkout', 'wc_ajax_wc_stripe_get_cart_details', 'wc_ajax_square_digital_wallet_recalculate_totals' ) as $context ) {
	expect_same( array(), apply_filters( 'woocommerce_find_rates', $manual_rates, $location ), "$context excludes duplicate US tax" );
}
$context = '';
$wp->query_vars['rest_route'] = '/wc/store/v1/cart';
expect_same( array(), apply_filters( 'woocommerce_find_rates', $manual_rates, $location ), 'Store API excludes duplicate US tax' );
$wp->query_vars = array( 'edit-subscription' => 123 );
expect_same( array(), apply_filters( 'woocommerce_find_rates', $manual_rates, $location ), 'Subscription editing excludes duplicate US tax' );
foreach ( array( '/foosales/v6/connect_data_fetch', '/foosales/v6/create_update_order', '/wc/v3/orders', '' ) as $route ) {
	$wp->query_vars = array( 'rest_route' => $route );
	expect_same( $manual_rates, apply_filters( 'woocommerce_find_rates', $manual_rates, $location ), "$route retains POS/manual rates" );
}
$context = 'checkout';
$mode = 'data_mover';
expect_same( $manual_rates, apply_filters( 'woocommerce_find_rates', $manual_rates, $location ), 'Data import retains manual tax' );
$mode = 'realtime';
$settings['disable_integration'] = 'yes';
expect_same( $manual_rates, apply_filters( 'woocommerce_find_rates', $manual_rates, $location ), 'Disabled integration retains manual tax' );
unset( $settings['disable_integration'] );
$settings['tc_key'] = '';
expect_same( $manual_rates, apply_filters( 'woocommerce_find_rates', $manual_rates, $location ), 'Unconfigured integration retains manual tax' );
$settings['tc_key'] = 'test';
expect_same( $manual_rates, apply_filters( 'woocommerce_find_rates', $manual_rates, array( 'country' => 'CA' ) ), 'Non-US manual rates remain available' );
$woocommerce->session = null;
expect_same( $manual_rates, apply_filters( 'woocommerce_find_rates', $manual_rates, $location ), 'No cart session retains manual rates' );
$woocommerce->session = true;
$filters['sst_calculate_tax_totals'][10][] = array( function () { return false; }, 1 );
expect_same( $manual_rates, apply_filters( 'woocommerce_find_rates', $manual_rates, $location ), 'Calculation opt-out retains manual rates' );
unset( $filters['sst_calculate_tax_totals'] );

// A FooSales order must never have its total increased after the client took
// payment, including FooSales versions which mark orders as rest-api.
$controller = new SST_Order_Controller();
$order = new WC_Order();
$order->source = 'foosales_app';
foreach ( array( '', 'rest-api' ) as $via ) {
	$order->via = $via;
	$controller->calculate_order_tax( true, $order );
	expect_same( 0, $calculations, 'POS preserves collected tax for ' . $via );
}
$order->source = '';
$order->via = 'rest-api';
$controller->calculate_order_tax( true, $order );
expect_same( 1, $calculations, 'Ordinary REST order still uses TaxCloud' );
$controller->calculate_order_tax( false, $order );
expect_same( 1, $calculations, 'Recursion guard remains effective' );
$filters['sst_should_calculate_order_tax'][20][] = array( function () { return true; }, 1 );
$controller->calculate_order_tax( false, $order );
expect_same( 1, $calculations, 'An extension cannot bypass the recursion guard' );
unset( $filters['sst_should_calculate_order_tax'][20] );

// In-store reporting must use the storefront, not an empty guest address or
// a customer's saved home address. Shipped and non-POS orders retain theirs.
$integration = new SST_FooSales();
$customer = array( 'country' => 'US', 'state' => 'NY', 'city' => 'New York', 'postcode' => '10001', 'address' => '1 Customer St', 'address_2' => '' );
$packages = array( array( 'destination' => $customer, 'contents' => array( 'item' => 503 ) ) );
$order->source = 'foosales_app';
$result = $integration->use_store_address( $packages, $order );
expect_same( 'Lafayette', $result[0]['destination']['city'], 'In-store destination is Lafayette' );
expect_same( '1399 Horizon Ave', $result[0]['origin']->getAddress1(), 'In-store origin is storefront' );
expect_same( $packages[0]['contents'], $result[0]['contents'], 'Package contents are retained' );
expect_same( 'CO', $integration->use_store_tax_location( $customer, $order )['state'], 'WooCommerce and TaxCloud use the same POS location' );
$order->shipping = array( 'flat_rate' );
expect_same( $packages, $integration->use_store_address( $packages, $order ), 'POS delivery retains customer destination and origin' );
expect_same( $customer, $integration->use_store_tax_location( $customer, $order ), 'POS delivery retains WooCommerce tax location' );
$order->shipping = array();
$order->source = '';
expect_same( $packages, $integration->use_store_address( $packages, $order ), 'Online order packages are unchanged' );
$order->source = 'foosales_app';
$countries->country = 'CA';
expect_same( $packages, $integration->use_store_address( $packages, $order ), 'Non-US storefront is not reported as US' );
$countries->country = 'US';
$options['woocommerce_store_address'] = '';
expect_same( $packages, $integration->use_store_address( $packages, $order ), 'Incomplete storefront does not create a false address' );

echo "FooSales regression tests passed.\n";
