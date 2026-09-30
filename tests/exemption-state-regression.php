<?php

// Standalone regression test: php tests/exemption-state-regression.php
define( 'ABSPATH', __DIR__ . '/' );

$certificate_transients = array();

function get_current_user_id() { return 19151; }
function get_transient( $key ) { global $certificate_transients; return $certificate_transients[ $key ] ?? false; }
function add_filter() {}
function add_action() {}
function sst_storefront_active() { return false; }
function __( $message ) { return $message; }
function wp_date( $format, $timestamp = null ) { return date( $format, $timestamp ?? time() ); }
function get_option( $key, $default = null ) { return $default; }
function sst_prettify( $value ) { return $value; }
function WC() { global $test_woocommerce; return $test_woocommerce; }

class SST_Settings {
	public static function get( $key, $default = null ) {
		if ( 'tc_id' === $key ) { return 'TESTID'; }
		if ( 'tc_key' === $key ) { return 'TESTKEY'; }
		return $default;
	}
}

class SST_Logger {
	public static function add() {}
	public static function debug() {}
}

require dirname( __DIR__ ) . '/includes/vendor/autoload.php';
require dirname( __DIR__ ) . '/includes/class-sst-certificates.php';
require dirname( __DIR__ ) . '/includes/abstracts/class-sst-abstract-cart.php';
require dirname( __DIR__ ) . '/includes/frontend/class-sst-cart-proxy.php';
require dirname( __DIR__ ) . '/includes/frontend/class-sst-checkout.php';

class Exemption_State_Test_Cart extends SST_Abstract_Cart {
	public function request_for( &$package ) { return $this->get_lookup_for_package( $package ); }
	protected function get_packages() { return array(); }
	protected function set_packages( $packages = array() ) {}
	protected function get_base_packages() { return array(); }
	protected function create_packages() { return array(); }
	protected function get_saved_package( $hash ) { return false; }
	protected function save_package( $hash, $package ) {}
	protected function reset_taxes() {}
	protected function update_taxes() {}
	protected function set_product_tax( $id, $tax ) {}
	protected function set_shipping_tax( $id, $tax ) {}
	protected function set_fee_tax( $id, $tax ) {}
	public function get_certificate() { return null; }
	protected function handle_error( $message ) {}
}

class Exemption_State_Test_Checkout extends SST_Checkout {
	public $test_packages = array();
	public function __construct() {}
	protected function create_packages() { return $this->test_packages; }
	public function new_certificate_covers( $state ) { return $this->new_certificate_covers_packages( $state ); }
}

function expect_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}

function certificate_data( $id, $states, $created_date ) {
	return array(
		'CertificateID' => $id,
		'Detail'        => array(
			'ExemptStates'                    => array_map(
				function ( $state ) { return array( 'StateAbbr' => $state, 'ReasonForExemption' => 'Resale', 'IdentificationNumber' => 'test' ); },
				$states
			),
			'PurchaserTaxID'                  => array( 'TaxType' => 'FEIN', 'IDNumber' => 'test', 'StateOfIssue' => 'NC' ),
			'SinglePurchase'                  => false,
			'SinglePurchaseOrderNumber'       => '',
			'PurchaserFirstName'              => 'Test',
			'PurchaserLastName'               => 'Buyer',
			'PurchaserTitle'                  => '',
			'PurchaserAddress1'               => '1 Main St',
			'PurchaserAddress2'               => '',
			'PurchaserCity'                   => 'Test',
			'PurchaserState'                  => 'NC',
			'PurchaserZip'                    => '28043',
			'PurchaserBusinessType'           => 'Manufacturing',
			'PurchaserBusinessTypeOtherValue' => '',
			'PurchaserExemptionReason'        => 'Resale',
			'PurchaserExemptionReasonValue'   => '',
			'CreatedDate'                     => $created_date,
		),
	);
}

