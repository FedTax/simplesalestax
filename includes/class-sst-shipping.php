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
