<?php
/**
 * Update functions.
 *
 * Callbacks invoked by the SST updater.
 *
 * @author  Simple Sales Tax
 * @package SST
 * @since   5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * In 2.6, we eliminated the ability to manually disable shipping tax. This
 * function deletes the related option.
 *
 * @since 5.0
 */
function sst_update_26_remove_shipping_taxable_option() {
	delete_option( 'wootax_shipping_taxable' );

	return false;
}

/**
 * In 3.8, we started allowing multiple business addresses. This function
 * migrates existing address data to work with the new address system.
 *
 * @since 5.0
 */
function sst_update_38_update_addresses() {
	// Set new address array.
	$address = new TaxCloud\Address(
		get_option( 'wootax_address1' ),
		get_option( 'wootax_address2' ),
		get_option( 'wootax_city' ),
		get_option( 'wootax_state' ),
		get_option( 'wootax_zip5' ),
		get_option( 'wootax_zip4' )
	);

	SST_Settings::set( 'addresses', array( wp_json_encode( $address ) ) );

	// Delete old options.
	delete_option( 'wootax_address1' );
	delete_option( 'wootax_address2' );
	delete_option( 'wootax_state' );
	delete_option( 'wootax_city' );
	delete_option( 'wootax_zip5' );
	delete_option( 'wootax_zip4' );

	return false;
}

/**
 * In version 4.2, we started using the WooCommerce settings API to manage plugin
 * settings. This function migrates existing settings so they can work with the
 * API.
 *
 * @since 5.0
 */
function sst_update_42_migrate_settings() {
	global $wpdb;

	$options = array(
		'wootax_tc_id',
		'wootax_tc_key',
		'wootax_usps_id',
		'wootax_show_exempt',
		'wootax_exemption_text',
		'wootax_company_name',
		'wootax_show_zero_tax',
		'wootax_tax_based_on',
		'wootax_addresses',
		'wootax_default_address',
	);

	// Get old options.
	$existing = $wpdb->get_results(
		"SELECT * FROM {$wpdb->options} WHERE option_name IN ( " . implode( ',', $options ) . ' );'
	);

	// Migrate.
	$new_options = get_option( 'woocommerce_wootax_settings', array() );

	foreach ( $existing as $old_option ) {
		$new_options[ $old_option->option_name ] = maybe_unserialize( $old_option->option_value );
	}

	update_option( 'woocommerce_wootax_settings', $new_options );

	// Delete old options.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name IN ( " . implode( ',', $options ) . ' );' );

	return false;
}

/**
 * In version 4.2, we eliminated the "wootax_order" post type and started
 * storing tax data as order metadata. This function transfer all metadata
 * associated with wootax_order posts to the corresponding WooCommerce order.
 *
 * Unlike its predecessor, this function DOES NOT reformat existing cart_taxes,
 * lookup_data, and mapping arrays. This means that the refund/capture/lookup
 * routines must detect and handle legacy orders.
 *
 * @since 5.0
 */
