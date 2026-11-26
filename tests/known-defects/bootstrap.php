<?php

// Shared stubs for the known-defect proofs in this directory: WordPress and
// WooCommerce are stubbed, the real SST_Order and SST_Checkout package code runs,
// and no network call is made. Each proof exits 1 while its defect is present
// (strict expected failure) and 0 once it is fixed.
define( 'ABSPATH', __DIR__ . '/' );

$filters  = array();
$settings = array( 'tc_id' => 'test', 'tc_key' => 'test' );
$options  = array( 'woocommerce_store_address' => '1399 Horizon Ave', 'woocommerce_store_address_2' => '' );
$products = array();

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
function WC() { global $woocommerce; return $woocommerce; }
function get_option( $key, $default = false ) { global $options; return $options[ $key ] ?? $default; }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, $args ); }
function maybe_unserialize( $value ) { return $value; }
function get_transient() { return false; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wc_get_product( $id ) { global $products; return $products[ $id ] ?? false; }
function sst_storefront_active() { return false; }
function get_current_user_id() { return 0; }

class SST_Settings {
	public static function get( $key, $default = null ) { global $settings; return $settings[ $key ] ?? $default; }
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
	public function log_limit_reached() {}
}
class Test_Product {
	private $ships;
	public function __construct( $ships ) { $this->ships = $ships; }
	public function needs_shipping() { return $this->ships; }
}
class WC_Order {
	public $items = array();
	public function get_id() { return 258411; }
	public function get_meta( $key ) { return ''; }
	public function update_meta_data( $key, $value ) {}
	public function get_items() { return $this->items; }
	public function get_shipping_methods() { return array(); }
	public function get_fees() { return array(); }
	public function get_user_id() { return 0; }
	public function __call( $name, $args ) { return test_address_part( $name ); }
}
class Test_Customer {
	public function __call( $name, $args ) { return test_address_part( $name ); }
}
// Billing address is in VA and shipping address is in TX; every other part is fixed text.
function test_address_part( $getter ) {
	if ( false !== strpos( $getter, 'country' ) ) {
		return 'US';
	}
	if ( false !== strpos( $getter, 'state' ) ) {
		return false !== strpos( $getter, 'billing' ) ? 'VA' : 'TX';
	}
	return false !== strpos( $getter, 'postcode' ) ? '80026' : 'Test Value';
}
class Test_Cart {
	public $cart = array();
	public function get_cart() { return $this->cart; }
	public function get_fees() { return array(); }
}

$woocommerce            = new stdClass();
$woocommerce->customer  = new Test_Customer();
$woocommerce->session   = new class { public function set() {} public function get() { return null; } };
$woocommerce->shipping  = new class {
	public $packages = array();
	public function get_packages() { return $this->packages; }
};
$woocommerce->countries = new class {
	public function get_base_country() { return 'US'; }
	public function get_base_state() { return 'CO'; }
	public function get_base_city() { return 'Lafayette'; }
	public function get_base_postcode() { return '80026'; }
};

require dirname( __DIR__, 2 ) . '/includes/vendor/autoload.php';
require dirname( __DIR__, 2 ) . '/includes/sst-functions.php';
require dirname( __DIR__, 2 ) . '/includes/class-sst-addresses.php';
require dirname( __DIR__, 2 ) . '/includes/class-sst-origin-address.php';
require dirname( __DIR__, 2 ) . '/includes/abstracts/class-sst-abstract-cart.php';
require dirname( __DIR__, 2 ) . '/includes/class-sst-order.php';
require dirname( __DIR__, 2 ) . '/includes/frontend/class-sst-cart-proxy.php';
require dirname( __DIR__, 2 ) . '/includes/frontend/class-sst-checkout.php';

class Test_Checkout extends SST_Checkout {
	public function base_packages( $cart ) {
		( new ReflectionProperty( SST_Checkout::class, 'cart' ) )->setValue( $this, $cart );
		return $this->get_base_packages();
	}
}

$origin                               = new SST_Origin_Address( 'origin-1', true, '1399 Horizon Ave', '', 'Lafayette', 'CO', '80026' );
$settings['addresses']                = array( 'origin-1' => wp_json_encode( $origin ) );
$settings['default_origin_addresses'] = array( 'origin-1' );

// A shippable product and a product that does not need shipping.
$products = array( 11 => new Test_Product( true ), 12 => new Test_Product( false ) );

function order_with_lines( $shippable_total, $virtual_total ) {
	$order        = new WC_Order();
	$order->items = array(
		1 => array( 'product_id' => 11, 'variation_id' => 0, 'qty' => 1, 'line_total' => $shippable_total, 'line_subtotal' => $shippable_total ),
		2 => array( 'product_id' => 12, 'variation_id' => 0, 'qty' => 1, 'line_total' => $virtual_total, 'line_subtotal' => $virtual_total ),
	);
	return $order;
}

// Base packages the cart checkout builds for the same two lines.
function checkout_packages_with_lines( $shippable_total, $virtual_total ) {
	global $products, $woocommerce;
	$cart       = new Test_Cart();
	$cart->cart = array(
		'a' => array( 'key' => 'a', 'product_id' => 11, 'variation_id' => 0, 'quantity' => 1, 'line_total' => $shippable_total, 'data' => $products[11] ),
		'b' => array( 'key' => 'b', 'product_id' => 12, 'variation_id' => 0, 'quantity' => 1, 'line_total' => $virtual_total, 'data' => $products[12] ),
	);
	$woocommerce->shipping->packages = array(
		0 => array(
			'contents'    => array( 'a' => $cart->cart['a'] ),
			'destination' => array( 'country' => 'US', 'state' => 'TX', 'postcode' => '80026' ),
		),
	);
	return ( new Test_Checkout() )->base_packages( $cart );
}

// Number of product lines the packages send for tax; a package set that drops a line is a defect too.
function line_count( $packages ) {
	return array_sum( array_map( fn( $package ) => count( $package['contents'] ), $packages ) );
}

// Print one line per case and exit 1 when any case shows the defect.
function finish( $results ) {
	$failed = false;
	foreach ( $results as $label => $defect_present ) {
		echo ( $defect_present ? 'FAIL: ' : 'PASS: ' ) . $label . "\n";
		$failed = $failed || $defect_present;
	}
	exit( $failed ? 1 : 0 );
}
