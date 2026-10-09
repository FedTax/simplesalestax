<?php
/** Temporary local MU plugin. Installed/removed by the Cypress Docker runner. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function sst_e2e_context() {
  $context = get_option( 'sst_cypress_v3_context', array() );
  $run = getenv( 'SST_E2E_RUN' ) ?: ( $_COOKIE['sst_e2e_run'] ?? '' );
  return ! empty( $context['run'] ) && hash_equals( $context['run'], (string) $run ) ? $context : null;
}

// Overrides apply only to the run's cookie/CLI process. Other visitors keep their settings.
add_filter( 'option_woocommerce_wootax_settings', function( $settings ) {
  $context = sst_e2e_context();
  if ( ! $context ) { return $settings; }
  $settings = array_merge( $settings, array(
    'api_version' => 'v3', 'disable_integration' => 'no', 'show_exempt' => 'true',
    'restrict_exempt' => 'no', 'enable_taxcloud_rate_limit' => 'no',
    'capture_orders_in_taxcloud' => 'yes', 'capture_trigger' => 'completed',
    'show_zero_tax' => 'true', 'disable_virtual_split' => 'no',
    'data_mover' => ! empty( $context['dataMover'] ),
    'addresses' => array( wp_json_encode( array( 'ID' => 'cypress-origin', 'Default' => true,
      'Address1' => '323 Washington Ave N', 'Address2' => '', 'City' => 'Minneapolis',
      'State' => 'MN', 'Zip5' => '55401', 'Zip4' => '' ) ) ),
    'default_origin_addresses' => array( 'cypress-origin' ),
  ) );
  if ( 'fixture' === $context['mode'] ) {
    $settings['tc_id'] = 'cypress-fixture-login';
    $settings['tc_key'] = 'cypress-fixture-connection';
  }
  return $settings;
} );
// Verification updates runtime SST settings. Do not persist the scoped fixture
// credentials/integration IDs into the real site's saved settings.
add_filter( 'pre_update_option_woocommerce_wootax_settings', function( $new, $old ) {
  return sst_e2e_context() ? $old : $new;
}, 10, 2 );
foreach ( array( 'cart', 'checkout' ) as $page ) {
  add_filter( 'option_woocommerce_' . $page . '_page_id', function( $id ) use ( $page ) {
    $context = sst_e2e_context();
    return $context ? $context[ $page . ( ! empty( $context['blocks'] ) ? 'Block' : 'Classic' ) ] : $id;
  } );
}
add_filter( 'option_woocommerce_cheque_settings', function( $settings ) {
  if ( sst_e2e_context() ) { $settings['enabled'] = 'yes'; }
  return $settings;
} );
// Test emails never go to a mail transport.
add_filter( 'pre_wp_mail', function( $result ) { return sst_e2e_context() ? true : $result; } );
add_filter( 'sst_package_order_id', function( $id, $order, $key ) {
  $context = sst_e2e_context();
  if ( ! $context || (int) $order->get_customer_id() !== (int) $context['customerId'] ) { return $id; }
  $base = 'cy-v3-' . substr( $context['run'], 0, 8 ) . '-' . $order->get_id();
  if ( $order->get_meta( 'sst_order_id' ) !== $base ) {
    $order->update_meta_data( 'sst_order_id', $base ); $order->save();
  }
  return $base . '_' . $key;
}, 100, 3 );

function sst_e2e_record( $url, $args, $response ) {
  $context = sst_e2e_context();
  if ( ! $context ) { return; }
  $path = parse_url( $url, PHP_URL_PATH );
  $path = preg_replace( '#/connections/[^/]+#', '/connections/:connection', $path );
  $body = json_decode( $args['body'] ?? '', true );
  $result = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
  if ( false !== strpos( $path, '/auth/token' ) ) { $body = null; $result = null; }
  $record = array( 'method' => $args['method'] ?? 'GET', 'path' => $path, 'request' => $body,
    'status' => is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response ),
    'response' => $result, 'error' => is_wp_error( $response ) ? $response->get_error_code() : null );
  // Never persist credentials, connection keys, or bearer tokens in evidence.
  $json = wp_json_encode( $record );
  $settings = get_option( 'woocommerce_wootax_settings' );
  foreach ( array( $settings['tc_id'] ?? '', $settings['tc_key'] ?? '', $settings['tc_connection_id'] ?? '', $settings['tc_integration_id'] ?? '' ) as $secret ) {
    if ( $secret ) { $json = str_replace( $secret, '[redacted]', $json ); }
  }
  file_put_contents( '/tmp/sst-cypress-' . $context['run'] . '/requests.ndjson', $json . "\n", FILE_APPEND | LOCK_EX );
}

function sst_e2e_taxcloud_url( $url ) {
  return in_array( parse_url( $url, PHP_URL_HOST ), array(
    'api.v3.taxcloud.com', 'api.v3.taxcloud.net',
    'taxcloudapi-appservice-core-prod.azurewebsites.net', 'staging-taxcloudapi.azurewebsites.net',
  ), true );
}
add_action( 'http_api_debug', function( $response, $type, $class, $args, $url ) {
  $context = sst_e2e_context();
  if ( $context && 'live' === $context['mode'] && 'response' === $type && sst_e2e_taxcloud_url( $url ) ) {
    sst_e2e_record( $url, $args, $response );
  }
}, 10, 5 );

add_filter( 'pre_http_request', function( $preempt, $args, $url ) {
  $context = sst_e2e_context();
  if ( ! $context || 'fixture' !== $context['mode'] || ! sst_e2e_taxcloud_url( $url ) ) { return $preempt; }
  $path = parse_url( $url, PHP_URL_PATH );
  $method = $args['method'] ?? 'GET';
  $body = json_decode( $args['body'] ?? '{}', true );
  $state = get_option( 'sst_cypress_v3_remote', array( 'certificates' => array(), 'carts' => array(), 'orders' => array() ) );
  $status = 200;
  $result = null;
  $invalid_cart = null;
  if ( 'POST' === $method && preg_match( '#/carts$#', $path ) ) {
    foreach ( $body['items'] ?? array() as $index => $cart ) {
      if ( strlen( $cart['cartId'] ?? '' ) > 50 ) {
        $invalid_cart = array( 'message' => 'expected length <= 50',
          'location' => "body.items[$index].cartId", 'value' => $cart['cartId'] );
      }
      foreach ( array( 'origin', 'destination' ) as $kind ) {
        $address = $cart[ $kind ] ?? array();
        if ( 'US' === ( $address['countryCode'] ?? '' ) && ! preg_match( '/^\d{5}(?:-\d{4})?$/D', $address['zip'] ?? '' ) ) {
          $invalid_cart = array( 'message' => 'zip has a bad value for country US',
            'location' => "body.items[$index].$kind.zip", 'value' => $address['zip'] ?? '' );
        }
      }
    }
  }
  if ( $invalid_cart ) {
    $status = 422; $result = array( 'detail' => 'validation failed', 'errors' => array( $invalid_cart ) );
  } elseif ( ! empty( $context['failure'] ) && preg_match( '#/carts$#', $path ) ) {
    $status = 422; $result = array( 'detail' => 'Cypress injected cart rejection' );
  } elseif ( str_ends_with( $path, '/auth/token' ) ) {
    $result = array( 'access_token' => 'fixture-bearer', 'connection_id' => 'fixture-integration' );
  } elseif ( preg_match( '#^/mgmt/connections/[^/]+$#', $path ) ) {
    $result = array( 'id' => 'fixture-integration', 'options' => array( 'data_mover' => array( 'flag' => false ) ) );
  } elseif ( str_ends_with( $path, '/ping' ) ) {
    $result = array( 'message' => 'Successfully authenticated' );
  } elseif ( '/tax/verify-address' === $path ) {
    $result = $body;
  } elseif ( '/tax/tic/search' === $path ) {
    $result = array( 'query' => $body['query'], 'results' => array( array( 'ticId' => 0, 'description' => 'Uncategorized' ) ), 'nextCursor' => null );
  } elseif ( '/tax/exemption-certificates' === $path ) {
    parse_str( parse_url( $url, PHP_URL_QUERY ) ?? '', $query );
    $result = array( 'items' => array_values( array_filter( $state['certificates'], function( $cert ) use ( $query ) {
      return null === $cert['disabledAt'] && ( ! isset( $query['customerId'] ) || $cert['customerId'] === $query['customerId'] );
    } ) ), 'nextCursor' => null );
  } elseif ( preg_match( '#/exemption-certificates(?:/([^/]+))?$#', $path, $match ) ) {
    $id = isset( $match[1] ) ? rawurldecode( $match[1] ) : null;
    if ( 'POST' === $method ) {
      $id = 'cert-' . wp_generate_uuid4(); $status = 201;
      $result = array_merge( $body, array( 'certificateId' => $id, 'disabledAt' => null, 'createdDate' => gmdate( 'c' ) ) );
      $state['certificates'][ $id ] = $result;
    } elseif ( isset( $state['certificates'][ $id ] ) ) {
      $result = $state['certificates'][ $id ];
      if ( 'DELETE' === $method ) { $state['certificates'][ $id ]['disabledAt'] = gmdate( 'c' ); $result = null; $status = 204; }
    } else { $status = 404; $result = array( 'detail' => 'Certificate not found' ); }
  } elseif ( str_ends_with( $path, '/carts/orders' ) ) {
    $status = 201;
    if ( ! isset( $state['carts'][ $body['cartId'] ] ) ) { $status = 404; $result = array( 'detail' => 'Cart not found' ); }
    else {
      $result = array_merge( $state['carts'][ $body['cartId'] ], $body );
      $result['completedDate'] = $body['completedDate'] ?? ( ! empty( $body['completed'] ) ? gmdate( 'c' ) : null );
      $state['orders'][ $body['orderId'] ] = $result;
    }
  } elseif ( preg_match( '#/carts(?:/([^/]+))?$#', $path, $match ) ) {
    if ( 'POST' === $method ) {
      $items = array();
      foreach ( $body['items'] as $cart ) {
        $cart['cartId'] = $cart['cartId'] ?: 'cart-' . wp_generate_uuid4();
        $exempt = ! empty( $cart['exemption']['exemptionId'] );
        $rate = $exempt || 'OR' === $cart['destination']['state'] ? 0 : 0.1;
        foreach ( $cart['lineItems'] as &$item ) { $item['tax'] = array( 'amount' => round( $item['price'] * $item['quantity'] * $rate, 2 ), 'rate' => $rate ); }
        unset( $item );
        $state['carts'][ $cart['cartId'] ] = $cart;
        $items[] = $cart;
      }
      $result = array( 'items' => $items );
    } else { $result = $state['carts'][ rawurldecode( $match[1] ) ] ?? null; }
  } elseif ( preg_match( '#/orders/refunds/([^/]+)$#', $path, $match ) ) {
    $id = rawurldecode( $match[1] ); $status = 201;
    if ( ! isset( $state['orders'][ $id ] ) ) { $status = 404; $result = array( 'detail' => 'Order not found' ); }
    else { $result = array( array( 'idempotencyKey' => $body['idempotencyKey'] ?? '', 'items' => $body['items'] ?? array() ) ); }
  } elseif ( preg_match( '#/orders(?:/([^/]+))?$#', $path, $match ) ) {
    if ( 'POST' === $method ) { $status = 201; $result = $body; $state['orders'][ $body['orderId'] ] = $body; }
    else {
      $id = rawurldecode( $match[1] );
      if ( 'PATCH' === $method && ! empty( $state['orders'][ $id ]['completedDate'] ) ) {
        $status = 400; $result = array( 'detail' => 'order is already completed, completed date cannot be updated' );
      } else {
        if ( 'PATCH' === $method && isset( $state['orders'][ $id ] ) ) { $state['orders'][ $id ] = array_merge( $state['orders'][ $id ], $body ); }
        $result = $state['orders'][ $id ] ?? null;
      }
    }
  } else { return new WP_Error( 'sst_e2e_unhandled_request', 'Unhandled TaxCloud fixture route: ' . $method . ' ' . $path ); }
  update_option( 'sst_cypress_v3_remote', $state, false );
  $response = array( 'response' => array( 'code' => $status, 'message' => 'Cypress fixture' ),
    'headers' => array(), 'body' => 204 === $status ? '' : wp_json_encode( $result ), 'cookies' => array() );
  sst_e2e_record( $url, $args, $response );
  return $response;
}, 10, 3 );
