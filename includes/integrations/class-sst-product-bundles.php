<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keep the individual tax lines when Product Bundles represents them as one shipment.
 */
class SST_Product_Bundles {

	public function __construct( $extension_active ) {
		add_filter( 'sst_order_package_items', array( $this, 'prepare_order_items' ), 10, 2 );
		add_filter( 'wootax_order_packages_before_split', array( $this, 'normalize_order_packages' ) );

		if ( $extension_active ) {
			add_filter( 'wootax_cart_packages_before_split', array( $this, 'normalize_cart_packages' ), 20, 2 );
		} else {
			add_filter( 'woocommerce_is_purchasable', array( $this, 'prevent_inactive_bundle_purchase' ), 10, 2 );
			add_filter( 'woocommerce_cart_item_is_purchasable', array( $this, 'prevent_orphan_purchase' ), 10, 3 );
			add_filter( 'woocommerce_order_again_cart_item_data', array( $this, 'keep_bundle_link_for_reorder' ), 10, 2 );
		}
	}

	/**
	 * WooCommerce loads unknown bundle products as simple products. Keep them
	 * from being sold without the extension that constructs their cart lines.
	 */
	public function prevent_inactive_bundle_purchase( $purchasable, $product ) {
		return $purchasable && ( ! $product->is_type( 'simple' ) || 'bundle' !== WC_Product_Factory::get_product_type( $product->get_id() ) );
	}

	/**
	 * Remove saved Woo Product Bundles components when the extension is inactive.
	 */
	public function prevent_orphan_purchase( $purchasable, $key, $item ) {
		return $this->is_woo_bundle_component( $item ) ? false : $purchasable;
	}

	/**
	 * Keep the same protection when WooCommerce reconstructs a cart from an order.
	 */
	public function keep_bundle_link_for_reorder( $cart_item, $order_item ) {
		$bundle_data = array(
			'bundled_by'      => $order_item->get_meta( '_bundled_by', true ),
			'bundled_item_id' => $order_item->get_meta( '_bundled_item_id', true ),
			'stamp'           => $order_item->get_meta( '_stamp', true ),
		);

		return $this->is_woo_bundle_component( $bundle_data ) ? array_replace( $cart_item, $bundle_data ) : $cart_item;
	}

	/** Other bundle extensions share the parent and item keys. Woo stores the component configuration in stamp. */
	private function is_woo_bundle_component( $item ) {
		$id    = $item['bundled_item_id'] ?? null;
		$stamp = $item['stamp'] ?? null;

		return ! empty( $item['bundled_by'] ) && ! empty( $id )
			&& ( is_int( $id ) || is_string( $id ) )
			&& is_array( $stamp ) && isset( $stamp[ $id ] ) && is_array( $stamp[ $id ] );
	}

	/**
	 * Restore the parent price and move physically packed children out of the virtual
	 * package. Product Bundles assigns bundled_value to those children even at $0.
	 */
	public function normalize_cart_packages( $packages, $cart ) {
		if ( ! function_exists( 'wc_pb_get_bundled_cart_items' ) || empty( $packages ) ) {
			return $packages;
		}

		$cart_items         = $cart->get_cart();
		$shipping_locations = array();
		$changed            = false;

		foreach ( $packages as $package_key => $package ) {
			if ( array_key_exists( 'contents_cost', $package ) ) {
				foreach ( $package['contents'] as $key => $item ) {
					$shipping_locations[ $key ] = $package_key;
				}
			}
		}

		foreach ( $cart_items as $parent_key => $parent ) {
			$children = wc_pb_get_bundled_cart_items( $parent, $cart_items );
			if ( ! $children ) {
				continue;
			}

			if ( isset( $shipping_locations[ $parent_key ] ) && $parent['data']->needs_shipping() ) {
				$package_key = $shipping_locations[ $parent_key ];
				// Shipping rates have already been calculated from WooCommerce's aggregated package.
				$packages[ $package_key ]['contents'][ $parent_key ] = $parent;
				$changed = true;
				foreach ( $children as $key => $child ) {
					if ( ! $child['data']->needs_shipping() && null !== WC_PB()->product_data->get( $child['data'], 'bundled_value' ) ) {
						$this->place_in_package( $packages, $package_key, $key, $child, $parent );
					}
				}
			} elseif ( ! $parent['data']->needs_shipping() ) {
				// An unassembled bundle has no parcel of its own. A base price still
				// belongs to the shipped sale when any of its components ship.
				foreach ( $children as $key => $child ) {
					if ( isset( $shipping_locations[ $key ] ) && $child['data']->needs_shipping() ) {
						$this->place_in_package( $packages, $shipping_locations[ $key ], $parent_key, $parent, $child );
						$changed = true;
						break;
					}
				}
			}
		}

		return $changed ? $this->remove_empty_packages( $packages ) : $packages;
	}

