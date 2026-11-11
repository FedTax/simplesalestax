<?php
/** DEV-10624: php tests/marketplace-cart-regression.php. No external requests. */

define( 'ABSPATH', __DIR__ . '/' );
define( 'SST_FILE', dirname( __DIR__ ) . '/simple-sales-tax.php' );
define( 'DOKAN_PLUGIN_VERSION', '3.0.0' );
define( 'WCFM_VERSION', '6.5.0' );
define( 'WCMp_PLUGIN_VERSION', '3.4.0' );
define( 'WCV_PRO_VERSION', '1.5.8' );

$WCFMmp = new stdClass();
$filters = array();
$settings = array( 'tc_id' => 'test-id', 'tc_key' => 'test-key' );

function add_action() {}
function remove_action() {}
function add_filter( $hook, $callback ) { $GLOBALS['filters'][ $hook ][] = $callback; }
function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['filters'][ $hook ] ?? array() as $callback ) {
		$value = $callback( $value, ...$args );
	}
	return $value;
}
function __( $message ) { return $message; }
function WC() { return $GLOBALS['woocommerce']; }
function sst_storefront_active() { return false; }
function is_wp_error( $response ) { return false; }
function get_current_user_id() { return 42; }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, $args ); }
function wp_list_pluck( $items, $key ) { return array_column( $items, $key ); }
function get_post_field( $field, $product_id ) { return $GLOBALS['product_authors'][ $product_id ]; }
function wc_get_product( $product_id ) { return (object) array( 'id' => $product_id ); }

function dokan_is_user_seller( $id ) { return in_array( (int) $id, array( 17, 23 ), true ); }
function wcfm_is_vendor( $id ) { return dokan_is_user_seller( $id ); }
function is_user_wcmp_vendor( $id ) { return dokan_is_user_seller( $id ); }
function wcfm_get_vendor_id_by_post( $product_id ) { return $GLOBALS['product_vendors'][ $product_id ]; }
function get_wcmp_product_vendors( $product_id ) { return (object) array( 'id' => $GLOBALS['product_vendors'][ $product_id ] ); }

class WCV_Vendors {
	public static function is_vendor( $id ) { return dokan_is_user_seller( $id ); }
	public static function get_vendor_from_product( $product_id ) { return $GLOBALS['product_vendors'][ $product_id ]; }
}

function dokan_get_store_info( $id ) {
	return array( 'address' => array( 'street_1' => "$id Seller St", 'city' => 'Denver', 'state' => 'CO', 'zip' => '80202' ) );
}

function get_user_meta( $id, $key ) {
	$address = array( 'address1' => "$id Seller St", 'city' => 'Denver', 'state' => 'CO', 'zip' => '80202' );
	if ( 'wcfmmp_profile_settings' === $key ) {
		return array( 'address' => $address + array( 'street_1' => $address['address1'] ) );
	}

	$fields = array(
		'_vendor_address_1'   => $address['address1'],
		'_wcv_store_address1' => $address['address1'],
		'_vendor_city'        => $address['city'],
		'_wcv_store_city'     => $address['city'],
		'_vendor_state'       => $address['state'],
		'_wcv_store_state'    => $address['state'],
		'_vendor_postcode'    => $address['zip'],
		'_wcv_store_postcode' => $address['zip'],
	);

	return $fields[ $key ] ?? '';
}

class SST_Settings {
	public static function get( $key, $default = null ) { return $GLOBALS['settings'][ $key ] ?? $default; }
}

class SST_Logger {
	public static function add() {}
}

class SST_Addresses {
	public static function verify_address( $address, &$outcome ) {
		$outcome = array( 'input_hash' => $address->getAddress1(), 'status' => 'verified' );
		return $address;
	}
	public static function to_address( $address ) { return $address; }
	public static function get_default_address() {
		return new SST_Origin_Address( 'default', true, '1 Store St', '', 'Denver', 'CO', '80202' );
	}
}

class WC_Shipping_Rate {
	public $id;
	public $cost;
	public $method_id;