function sst_update_42_migrate_order_data() {
	global $wpdb;

	/**
	 * Associate all existing metadata for wootax_order posts with the
	 * corresponding WooCommerce order.
	 */
	$meta_keys = array(
		'_wootax_tax_total',
		'_wootax_shipping_tax_total',
		'_wootax_captured',
		'_wootax_refunded',
		'_wootax_customer_id',
		'_wootax_tax_item_id',
		'_wootax_exemption_applied',
		'_wootax_lookup_data',
		'_wootax_cart_taxes',
	);

	$wpdb->query(
		"
        UPDATE {$wpdb->postmeta} wt, (
            SELECT * FROM {$wpdb->postmeta}
            WHERE meta_key = '_wootax_wc_order_id'
            AND meta_key <> 0
        ) wc
        SET wt.post_id = wc.meta_value
        WHERE wt.post_id = wc.post_id
        AND wt.meta_key <> 0
        AND wt.meta_key IN ( " . implode( ',', $meta_keys ) . ' );
    '
	);

	/**
	 * Process WooCommerce orders. Add new order and item metadata introduced
	 * in 4.2.
	 */
	$orders = $wpdb->get_results(
		"
        SELECT p.ID as wt_oid, pm.meta_value AS wc_oid
        FROM {$wpdb->posts} p, {$wpdb->postmeta} pm
        WHERE p.ID = pm.post_id
        AND pm.meta_key = '_wootax_wc_order_id';
    "
	);

	foreach ( $orders as $order ) {

		$lookup_data = get_post_meta( $order->wt_oid, '_wootax_lookup_data', true );
		$cart_taxes  = get_post_meta( $order->wt_oid, '_wootax_cart_taxes', true );

		// No need to update order if lookup_data not set.
		if ( ! is_array( $lookup_data ) ) {
			continue;
		}

		foreach ( $lookup_data as $location_key => $items ) {
			// Skip cart_id/order_id.
			if ( ! is_array( $items ) ) {
				continue;
			}

			foreach ( $items as $index => $item ) {
				// Get sales tax for item.
				if ( isset( $cart_taxes[ $location_key ][ $index ] ) ) {
					$tax_amount = $cart_taxes[ $location_key ][ $index ];
				} else {
					$tax_amount = 0;
				}

				// Update item metadata.
				$item_id   = $item['ItemID'];
				$item_type = sst_update_42_get_item_type( $item_id );

				switch ( $item_type ) {
					case 'product':
						if ( 'product_variation' === get_post_type( $item_id ) ) {
							$meta_key = '_variation_id';
						} else {
							$meta_key = '_product_id';
						}

						$cart_item_id = $wpdb->get_var(
							$wpdb->prepare(
								"
                            SELECT order_item_id
                            FROM {$wpdb->prefix}woocommerce_order_itemmeta
                            WHERE meta_key = %s
                            AND meta_value = %s
                            AND order_id = %d
                        ",
								$meta_key,
								$item_id,
								$order->wc_oid
							)
						);

						if ( ! is_null( $cart_item_id ) ) {
							wc_update_order_item_meta( $cart_item_id, '_wootax_index', $index );
							wc_update_order_item_meta( $cart_item_id, '_wootax_tax_amount', $tax_amount );
							wc_update_order_item_meta( $cart_item_id, '_wootax_location_id', $location_key );
						}
						break;
					case 'shipping':
						if ( version_compare( WC_VERSION, '2.2', '<' ) ) {
							update_post_meta( $order->wc_oid, '_wootax_first_found', $location_key );
							update_post_meta( $order->wc_oid, '_wootax_shipping_index', $index );
						} else {
							// NOTE: This assumes one shipping method per order.
							$shipping_id = $wpdb->get_var(
								$wpdb->prepare(
									"
                                SELECT order_item_id FROM {$wpdb->prefix}woocommerce_order_items
                                WHERE order_item_type = 'shipping'
                                AND order_id = %d
                                LIMIT 1;
                            ",
									$order->wc_oid
								)
							);

							if ( ! is_null( $shipping_id ) ) {
								wc_update_order_item_meta( $shipping_id, '_wootax_index', $index );
								wc_update_order_item_meta( $shipping_id, '_wootax_tax_amount', $tax_amount );
								wc_update_order_item_meta( $shipping_id, '_wootax_location_id', $location_key );
							}
						}
						break;
					case 'fee':
						$fee_id = $wpdb->get_var(
							$wpdb->prepare(
								"
                            SELECT order_item_id
                            FROM {$wpdb->prefix}woocommerce_order_items
                            WHERE order_item_name = %s
                            AND order_item_type = 'fee'
                            AND order_id = %d
                        ",
								$item_id,
								$order->wc_oid
							)
						);

						if ( ! is_null( $fee_id ) ) {
							wc_update_order_item_meta( $fee_id, '_wootax_index', $index );
							wc_update_order_item_meta( $fee_id, '_wootax_tax_amount', $tax_amount );
							wc_update_order_item_meta( $fee_id, '_wootax_location_id', $location_key );
						}
						break;
				}
			}
		}
	}

	// Remove all wootax_order posts and the corresponding metadata.
	$wpdb->query(
		"
        DELETE FROM {$wpdb->posts} p, {$wpdb->postmeta} pm
        WHERE p.ID = pm.post_id
        AND p.post_type = 'wootax_order'
    "
	);

	return false;
}

/**
 * Helper for 4.2 update: Get item type given item ID.
 *
 * @param int $item_id Item ID.
 *
 * @return string "shipping," "cart," or "fee"
 * @since 5.0
 */
function sst_update_42_get_item_type( $item_id ) {
	global $wpdb;

	if ( 99999 === (int) $item_id ) {
		return 'shipping';
	} elseif ( in_array( get_post_type( $item_id ), array( 'product', 'product_variation' ), true ) ) {
		return 'product';
	} else {
		return 'fee';
	}

	return false;
}

/**
 * After version 4.5, license keys were no longer required. Remove the license
 * key option.
 *
 * @since 5.0
 */
function sst_update_45_remove_license_option() {
	delete_option( 'wootax_license_key' );

	return false;
}

/**
 * In 5.0, we added support for multiple default origin addresses. This function
 * migrates existing address data to work with the new system.
 *
 * @since 5.0
 */
function sst_update_50_origin_addresses() {
	$default_address = SST_Settings::get( 'default_address' );
	$addresses       = SST_Settings::get( 'addresses' );

	foreach ( $addresses as $key => $address ) {
		try {
			$new_address = new SST_Origin_Address(
				$key,
				(int) $key === (int) $default_address,
				isset( $address['address_1'] ) ? $address['address_1'] : '',
				isset( $address['address_2'] ) ? $address['address_2'] : '',
				isset( $address['city'] ) ? $address['city'] : '',
				isset( $address['state'] ) ? $address['state'] : '',
				isset( $address['zip5'] ) ? $address['zip5'] : '',
				isset( $address['zip4'] ) ? $address['zip4'] : ''
			);

			$addresses[ $key ] = wp_json_encode( $new_address );
		} catch ( Exception $ex ) {
			// Address was invalid -- not much we can do.
			unset( $addresses[ $key ] );
		}
	}

	SST_Settings::set( 'addresses', $addresses );
	SST_Settings::set( 'default_address', null );

	return false;
}

/**
 * Prior to 5.0, default category TICs were stored as WordPress options. After 5.0,
 * category TICs are stored as term metadata.
 *
 * @since 5.0
 */
function sst_update_50_category_tics() {
	$terms = get_terms(
		array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
		)
	);

	foreach ( $terms as $term ) {
		$tic = get_option( 'tic_' . $term->term_id );

		if ( ! empty( $tic ) ) {
			if ( is_array( $tic ) ) {
				$tic = $tic['tic'];
			}

			update_term_meta( $term->term_id, 'tic', $tic );

			delete_option( 'tic_' . $term->term_id );
		}
	}

	return false;
}

/**
 * Before 5.0, we used a "lookup_data" data structure to store information about
 * the tax lookups for an order. The structure consisted of a two-dimensional array
 * of CartItems indexed by origin address key. One lookup was sent for each of
 * the entries in "lookup_data," and the CartID/OrderID sent to TaxCloud were stored
 * in a separate "taxcloud_ids" array, also indexed by origin address key.
 *
 * In addition to the above, each order had two other data structures: an "identifiers"
 * array and a "mapping" array. The identifiers array was used to deal with the fact
 * that different item identifiers were sent to TaxCloud during checkout and after. It
 * mapped universally available item IDs (product/variation IDs for line items,
 * sanitized name for fees, SHIPPING for shipping methods) to the IDs sent to TaxCloud
 * during checkout, and was meant to ensure that orders could be captured immediately
 * after checkout without an additional lookup.
 *
 * The mapping array was generated for each entry in lookup_data before a lookup and
 * mapped tuples (origin address key, CartItem index) to an internal item ID (or, during
 * checkout, an array with keys 'type', 'index', 'id' and 'key').
 *
 * Each order also had two boolean flags 'captured' and 'refunded' associated with
 * it. The 'captured' flag was set after the order was captured in TaxCloud, and the
 * 'refunded' flag was set when a partial or full refund was issued.
 *
 * In 5.0, the "lookup_data" and "mapping_array" data structures were merged into a
 * simplified "packages" data structure. In addition, the "identifiers" data structure
 * was eliminated. The boolean flags 'captured' and 'refunded' have also been merged
 * into a single 'status' field. This function updates all existing orders to use the
 * new data structures.
 *
 * @throws Exception When order processing fails.
 */
