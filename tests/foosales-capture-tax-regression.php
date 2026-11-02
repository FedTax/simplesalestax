<?php

// Drives the real capture gate and FooSales action with fake external APIs.
// Run: php tests/foosales-capture-tax-regression.php
define( 'ABSPATH', __DIR__ . '/' );
$actions = array();
$decimals = 2;
function __( $message ) { return $message; }
function add_filter() {}
function add_action( $hook, $callback ) { global $actions; $actions[ $hook ][] = $callback; }
function do_action( $hook, ...$args ) { global $actions; foreach ( $actions[ $hook ] ?? array() as $callback ) { $callback( ...$args ); } }
function apply_filters( $hook, $value ) { return $value; }
function sst_unslash( $value ) { return $value; }
function maybe_unserialize( $value ) { return 'a:' === substr( $value, 0, 2 ) ? unserialize( $value ) : $value; }
function sst_integration_mode() { return 'realtime'; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wc_get_price_decimals() { global $decimals; return $decimals; }
class SST_Settings {
	public static $capture = 'yes';
	public static function get( $key, $default = null ) {
		if ( 'capture_orders_in_taxcloud' === $key ) { return self::$capture; }
		return in_array( $key, array( 'tc_id', 'tc_key' ), true ) ? 'test' : $default;
	}
	public static function get_capture_trigger() { return 'both'; }
}
class SST_Logger {
	public static $messages = array();
	public static function add() {}
	public static function order_log( $message ) { self::$messages[] = $message; }
}
class WC_Order {
	public $meta = array( '_foosales_order_source' => 'foosales_app' );
	public $notes = array();
	public $meta_saves = 0;
	public $tax = '9.21';
	public function get_id() { return 605; }
	public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function get_total_tax() { return $this->tax; }
	public function get_total() { return '109.21'; }
	public function get_currency() { return 'USD'; }
	public function add_order_note( $message ) { $this->notes[] = $message; }
	public function save_meta_data() { ++$this->meta_saves; }
	public function save() {}
}
class Capture_Tax_Api {
	public $fail = false;
	public $requests = 0;
	public function AuthorizedWithCapture() {
		++$this->requests;
		if ( $this->fail ) { throw new RuntimeException( 'Test capture rejected' ); }
	}
}
$api = new Capture_Tax_Api();
function TaxCloud() { global $api; return $api; }
require dirname( __DIR__ ) . '/includes/vendor/autoload.php';
require dirname( __DIR__ ) . '/includes/abstracts/class-sst-abstract-cart.php';
require getenv( 'SST_ORDER_TEST_FILE' ) ?: dirname( __DIR__ ) . '/includes/class-sst-order.php';
require dirname( __DIR__ ) . '/includes/integrations/class-sst-foosales.php';

class Capture_Tax_Order extends SST_Order {
	public $lookup_packages = array();
	public $v3_notes_at_capture;
	protected function do_lookup() { $this->set_packages( $this->lookup_packages ); return $this->lookup_packages; }
	protected function handle_error( $message ) {}
	protected function capture_order_v3( $packages, $order ) {
		// External API routing is covered separately. Observe that the actual
		// capture gate dispatches the FooSales check before entering V3.
		$this->v3_notes_at_capture = count( $order->notes );
		$this->update_meta( 'status', 'captured' );
		$order->save();
		return true;
	}
}
function expect_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) { throw new RuntimeException( $message . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) ); }
}
function quote_packages( $version, $taxes ) {
	$result = array();
	foreach ( $taxes as $key => $tax ) {
		$result[] = array( 'api_version' => $version, 'cart_id' => 'cart-' . $key, 'customer_id' => 0,
			'cart_items' => array( array( 'type' => 'line_item', 'id' => 503, 'price' => 100, 'qty' => 1 ) ),
			'response' => array( 'cart-' . $key => array( 0 => $tax ) ) );
	}
	return $result;
}
foreach ( array( 'v1', 'v3' ) as $version ) {
	foreach ( array( array( 9.21 ), array( 5.0, 4.21 ), array( 9.214 ) ) as $taxes ) {
		$order = new WC_Order();
		$sst = new Capture_Tax_Order( $order );
		$sst->lookup_packages = quote_packages( $version, $taxes );
		expect_same( true, $sst->do_capture(), "$version matching quote captures" );
		expect_same( array(), $order->notes, "$version compares aggregate tax at currency precision" );
	}
	foreach ( array( array( 9.31 ), array( 5.0, 4.31 ), array( 9.11 ), array( 0.0 ) ) as $taxes ) {
		$order = new WC_Order();
		$sst = new Capture_Tax_Order( $order );
		$sst->lookup_packages = quote_packages( $version, $taxes );
		expect_same( true, $sst->do_capture(), "$version mismatch preserves normal capture" );
		expect_same( 1, count( $order->notes ), "$version mismatch adds an internal order note" );
		expect_same( true, false !== strpos( $order->notes[0], '9.21 USD' ), "$version note identifies collected tax" );
		expect_same( true, false !== strpos( $order->notes[0], number_format( array_sum( $taxes ), 2 ) . ' USD' ), "$version note identifies TaxCloud tax" );
		expect_same( true, in_array( $order->notes[0], SST_Logger::$messages, true ), "$version discrepancy is logged" );
		expect_same( '9.21', $order->get_total_tax(), "$version paid tax is unchanged" );
		expect_same( '109.21', $order->get_total(), "$version paid total is unchanged" );
		if ( 'v3' === $version ) { expect_same( 1, $sst->v3_notes_at_capture, 'V3 receives the warning before API dispatch' ); }
		expect_same( false, $sst->do_capture(), "$version completion does not recapture" );
		expect_same( 1, count( $order->notes ), "$version duplicate completion does not duplicate notes" );
	}
}

