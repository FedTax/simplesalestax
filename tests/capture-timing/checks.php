<?php
/** Real WooCommerce order stores and Action Scheduler, with a process-local TaxCloud service. */
if ( ! defined( 'ABSPATH' ) || ! getenv( 'SST_TIMING_DIR', true ) ) {
	exit;
}

function sst_verify_deferred_capture( $config, $storage ) {
	global $wpdb;
	$count = 0;
	$check = function ( $condition, $message ) use ( &$count ) {
		if ( ! $condition ) {
			throw new RuntimeException( $message );
		}
		++$count;
	};
	$check( ( 'hpos' === $storage ) === Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(), 'Expected order store' );
	$overrides = array();
	add_filter( 'option_woocommerce_wootax_settings', function ( $settings ) use ( &$overrides ) {
		return array_merge( $settings, $overrides );
	}, 100 );
	$set = function ( $key, $value ) use ( &$overrides ) {
		$overrides[ $key ] = $value;
		SST_Settings::load_settings();
	};

	$remote = $posts = $owned_orders = array();
	add_action( 'shutdown', function () use ( &$owned_orders ) {
		foreach ( $owned_orders as $id ) {
			for ( $attempt = 0; $attempt <= 3; $attempt++ ) {
				as_unschedule_all_actions( SST_Deferred_Capture::HOOK, array( $id, $attempt ), SST_Deferred_Capture::GROUP );
			}
		}
	}, 40 );
	$fault = '';
	$http = 0;
	add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$remote, &$posts, &$http, &$fault ) {
		++$http;
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$code = 200;
		$headers = array();
		if ( str_ends_with( $path, '/auth/token' ) ) {
			$body = array( 'access_token' => 'fixture-token' );
		} elseif ( 'GET' === $args['method'] && str_contains( $path, '/orders/' ) ) {
			$id = rawurldecode( basename( $path ) );
			$code = isset( $remote[ $id ] ) ? 200 : 404;
			$body = $remote[ $id ] ?? array();
		} elseif ( 'POST' === $args['method'] && str_ends_with( $path, '/orders' ) ) {
			$body = json_decode( $args['body'], true );
			$id = $body['orderId'];
			if ( is_int( $fault ) || ( 'second-package' === $fault && str_ends_with( $id, '_1' ) ) ) {
				$code = is_int( $fault ) ? $fault : 503;
				if ( 429 === $code ) { $headers['Retry-After'] = '130'; }
				$body = array( 'detail' => 'Fixture rejection' );
			} else {
				$posts[ $id ] = ( $posts[ $id ] ?? 0 ) + 1;
				$body['connectionId'] = 'dev6611-fixture';
				$remote[ $id ] = $body;
				$code = 201;
				if ( 'lost' === $fault ) {
					$fault = '';
					return new WP_Error( 'http_request_failed', 'Timed out after remote acceptance.' );
				}
			}
		} else {
			return $pre;
		}
		return array( 'response' => array( 'code' => $code ), 'headers' => $headers, 'body' => wp_json_encode( $body ) );
	}, 100, 3 );
	$create = function ( $status = 'processing', $packages = 1 ) use ( $config, &$owned_orders ) {
		$order = wc_create_order();
		$owned_orders[] = $order->get_id();
		$order->set_address( array( 'first_name' => 'Capture', 'last_name' => 'QA', 'email' => $config['email'],
			'address_1' => '323 Washington Ave N', 'city' => 'Minneapolis', 'state' => 'MN', 'postcode' => '55401', 'country' => 'US' ), 'billing' );
		foreach ( array_slice( $config['products'], 0, $packages ) as $id ) {
			$order->add_product( wc_get_product( $id ), 1 );
		}
		$order->calculate_totals( false );
		$order->set_status( $status );
		$order->save();
		return $order;
	};
	$state = function ( $order ) {
		$order->read_meta_data( true );
		return SST_Deferred_Capture::state( $order );
	};
	$run = function ( $order ) use ( $state ) {
		$action = $state( $order )['action'];
		ActionScheduler::runner()->process_action( $action, 'DEV-6611 regression' );
		return $state( $order );
	};
	$note_count = function ( $order, $message ) {
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id(), 'type' => 'internal' ) );
		return count( array_filter( $notes, function ( $note ) use ( $message ) { return $message === $note->content; } ) );
	};

	$set( 'capture_trigger', 'both' );
	$order = $create();
	$first = $state( $order );
	$order->update_status( 'completed' );
	$other = $create( 'completed', 2 );
	$check( $first && $first === $state( $order ) && ! $first['action'], 'Both transitions must produce one original intent' );
	$check( 0 === $http, 'No capture HTTP during either transition' );
	SST_Deferred_Capture::flush();
	$check( $state( $order )['action'] && $state( $other )['action'] !== $state( $order )['action'], 'Two orders require independent queued jobs' );
	$overdue = $state( $order );
	$overdue['due'] = time() - 901;
	$check( 'Overdue' === SST_Deferred_Capture::label( $overdue ), 'Stalled cron is visible on page read' );
	$items = $order->get_items();
	$item = reset( $items );
	$item->set_quantity( 3 );
	$item->set_total( 18 );
	$item->set_subtotal( 18 );
	$item->set_taxes( array( 'total' => array( 1 => '1.44' ), 'subtotal' => array( 1 => '1.44' ) ) );
	$item->save();
	$order->calculate_totals( false );
	$order->set_billing_address_1( '200 S 6th St' );
	$order->set_date_completed( time() - HOUR_IN_SECONDS );
	$order->save();
	$set( 'background_data_import_capture', 'no' );
	$check( 'captured' === $run( $order )['status'], 'Previously queued order still runs after opt-out' );
	$receipt = end( $remote );
	$check( '200 S 6th St' === $receipt['destination']['line1'] && strtotime( $receipt['completedDate'] ) === $first['date'], 'Edited destination and original trigger date' );
	$line = $receipt['lineItems'][0];
	$check( 3 === (int) $line['quantity'] && 6.0 === (float) $line['price'] && 1.44 === (float) $line['tax']['amount'], 'Freshly saved item quantity, price and tax' );

	$sync = $create();
	$check( ! $state( $sync ) && 'captured' === ( new SST_Order( $sync->get_id() ) )->get_taxcloud_status(), 'Default Data Import path captures synchronously' );
	$set( 'background_data_import_capture', 'yes' );

	$fault = 'lost';
	$check( 'retry' === $run( $other )['status'], 'Ambiguous POST schedules a retry' );
	$check( 'captured' === $run( $other )['status'] && max( $posts ) === 1, 'Recover missing split packages without a duplicate remote order' );

	$fault = 'second-package';
	$partial = $create( 'completed', 2 );
	SST_Deferred_Capture::flush();
	$check( 'retry' === $run( $partial )['status'], 'Retry a server error on the second package' );
	$partial->set_billing_address_1( '25 Park Ave' );
	$partial->save();
	$fault = '';
	$before = $posts;
	$check( 'attention' === $run( $partial )['status'] && $before === $posts, 'Edit after partial acceptance requires review' );

	$fault = 'lost';
	$conflict = $create();
	SST_Deferred_Capture::flush();
	$run( $conflict );
	$conflict_id = array_key_last( $remote );
	$remote[ $conflict_id ]['lineItems'][0]['price'] = 0;
	$check( 'attention' === $run( $conflict )['status'] && 1 === $posts[ $conflict_id ], 'Conflicting remote price requires review' );

	$order = $create();
	SST_Deferred_Capture::flush();
	$lock = 'sst_capture_' . md5( DB_NAME . ':' . $wpdb->prefix . ':' . $order->get_id() );
	$second = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	$second->get_var( $second->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) );
	$before = $http;
	try {
		$manual = ( new SST_Order( $order ) )->capture_data_import_manually();
		$check( 'sst_capture_busy' === $manual->get_error_code(), 'Manual capture honors in-flight upload lock' );
		$run( $order );
		$check( $before === $http, 'Busy worker sends no request' );
	} finally {
		$second->get_var( $second->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
	}
	$action = $state( $order )['action'];
	$check( true === ( new SST_Order( $order ) )->capture_data_import_manually(), 'Manual recovery works without cron' );
	$before = $http;
	ActionScheduler::runner()->process_action( $action, 'DEV-6611 duplicate' );
	$check( $before === $http && 'captured' === $state( $order )['status'], 'Stale job after manual success exits cleanly' );

	$fault = 429;
	$order = $create();
	SST_Deferred_Capture::flush();
	foreach ( array( 130, 300, 1800 ) as $index => $delay ) {
		$before = time();
		$next = $run( $order );
		$check( 'retry' === $next['status'] && $index + 1 === $next['attempt'] && $next['due'] >= $before + $delay
			&& $next['due'] <= time() + $delay, 'Retry delay, Retry-After and attempt counter' );
	}
	$failed = $run( $order );
	$check( 'attention' === $failed['status'] && 1 === $note_count( $order, $failed['error'] ), 'Exhaustion leaves one terminal note' );
	$check( ActionScheduler_Store::STATUS_FAILED === ActionScheduler::store()->get_status( $failed['action'] ), 'Terminal failure is recorded by Action Scheduler' );
	$fault = 401;
	$order = $create();
	SST_Deferred_Capture::flush();
	$check( 'attention' === $run( $order )['status'] && 0 === $state( $order )['attempt'], 'Credentials require attention without retry' );
	$fault = '';

	$reject = function ( $pre, $time, $hook ) { return SST_Deferred_Capture::HOOK === $hook ? 0 : $pre; };
	add_filter( 'pre_as_schedule_single_action', $reject, 999, 3 );
	$order = $create();
	SST_Deferred_Capture::flush();
	remove_filter( 'pre_as_schedule_single_action', $reject, 999 );
	$check( 'attention' === $state( $order )['status'] && 0 === $state( $order )['action'], 'Failed enqueue visible immediately' );

	foreach ( array( 'capture_orders_in_taxcloud' => 'no', 'disable_integration' => 'yes', 'data_mover' => false, 'tc_key' => 'different' ) as $key => $value ) {
		$order = $create();
		SST_Deferred_Capture::flush();
		$original = SST_Settings::get( $key );
		$set( $key, $value );
		$before = $http;
		$check( ( 'tc_key' === $key ? 'attention' : 'stopped' ) === $run( $order )['status'] && $before === $http, 'Setting change must stop upload: ' . $key );
		$set( $key, $original );
	}
	$order = $create();
	SST_Deferred_Capture::flush();
	$active = array( 'data_mover' => true, 'capture_orders_in_taxcloud' => 'yes' );
	$disabled = SST_Deferred_Capture::settings_updated( array_merge( $active, array( 'capture_orders_in_taxcloud' => 'no' ) ), $active );
	$enabled = SST_Deferred_Capture::settings_updated( $active, $disabled );
	$set( 'background_capture_generation', $enabled['background_capture_generation'] );
	$before = $http;
	$check( 'stopped' === $run( $order )['status'] && $http === $before, 'Disabling and re-enabling before cron cannot revive an old intent' );

	foreach ( array( 'processing', 'completed' ) as $trigger ) {
		$set( 'capture_trigger', $trigger );
		$order = $create( 'processing' === $trigger ? 'completed' : 'processing' );
		$check( ! $state( $order ), 'Only the configured trigger queues an order' );
		$order->update_status( $trigger );
		$check( 'queued' === $state( $order )['status'], 'Configured trigger queues an order' );
		SST_Deferred_Capture::flush();
		$run( $order );
	}
	$set( 'capture_trigger', 'both' );
	$set( 'capture_orders_in_taxcloud', 'no' );
	$order = $create();
	$check( ! $state( $order ), 'Automatic capture off suppresses new jobs' );
	$set( 'capture_orders_in_taxcloud', 'yes' );
	$set( 'data_mover', false );
	foreach ( array( 'yes', 'no' ) as $opt ) {
		$set( 'background_data_import_capture', $opt );
		// Exercise the existing synchronous Real Time hook without a cart lookup.
		add_filter( 'sst_should_capture_order', '__return_false' );
		$order = $create();
		remove_filter( 'sst_should_capture_order', '__return_false' );
		$check( ! $state( $order ), 'Real Time cannot enqueue an order' );
	}
	$set( 'data_mover', true );
	$set( 'background_data_import_capture', 'yes' );
	foreach ( array( 1, 10 ) as $amount ) {
		$order = $create();
		SST_Deferred_Capture::flush();
		$before = $http;
		$refund = wc_create_refund( array( 'amount' => $amount, 'order_id' => $order->get_id(), 'refund_payment' => false ) );
		$held = $run( $order );
		$check( ! is_wp_error( $refund ) && 'attention' === $held['status'] && in_array( $refund->get_id(), $held['refunds'], true )
			&& $http === $before, 'Refund hook holds full and partial refunds without HTTP' );
	}
	$order = $create();
	SST_Deferred_Capture::flush();
	$refund = new WC_Order_Refund();
	$refund->set_parent_id( $order->get_id() );
	$refund->set_amount( 1 );
	$refund->save(); // No woocommerce_refund_created notification.
	$held = $run( $order );
	$check( 'attention' === $held['status'] && in_array( $refund->get_id(), $held['refunds'], true ), 'Worker independently detects a refund' );
	$fields = SST_Settings::get_form_fields();
	$check( 'no' === $fields['background_data_import_capture']['default'], 'Merchant setting is opt-in' );
	$set( 'data_mover', false );
	$fields = SST_Settings::get_form_fields();
	$check( ! isset( $fields['background_data_import_capture'] ), 'Setting is absent in Real Time' );

	return $count;
}