function sst_update_50_order_data() {
	global $wpdb;

	/* Get next batch of orders to process */
	$orders = $wpdb->get_results(
		"
        SELECT p.ID AS ID
        FROM {$wpdb->posts} p
        WHERE p.post_type = 'shop_order'
        AND NOT EXISTS (
            SELECT meta_id 
            FROM {$wpdb->postmeta} pm
            WHERE pm.post_id = p.ID
            AND pm.meta_key = '_wootax_db_version'
            AND pm.meta_value = '5.0'
        )
        ORDER BY p.ID DESC
        LIMIT 50
    "
	);

	/* Define variables used within the loop */
	$logger   = new WC_Logger();
	$woo_3_0  = version_compare( WC_VERSION, '3.0', '>=' );
	$based_on = SST_Settings::get( 'tax_based_on' );

	foreach ( $orders as $order ) {

		$_order = new SST_Order( $order->ID );

		try {
			$destination = $_order->get_destination_address();

			if ( is_null( $destination ) ) {
				throw new Exception( 'No destination address is available for order ' . $order->ID . '. Skipping.' );
			}

			/*
			 * Exemption certificates previously stored under key 'exemption_applied'.
			 * In 5.0, we move them to key 'exempt_cert' and store them in a different
			 * format.
			 */
			$old_certificate = $_order->get_meta( 'exemption_applied' );

			if ( is_array( $old_certificate ) && isset( $old_certificate['CertificateID'] ) ) {
				$new_certificate = new TaxCloud\ExemptionCertificateBase(
					$old_certificate['CertificateID']
				);

				$_order->set_certificate( $new_certificate );
			}

			/*
			 * Actions we take from here will depend on the order status (pending,
			 * captured, refunded).
			 */
			$captured = $_order->get_meta( 'captured' );
			$refunded = $_order->get_meta( 'refunded' );

			if ( ! $captured && ! $refunded ) {         /* Pending */

				/*
				 * If order is not actually pending, update the status, but don't
				 * recalculate the taxes. Orders with a status other than pending
				 * were very likely placed before SST was installed.
				 */
				if ( in_array( $_order->get_status(), array( 'pending', 'processing', 'on-hold' ), true ) ) {
					$_order->calculate_taxes();
					$_order->calculate_totals( false );
				}

				$_order->update_meta( 'status', 'pending' );
			} elseif ( $captured && ! $refunded ) {    /* Captured */

				$taxcloud_ids = $_order->get_meta( 'taxcloud_ids' );
				$identifiers  = $_order->get_meta( 'identifiers' );

				if ( ! is_array( $taxcloud_ids ) || empty( $taxcloud_ids ) ) {
					throw new Exception( 'TaxCloud IDs not available for order ' . $order->ID . '. Skipping.' );
				}

				/* Build map from address keys to items */
				$mappings = array();

				foreach ( $_order->get_items( array( 'line_item', 'fee', 'shipping' ) ) as $item_id => $item ) {
					$location_id = wc_get_order_item_meta( $item_id, '_wootax_location_id' );

					if ( empty( $location_id ) ) { /* Very old order (pre 4.2) */
						continue;
					}

					if ( ! isset( $mappings[ $location_id ] ) ) {
						$mappings[ $location_id ] = array();
					}

					$mappings[ $location_id ][ $item_id ] = $item;
				}

				/* For each address key, create one or more new packages. */
				$packages = array();

				foreach ( $mappings as $address_key => $items ) {
					/*
					 * Create a base package with just the cart id, order id, and
					 * addresses set. We will copy this package to create others.
					 */
					$base_package = sst_create_package();

					$base_package['cart_id']     = $taxcloud_ids[ $address_key ]['cart_id'];
					$base_package['order_id']    = $taxcloud_ids[ $address_key ]['order_id'];
					$base_package['origin']      = SST_Addresses::to_address(
						SST_Addresses::get_address( $address_key )
					);
					$base_package['destination'] = $destination;

					/*
					 * Create a package for every shipping method that falls under
					 * this address key.
					 */
					$new_packages = array();

					foreach ( $items as $item_id => $item ) {
						if ( 'shipping' !== $item['type'] ) {
							continue;
						}

						$new_package = $base_package;

						/* Set shipping method */
						$method_id = wc_get_order_item_meta( $item_id, 'method_id' );
						$total     = wc_get_order_item_meta( $item_id, $woo_3_0 ? 'total' : 'cost' );

						$new_package['shipping'] = new WC_Shipping_Rate( $item_id, '', $total, array(), $method_id );

						/* Add cart item and map entry for shipping */
						$new_package['contents'][] = new TaxCloud\CartItem(
							count( $new_package['contents'] ),
							isset( $identifiers[ SST_SHIPPING_ITEM ] ) ? $identifiers[ SST_SHIPPING_ITEM ] : $item_id,
							sst_get_shipping_tic( $method_id ),
							$total,
							1
						);
						$new_package['map'][]      = array(
							'type'    => 'shipping',
							'id'      => SST_SHIPPING_ITEM,
							'cart_id' => $item_id,
						);

						$new_packages[] = $new_package;
						unset( $items[ $item_id ] );
					}

					/*
					 * Add all fees and line items to the first package. If no
					 * packages were created, create one.
					 */
					if ( empty( $new_packages ) ) {
						$new_packages[] = $base_package;
					}

					foreach ( $items as $item_id => $item ) {
						if ( 'fee' === $item['type'] ) {
							$taxcloud_id = sanitize_title(
								empty( $item['name'] ) ? __( 'Fee', 'woocommerce' ) : $item['name']
							);

							$new_packages[0]['contents'][] = new TaxCloud\CartItem(
								count( $new_packages[0]['contents'] ),
								isset( $identifiers[ $taxcloud_id ] ) ? $identifiers[ $taxcloud_id ] : $item_id,
								apply_filters( 'wootax_fee_tic', SST_DEFAULT_FEE_TIC ),
								$item['line_total'],
								1
							);
							$new_packages[0]['map'][]      = array(
								'type'    => 'fee',
								'id'      => $taxcloud_id,
								'cart_id' => $item_id,
							);
						} else {
							$taxcloud_id = $item['variation_id'] ? $item['variation_id'] : $item['product_id'];

							$new_packages[0]['contents'][] = new TaxCloud\CartItem(
								count( $new_packages[0]['contents'] ),
								isset( $identifiers[ $taxcloud_id ] ) ? $identifiers[ $taxcloud_id ] : $item_id,
								SST_Product::get_tic( $item['product_id'], $item['variation_id'] ),
								'item-price' === $based_on ? $item['line_subtotal'] / $item['qty'] : $item['line_subtotal'],
								'item-price' === $based_on ? $item['qty'] : 1
							);
							$new_packages[0]['map'][]      = array(
								'type'    => 'line_item',
								'id'      => $taxcloud_id,
								'cart_id' => $item_id,
							);
						}
					}

					$packages = array_merge( $packages, $new_packages );
				}

				/*
				 * Generate lookup request for each package (cartItems is the only
				 * required field).
				 */
				foreach ( $packages as &$package ) {
					$package['request'] = new TaxCloud\Request\Lookup(
						SST_Settings::get( 'tc_id' ),
						SST_Settings::get( 'tc_key' ),
						$_order->get_user_id(),
						$package['cart_id'],
						$package['contents'],
						$package['origin'],
						$package['destination']
					);
				}

				$_order->set_packages( $packages );
				$_order->update_meta( 'status', 'captured' );
			} elseif ( $refunded ) {                   /* Refunded */

				$_order->update_meta( 'status', 'refunded' );
			}
		} catch ( Exception $ex ) {
			$logger->add( 'sst_db_updates', $ex->getMessage() );
		} finally {
			$_order->update_meta( 'db_version', '5.0' );
			$_order->save();
		}
	}

	/*
	 * If more orders need processing, keep this function in the background
	 * processing queue.
	 */
	if ( 50 === count( $orders ) ) {
		return 'sst_update_50_order_data';
	}

	return false;
}

