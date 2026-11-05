<?php
/** DEV-10621: exercise WooCommerce's real posts-meta writer without a database. */
define( 'ABSPATH', __DIR__ . '/' );

function add_filter() {}
function apply_filters( $hook, $value ) { return $value; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_slash( $value ) { return is_array( $value ) ? array_map( 'wp_slash', $value ) : ( is_string( $value ) ? addslashes( $value ) : $value ); }
function wp_unslash( $value ) { return is_array( $value ) ? array_map( 'wp_unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value ); }
function add_metadata( $type, $id, $key, $value, $unique ) {
	// Model WordPress's recursive unslashing and persistence boundary. The
	// WooCommerce method deciding whether to wp_slash() is the production code.
	$GLOBALS['stored_meta'][ wp_unslash( $key ) ] = serialize( wp_unslash( $value ) );
	return 1;
}
class SST_Settings {
	public static function get( $key, $default = null ) { return $default; }
}
class WC_Cache_Helper {
	public static function get_transient_version( $key ) { return 'test-version'; }
}
class WC_Order {
	public $meta = array();
	public function get_id() { return 123; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function get_meta( $key, $single = true, $context = 'view' ) { return $this->meta[ $key ] ?? ''; }
	public function save() {
		$store = new WC_Data_Store_WP();
		foreach ( $this->meta as $key => $value ) {
			$store->add_meta( $this, (object) array( 'key' => $key, 'value' => $value ) );
			// WordPress has already decoded the metadata once when WC reads it.
			$this->meta[ $key ] = unserialize( $GLOBALS['stored_meta'][ $key ] );
		}
	}
}
require dirname( __DIR__ ) . '/includes/vendor/woocommerce/woocommerce/plugins/woocommerce/includes/data-stores/class-wc-data-store-wp.php';
require dirname( __DIR__ ) . '/includes/abstracts/class-sst-abstract-cart.php';
require getenv( 'SST_ORDER_TEST_FILE' ) ?: dirname( __DIR__ ) . '/includes/class-sst-order.php';

$assertions = 0;
function expect_same( $expected, $actual, $message ) {
	++$GLOBALS['assertions'];
	if ( $expected !== $actual ) { throw new RuntimeException( $message ); }
}
$wc_order = new WC_Order();
$order = new SST_Order( $wc_order );
$hash = new ReflectionMethod( SST_Abstract_Cart::class, 'get_package_hash' );
$hash->setAccessible( true );
foreach ( array( 'v1', 'v3' ) as $version ) {
	$package = array( 'api_version' => $version, 'cart_id' => 'saved-cart', 'customer_id' => 42,
		'cart_items' => array( array( 'type' => 'line_item', 'id' => 1, 'price' => 100, 'qty' => 1 ) ),
		'origin_address' => 'Warehouse \\ North', 'destination_address' => 'Suite \\\\ South',
		'destination' => array( 'line1' => 'Suite \\ South' ) );
	$original_hash = $hash->invoke( $order, $package );
	$order->set_packages( array( $package ) );
	$wc_order->save();
	expect_same( array( $package ), $order->get_packages(), "$version: first posts-meta save must preserve nested backslashes" );
	expect_same( $original_hash, $hash->invoke( $order, $order->get_packages()[0] ), "$version: saved package hash remains stable" );
}
echo "Order metadata storage regression checks passed ($assertions assertions).\n";
