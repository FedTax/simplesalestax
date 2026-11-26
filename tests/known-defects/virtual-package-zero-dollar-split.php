<?php

// Known-defect proof: php tests/known-defects/virtual-package-zero-dollar-split.php
// With default settings, a $0 shippable parent whose priced child lines do not
// need shipping (a bundle) is split into a $0 package plus a priced package, and
// each package is captured at TaxCloud as its own order. No package that totals
// $0 should be created next to a priced package. Checks the order path
// (SST_Order) and the cart checkout path (SST_Checkout) separately.
require __DIR__ . '/bootstrap.php';

function zero_dollar_package_count( $packages ) {
	$zero = 0;
	foreach ( $packages as $package ) {
		$zero += 0.0 === (float) array_sum( array_column( $package['contents'], 'line_total' ) ) ? 1 : 0;
	}
	return $zero;
}

$order_packages    = ( new SST_Order( order_with_lines( 0, 95.96 ) ) )->create_packages();
$checkout_packages = checkout_packages_with_lines( 0, 95.96 );

finish(
	array(
		'order path creates no separate $0 package and keeps both lines'    => zero_dollar_package_count( $order_packages ) > 0 || 2 !== line_count( $order_packages ),
		'checkout path creates no separate $0 package and keeps both lines' => zero_dollar_package_count( $checkout_packages ) > 0 || 2 !== line_count( $checkout_packages ),
	)
);
