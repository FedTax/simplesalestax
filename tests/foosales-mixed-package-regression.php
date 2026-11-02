<?php

// Standalone regression test: php tests/foosales-mixed-package-regression.php
// A FooSales in-store sale mixing a virtual and a physical item must report
// both items to TaxCloud. Drives the real SST_Order::create_packages with the
// real SST_FooSales filter; only WordPress/WooCommerce and network guards are stubbed.
define( 'ABSPATH', __DIR__ . '/' );

$filters  = array();
$settings = array( 'tc_id' => 'test', 'tc_key' => 'test' );
$options  = array( 'woocommerce_store_address' => '1399 Horizon Ave', 'woocommerce_store_address_2' => '' );

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

class SST_Settings {
	public static function get( $key, $default = null ) { global $settings; return $settings[ $key ] ?? $default; }
}
class SST_Logger {
	public static function add() {}
	public static function order_log() {}
}
// Network guard: makes SST_Addresses::verify_address return the address unverified, with no API call.
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
	public $meta = array( '_foosales_order_source' => 'foosales_app' );
	public $items = array();
	public function get_id() { return 606; }
	public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function get_items() { return $this->items; }
	public function get_shipping_methods() { return array(); }
	public function get_fees() { return array(); }
	public function get_user_id() { return 0; }
	// Guest POS order: no customer address.
	public function __call( $name, $args ) { return ''; }
}

$woocommerce            = new stdClass();
$woocommerce->countries = new class {
	public function get_base_country() { return 'US'; }
	public function get_base_state() { return 'CO'; }
	public function get_base_city() { return 'Lafayette'; }
	public function get_base_postcode() { return '80026'; }
};

require dirname( __DIR__ ) . '/includes/vendor/autoload.php';
require dirname( __DIR__ ) . '/includes/sst-functions.php';
require dirname( __DIR__ ) . '/includes/class-sst-addresses.php';
require dirname( __DIR__ ) . '/includes/class-sst-origin-address.php';
require dirname( __DIR__ ) . '/includes/abstracts/class-sst-abstract-cart.php';
require dirname( __DIR__ ) . '/includes/class-sst-order.php';
require getenv( 'SST_FOOSALES_TEST_FILE' ) ?: dirname( __DIR__ ) . '/includes/integrations/class-sst-foosales.php';

function expect_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}
function reported_items( $order ) {
	$items = array();
	foreach ( ( new SST_Order( $order ) )->create_packages() as $package ) {
		$items = array_merge( $items, array_keys( $package['contents'] ) );
	}
	sort( $items );
	return $items;
}

// Gift card (virtual) plus a physical product, sold at the counter.
$products = array( 11 => new Test_Product( false ), 12 => new Test_Product( true ) );
$order    = new WC_Order();
$order->items = array(
	501 => array( 'product_id' => 11, 'variation_id' => 0, 'qty' => 1, 'line_total' => 25, 'line_subtotal' => 25 ),
	503 => array( 'product_id' => 12, 'variation_id' => 0, 'qty' => 1, 'line_total' => 100, 'line_subtotal' => 100 ),
);

// Control: with the virtual split disabled there is one package, and both items survive.
$settings['disable_virtual_split'] = 'yes';
expect_same( array( 501, 503 ), reported_items( $order ), 'Single-package POS order reports both items' );
unset( $settings['disable_virtual_split'] );

// Default settings: virtual and physical items go to separate base packages.
expect_same( array( 501, 503 ), reported_items( $order ), 'Mixed POS order reports virtual and physical items' );

$packages = ( new SST_Order( $order ) )->create_packages();
expect_same( 2, count( $packages ), 'Virtual and physical packages both survive' );
expect_same( array_keys( $packages ), array_keys( ( new SST_Order( $order ) )->create_packages() ), 'Package identities remain stable across lookups' );

// Multiple virtual lines stay together without replacing the physical lines.
$order->items[502] = array( 'product_id' => 11, 'variation_id' => 0, 'qty' => 2, 'line_total' => 50, 'line_subtotal' => 50 );
expect_same( array( 501, 502, 503 ), reported_items( $order ), 'Every mixed-order line is reported exactly once' );
unset( $order->items[503] );
expect_same( array( 501, 502 ), reported_items( $order ), 'Virtual-only orders retain every line' );
$order->items = array( 503 => array( 'product_id' => 12, 'variation_id' => 0, 'qty' => 1, 'line_total' => 100, 'line_subtotal' => 100 ) );
expect_same( array( 503 ), reported_items( $order ), 'Physical-only orders retain their line' );

echo "FooSales mixed-package regression checks passed.\n";
