<?php

/**
 * Uninstalls TaxCloud for WooCommerce.
 *
 * Uninstalling removes all user roles, product data, and options.
 *
 * @author  TaxCloud
 * @package SST
 * @since   1.0
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove data, but only if "Remove Data on Delete" option is enabled
$settings    = get_option( 'woocommerce_wootax_settings' );
$remove_data = isset( $settings['remove_all_data'] ) ? 'yes' === $settings['remove_all_data'] : false;

if ( $remove_data ) {
	global $wpdb;

	// Roles
	remove_role( 'exempt-customer' );

	// Options
	$wpdb->query(
		"
        DELETE FROM {$wpdb->options} 
        WHERE option_name LIKE 'woocommerce\_wootax%' 
        OR option_name LIKE 'wootax\_%'
        OR option_name LIKE 'tic\_%';
    "
	);

	// Product settings
	$wpdb->query(
		"
        DELETE FROM {$wpdb->postmeta}
        WHERE meta_key LIKE '%wootax%';
    "
	);

	// Category TICs
	$wpdb->query(
		"
        DELETE FROM {$wpdb->termmeta}
        WHERE meta_key = 'tic';
    "
	);

	// Database tables
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sst_tics" );
}
