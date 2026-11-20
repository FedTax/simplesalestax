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

require_once __DIR__ . '/fixtures/credential-settings.php';
class SST_Settings {
	use SST_Test_Credential_Settings;
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
	public $items = array();
	public function get_id() { return 51528; }
	public function get_items( $type ) { return $this->items; }
	public function get_total() { return 628.79; }
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
class Refund_Product_Item {
	private $total;
	private $meta;
	public function __construct( $total, $meta = array() ) { $this->total = $total; $this->meta = $meta; }
	public function get_type() { return 'line_item'; }
	public function get_variation_id() { return 1190; }
	public function get_product_id() { return 1189; }
	public function get_total() { return $this->total; }
	public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? ''; }
}
class Refund_Test_Transaction {
	private $amount;
	private $items;
	public function __construct( $amount, $items = array() ) { $this->amount = $amount; $this->items = $items; }
	public function get_amount() { return $this->amount; }
	public function get_items( $types ) { return $this->items; }
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

// The same variation can be sold on its own, in more than one bundle, or
// included at $0. Refunds must target the specific captured line and price.
$refund_api->fail = false;
$refund_api->requests = array();
$order = new WC_Order();
$order->items = array(
	44 => new Refund_Product_Item( 40, array( '_sst_cart_item_key' => 'standalone' ) ),
	45 => new Refund_Product_Item( 60, array( '_bundle_cart_key' => 'discounted-child' ) ),
	46 => new Refund_Product_Item( 0, array( '_sst_cart_item_key' => 'free-child' ) ),
	47 => new Refund_Product_Item( 25 ),
);
$line = array( 'type' => 'line_item', 'id' => 1190, 'tic' => 30040, 'qty' => 1 );
$packages[0]['cart_items'] = array(
	$line + array( 'cart_id' => 'free-child', 'price' => 0 ),
	$line + array( 'cart_id' => 'standalone', 'price' => 40 ),
	array_merge( $line, array( 'cart_id' => 'discounted-child', 'price' => 30, 'qty' => 2 ) ),
	$line + array( 'cart_id' => 47, 'price' => 25 ),
);
$sst = new Refund_Test_Order( $order );
$sst->update_meta( 'status', 'captured' );
$sst->set_packages( $packages );
$refund = new Refund_Test_Transaction( 55, array(
	new Refund_Product_Item( -30, array( '_refunded_item_id' => 45 ) ),
	new Refund_Product_Item( 0, array( '_refunded_item_id' => 46 ) ),
	new Refund_Product_Item( -25, array( '_refunded_item_id' => 47 ) ),
) );
expect_same( true, $sst->do_refund( $refund ), 'Partial refund resolves both original order lines' );
$returned = $refund_api->requests[0]->getCartItems();
expect_same( array( 2, 3 ), array_map( function ( $item ) { return $item->getIndex(); }, $returned ), 'Refund excludes standalone and free copies of the variation' );
expect_same( array( 30, 25 ), array_map( function ( $item ) { return $item->getPrice(); }, $returned ), 'Refund uses each original captured price' );
expect_same( 1.0, (float) $returned[0]->getQty(), 'Refund returns one of the two discounted units' );
expect_same( false, $sst->do_refund( new Refund_Test_Transaction( 20 ) ), 'No amount-only partial refund is sent' );
expect_same( 1, count( $refund_api->requests ), 'No return request for unallocated amount' );

$order->refunded = $order->get_total();
expect_same( true, $sst->do_refund( new Refund_Test_Transaction( $order->get_total() ) ), 'Full amount-only refund uses captured lines' );
$returned = $refund_api->requests[1]->getCartItems();
expect_same( array( 1, 2, 3 ), array_map( function ( $item ) { return $item->getIndex(); }, $returned ), 'Full refund returns every priced line once' );

echo "Order refund regression checks passed.\n";
