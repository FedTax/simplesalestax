<?php

// Standalone capture checks with a fake API; no transactions leave this process.
// Run: php tests/order-capture-regression.php
define( 'ABSPATH', __DIR__ . '/' );

function __( $message ) { return $message; }
function add_filter() {}
function apply_filters( $name, $value ) { return $value; }
function sst_unslash( $value ) { return $value; }
function maybe_unserialize( $value ) { return 'a:' === substr( $value, 0, 2 ) ? unserialize( $value ) : $value; }
function sst_integration_mode() { return 'realtime'; }
function wp_json_encode( $value ) { return json_encode( $value ); }

class SST_Settings {
	public static $capture = 'yes';
	public static function get( $key, $default = null ) {
		if ( 'capture_orders_in_taxcloud' === $key ) {
			return self::$capture;
		}
		return in_array( $key, array( 'tc_id', 'tc_key' ), true ) ? 'test' : $default;
	}
	public static function get_capture_trigger() { return 'both'; }
}
class SST_Logger {
	public static function add() {}
	public static function order_log() {}
}
class WC_Order {
	public $meta = array();
	public $saves = 0;
	public function get_id() { return 605; }
	public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function save() { ++$this->saves; }
}
class Capture_Test_Api {
	public $requests = array();
	public $fail = false;
	public function AuthorizedWithCapture( $request ) {
		$this->requests[] = $request;
		if ( $this->fail ) {
			throw new RuntimeException( 'Test capture rejected' );
		}
	}
}
$capture_api = new Capture_Test_Api();
function TaxCloud() { global $capture_api; return $capture_api; }

require dirname( __DIR__ ) . '/includes/vendor/autoload.php';
require dirname( __DIR__ ) . '/includes/abstracts/class-sst-abstract-cart.php';
require dirname( __DIR__ ) . '/includes/class-sst-order.php';

class Capture_Test_Order extends SST_Order {
	public $lookups = 0;
	public $lookup_packages = array();
	public $errors = array();
	protected function do_lookup() {
		++$this->lookups;
		$this->set_packages( $this->lookup_packages );
		return $this->lookup_packages;
	}
	protected function handle_error( $message ) { $this->errors[] = $message; }
}
function expect_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}
$packages = array( array(
	'customer_id' => 0,
	'cart_id'     => 'test-lookup-cart',
	'cart_items'  => array( array( 'type' => 'line_item', 'id' => 503, 'tic' => 0, 'price' => 100, 'qty' => 1 ) ),
) );

// Online orders retain their existing lookup cart when captured.
$order = new WC_Order();
$sst = new Capture_Test_Order( $order );
$sst->set_packages( $packages );
expect_same( true, $sst->do_capture(), 'Online capture succeeds' );
expect_same( 0, $sst->lookups, 'Online capture reuses its lookup' );
expect_same( 1, count( $capture_api->requests ), 'One capture request sent' );
$request = $capture_api->requests[0];
expect_same( 'test-lookup-cart', $request->getCartID(), 'Capture references the calculated cart' );
expect_same( 0, $request->getCustomerID(), 'Guest customer zero is valid' );
expect_same( '605_0', $request->getOrderID(), 'Package order ID is stable' );
expect_same( 'captured', $sst->get_taxcloud_status(), 'Capture status saved' );
expect_same( 1, $order->saves, 'Order saved after successful API call' );
expect_same( false, $sst->do_capture(), 'Duplicate status trigger is skipped' );
expect_same( 1, count( $capture_api->requests ), 'Duplicate trigger sends no second capture' );

// Paid FooSales orders have WooCommerce tax but no TaxCloud lookup metadata.
// Capture must prepare lookup packages without recalculating the paid totals.
$order = new WC_Order();
$order->meta['_foosales_order_source'] = 'foosales_app';
$sst = new Capture_Test_Order( $order );
$sst->lookup_packages = $packages;
expect_same( true, $sst->do_capture(), 'POS capture rebuilds missing lookup metadata' );
expect_same( 1, $sst->lookups, 'POS capture performs one lookup' );
expect_same( 2, count( $capture_api->requests ), 'POS capture request sent' );
expect_same( 'captured', $sst->get_taxcloud_status(), 'POS marked captured after API success' );

// Failed lookup must not send capture or mark the order captured.
$order = new WC_Order();
$sst = new Capture_Test_Order( $order );
expect_same( false, $sst->do_capture(), 'Missing lookup data fails capture' );
expect_same( 2, count( $capture_api->requests ), 'Missing lookup sends no capture' );
expect_same( 'pending', $sst->get_taxcloud_status(), 'Missing lookup stays pending' );
expect_same( 0, $order->saves, 'Failed lookup does not save success' );

// Rejected requests stay pending and can be retried against the same cart.
$order = new WC_Order();
$sst = new Capture_Test_Order( $order );
$sst->set_packages( $packages );
$capture_api->fail = true;
expect_same( false, $sst->do_capture(), 'API rejection fails capture' );
expect_same( 'pending', $sst->get_taxcloud_status(), 'Rejected capture stays pending' );
expect_same( 0, $order->saves, 'Rejected capture does not save success' );
expect_same( 1, count( $sst->errors ), 'Rejection is surfaced' );
$capture_api->fail = false;
expect_same( true, $sst->do_capture(), 'Capture retry succeeds' );
expect_same( 0, $sst->lookups, 'Retry preserves original lookup cart' );
expect_same( 'captured', $sst->get_taxcloud_status(), 'Retry saves captured state' );

// The configured capture switch blocks lookup and transmission entirely.
SST_Settings::$capture = 'no';
$before = count( $capture_api->requests );
$order = new WC_Order();
$sst = new Capture_Test_Order( $order );
$sst->lookup_packages = $packages;
expect_same( false, $sst->do_capture(), 'Disabled capture is skipped' );
expect_same( 0, $sst->lookups, 'Disabled capture does not look up' );
expect_same( $before, count( $capture_api->requests ), 'Disabled capture sends no requests' );
expect_same( 'pending', $sst->get_taxcloud_status(), 'Disabled capture stays pending' );

echo "Order capture regression checks passed (fake API; no external capture).\n";
