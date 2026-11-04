<?php

// Standalone regression: php tests/stripe-tax-address-regression.php
// No TaxCloud request, order creation or payment is performed.
define( 'ABSPATH', __DIR__ . '/' );

$filters = array();
$settings = array( 'tc_id' => 'test', 'tc_key' => 'test' );
$mode = 'realtime';
$tax_enabled = true;
$page = 'checkout';
$product_id = 17455;
$products = array();

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
function sst_storefront_active() { return false; }
function sst_integration_mode() { global $mode; return $mode; }
function wc_tax_enabled() { global $tax_enabled; return $tax_enabled; }
function is_wc_endpoint_url( $endpoint ) { global $page; return $endpoint === $page; }
function is_account_page() { global $page; return 'account' === $page; }
function is_product() { global $page; return 'product' === $page; }
function wc_post_content_has_shortcode( $shortcode ) { global $page; return 'shortcode' === $page && 'product_page' === $shortcode; }
function get_queried_object_id() { global $product_id; return $product_id; }
function wc_get_product( $id ) { global $products; return $products[ $id ] ?? false; }
function WC() { global $woocommerce; return $woocommerce; }

class SST_Settings {
	public static function get( $key, $default = null ) { global $settings; return $settings[ $key ] ?? $default; }
}

class Stripe_Tax_Test_Product {
	public $virtual;
	private $taxable;
	private $shipping;
	public function __construct( $taxable, $shipping, $virtual = false ) {
		$this->taxable = $taxable;
		$this->shipping = $shipping;
		$this->virtual = $virtual;
	}
	public function is_taxable() { return $this->taxable; }
	public function needs_shipping() { return $this->shipping; }
}

$session = new class {
	public $exempt = false;
	public function get( $key, $default = null ) { return 'is_tax_exempt' === $key ? $this->exempt : $default; }
};
$cart = new class {
	public $items = array();
	public $shipping = false;
	public function get_cart() { return $this->items; }
	public function needs_shipping() { return $this->shipping; }
};
$woocommerce = (object) array( 'cart' => $cart, 'session' => $session );

require dirname( __DIR__ ) . '/includes/abstracts/class-sst-abstract-cart.php';
require getenv( 'SST_CHECKOUT_TEST_FILE' ) ?: dirname( __DIR__ ) . '/includes/frontend/class-sst-checkout.php';

$hook = 'wc_stripe_should_hide_express_checkout_button_based_on_tax_setup';
$checks = 0;
function expect_same( $expected, $actual, $message ) {
	global $checks;
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
	++$checks;
}
function set_cart( $products, $shipping = false ) {
	global $cart;
	$cart->shipping = $shipping;
	$cart->items = array_map( function ( $product ) { return array( 'data' => $product ); }, $products );
}

// OASIS's runtime shipping decision differs from the stored Virtual checkbox.
// Stripe passes false here because its store-level tax address is shipping.
$sticker = new Stripe_Tax_Test_Product( true, false, false );
$book = new Stripe_Tax_Test_Product( true, true );
$virtual = new Stripe_Tax_Test_Product( true, false, true );
$tax_free = new Stripe_Tax_Test_Product( false, false, true );
set_cart( array( $sticker ) );
expect_same( false, $sticker->virtual, 'Reproduction retains physical product metadata' );
expect_same( true, apply_filters( $hook, false ), 'Runtime nonshippable taxable sticker requires standard checkout' );
expect_same( true, apply_filters( $hook, true ), 'Stripe existing restriction is preserved' );
set_cart( array( $book ), true );
expect_same( false, apply_filters( $hook, false ), 'Shippable taxable book retains express checkout' );
set_cart( array( $virtual ) );
expect_same( true, apply_filters( $hook, false ), 'Taxable virtual-only cart requires billing tax address' );
set_cart( array( $tax_free ) );
expect_same( false, apply_filters( $hook, false ), 'Tax-free virtual-only cart retains express checkout' );
set_cart( array( $book, $virtual ), true );
expect_same( true, apply_filters( $hook, false ), 'Mixed cart with separate billing-tax package is protected' );
$settings['disable_virtual_split'] = 'yes';
expect_same( false, apply_filters( $hook, false ), 'Mixed cart taxed entirely at shipping destination retains express checkout' );
set_cart( array( $virtual ) );
expect_same( true, apply_filters( $hook, false ), 'Disabling split without shipping still requires billing address' );
unset( $settings['disable_virtual_split'] );
set_cart( array( $book, $tax_free ), true );
expect_same( false, apply_filters( $hook, false ), 'Tax-free virtual item does not block a shippable cart' );
set_cart( array() );
expect_same( false, apply_filters( $hook, false ), 'Empty cart retains gateway decision' );
set_cart( array( null ) );
expect_same( false, apply_filters( $hook, false ), 'Missing cart product is ignored' );

set_cart( array( $sticker ) );
foreach ( array( 'disable_integration' => 'yes', 'tc_id' => '', 'tc_key' => '' ) as $key => $value ) {
	$before = $settings;
	$settings[ $key ] = $value;
	expect_same( false, apply_filters( $hook, false ), "$key leaves an inactive TaxCloud integration out of eligibility" );
	expect_same( true, apply_filters( $hook, true ), "$key preserves Stripe restriction" );
	$settings = $before;
}
$mode = 'data_mover';
expect_same( false, apply_filters( $hook, false ), 'Data Import retains gateway eligibility' );
$mode = 'realtime';
$tax_enabled = false;
expect_same( false, apply_filters( $hook, false ), 'Disabled WooCommerce taxes retain gateway eligibility' );
$tax_enabled = true;
$session->exempt = true;
expect_same( false, apply_filters( $hook, false ), 'Explicit checkout tax exemption retains gateway eligibility' );
$session->exempt = false;

foreach ( array( 'order-pay', 'account' ) as $page ) {
	expect_same( false, apply_filters( $hook, false ), "$page does not recalculate an existing order or setup payment" );
}
$page = 'product';
$products[17455] = $sticker;
expect_same( true, apply_filters( $hook, false ), 'Product-page sticker is protected before add to cart' );
$products[17455] = $book;
expect_same( false, apply_filters( $hook, false ), 'Shippable product retains express checkout despite unrelated cart' );
unset( $products[17455] );
expect_same( false, apply_filters( $hook, false ), 'Unavailable product retains gateway decision' );
$page = 'shortcode';
$product_id = 6217;
$post = (object) array( 'post_content' => '[product_page id="17455"]' );
$products[17455] = $sticker;
expect_same( true, apply_filters( $hook, false ), 'Product shortcode checks the embedded product rather than the hosting page' );
$products[17455] = $book;
expect_same( false, apply_filters( $hook, false ), 'Shippable product shortcode ignores an unrelated nonshippable cart' );
$page = 'checkout';
$woocommerce->cart = null;
expect_same( false, apply_filters( $hook, false ), 'No cart retains gateway decision' );
$woocommerce->session = null;
expect_same( false, apply_filters( $hook, false ), 'Uninitialized session and cart are safe' );

echo "Stripe tax-address regression checks passed ($checks checks).\n";