	public function __construct( $id, $label, $cost, $taxes, $method_id ) {
		$this->id        = $id;
		$this->cost      = $cost;
		$this->method_id = $method_id;
	}
}

class Marketplace_Test_Session {
	private $values = array();
	public function get( $key, $default = null ) { return $this->values[ $key ] ?? $default; }
	public function set( $key, $value ) { $this->values[ $key ] = $value; }
}

class Marketplace_Test_Cart {
	public $items = array();
	public function get_cart() { return $this->items; }
	public function get_fees() { return array(); }
}

class Marketplace_Test_Shipping {
	public $packages = array();
	public function get_packages() { return $this->packages; }
}

class Marketplace_Test_Customer {
	public $shipping_state = 'NY';
	public function get_billing_country() { return 'US'; }
	public function get_billing_address() { return '1 Billing St'; }
	public function get_billing_address_2() { return 'Apt 2'; }
	public function get_billing_city() { return 'Denver'; }
	public function get_billing_state() { return 'CO'; }
	public function get_billing_postcode() { return '80202'; }
	public function get_shipping_country() { return 'US'; }
	public function get_shipping_address() { return '2 Shipping St'; }
	public function get_shipping_address_2() { return ''; }
	public function get_shipping_city() { return 'New York'; }
	public function get_shipping_state() { return $this->shipping_state; }
	public function get_shipping_postcode() { return '10001'; }
}

require dirname( __DIR__ ) . '/includes/vendor/autoload.php';
require dirname( __DIR__ ) . '/includes/sst-functions.php';
require dirname( __DIR__ ) . '/includes/class-sst-origin-address.php';
require dirname( __DIR__ ) . '/includes/class-sst-shipping.php';
require dirname( __DIR__ ) . '/includes/class-sst-marketplaces.php';
require dirname( __DIR__ ) . '/includes/abstracts/class-sst-abstract-cart.php';
require dirname( __DIR__ ) . '/includes/frontend/class-sst-checkout.php';

class Marketplace_Test_Checkout extends SST_Checkout {
	public $taxes;
	public $lookups;
	public $shipping_taxes;
	public function __construct( $cart ) {
		$property = new ReflectionProperty( SST_Checkout::class, 'cart' );
		$property->setAccessible( true );
		$property->setValue( $this, $cart );
	}

	public function get_certificate_id() { return ''; }
	protected function reset_taxes() { $this->taxes = $this->lookups = $this->shipping_taxes = array(); }
	protected function update_taxes() {}
	protected function set_product_tax( $id, $tax ) { $this->taxes[ $id ] = $tax; }
	protected function set_shipping_tax( $id, $tax ) { $this->shipping_taxes[ $id ] = $tax; }

	// Record what checkout sends for lookup and return a fixed tax per line.
	protected function do_lookup() {
		$responses = array();
		foreach ( $this->create_packages() as $package ) {
			$this->lookups[] = $package;
			$items = array();
			foreach ( $package['contents'] as $item ) {
				$items[] = array( 'type' => 'line_item', 'cart_id' => $item['key'] );
			}
			$taxes = array_fill( 0, count( $items ), 5.0 );
			if ( $package['shipping'] ) {
				$items[] = array( 'type' => 'shipping', 'cart_id' => $package['shipping']->id );
				$taxes[] = $package['shipping']->cost * 0.1;
			}
			$responses[] = array(
				'cart_items' => $items,
				'response'   => array( $taxes ),
			);
		}
		return $responses;
	}
}

function expect_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}

function cart_item( $key, $product_id, $needs_shipping, $variation_id = 0 ) {
	return array(
		'key'          => $key,
		'product_id'   => $product_id,
		'variation_id' => $variation_id,
		'line_total'   => 50,
		'data'         => new class( $needs_shipping ) {
			private $needs_shipping;
			public function __construct( $needs_shipping ) { $this->needs_shipping = $needs_shipping; }
			public function needs_shipping() { return $this->needs_shipping; }
		},
	);
}

