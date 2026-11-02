<?php
/**
 * Plugin Name: SST Staging QA Helper
 * Description: Administrator-only fixture preparation, run evidence and exact settings restoration for TaxCloud QA. Idle outside a run.
 * Version: 0.2.5
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class SST_Staging_QA {
    const OPTION = 'sst_staging_qa_run';
    const REGISTRY = 'sst_staging_qa_registry';
    private static $mail_context = null;
    const OPTIONS = array( 'active_plugins', 'woocommerce_wootax_settings', 'woocommerce_calc_taxes', 'woocommerce_prices_include_tax',
        'woocommerce_tax_based_on', 'woocommerce_tax_display_shop', 'woocommerce_tax_display_cart',
        'woocommerce_cart_page_id', 'woocommerce_checkout_page_id', 'woocommerce_myaccount_page_id',
        'woocommerce_enable_guest_checkout', 'woocommerce_enable_checkout_login_reminder',
        'woocommerce_enable_signup_and_login_from_checkout', 'woocommerce_cheque_settings',
        'woocommerce_new_order_settings', 'woocommerce_customer_completed_order_settings', 'woocommerce_customer_refunded_order_settings',
        'woocommerce_currency', 'woocommerce_default_country', 'woocommerce_allowed_countries', 'woocommerce_ship_to_countries' );

    public static function init() {
        add_action( 'admin_menu', function() { add_management_page( 'SST Staging QA', 'SST Staging QA', 'manage_options', 'sst-staging-qa', array( __CLASS__, 'page' ) ); } );
        add_action( 'admin_post_sst_qa_prepare', array( __CLASS__, 'prepare' ) );
        add_action( 'admin_post_sst_qa_restore', array( __CLASS__, 'restore_from_admin' ) );
        add_action( 'admin_post_sst_qa_access', array( __CLASS__, 'download' ) );
        add_action( 'rest_api_init', function() {
            register_rest_route( 'sst-qa/v1', '/control', array( 'methods' => 'POST', 'permission_callback' => function() { return current_user_can( 'manage_options' ); }, 'callback' => array( __CLASS__, 'control' ) ) );
        } );
        add_filter( 'woocommerce_email_headers', function( $headers, $email_id, $object ) {
            self::$mail_context = null;
            if ( is_object( $object ) && method_exists( $object, 'get_meta' ) && self::owns_order( $object ) ) {
                $kinds = array( 'new_order' => 'new-order', 'customer_completed_order' => 'completed', 'customer_refunded_order' => 'refund' );
                self::$mail_context = array( 'notificationType' => $kinds[ $email_id ] ?? 'other', 'orderId' => (int) $object->get_id() );
            }
            return $headers;
        }, 10, 3 );
        add_filter( 'pre_wp_mail', function( $value, $mail ) {
            $context = self::$mail_context; self::$mail_context = null;
            if ( ! self::scoped() && ! $context ) { return $value; }
            self::record( array( 'transport' => 'mail-generation', 'notificationType' => $context['notificationType'] ?? 'other', 'orderId' => $context['orderId'] ?? null, 'suppressed' => true ) );
            return true; // Generation only; no recipient, subject, body, order key or delivery claim is retained.
        }, 10, 2 );
        add_filter( 'sst_package_order_id', array( __CLASS__, 'package_order_id' ), 100, 3 );
        add_action( 'woocommerce_before_order_object_save', array( __CLASS__, 'tag_order' ), 5 );
        add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'tag_order' ), 30 );
        add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'tag_order' ), 30 );
        add_action( 'woocommerce_after_order_object_save', array( __CLASS__, 'bind_order_name' ), 30 );
        add_action( 'http_api_debug', array( __CLASS__, 'observe' ), 10, 5 );
        add_filter( 'taxcloud_api_headers', function( $headers, $endpoint = '' ) {
            if ( self::scoped() ) { self::record( array( 'transport' => 'v1-attempt', 'path' => $endpoint, 'status' => null ) ); }
            return $headers;
        }, 100, 2 );
        add_action( 'woocommerce_cart_calculate_fees', function( $cart ) {
            if ( ! self::scoped() || ! WC()->session ) { return; }
            $fee = self::run()['fixtureFee'] ?? WC()->session->get( 'sst_qa_fee', '' );
            if ( 'positive' === $fee ) { $cart->add_fee( 'QA service fee', 2, true ); }
            if ( 'negative' === $fee ) { $cart->add_fee( 'QA discount fee', -2, false ); }
        } );
    }
    // Captured QA orders keep their remote identity after restoration or a later run.
    // This does not grant control/read access to orders belonging to another run.
    public static function package_order_id( $id, $order, $key ) {
        if ( ! $order ) { return $id; }
        $owner = (string) $order->get_meta( '_sst_qa_run' );
        $stored = (string) $order->get_meta( 'sst_order_id' );
        if ( $owner && $stored === 'sstqa-' . substr( $owner, 0, 8 ) . '-' . $order->get_id() ) {
            return $stored . '_' . $key;
        }
        $run = self::run();
        if ( ! self::owns_order( $order ) || ( $owner && $owner !== $run['id'] ) || $stored ) { return $id; }
        $base = 'sstqa-' . substr( $run['id'], 0, 8 ) . '-' . $order->get_id();
        $order->update_meta_data( '_sst_qa_run', $run['id'] );
        $order->update_meta_data( 'sst_order_id', $base );
        $order->save();
        return $base . '_' . $key;
    }
    private static function run() { return get_option( self::OPTION, array() ); }
    private static function site() { return trailingslashit( home_url() ); }
    private static function registry() {
        $registry = get_option( self::REGISTRY, array( 'site' => self::site(), 'actors' => array(), 'pages' => array() ) );
        if ( $registry['site'] !== self::site() ) { throw new RuntimeException( 'QA registry belongs to another target. Review clone provenance before preparing.' ); }
        return $registry;
    }
    private static function scoped() {
        $r = self::run();
        if ( empty( $r['active'] ) || empty( $r['leaseToken'] ) || ! hash_equals( $r['leaseToken'], (string) ( $_COOKIE['sst_qa_run'] ?? '' ) ) ) { return false; }
        $uid = get_current_user_id();
        if ( ! $uid ) { return true; } // Guest session lease; no WordPress account is created.
        $registry = self::registry();
        foreach ( $registry['actors'] as $actor ) {
            if ( (int) $actor['id'] === $uid && in_array( $uid, $r['userIds'], true ) && get_user_meta( $uid, '_sst_qa_site', true ) === self::site() ) { return true; }
        }
        return false;
    }
    private static function owns_order( $order ) {
        $r = self::run();
        return $order && ! empty( $r['active'] ) && $order->get_meta( '_sst_qa_run' ) === $r['id'];
    }
    public static function tag_order( $order ) {
        if ( ! self::scoped() ) { return; }
        if ( 851 === (int) $order->get_id() ) { throw new RuntimeException( 'Protected staging order 851 cannot be written by a QA lease.' ); }
        $r = self::run(); $owner = $order->get_meta( '_sst_qa_run' );
        if ( $owner && $owner !== $r['id'] ) { throw new RuntimeException( 'Foreign-run order write denied for leased QA request.' ); }
        // Shared customers never grant ownership of their historical orders.
        if ( ! $owner && $order->get_id() && ! in_array( $order->get_status(), array( 'auto-draft', 'checkout-draft' ), true ) ) { return; }
        $token = (string) ( $_COOKIE['sst_qa_order'] ?? '' );
        $bound_token = (string) $order->get_meta( '_sst_qa_reservation' );
        if ( $bound_token && ! hash_equals( $bound_token, $token ) ) { throw new RuntimeException( 'Another logical order reservation cannot rewrite this order.' ); }
        $reservation = $r['names'][ $token ] ?? null;
        if ( ! $reservation ) { return; }
        if ( ! empty( $reservation['orderId'] ) && (int) $reservation['orderId'] !== (int) $order->get_id() ) { throw new RuntimeException( 'QA reservation already belongs to an order; inspect its outcome before retrying.' ); }
        $order->update_meta_data( '_sst_qa_run', $r['id'] );
        $order->update_meta_data( '_sst_qa_name', $reservation['label'] );
        $order->update_meta_data( '_sst_qa_reservation', $token );
        // Names must be entered through the actual checkout/order editor; this hook only records ownership.
    }
    public static function bind_order_name( $order ) {
        $r = self::run(); $token = $order->get_meta( '_sst_qa_reservation' );
        if ( ! self::owns_order( $order ) || ! isset( $r['names'][ $token ] ) ) { return; }
        if ( ! empty( $r['names'][ $token ]['orderId'] ) && (int) $r['names'][ $token ]['orderId'] !== (int) $order->get_id() ) { throw new RuntimeException( 'Duplicate QA order reservation.' ); }
        $r['names'][ $token ]['orderId'] = $order->get_id();
        update_option( self::OPTION, $r, false );
    }
    private static function actor( $name, $role ) {
        $registry = self::registry(); $entry = $registry['actors'][ $name ] ?? null;
        $user = $entry ? get_userdata( $entry['id'] ) : false;
        if ( $user && ( get_user_meta( $user->ID, '_sst_qa_actor', true ) !== $name || get_user_meta( $user->ID, '_sst_qa_site', true ) !== self::site() ) ) { throw new RuntimeException( 'QA actor marker mismatch: ' . $name ); }
        $provenance = 'created';
        if ( ! $entry ) {
            // One-time migration requires the old helper marker AND exact login/email provenance.
            foreach ( get_users( array( 'meta_key' => '_sst_qa_run', 'orderby' => 'ID', 'order' => 'ASC' ) ) as $candidate ) {
                $run = get_user_meta( $candidate->ID, '_sst_qa_run', true );
                if ( preg_match( '/^[a-f0-9-]{36}$/', $run ) && $candidate->user_login === 'sstqa-' . substr( $run, 0, 8 ) . '-' . $name && $candidate->user_email === $candidate->user_login . '@example.test' && $candidate->roles === array( $role ) ) { $user = $candidate; $provenance = 'adopted:' . $run; break; }
            }
        }
        if ( ! $user ) {
            $login = 'sstqa-' . $name;
            if ( username_exists( $login ) || email_exists( $login . '@example.test' ) ) { throw new RuntimeException( 'Untagged QA username/email collision: ' . $name ); }
            $uid = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password( 48, false ), 'user_email' => $login . '@example.test', 'role' => $role ) );
            if ( is_wp_error( $uid ) ) { throw new RuntimeException( 'Cannot create missing QA actor: ' . $name ); }
            $user = get_userdata( $uid );
        }
        $caps = array_keys( array_filter( $user->caps ) );
        if ( $user->roles !== array( $role ) || array_diff( $caps, array( $role ) ) ) { throw new RuntimeException( 'Unexpected QA role/capabilities: ' . $name ); }
        update_user_meta( $user->ID, '_sst_qa_actor', $name ); update_user_meta( $user->ID, '_sst_qa_site', self::site() );
        if ( ! $entry || (int) $entry['id'] !== (int) $user->ID ) {
            $registry['actors'][ $name ] = array( 'id' => (int) $user->ID, 'role' => $role, 'provenance' => $provenance, 'displayName' => 'runner' === $name ? 'TaxCloud QA Runner' : ( 'buyer' === $name ? 'Txc User 1' : 'Txc User 2' ) );
            update_option( self::REGISTRY, $registry, false );
        }
        $registry = self::registry();
        if ( empty( $registry['actors'][ $name ]['baseline'] ) ) {
            $registry['actors'][ $name ]['baseline'] = array( 'first_name' => 'runner' === $name ? 'TaxCloud' : 'Txc', 'last_name' => 'runner' === $name ? 'QA Runner' : ( 'buyer' === $name ? 'User 1' : 'User 2' ), 'company' => 'Synthetic TaxCloud QA', 'country' => 'US', 'address_1' => '323 Washington Ave N', 'address_2' => '', 'city' => 'Minneapolis', 'state' => 'MN', 'postcode' => '55401', 'phone' => '6125550100', 'email' => $user->user_email );
            update_option( self::REGISTRY, $registry, false );
        }
        return $user;
    }
    private static function reset_actors() {
        $r = self::run(); if ( empty( $r['userIds'] ) ) { return; } $registry = self::registry();
        foreach ( $registry['actors'] as $name => $actor ) {
            if ( ! in_array( $actor['id'], $r['userIds'] ?? array(), true ) ) { continue; }
            if ( get_user_meta( $actor['id'], '_sst_qa_site', true ) !== self::site() || get_user_meta( $actor['id'], '_sst_qa_actor', true ) !== $name ) { throw new RuntimeException( 'Cannot reset unvalidated QA actor.' ); }
            $user = get_userdata( $actor['id'] ); $user->set_role( $actor['role'] );
            wp_update_user( array( 'ID' => $actor['id'], 'display_name' => $actor['displayName'], 'first_name' => $actor['baseline']['first_name'], 'last_name' => $actor['baseline']['last_name'] ) );
            foreach ( array( 'billing_', 'shipping_' ) as $prefix ) {
                foreach ( $actor['baseline'] as $key => $value ) { update_user_meta( $user->ID, $prefix . $key, $value ); }
            }
            delete_user_meta( $user->ID, '_woocommerce_persistent_cart_' . get_current_blog_id() );
            $handler = WC()->session;
            if ( ! $handler ) { $handler_class = apply_filters( 'woocommerce_session_handler', 'WC_Session_Handler' ); $handler = new $handler_class(); }
            if ( ! method_exists( $handler, 'delete_session' ) ) { throw new RuntimeException( 'QA session handler cannot reset the leased actor session.' ); }
            $handler->delete_session( $user->ID );
            delete_transient( 'sst_rate_limit_user_' . $user->ID );
        }
    }
    private static function checkout_page( $key, $slug, $content ) {
        $registry = self::registry();
        $page = get_page_by_path( $slug, OBJECT, array( 'page', 'post', 'attachment' ) );
        if ( ! $page && ! empty( $registry['pages'][ $key ]['id'] ) ) {
            $stale = get_post( $registry['pages'][ $key ]['id'] );
            if ( $stale ) { throw new RuntimeException( 'Required QA checkout moved or trashed: ' . $slug ); }
        }
        if ( $page ) {
            $valid = 'checkoutClassic' === $key ? has_shortcode( $page->post_content, 'woocommerce_checkout' ) && ! has_block( 'woocommerce/checkout', $page ) : has_block( 'woocommerce/checkout', $page ) && false !== strpos( $page->post_content, 'woocommerce/checkout-fields-block' );
            if ( 'page' !== $page->post_type || 'publish' !== $page->post_status || $page->post_password || ! $valid ) { throw new RuntimeException( 'Checkout path conflict (type/status/content): ' . $slug ); }
            $id = $page->ID; $provenance = 'reused';
        } else {
            $id = wp_insert_post( array( 'post_title' => 'checkoutClassic' === $key ? 'Classic Checkout' : 'Block Checkout', 'post_name' => $slug, 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => $content, 'meta_input' => array( '_sst_qa_site' => self::site() ) ), true );
            if ( is_wp_error( $id ) ) { throw new RuntimeException( 'Cannot create missing checkout: ' . $slug ); }
            $provenance = 'created';
        }
        $url = get_permalink( $id );
        if ( untrailingslashit( $url ) !== untrailingslashit( self::site() . $slug ) ) { throw new RuntimeException( 'Required checkout permalink mismatch: ' . $slug ); }
        $registry['pages'][ $key ] = array( 'id' => (int) $id, 'url' => $url, 'provenance' => $provenance );
        update_option( self::REGISTRY, $registry, false ); return $id;
    }
    public static function page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $r = self::run();
        echo '<div class="wrap"><h1>SST Staging QA</h1><p>Prepare real test fixtures and an isolated Cypress runner. Original settings are snapshotted before changes. Test data is retained.</p>';
        echo '<p data-sst-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '">Run: ' . esc_html( $r['id'] ?? 'not prepared' ) . ' — ' . ( empty( $r['active'] ) ? 'idle' : 'active' ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sst_qa_prepare">';
        wp_nonce_field( 'sst_qa_prepare' );
        submit_button( empty( $r['active'] ) ? 'Prepare test site' : 'Resume prepared test site' );
        echo '</form>';
        if ( ! empty( $r['active'] ) ) {
            echo '<p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sst_qa_access' ), 'sst_qa_access' ) ) . '">Download private runner access</a></p>';
        }
        if ( ! empty( $r['active'] ) ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sst_qa_restore">';
            wp_nonce_field( 'sst_qa_restore' ); submit_button( 'Restore original settings and end run', 'secondary' ); echo '</form>';
        }
        $private = get_transient( 'sst_qa_access_' . ( $r['id'] ?? '' ) );
        if ( ! empty( $r['active'] ) && $private ) { echo '<details><summary>Show private runner access</summary><label for="sst-qa-private-access">Private runner JSON — do not share in reports</label><textarea id="sst-qa-private-access" readonly rows="6" style="width:100%">' . esc_textarea( wp_json_encode( $private ) ) . '</textarea></details>'; }
        echo '<p>Configuration checks and credential/origin switching are exercised through the actual TaxCloud settings page. The helper never replaces API responses.</p></div>';
    }
    private static function require_admin( $nonce ) {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Administrator access required.', '', array( 'response' => 403 ) ); }
        check_admin_referer( $nonce );
    }
    public static function prepare() {
        self::require_admin( 'sst_qa_prepare' );
        if ( ! class_exists( 'WC_Product_Simple' ) || ! class_exists( 'SST_Settings' ) ) { wp_die( 'Activate WooCommerce and TaxCloud first.' ); }
        $old = self::run();
        if ( ! empty( $old['active'] ) && ! empty( $old['ready'] ) ) { wp_safe_redirect( admin_url( 'tools.php?page=sst-staging-qa' ) ); exit; }
        if ( ! empty( $old['active'] ) ) { wp_die( 'A run is already active. Resume its existing private access, or restore this interrupted setup before preparing again. No fixtures or identities were duplicated.' ); }
        $id = wp_generate_uuid4(); $short = substr( $id, 0, 8 );
        $r = array( 'id' => $id, 'active' => true, 'started' => gmdate( 'c' ), 'snapshot' => array(), 'userIds' => array(), 'products' => array(), 'pages' => array(), 'records' => array(), 'secrets' => array(), 'leaseToken' => wp_generate_password( 48, false ), 'names' => array() );
        foreach ( self::OPTIONS as $key ) {
            $sentinel = '__sst_qa_missing_' . $id;
            $value = get_option( $key, $sentinel );
            $r['snapshot'][ $key ] = array( 'exists' => $value !== $sentinel, 'value' => $value === $sentinel ? null : $value );
        }
        update_option( self::OPTION, $r, false ); // Persist before any mutation so interrupted setup is recoverable.
        $access = array( 'targetUrl' => trailingslashit( home_url() ), 'runId' => $id, 'customers' => array() );
        $access['leaseToken'] = $r['leaseToken'];
        try {
            self::registry();
            foreach ( array( 'runner' => 'administrator', 'buyer' => 'customer', 'exempt' => 'customer' ) as $name => $role ) {
                $user = self::actor( $name, $role ); $uid = (int) $user->ID; $password = wp_generate_password( 36, false );
                WP_Session_Tokens::get_instance( $uid )->destroy_all(); wp_set_password( $password, $uid );
                $r['userIds'][] = $uid;
                $person = array( 'username' => $user->user_login, 'password' => $password, 'id' => $uid );
                if ( 'runner' === $name ) { $access['admin'] = $person; } else { $access['customers'][ $name ] = $person; }
                update_option( self::OPTION, $r, false );
            }
            self::reset_actors();
        } catch ( Throwable $error ) { wp_die( esc_html( $error->getMessage() . ' Restore this interrupted run before retrying.' ) ); }
        foreach ( array( 'virtual' => array( '10', true, 'taxable' ), 'physical' => array( '12.35', false, 'taxable' ), 'nontaxable' => array( '5', true, 'none' ), 'free' => array( '0', true, 'taxable' ), 'firearm' => array( '100', true, 'taxable' ) ) as $name => $spec ) {
            $p = new WC_Product_Simple(); $p->set_name( 'SST QA ' . $short . ' ' . $name ); $p->set_status( 'publish' ); $p->set_regular_price( $spec[0] ); $p->set_virtual( $spec[1] ); $p->set_tax_status( $spec[2] );
            $p->update_meta_data( 'wootax_tic', 'firearm' === $name ? '90505' : '00000' ); $p->update_meta_data( '_sst_qa_run', $id ); $r['products'][ $name ] = $p->save();
            update_option( self::OPTION, $r, false );
        }
        $parent = new WC_Product_Variable(); $parent->set_name( 'SST QA ' . $short . ' variable' ); $parent->set_status( 'publish' ); $parent->update_meta_data( '_sst_qa_run', $id );
        $attribute = new WC_Product_Attribute(); $attribute->set_name( 'Size' ); $attribute->set_options( array( 'Small', 'Large' ) ); $attribute->set_visible( true ); $attribute->set_variation( true ); $parent->set_attributes( array( $attribute ) );
        $r['products']['variable'] = $parent->save();
        foreach ( array( 'Small', 'Large' ) as $size ) {
            $v = new WC_Product_Variation(); $v->set_parent_id( $parent->get_id() ); $v->set_attributes( array( 'size' => $size ) ); $v->set_regular_price( '15.15' ); $v->set_virtual( true ); $v->update_meta_data( 'wootax_tic', '00000' ); $v->update_meta_data( '_sst_qa_run', $id ); $r['products'][ strtolower( $size ) ] = $v->save();
        }
        WC_Product_Variable::sync( $parent->get_id() );
        foreach ( array( 'cartClassic' => '[woocommerce_cart]', 'checkoutClassic' => '[woocommerce_checkout]', 'cartBlock' => '<!-- wp:woocommerce/cart /-->', 'checkoutBlock' => '<!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout alignwide wc-block-checkout is-loading"><!-- wp:woocommerce/checkout-fields-block --><div class="wp-block-woocommerce-checkout-fields-block"><!-- wp:woocommerce/checkout-contact-information-block /--><!-- wp:woocommerce/checkout-shipping-address-block /--><!-- wp:woocommerce/checkout-billing-address-block /--><!-- wp:woocommerce/checkout-shipping-methods-block /--><!-- wp:woocommerce/checkout-payment-block /--><!-- wp:woocommerce/checkout-additional-information-block /--><!-- wp:woocommerce/checkout-order-note-block /--><!-- wp:woocommerce/checkout-terms-block /--><!-- wp:woocommerce/checkout-actions-block /--></div><!-- /wp:woocommerce/checkout-fields-block --><!-- wp:woocommerce/checkout-totals-block --><div class="wp-block-woocommerce-checkout-totals-block"><!-- wp:woocommerce/checkout-order-summary-block /--></div><!-- /wp:woocommerce/checkout-totals-block --></div><!-- /wp:woocommerce/checkout -->', 'myAccount' => '[woocommerce_my_account]' ) as $name => $content ) {
            if ( in_array( $name, array( 'cartBlock', 'checkoutBlock' ), true ) ) {
                $block = 'cartBlock' === $name ? 'woocommerce/cart' : 'woocommerce/checkout';
                foreach ( get_posts( array( 'post_type' => 'page', 'numberposts' => -1 ) ) as $page ) { if ( has_block( $block, $page ) && false === strpos( $page->post_title, 'SST QA' ) ) { $content = $page->post_content; break; } }
            }
            if ( in_array( $name, array( 'checkoutClassic', 'checkoutBlock' ), true ) ) {
                try { $r['pages'][ $name ] = self::checkout_page( $name, 'checkoutClassic' === $name ? 'classic-checkout' : 'block-checkout', $content ); }
                catch ( Throwable $error ) { wp_die( esc_html( $error->getMessage() . ' Restore the interrupted run.' ) ); }
            } else {
            $r['pages'][ $name ] = wp_insert_post( array( 'post_title' => 'SST QA ' . $short . ' ' . $name, 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => $content, 'meta_input' => array( '_sst_qa_run' => $id ) ) );
            }
            update_option( self::OPTION, $r, false );
        }
        $coupon = new WC_Coupon(); $coupon->set_code( 'sstqa-' . $short ); $coupon->set_discount_type( 'percent' ); $coupon->set_amount( 10 ); $coupon->update_meta_data( '_sst_qa_run', $id ); $r['coupon'] = $coupon->get_code(); $r['couponId'] = $coupon->save();
        $r['zoneOrders'] = array();
        foreach ( WC_Shipping_Zones::get_zones() as $existing ) { $r['zoneOrders'][ $existing['zone_id'] ] = $existing['zone_order']; }
        update_option( self::OPTION, $r, false );
        foreach ( $r['zoneOrders'] as $zone_id => $position ) { $existing_zone = new WC_Shipping_Zone( $zone_id ); $existing_zone->set_zone_order( (int) $position + 1 ); $existing_zone->save(); }
        $zone = new WC_Shipping_Zone(); $zone->set_zone_name( 'SST QA ' . $short ); $zone->set_zone_order( 0 ); $zone->add_location( 'US', 'country' ); $r['shippingZone'] = $zone->save();
        foreach ( array( 'flat_rate', 'free_shipping', 'local_pickup' ) as $method ) { $instance = $zone->add_shipping_method( $method ); update_option( 'woocommerce_' . $method . '_' . $instance . '_settings', array( 'enabled' => 'yes', 'title' => 'QA ' . $method, 'cost' => 'flat_rate' === $method ? '5' : '0', 'tax_status' => 'taxable', 'requires' => '' ) ); }
        foreach ( array( 'woocommerce_currency' => 'USD', 'woocommerce_calc_taxes' => 'yes', 'woocommerce_prices_include_tax' => 'no', 'woocommerce_tax_based_on' => 'shipping', 'woocommerce_tax_display_shop' => 'excl', 'woocommerce_tax_display_cart' => 'excl', 'woocommerce_enable_guest_checkout' => 'yes', 'woocommerce_enable_signup_and_login_from_checkout' => 'no', 'woocommerce_enable_checkout_login_reminder' => 'yes', 'woocommerce_default_country' => 'US:MN', 'woocommerce_allowed_countries' => 'all', 'woocommerce_ship_to_countries' => '', 'woocommerce_myaccount_page_id' => $r['pages']['myAccount'] ) as $key => $value ) { update_option( $key, $value ); }
        // Isolate offline TaxCloud workflows from online-gateway scripts; preserve original failures and restore activation state at run end.
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        foreach ( array( 'woocommerce-paypal-payments/woocommerce-paypal-payments.php', 'authorize-net-payments-for-woocommerce/authorize-net-payments-for-woocommerce.php', 'woocommerce-gateway-stripe/woocommerce-gateway-stripe.php', 'woocommerce-payments/woocommerce-payments.php', 'checkoutwc-lite/checkout-for-woocommerce.php' ) as $gateway ) { if ( is_plugin_active( $gateway ) ) { deactivate_plugins( $gateway ); $r['isolatedPlugins'][] = $gateway; } }
        foreach ( array( 'woocommerce_new_order_settings', 'woocommerce_customer_completed_order_settings', 'woocommerce_customer_refunded_order_settings' ) as $email_key ) { $email_settings = get_option( $email_key, array() ); $email_settings['enabled'] = 'yes'; update_option( $email_key, $email_settings ); }
        $cheque = get_option( 'woocommerce_cheque_settings', array() ); $cheque['enabled'] = 'yes'; $cheque['title'] = 'QA offline payment'; update_option( 'woocommerce_cheque_settings', $cheque );
        $s = get_option( 'woocommerce_wootax_settings', array() );
        $s = array_merge( $s, array( 'disable_integration' => 'no', 'capture_orders_in_taxcloud' => 'yes', 'capture_trigger' => 'completed', 'show_exempt' => 'true', 'company_name' => 'TaxCloud QA', 'restrict_exempt' => 'no', 'enable_taxcloud_rate_limit' => 'no', 'log_requests' => 'yes', 'show_zero_tax' => 'true' ) );
        update_option( 'woocommerce_wootax_settings', $s );
        update_option( self::OPTION, $r, false );
        $r['ready'] = true; update_option( self::OPTION, $r, false );
        set_transient( 'sst_qa_access_' . $id, $access, 12 * HOUR_IN_SECONDS );
        self::layout( 'classic' );
        wp_safe_redirect( admin_url( 'tools.php?page=sst-staging-qa' ) ); exit;
    }
    public static function restore_from_admin() {
        self::require_admin( 'sst_qa_restore' );
        $request = new WP_REST_Request( 'POST' ); $request->set_body( wp_json_encode( array( 'action' => 'restore', 'runId' => self::run()['id'] ?? '' ) ) );
        $request->set_header( 'Content-Type', 'application/json' );
        $result = self::control( $request ); if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ) ); }
        wp_safe_redirect( admin_url( 'tools.php?page=sst-staging-qa' ) ); exit;
    }
    public static function download() {
        self::require_admin( 'sst_qa_access' ); $r = self::run(); $access = get_transient( 'sst_qa_access_' . ( $r['id'] ?? '' ) );
        if ( empty( $r['active'] ) || ! $access ) { wp_die( 'Runner access expired. Restore and prepare a new run.' ); }
        nocache_headers(); header( 'Content-Type: application/json' ); header( 'Content-Disposition: attachment; filename="sst-qa-access.json"' );
        echo wp_json_encode( $access ); exit;
    }
    private static function layout( $layout ) {
        $r = self::run(); $suffix = 'blocks' === $layout ? 'Block' : 'Classic';
        update_option( 'woocommerce_cart_page_id', $r['pages'][ 'cart' . $suffix ] ); update_option( 'woocommerce_checkout_page_id', $r['pages'][ 'checkout' . $suffix ] );
    }
    private static function clean( $data ) {
        $r = self::run(); $s = get_option( 'woocommerce_wootax_settings', array() );
        $secrets = array_merge( $r['secrets'] ?? array(), array( $s['tc_id'] ?? '', $s['tc_key'] ?? '', $s['tc_connection_id'] ?? '', $s['tc_integration_id'] ?? '' ) );
        $walk = function( $v ) use ( &$walk ) {
            if ( ! is_array( $v ) ) { return $v; }
            foreach ( $v as $k => &$x ) { $x = preg_match( '/^(apiLoginID|apiKey|access_token|refresh_token|authorization|password|token|tc_key|tc_id)$/i', (string) $k ) ? '[redacted]' : $walk( $x ); }
            return $v;
        };
        $json = wp_json_encode( $walk( $data ) );
        foreach ( $secrets as $secret ) { if ( is_string( $secret ) && strlen( $secret ) > 3 ) { $json = str_replace( $secret, '[redacted]', $json ); } }
        return json_decode( $json, true );
    }
    private static function record( $record ) {
        $r = self::run(); if ( empty( $r['active'] ) ) { return; }
        $s = get_option( 'woocommerce_wootax_settings', array() );
        foreach ( array( 'tc_id', 'tc_key', 'tc_connection_id', 'tc_integration_id' ) as $key ) { if ( ! empty( $s[ $key ] ) ) { $r['secrets'][] = $s[ $key ]; } }
        $r['secrets'] = array_values( array_unique( $r['secrets'] ) );
        update_option( self::OPTION, $r, false );
        $record['time'] = gmdate( 'c' ); $record['apiSetting'] = $s['api_version'] ?? 'v1'; $record['profile'] = empty( $s['data_mover'] ) ? 'realtime' : 'dataImport';
        add_option( 'sst_qa_event_' . $r['id'] . '_' . str_replace( '-', '', wp_generate_uuid4() ), self::clean( $record ), '', false );
    }
    private static function records() {
        global $wpdb; $r = self::run();
        $names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id", $wpdb->esc_like( 'sst_qa_event_' . $r['id'] . '_' ) . '%' ) );
        return array_map( 'get_option', $names );
    }
    public static function observe( $response, $type, $class, $args, $url ) {
        if ( ! self::scoped() || 'response' !== $type ) { return; }
        $host = wp_parse_url( $url, PHP_URL_HOST );
        if ( ! in_array( $host, array( 'api.v3.taxcloud.com', 'api.v3.taxcloud.net', 'taxcloudapi-appservice-core-prod.azurewebsites.net', 'staging-taxcloudapi.azurewebsites.net' ), true ) ) { return; }
        $path = preg_replace( '#/connections/[^/]+#', '/connections/:connection', wp_parse_url( $url, PHP_URL_PATH ) ); $auth = false !== strpos( $path, '/auth/token' );
        self::record( array( 'transport' => 'wordpress-http', 'host' => $host, 'method' => $args['method'] ?? 'GET', 'path' => $path, 'status' => is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response ), 'request' => $auth ? null : json_decode( $args['body'] ?? '', true ), 'response' => $auth || is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true ), 'error' => is_wp_error( $response ) ? $response->get_error_code() : null ) );
    }
    public static function control( $request ) {
        if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'forbidden', 'Administrator access required.', array( 'status' => 403 ) ); }
        $input = $request->get_json_params(); $r = self::run();
        if ( empty( $r['id'] ) || ! hash_equals( $r['id'], (string) ( $input['runId'] ?? '' ) ) ) { return new WP_Error( 'run_mismatch', 'No matching QA run.', array( 'status' => 409 ) ); }
        $action = $input['action'] ?? '';
        if ( empty( $r['active'] ) && ! in_array( $action, array( 'info', 'restore' ), true ) ) { return new WP_Error( 'run_inactive', 'Run is inactive.', array( 'status' => 409 ) ); }
        if ( 'info' === $action ) {
            $s = get_option( 'woocommerce_wootax_settings', array() ); $pages = array(); foreach ( $r['pages'] as $k => $id ) { $pages[ $k ] = get_permalink( $id ); }
            require_once ABSPATH . 'wp-admin/includes/plugin.php'; $plugins = array(); foreach ( get_plugins() as $file => $p ) { $plugins[] = array( 'name' => $p['Name'], 'version' => $p['Version'], 'active' => is_plugin_active( $file ) ); }
            return array( 'runId' => $r['id'], 'active' => $r['active'], 'versions' => array( 'wordpress' => get_bloginfo( 'version' ), 'woocommerce' => WC_VERSION, 'php' => PHP_VERSION, 'theme' => wp_get_theme()->get( 'Name' ) ), 'plugins' => $plugins, 'products' => $r['products'], 'pages' => $pages, 'coupon' => $r['coupon'], 'userIds' => $r['userIds'], 'pageIds' => $r['pages'], 'registry' => self::registry(), 'names' => array_values( $r['names'] ?? array() ), 'newOrderUrl' => admin_url( ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) ? 'admin.php?page=wc-orders&action=new' : 'post-new.php?post_type=shop_order' ), 'taxcloudStagingEndpoints' => defined( 'SST_TAXCLOUD_STAGING' ) && SST_TAXCLOUD_STAGING, 'hpos' => class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(), 'settings' => array_intersect_key( $s, array_flip( array( 'api_version', 'data_mover', 'default_origin_addresses', 'show_exempt', 'restrict_exempt', 'enable_taxcloud_rate_limit', 'capture_trigger', 'disable_integration', 'capture_orders_in_taxcloud' ) ) ), 'recordCount' => count( self::records() ), 'restUrl' => rest_url( 'sst-qa/v1/control' ) );
        }
        // Private restoration material is returned only over the existing admin cookie + REST nonce boundary.
        // The Node task exposes it solely for masked settings form entry; never include it in run evidence.
        if ( 'restorePreview' === $action ) {
            $saved = $r['snapshot']['woocommerce_wootax_settings']['value'] ?? array();
            return array( 'login' => $saved['tc_id'] ?? '', 'key' => $saved['tc_key'] ?? '', 'apiVersion' => $saved['api_version'] ?? 'v1', 'origins' => $saved['default_origin_addresses'] ?? array() );
        }
        if ( 'resetActors' === $action ) { self::reset_actors(); return true; }
        if ( 'reserveName' === $action ) {
            $key = (string) ( $input['key'] ?? '' );
            if ( ! $key || strlen( $key ) > 500 ) { return new WP_Error( 'bad_reservation', 'Scenario key required.', array( 'status' => 400 ) ); }
            foreach ( $r['names'] ?? array() as $token => $name ) { if ( $name['key'] === $key ) { return array_merge( $name, array( 'token' => $token ) ); } }
            if ( ! add_option( 'sst_qa_name_lock', $r['id'], '', false ) ) { return new WP_Error( 'name_locked', 'Name reservation busy; inspect before retrying.', array( 'status' => 409 ) ); }
            try {
                $number = (int) get_option( 'sst_qa_name_sequence', 0 ) + 1; update_option( 'sst_qa_name_sequence', $number, false );
                $token = wp_generate_password( 40, false );
                $name = array( 'key' => $key, 'number' => $number, 'label' => 'Txc User ' . $number, 'firstName' => 'Txc', 'lastName' => 'User ' . $number, 'email' => 'txc-user-' . $number . '@example.test', 'surface' => in_array( $input['surface'] ?? '', array( 'classic', 'blocks', 'admin' ), true ) ? $input['surface'] : 'unspecified', 'actor' => in_array( $input['role'] ?? '', array( 'buyer', 'guest', 'exempt' ), true ) ? $input['role'] : 'unspecified', 'orderId' => null, 'reservedAt' => gmdate( 'c' ) );
                $r['names'][ $token ] = $name; update_option( self::OPTION, $r, false );
                return array_merge( $name, array( 'token' => $token ) );
            } finally { delete_option( 'sst_qa_name_lock' ); }
        }
        if ( 'layout' === $action ) { if ( ! in_array( $input['layout'] ?? '', array( 'classic', 'blocks' ), true ) ) { return new WP_Error( 'bad_layout', 'Unknown layout.', array( 'status' => 400 ) ); } self::layout( $input['layout'] ); return true; }
        if ( 'records' === $action ) { return self::clean( self::records() ); }
        if ( 'fee' === $action ) { if ( ! in_array( $input['fee'] ?? '', array( 'none', 'positive', 'negative' ), true ) ) { return new WP_Error( 'bad_fee', 'Unknown fixed fee fixture.', array( 'status' => 400 ) ); } $r['fixtureFee'] = $input['fee']; update_option( self::OPTION, $r, false ); return true; }
        if ( 'clearCustomerRate' === $action ) { foreach ( $r['userIds'] as $user_id ) { delete_transient( 'sst_rate_limit_user_' . $user_id ); } return true; }
        if ( 'order' === $action ) {
            $order = wc_get_order( absint( $input['id'] ?? 0 ) );
            if ( ! $order || $order->get_meta( '_sst_qa_run' ) !== $r['id'] ) { return new WP_Error( 'not_owned', 'Order is not owned by this run.', array( 'status' => 403 ) ); }
            $lines = array(); foreach ( $order->get_items( array( 'line_item', 'shipping', 'fee' ) ) as $item ) { $lines[] = array( 'id' => $item->get_id(), 'type' => $item->get_type(), 'name' => $item->get_name(), 'total' => (float) $item->get_total(), 'tax' => (float) $item->get_total_tax(), 'quantity' => $item->get_quantity() ); }
            $url = method_exists( $order, 'get_edit_order_url' ) ? $order->get_edit_order_url() : admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' );
            return self::clean( array( 'id' => $order->get_id(), 'customerId' => $order->get_customer_id(), 'billingName' => $order->get_formatted_billing_full_name(), 'shippingName' => $order->get_formatted_shipping_full_name(), 'nameLabel' => $order->get_meta( '_sst_qa_name' ), 'status' => $order->get_status(), 'tax' => (float) $order->get_total_tax(), 'total' => (float) $order->get_total(), 'taxcloudStatus' => $order->get_meta( '_wootax_status' ), 'packages' => maybe_unserialize( $order->get_meta( '_wootax_packages' ) ), 'remoteId' => $order->get_meta( 'sst_order_id' ), 'refunded' => (float) $order->get_total_refunded(), 'remainingRefund' => (float) $order->get_remaining_refund_amount(), 'refundIds' => array_map( function( $x ) { return $x->get_id(); }, $order->get_refunds() ), 'lines' => $lines, 'editUrl' => $url, 'notes' => array_map( function( $x ) { return $x->content; }, wc_get_order_notes( array( 'order_id' => $order->get_id(), 'limit' => 30 ) ) ) ) );
        }
        if ( 'remoteCertificates' === $action ) {
            $uid = absint( $input['actorId'] ?? 0 );
            if ( ! in_array( $uid, $r['userIds'], true ) || get_user_meta( $uid, '_sst_qa_site', true ) !== self::site() ) { return new WP_Error( 'not_owned', 'Actor is not leased to this run.', array( 'status' => 403 ) ); }
            $api = new TaxCloud_V3\Exemptions(); return self::clean( $api->get_certificates_for_customer_ids( array( (string) $uid ) ) );
        }
        if ( 'remoteOrder' === $action ) {
            $order = wc_get_order( absint( $input['id'] ?? 0 ) );
            if ( ! $order || $order->get_meta( '_sst_qa_run' ) !== $r['id'] || ! $order->get_meta( 'sst_order_id' ) ) { return new WP_Error( 'not_owned', 'No run-owned remote order.', array( 'status' => 403 ) ); }
            $api = new TaxCloud_V3\Orders(); $value = $api->get_order( $order->get_meta( 'sst_order_id' ) . '_0' ); return is_wp_error( $value ) ? $value : self::clean( $value );
        }
        if ( 'restore' === $action ) {
            foreach ( $r['snapshot'] as $key => $saved ) { if ( $saved['exists'] ) { update_option( $key, $saved['value'] ); } else { delete_option( $key ); } }
            $mismatches = array();
            foreach ( $r['snapshot'] as $key => $saved ) {
                $sentinel = '__missing__';
                $actual = get_option( $key, $sentinel );
                $matches = false;
                if ( ! $saved['exists'] ) {
                    $matches = ( $actual === $sentinel );
                } elseif ( 'active_plugins' === $key && is_array( $actual ) && is_array( $saved['value'] ) ) {
                    $s1 = $actual; sort( $s1 );
                    $s2 = $saved['value']; sort( $s2 );
                    $matches = ( $s1 === $s2 );
                } else {
                    $matches = ( $actual === $saved['value'] );
                }
                if ( ! $matches ) { $mismatches[] = $key; }
            }
            foreach ( $r['zoneOrders'] ?? array() as $zone_id => $position ) { $old_zone = new WC_Shipping_Zone( $zone_id ); $old_zone->set_zone_order( $position ); $old_zone->save(); if ( (int) $old_zone->get_zone_order() !== (int) $position ) { $mismatches[] = 'shipping_zone_' . $zone_id; } }
            if ( $r['shippingZone'] ?? false ) { $zone = new WC_Shipping_Zone( $r['shippingZone'] ); $zone->set_zone_order( 999999 ); foreach ( $zone->get_shipping_methods() as $method ) { $settings = get_option( $method->get_option_key(), array() ); $settings['enabled'] = 'no'; update_option( $method->get_option_key(), $settings ); } $zone->save(); }
            if ( $mismatches ) { return new WP_Error( 'restore_mismatch', 'Restoration mismatch: ' . implode( ', ', $mismatches ), array( 'status' => 500 ) ); }
            $r['active'] = false; $r['restoredAt'] = gmdate( 'c' ); update_option( self::OPTION, $r, false ); delete_transient( 'sst_qa_access_' . $r['id'] );
            self::reset_actors();
            foreach ( $r['userIds'] as $uid ) { WP_Session_Tokens::get_instance( $uid )->destroy_all(); }
            if ( ! empty( $r['userIds'][0] ) && ( get_user_meta( $r['userIds'][0], '_sst_qa_actor', true ) === 'runner' ) ) { wp_set_password( wp_generate_password( 48, true ), $r['userIds'][0] ); }
            return array( 'restored' => true, 'mismatches' => array(), 'helper' => 'active-idle', 'retained' => array( 'products' => $r['products'], 'pages' => $r['pages'], 'users' => $r['userIds'], 'shippingZone' => $r['shippingZone'] ) );
        }
        return new WP_Error( 'unknown_action', 'Unknown fixed QA action.', array( 'status' => 400 ) );
    }
}
SST_Staging_QA::init();