/**
 * Starting with 5.9, TICs are stored in a WordPress transient. The sst_tics
 * table is no longer needed and can be safely dropped.
 */
function sst_update_59_tic_table() {
	global $wpdb;

	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sst_tics" );
}

/**
 * Fixes the duplicate transaction bug introduced in v6.0.5.
 */
function sst_update_606_fix_duplicate_transactions() {
	$batch_size = 25;
	$args       = array(
		'type'           => 'shop_order',
		'return'         => 'ids',
		'limit'          => $batch_size,
		'wootax_version' => '6.0.5',
		'wootax_status'  => 'captured',
	);

	$order_ids = wc_get_orders( $args );

	$api_id  = SST_Settings::get( 'tc_id' );
	$api_key = SST_Settings::get( 'tc_key' );

	foreach ( $order_ids as $order_id ) {
		$order          = new SST_Order( $order_id );
		$old_packages   = $order->get_packages();
		$order_packages = $order->create_packages();
		$new_packages   = array();
		$removed        = array();

		foreach ( $old_packages as $key => $package ) {
			$matching_package = _sst_update_606_find_matching_package( $package, $order_packages );

			if ( $matching_package ) {
				$new_packages[] = $package;
			} else {
				$package_order_id = sprintf( '%d_%s', $order_id, $key );
				$cart_items       = $package['request']->getCartItems();

				// Try to return the extraneous package.
				try {
					$request = new TaxCloud\Request\Returned(
						$api_id,
						$api_key,
						$package_order_id,
						$cart_items,
						date( 'c' )
					);

					TaxCloud()->Returned( $request );
				} catch ( Exception $ex ) {
					wc_get_logger()->debug(
						sprintf(
							/* translators: 1 - WooCommerce order ID, 2 - Error message */
							__( 'Failed to refund extraneous package for order #%1$d: %2$s.', 'simple-sales-tax' ),
							$order_id,
							$ex->getMessage()
						),
						array( 'source' => 'sst_db_updates' )
					);
				}

				$removed[] = $package;
			}
		}

		if ( count( $removed ) > 0 ) {
			$order->update_meta( 'removed_packages', $removed );
		}

		$order->set_packages( $new_packages );
		$order->update_meta( 'db_version', '6.0.6' );

		$order->save();
	}

	if ( count( $order_ids ) === $batch_size ) {
		// More orders remain to be processed.
		return 'sst_update_606_fix_duplicate_transactions';
	}

	return false;
}

/**
 * Helper to find a shipping package matching the given package.
 *
 * Packages match if they have the same contents, fees, shipping method, origin and destination addresses,
 * and exemption certificate.
 *
 * @param array $needle   Package to find a match for.
 * @param array $haystack Packages to search for matches.
 *
 * @return bool|array False if no matching package is found or matching package otherwise.
 */
function _sst_update_606_find_matching_package( $needle, $haystack ) {
	$needle      = _sst_update_606_normalize_package( $needle );
	$needle_hash = md5( wp_json_encode( $needle ) );

	foreach ( $haystack as $package ) {
		$normalized_package = _sst_update_606_normalize_package( $package );
		$package_hash       = md5( wp_json_encode( $normalized_package ) );

		if ( $needle_hash === $package_hash ) {
			return $package;
		}
	}

	return false;
}

/**
 * Normalizes packages to ensure that the hashes of two identical packages are the same.
 *
 * @param array $package Package to normalize.
 *
 * @return array
 */
function _sst_update_606_normalize_package( $package ) {
	$new_package = array();

	// Items.
	$contents = array();

	foreach ( $package['contents'] as $item ) {
		$contents[] = array(
			'product_id'    => (int) $item['product_id'],
			'variation_id'  => (int) $item['variation_id'],
			'quantity'      => (int) $item['quantity'],
			'line_total'    => (float) $item['line_total'],
			'line_subtotal' => (float) $item['line_subtotal'],
		);
	}

	$new_package['contents'] = wp_list_sort( $contents, 'product_id' );

	// Fees.
	$fees = array();

	foreach ( $package['fees'] as $fee ) {
		$fees[] = array(
			'id'     => $fee->id,
			'amount' => $fee->amount,
		);
	}

	$new_package['fees'] = wp_list_sort( $fees, 'id' );

	// Shipping.
	$shipping = null;

	if ( ! empty( $package['shipping'] ) ) {
		$shipping = array(
			'method_id' => $package['shipping']->method_id,
			'cost'      => $package['shipping']->cost,
		);
	}

	$new_package['shipping'] = $shipping;

	// Origin and destination addresses.
	$new_package['origin']      = _sst_update_606_get_address( $package['origin'] );
	$new_package['destination'] = _sst_update_606_get_address( $package['destination'] );

	// Certificate - use ID for comparison.
	$new_package['certificate'] = $package['certificate'];

	if ( ! empty( $new_package['certificate'] ) ) {
		$new_package['certificate'] = $new_package['certificate']->getCertificateID();
	}

	return $new_package;
}

