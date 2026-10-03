<?php

// Standalone regression test: php tests/local-pickup-address-regression.php
define( 'ABSPATH', __DIR__ . '/' );

$test_filters = array();
$test_settings = array();

function apply_filters( $hook, $value, ...$args ) {
	global $test_filters;
	return isset( $test_filters[ $hook ] ) ? $test_filters[ $hook ]( $value, ...$args ) : $value;
}
function __( $message ) { return $message; }
function WC() { global $test_woocommerce; return $test_woocommerce; }
function add_filter() {}
function add_action() {}
function sst_storefront_active() { return false; }

class SST_Settings {
	public static function get( $key, $default = null ) {
		global $test_settings;
		return $test_settings[ $key ] ?? $default;
	}
}

class SST_Logger {
	public static function add() {}
	public static function order_log() {}
}

require dirname( __DIR__ ) . '/includes/vendor/autoload.php';
require dirname( __DIR__ ) . '/includes/class-sst-origin-address.php';
require dirname( __DIR__ ) . '/includes/class-sst-addresses.php';
require dirname( __DIR__ ) . '/includes/class-sst-shipping.php';
require dirname( __DIR__ ) . '/includes/abstracts/class-sst-abstract-cart.php';
require dirname( __DIR__ ) . '/includes/frontend/class-sst-checkout.php';
require dirname( __DIR__ ) . '/includes/class-sst-order.php';

class Local_Pickup_Test_Session {
	public $chosen_shipping_methods = array();
	public function get( $key ) { return $this->$key; }
}

class Local_Pickup_Test_Checkout extends SST_Checkout {
	public $test_packages = array();
	public function __construct() {}
	public function packages() { return $this->create_packages(); }
	protected function get_base_packages() { return $this->test_packages; }
	// Inspect the destination passed to splitting without making TaxCloud API calls.
	protected function split_package( $package ) { return array( $package ); }
}

class Local_Pickup_Test_Order extends SST_Order {
	public $test_packages = array();
	public $shipping_address;
	public function __construct() {
		$this->order = new class {
			public function get_id() { return 123; }
		};
	}
	public function packages() { return $this->create_packages(); }
	protected function get_base_packages() { return $this->test_packages; }
	protected function get_shipping_address() { return $this->shipping_address; }
	protected function split_package( $package ) { return array( $package ); }
	public function order_context() { return $this->order; }
}

function expect_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}

function raw_package( $destination, $method_id = 'local_pickup' ) {
	$rate = (object) array( 'id' => $method_id . ':3', 'method_id' => $method_id, 'cost' => '0.00' );
	return array(
		'contents'    => array( 'product' => array( 'product_id' => 123 ) ),
		'destination' => $destination,
		'rates'       => array( $rate->id => $rate ),
		'shipping'    => $rate,
	);
}

$customer_destination = array(
	'country'   => 'US',
	'address'   => '100 Customer St',
	'address_1' => '100 Customer St',
	'address_2' => 'Apt 2',
	'city'      => 'New York',
	'state'     => 'NY',
	'postcode'  => '10001',
);
$pickup_destination = array(
	'country'   => 'US',
	'address'   => '200 Pickup St',
	'address_2' => 'Suite 3',
	'city'      => 'Charlotte',
	'state'     => 'NC',
	'postcode'  => '28202',
);
$default_address = new SST_Origin_Address( 'origin', true, '200 Pickup St', 'Suite 3', 'Charlotte', 'NC', '28202', '' );
$custom_address = new TaxCloud\Address( '200 Pickup St', 'Suite 3', 'Charlotte', 'NC', '28202' );
$test_woocommerce = (object) array( 'session' => new Local_Pickup_Test_Session() );
$test_filters['wootax_add_fees'] = function () { return false; };

