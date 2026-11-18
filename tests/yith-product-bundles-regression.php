<?php

// Standalone regression: php tests/yith-product-bundles-regression.php
define( 'ABSPATH', __DIR__ . '/' );

$hooks = array();
function add_filter( $hook, $callback ) { $GLOBALS['hooks'][ $hook ] = $callback; }

class YITH_Test_Product {
	private $type;
	private $ships;
	public function __construct( $type, $ships ) { $this->type = $type; $this->ships = $ships; }
	public function is_type( $type ) { return $this->type === $type; }
	public function needs_shipping() { return $this->ships; }
}
class YITH_Test_Cart {
	private $items;
	public function __construct( $items ) { $this->items = $items; }
	public function get_cart() { return $this->items; }
}
function yith_assert( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}
function yith_item( $id, $total, $ships, $type = 'simple' ) {
	return array( 'product_id' => $id, 'line_total' => $total, 'quantity' => 1, 'data' => new YITH_Test_Product( $type, $ships ) );
}

require dirname( __DIR__ ) . '/includes/integrations/class-sst-yith-product-bundles.php';
new SST_YITH_Product_Bundles();
$normalize = $hooks['wootax_cart_packages_before_split'];
$items = array(
	'parent' => yith_item( 10, 80, true, 'yith_bundle' ) + array( 'bundled_items' => array( 'digital', 'child', 'second' ), 'cartstamp' => array() ),
	'child' => yith_item( 20, 0, true ) + array( 'bundled_by' => 'parent' ),
	'second' => yith_item( 30, 0, true ) + array( 'bundled_by' => 'parent' ),
	'digital' => yith_item( 40, 0, false ) + array( 'bundled_by' => 'parent' ),
	'standalone' => yith_item( 20, 40, true ),
);
$shipping = array( 'contents_cost' => 40, 'rates' => array( 'flat_rate' => 5 ), 'destination' => 'shipping' );
foreach ( array( true, false ) as $split ) {
	$packages = array(
		2 => $shipping + array( 'contents' => array_intersect_key( $items, array_flip( array( 'child', 'standalone' ) ) ) ),
		4 => $shipping + array( 'contents' => array( 'second' => $items['second'] ) ),
	);
	if ( $split ) {
		$packages[5] = array( 'contents' => array( 'digital' => $items['digital'] ), 'destination' => 'billing' );
	} else {
		$packages[2]['contents']['digital'] = $items['digital'];
	}
	$result = $normalize( $packages, new YITH_Test_Cart( $items ) );
	$expected_parent = $items['parent'] + array( 'sst_origin_product_id' => 20 );
	yith_assert( $expected_parent, $result[2]['contents']['parent'] ?? null, 'Restore the original YITH parent with its charged price and parcel origin' );
	yith_assert( false, isset( $result[4]['contents']['parent'] ), 'A second component shipment does not duplicate the parent charge' );
	unset( $result[2]['contents']['parent'] );
	yith_assert( $packages, $result, 'Component lines, standalone prices, virtual destinations and shipping rates are preserved' );
}

$present = array( 2 => $shipping + array( 'contents' => $items ) );
yith_assert( $present, $normalize( $present, new YITH_Test_Cart( $items ) ), 'An existing parent is included only once' );
yith_assert( array(), $normalize( array(), new YITH_Test_Cart( $items ) ), 'An empty package list stays empty' );

$discounted = $items;
$discounted['parent']['line_total'] = 144;
$discounted['parent']['quantity'] = 2;
$discounted['child']['quantity'] = 4;
$packages = array( $shipping + array( 'contents' => array( 'child' => $discounted['child'] ) ) );
$result = $normalize( $packages, new YITH_Test_Cart( $discounted ) );
yith_assert( 144, $result[0]['contents']['parent']['line_total'], 'Multiple discounted bundles retain their actual charged total' );
yith_assert( 2, $result[0]['contents']['parent']['quantity'], 'Bundle quantity is retained for tax lookup and refunds' );

$virtual = array(
	'parent' => yith_item( 10, 80, false, 'yith_bundle' ) + array( 'bundled_items' => array( 'digital' ) ),
	'digital' => $items['digital'],
);
$packages = array( array( 'contents' => $virtual, 'destination' => 'billing' ) );
yith_assert( $packages, $normalize( $packages, new YITH_Test_Cart( $virtual ) ), 'A virtual bundle keeps its billing package' );
$packages = array( array( 'contents' => array( 'digital' => $items['digital'] ), 'destination' => 'billing' ) );
yith_assert( $packages, $normalize( $packages, new YITH_Test_Cart( $items ) ), 'A physical parent is not restored into a virtual-only package' );

$other = $items;
$other['parent']['data'] = new YITH_Test_Product( 'bundle', true );
$packages = array( $shipping + array( 'contents' => array( 'child' => $items['child'] ) ) );
yith_assert( $packages, $normalize( $packages, new YITH_Test_Cart( $other ) ), 'Other bundle extensions retain their package contents' );

echo "YITH Product Bundles regression checks passed.\n";