/**
 * Gets a TaxCloud Address as an array.
 *
 * @param TaxCloud\Address $address Address to format as array.
 *
 * @return array|null Address, or NULL if $address is null.
 */
function _sst_update_606_get_address( $address ) {
	if ( is_null( $address ) ) {
		return null;
	}

	return array(
		'address_1' => $address->getAddress1(),
		'address_2' => $address->getAddress2(),
		'city'      => $address->getCity(),
		'state'     => $address->getState(),
		'zip'       => $address->getZip(),
	);
}

/**
 * Import shipping origin addresses from TaxCloud when updating to 6.2.
 */
function sst_update_620_import_origin_addresses() {
	$taxcloud_addresses = SST_Addresses::get_origin_addresses( true );
	$saved_addresses    = SST_Settings::get( 'addresses', array() );

	if ( empty( $saved_addresses ) ) {
		// Easy case: Just save the new addresses
		SST_Settings::set( 'addresses', array_map( 'json_encode', $taxcloud_addresses ) );
		return false;
	}

	// Reset Default flag for all addresses. May be incorrect.
	foreach ( $taxcloud_addresses as $taxcloud_address ) {
		$taxcloud_address->setDefault( false );
	}

	// Set Default flag for addresses as appropriate.
	$address_id_map       = array();
	$mismatched_addresses = array();

	foreach ( $saved_addresses as $old_index => $saved_address ) {
		$saved_address      = json_decode( $saved_address, true );
		$normalized_address = _sst_update_620_normalize_address( $saved_address['Address1'] );
		$matching_address   = null;

		foreach ( $taxcloud_addresses as $new_index => $taxcloud_address ) {
			if ( _sst_update_620_normalize_address( $taxcloud_address->getAddress1() ) === $normalized_address ) {
				$matching_address             = $taxcloud_address;
				$address_id_map[ $old_index ] = $taxcloud_address->getID();
				break;
			}
		}

		if ( $matching_address ) {
			$matching_address->setDefault( $saved_address['Default'] );
		} else {
			$mismatched_addresses[] = $saved_address;
		}
	}

	// Abort if an address mismatch was detected.
	if ( ! empty( $mismatched_addresses ) ) {
		SST_Settings::set( 'address_mismatch', true );

		if ( ! class_exists( 'WC_Admin_Notices' ) ) {
			require WC()->plugin_path() . '/admin/class-wc-admin-notices.php';
		}

		WC_Admin_Notices::add_custom_notice(
			'sst_address_mismatch',
			_sst_update_620_get_address_mismatch_notice( $mismatched_addresses )
		);

		return false;
	}

	SST_Settings::set( 'address_mismatch', false );
	SST_Settings::set( 'addresses', array_map( 'json_encode', $taxcloud_addresses ) );

	// Save the address ID map so we can use it to update product origin addresses
	update_option( '_sst_update_620_address_id_map', $address_id_map );

	// This will queue the sst_update_620_fix_product_origin_addresses task
	return 'sst_update_620_fix_product_origin_addresses';
}

/**
 * Get the markup for the address mismatch notice.
 *
 * @param array $mismatched_addresses Addresses not found in TaxCloud.
 *
 * @return string
 */
function _sst_update_620_get_address_mismatch_notice( $mismatched_addresses ) {
	$address_list_html = '<ul class="sst-address-list">';

	foreach ( $mismatched_addresses as $address ) {
		$address_list_html .= sprintf(
			'<li>%s, %s, %s %s</li>',
			$address['Address1'],
			$address['City'],
			$address['State'],
			$address['Zip5']
		);
	}

	$address_list_html .= '</ul>';

	return sprintf(
		__(
			'<strong>IMPORTANT: Your TaxCloud Locations are out of sync.</strong> One or more of the addresses from your Simple Sales Tax settings are not registered as Locations in your TaxCloud account. Please add all of the addresses listed below on the <a href="https://app.taxcloud.com/go/locations" target="_blank">Locations</a> page in TaxCloud, then click Dismiss to dismiss this notice. %2$s',
			'simple-sales-tax'
		),
		add_query_arg( $dismiss_args, admin_url( 'admin.php' ) ),
		$address_list_html
	);
}

/**
 * Helper function to normalize a street address. Replaces street suffixes
 * like Avenue and Road with their abbreviations and converts everything to
 * uppercase.
 *
 * Abbreviations taken from https://pe.usps.com/text/pub28/28apc_002.htm
 *
 * @param string $address A street address like 123 Easy Street.
 *
 * @return string Normalized street address.
 */
