<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Shipping.
 *
 * Contains functionality related to shipping.
 *
 * @author  Simple Sales Tax
 * @package SST
 * @since   5.0
 */
class SST_Shipping {

	/**
	 * Get the chosen rate for a WooCommerce cart shipping package.
	 *
	 * @param int|string $key     Package key.
	 * @param array      $package WooCommerce shipping package.
	 * @return WC_Shipping_Rate|null
	 */
	public static function get_chosen_package_rate( $key, $package ) {
		$chosen_methods = WC()->session->get( 'chosen_shipping_methods' );

		// WooCommerce Multiple Shipping stores its selections separately.
		if ( function_exists( 'wcms_session_isset' ) && wcms_session_isset( 'shipping_methods' ) ) {
			$chosen_methods = array();

			foreach ( wcms_session_get( 'shipping_methods' ) as $package_key => $method ) {
				$chosen_methods[ $package_key ] = $method['id'];
			}
		}

		if ( isset( $chosen_methods[ $key ], $package['rates'][ $chosen_methods[ $key ] ] ) ) {
			return $package['rates'][ $chosen_methods[ $key ] ];
		}

		return null;
	}

	/**
	 * Is one of the given shipping methods a local pickup method?
	 *
	 * @param array $method_ids IDs of shipping methods to check.
	 *
	 * @return bool
	 * @since 5.0
	 */
	public static function is_local_pickup( $method_ids ) {
		if ( ! apply_filters( 'woocommerce_apply_base_tax_for_local_pickup', true ) ) {
			return false;
		}

		$local_pickup_methods = apply_filters(
			'woocommerce_local_pickup_methods',
			array( 'legacy_local_pickup', 'local_pickup' )
		);

		return count( array_intersect( $method_ids, $local_pickup_methods ) ) > 0;
	}

	/**
	 * Is the provided shipping method a local delivery method?
	 *
	 * @param string $method_id Method ID (default '').
	 *
	 * @return bool
	 * @since 5.0
	 */
	public static function is_local_delivery( $method_id = '' ) {
		return in_array(
			$method_id,
			apply_filters(
				'wootax_local_delivery_methods',
				array( 'local_delivery', 'legacy_local_delivery' )
			),
			true
		);
	}

}
