<?php

// DEV-10692. Run with php tests/subscription-renewal-tax-regression.php.
// Exercise the registered renewal filter with modeled WooCommerce orders and fixture quotes.
define( 'ABSPATH', __DIR__ . '/' );
define( 'SST_RATE_ID', 77 );
define( 'SST_DEFAULT_FEE_TIC', 10010 );
define( 'SST_SHIPPING_ITEM', 'SHIPPING' );

$filters = array();
$assertions = 0;

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	global $filters;
	$filters[ $hook ][ $priority ][] = array( $callback, $accepted_args );
}

function apply_filters( $hook, $value, ...$args ) {
	global $filters;
	$callbacks = $filters[ $hook ] ?? array();
	ksort( $callbacks );
	foreach ( $callbacks as $group ) {
		foreach ( $group as list( $callback, $accepted_args ) ) {
			$value = $callback( ...array_slice( array_merge( array( $value ), $args ), 0, $accepted_args ) );
		}
	}
	return $value;
}

function __( $message ) { return $message; }
function add_action() {}
function did_action() { return 0; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function sst_get_api_version() { return 'v1'; }
function sst_integration_mode() { return SST_Settings::get( 'data_mover' ) ? 'data_mover' : 'realtime'; }
function wc_get_price_decimals() { return 2; }
function sst_get_shipping_tic() { return 11010; }
function sst_format_order_items() { return array(); }
function wcs_order_contains_renewal() { return true; }
function TaxCloud() { return new Renewal_Test_Lookup(); }

class WP_Error {
	private $message;
	public function __construct( $code, $message ) { $this->message = $message; }
	public function get_error_message() { return $this->message; }
}

class SST_Settings {
	public static $settings = array();
	public static function get( $key, $default = null ) { return self::$settings[ $key ] ?? $default; }
}

class SST_Logger {
	public static function add() {}
	public static function debug() {}
	public static function order_log() {}
}

class SST_Rate_Limit {
	public function limit_reached() { return SST_Settings::get( 'fixture_rate_limited', false ); }
	public function increment_count() {}
	public function log_limit_reached() {}
}

class SST_Product { public static function get_tic() { return 0; } }
class SST_Shipping { public static function is_local_delivery() { return false; } }
class WC_Cache_Helper { public static function get_transient_version() { return 'fixture'; } }
class WC_Subscriptions_Cart { public static function get_calculation_type() { return 'none'; } }
class WC_Subscriptions_Product { public static function needs_one_time_shipping() { return false; } }

class Renewal_Test_Lookup {
	public static $responses = array();
	public static $calls = 0;

	public function Lookup( $request ) {
		++self::$calls;
		$response = array_shift( self::$responses );
		if ( $response instanceof Throwable ) {
			throw $response;
		}
		return array( 'fixture-cart' => $response );
	}
}

class Renewal_Test_Item {
	public $id;
	public $type;
	public $total;
	private $taxes;

	public function __construct( $id, $type, $total, $tax ) {
		$this->id = $id;
		$this->type = $type;
		$this->total = $total;
		$this->set_taxes( array( 'total' => array( 42 => $tax ), 'subtotal' => array( 42 => $tax ) ) );
	}

	public function get_id() { return $this->id; }
	public function get_product() { return $this; }
	public function get_taxes( $context = 'view' ) { return $this->taxes; }
	public function save() {}

	public function set_taxes( $taxes ) {
		$this->taxes = array( 'total' => array_map( 'floatval', $taxes['total'] ) );
		if ( 'line_item' === $this->type ) {
			$this->taxes['subtotal'] = array_map( 'floatval', $taxes['subtotal'] );
		}
	}
}

class WC_Order {
	public $meta = array( '_wootax_status' => 'captured' );
	public $notes = array();
	public $items;
	public $packages = array();
	public $cart_taxes = array();
	public $shipping_taxes = array();
	public $total;

	public function __construct() {
		$this->items = array(
			101 => new Renewal_Test_Item( 101, 'line_item', 100, 9.0 ),
			102 => new Renewal_Test_Item( 102, 'fee', 25, 2.0 ),
			103 => new Renewal_Test_Item( 103, 'shipping', 10, 1.0 ),
		);
		$this->update_taxes();
		$this->calculate_totals( false );
	}

	public function __clone() {
		foreach ( $this->items as $id => $item ) {
			$this->items[ $id ] = clone $item;
		}
	}

	public function get_id() { return 435569; }
	public function get_total_refunded() { return 0; }
	public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function add_order_note( $message ) { $this->notes[] = $message; }
	public function get_item( $id, $load = true ) { return $this->items[ $id ]; }
	public function add_item( $item ) { $this->items[ $item->get_id() ] = $item; }
	public function get_shipping_methods() { return $this->get_items( 'shipping' ); }

	public function get_items( $types = 'line_item' ) {
		return array_filter( $this->items, static function ( $item ) use ( $types ) {
			return in_array( $item->type, (array) $types, true );
		} );
	}

	// Model WooCommerce's rate rows separately from the taxes stored on each item.
	public function update_taxes() {
		$this->cart_taxes = $this->shipping_taxes = array();
		foreach ( $this->items as $item ) {
			$totals = 'shipping' === $item->type ? 'shipping_taxes' : 'cart_taxes';
			foreach ( $item->get_taxes()['total'] as $rate => $tax ) {
				$this->{$totals}[ $rate ] = ( $this->{$totals}[ $rate ] ?? 0.0 ) + $tax;
			}
		}
	}

	public function calculate_totals( $and_taxes ) {
		if ( $and_taxes ) {
			throw new RuntimeException( 'The renewal must use the taxes already on its items.' );
		}
		$this->total = array_sum( $this->cart_taxes ) + array_sum( $this->shipping_taxes );
		foreach ( $this->items as $item ) {
			$this->total += $item->total;
		}
	}
}

require dirname( __DIR__ ) . '/includes/vendor/autoload.php';
require dirname( __DIR__ ) . '/includes/class-sst-addresses.php';
require getenv( 'SST_ABSTRACT_CART_TEST_FILE' ) ?: dirname( __DIR__ ) . '/includes/abstracts/class-sst-abstract-cart.php';
require getenv( 'SST_ORDER_TEST_FILE' ) ?: dirname( __DIR__ ) . '/includes/class-sst-order.php';
require getenv( 'SST_SUBSCRIPTIONS_TEST_FILE' ) ?: dirname( __DIR__ ) . '/includes/integrations/class-sst-subscriptions.php';

// Supply normalized packages so the real lookup, response handling, and tax writes run.
add_filter( 'wootax_order_packages', static function ( $packages, $order ) {
	return $order->packages;
}, 10, 2 );

function renewal_test_package( $product, $fee = null, $shipping = null ) {
	$address = new TaxCloud\Address( '100 Fixture Way', '', 'Austin', 'TX', '78701' );
	return array(
		'origin'      => $address,
		'destination' => $address,
		'contents'    => array( array(
			'key' => $product->id, 'data' => $product, 'product_id' => $product->id,
			'variation_id' => 0, 'quantity' => 1, 'line_total' => $product->total,
			'line_tax' => array_sum( $product->get_taxes()['total'] ),
		) ),
		'fees'        => $fee ? array( $fee->id => (object) array( 'id' => 'fixture-fee', 'amount' => $fee->total, 'tax' => array_sum( $fee->get_taxes()['total'] ) ) ) : array(),
		'shipping'    => $shipping ? (object) array( 'id' => $shipping->id, 'method_id' => 'flat_rate', 'cost' => $shipping->total ) : null,
		'certificate' => null,
		'user'        => array( 'ID' => 51 ),
	);
}

function renewal_expect( $expected, $actual, $label ) {
	global $assertions;
	++$assertions;
	if ( $expected !== $actual ) {
		throw new RuntimeException( $label . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}

function renewal_fixture( $subscription, $responses, $options = array(), $split = false ) {
	SST_Settings::$settings = array_merge( array( 'tc_id' => 'fixture', 'tc_key' => 'fixture' ), $options );
	Renewal_Test_Lookup::$responses = $responses;
	Renewal_Test_Lookup::$calls = 0;
	$order = clone $subscription;
	$order->packages[] = renewal_test_package( $order->items[101], $order->items[102], $order->items[103] );
	if ( $split ) {
		$order->items[104] = new Renewal_Test_Item( 104, 'line_item', 50, 5.0 );
		$order->packages[] = renewal_test_package( $order->items[104] );
	}
	return $order;
}

function run_renewal( $order, $subscription, $calls, $label ) {
	$renewal = apply_filters( 'wcs_renewal_order_created', $order, $subscription );
	renewal_expect( $order, $renewal, "$label returns the same order" );
	renewal_expect( array(), $renewal->notes, "$label completes without a calculation exception" );
	renewal_expect( $calls, Renewal_Test_Lookup::$calls, "$label quote count" );
}

$subscription = new WC_Order();
$quote = array( 10.0, 2.5, 1.5 );
$renewal = renewal_fixture( $subscription, array( $quote, $quote ) );
run_renewal( $renewal, $subscription, 1, 'Quoted renewal' );
renewal_expect( array( 'total' => array( SST_RATE_ID => 10.0 ), 'subtotal' => array( SST_RATE_ID => 10.0 ) ), $renewal->items[101]->get_taxes(), 'Quoted product replaces both copied tax arrays' );
renewal_expect( array( SST_RATE_ID => 2.5 ), $renewal->items[102]->get_taxes()['total'], 'Quoted fee replaces the copied rate' );
renewal_expect( array( SST_RATE_ID => 1.5 ), $renewal->items[103]->get_taxes()['total'], 'Quoted shipping replaces the copied rate' );
renewal_expect( array( SST_RATE_ID => 12.5 ), $renewal->cart_taxes, 'Only TaxCloud in order cart tax rows' );
renewal_expect( array( SST_RATE_ID => 1.5 ), $renewal->shipping_taxes, 'Only TaxCloud in order shipping tax rows' );
renewal_expect( 149.0, $renewal->total, 'Gross renewal total includes tax once' );
renewal_expect( 'fixture-cart', ( new SST_Order( $renewal ) )->get_packages()[0]['cart_id'], 'New quote remains available for capture' );
run_renewal( $renewal, $subscription, 2, 'Repeated renewal calculation' );
renewal_expect( 149.0, $renewal->total, 'Repeating calculation does not add tax again' );
renewal_expect( array( 'total' => array( 42 => 9.0 ), 'subtotal' => array( 42 => 9.0 ) ), $subscription->items[101]->get_taxes(), 'Original subscription keeps its item tax arrays' );
renewal_expect( array( 42 => 11.0 ), $subscription->cart_taxes, 'Original subscription keeps its cart tax' );
renewal_expect( array( 42 => 1.0 ), $subscription->shipping_taxes, 'Original subscription keeps its shipping tax' );
renewal_expect( 147.0, $subscription->total, 'Original subscription keeps its gross total' );

$renewal = renewal_fixture( $subscription, array( array( 0, '0.00', 0.0 ) ) );
run_renewal( $renewal, $subscription, 1, 'Zero quote' );
foreach ( $renewal->items as $item ) {
	renewal_expect( array( SST_RATE_ID => 0.0 ), $item->get_taxes()['total'], 'Explicit zero replaces the copied ' . $item->type . ' rate' );
}
renewal_expect( 135.0, $renewal->total, 'Valid zero quote yields the pretax total' );

$error = new RuntimeException( 'TaxCloud fixture unavailable' );
$renewal = renewal_fixture( $subscription, array( $quote, $error ), array(), true );
run_renewal( $renewal, $subscription, 2, 'One successful and one failed package' );
renewal_expect( array( SST_RATE_ID => 12.5, 42 => 5.0 ), $renewal->cart_taxes, 'Mixed outcomes rebuild cart tax rows' );
renewal_expect( array( SST_RATE_ID => 1.5 ), $renewal->shipping_taxes, 'Mixed outcomes rebuild shipping tax rows' );
renewal_expect( 204.0, $renewal->total, 'Mixed outcomes charge each package once' );

$renewal = renewal_fixture( $subscription, array( array( 0 => 10.0, 1 => null ) ) );
run_renewal( $renewal, $subscription, 1, 'Incomplete V1 quote' );
renewal_expect( array( SST_RATE_ID => 10.0 ), $renewal->items[101]->get_taxes()['total'], 'A quoted item is still recalculated' );
renewal_expect( 2.0, $renewal->items[102]->get_taxes()['total'][42] ?? null, 'Null amount preserves the copied fee tax' );
renewal_expect( 1.0, $renewal->items[103]->get_taxes()['total'][42] ?? null, 'Missing amount preserves the copied shipping tax' );
renewal_expect( 148.0, $renewal->total, 'Incomplete quote preserves the tax on unquoted items' );

$renewal = renewal_fixture( $subscription, array( array( 'unavailable', INF, 'invalid' ) ) );
run_renewal( $renewal, $subscription, 1, 'Invalid amounts in V1 quote' );
renewal_expect( 147.0, $renewal->total, 'Nonnumeric and nonfinite amounts preserve copied taxes' );

foreach ( array(
	'Failed quote' => array( array( $error ), array(), 1 ),
	'Skipped quote' => array( array(), array( 'fixture_rate_limited' => true ), 0 ),
	'Data Import' => array( array(), array( 'data_mover' => true ), 0 ),
	'Disabled integration' => array( array(), array( 'disable_integration' => 'yes' ), 0 ),
	'Unconfigured integration' => array( array(), array( 'tc_key' => '' ), 0 ),
) as $label => list( $responses, $options, $calls ) ) {
	$renewal = renewal_fixture( $subscription, $responses, $options );
	run_renewal( $renewal, $subscription, $calls, $label );
	renewal_expect( 11.0, $renewal->cart_taxes[42] ?? null, "$label preserves copied cart tax" );
	renewal_expect( 1.0, $renewal->shipping_taxes[42] ?? null, "$label preserves copied shipping tax" );
	renewal_expect( 147.0, $renewal->total, "$label preserves gross total" );
}

$order = renewal_fixture( $subscription, array( $quote ) );
$order->meta['_wootax_status'] = 'pending';
$sst_order = new SST_Order( $order );
renewal_expect( true, $sst_order->calculate_taxes(), 'Ordinary order recalculation still succeeds' );
renewal_expect( array( 42 => 11.0, SST_RATE_ID => 12.5 ), $order->cart_taxes, 'Ordinary order recalculation retains its manual cart rates' );
renewal_expect( array( 42 => 1.0, SST_RATE_ID => 1.5 ), $order->shipping_taxes, 'Ordinary order recalculation retains its manual shipping rates' );

echo "Subscription renewal tax regression checks passed ($assertions assertions).\n";