function _sst_update_620_normalize_address( $address ) {
	$address      = strtoupper( $address );
	$replacements = array(
		'ALLEE'      => 'ALY',
		'ALLEY'      => 'ALY',
		'ALLY'       => 'ALY',
		'ANEX'       => 'ANX',
		'ANNEX'      => 'ANX',
		'ANNX'       => 'ANX',
		'ARCADE'     => 'ARC',
		'AV'         => 'AVE',
		'AVEN'       => 'AVE',
		'AVENU'      => 'AVE',
		'AVENUE'     => 'AVE',
		'AVN'        => 'AVE',
		'AVNUE'      => 'AVE',
		'BAYOO'      => 'BYU',
		'BAYOU'      => 'BYU',
		'BEACH'      => 'BCH',
		'BEND'       => 'BND',
		'BLUF'       => 'BLF',
		'BLUFF'      => 'BLF',
		'BOT'        => 'BTM',
		'BOTTM'      => 'BTM',
		'BOTTOM'     => 'BTM',
		'BOUL'       => 'BLVD',
		'BOULEVARD'  => 'BLVD',
		'BOULV'      => 'BLVD',
		'BRNCH'      => 'BR',
		'BRANCH'     => 'BR',
		'BRDGE'      => 'BRG',
		'BRIDGE'     => 'BRG',
		'BROOK'      => 'BRK',
		'BROOKS'     => 'BRKS',
		'BURG'       => 'BG',
		'BURGS'      => 'BGS',
		'BYPA'       => 'BYP',
		'BYPAS'      => 'BYP',
		'BYPASS'     => 'BYP',
		'BYPS'       => 'BYP',
		'CAMP'       => 'CP',
		'CMP'        => 'CP',
		'CANYN'      => 'CYN',
		'CANYON'     => 'CYN',
		'CNYN'       => 'CYN',
		'CAPE'       => 'CPE',
		'CAUSEWAY'   => 'CSWY',
		'CAUSWA'     => 'CSWY',
		'CEN'        => 'CTR',
		'CENT'       => 'CTR',
		'CENTER'     => 'CTR',
		'CENTR'      => 'CTR',
		'CENTRE'     => 'CTR',
		'CNTER'      => 'CTR',
		'CNTR'       => 'CTR',
		'CENTERS'    => 'CTRS',
		'CIRC'       => 'CIR',
		'CIRCL'      => 'CIR',
		'CIRCLE'     => 'CIR',
		'CRCL'       => 'CIR',
		'CRCLE'      => 'CIR',
		'CIRCLES'    => 'CIRS',
		'CLIFF'      => 'CLF',
		'CLIFFS'     => 'CLFS',
		'CLUB'       => 'CLB',
		'COMMON'     => 'CMN',
		'COMMONS'    => 'CMNS',
		'CORNER'     => 'COR',
		'CORNERS'    => 'CORS',
		'COURSE'     => 'CRSE',
		'COURT'      => 'CT',
		'COURTS'     => 'CTS',
		'COVE'       => 'CV',
		'COVES'      => 'CVS',
		'CREEK'      => 'CRK',
		'CRESCENT'   => 'CRES',
		'CRSENT'     => 'CRES',
		'CRSNT'      => 'CRES',
		'CREST'      => 'CRST',
		'CROSSING'   => 'XING',
		'CRSSNG'     => 'XING',
		'CROSSROAD'  => 'XRD',
		'CROSSROADS' => 'XRDS',
		'CURVE'      => 'CURV',
		'DALE'       => 'DL',
		'DAM'        => 'DM',
		'DIV'        => 'DV',
		'DIVIDE'     => 'DV',
		'DVD'        => 'DV',
		'DRIV'       => 'DR',
		'DRIVE'      => 'DR',
		'DRV'        => 'DR',
		'DRIVES'     => 'DRS',
		'ESTATE'     => 'EST',
		'ESTATES'    => 'ESTS',
		'EXP'        => 'EXPY',
		'EXPR'       => 'EXPY',
		'EXPRESS'    => 'EXPY',
		'EXPRESSWAY' => 'EXPY',
		'EXPW'       => 'EXPY',
		'EXTENSION'  => 'EXT',
		'EXTN'       => 'EXT',
		'EXTNSN'     => 'EXT',
		'EXTENSIONS' => 'EXTS',
		'FALLS'      => 'FLS',
		'FERRY'      => 'FRY',
		'FRRY'       => 'FRY',
		'FIELD'      => 'FLD',
		'FIELDS'     => 'FLDS',
		'FLAT'       => 'FLT',
		'FLATS'      => 'FLTS',
		'FORD'       => 'FRD',
		'FORDS'      => 'FRDS',
		'FOREST'     => 'FRST',
		'FORESTS'    => 'FRST',
		'FORG'       => 'FRG',
		'FORGE'      => 'FRG',
		'FORGES'     => 'FRGS',
		'FORK'       => 'FRK',
		'FORKS'      => 'FRKS',
		'FORT'       => 'FT',
		'FRT'        => 'FT',
		'FREEWAY'    => 'FWY',
		'FREEWY'     => 'FWY',
		'FRWAY'      => 'FWY',
		'FRWY'       => 'FWY',
		'GARDEN'     => 'GDN',
		'GARDN'      => 'GDN',
		'GRDEN'      => 'GDN',
		'GRDN'       => 'GDN',
		'GARDENS'    => 'GDNS',
		'GRDNS'      => 'GDNS',
		'GATEWAY'    => 'GTWY',
		'GATEWY'     => 'GTWY',
		'GATWAY'     => 'GTWY',
		'GTWAY'      => 'GTWY',
		'GLEN'       => 'GLN',
		'GLENS'      => 'GLNS',
		'GREEN'      => 'GRN',
		'GREENS'     => 'GRNS',
		'GROV'       => 'GRV',
		'GROVE'      => 'GRV',
		'GROVES'     => 'GRVS',
		'HARB'       => 'HBR',
		'HARBOR'     => 'HBR',
		'HARBR'      => 'HBR',
		'HRBOR'      => 'HBR',
		'HARBORS'    => 'HBRS',
		'HAVEN'      => 'HVN',
		'HEIGHTS'    => 'HTS',
		'HT'         => 'HTS',
		'HIGHWAY'    => 'HWY',
		'HIGHWY'     => 'HWY',
		'HIWAY'      => 'HWY',
		'HIWY'       => 'HWY',
		'HWAY'       => 'HWY',
		'HILL'       => 'HL',
		'HILLS'      => 'HLS',
		'HLLW'       => 'HOLW',
		'HOLLOW'     => 'HOLW',
		'HOLLOWS'    => 'HOLW',
		'HOLWS'      => 'HOLW',
		'INLET'      => 'INLT',
		'ISLAND'     => 'IS',
		'ISLND'      => 'IS',
		'ISLANDS'    => 'ISS',
		'ISLNDS'     => 'ISS',
		'ISLES'      => 'ISLE',
		'JCTION'     => 'JCT',
		'JCTN'       => 'JCT',
		'JUNCTION'   => 'JCT',
		'JUNCTN'     => 'JCT',
		'JUNCTON'    => 'JCT',
		'JCTNS'      => 'JCTS',
		'JUNCTIONS'  => 'JCTS',
		'KEY'        => 'KY',
		'KEYS'       => 'KYS',
		'KNOL'       => 'KNL',
		'KNOLL'      => 'KNL',
		'KNOLLS'     => 'KNLS',
		'LAKE'       => 'LK',
		'LAKES'      => 'LKS',
		'LANDING'    => 'LNDG',
		'LNDNG'      => 'LNDG',
		'LANE'       => 'LN',
		'LIGHT'      => 'LGT',
		'LIGHTS'     => 'LGTS',
		'LOAF'       => 'LF',
		'LOCK'       => 'LCK',
		'LOCKS'      => 'LCKS',
		'LDGE'       => 'LDG',
		'LODG'       => 'LDG',
		'LODGE'      => 'LDG',
		'LOOPS'      => 'LOOP',
		'MANOR'      => 'MNR',
		'MANORS'     => 'MNRS',
		'MDW'        => 'MDWS',
		'MEADOWS'    => 'MDWS',
		'MEDOWS'     => 'MDWS',
		'MILL'       => 'ML',
		'MILLS'      => 'MLS',
		'MISSION'    => 'MSN',
		'MISSN'      => 'MSN',
		'MSSN'       => 'MSN',
		'MOTORWAY'   => 'MTWY',
		'MOUNT'      => 'MT',
		'MNT'        => 'MT',
		'MNTAIN'     => 'MTN',
		'MNTN'       => 'MTN',
		'MOUNTAIN'   => 'MTN',
		'MOUNTIN'    => 'MTN',
		'MTIN'       => 'MTN',
		'MNTNS'      => 'MTNS',
		'MOUNTAINS'  => 'MTNS',
		'NECK'       => 'NCK',
		'ORCHARD'    => 'ORCH',
		'ORCHRD'     => 'ORCH',
		'OVL'        => 'OVAL',
		'OVERPASS'   => 'OPAS',
		'PRK'        => 'PARK',
		'PARKS'      => 'PARK',
		'PARKWAY'    => 'PKWY',
		'PARKWY'     => 'PKWY',
		'PKWAY'      => 'PKWY',
		'PKY'        => 'PKWY',
		'PARKWAYS'   => 'PKWY',
		'PKWYS'      => 'PKWY',
		'PASSAGE'    => 'PSGE',
		'PATHS'      => 'PATH',
		'PIKES'      => 'PIKE',
		'PINE'       => 'PNE',
		'PINES'      => 'PNES',
		'PLACE'      => 'PL',
		'PLAIN'      => 'PLN',
		'PLAINS'     => 'PLNS',
		'PLAZA'      => 'PLZ',
		'PLZA'       => 'PLZ',
		'POINT'      => 'PT',
		'POINTS'     => 'PTS',
		'PORT'       => 'PRT',
		'PORTS'      => 'PRTS',
		'PRAIRIE'    => 'PR',
		'PRR'        => 'PR',
		'RAD'        => 'RADL',
		'RADIAL'     => 'RADL',
		'RADIEL'     => 'RADL',
		'RANCH'      => 'RNCH',
		'RANCHES'    => 'RNCH',
		'RNCHS'      => 'RNCH',
		'RAPID'      => 'RPD',
		'RAPIDS'     => 'RPDS',
		'REST'       => 'RST',
		'RDGE'       => 'RDG',
		'RIDGE'      => 'RDG',
		'RIDGES'     => 'RDGS',
		'RIVER'      => 'RIV',
		'RVR'        => 'RIV',
		'RIVR'       => 'RIV',
		'ROAD'       => 'RD',
		'ROADS'      => 'RDS',
		'ROUTE'      => 'RTE',
		'SHOAL'      => 'SHL',
		'SHOALS'     => 'SHLS',
		'SHOAR'      => 'SHR',
		'SHORE'      => 'SHR',
		'SHOARS'     => 'SHRS',
		'SHORES'     => 'SHRS',
		'SKYWAY'     => 'SKWY',
		'SPNG'       => 'SPG',
		'SPRING'     => 'SPG',
		'SPRNG'      => 'SPG',
		'SPNGS'      => 'SPGS',
		'SPRINGS'    => 'SPGS',
		'SPRNGS'     => 'SPGS',
		'SPURS'      => 'SPUR',
		'SQR'        => 'SQ',
		'SQRE'       => 'SQ',
		'SQU'        => 'SQ',
		'SQUARE'     => 'SQ',
		'SQRS'       => 'SQS',
		'SQUARES'    => 'SQS',
		'STATION'    => 'STA',
		'STATN'      => 'STA',
		'STN'        => 'STA',
		'STRAV'      => 'STRA',
		'STRAVEN'    => 'STRA',
		'STRAVENUE'  => 'STRA',
		'STRAVN'     => 'STRA',
		'STRVN'      => 'STRA',
		'STRVNUE'    => 'STRA',
		'STREAM'     => 'STRM',
		'STREME'     => 'STREME',
		'STREET'     => 'ST',
		'STRT'       => 'ST',
		'STR'        => 'ST',
		'STREETS'    => 'STS',
		'SUMIT'      => 'SMT',
		'SUMITT'     => 'SMT',
		'SUMMIT'     => 'SMT',
		'TERR'       => 'TER',
		'TERRACE'    => 'TER',
		'THROUGHWAY' => 'TRWY',
		'TRACE'      => 'TRCE',
		'TRACES'     => 'TRCE',
		'TRACK'      => 'TRAK',
		'TRACKS'     => 'TRAK',
		'TRK'        => 'TRAK',
		'TRKS'       => 'TRAK',
		'TRAFFICWAY' => 'TRFY',
		'TRAIL'      => 'TRL',
		'TRAILS'     => 'TRL',
		'TRLS'       => 'TRL',
		'TRAILER'    => 'TRLR',
		'TRLRS'      => 'TRLR',
		'TUNEL'      => 'TUNL',
		'TUNLS'      => 'TUNL',
		'TUNNEL'     => 'TUNL',
		'TUNNELS'    => 'TUNL',
		'TUNNL'      => 'TUNL',
		'TRNPK'      => 'TPKE',
		'TURNPIKE'   => 'TPKE',
		'TURNPK'     => 'TPKE',
		'UNDERPASS'  => 'UPAS',
		'UNION'      => 'UN',
		'UNIONS'     => 'UNS',
		'VALLEY'     => 'VLY',
		'VALLY'      => 'VLY',
		'VLLY'       => 'VLY',
		'VALLEYS'    => 'VLYS',
		'VDCT'       => 'VIA',
		'VIADCT'     => 'VIA',
		'VIADUCT'    => 'VIA',
		'VIEW'       => 'VW',
		'VIEWS'      => 'VWS',
		'VILL'       => 'VLG',
		'VILLAG'     => 'VLG',
		'VILLAGE'    => 'VLG',
		'VILLG'      => 'VLG',
		'VILLIAGE'   => 'VLG',
		'VILLAGES'   => 'VLGS',
		'VILLE'      => 'VL',
		'VIST'       => 'VIS',
		'VISTA'      => 'VIS',
		'VST'        => 'VIS',
		'VSTA'       => 'VIS',
		'WALKS'      => 'WALK',
		'WY'         => 'WAY',
		'WELL'       => 'WL',
		'WELLS'      => 'WLS',
		'NORTH'      => 'N',
		'NORTHEAST'  => 'NE',
		'NORTHWEST'  => 'NW',
		'EAST'       => 'E',
		'SOUTH'      => 'SW',
		'SOUTHEAST'  => 'SE',
		'SOUTHWEST'  => 'SW',
		'WEST'       => 'W',
	);

	$find    = array();
	$replace = array();
	foreach ( $replacements as $str_to_replace => $replacement ) {
		$find[]    = " {$str_to_replace} ";
		$replace[] = " {$replacement} ";
	}

	// Add space after address so finding word boundaries is easier
	$address .= ' ';

	return trim( str_replace( $find, $replace, $address ) );
}

