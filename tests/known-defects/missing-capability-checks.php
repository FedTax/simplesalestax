<?php

// Known-defect proof: php tests/known-defects/missing-capability-checks.php
// Exits 1 while the defect is present (strict expected failure) and 0 once fixed.
// Three paths act for a logged-in user without checking what that user may do:
// saving TICs for posted product IDs, dismissing admin notices, and choosing the
// sign-up-fee TIC from a request-supplied order ID. Each case runs as a
// shop-staff user who can edit product 5 only and cannot manage WooCommerce.
define( 'ABSPATH', __DIR__ . '/' );

$filters      = array();
$meta_writes  = array();
$settings_set = array();
$settings     = array( 'dismissed_notices' => array() );

function add_filter( $hook, $callback ) { global $filters; $filters[ $hook ][] = $callback; }
function add_action( $hook, $callback ) {}
function apply_filters( $hook, $value, ...$args ) {
	global $filters;
	foreach ( $filters[ $hook ] ?? array() as $callback ) {
		$value = $callback( $value, ...$args );
	}
	return $value;
}
function is_admin() { return true; }
function __( $text ) { return $text; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function wp_unslash( $value ) { return $value; }
function doing_filter() { return false; }
function check_ajax_referer() { return true; }
function wp_send_json_success( $data = null ) { return $data; }
function update_post_meta( $id, $key, $value ) { global $meta_writes; $meta_writes[] = array( $id, $key ); }
function current_user_can( $capability, $id = null ) { return 'edit_post' === $capability && 5 === (int) $id; }
function wcs_order_contains_renewal() { return true; }

class SST_Settings {
	public static function get( $key, $default = null ) { global $settings; return $settings[ $key ] ?? $default; }
	public static function set( $key, $value ) { global $settings_set; $settings_set[ $key ] = $value; }
}
class WC_Subscriptions_Cart {
	public static function get_calculation_type() { return 'none'; }
}
class WC_Subscriptions_Product {
	public static function get_trial_length() { return 14; }
	public static function get_sign_up_fee() { return 25; }
}

require dirname( __DIR__, 2 ) . '/includes/class-sst-product.php';
require dirname( __DIR__, 2 ) . '/includes/class-sst-ajax.php';
require dirname( __DIR__, 2 ) . '/includes/integrations/class-sst-subscriptions.php';

function other_products_written() {
	global $meta_writes;
	return array_values( array_unique( array_filter( array_column( $meta_writes, 0 ), fn( $id ) => 5 !== $id ) ) );
}

// 1. Saving product 5 with a posted TIC map that names product 999 and variation 998.
$_REQUEST['wootax_tic']       = array( 5 => '20010', 999 => '99999' );
SST_Product::save_product_meta( 5 );
$_POST['variable_post_id']    = array( 0 => 998 );
$_POST['wootax_tic']          = array( 998 => '99999' );
SST_Product::ajax_save_variation_tics();
$tic_writes_to_other_products = other_products_written();

// 2. Dismissing a notice.
$_POST['notice_id'] = 'review-request';
SST_Ajax::dismiss_taxcloud_notice();
$notice_dismissed = isset( $settings_set['dismissed_notices'] );

// 3. A request that names a renewal order while the cart holds an initial subscription.
$_REQUEST['order_id'] = '77';
$sign_up_fee_tic      = apply_filters( 'wootax_product_tic', 10000, 31, 0 );

$failed  = false;
$results = array(
	'saving a product TIC does not write TICs of other posts'              => array( array() === $tic_writes_to_other_products, 'wrote to posts ' . implode( ',', $tic_writes_to_other_products ) ),
	'dismissing a notice needs permission to manage WooCommerce'            => array( ! $notice_dismissed, 'notice was dismissed' ),
	'sign-up-fee TIC ignores a request order ID from an unprivileged user' => array( 91070 === $sign_up_fee_tic, "TIC was $sign_up_fee_tic" ),
);
foreach ( $results as $label => list( $ok, $detail ) ) {
	echo ( $ok ? 'PASS: ' : 'FAIL: ' ) . $label . ( $ok ? '' : " ($detail)" ) . "\n";
	$failed = $failed || ! $ok;
}
exit( $failed ? 1 : 0 );
