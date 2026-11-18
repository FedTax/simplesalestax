<?php

// Standalone regression: php tests/product-bundles-regression.php
define( 'ABSPATH', __DIR__ . '/' );

$inactive = in_array( '--inactive', $argv, true );
$hooks    = array();
function add_filter( $hook, $callback ) { $GLOBALS['hooks'][ $hook ] = $callback; }
if ( ! $inactive ) {
	function WC_PB() {
		return (object) array( 'product_data' => new Bundle_Test_Product_Data() );
	}
	function wc_pb_get_bundled_cart_items( $item, $cart ) {
		return array_intersect_key( $cart, array_flip( $item['bundled_items'] ?? array() ) );
	}
}

class WC_Product_Factory {
	public static function get_product_type( $id ) { return 10 === $id ? 'bundle' : 'simple'; }
}
class Bundle_Test_Product {
	public $ships;
	public $packed_value;
	private $id;
	public function __construct( $ships, $packed_value = null, $id = 0 ) {
		$this->ships        = $ships;
		$this->packed_value = $packed_value;
		$this->id           = $id;
	}
	public function needs_shipping() { return $this->ships; }
	public function get_id() { return $this->id; }
	public function is_type( $type ) { return 'simple' === $type; }
}
class Bundle_Test_Product_Data {
	public function get( $product, $key ) { return $product->packed_value; }
}
class Bundle_Test_Cart {
	private $items;
	public function __construct( $items ) { $this->items = $items; }
	public function get_cart() { return $this->items; }
}
class Bundle_Test_Order_Item {
	private $meta;
	public function __construct( $meta = array() ) { $this->meta = $meta; }
	public function get_meta( $key, $single ) { return $this->meta[ $key ] ?? ''; }
	public function meta_exists( $key ) { return isset( $this->meta[ $key ] ); }
}

function bundle_assert( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}
function bundle_item( $id, $total, $ships, $packed_value = null ) {
	return array( 'product_id' => $id, 'line_total' => $total, 'data' => new Bundle_Test_Product( $ships, $packed_value, $id ) );
}

require dirname( __DIR__ ) . '/includes/integrations/class-sst-product-bundles.php';

