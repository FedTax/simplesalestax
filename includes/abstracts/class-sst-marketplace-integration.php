<?php
/**
 * Marketplace Integration.
 *
 * Provides some common behaviors for SST marketplace integrations.
 *
 * @package simple-sales-tax
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SST_Marketplace_Integration
 */
abstract class SST_Marketplace_Integration {

    /**
     * Flag to control whether package filters should be added.
     *
     * @var bool
     */
    protected $should_add_package_filters = true;

    /**
     * Constructor.
     */
    public function __construct() {
        // Replace the default sales tax meta box with one optimized for marketplaces.
        remove_action( 'sst_output_tax_meta_box', 'sst_render_tax_meta_box' );
        add_action( 'sst_output_tax_meta_box', array( $this, 'render_tax_meta_box' ) );

        // Prevent SST from sending parent orders with sub orders to TaxCloud.
        add_filter( 'sst_should_capture_order', array( $this, 'prevent_parent_order_processing' ), 10, 2 );
        add_filter( 'sst_should_refund_order', array( $this, 'prevent_parent_order_processing' ), 10, 2 );

        if ( $this->should_add_package_filters ) {
            add_filter( 'wootax_cart_packages_before_split', array( $this, 'filter_wootax_cart_packages' ), 10, 2 );
            add_filter( 'wootax_order_packages_before_split', array( $this, 'filter_wootax_order_packages' ), 10, 2 );
        }
    }

    /**
     * Saves the TIC for a product or variation.
     *
     * @param int $product_id Product ID.
     */
    public function save_tic( $product_id ) {
        $tic = null;

        // phpcs:disable
        if ( isset( $_REQUEST['wootax_tic'][0] ) ) { // New product
            $tic = $_REQUEST['wootax_tic'][0];
        } elseif ( isset( $_REQUEST['wootax_tic'][ $product_id ] ) ) {
            $tic = $_REQUEST['wootax_tic'][ $product_id ];
        }
        // phpcs:enable

        if ( ! is_null( $tic ) ) {
            update_post_meta( $product_id, 'wootax_tic', sanitize_text_field( $tic ) );
        }
    }

    /**
     * Filters the cart packages so that we execute one tax lookup per seller.
     *
     * @param array   $packages SST cart packages.
     * @param WC_Cart $cart     Cart instance.
     *
     * @return array
     */
    public function filter_wootax_cart_packages( $packages, $cart ) {
        $packages          = array();
        $virtual_by_seller = array();
        $virtual_keys      = array();
        $shipping_packages = WC()->shipping->get_packages();

        foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
            if ( ! isset( $cart_item['data'] ) || $cart_item['data']->needs_shipping() ) {
                continue;
            }

            // WooCommerce uses the parent product ID for variations.
            $seller_id = $this->get_seller_id_for_product( $cart_item['product_id'] );
            if ( $seller_id <= 0 || ! $this->is_user_seller( $seller_id ) ) {
                $seller_id = 0;
            }

            $virtual_by_seller[ $seller_id ][ $cart_item_key ] = $cart_item;
            $virtual_keys[ $cart_item_key ]                   = true;
        }

        foreach ( $shipping_packages as $key => $package ) {
            // Reassign virtual lines even if an upstream shipping filter included them.
            $removed_items = array();
            foreach ( $package['contents'] as $item_key => $item ) {
                if ( isset( $virtual_keys[ $item['key'] ?? $item_key ] ) ) {
                    $removed_items[ $item_key ] = $item;
                    unset( $package['contents'][ $item_key ] );
                }
            }

            if ( $removed_items && empty( $package['contents'] ) ) {
                continue;
            }

            $seller_id = $this->get_seller_id_for_cart_package( $package );
            if ( $seller_id && $this->is_user_seller( $seller_id ) ) {
                $package['origin'] = $this->get_seller_address( $seller_id );
            } else {
                $seller_id = 0;
            }

            $virtual_items = $virtual_by_seller[ $seller_id ] ?? array();
            if ( $virtual_items || $removed_items ) {
                $contents_cost = $package['contents_cost'] ?? array_sum( wp_list_pluck( $package['contents'] + $removed_items, 'line_total' ) );
                $package['contents'] += $virtual_items;
                $package['contents_cost'] = $contents_cost
                    + array_sum( wp_list_pluck( $virtual_items, 'line_total' ) )
                    - array_sum( wp_list_pluck( $removed_items, 'line_total' ) );
                unset( $virtual_by_seller[ $seller_id ] );
            }

            $packages[ $key ] = $package;
        }

        foreach ( $virtual_by_seller as $seller_id => $items ) {
            $package = sst_create_virtual_cart_package( $items );
            if ( $seller_id ) {
                $package['origin'] = $this->get_seller_address( $seller_id );
            }

            $packages[] = $package;
        }

