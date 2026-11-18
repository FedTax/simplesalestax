<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Restore YITH's priced parent after its components replace it for shipping. */
class SST_YITH_Product_Bundles {

	public function __construct() {
		add_filter( 'wootax_cart_packages_before_split', array( $this, 'restore_cart_parents' ), 20, 2 );
	}

	public function restore_cart_parents( $packages, $cart ) {
		$cart_items = $cart->get_cart();
		$locations  = array();
		foreach ( $packages as $package_key => $package ) {
			foreach ( $package['contents'] as $key => $item ) {
				$locations[ $key ] = $package_key;
			}
		}

		foreach ( $cart_items as $parent_key => $parent ) {
			if ( isset( $locations[ $parent_key ] ) || empty( $parent['bundled_items'] )
				|| ! $parent['data']->is_type( 'yith_bundle' ) || ! $parent['data']->needs_shipping()
			) {
				continue;
			}

			foreach ( $parent['bundled_items'] as $child_key ) {
				$package_key = $locations[ $child_key ] ?? null;
				if ( null === $package_key || ! array_key_exists( 'contents_cost', $packages[ $package_key ] )
					|| ! $cart_items[ $child_key ]['data']->needs_shipping()
				) {
					continue;
				}

				// Keep the charged parent price and TIC with its physical component's origin.
				$parent['sst_origin_product_id'] = $cart_items[ $child_key ]['product_id'];
				$packages[ $package_key ]['contents'][ $parent_key ] = $parent;
				break;
			}
		}

		return $packages;
	}
}