/**
 * Update the saved origin addresses for each product after importing the
 * addresses from the user's TaxCloud account.
 */
function sst_update_620_fix_product_origin_addresses() {
	global $wpdb;

	$product_ids = $wpdb->get_col(
		"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wootax_origin_addresses' AND meta_value != ''"
	);

	if ( empty( $product_ids ) ) {
		// Nothing to process. Return false to bail.
		return false;
	}

	// Kick off batch processing of products.
	update_option( '_sst_update_620_products_list', $product_ids );
	update_option( '_sst_update_620_batch_offset', 0 );

	return 'sst_update_620_fix_product_origin_addresses_batch';
}

/**
 * Updates the origin addresses for a batch of 100 products.
 *
 * We used to save the index of the product origin addresses, now we save
 * TaxCloud Location IDs.
 */
function sst_update_620_fix_product_origin_addresses_batch() {
	$address_id_map = get_option( '_sst_update_620_address_id_map', array() );

	// If we don't have the address ID map we can't do anything, so bail.
	if ( ! $address_id_map ) {
		delete_option( '_sst_update_620_products_list' );
		delete_option( '_sst_update_620_batch_offset' );

		return false;
	}

	$all_product_ids   = get_option( '_sst_update_620_products_list', array() );
	$offset            = get_option( '_sst_update_620_batch_offset', 0 );
	$batch_size        = 100;
	$batch_product_ids = array_slice( $all_product_ids, $offset, $batch_size );

	foreach ( $batch_product_ids as $product_id ) {
		$saved_origin_addresses = get_post_meta( $product_id, '_wootax_origin_addresses', true );
		$new_origin_addresses   = array();
		foreach ( $saved_origin_addresses as $address_index ) {
			if ( isset( $address_id_map[ $address_index ] ) ) {
				$new_origin_addresses[] = $address_id_map[ $address_index ];
			}
		}
		update_post_meta( $product_id, '_wootax_origin_addresses', $new_origin_addresses );
		update_post_meta( $product_id, '_wootax_origin_addresses_pre_62', $saved_origin_addresses );
	}

	if ( count( $batch_product_ids ) < $batch_size ) {
		// Reached end. Clean up.
		delete_option( '_sst_update_620_products_list' );
		delete_option( '_sst_update_620_batch_offset' );

		return false;
	}

	// Continue processing next batch.
	update_option( '_sst_update_620_batch_offset', $offset + $batch_size );

	return 'sst_update_620_fix_product_origin_addresses_batch';
}

