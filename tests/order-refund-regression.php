<?php

// Standalone regression test: php tests/order-refund-regression.php
// Set SST_ORDER_TEST_FILE to compare against an earlier class-sst-order.php.
define( 'ABSPATH', __DIR__ . '/' );

function __( $message ) { return $message; }
function add_filter() {}
function apply_filters( $name, $value ) { return $value; }
function did_action( $name ) { return 'woocommerce_order_partially_refunded' === $name ? 1 : 0; }
function sst_unslash( $value ) { return $value; }
function maybe_unserialize( $value ) { return 'a:' === substr( $value, 0, 2 ) ? unserialize( $value ) : $value; }
function sst_integration_mode() { return 'realtime'; }
function is_wp_error() { return false; }

class SST_Settings {
	public static function get( $key, $default = null ) {
		return in_array( $key, array( 'tc_id', 'tc_key' ), true ) ? 'test' : $default;
	}
}
class SST_Logger {
	public static function add() {}
	public static function order_log() {}
}
class WC_Order {
	public $meta = array();
	public $refunded = 0;
	public $notes = array();
	public function get_id() { return 51528; }
	public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function get_total_refunded() { return $this->refunded; }
	public function get_remaining_refund_amount() { return 628.79 - $this->refunded; }
	public function add_order_note( $message ) { $this->notes[] = $message; }
	public function save() {}
}
class Refund_Shipping_Item {
	public function get_type() { return 'shipping'; }
	public function get_method_id() { return 'flat_rate:7'; }
	public function get_total() { return -25.30; }
}
class WC_Order_Refund extends WC_Order {
	public function get_items( $types ) { return array( new Refund_Shipping_Item() ); }
}
class Refund_Api {
	public $requests = array();
	public $fail = false;
	public function Returned( $request ) {
		if ( $this->fail ) {
			throw new RuntimeException( 'The item amount exceeds the original item amount.' );
		}
		$this->requests[] = $request;
	}
}
$refund_api = new Refund_Api();
function TaxCloud() { global $refund_api; return $refund_api; }

require dirname( __DIR__ ) . '/includes/vendor/autoload.php';
require dirname( __DIR__ ) . '/includes/abstracts/class-sst-abstract-cart.php';
require getenv( 'SST_ORDER_TEST_FILE' ) ?: dirname( __DIR__ ) . '/includes/class-sst-order.php';

class Refund_Test_Order extends SST_Order {
	public $resets = 0;
	public $lookups = 0;
	public $errors = array();
	protected function reset_taxes() { ++$this->resets; }
	protected function update_taxes() {}
	protected function do_lookup() {
		++$this->lookups;
		$this->set_packages( array() );
		return array();
	}
	protected function get_package_order_id( $key, $package = array() ) { return '51528_' . $key; }
	protected function handle_error( $message ) { $this->errors[] = $message; }
}
function expect_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}
$packages = array( array(
	'cart_id' => 'captured-cart',
	'customer_id' => 4597,
	'shipping_method' => 'flat_rate:7',
	'cart_items' => array(
		array( 'type' => 'line_item', 'id' => 1190, 'tic' => 0, 'price' => 139, 'qty' => 1 ),
		array( 'type' => 'shipping', 'id' => 'SHIPPING', 'tic' => 11010, 'price' => 350, 'qty' => 1 ),
	),
) );

// Recalculation must retain the original captured cart, prices, item indexes,
// taxes and refund state, including orders reopened to pending in WooCommerce.
foreach ( array( 'captured', 'partially_refunded', 'refunded' ) as $status ) {
	$order = new WC_Order();
	$sst = new Refund_Test_Order( $order );
	$sst->update_meta( 'status', $status );
	$sst->set_packages( $packages );
	expect_same( true, $sst->calculate_taxes(), $status . ' recalculation succeeds as a no-op' );
	expect_same( 0, $sst->resets, $status . ' does not reset tax' );
	expect_same( 0, $sst->lookups, $status . ' does not perform a lookup' );
	expect_same( $packages, $sst->get_packages(), $status . ' retains packages' );
}
$order = new WC_Order();
$order->refunded = 0.50;
$sst = new Refund_Test_Order( $order );
$sst->set_packages( $packages );
$sst->calculate_taxes();
expect_same( 0, $sst->resets, 'Sub-dollar refund prevents recalculation' );
expect_same( $packages, $sst->get_packages(), 'Refunded pending order retains packages' );

$order = new WC_Order();
$sst = new Refund_Test_Order( $order );
$sst->calculate_taxes();
expect_same( 1, $sst->resets, 'Uncaptured order still calculates taxes' );
expect_same( 1, $sst->lookups, 'Uncaptured order still performs lookup' );

// A shipping-only refund sends a fractional quantity of the original shipping
// line. TaxCloud computes the returned tax from that captured line.
$order = new WC_Order();
$order->refunded = 26.69;
$sst = new Refund_Test_Order( $order );
$sst->update_meta( 'status', 'captured' );
$sst->set_packages( $packages );
$items = new WC_Order_Refund();
expect_same( true, $sst->do_refund( $items ), 'Shipping refund succeeds' );
expect_same( 1, count( $refund_api->requests ), 'One return sent' );
$request = $refund_api->requests[0];
expect_same( '51528_0', $request->getOrderID(), 'Return uses captured order ID' );
expect_same( 1, count( $request->getCartItems() ), 'Only shipping returned' );
$item = $request->getCartItems()[0];
expect_same( 'SHIPPING', $item->getItemID(), 'Original shipping ID retained' );
expect_same( 1, $item->getIndex(), 'Original shipping index retained' );
expect_same( 11010, $item->getTIC(), 'Original shipping TIC retained' );
expect_same( 350, $item->getPrice(), 'Original shipping price retained' );
expect_same( 25.30 / 350, $item->getQty(), 'Correct fractional shipping quantity' );
expect_same( 'partially_refunded', $sst->get_taxcloud_status(), 'Partial refund state saved' );
expect_same( true, $sst->do_refund( $items ), 'Second partial refund succeeds' );
expect_same( 2, count( $refund_api->requests ), 'Second return sent' );

$order = new WC_Order();
$sst = new Refund_Test_Order( $order );
$sst->update_meta( 'status', 'captured' );
expect_same( false, $sst->do_refund( $items ), 'Missing packages fail instead of reporting success' );
expect_same( 'captured', $sst->get_taxcloud_status(), 'Failed sync does not mark order refunded' );
expect_same( 1, count( $order->notes ), 'Failed sync leaves an actionable order note' );
expect_same( 2, count( $refund_api->requests ), 'Missing packages send no return' );

$order = new WC_Order();
$sst = new Refund_Test_Order( $order );
$sst->update_meta( 'status', 'captured' );
$sst->set_packages( $packages );
$refund_api->fail = true;
expect_same( false, $sst->do_refund( $items ), 'TaxCloud rejection reports failed sync' );
expect_same( 'captured', $sst->get_taxcloud_status(), 'Rejected return does not change sync status' );
expect_same( 1, count( $order->notes ), 'TaxCloud rejection leaves an order note' );
expect_same( true, false !== strpos( $order->notes[0], 'exceeds the original item amount' ), 'Order note explains rejection' );

echo "Order refund regression checks passed.\n";
