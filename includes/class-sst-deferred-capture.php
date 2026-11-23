<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The durable Data Import capture intent. The upload itself uses SST_Order's separate upload lock. */
class SST_Deferred_Capture {
	const META  = '_wootax_deferred_capture';
	const HOOK  = 'sst_capture_data_import_order';
	const GROUP = 'simple-sales-tax';

	private static $shutdown_orders = array();

	public static function enabled() {
		return 'yes' === SST_Settings::get( 'background_data_import_capture' )
			&& 'yes' === SST_Settings::get( 'capture_orders_in_taxcloud' )
			&& 'data_mover' === sst_integration_mode();
	}

	/** Invalidate outstanding automatic intents even if the merchant re-enables capture before cron runs. */
	public static function settings_updated( $new, $old ) {
		if ( ! is_array( $new ) || ! is_array( $old ) || $new === $old ) {
			return $new;
		}
		$was_active = ! empty( $old['data_mover'] ) && 'yes' === ( $old['capture_orders_in_taxcloud'] ?? 'yes' )
			&& 'yes' !== ( $old['disable_integration'] ?? 'no' );
		$is_active  = ! empty( $new['data_mover'] ) && 'yes' === ( $new['capture_orders_in_taxcloud'] ?? 'yes' )
			&& 'yes' !== ( $new['disable_integration'] ?? 'no' );
		if ( $was_active && ! $is_active ) {
			$new['background_capture_generation'] = wp_generate_uuid4();
		} elseif ( isset( $old['background_capture_generation'] ) ) {
			$new['background_capture_generation'] = $old['background_capture_generation'];
		}
		return $new;
	}

	public static function state( $order ) {
		$value = $order->get_meta( self::META, true );
		return is_array( $value ) ? $value : array();
	}

	private static function save( $order, $state ) {
		$order->update_meta_data( self::META, $state );
		$order->save_meta_data();
	}

