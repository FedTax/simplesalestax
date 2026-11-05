<?php
/** DEV-10621: php tests/certificate-meta-regression.php. No database or API calls. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'SST_SINGLE_PURCHASE_CERT_ID', 'single-purchase' );
const TEST_CERTIFICATE_ID = '11111111-2222-3333-4444-555555555555';

$assertions = 0;
$saved_items = array();
$logged_meta = array();

function __( $message ) { return $message; }
function esc_html__( $message ) { return $message; }
function add_action() {}
function add_filter() {}
function apply_filters( $hook, $value ) { return $value; }
function check_ajax_referer() {}
function wp_verify_nonce() { return true; }
function wp_unslash( $value ) { return $value; }
function sanitize_text_field( $value ) { return is_string( $value ) ? trim( $value ) : ''; }
function absint( $value ) { return abs( (int) $value ); }
function wc_clean( $value ) { return $value; }
function wc_get_order( $id ) { return $GLOBALS['test_order']; }
function wc_save_order_items( $id, $items ) { $GLOBALS['saved_items'][] = $id; }
function sst_order_calculate_taxes( $order ) { throw new RuntimeException( 'recalculation reached' ); }
function wp_die( $message = '' ) { throw new RuntimeException( (string) $message ); }
function wp_send_json_error( $message ) { throw new RuntimeException( $message ); }
function wp_send_json_success() { throw new RuntimeException( 'success' ); }
function current_user_can() { return true; }
function get_current_user_id() { return 42; }
function get_user_by( $field, $id ) { return 42 === $id ? (object) array( 'ID' => 42 ) : false; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function sst_get_api_version() { return 'v1'; }
function wc_get_orders() { return array( 123 ); }
function sst_unslash( $value ) {
	while ( strstr( $value, '\\\\' ) ) { $value = stripslashes( $value ); }
	return $value;
}
function maybe_unserialize( $value ) { return is_string( $value ) && preg_match( '/^[aOs]:/', $value ) ? unserialize( $value ) : $value; }

class SST_Settings {
	public static function get( $key, $default = null ) { return $default; }
}
class SST_Logger {
	public static function order_log( $message, $id, $meta ) { $GLOBALS['logged_meta'] = $meta; }
}
class WC_Order {
	public $meta = array();
	public function get_meta( $key, $single = true, $context = 'view' ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function save() {}
	public function get_id() { return 123; }
	public function get_status() { return 'pending'; }
	public function get_customer_id() { return 42; }
	public function get_total() { return 110; }
	public function get_shipping_total() { return 0; }
	public function get_total_tax() { return 10; }
	public function __call( $method, $args ) { return 'existing address'; }
}
class Certificate_Meta_Gadget {
	public static $wakeups = 0;
	public function __wakeup() { ++self::$wakeups; }
}
class WC_Product extends Certificate_Meta_Gadget {}

require dirname( __DIR__ ) . '/includes/vendor/autoload.php';
require dirname( __DIR__ ) . '/includes/class-sst-certificates.php';
require dirname( __DIR__ ) . '/includes/class-sst-addresses.php';
require dirname( __DIR__ ) . '/includes/vendor/woocommerce/woocommerce/plugins/woocommerce/includes/class-wc-shipping-rate.php';
require dirname( __DIR__ ) . '/includes/sst-update-functions.php';
require dirname( __DIR__ ) . '/includes/abstracts/class-sst-abstract-cart.php';
require getenv( 'SST_ORDER_TEST_FILE' ) ?: dirname( __DIR__ ) . '/includes/class-sst-order.php';
require dirname( __DIR__ ) . '/includes/class-sst-ajax.php';
require dirname( __DIR__ ) . '/includes/class-sst-order-controller.php';

function expect_same( $expected, $actual, $message ) {
	++$GLOBALS['assertions'];
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}
function response_from( $callback ) {
	try { $callback(); } catch ( RuntimeException $ex ) { return $ex->getMessage(); }
	throw new RuntimeException( 'Expected an endpoint response.' );
}

$test_order = new WC_Order();
$order = new SST_Order( $test_order );
$payload = serialize( new Certificate_Meta_Gadget() );

// WordPress preserves a serialized-looking string after its own metadata read.
foreach ( array( 'exempt_cert', 'status', 'packages', 'single_purchase_cert', 'arbitrary' ) as $key ) {
	$test_order->meta[ '_wootax_' . $key ] = $payload;
	expect_same( $payload, $order->get_meta( $key ), "$key: serialized payload stays a string" );
}
expect_same( 0, Certificate_Meta_Gadget::$wakeups, 'Metadata reads never instantiate arbitrary classes' );
$test_order->meta['_wootax_exempt_cert'] = 'O:8:"stdClass":0:{}';
expect_same( 'O:8:"stdClass":0:{}', $order->get_meta( 'exempt_cert' ), 'stdClass is not an allowed certificate class' );

$certificate = SST_Certificates::build_certificate(
	array( 'ExemptState' => 'CO', 'TaxType' => 'FEIN', 'StateOfIssue' => '', 'IDNumber' => 'test',
		'PurchaserBusinessType' => 'RetailTrade', 'PurchaserBusinessTypeOtherValue' => '',
		'PurchaserExemptionReason' => 'Resale', 'PurchaserExemptionReasonOtherValue' => 'Resale exemption' ),
	array( 'first_name' => 'Test', 'last_name' => 'Buyer', 'address_1' => '1 Main St', 'address_2' => '',
		'city' => 'Denver', 'state' => 'CO', 'postcode' => '80202' )
);
$property = new ReflectionProperty( TaxCloud\ExemptionCertificateBase::class, 'CertificateID' );
$property->setAccessible( true );
$property->setValue( $certificate, TEST_CERTIFICATE_ID );
foreach ( array( new TaxCloud\ExemptionCertificateBase( TEST_CERTIFICATE_ID ), $certificate ) as $legacy ) {
	$test_order->meta['_wootax_exempt_cert'] = serialize( $legacy );
	expect_same( TEST_CERTIFICATE_ID, $order->get_certificate_id(), 'Pre-7.0 serialized certificate ID still displays' );
	expect_same( get_class( $legacy ), get_class( $order->get_meta( 'exempt_cert' ) ), 'Legacy certificate type survives' );
}
expect_same( $certificate->jsonSerialize(), $order->get_meta( 'exempt_cert' )->jsonSerialize(), 'Legacy certificate detail, states and tax ID survive' );
$test_order->meta['_wootax_exempt_cert'] = addslashes( serialize( $certificate ) );
expect_same( TEST_CERTIFICATE_ID, $order->get_certificate_id(), 'Legacy certificates with extra slashes remain readable' );
$test_order->meta['_wootax_exempt_cert'] = $certificate;
expect_same( TEST_CERTIFICATE_ID, $order->get_certificate_id(), 'Already-decoded legacy certificates remain supported' );
$malformed_certificate = new TaxCloud\ExemptionCertificateBase( new Certificate_Meta_Gadget() );
$test_order->meta['_wootax_exempt_cert'] = serialize( $malformed_certificate );
expect_same( '', $order->get_certificate_id(), 'Legacy certificate IDs cannot contain embedded objects' );
expect_same( 0, Certificate_Meta_Gadget::$wakeups, 'Allowed legacy certificates cannot instantiate embedded gadget classes' );
$test_order->meta['_wootax_single_purchase_cert'] = json_encode( array( 'IDNumber' => 'test\\\\tax\\identifier' ) );
expect_same( $test_order->meta['_wootax_single_purchase_cert'], $order->get_meta( 'single_purchase_cert' ), 'JSON metadata keeps its intentional backslashes' );

// Pre-7.0 packages must still compress, including in the real 7.0 updater.
$origin = new TaxCloud\Address( '1 Origin St', '', 'Denver', 'CO', '80202' );
$destination = new TaxCloud\Address( '2 Destination St', '', 'Denver', 'CO', '80203' );
$legacy_certificate = new TaxCloud\ExemptionCertificateBase( TEST_CERTIFICATE_ID );
$legacy_package = array(
	'user' => array( 'ID' => 42 ), 'cart_id' => 'legacy-cart',
	'request' => new TaxCloud\Request\Lookup( 'test-login', 'test-key', 42, 'legacy-cart',
		array( new TaxCloud\CartItem( 0, 'product', 0, 100, 2 ) ), $origin, $destination, false, $legacy_certificate ),
	'origin' => $origin, 'destination' => $destination,
	'shipping' => new WC_Shipping_Rate( 'flat_rate:1', 'Flat rate', 5, array(), 'flat_rate' ),
	'certificate' => $legacy_certificate, 'map' => array( array( 'type' => 'line_item', 'id' => 1 ) ),
	'contents' => array( array( 'data' => new WC_Product() ) ), 'fees' => array( new Certificate_Meta_Gadget() ),
);
foreach ( array( 'packages', 'removed_packages' ) as $key ) {
	$test_order->meta[ '_wootax_' . $key ] = serialize( array( $legacy_package ) );
	$decoded = $order->get_meta( $key );
	expect_same( TaxCloud\Request\Lookup::class, get_class( $decoded[0]['request'] ), "$key: legacy request survives" );
	expect_same( TaxCloud\CartItem::class, get_class( $decoded[0]['request']->getCartItems()[0] ), "$key: legacy cart item survives" );
	expect_same( WC_Shipping_Rate::class, get_class( $decoded[0]['shipping'] ), "$key: legacy shipping survives" );
	expect_same( '__PHP_Incomplete_Class', get_class( $decoded[0]['contents'][0]['data'] ), "$key: WC products stay blocked" );
	expect_same( '__PHP_Incomplete_Class', get_class( $decoded[0]['fees'][0] ), "$key: unrelated classes stay blocked" );
}
$compressed = $order->get_packages()[0];
expect_same( array( array( 'type' => 'line_item', 'id' => 1, 'qty' => 2, 'tic' => 0, 'price' => 100 ) ), $compressed['cart_items'], 'Pre-7.0 request compresses its cart items' );
expect_same( 'flat_rate', $compressed['shipping_method'], 'Pre-7.0 shipping method survives compression' );
expect_same( 5.0, (float) $compressed['shipping_cost'], 'Pre-7.0 shipping cost survives compression' );
expect_same( SST_Addresses::format( $origin ), $compressed['origin_address'], 'Pre-7.0 origin survives compression' );
expect_same( SST_Addresses::format( $destination ), $compressed['destination_address'], 'Pre-7.0 destination survives compression' );
expect_same( TEST_CERTIFICATE_ID, $compressed['certificate_id'], 'Pre-7.0 certificate survives compression' );
expect_same( 0, Certificate_Meta_Gadget::$wakeups, 'Legacy reads and compression cannot wake products or gadgets' );
expect_same( false, sst_update_700_compress_packages(), 'Actual background migration completes' );
expect_same( '7.0.0', $test_order->meta['_wootax_db_version'], 'Actual migration saves the upgraded version' );
expect_same( array( $compressed ), $order->get_packages(), 'Migrated packages remain usable' );

$packages = array( array( 'api_version' => 'v1', 'cart_id' => 'saved-cart', 'customer_id' => 42,
	'cart_items' => array( array( 'type' => 'line_item', 'id' => 1, 'qty' => 1, 'price' => 100, 'tic' => 0 ) ) ) );
foreach ( array( 'packages', 'removed_packages' ) as $key ) {
	$test_order->meta[ '_wootax_' . $key ] = serialize( $packages );
	expect_same( $packages, $order->get_meta( $key ), "$key: existing double-serialized arrays remain readable" );
}
expect_same( $packages, $order->get_packages(), 'Legacy saved tax packages remain usable' );
$order->set_packages( $packages );
expect_same( serialize( $packages ), $test_order->meta['_wootax_packages'], 'Package arrays are serialized before the WooCommerce boundary' );
expect_same( $packages, $order->get_packages(), 'New packages remain usable' );
$test_order->meta['_wootax_packages'] = serialize( array( new Certificate_Meta_Gadget() ) );
$order->get_meta( 'packages' );
expect_same( 0, Certificate_Meta_Gadget::$wakeups, 'Legacy arrays cannot instantiate embedded gadget classes' );

// The order log must use the same restricted compatibility path.
$test_order->meta['_wootax_packages'] = serialize( $packages );
foreach ( array( $payload, serialize( $certificate ) ) as $value ) {
	$test_order->meta['_wootax_exempt_cert'] = $value;
	$test_order->meta['_wootax_status'] = $payload;
	$test_order->meta['_shipping_address_index'] = $payload;
	$_POST = array( 'order_id' => 123 );
	expect_same( 'success', response_from( array( 'SST_Ajax', 'get_order_log' ) ), 'Debug log responds successfully' );
	expect_same( $payload, $logged_meta['_wootax_status'], 'Debug log leaves noncertificate serialized strings alone' );
	expect_same( $payload, $logged_meta['_shipping_address_index'], 'Debug log leaves WooCommerce serialized strings alone' );
	expect_same( $packages, $logged_meta['_wootax_packages'], 'Debug log keeps legacy package details' );
}
expect_same( TaxCloud\ExemptionCertificate::class, get_class( $logged_meta['_wootax_exempt_cert'] ), 'Debug log keeps legitimate legacy certificates' );
expect_same( 0, Certificate_Meta_Gadget::$wakeups, 'Debug log cannot instantiate arbitrary classes' );

$invalid_ids = array( $payload, TEST_CERTIFICATE_ID . '&order_id=999', TEST_CERTIFICATE_ID . "\n", 'not-a-guid', array( TEST_CERTIFICATE_ID ), null, 123 );
foreach ( $invalid_ids as $id ) {
	expect_same( false, SST_Certificates::is_valid_certificate_id( $id ), 'Malformed ID fails GUID validation' );
	$_POST = array( 'order_id' => 123, 'country' => 'US', 'state' => 'CO', 'postcode' => '80202', 'city' => 'Denver', 'items' => '', 'exemption_certificate' => $id ?? array() );
	expect_same( 'Invalid exemption certificate ID.', response_from( array( 'SST_Ajax', 'calc_line_taxes' ) ), 'Malformed ID is rejected before saving or recalculating an order' );
	$_POST = array( 'nonce' => 'test', 'certificate_id' => $id );
	expect_same( 'Invalid exemption certificate ID.', response_from( array( 'SST_Ajax', 'delete_certificate' ) ), 'Malformed delete ID never reaches TaxCloud' );
	$_POST = array( 'exempt_cert' => $id );
	$test_order->meta['_wootax_status'] = 'pending';
	$test_order->meta['_wootax_exempt_cert'] = TEST_CERTIFICATE_ID;
	( new SST_Order_Controller() )->save_certficiate( 123 );
	expect_same( TEST_CERTIFICATE_ID, $test_order->meta['_wootax_exempt_cert'], 'Malformed admin save ID is ignored' );
}
expect_same( array(), $saved_items, 'Rejected recalculations never save items on any order' );

foreach ( array( TEST_CERTIFICATE_ID, strtoupper( TEST_CERTIFICATE_ID ), '', 'none', SST_SINGLE_PURCHASE_CERT_ID ) as $id ) {
	$_POST = array( 'order_id' => 123, 'country' => 'US', 'state' => 'CO', 'postcode' => '80202', 'city' => 'Denver', 'items' => '', 'exemption_certificate' => $id );
	expect_same( 'recalculation reached', response_from( array( 'SST_Ajax', 'calc_line_taxes' ) ), 'Valid IDs, clear selections and the internal single-purchase marker can recalculate' );
	expect_same( 'none' === $id ? '' : $id, $test_order->meta['_wootax_exempt_cert'], 'Allowed recalculation selection is saved' );
}
expect_same( true, SST_Certificates::is_valid_certificate_id( 'ABCDEFAB-CDEF-ABCD-EFAB-CDEFABCDEFAB' ), 'GUIDs accept uppercase hex without imposing a UUID version' );

echo "Certificate metadata security regression checks passed ($assertions assertions).\n";