foreach ( array( new Local_Pickup_Test_Checkout(), new Local_Pickup_Test_Order() ) as $subject ) {
	$label = get_class( $subject );
	$package = raw_package( $customer_destination );
	$subject->test_packages = array( 7 => $package );
	$test_woocommerce->session->chosen_shipping_methods = array( 7 => 'local_pickup:3' );
	if ( $subject instanceof Local_Pickup_Test_Order ) {
		$subject->shipping_address = $customer_destination;
	}

	$test_settings = array();
	unset( $test_filters['wootax_pickup_address'] );
	expect_same( null, SST_Addresses::get_default_address(), 'No origin configured' );
	$packages = $subject->packages();
	expect_same( 1, count( $packages ), "$label: no origin retains package" );
	expect_same( $customer_destination, $packages[0]['destination'], "$label: no origin retains customer destination" );
	expect_same( $package['contents'], $packages[0]['contents'], "$label: no origin retains contents" );
	expect_same( 'local_pickup', $packages[0]['shipping']->method_id, "$label: no origin retains shipping method" );
	expect_same( 'local_pickup:3', $package['shipping']->id, "$label: original rate is unchanged" );
	if ( $subject instanceof Local_Pickup_Test_Checkout ) {
		expect_same( 7, $packages[0]['shipping']->id, 'Checkout retains package shipping key' );
	}

	$nondefault_address = clone $default_address;
	$nondefault_address->setDefault( false );
	$test_settings['addresses'] = array( json_encode( $nondefault_address ) );
	expect_same( null, SST_Addresses::get_default_address(), 'Origin exists but is not default' );
	expect_same( $customer_destination, $subject->packages()[0]['destination'], "$label: no default retains destination" );

	$test_settings['addresses'] = array( json_encode( $default_address ) );
	expect_same( $pickup_destination, $subject->packages()[0]['destination'], "$label: default pickup overrides destination" );

	$expected_context = $subject instanceof Local_Pickup_Test_Order ? $subject->order_context() : null;
	$test_filters['wootax_pickup_address'] = function ( $address, $context ) use ( $expected_context ) {
		expect_same( $expected_context, $context, 'Pickup filter context is preserved' );
		return null;
	};
	expect_same( $customer_destination, $subject->packages()[0]['destination'], "$label: null pickup filter retains destination" );

	$test_settings = array();
	$test_filters['wootax_pickup_address'] = function ( $address ) use ( $custom_address ) {
		expect_same( null, $address, 'Pickup filter can supply address without a default' );
		return $custom_address;
	};
	expect_same( $pickup_destination, $subject->packages()[0]['destination'], "$label: custom pickup overrides destination" );

	$test_filters['wootax_pickup_address'] = function () {
		throw new RuntimeException( 'Pickup filter should not run for delivery or when pickup base tax is disabled' );
	};
	$test_filters['woocommerce_apply_base_tax_for_local_pickup'] = function () { return false; };
	expect_same( $customer_destination, $subject->packages()[0]['destination'], "$label: pickup base tax opt-out is preserved" );
	unset( $test_filters['woocommerce_apply_base_tax_for_local_pickup'] );
	$subject->test_packages = array( 7 => raw_package( $customer_destination, 'flat_rate' ) );
	$test_woocommerce->session->chosen_shipping_methods = array( 7 => 'flat_rate:3' );
	expect_same( $customer_destination, $subject->packages()[0]['destination'], "$label: delivery destination is unchanged" );

	unset( $test_filters['wootax_pickup_address'] );
	$subject->test_packages = array( 7 => $package, 9 => raw_package( $customer_destination, 'flat_rate' ) );
	$test_woocommerce->session->chosen_shipping_methods = array( 7 => 'local_pickup:3', 9 => 'flat_rate:3' );
	$packages = $subject->packages();
	expect_same( 2, count( $packages ), "$label: missing pickup address does not skip other packages" );
	expect_same( $customer_destination, $packages[1]['destination'], "$label: mixed delivery destination is preserved" );

	$invalid_destination = $customer_destination;
	$invalid_destination['country'] = 'CA';
	$subject->test_packages = array( 7 => raw_package( $invalid_destination ) );
	expect_same( array(), $subject->packages(), "$label: missing pickup address still validates customer destination" );

	if ( $subject instanceof Local_Pickup_Test_Order ) {
		unset( $package['destination'] );
		$subject->test_packages = array( 7 => $package );
		expect_same( $customer_destination, $subject->packages()[0]['destination'], 'Order without package destination falls back to shipping address' );
	}
}

echo "Local pickup address regression checks passed.\n";
