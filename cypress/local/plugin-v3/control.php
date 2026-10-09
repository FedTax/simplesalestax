<?php
/** WP-CLI fixture setup, read-only evidence, and controlled test operations. */
$input = json_decode( base64_decode( getenv( 'SST_E2E_PAYLOAD' ) ), true );
$action = $input['action'];
$context = get_option( 'sst_cypress_v3_context', array() );
if ( 'setup' !== $action && ( $context['run'] ?? '' ) !== $input['run'] ) { throw new Exception( 'No matching Cypress run.' ); }
$result = null;
if ( 'setup' === $action ) {
  if ( $context ) { throw new Exception( 'A previous Cypress run needs cleanup before another can start.' ); }
  $context = array( 'run' => $input['run'], 'mode' => $input['mode'],
    'adminId' => get_user_by( 'login', 'admin' )->ID,
    'password' => wp_generate_password( 24, false ), 'username' => 'cy-' . substr( $input['run'], 0, 12 ),
    'dataMover' => false, 'blocks' => false, 'failure' => false );
  $user_id = wp_insert_user( array( 'user_login' => $context['username'], 'user_pass' => $context['password'],
    'user_email' => $context['username'] . '@example.test', 'role' => 'customer' ) );
  if ( is_wp_error( $user_id ) ) { throw new Exception( $user_id->get_error_message() ); }
  $context['customerId'] = $user_id;
  $address = array( 'first_name' => 'Cypress', 'last_name' => 'Buyer', 'country' => 'US',
    'address_1' => '323 Washington Ave N', 'address_2' => '', 'city' => 'Minneapolis', 'state' => 'MN',
    'postcode' => '55401', 'email' => $context['username'] . '@example.test', 'phone' => '6125550100' );
  foreach ( $address as $key => $value ) {
    update_user_meta( $user_id, 'billing_' . $key, $value );
    update_user_meta( $user_id, 'shipping_' . $key, $value );
  }
  $product = new WC_Product_Simple();
  $product->set_name( 'Cypress V3 ' . $input['run'] );
  $product->set_regular_price( '100' ); $product->set_virtual( true ); $product->set_tax_status( 'taxable' );
  $product->set_status( 'publish' ); $product->update_meta_data( '_wootax_tic', 0 );
  $context['productId'] = $product->save();
  foreach ( array( 'cartClassic' => '[woocommerce_cart]', 'checkoutClassic' => '[woocommerce_checkout]',
    'cartBlock' => '<!-- wp:woocommerce/cart /-->', 'checkoutBlock' => '<!-- wp:woocommerce/checkout /-->' ) as $key => $content ) {
    if ( in_array( $key, array( 'cartBlock', 'checkoutBlock' ), true ) ) {
      $block = 'cartBlock' === $key ? 'woocommerce/cart' : 'woocommerce/checkout';
      foreach ( get_posts( array( 'post_type' => 'page', 'numberposts' => -1 ) ) as $page ) {
        if ( has_block( $block, $page ) && false === strpos( $page->post_title, 'Cypress ' ) ) { $content = $page->post_content; break; }
      }
    }
    $context[ $key ] = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish',
      'post_title' => 'Cypress ' . $key . ' ' . $input['run'], 'post_content' => $content ) );
  }
  update_option( 'sst_cypress_v3_context', $context, false );
  $result = true;
} elseif ( 'info' === $action ) {
  $result = array_intersect_key( $context, array_flip( array( 'username', 'password', 'productId', 'customerId', 'mode' ) ) );
  foreach ( array( 'cartClassic', 'checkoutClassic', 'cartBlock', 'checkoutBlock' ) as $key ) { $result[ $key ] = get_permalink( $context[ $key ] ); }
} elseif ( 'configure' === $action ) {
  foreach ( array( 'dataMover', 'blocks', 'failure' ) as $key ) { if ( isset( $input[ $key ] ) ) { $context[ $key ] = (bool) $input[ $key ]; } }
  update_option( 'sst_cypress_v3_context', $context, false );
  $result = true;
} elseif ( 'records' === $action ) {
  $file = '/tmp/sst-cypress-' . $context['run'] . '/requests.ndjson';
  $result = file_exists( $file ) ? array_map( function( $line ) { return json_decode( $line, true ); }, file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ) : array();
} elseif ( 'clearCaches' === $action ) {
  delete_transient( 'sst_tc_v3_token_' . md5( SST_Settings::get( 'tc_id' ) . ':' . SST_Settings::get( 'tc_key' ) ) );
  delete_transient( 'sst_verified_addresses' );
  SST_Certificates::delete_certificates( $context['customerId'] );
  $result = true;
} elseif ( 'order' === $action ) {
  $order = wc_get_order( $input['id'] );
  if ( ! $order || (int) $order->get_customer_id() !== (int) $context['customerId'] ) { throw new Exception( 'This is not an order belonging to the test customer.' ); }
  $result = array( 'id' => $order->get_id(), 'status' => $order->get_status(), 'tax' => (float) $order->get_total_tax(),
    'total' => (float) $order->get_total(), 'taxcloudStatus' => $order->get_meta( '_wootax_status' ),
    'packages' => maybe_unserialize( $order->get_meta( '_wootax_packages' ) ), 'certificate' => $order->get_meta( '_wootax_exempt_cert' ) );
} elseif ( 'clientReads' === $action ) {
  // These four methods have no WooCommerce UI caller; this is integration coverage.
  $carts = new TaxCloud_V3\Carts(); $orders = new TaxCloud_V3\Orders(); $certs = new TaxCloud_V3\Exemptions();
  $result = array();
  if ( ! empty( $input['cartId'] ) ) { $result['cart'] = $carts->get_cart( $input['cartId'] ); }
  if ( ! empty( $input['orderId'] ) ) {
    $result['order'] = $orders->get_order( $input['orderId'] );
  }
  if ( ! empty( $input['certificateId'] ) ) { $result['certificate'] = $certs->get_certificate( $input['certificateId'] ); }
  foreach ( $result as $value ) { if ( is_wp_error( $value ) ) { throw new Exception( $value->get_error_message() ); } }
} elseif ( 'clientUpdate' === $action ) {
  // Update Order has no UI caller. Complete a separate pending order; TaxCloud
  // rejects changing the completed date of an already captured order.
  $cart = $input['cart'];
  if ( $cart['customerId'] !== 'customer-' . $context['customerId'] ) { throw new Exception( 'This cart does not belong to the test customer.' ); }
  $cart['cartId'] = 'cy-v3-' . substr( $context['run'], 0, 8 ) . '-update-cart';
  $order_id = 'cy-v3-' . substr( $context['run'], 0, 8 ) . '-update-order';
  $carts = new TaxCloud_V3\Carts(); $orders = new TaxCloud_V3\Orders();
  $quote = $carts->calculate_tax( array( 'items' => array( $cart ) ) );
  if ( is_wp_error( $quote ) ) { throw new Exception( $quote->get_error_message() ); }
  $pending = $carts->create_order( $quote['items'][0]['cartId'], $order_id, false );
  if ( is_wp_error( $pending ) ) { throw new Exception( $pending->get_error_message() ); }
  $completed_date = gmdate( 'c' );
  $updated = $orders->update_order( $order_id, $completed_date );
  if ( is_wp_error( $updated ) ) { throw new Exception( $updated->get_error_message() ); }
  // Live PATCH can return the pre-update representation. Verify persisted state
  // with GET instead of assuming the PATCH body includes completedDate.
  $persisted = $orders->get_order( $order_id );
  if ( is_wp_error( $persisted ) ) { throw new Exception( $persisted->get_error_message() ); }
  $result = array( 'pendingOrder' => $pending, 'updatedOrder' => $updated,
    'persistedOrder' => $persisted, 'completedDate' => $completed_date );
} elseif ( 'cleanup' === $action ) {
  // Keep test orders for inspection. Remove only this run's disposable fixtures.
  if ( 'live' === $context['mode'] ) {
    wp_set_current_user( $context['adminId'] );
    foreach ( SST_Certificates::get_certificates( $context['customerId'] ) as $cert ) {
      SST_Certificates::delete_certificate( $cert->getCertificateID(), $context['customerId'] );
    }
  }
  foreach ( array( 'cartClassic', 'checkoutClassic', 'cartBlock', 'checkoutBlock', 'productId' ) as $key ) { wp_trash_post( $context[ $key ] ); }
  // Retain the test customer with its orders; never delete existing customers.
  delete_option( 'sst_cypress_v3_context' ); delete_option( 'sst_cypress_v3_remote' );
  $result = true;
} else { throw new Exception( 'Unknown Cypress task.' ); }
echo 'SST_E2E_RESULT:' . wp_json_encode( $result );