	/** Only hold this lock around local queue state, never during a TaxCloud request. */
	private static function change( $id, $callback ) {
		global $wpdb;
		$name = 'sst_queue_' . md5( DB_NAME . ':' . $wpdb->prefix . ':' . $id );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 3)', $name ) ) ) {
			throw new RuntimeException( __( 'Could not lock the TaxCloud capture queue for this order.', 'simple-sales-tax' ) );
		}
		try {
			$order = wc_get_order( $id );
			if ( $order instanceof WC_Order ) {
				$order->read_meta_data( true );
				return $callback( $order, self::state( $order ) );
			}
			return false;
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
	}

	/** Commit the first event and defer scheduling until all order processing hooks have returned. */
	public static function queue( $order ) {
		$existing = self::state( $order );
		if ( $existing ) {
			if ( self::pending( $existing ) && empty( $existing['action'] ) ) {
				self::$shutdown_orders[ $order->get_id() ] = true;
			}
			return true;
		}
		$complete = $order->get_date_completed();
		$date     = $complete ? $complete->getTimestamp() : time();
		try {
			return self::change( $order->get_id(), function ( $fresh, $state ) use ( $date ) {
				if ( $state ) {
					return true;
				}
				$sst = new SST_Order( $fresh );
				if ( ! $sst->is_data_import_capture_candidate( true ) || ! apply_filters( 'sst_should_capture_order', true, $fresh, $sst ) ) {
					return false;
				}
				self::save( $fresh, array(
					'status' => 'queued', 'date' => $date, 'due' => time(), 'attempt' => 0,
					'connection' => hash( 'sha256', (string) SST_Settings::get( 'tc_key' ) ),
					'generation' => SST_Settings::get( 'background_capture_generation' ),
					'error' => '', 'action' => 0,
				) );
				self::$shutdown_orders[ $fresh->get_id() ] = true;
				return true;
			} );
		} catch ( Exception $ex ) {
			$order->add_order_note( __( 'TaxCloud could not queue this order. Use Capture in TaxCloud on the order page.', 'simple-sales-tax' ) );
			return false;
		}
	}

	public static function flush() {
		$ids = array_keys( self::$shutdown_orders );
		self::$shutdown_orders = array();
		foreach ( $ids as $id ) {
			try {
				self::change( $id, function ( $order, $state ) {
					if ( self::pending( $state ) && empty( $state['action'] ) ) {
						self::schedule( $order, $state );
					}
				} );
			} catch ( Exception $ex ) {
				SST_Logger::order_log( $ex->getMessage(), $id );
			}
		}
	}

	/** Called under the queue lock. The attempt number makes callbacks from older retries harmless. */
	private static function schedule( $order, $state ) {
		$args = array( $order->get_id(), $state['attempt'] );
		// Persist the attempt before insertion. A worker can start immediately after insertion.
		$state['action'] = 0;
		self::save( $order, $state );
		try {
			$pending = function_exists( 'as_get_scheduled_actions' ) ? as_get_scheduled_actions( array(
				'hook' => self::HOOK, 'group' => self::GROUP, 'args' => $args, 'status' => 'pending', 'per_page' => 1,
			), 'ids' ) : array();
			if ( $pending ) {
				$state['action'] = reset( $pending );
			} elseif ( function_exists( 'as_schedule_single_action' ) ) {
				$state['action'] = as_schedule_single_action( $state['due'], self::HOOK, $args, self::GROUP );
			}
		} catch ( Exception $ex ) {
			$state['action'] = 0;
		}
		if ( ! $state['action'] ) {
			self::finish( $order, $state, 'attention', __( 'TaxCloud could not schedule capture. Use Capture in TaxCloud on the order page.', 'simple-sales-tax' ) );
			return;
		}
		self::save( $order, $state );
	}

	private static function finish( $order, $state, $status, $message ) {
		$previous = $state['status'];
		$state['status'] = $status;
		$state['error']  = $message;
		$state['due']    = 0;
		self::save( $order, $state );
		if ( $status !== $previous && in_array( $status, array( 'attention', 'stopped' ), true ) ) {
			$order->add_order_note( $message );
		}
	}

	private static function pending( $state ) {
		return in_array( $state['status'] ?? '', array( 'queued', 'retry' ), true );
	}

	/** Do not submit a refund against an order the queue has not confirmed yet. */
	public static function hold_refund( $order_id, $refund_id ) {
		return self::change( $order_id, function ( $order, $state ) use ( $refund_id ) {
			if ( ! $state || ( 'pending' !== ( new SST_Order( $order ) )->get_taxcloud_status() && empty( $state['refunds'] ) ) ) {
				return false;
			}
			$ids = array( (int) $refund_id );
			foreach ( $order->get_refunds() as $refund ) {
				$ids[] = (int) $refund->get_id();
			}
			$state['refunds'] = array_values( array_unique( array_merge( $state['refunds'] ?? array(), $ids ) ) );
			/* translators: %s: WooCommerce refund IDs. */
			$message = sprintf( __( 'Refund %s occurred before TaxCloud capture was confirmed. Reconcile the sale and refund in TaxCloud.', 'simple-sales-tax' ), implode( ', ', $state['refunds'] ) );
			self::finish( $order, $state, 'attention', $message );
			return true;
		} );
	}

	/** Called after the captured status and all package receipts have been saved. */
	public static function captured( $order_id ) {
		self::change( $order_id, function ( $order, $state ) {
			if ( $state && empty( $state['refunds'] ) ) {
				self::finish( $order, $state, 'captured', '' );
			}
		} );
	}

	public static function run( $order_id, $attempt ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$order->read_meta_data( true );
		$state = self::state( $order );
		if ( ! self::pending( $state ) || (int) $attempt !== $state['attempt'] ) {
			return;
		}
		$sst = new SST_Order( $order );
		if ( 'captured' === $sst->get_taxcloud_status() ) {
			self::captured( $order_id );
			return;
		}
		foreach ( $order->get_refunds() as $refund ) {
			self::hold_refund( $order_id, $refund->get_id() );
			return;
		}

		// The upload rechecks settings, connection, order and refund state under its own lock.
		$result = $sst->capture_data_import_in_background( (int) $attempt );
		if ( true === $result ) {
			return;
		}
		$failed = self::change( $order_id, function ( $fresh, $current ) use ( $attempt, $result ) {
			if ( ! self::pending( $current ) || (int) $attempt !== $current['attempt'] ) {
				return;
			}
			$data    = $result->get_error_data();
			$message = $result->get_error_message();
			$code    = $result->get_error_code();
			if ( 'sst_capture_busy' === $code ) {
				// A manual capture or another worker owns the upload lock. Recheck its result.
				$current['due'] = time() + 60;
				self::schedule( $fresh, $current );
				return;
			}
			$delays = array( 60, 300, 1800 );
			if ( ! empty( $data['retryable'] ) && isset( $delays[ $attempt ] ) ) {
				$current['status'] = 'retry';
				$current['error'] = $message;
				$current['due'] = time() + max( $delays[ $attempt ], $data['retry_after'] ?? 0 );
				++$current['attempt'];
				self::schedule( $fresh, $current );
				return;
			}
			$stopped = 'sst_capture_stopped' === $code;
			self::finish( $fresh, $current, $stopped ? 'stopped' : 'attention', $message );
			return ! $stopped;
		} );
		if ( $failed ) {
			// Let Action Scheduler record the terminal failure in its own history too.
			throw new RuntimeException( $result->get_error_message() );
		}
	}

	/** Surface a worker that died before it could record an outcome, including runner timeouts. */
	public static function failed_action( $action_id ) {
		$action = ActionScheduler::store()->fetch_action( $action_id );
		if ( ! $action || self::HOOK !== $action->get_hook() || self::GROUP !== $action->get_group() ) {
			return;
		}
		$args = $action->get_args();
		self::change( $args[0], function ( $order, $state ) use ( $action_id ) {
			if ( ! self::pending( $state ) || (int) $state['action'] !== (int) $action_id ) {
				return;
			}
			if ( 'captured' === ( new SST_Order( $order ) )->get_taxcloud_status() ) {
				self::finish( $order, $state, 'captured', '' );
			} else {
				self::finish( $order, $state, 'attention', __( 'The background worker stopped before confirming capture. Use Capture in TaxCloud to check and resume this upload.', 'simple-sales-tax' ) );
			}
		} );
	}

	public static function label( $state ) {
		$labels = array(
			'queued' => __( 'Queued', 'simple-sales-tax' ), 'retry' => __( 'Retry scheduled', 'simple-sales-tax' ),
			'attention' => __( 'Needs attention', 'simple-sales-tax' ), 'stopped' => __( 'Stopped', 'simple-sales-tax' ),
			'captured' => __( 'Captured', 'simple-sales-tax' ),
		);
		if ( self::pending( $state ) && time() >= (int) $state['due'] + 900 ) {
			return __( 'Overdue', 'simple-sales-tax' );
		}
		return $labels[ $state['status'] ] ?? '';
	}
}