	/**
	 * Use the shipping status recorded on bundle order lines. The catalog product
	 * for a packed child is physical even though Woo marked its cart copy virtual.
	 */
	public function prepare_order_items( $items, $order_items ) {
		$children_by_key = array();
		foreach ( $order_items as $child_id => $child ) {
			$parent_key = $child->get_meta( '_bundled_by', true );
			if ( $parent_key && $child->get_meta( '_bundled_item_id', true ) ) {
				$children_by_key[ $parent_key ][ $child_id ] = $child;
			}
		}
		if ( ! $children_by_key ) {
			return $items;
		}

		foreach ( $items as $parent_id => $parent ) {
			$parent_key = $order_items[ $parent_id ]->get_meta( '_bundle_cart_key', true );
			$children   = $children_by_key[ $parent_key ] ?? array();
			if ( ! $children && function_exists( 'wc_pb_get_bundled_order_items' ) ) {
				$children = wc_pb_get_bundled_order_items( $order_items[ $parent_id ], $order_items );
			}
			if ( ! $children ) {
				continue;
			}

			// Product Bundles saves this even for a physical parcel with zero weight.
			$parent_ships = $order_items[ $parent_id ]->meta_exists( '_bundle_weight' ) || $parent['data']->needs_shipping();
			if ( $parent_ships ) {
				$items[ $parent_id ]['sst_needs_shipping'] = true;
			}
			foreach ( $children as $child_id => $child ) {
				if ( ! isset( $items[ $child_id ] ) ) {
					continue;
				}

				$child_ships = $child->get_meta( '_bundled_item_needs_shipping', true );
				if ( 'yes' === $child_ships ) {
					$items[ $child_id ]['sst_needs_shipping'] = true;
					if ( ! $parent_ships && ! isset( $items[ $parent_id ]['sst_bundle_shipping_item'] ) ) {
						$items[ $parent_id ]['sst_bundle_shipping_item'] = $child_id;
						$items[ $parent_id ]['sst_needs_shipping'] = true;
					}
				} elseif ( 'no' === $child_ships ) {
					$packed = $parent_ships && $items[ $child_id ]['data']->needs_shipping();
					$items[ $child_id ]['sst_needs_shipping'] = $packed;
					if ( $packed ) {
						$items[ $child_id ]['sst_bundle_shipping_item'] = $parent_id;
					}
				}
			}
		}

		return $items;
	}

	/**
	 * Keep associated order lines together when TaxCloud rebuilds its packages.
	 * Read the package location before moving any items.
	 */
	public function normalize_order_packages( $packages ) {
		$locations = array();
		$items     = array();
		foreach ( $packages as $package_key => $package ) {
			foreach ( $package['contents'] as $key => $item ) {
				$items[ $key ] = $item;
				$locations[ $key ] = $package_key;
			}
		}

		foreach ( $items as $key => $item ) {
			$shipping_key = $item['sst_bundle_shipping_item'] ?? null;
			if ( isset( $locations[ $shipping_key ] ) ) {
				$this->place_in_package( $packages, $locations[ $shipping_key ], $key, $item, $items[ $shipping_key ] );
			}
		}

		return $packages;
	}

	private function place_in_package( &$packages, $target, $key, $item, $shipping_item ) {
		foreach ( $packages as $package_key => $package ) {
			if ( $target !== $package_key ) {
				unset( $packages[ $package_key ]['contents'][ $key ] );
			}
		}

		// Use the parcel's origin, but resolve product ID and TIC on the original line.
		$item['sst_origin_product_id'] = $shipping_item['product_id'];
		$packages[ $target ]['contents'][ $key ] = $item;
	}

	private function remove_empty_packages( $packages ) {
		return array_filter(
			$packages,
			static function ( $package ) {
				return ! empty( $package['contents'] ) || ! empty( $package['rates'] ) || ! empty( $package['shipping'] );
			}
		);
	}
}
