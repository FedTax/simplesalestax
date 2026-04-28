<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Composite Products integration for Simple Sales Tax.
 *
 * @author Brett Porcelli <bporcelli@taxcloud.com>
 */
class SST_Composite_Products {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'wootax_product_price', array( $this, 'filter_composite_product_price' ), 10, 3 );
	}

	/**
	 * Adjusts the taxable price for composite product items to avoid
	 * overcalculation or undercalculation of tax.
	 *
	 * When a composite uses aggregate pricing (priced_individually = no),
	 * the parent carries the full price and children should be $0.
	 *
	 * When a composite uses per-item pricing (priced_individually = yes),
	 * each child carries its own price and the parent is $0 — children
	 * must keep their prices so tax is calculated correctly.
	 *
	 * @param float      $price   Taxable price for product.
	 * @param WC_Product $product WooCommerce product instance.
	 * @param array      $item    Cart/order item data.
	 *
	 * @return float
	 */
	public function filter_composite_product_price( $price, $product, $item ) {

		$order_item_id = isset( $item['key'] ) ? intval( $item['key'] ) : 0;

		if ( ! $order_item_id ) {
			return $price;
		}

		/* Not a composite child, skip. */
		$composite_parent = wc_get_order_item_meta( $order_item_id, '_composite_parent', true );

		if ( empty( $composite_parent ) ) {
			return $price;
		}

		/*
		 * Composites have two pricing modes. With aggregate pricing the parent
		 * holds the total and children are $0. With per-item pricing each child
		 * carries its own price and the parent is $0. We only zero children out
		 * for aggregate pricing — otherwise tax gets lost entirely.
		 */

		/* Check the order item meta that WC Composite Products stores. */
		$priced_individually = wc_get_order_item_meta( $order_item_id, '_component_priced_individually', true );

		if ( 'yes' === $priced_individually ) {
			return $price;
		}

		/* Meta not set — load the composite product and check the component. */
		if ( '' === $priced_individually ) {
			$composite_id = wc_get_order_item_meta( $order_item_id, '_composite_item', true );
			$composite    = $composite_id ? wc_get_product( $composite_id ) : null;

			if ( ! $composite || ! is_callable( array( $composite, 'get_component' ) ) ) {
				return 0.0;
			}

			/* Meta key changed between CP versions. */
			$component_id = wc_get_order_item_meta( $order_item_id, '_composite_cart_key', true );
			if ( ! $component_id ) {
				$component_id = wc_get_order_item_meta( $order_item_id, '_composite_component', true );
			}

			$component = $component_id ? $composite->get_component( $component_id ) : null;

			if ( $component && is_callable( array( $component, 'is_priced_individually' ) ) && $component->is_priced_individually() ) {
				return $price;
			}
		}

		/* Aggregate pricing — parent has the total, zero the child. */
		return 0.0;
	}

}

new SST_Composite_Products();
