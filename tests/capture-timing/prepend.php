<?php
/**
 * Private php-cgi probe for the LocalWP timing study. Loaded by run.py only.
 * Never install this file as a WordPress plugin.
 */
$run_dir = getenv( 'SST_TIMING_DIR', true );
if ( 'cgi-fcgi' !== PHP_SAPI || ! $run_dir || ! is_file( $run_dir . '/run.json' ) ) {
	exit;
}
$config = json_decode( file_get_contents( $run_dir . '/run.json' ), true );
$cell   = json_decode( getenv( 'SST_TIMING_CELL', true ), true );
define( 'WP_PLUGIN_DIR', $run_dir . '/plugins' );
define( 'WPMU_PLUGIN_DIR', $run_dir . '/mu' );
define( 'DISABLE_WP_CRON', true );

function sst_timing_queue( $order ) {
	$GLOBALS['sst_timing_sample']['trigger_at'] = microtime( true );
	return SST_Deferred_Capture::queue( $order );
}

function sst_timing_capture( $order ) {
	$start = hrtime( true );
	$GLOBALS['sst_timing_in_capture'] = true;
	try {
		$result = $order->do_capture();
		$GLOBALS['sst_timing_sample']['captured'] = $result;
		return $result;
	} finally {
		$GLOBALS['sst_timing_sample']['capture_ms'] = ( hrtime( true ) - $start ) / 1e6;
		$GLOBALS['sst_timing_in_capture'] = false;
	}
}