function shipping_package( $items, $seller_key, $seller_id ) {
	return array(
		$seller_key     => $seller_id,
		'contents'      => $items,
		'contents_cost' => 50 * count( $items ),
		'destination'   => array(
			'country'  => 'US',
			'address'  => '2 Shipping St',
			'city'     => 'New York',
			'state'    => 'NY',
			'postcode' => '10001',
		),
	);
}

$cart = new Marketplace_Test_Cart();
$woocommerce = (object) array(
	'shipping' => new Marketplace_Test_Shipping(),
	'customer' => new Marketplace_Test_Customer(),
	'session'  => new Marketplace_Test_Session(),
);
$checkout = new Marketplace_Test_Checkout( $cart );
$physical_a = cart_item( 'physical-a', 101, true );
$virtual_a  = cart_item( 'virtual-a', 102, false, 105 );
$physical_b = cart_item( 'physical-b', 103, true );
$virtual_b  = cart_item( 'virtual-b', 104, false );
$all_items  = array_column( array( $physical_a, $virtual_a, $physical_b, $virtual_b ), null, 'key' );
$callbacks = $filters['wootax_cart_packages_before_split'];

expect_same( 4, count( $callbacks ), 'Marketplace integrations registered' );
foreach ( $callbacks as $callback ) {
	$integration = get_class( $callback[0] );
	$filters['wootax_cart_packages_before_split'] = array( $callback );
	$seller_key = 'SST_Dokan' === $integration ? 'seller_id' : 'vendor_id';
	$product_vendors = array( 101 => 17, 102 => 17, 103 => 23, 104 => 23 );
	// Exercise marketplace-specific ownership when the product author differs.
	$product_authors = 'SST_Dokan' === $integration ? $product_vendors : array_fill_keys( array_keys( $product_vendors ), 1 );
	$product_authors[105] = 1;

	$cart->items = array( 'virtual-a' => $virtual_a );
	$woocommerce->shipping->packages = array();
	$checkout->calculate_taxes();
	expect_same( array( 'virtual-a' => 5.0 ), $checkout->taxes, "$integration virtual-only tax" );
	expect_same( 1, count( $checkout->lookups ), "$integration virtual-only lookup count" );
	expect_same( 'S-17', $checkout->lookups[0]['origin']->getID(), "$integration virtual-only origin" );
	expect_same( '1 Billing St', $checkout->lookups[0]['destination']->getAddress1(), "$integration virtual-only destination" );
	expect_same( null, $checkout->lookups[0]['shipping'], "$integration virtual-only shipping charge" );
	expect_same( array( 'ID' => 42 ), $checkout->lookups[0]['user'], "$integration virtual-only customer" );

	$settings['disable_virtual_split'] = 'yes';
	$checkout->calculate_taxes();
	expect_same( '2 Shipping St', $checkout->lookups[0]['destination']->getAddress1(), "$integration shipping destination when virtual split is disabled" );
	$woocommerce->customer->shipping_state = '';
	$checkout->calculate_taxes();
	expect_same( '1 Billing St', $checkout->lookups[0]['destination']->getAddress1(), "$integration billing fallback" );
	$woocommerce->customer->shipping_state = 'NY';
	unset( $settings['disable_virtual_split'] );

	$cart->items = array_intersect_key( $all_items, array( 'physical-a' => true, 'virtual-a' => true ) );
	$woocommerce->shipping->packages = array( 4 => shipping_package( array( $physical_a ), $seller_key, '17' ) );
	$rate = new WC_Shipping_Rate( 'flat_rate:1', '', 10, array(), 'flat_rate' );
	$woocommerce->shipping->packages[4]['rates'][ $rate->id ] = $rate;
	$woocommerce->session->set( 'chosen_shipping_methods', array( 4 => $rate->id ) );
	$checkout->calculate_taxes();
	expect_same( 1, count( $checkout->lookups ), "$integration mixed single-seller lookup count" );
	expect_same( array( 'physical-a', 'virtual-a' ), array_column( $checkout->lookups[0]['contents'], 'key' ), "$integration mixed single-seller lines" );
	expect_same( 100, $checkout->lookups[0]['contents_cost'], "$integration mixed single-seller cost" );
	expect_same( 4, $checkout->lookups[0]['shipping']->id, "$integration mixed single-seller shipping package key" );

	// Some marketplace shipping packages carry a seller name but omit the
	// seller ID. Infer ownership from an unambiguous single-seller package.
	$package_without_seller_id = shipping_package( array( $physical_a ), $seller_key, '17' );
	unset( $package_without_seller_id[ $seller_key ] );
	$package_without_seller_id['package_name'] = '17 Seller Shipping';
	$woocommerce->shipping->packages = array( 4 => $package_without_seller_id );
	$checkout->calculate_taxes();
	expect_same( 1, count( $checkout->lookups ), "$integration package without seller ID lookup count" );
	expect_same( array( 'physical-a', 'virtual-a' ), array_column( $checkout->lookups[0]['contents'], 'key' ), "$integration package without seller ID lines" );
	expect_same( 'S-17', $checkout->lookups[0]['origin']->getID(), "$integration package without seller ID origin" );

	$cart->items = $all_items;
	$woocommerce->shipping->packages[8] = shipping_package( array( $physical_b ), $seller_key, '23' );
	$checkout->calculate_taxes();
	expect_same( 2, count( $checkout->lookups ), "$integration mixed two-seller lookup count" );
	expect_same( array( 'physical-a', 'virtual-a' ), array_column( $checkout->lookups[0]['contents'], 'key' ), "$integration seller A lines" );
	expect_same( 'S-17', $checkout->lookups[0]['origin']->getID(), "$integration seller A origin" );
	expect_same( array( 'physical-b', 'virtual-b' ), array_column( $checkout->lookups[1]['contents'], 'key' ), "$integration seller B lines" );
	expect_same( 'S-23', $checkout->lookups[1]['origin']->getID(), "$integration seller B origin" );

	// Seller B has only virtual products. An upstream package included its line under seller A.
	unset( $cart->items['physical-b'], $woocommerce->shipping->packages[8] );
	$woocommerce->shipping->packages[4] = shipping_package( array( $physical_a, $virtual_b ), $seller_key, '17' );
	$checkout->calculate_taxes();
	expect_same( 2, count( $checkout->lookups ), "$integration mixed with virtual-only seller lookup count" );
	expect_same( array( 'physical-a', 'virtual-a' ), array_column( $checkout->lookups[0]['contents'], 'key' ), "$integration seller A lines after reassignment" );
	expect_same( 100, $checkout->lookups[0]['contents_cost'], "$integration seller A cost after reassignment" );
	expect_same( array( 'virtual-b' ), array_keys( $checkout->lookups[1]['contents'] ), "$integration virtual-only seller B lines" );
	expect_same( 'S-23', $checkout->lookups[1]['origin']->getID(), "$integration virtual-only seller B origin" );
	expect_same( '1 Billing St', $checkout->lookups[1]['destination']->getAddress1(), "$integration virtual-only seller B destination" );

	// An upstream package with only virtual lines may still have a selected charge.
	$cart->items = array( 'virtual-a' => $virtual_a );
	$woocommerce->shipping->packages = array( 4 => shipping_package( array( $virtual_a ), $seller_key, '17' ) );
	$woocommerce->shipping->packages[4]['rates'][ $rate->id ] = $rate;
	$checkout->calculate_taxes();
	expect_same( array( 4 => 1.0 ), $checkout->shipping_taxes, "$integration preserves the selected shipping charge" );
	expect_same( 2, count( $checkout->lookups ), "$integration shipping-only and virtual-only lookups" );
	expect_same( array(), $checkout->lookups[0]['contents'], "$integration shipping-only package has no product lines" );
	expect_same( 0, $checkout->lookups[0]['contents_cost'], "$integration shipping-only product subtotal" );
	expect_same( 'S-17', $checkout->lookups[0]['origin']->getID(), "$integration shipping-only seller origin" );
	expect_same( '2 Shipping St', $checkout->lookups[0]['destination']->getAddress1(), "$integration shipping-only destination" );
	expect_same( array( 'virtual-a' => 5.0 ), $checkout->taxes, "$integration virtual product tax alongside selected shipping" );
	expect_same( '1 Billing St', $checkout->lookups[1]['destination']->getAddress1(), "$integration virtual destination alongside selected shipping" );
	expect_same( null, $checkout->lookups[1]['shipping'], "$integration no duplicate shipping on the virtual package" );

	unset( $woocommerce->shipping->packages[4][ $seller_key ] );
	$checkout->calculate_taxes();
	expect_same( array( 4 => 1.0 ), $checkout->shipping_taxes, "$integration shipping charge without a seller ID" );
	expect_same( 'default', $checkout->lookups[0]['origin']->getID(), "$integration default origin for unattributed shipping" );

	$woocommerce->session->set( 'chosen_shipping_methods', array() );
	$checkout->calculate_taxes();
	expect_same( 1, count( $checkout->lookups ), "$integration empty shipping package without a selected rate is dropped" );
	expect_same( array(), $checkout->shipping_taxes, "$integration no unselected shipping charge" );

	// Separate shipping packages can share an origin without sharing their lines.
	$second_physical = cart_item( 'physical-a-2', 101, true );
	$cart->items = array_column( array( $physical_a, $virtual_a, $second_physical ), null, 'key' );
	$woocommerce->shipping->packages = array(
		4 => shipping_package( array( $physical_a ), $seller_key, '17' ),
		8 => shipping_package( array( $second_physical ), $seller_key, '17' ),
	);
	foreach ( array( 4, 8 ) as $key ) {
		$woocommerce->shipping->packages[ $key ]['rates'][ $rate->id ] = $rate;
	}
	$woocommerce->session->set( 'chosen_shipping_methods', array( 4 => $rate->id, 8 => $rate->id ) );
	$checkout->calculate_taxes();
	expect_same( 2, count( $checkout->lookups ), "$integration separate packages with the same seller origin" );
	expect_same( array( 'physical-a', 'virtual-a' ), array_column( $checkout->lookups[0]['contents'], 'key' ), "$integration first same-seller package lines" );
	expect_same( array( 'physical-a-2' ), array_column( $checkout->lookups[1]['contents'], 'key' ), "$integration second same-seller package lines" );
	expect_same( array( 4 => 1.0, 8 => 1.0 ), $checkout->shipping_taxes, "$integration separate shipping taxes for the same seller" );

	// Recalculation should group products with the matching vendor shipping line.
	$order = new class {
		public function get_items() {
			return array(
				11 => array( 'product_id' => 102, 'variation_id' => 105, 'qty' => 1, 'line_total' => 50, 'line_subtotal' => 50 ),
				12 => array( 'product_id' => 103, 'variation_id' => 0, 'qty' => 1, 'line_total' => 50, 'line_subtotal' => 50 ),
			);
		}
		public function get_shipping_methods() {
			return array( 31 => new class {
				public function get_meta( $key ) { return 'vendor_id' === $key ? 17 : ''; }
				public function get_id() { return 31; }
				public function get_total() { return 10; }
				public function get_method_id() { return 'flat_rate'; }
			} );
		}
		public function get_user_id() { return 42; }
	};
	$order_packages = $callback[0]->filter_wootax_order_packages( array(), $order );
	expect_same( 2, count( $order_packages ), "$integration order recalculation seller packages" );
	expect_same( array( 11 ), array_keys( $order_packages[0]['contents'] ), "$integration order variation assigned by parent product ownership" );
	expect_same( 31, $order_packages[0]['shipping']->id, "$integration order shipping assigned to seller A" );
	expect_same( 'S-17', $order_packages[0]['origin']->getID(), "$integration order seller A origin" );
	expect_same( array( 12 ), array_keys( $order_packages[1]['contents'] ), "$integration order seller B items" );
	expect_same( 'S-23', $order_packages[1]['origin']->getID(), "$integration order seller B origin" );
}

echo "Marketplace cart regression checks passed for Dokan, WCFM, WCMp, and WC Vendors Pro.\n";
