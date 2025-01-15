<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

use \Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use \TaxCloud\ExemptionCertificate;
use \TaxCloud\ExemptionCertificateBase;

/**
 * Checkout.
 *
 * Responsible for computing the sales tax due during checkout.
 *
 * @author  Simple Sales Tax
 * @package SST
 * @since   5.0
 */
class SST_Checkout extends SST_Abstract_Cart {

    /**
     * The cart we are calculating taxes for.
     *
     * @var WC_Cart
     */
    private $cart = null;

    /**
     * Cart validation errors.
     *
     * @var array
     */
    private $errors = array();

    /**
     * Constructor: Initialize hooks.
     *
     * @since 5.0
     */
    public function __construct() {
        add_filter( 'woocommerce_calculated_total', array( $this, 'calculate_tax_totals' ), 1100, 2 );
        add_filter( 'woocommerce_cart_hide_zero_taxes', array( $this, 'hide_zero_taxes' ) );
        add_action( 'woocommerce_checkout_order_created', array( $this, 'handle_legacy_checkout' ) );
        add_action( 'woocommerce_cart_emptied', array( $this, 'clear_session_data' ) );
        add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_legacy_checkout' ), 10, 2 );
        add_filter( 'woocommerce_add_cart_item', array( $this, 'set_key_for_cart_item' ), 10, 2 );
        add_filter( 'wootax_cart_packages', array( $this, 'handle_negative_fees' ), PHP_INT_MAX - 1 );
        add_action( 'woocommerce_init', array( $this, 'init_certificate_id' ) );
        add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'handle_checkout' ), 10, 2 );

        if ( sst_storefront_active() ) {
            add_action( 'woocommerce_checkout_shipping', array( $this, 'output_exemption_form' ), 15 );
        } else {
            add_action( 'woocommerce_checkout_after_customer_details', array( $this, 'output_exemption_form' ) );
        }

        add_action( 'woocommerce_checkout_create_order_shipping_item', array( $this, 'add_shipping_meta' ), 10, 3 );

        parent::__construct();
    }

    /**
     * Perform a tax lookup and update the sales tax for all items.
     *
     * IMPORTANT: This hook needs to run after WC_Subscriptions_Cart::calculate_subscription_totals().
     *
     * @param float   $total Calculated total for cart.
     * @param WC_Cart $cart  Cart object.
     *
     * @return float
     * @since 5.0
     */
    public function calculate_tax_totals( $total, $cart ) {
        $tax_total = 0;

        $this->cart = new SST_Cart_Proxy( $cart );

        $should_calculate = (
            is_cart() ||
            is_checkout() ||
            doing_action( 'wc_ajax_square_digital_wallet_recalculate_totals' ) ||
            $this->is_store_api_request() ||
            $this->is_edit_subscription_request()
        );

        if ( apply_filters( 'sst_calculate_tax_totals', $should_calculate ) ) {
            $this->calculate_taxes();

            /**
             * Woo won't include the taxes calculated by SST in the total so
             * we add them in here.
             */
            foreach ( $this->cart->get_taxes() as $rate_id => $tax ) {
                if ( (int) SST_RATE_ID === $rate_id ) {
                    $tax_total += $tax;
                }
            }
        }

        $this->cart->set_total_tax( $tax_total );

        return $total + $tax_total;
    }

    /**
     * Check if is request to the Store API.
     *
     * @return bool
     */
    protected function is_store_api_request() {
        global $wp;
        $rest_route = $wp->query_vars['rest_route'] ?? '';
        return 0 === strpos( $rest_route, '/wc/store/' );
    }
    /**
     * Check come from edit-subscription
     * @return bool
     */
    protected function is_edit_subscription_request() {
        global $wp;
        $subscriptionId = $wp->query_vars['edit-subscription'] ?? '';
        if(!$subscriptionId){
            $subscriptionId = $wp->query_vars['view-subscription'] ?? '';
        }
        return $subscriptionId > 0;
    }
    /**
     * Calculates the tax due for the cart.
     */
    public function calculate_taxes() {
        $this->errors = array();

        if ( 'new' === $this->get_certificate_id() ) {
            // Assume 0 tax until checkout is processed and new certificate is saved
            $this->reset_taxes();
            $this->update_taxes();

            return true;
        }

        return parent::calculate_taxes();
    }

    /**
     * Should the Sales Tax line item be hidden if no tax is due?
     *
     * @return bool
     * @since 5.0
     */
    public function hide_zero_taxes() {
        return 'true' !== SST_Settings::get( 'show_zero_tax' );
    }

    /**
     * Get saved packages for this cart.
     *
     * @return array
     * @since 5.0
     */
    protected function get_packages() {
        return WC()->session->get( 'sst_packages', array() );
    }

    /**
     * Set saved packages for this cart.
     *
     * @param array $packages Packages to save for cart (default: array()).
     *
     * @since 5.0
     */
    protected function set_packages( $packages = array() ) {
        WC()->session->set( 'sst_packages', $packages );
    }

    /**
     * Filter items not needing shipping callback.
     *
     * @param array $item WooCommerce cart item.
     *
     * @return bool
     * @since 5.0
     */
    protected function filter_items_not_needing_shipping( $item ) {
        return $item['data'] && ! $item['data']->needs_shipping();
    }

    /**
     * Get only items that don't need shipping.
     *
     * @return array
     * @since 5.0
     */
    protected function get_items_not_needing_shipping() {
        return array_filter( $this->cart->get_cart(), array( $this, 'filter_items_not_needing_shipping' ) );
    }

    /**
     * Get the shipping rate for a package.
     *
     * @param int   $key     Package key.
     * @param array $package Package.
     *
     * @return WC_Shipping_Rate | NULL
     * @since 5.0
     */
    protected function get_package_shipping_rate( $key, $package ) {
        $chosen_methods = WC()->session->get( 'chosen_shipping_methods' );

        /* WC Multiple Shipping doesn't use chosen_shipping_methods -_- */
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
     * Get the base shipping packages for this cart.
     *
     * @return array
     * @since 5.5
     */
    protected function get_base_packages() {
        $packages = WC()->shipping->get_packages();

        /*
         * After WooCommerce 3.0, items that do not need shipping are excluded
         * from shipping packages. To ensure that these products are taxed, we
         * create a special package for them.
         */
        $virtual_package = $this->create_virtual_package();

        if ( $virtual_package ) {
            $packages[] = $virtual_package;
        }

        return $packages;
    }

    /**
     * Creates a virtual shipping package for all items that don't need shipping.
     *
     * @return array|false Package or false if all cart items need shipping.
     */
    protected function create_virtual_package() {
        $digital_items = $this->get_items_not_needing_shipping();

        if ( ! empty( $digital_items ) ) {
            return sst_create_package(
                array(
                    'contents'    => $digital_items,
                    'destination' => array(
                        'country'   => WC()->customer->get_billing_country(),
                        'address'   => WC()->customer->get_billing_address(),
                        'address_2' => WC()->customer->get_billing_address_2(),
                        'city'      => WC()->customer->get_billing_city(),
                        'state'     => WC()->customer->get_billing_state(),
                        'postcode'  => WC()->customer->get_billing_postcode(),
                    ),
                    'user'        => array(
                        'ID' => get_current_user_id(),
                    ),
                )
            );
        }

        return false;
    }

    /**
     * Create shipping packages for this cart.
     *
     * @return array
     * @since 5.0
     */
    protected function create_packages() {
        $packages = array();

        // Let devs change the packages before we split them.
        $raw_packages = apply_filters(
            'wootax_cart_packages_before_split',
            $this->get_base_packages(),
            $this->cart
        );

        // Set shipping method for each package and change destination address
        // if method is Local Pickup.
        foreach ( $raw_packages as $key => $package ) {
            $method = $this->get_package_shipping_rate( $key, $package );

            if ( is_null( $method ) ) {
                continue;
            }

            $method     = clone $method;    /* IMPORTANT: preserve original */
            $method->id = $key;

            $raw_packages[ $key ]['shipping'] = $method;

            if ( SST_Shipping::is_local_pickup( array( $method->method_id ) ) ) {
                $pickup_address = apply_filters( 'wootax_pickup_address', SST_Addresses::get_default_address(), null );

                $raw_packages[ $key ]['destination'] = array(
                    'country'   => 'US',
                    'address'   => $pickup_address->getAddress1(),
                    'address_2' => $pickup_address->getAddress2(),
                    'city'      => $pickup_address->getCity(),
                    'state'     => $pickup_address->getState(),
                    'postcode'  => $pickup_address->getZip5(),
                );
            }
        }

        // Filter out packages with invalid destinations.
        $raw_packages = array_filter( $raw_packages, array( $this, 'is_package_destination_valid' ) );

        // Split packages by origin address.
        foreach ( $raw_packages as $raw_package ) {
            $packages = array_merge( $packages, $this->split_package( $raw_package ) );
        }

        // Add fees to first package.
        if ( ! empty( $packages ) && apply_filters( 'wootax_add_fees', true ) ) {
            $packages[ key( $packages ) ]['fees'] = $this->cart->get_fees();
        }

        return apply_filters( 'wootax_cart_packages', $packages, $this->cart );
    }

    /**
     * Reset sales tax totals.
     *
     * @since 5.0
     */
    protected function reset_taxes() {
        foreach ( $this->cart->get_cart() as $cart_key => $item ) {
            $this->set_product_tax( $cart_key, 0 );
        }

        foreach ( $this->cart->get_fees() as $key => $fee ) {
            $this->set_fee_tax( $key, 0 );
        }

        $this->cart->reset_shipping_taxes();

        $this->update_taxes();
    }

    /**
     * Update sales tax totals.
     *
     * @since 5.0
     */
    protected function update_taxes() {
        $cart_tax_total = 0;

        foreach ( $this->cart->get_cart() as $item ) {
            $tax_data = $item['line_tax_data'];

            if ( isset( $tax_data['total'], $tax_data['total'][ SST_RATE_ID ] ) ) {
                $cart_tax_total += $tax_data['total'][ SST_RATE_ID ];
            }
        }

        foreach ( $this->cart->get_fees() as $fee ) {
            if ( isset( $fee->tax_data[ SST_RATE_ID ] ) ) {
                $cart_tax_total += $fee->tax_data[ SST_RATE_ID ];
            }
        }

        $shipping_tax_total = WC_Tax::get_tax_total( $this->cart->sst_shipping_taxes );

        $this->cart->set_tax_amount( SST_RATE_ID, $cart_tax_total );
        $this->cart->set_shipping_tax_amount( SST_RATE_ID, $shipping_tax_total );

        $this->cart->update_tax_totals();
    }

    /**
     * Set the tax for a product.
     *
     * @param mixed $id  Product ID.
     * @param float $tax Sales tax for product.
     *
     * @since 5.0
     */
    protected function set_product_tax( $id, $tax ) {
        $this->cart->set_cart_item_tax( $id, $tax );
    }

    /**
     * Set the tax for a shipping package.
     *
     * @param mixed $id  Package key.
     * @param float $tax Sales tax for package.
     *
     * @since 5.0
     */
    protected function set_shipping_tax( $id, $tax ) {
        $this->cart->set_package_tax( $id, $tax );
    }

    /**
     * Set the tax for a fee.
     *
     * @param mixed $id  Fee ID.
     * @param float $tax Sales tax for fee.
     *
     * @since 5.0
     */
    protected function set_fee_tax( $id, $tax ) {
        $this->cart->set_fee_item_tax( $id, $tax );
    }

    /**
     * Get the POST data from the checkout page.
     *
     * @return array
     */
    protected function get_post_data() {
        if ( ! isset( $_POST['post_data'] ) ) { // phpcs:ignore WordPress.CSRF.NonceVerification
            $post_data = $_POST; // phpcs:ignore WordPress.CSRF.NonceVerification
        } else {
            $post_data = array();
            parse_str( $_POST['post_data'], $post_data ); // phpcs:ignore WordPress.CSRF.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        }

        return $post_data;
    }

    /**
     * Get the exemption certificate for the customer.
     *
     * @return ExemptionCertificateBase
     */
    public function get_certificate() {
        $certificate_id = $this->get_certificate_id();

        if ( $certificate_id ) {
            // Apply saved entity-based cert
            return new ExemptionCertificateBase( $certificate_id );
        }

        return null;
    }

    /**
     * Get the ID of the exemption certificate to apply by default
     * for the current user.
     *
     * @return string
     */
    protected function get_default_certificate_id() {
        if ( ! sst_is_user_tax_exempt() ) {
            // For users who don't have an exempt user role, nothing is selected by default
            return '';
        }

        // For users with an exempt role, select the first available certificate
        $certificates   = SST_Certificates::get_certificates_formatted();
        $certificate_id = count( $certificates ) > 0
            ? current( array_keys( $certificates ) )
            : '';

        return $certificate_id;
    }

    /**
     * Initialize certificate ID in session.
     */
    public function init_certificate_id() {
        if ( ! WC()->session ) {
            return;
        }

        $post_data = $this->get_post_data();

        if ( isset( $post_data['certificate_id'] ) ) {
            $certificate_id = sanitize_text_field(
                wp_unslash( $post_data['certificate_id'] )
            );
            WC()->session->set( 'sst_certificate_id', $certificate_id );
        } else if ( is_null( WC()->session->sst_certificate_id ) ) {
            WC()->session->set(
                'sst_certificate_id',
                $this->get_default_certificate_id()
            );
        }
    }

    /**
     * Get the ID of the applied exemption certificate.
     *
     * @return string Exemption certificate ID.
     *
     * @since 7.0.0
     */
    public function get_certificate_id() {
        return WC()->session->get( 'sst_certificate_id', '' );
    }

    /**
     * Display an error message to the user.
     *
     * @param string $message Message describing the error.
     *
     * @since 5.0
     */
    protected function handle_error( $message ) {
        $action = $this->get_error_action( $message );

        switch ( $action ) {
            case 'show':
                if ( ! wc_has_notice( $message ) ) {
                    wc_add_notice( $message, 'error' );
                }
                break;
            case 'defer':
                $this->errors[] = $message;
                break;
        }

        SST_Logger::add( $message );
    }

    /**
     * Determines whether the given error message should be shown immediately,
     * suppressed, or deferred until checkout is complete.
     *
     * @param string $error The error message.
     *
     * @return string What do to with the error message - valid return values
     *                are 'show', 'suppress', and 'defer'.
     */
    protected function get_error_action( $error ) {
        $error_actions = array(
            'API Login ID not set.' => 'suppress',
            'API Key not set.'      => 'suppress',
            'The Ship To zip code'  => 'defer',
        );

        foreach ( $error_actions as $error_message => $action ) {
            if ( false !== strpos( $error, $error_message ) ) {
                return $action;
            }
        }

        return 'show';
    }

    /**
     * Create a new exemption certificate based on checkout data and
     * associate it with the newly created order.
     *
     * If an exemption certificate was already created during a previous
     * failed checkout attempt, it will be deleted and recreated.
     *
     * @param array     $data  Checkout data
     * @param SST_Order $order New order object
     */
    protected function create_exemption_certificate( $data, $order ) {
        $certificate = $data['certificate'] ?? [];

        if ( ! $certificate ) {
            return;
        }

        if ( $order->get_certificate_id() ) {
            try {
                SST_Certificates::delete_certificate(
                    $order->get_certificate_id()
                );

                $order->set_certificate_id( '' );
            } catch ( Throwable $ex ) {
                SST_Logger::add(
                    "Failed to delete certificate during checkout: {$ex->getMessage()}"
                );
            }
        }

        $certificate = array_map(
            'sanitize_text_field',
            wp_unslash( $certificate )
        );

        $purchaser = array(
            'first_name' => $order->get_billing_first_name(),
            'last_name'  => $order->get_billing_last_name(),
            'address_1'  => $order->get_billing_address_1(),
            'address_2'  => $order->get_billing_address_2(),
            'city'       => $order->get_billing_city(),
            'state'      => $order->get_billing_state(),
            'postcode'   => $order->get_billing_postcode(),
        );

        try {
            // Build single purchase cert or save entity-based cert
            if ( empty( $certificate['SinglePurchase'] ) ) {
                $certificate_id = SST_Certificates::add_certificate(
                    $certificate,
                    $purchaser
                );

                $order->set_certificate_id( $certificate_id );
            } else {
                $certificate = SST_Certificates::build_certificate(
                    array_merge(
                        $certificate,
                        array(
                            'SinglePurchase'             => true,
                            'SinglePurchaserOrderNumber' => $order->get_id(),
                        )
                    ),
                    $purchaser
                );

                $order->set_single_purchase_certificate( $certificate );
            }

            // Do a tax lookup
            $order->calculate_taxes();
            $order->calculate_totals( false );
        } catch ( Throwable $ex ) {
            SST_Logger::add(
                sprintf(
                /* translators: 1 - error message */
                    __(
                        'Failed to add exemption certificate during checkout. Error was: %1$s',
                        'simple-sales-tax'
                    ),
                    $ex->getMessage()
                )
            );

            throw new Exception( $ex->getMessage() );
        }
    }

    /**
     * Process checkout and save order meta.
     *
     * @param array    $data     Checkout data
     * @param WC_Order $wc_order Order object
     */
    protected function process_checkout( $data, $wc_order ) {
        $certificate_id = $data['certificate_id'] ?? '';
        $order          = new SST_Order( $wc_order );

        if ( 'new' === $certificate_id ) {
            $this->create_exemption_certificate( $data, $order );
            return;
        }

        // Ensure we save packages from the 'main' cart
        $this->cart = new SST_Cart_Proxy( WC()->cart );

        $order->set_certificate_id( $certificate_id );
        $order->set_packages( $this->get_packages() );

        // Fix tax totals for orders created via store API
        if ( 'store-api' === $order->get_created_via() ) {
            $order->remove_order_items( 'line_item' );
            $order->remove_order_items( 'shipping' );
            $order->remove_order_items( 'fee' );

            WC()->checkout->create_order_line_items( $order, WC()->cart );
            WC()->checkout->create_order_shipping_lines(
                $order,
                WC()->session->get( 'chosen_shipping_methods' ),
                WC()->shipping()->get_packages()
            );
            WC()->checkout->create_order_fee_lines( $order, WC()->cart );

            $order->update_taxes();
            $order->calculate_totals( false );
        }
    }

    /**
     * Handles processing of the legacy shortcode based checkout.
     *
     * @param WC_Order $order New order object
     */
    public function handle_legacy_checkout( $order ) {
        $this->process_checkout( $this->get_post_data(), $order );
    }

    /**
     * Given a package key, return the shipping tax for the package.
     *
     * @param string $package_key Package key.
     *
     * @return float -1 if no shipping tax, otherwise shipping tax.
     * @since 5.0
     */
    protected function get_package_shipping_tax( $package_key ) {
        $cart          = WC()->cart;
        $package_index = $package_key;
        $cart_key      = '';

        if ( sst_subs_active() ) {
            $last_underscore_i = strrpos( $package_key, '_' );

            if ( false !== $last_underscore_i ) {
                $cart_key      = substr( $package_key, 0, $last_underscore_i );
                $package_index = substr( $package_key, $last_underscore_i + 1 );
            }
        }

        if ( ! empty( $cart_key ) ) {
            $cart = WC()->cart->recurring_carts[ $cart_key ];
        }

        if ( isset( $cart->sst_shipping_taxes[ $package_index ] ) ) {
            return $cart->sst_shipping_taxes[ $package_index ];
        } else {
            return -1;
        }
    }

    /**
     * Add shipping meta for newly created shipping items.
     *
     * @param WC_Order_Item_Shipping $item        The shipping item that was just created.
     * @param int                    $package_key Cart shipping package key.
     * @param array                  $package     Cart shipping package.
     *
     * @throws WC_Data_Exception If the tax data passed to WooCommerce is invalid.
     * @since 5.0
     */
    public function add_shipping_meta( $item, $package_key, $package ) {
        $shipping_tax = $this->get_package_shipping_tax( $package_key );

        if ( $shipping_tax >= 0 ) {
            $taxes                         = $item->get_taxes();
            $taxes['total'][ SST_RATE_ID ] = $shipping_tax;
            $item->set_taxes( $taxes );
        }
    }

    /**
     * Output Tax Details section of checkout form.
     *
     * @since 5.0
     */
    public function output_exemption_form() {
        if ( ! sst_should_show_tax_exemption_form() ) {
            return;
        }

        $certificates = SST_Certificates::get_certificates_formatted();
        $options      = array(
            ''    => 'None',
            'new' => 'Add new certificate',
        );

        foreach ( $certificates as $cert ) {
            $options[ $cert['CertificateID'] ] = $cert['Description'];
        }

        $selected = $this->get_certificate_id();

        wp_enqueue_script( 'sst-checkout' );
        wp_enqueue_style( 'sst-checkout-css' );
        wp_enqueue_style( 'sst-certificate-form-css' );

        wc_get_template(
            'html-checkout.php',
            array(
                'options'  => $options,
                'selected' => $selected,
            ),
            'sst/',
            SST()->path( 'includes/frontend/views/' )
        );
    }

    /**
     * Clear SST session data when the cart is emptied.
     *
     * @since 5.7
     */
    public function clear_session_data() {
        WC()->session->set( 'sst_packages', array() );
        WC()->session->set( 'sst_package_cache', array() );

        unset( WC()->session->sst_certificate_id );
    }

    /**
     * Validate checkout.
     *
     * @param array    $data   POST data.
     * @param WP_Error $errors Checkout errors.
     */
    protected function validate_checkout( $data, $errors ) {
        foreach ( $this->errors as $error_message ) {
            $errors->add( 'tax', $error_message );
        }

        $this->validate_exemption_certificate( $data, $errors );
    }

    /**
     * Validate legacy checkout on {@see 'woocommerce_after_checkout_validation'}
     *
     * @param array    $data   POST data.
     * @param WP_Error $errors Checkout errors.
     */
    public function validate_legacy_checkout( $data, $errors ) {
        $this->validate_checkout( $this->get_post_data(), $errors );
    }

    /**
     * Validate exemption certificate checkout fields.
     *
     * @param array    $data   Checkout data.
     * @param WP_Error $errors Checkout errors.
     */
    protected function validate_exemption_certificate( $data, $errors ) {
        $certificate_id = $data['certificate_id'] ?? '';
        $certificate    = $data['certificate'] ?? [];

        if ( 'new' !== $certificate_id ) {
            return;
        }

        // Field key => Condition when field is required
        $required_fields = [
            'ExemptState'                        => [],
            'TaxType'                            => [],
            'IDNumber'                           => [],
            'StateOfIssue'                       => [
                'field' => 'TaxType',
                'value' => 'StateIssued',
            ],
            'PurchaserBusinessType'              => [],
            'PurchaserBusinessTypeOtherValue'    => [
                'field' => 'PurchaserBusinessType',
                'value' => 'Other',
            ],
            'PurchaserExemptionReason'           => [],
            'PurchaserExemptionReasonOtherValue' => [
                'field' => 'PurchaserExemptionReason',
                'value' => 'Other',
            ],
        ];

        foreach ( $required_fields as $field_key => $condition ) {
            $required = true;

            if ( isset( $condition['field'], $condition['value'] ) ) {
                $field_value = $certificate[ $condition['field'] ] ?? '';
                $required    = $field_value === $condition['value'];
            }

            if ( $required && empty( $certificate[ $field_key ] ) ) {
                $errors->add(
                    $field_key . '_required',
                    __( 'Required exemption certificate fields are missing', 'simple-sales-tax' )
                );
                break;
            }
        }
    }

    /**
     * Gets a saved package by its package hash.
     *
     * @param string $hash Package hash.
     *
     * @return array|bool The saved package with the given hash, or false if no such package exists.
     */
    protected function get_saved_package( $hash ) {
        $saved_packages = WC()->session->get( 'sst_package_cache', array() );

        if ( isset( $saved_packages[ $hash ] ) ) {
            return $saved_packages[ $hash ];
        }

        return false;
    }

    /**
     * Saves a package.
     *
     * @param string $hash    Package hash.
     * @param array  $package Package.
     */
    protected function save_package( $hash, $package ) {
        $saved_packages          = WC()->session->get( 'sst_package_cache', array() );
        $saved_packages[ $hash ] = $package;

        WC()->session->set( 'sst_package_cache', $saved_packages );
    }

    /**
     * Ensures that the 'key' property is set for all WooCommerce cart items.
     *
     * @param array  $cart_item_data Data for cart item being added.
     * @param string $cart_item_key  Cart item key.
     *
     * @return array
     */
    public function set_key_for_cart_item( $cart_item_data, $cart_item_key ) {
        if ( ! isset( $cart_item_data['key'] ) ) {
            $cart_item_data['key'] = $cart_item_key;
        }

        return $cart_item_data;
    }

    /**
     * Handle negative fees by spreading them evenly across all cart package
     * items as discounts.
     *
     * This is required to support extensions like Deposits for WooCommerce
     * that apply discounts using negative fees instead of coupons. Without
     * it, tax lookups will fail due to the inclusion of line items with
     * negative prices.
     *
     * @param array $packages Cart packages
     * @return array Cart packages with negative fees converted to discounts
     */
    public function handle_negative_fees( $packages ) {
        $cart_total         = 0;
        $negative_fee_total = 0;

        foreach ( $packages as &$package ) {
            foreach ( $package['contents'] as $item ) {
                $cart_total += $item['line_total'];
            }

            foreach ( $package['fees'] as $key => $fee ) {
                if ( $fee->amount < 0 ) {
                    $negative_fee_total += abs( $fee->amount );
                    unset( $package['fees'][ $key ] );
                }
            }
        }

        if ( 0 === $negative_fee_total ) {
            return $packages;
        }

        foreach ( $packages as &$package ) {
            foreach ( $package['contents'] as &$item ) {
                if ( $negative_fee_total <= 0 || $cart_total <= 0 ) {
                    break 2;
                }

                $item_total = $item['line_total'];
                $discount   = $negative_fee_total * ( $item_total / $cart_total );

                $item['line_total'] -= $discount;

                $negative_fee_total -= $discount;
                $cart_total         -= $item_total;
            }
        }

        return $packages;
    }

    /**
     * Handle processing of block based checkout.
     *
     * @param WC_Order        $order   Order object
     * @param WP_REST_Request $request Request
     */
    public function handle_checkout( $order, $request ) {
        $extension_data = $request->get_param( 'extensions' );
        $data           = $extension_data['simple-sales-tax'];

        $error = new WP_Error();
        $this->validate_checkout( $data, $error );

        if ( $error->has_errors() ) {
            throw new RouteException( 'sst_checkout_invalid_fields', $error->get_error_message(), 400 );
        }

        $this->process_checkout( $data, $order );
    }

}

new SST_Checkout();