<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Composite Products integration for Simple Sales Tax.
 *
 * @author Taxcloud <bporcelli@taxcloud.com>
 */
class SST_Composite_Products {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'wootax_product_price', array( $this, 'filter_composite_product_price' ), 10, 2 );
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
	public function filter_composite_product_price( $price, $product ) {
		if ( is_a( $product, 'WC_Product_Composite' ) ) {
			$price = 0.0;
		}

		return $price;
	}

}

new SST_Composite_Products();