// Register before WP loads active plugins, only in this private CGI process.
$GLOBALS['wp_filter']['muplugins_loaded'][0][] = array(
	'accepted_args' => 0,
	'function'      => function () use ( $config, $cell, $run_dir ) {
		if ( ! empty( $config['verify'] ) ) {
			add_filter( 'pre_http_request', function () { return new WP_Error( 'fixture_only', 'Unmatched local fixture request.' ); }, -90 );
			if ( ! empty( $cell['storage'] ) ) {
				add_filter( 'pre_option_woocommerce_custom_orders_table_enabled', function () use ( $cell ) { return 'hpos' === $cell['storage'] ? 'yes' : 'no'; } );
				add_filter( 'pre_option_woocommerce_custom_orders_table_data_sync_enabled', function () { return 'no'; } );
			}
		}

		add_filter( 'pre_option_active_plugins', function () {
			return array( 'woocommerce/woocommerce.php', 'simple-sales-tax/simple-sales-tax.php' );
		} );
		add_filter( 'option_woocommerce_wootax_settings', function ( $settings ) use ( $config, $cell ) {
			$credentials = ! empty( $config['verify'] ) ? array_fill_keys( array( 'apiLoginIDRealtime', 'apiKeyRealtime', 'apiLoginIDDataImport', 'apiKeyDataImport' ), 'dev6611-fixture' ) : json_decode( file_get_contents( $config['credentials'] ), true );
			$suffix = 'import' === $cell['mode'] ? 'DataImport' : 'Realtime';
			$origins = array();
			foreach ( array( 'origin-a' => '323 Washington Ave N', 'origin-b' => '200 S 6th St' ) as $id => $street ) {
				$origins[] = wp_json_encode( array( 'ID' => $id, 'Default' => 'origin-a' === $id, 'Address1' => $street,
					'City' => 'Minneapolis', 'State' => 'MN', 'Zip5' => '55401', 'Zip4' => '' ) );
			}
			return array_merge( (array) $settings, array(
				'api_version' => 'v3', 'tc_id' => $credentials[ 'apiLoginID' . $suffix ], 'tc_key' => $credentials[ 'apiKey' . $suffix ],
				'tc_integration_id' => '', 'tc_connection_id' => '', 'disable_integration' => 'no', 'data_mover' => 'import' === $cell['mode'],
				'capture_orders_in_taxcloud' => $cell['enabled'] ? 'yes' : 'no', 'capture_trigger' => $cell['trigger'],
				'background_data_import_capture' => ! empty( $config['background'] ) ? 'yes' : 'no',
				'addresses' => $origins, 'default_origin_addresses' => array( 'origin-a' ),
				'show_exempt' => 'false', 'log_requests' => 'no', 'disable_virtual_split' => 'no',
				'enable_taxcloud_rate_limit' => 'no',
			) );
		} );
		add_filter( 'pre_update_option_woocommerce_wootax_settings', function ( $new, $old ) { return $old; }, 10, 2 );
		add_filter( 'option_woocommerce_cod_settings', function ( $settings ) {
			return array_merge( (array) $settings, array( 'enabled' => 'yes', 'enable_for_virtual' => 'yes', 'enable_for_methods' => array() ) );
		} );
		add_filter( 'pre_option_woocommerce_enable_guest_checkout', function () { return 'yes'; } );
		add_filter( 'pre_wp_mail', '__return_true' );
		add_filter( 'woocommerce_defer_transactional_emails', '__return_false' );
		add_filter( 'woocommerce_webhook_should_deliver', '__return_false' );
		add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );
		// All requests here originate in the driver, with no listening socket.
		add_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );
		add_filter( 'woocommerce_store_api_rate_limit_options', function () { return array( 'enabled' => false ); } );

		$GLOBALS['sst_timing_sample'] = array( 'calls' => array(), 'capture_ms' => null, 'captured' => null );
		$starts = array();
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$starts ) {
			$hosts = array( 'api.v3.taxcloud.com', 'taxcloudapi-appservice-core-prod.azurewebsites.net' );
			if ( ! in_array( wp_parse_url( $url, PHP_URL_HOST ), $hosts, true ) ) {
				return new WP_Error( 'timing_unrelated_http', 'Unrelated HTTP disabled during local measurement.' );
			}
			$starts[ $url ] = hrtime( true );
			return $pre;
		}, -100, 3 );
		add_action( 'http_api_debug', function ( $response, $type, $class, $args, $url ) use ( &$starts ) {
			if ( 'response' !== $type || ! isset( $starts[ $url ] ) ) { return; }
			$path = wp_parse_url( $url, PHP_URL_PATH );
			$route = preg_replace( '~.*/connections/[^/]+/?~', '/', $path );
			$route = preg_replace( '~^/(orders|carts)/[^/]+$~', '/$1/{id}', $route );
			if ( str_ends_with( $path, '/carts/orders' ) ) { $route = '/carts/orders'; }
			$GLOBALS['sst_timing_sample']['calls'][] = array( 'route' => $args['method'] . ' ' . $route,
				'status' => is_wp_error( $response ) ? $response->get_error_code() : wp_remote_retrieve_response_code( $response ),
				'timeout' => is_wp_error( $response ) && preg_match( '/timeout|timed out|error 28/i', $response->get_error_message() ) === 1,
				'capture' => ! empty( $GLOBALS['sst_timing_in_capture'] ), 'ms' => ( hrtime( true ) - $starts[ $url ] ) / 1e6 );
			unset( $starts[ $url ] );
		}, 10, 5 );

		if ( ! empty( $config['background'] ) ) {
			// Reserve this run's actions for its private runner; the shared site's cron uses different settings.
			add_filter( 'pre_as_schedule_single_action', function ( $pre, $when, $hook, $args, $group ) {
				static $reserving = false;
				if ( $reserving || 'sst_capture_data_import_order' !== $hook ) { return $pre; }
				$reserving = true;
				try {
					return as_schedule_single_action( $when + DAY_IN_SECONDS, $hook, $args, $group );
				} finally {
					$reserving = false;
				}
			}, 10, 5 );
		}

		add_action( 'wp_loaded', function () use ( $config, $cell ) {
			if ( home_url() !== $config['site'] || ! str_ends_with( wp_parse_url( home_url(), PHP_URL_HOST ), '.local' ) ) {
				wp_die( 'Expected LocalWP home URL.' );
			}
			if ( 'setup' === $cell['step'] ) {
				$ids = array();
				foreach ( array( 'origin-a', 'origin-b' ) as $origin ) {
					$product = new WC_Product_Simple();
					$product->set_name( 'DEV-6611 ' . $config['run'] . ' ' . $origin );
					$product->set_regular_price( '10.00' );
					$product->set_status( 'publish' );
					$product->set_catalog_visibility( 'hidden' );
					$product->set_virtual( true );
					$product->update_meta_data( '_wootax_origin_addresses', array( $origin ) );
					$ids[] = $product->save();
				}
				wp_send_json( $ids );
			}
			if ( 'verify' === $cell['step'] && ! empty( $config['verify'] ) ) {
				require __DIR__ . '/checks.php';
				try {
					wp_send_json( array( 'passed' => sst_verify_deferred_capture( $config, $cell['storage'] ) ) );
				} catch ( Throwable $error ) {
					wp_send_json( array( 'passed' => false, 'detail' => $error->getMessage() ) );
				}
			}

			if ( 'worker' === $cell['step'] ) {
				$order = wc_get_order( $cell['order'] );
				if ( ! $order || $order->get_billing_email() !== $config['email'] ) { wp_die( 'Timing order required.' ); }
				$state = SST_Deferred_Capture::state( $order );
				if ( empty( $state['action'] ) || 'queued' !== $state['status'] ) { wp_send_json( array( 'code' => 'job_missing' ), 409 ); }
				$GLOBALS['sst_timing_in_capture'] = true;
				$start = hrtime( true );
				ActionScheduler::runner()->process_action( $state['action'], 'LocalWP timing study' );
				$GLOBALS['sst_timing_sample']['worker_ms'] = ( hrtime( true ) - $start ) / 1e6;
				$GLOBALS['sst_timing_sample']['confirmed_at'] = microtime( true );
				$GLOBALS['sst_timing_in_capture'] = false;
				$order->read_meta_data( true );
				$state = SST_Deferred_Capture::state( $order );
				wp_send_json( array( 'status' => $state['status'], 'tax_status' => ( new SST_Order( $order ) )->get_taxcloud_status() ) );
			}

			if ( 'complete' === $cell['step'] ) {
				$order = wc_get_order( $cell['order'] );
				if ( ! $order || $config['email'] !== $order->get_billing_email() || ! $order->has_status( 'processing' ) ) {
					wp_die( 'Expected a Processing order owned by this timing run.' );
				}
				wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] );
			}
		} );
		register_shutdown_function( function () use ( $run_dir, $cell ) {
			$sample = $GLOBALS['sst_timing_sample'];
			$sample['php_request_ms'] = ( microtime( true ) - $_SERVER['REQUEST_TIME_FLOAT'] ) * 1000;
			file_put_contents( $run_dir . '/' . $cell['sample'] . '.json', wp_json_encode( $sample ) );
		} );
	},
);