        return $packages;
    }

    /**
     * Get the seller ID for a product.
     * @param int $product_id Parent product ID for variations.
     * @return int
     */
    protected function get_seller_id_for_product( $product_id ) {
        return (int) get_post_field( 'post_author', $product_id );
    }

    /**
     * Get the seller ID for a cart package.
     *
     * @param array $package WooCommerce cart package.
     *
     * @return int
     */
    protected function get_seller_id_for_cart_package( $package ) {
        $id_keys = $this->get_seller_id_keys();

        foreach ( $id_keys as $key ) {
            if ( ! empty( $package[ $key ] ) ) {
                return $package[ $key ];
            }
        }

        // Some marketplaces (including WCFM) identify their shipping package
        // by name and omit a seller ID. Infer the seller only when every
        // shippable product in the package belongs to that same seller.
        $seller_id = 0;
        foreach ( $package['contents'] ?? array() as $item ) {
            $product_id = (int) ( $item['product_id'] ?? 0 );
            if ( ! $product_id ) {
                continue;
            }

            $item_seller_id = $this->get_seller_id_for_product( $product_id );
            if ( $item_seller_id <= 0 || ! $this->is_user_seller( $item_seller_id ) ) {
                return 0;
            }

            if ( $seller_id && (int) $seller_id !== (int) $item_seller_id ) {
                return 0;
            }

            $seller_id = $item_seller_id;
        }

        return $seller_id;
    }

    /**
     * Gets a list of keys that are used for the seller ID in cart packages and
     * WooCommerce order item meta.
     *
     * @return array
     */
    protected function get_seller_id_keys() {
        $keys = array(
            'vendor_id',
            '_vendor_id',
            'seller_id',
            '_seller_id',
        );

        return apply_filters( 'sst_marketplace_seller_id_keys', $keys );
    }

    /**
     * Filters the order packages so that we can execute one tax lookup per seller.
     *
     * @param array    $packages SST order packages.
     * @param WC_Order $order    Order instance.
     */
    public function filter_wootax_order_packages( $packages, $order ) {
        $packages           = array();
        $items_by_seller    = array();
        $shipping_by_seller = array();
        $order_items        = sst_format_order_items( $order->get_items() );

        foreach ( $order_items as $item_id => $item ) {
            $seller_id = $this->get_seller_id_for_product( $item['product_id'] );
            if ( ! isset( $items_by_seller[ $seller_id ] ) ) {
                $items_by_seller[ $seller_id ] = array();
            }
            $items_by_seller[ $seller_id ][ $item_id ] = $item;
        }

        // Assumes one shipping method per seller.
        foreach ( $order->get_shipping_methods() as $item_id => $shipping_method ) {
            $seller_id                        = $this->get_seller_id_for_shipping_method( $shipping_method );
            $shipping_by_seller[ $seller_id ] = $shipping_method;
        }

        $seller_ids = array_keys( $shipping_by_seller + $items_by_seller );

        // Create one package per seller.
        foreach ( $seller_ids as $seller_id ) {
            $seller_package = array(
                'user' => array(
                    'ID' => $order->get_user_id(),
                ),
            );

            if ( isset( $items_by_seller[ $seller_id ] ) ) {
                $seller_package['contents'] = $items_by_seller[ $seller_id ];
            }

            if ( isset( $shipping_by_seller[ $seller_id ] ) ) {
                $shipping_method            = $shipping_by_seller[ $seller_id ];
                $seller_package['shipping'] = new WC_Shipping_Rate(
                    $shipping_method->get_id(),
                    '',
                    $shipping_method->get_total(),
                    array(),
                    $shipping_method->get_method_id()
                );
            }

            if ( $this->is_user_seller( $seller_id ) ) {
                $seller_package['origin'] = $this->get_seller_address( $seller_id );
            }

            $packages[] = sst_create_package( $seller_package );
        }

        return $packages;
    }

    /**
     * Get the seller ID for a WooCommerce shipping method.
     *
     * @param WC_Order_Item_Shipping $shipping_method WC shipping method.
     *
     * @return int
     */
    protected function get_seller_id_for_shipping_method( $shipping_method ) {
        $seller_id_keys = $this->get_seller_id_keys();

        foreach ( $seller_id_keys as $key ) {
            $value = $shipping_method->get_meta( $key );

            if ( $value ) {
                return $value;
            }
        }

        return 0;
    }

    /**
     * Renders the Sales Tax meta box for marketplaces.
     *
     * We show the default Sales Tax meta box for the suborders
     * that will be captured in TaxCloud and a warning message
     * for parent orders.
     *
     * @param SST_Order $order The order being edited.
     */
    public function render_tax_meta_box( $order ) {
        $has_sub_orders = $this->order_has_sub_orders( $order->get_id() );

        if ( $has_sub_orders ) {
            echo '<p>';

            esc_html_e(
                'This order has sub-orders and will not be captured in TaxCloud. Please see the sub-orders for tax details.',
                'simple-sales-tax'
            );

            echo '</p>';
        } else {
            sst_render_tax_meta_box( $order );
        }
    }

    /**
     * Prevents parent orders from being captured and refunded in TaxCloud.
     * If an order has sub-orders, only its sub-orders should be captured
     * and refunded in TaxCloud.
     *
     * @param bool     $should_process Should the order be captured/refunded
     *                                 in TaxCloud?
     * @param WC_Order $order          WC order instance.
     *
     * @return bool
     */
    public function prevent_parent_order_processing( $should_process, $order ) {
        if ( $this->order_has_sub_orders( $order ) ) {
            $should_process = false;
        }

        return $should_process;
    }

    /**
     * Checks whether an order has sub-orders.
     *
     * Integrations for marketplace plugins that use suborders
     * should override this.
     *
     * @param int|WC_Order $order Order or order ID.
     *
     * @return bool
     */
    protected function order_has_sub_orders( $order ) {
        return false;
    }

    /**
     * Checks whether a user is a seller.
     *
     * @param int $user_id User ID.
     *
     * @return bool
     */
    abstract public function is_user_seller( $user_id );

    /**
     * Get the origin address for a seller.
     *
     * @param int $seller_id Seller user ID.
     *
     * @return SST_Origin_Address
     */
    abstract public function get_seller_address( $seller_id );

    /**
     * Validates whether the given address has the required fields
     * to be used as an origin address.
     *
     * @param array $address Address components.
     *
     * @return bool
     * @see https://github.com/FedTax/simplesalestax/pull/388
     */
    public function is_valid_origin( $address ) {
        return ! empty( $address['address'] ) &&
               ! empty( $address['city'] ) &&
               ! empty( $address['state'] ) &&
               ! empty( $address['postcode'] );
    }

}