// A rejected capture may be retried with a newly loaded order. Persist the
// warning marker before the request, retaining exactly one note on retry.
$order = new WC_Order();
$sst = new Capture_Tax_Order( $order );
$sst->lookup_packages = quote_packages( 'v1', array( 9.31 ) );
$api->fail = true;
expect_same( false, $sst->do_capture(), 'Rejected capture fails normally' );
expect_same( 1, $order->meta_saves, 'Mismatch marker persists even after capture rejection' );
$reloaded = clone $order;
$api->fail = false;
expect_same( true, ( new Capture_Tax_Order( $reloaded ) )->do_capture(), 'Reloaded order can retry capture' );
expect_same( 1, count( $reloaded->notes ), 'Reload/retry does not duplicate the warning' );

// Non-POS orders and historical packages without amounts do not get warnings.
foreach ( array( 'ordinary', 'missing-quote' ) as $scenario ) {
	$order = new WC_Order();
	$packages = quote_packages( 'v1', array( 9.31 ) );
	if ( 'ordinary' === $scenario ) { $order->meta = array(); } else { unset( $packages[0]['response'] ); }
	$sst = new Capture_Tax_Order( $order );
	$sst->set_packages( $packages );
	expect_same( true, $sst->do_capture(), "$scenario capture remains supported" );
	expect_same( array(), $order->notes, "$scenario does not produce a misleading warning" );
}

$decimals = 3;
$order = new WC_Order();
$order->tax = '9.211';
$sst = new Capture_Tax_Order( $order );
$sst->set_packages( quote_packages( 'v1', array( 9.212 ) ) );
expect_same( true, $sst->do_capture(), 'Three-decimal precision remains supported' );
expect_same( true, false !== strpos( $order->notes[0], '9.211 USD' ) && false !== strpos( $order->notes[0], '9.212 USD' ), 'Warning uses configured currency precision' );
$decimals = 2;

foreach ( array( 'not-a-number', NAN, INF ) as $amount ) {
	$order = new WC_Order();
	do_action( 'sst_before_order_capture', $order, quote_packages( 'v1', array( $amount ) ) );
	expect_same( array(), $order->notes, 'Unavailable quote amounts are not reported as zero tax' );
}

SST_Settings::$capture = 'no';
$order = new WC_Order();
$sst = new Capture_Tax_Order( $order );
$sst->set_packages( quote_packages( 'v1', array( 9.31 ) ) );
expect_same( false, $sst->do_capture(), 'Disabled capture is skipped' );
expect_same( array(), $order->notes, 'Disabled capture produces no capture warning' );
echo "FooSales capture-tax regression checks passed.\n";