function lookup_package( $state, $certificate_id ) {
	return array(
		'contents'    => array(),
		'fees'        => array(),
		'shipping'    => null,
		'map'         => array(),
		'user'        => array( 'ID' => 19151 ),
		'origin'      => new TaxCloud\Address( '1 Main St', '', 'Test', 'NC', '28043' ),
		'destination' => new TaxCloud\Address( '1 Main St', '', 'Test', $state, '28043' ),
		'certificate' => new TaxCloud\ExemptionCertificateBase( $certificate_id ),
	);
}

$nc_cert = certificate_data( 'nc-only', array( 'NC' ), '2026-09-17T12:00:00Z' );
$ny_cert = certificate_data( 'ny-only', array( 'NY' ), '2026-09-16T12:00:00Z' );
$certificate_transients['_sst_certificates_19151'] = json_encode( array( 'nc-only' => $nc_cert, 'ny-only' => $ny_cert ) );

expect_same( 'nc-only', SST_Certificates::get_default_certificate_id_for_state( 'NC', 19151 ), 'NC auto-selection' );
expect_same( 'ny-only', SST_Certificates::get_default_certificate_id_for_state( 'NY', 19151 ), 'NY auto-selection' );
expect_same( '', SST_Certificates::get_default_certificate_id_for_state( 'TX', 19151 ), 'No TX auto-selection' );

$cart    = new Exemption_State_Test_Cart();
$package = lookup_package( 'NC', 'nc-only' );
expect_same( 'nc-only', $cart->request_for( $package )->getExemptCert()->getCertificateID(), 'NC certificate attached for NC' );

$package = lookup_package( 'NY', 'nc-only' );
expect_same( null, $cart->request_for( $package )->getExemptCert(), 'NC certificate removed for NY' );
expect_same( null, $package['certificate'], 'NY package does not retain NC certificate' );

$package = lookup_package( 'NY', 'ny-only' );
expect_same( 'ny-only', $cart->request_for( $package )->getExemptCert()->getCertificateID(), 'NY certificate attached for NY' );

$multi_state = TaxCloud\ExemptionCertificate::fromArray( certificate_data( '', array( 'NC', 'NY' ), '2026-09-17T12:00:00Z' ) );
expect_same( true, SST_Certificates::certificate_covers_state( $multi_state, 'NY', 19151 ), 'Multi-state certificate covers NY' );

$package = lookup_package( 'NY', 'missing' );
expect_same( null, $cart->request_for( $package )->getExemptCert(), 'Unknown certificate not attached' );

$package = lookup_package( 'NC', 'nc-only' );
$package['user']['ID'] = 0;
expect_same( null, $cart->request_for( $package )->getExemptCert(), 'Guest order cannot use a saved customer certificate' );

$single_purchase = TaxCloud\ExemptionCertificate::fromArray( certificate_data( '', array( 'NC' ), '2026-09-17T12:00:00Z' ) );
expect_same( true, SST_Certificates::certificate_covers_state( $single_purchase, 'NC', 19151 ), 'Single-purchase NC coverage' );
expect_same( false, SST_Certificates::certificate_covers_state( $single_purchase, 'NY', 19151 ), 'Single-purchase NY mismatch' );

$test_woocommerce = (object) array( 'cart' => new stdClass() );
$checkout         = new Exemption_State_Test_Checkout();
$checkout->test_packages = array( lookup_package( 'NC', 'nc-only' ) );
expect_same( true, $checkout->new_certificate_covers( 'NC' ), 'New NC certificate covers NC package' );
expect_same( false, $checkout->new_certificate_covers( 'NY' ), 'New NY certificate does not cover NC package' );
$checkout->test_packages[] = lookup_package( 'NY', 'nc-only' );
expect_same( false, $checkout->new_certificate_covers( 'NC' ), 'New NC certificate does not cover mixed destinations' );

echo "Exemption state regression checks passed.\n";