$integration = new SST_Product_Bundles( ! $inactive );
if ( $inactive ) {
	bundle_assert( false, isset( $hooks['wootax_cart_packages_before_split'] ), 'Active bundle cart package hook is absent' );
	$purchase = $hooks['woocommerce_is_purchasable'];
	bundle_assert( false, $purchase( true, new Bundle_Test_Product( true, null, 10 ) ), 'Fallback simple bundle cannot be purchased' );
	bundle_assert( true, $purchase( true, new Bundle_Test_Product( true, null, 20 ) ), 'Ordinary product remains purchasable' );
	$restore = $hooks['woocommerce_cart_item_is_purchasable'];
	$yith_child = array( 'bundled_by' => 'yith-parent', 'bundled_item_id' => 1 );
	bundle_assert( true, $restore( true, 'yith-child', $yith_child ), 'Saved YITH bundle child remains purchasable' );
	bundle_assert( false, $restore( false, 'yith-child', $yith_child ), 'An existing rejection is preserved' );
	$stamp = array( 1 => array( 'product_id' => 20, 'quantity' => 1 ) );
	$woo_child = array( 'bundled_by' => 'bundle', 'bundled_item_id' => 1, 'stamp' => $stamp );
	bundle_assert( false, $restore( true, 'old-child', $woo_child ), 'Saved Woo bundle child cannot become a standalone purchase' );
	bundle_assert( true, $restore( true, 'unrelated-stamp', array( 'stamp' => array( 2 => $stamp[1] ) ) + $yith_child ), 'Unrelated configuration does not identify a Woo bundle child' );
	bundle_assert( true, $restore( true, 'ordinary', array() ), 'Saved ordinary item remains purchasable' );
	$reorder = $hooks['woocommerce_order_again_cart_item_data'];
	$child = new Bundle_Test_Order_Item( array( '_bundled_by' => 'bundle', '_bundled_item_id' => '1', '_stamp' => $stamp ) );
	bundle_assert( false, $restore( true, 'reorder', $reorder( array(), $child ) ), 'Reordering cannot convert a bundled component to a standalone purchase' );
	$other_child = new Bundle_Test_Order_Item( array( '_bundled_by' => 'yith-parent', '_bundled_item_id' => '1' ) );
	bundle_assert( array( 'other_data' => true ), $reorder( array( 'other_data' => true ), $other_child ), 'Reordering preserves other plugins\' cart data' );
} else {
	$cart_items = array(
		'parent'   => bundle_item( 10, 10, true ) + array( 'bundled_items' => array( 'packed', 'free', 'digital', 'own-box' ) ),
		'packed'   => bundle_item( 20, 60, false, 30 ) + array( 'quantity' => 2 ),
		'free'     => bundle_item( 30, 0, false, 0 ),
		'digital'  => bundle_item( 40, 12, false ),
		'own-box'  => bundle_item( 50, 20, true ),
	);
	$shipping = array( 'contents_cost' => 70, 'rates' => array( 'flat_rate' => 5 ), 'destination' => 'ship' );
	$aggregated = array_replace( $cart_items['parent'], array( 'line_total' => 70 ) );
	foreach ( array( true, false ) as $split ) {
		$packages = array(
			2 => $shipping + array( 'contents' => array( 'parent' => $aggregated ) ),
			4 => array( 'contents_cost' => 20, 'contents' => array( 'own-box' => $cart_items['own-box'] ) ),
		);
		$virtual = array_intersect_key( $cart_items, array_flip( array( 'packed', 'free', 'digital' ) ) );
		if ( $split ) {
			$packages[5] = array( 'contents' => $virtual, 'destination' => 'bill' );
		} else {
			$packages[2]['contents'] += $virtual;
		}
		$result = $integration->normalize_cart_packages( $packages, new Bundle_Test_Cart( $cart_items ) );
		bundle_assert( 10, $result[2]['contents']['parent']['line_total'], 'Remove value Woo added for shipping' );
		bundle_assert( array( 60, 2, 20, 10 ), array(
			$result[2]['contents']['packed']['line_total'], $result[2]['contents']['packed']['quantity'],
			$result[2]['contents']['packed']['product_id'], $result[2]['contents']['packed']['sst_origin_product_id'],
		), 'Packed child keeps discounted price, quantity and product ID, and uses parcel origin' );
		bundle_assert( 10, $result[2]['contents']['free']['sst_origin_product_id'], 'Zero price packed child still follows parcel' );
		bundle_assert( array( 'flat_rate' => 5 ), $result[2]['rates'], 'Woo shipping rate is preserved' );
		bundle_assert( $packages[4], $result[4], 'Separately shipped child remains in its parcel' );
		if ( $split ) {
			bundle_assert( array( 'digital' => $cart_items['digital'] ), $result[5]['contents'], 'Digital component remains in billing package' );
		} else {
			bundle_assert( $cart_items['digital'], $result[2]['contents']['digital'], 'Disabled virtual split keeps digital component' );
		}
	}

	$unassembled = array(
		'parent' => bundle_item( 10, 15, false ) + array( 'bundled_items' => array( 'own-box' ) ),
		'own-box' => $cart_items['own-box'],
	);
	$packages = array(
		0 => $shipping + array( 'contents' => array( 'own-box' => $unassembled['own-box'] ) ),
		1 => array( 'contents' => array( 'parent' => $unassembled['parent'] ), 'destination' => 'bill' ),
	);
	$result = $integration->normalize_cart_packages( $packages, new Bundle_Test_Cart( $unassembled ) );
	bundle_assert( array( 0 ), array_keys( $result ), 'Unassembled base price follows physical sale and no duplicate package remains' );
	bundle_assert( 50, $result[0]['contents']['parent']['sst_origin_product_id'], 'Unassembled base price uses parcel origin' );
}

$items = array(
	100 => bundle_item( 10, 10, ! $inactive ), 200 => bundle_item( 20, 60, true ),
	300 => bundle_item( 40, 12, false ), 400 => bundle_item( 50, 20, true ),
);
$order_items = array(
	100 => new Bundle_Test_Order_Item( array( '_bundle_cart_key' => 'parent', '_bundle_weight' => 0 ) ),
	200 => new Bundle_Test_Order_Item( array( '_bundled_by' => 'parent', '_bundled_item_id' => 1, '_bundled_item_needs_shipping' => 'no' ) ),
	300 => new Bundle_Test_Order_Item( array( '_bundled_by' => 'parent', '_bundled_item_id' => 2, '_bundled_item_needs_shipping' => 'no' ) ),
	400 => new Bundle_Test_Order_Item( array( '_bundled_by' => 'parent', '_bundled_item_id' => 3, '_bundled_item_needs_shipping' => 'yes' ) ),
);
$prepared = $integration->prepare_order_items( $items, $order_items );
bundle_assert( true, $prepared[100]['sst_needs_shipping'], 'Saved bundle parcel survives catalog product fallback' );
bundle_assert( false, $prepared[300]['sst_needs_shipping'], 'Digital component remains virtual during order recalculation' );
$result = $integration->normalize_order_packages( array(
	array( 'contents' => array_intersect_key( $prepared, array_fill_keys( array( 100, 400 ), true ) ) ),
	array( 'contents' => array( 200 => $prepared[200] ), 'shipping' => 'second rate' ),
) );
bundle_assert( 60, $result[0]['contents'][200]['line_total'], 'Rebuilt order package retains original discounted total' );
bundle_assert( 10, $result[0]['contents'][200]['sst_origin_product_id'], 'Rebuilt packed child follows the container' );
bundle_assert( array( 'contents' => array(), 'shipping' => 'second rate' ), $result[1], 'Rebuild preserves remaining shipping charge' );

echo "Product Bundles regression checks passed.\n";
