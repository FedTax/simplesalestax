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
		add_filter( 'wootax_cart_packages_before_split', array( $this, 'normalize_assembled_cart_packages' ), 10, 2 );
	}

	/**
	 * Prevents assembled components from being taxed twice at checkout.
	 *
	 * Composite Products makes components that are shipped inside their parent
	 * virtual, then adds their value to the parent item in WooCommerce's shipping
	 * package. Simple Sales Tax normally creates a second package for virtual
	 * products, which causes the component value to be sent to TaxCloud once in
	 * the parent total and once as a separate line item.
	 *
	 * Restore the parent to its unaggregated cart total and move each assembled
	 * component into the same package. This keeps one physical shipment while
	 * preserving the component's own product ID, TIC, price, and tax mapping.
	 *
	 * @param array                   $packages Cart packages before they are split by origin.
	 * @param WC_Cart|SST_Cart_Proxy $cart     WooCommerce cart or SST cart proxy instance.
	 *
	 * @return array
	 */
	public function normalize_assembled_cart_packages( $packages, $cart ) {
		if ( empty( $packages ) || ! is_callable( array( $cart, 'get_cart' ) ) ) {
			return $packages;
		}

		$cart_contents       = $cart->get_cart();
		$parent_package_keys = array();

		/*
		 * Only WooCommerce shipping packages contain contents_cost. SST's virtual
		 * package does not. A parent found here has already had the value of its
		 * assembled components aggregated by Composite Products.
		 */
		foreach ( $packages as $package_key => $package ) {
			if ( ! array_key_exists( 'contents_cost', $package ) || empty( $package['contents'] ) ) {
				continue;
			}

			foreach ( $package['contents'] as $cart_item_key => $item ) {
				if ( ! empty( $item['composite_children'] ) ) {
					$parent_package_keys[ $cart_item_key ] = $package_key;
				}
			}
		}

		if ( empty( $parent_package_keys ) ) {
			return $packages;
		}

		foreach ( $cart_contents as $cart_item_key => $item ) {
			$parent_key = isset( $item['composite_parent'] ) ? $item['composite_parent'] : '';

			if (
				empty( $parent_key )
				|| ! isset( $parent_package_keys[ $parent_key ] )
				|| ! $this->is_component_assembled( $item, $cart_contents[ $parent_key ] )
			) {
				continue;
			}

			$target_package_key = $parent_package_keys[ $parent_key ];

			/* Remove the component from SST's virtual package (if present). */
			foreach ( $packages as $package_key => $package ) {
				if ( $package_key !== $target_package_key && isset( $package['contents'][ $cart_item_key ] ) ) {
					unset( $packages[ $package_key ]['contents'][ $cart_item_key ] );
				}
			}

			/*
			 * Replace the shipping representation of the parent with the original
			 * cart item, then include the component as its own taxable line.
			 */
			$packages[ $target_package_key ]['contents'][ $parent_key ]    = $cart_contents[ $parent_key ];
			$packages[ $target_package_key ]['contents'][ $cart_item_key ] = $item;
		}

		foreach ( $packages as $package_key => $package ) {
			if ( empty( $package['contents'] ) ) {
				unset( $packages[ $package_key ] );
				continue;
			}

			if ( array_key_exists( 'contents_cost', $package ) ) {
				$packages[ $package_key ]['contents_cost'] = array_sum( wp_list_pluck( $package['contents'], 'line_total' ) );
			}
		}

		return $packages;
	}

	/**
	 * Determines whether a component is physically assembled in its parent.
	 *
	 * @param array $item        Component cart item.
	 * @param array $parent_item Composite parent cart item.
	 *
	 * @return bool
	 */
	private function is_component_assembled( $item, $parent_item ) {
		if (
			empty( $item['composite_item'] )
			|| empty( $item['product_id'] )
			|| empty( $parent_item['data'] )
			|| ! is_a( $parent_item['data'], 'WC_Product_Composite' )
		) {
			return false;
		}

		$product_id = ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'];
		$product    = wc_get_product( $product_id );

		/* Genuinely virtual components are not aggregated into the parent. */
		if ( ! $product || ! $product->needs_shipping() ) {
			return false;
		}

		$component = $parent_item['data']->get_component_option( $item['composite_item'], $item['product_id'] );

		return $component && ! $component->is_shipped_individually( $product );
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

		/* 
		 * Cart Context: If this evaluates to 0, it means we are in the Cart. 
		 * WooCommerce natively sets line_total to 0 for aggregate components in the cart, 
		 * so we can safely return the already calculated $price without further checks.
		 */
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
			
			static $loaded_composites = array();
			if ( $composite_id && ! isset( $loaded_composites[ $composite_id ] ) ) {
				$loaded_composites[ $composite_id ] = wc_get_product( $composite_id );
			}
			$composite = $composite_id ? $loaded_composites[ $composite_id ] : null;

			if ( ! is_a( $composite, 'WC_Product_Composite' ) ) {
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