/**
 * Delete the _wootax_package_cache meta key on upgrade to 7.0.
 */
function sst_update_700_delete_package_cache() {
	global $wpdb;

	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_wootax_package_cache'" );
}

/**
 * Delete null _wootax_exempt_cert meta values on upgrade to 7.0.
 */
function sst_update_700_delete_null_certificates() {
	global $wpdb;

	$null_value = 's:2:"N;";';

	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_wootax_exempt_cert' AND meta_value = %s",
			$null_value
		)
	);
}

/**
 * Replaces serialized TaxCloud\ExemptionCertificateBase objects
 * with string certificate IDs in the `exempt_cert` meta key on
 * upgrade to 7.0.
 */
function sst_update_700_migrate_certificates() {
	$batch_size = 100;
	$args       = array(
		'type'         => 'shop_order',
		'return'       => 'ids',
		'limit'        => $batch_size,
		'meta_key'     => '_wootax_exempt_cert',
		'meta_value'   => 'ExemptionCertificateBase',
		'meta_compare' => 'LIKE',
	);

	$order_ids = wc_get_orders( $args );

	foreach ( $order_ids as $order_id ) {
		$order       = new SST_Order( $order_id );
		$certificate = $order->get_meta( 'exempt_cert' );

		if ( is_a( $certificate, 'TaxCloud\ExemptionCertificateBase' ) ) {
			$order->set_certificate_id( $certificate->getCertificateID() );
			$order->save();
		}
	}

	if ( count( $order_ids ) === $batch_size ) {
		// More orders remain to process.
		return 'sst_update_700_migrate_certificates';
	}

	return false;
}

/**
 * Compresses saved order packages on upgrade to 7.0.
 */
function sst_update_700_compress_packages() {
	$batch_size = 100;
	$args       = array(
		'type'         => 'shop_order',
		'return'       => 'ids',
		'limit'        => $batch_size,
		'meta_key'     => '_wootax_db_version',
		'meta_value'   => '7.0.0',
		'meta_compare' => '!=',
	);

	$order_ids = wc_get_orders( $args );

	foreach ( $order_ids as $order_id ) {
		$order        = new SST_Order( $order_id );
		$packages     = $order->get_meta( 'packages' );
		$new_packages = array_map(
		    array( $order, 'compress_package_data' ),
		    $packages
		);

		$order->set_packages( $new_packages );
		$order->update_meta( 'db_version', '7.0.0' );
		$order->save();
	}

	if ( count( $order_ids ) === $batch_size ) {
		// More orders remain to process.
		return 'sst_update_700_compress_packages';
	}

	return false;
}
