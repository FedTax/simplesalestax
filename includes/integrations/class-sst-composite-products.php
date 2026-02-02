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
	 * Sets the taxable price for composite products to zero to avoid overcalculation of tax.
	 *
	 * If this is not done, tax will be calculated for each of the individual products that comprise the composite
	 * product AND for the composite product itself.
	 *
	 * @param float      $price   Taxable price for product.
	 * @param WC_Product $product WooCommerce product instance.
	 *
	 * @return float
	 */
	public function filter_composite_product_price( $price, $product, $item ) {

		/**
		 * Get the order item ID.
		 */
		$order_item_id = isset( $item['key'] ) ? intval( $item['key'] ) : 0;

		/**
		 * Check if this item has a composite parent.
		 */
		$composite_parent = wc_get_order_item_meta( $order_item_id, '_composite_parent', true );

		/**
		 * If this item has a composite parent, set its taxable price to zero.
		 */
		if ( ! empty( $composite_parent ) ) {
			$price = 0.0;
		}

		return $price;
	}

}

new SST_Composite_Products();
