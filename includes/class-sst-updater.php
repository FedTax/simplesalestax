<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'WP_Async_Request', false ) ) {
	require_once WC()->plugin_path() . '/includes/libraries/wp-async-request.php';
}

if ( ! class_exists( 'WP_Background_Process', false ) ) {
	require_once WC()->plugin_path() . '/includes/libraries/wp-background-process.php';
}

/**
 * SST Updater.
 *
 * Uses https://github.com/A5hleyRich/wp-background-processing to handle DB
 * updates in the background. Ripped from WooCommerce core.
 *
 * @author  Simple Sales Tax
 * @package SST
 * @since   5.0
 */
class SST_Updater extends WP_Background_Process {

	/**
	 * Action for background process.
	 *
	 * @var string
	 */
	protected $action = 'sst_updater';

	/**
	 * Dispatch updater.
	 *
	 * Updater will still run via cron job if this fails for any reason.
	 */
	public function dispatch() {
		$dispatched = parent::dispatch();
		$logger     = new WC_Logger();

		if ( is_wp_error( $dispatched ) ) {
			$logger->add(
				'sst_db_updates',
				sprintf( 'Unable to dispatch Simple Sales Tax updater: %s', $dispatched->get_error_message() )
			);
		}
	}

	/**
	 * Handle cron healthcheck
	 *
	 * Restart the background process if not already running
	 * and data exists in the queue.
	 */
	public function handle_cron_healthcheck() {
		if ( $this->is_process_running() ) {
			// Background process already running.
			return;
		}

		if ( $this->is_queue_empty() ) {
			// No data to process.
			$this->clear_scheduled_event();

			return;
		}

		$this->handle();
	}

	/**
	 * Schedule fallback event.
	 */
	protected function schedule_event() {
		if ( ! wp_next_scheduled( $this->cron_hook_identifier ) ) {
			wp_schedule_event( time() + 10, $this->cron_interval_identifier, $this->cron_hook_identifier );
		}
	}

	/**
	 * Is the updater running?
	 *
	 * @return boolean
	 */
	public function is_updating() {
		return false === $this->is_queue_empty();
	}

	/**
	 * Task
	 *
	 * Override this method to perform any actions required on each
	 * queue item. Return the modified item for further processing
	 * in the next pass through. Or, return false to remove the
	 * item from the queue.
	 *
	 * @param string $callback Update callback function.
	 *
	 * @return mixed
	 */
	protected function task( $callback ) {
		if ( ! defined( 'SST_UPDATING' ) ) {
			define( 'SST_UPDATING', true );
		}

		$logger = new WC_Logger();

		require_once 'sst-update-functions.php';

		$return = false;

		if ( is_callable( $callback ) ) {
			$logger->add( 'sst_db_updates', sprintf( 'Running %s callback', $callback ) );

			$return = call_user_func( $callback );

			$logger->add( 'sst_db_updates', sprintf( 'Finished %s callback', $callback ) );
		} else {
			$logger->add( 'sst_db_updates', sprintf( 'Could not find %s callback', $callback ) );
		}

		return $return;
	}

	/**
	 * Complete
	 *
	 * Override if applicable, but ensure that the below actions are
	 * performed, or, call parent::complete().
	 */
	protected function complete() {
		$logger = new WC_Logger();
		$logger->add( 'sst_db_updates', 'Data update complete' );

		update_option( 'wootax_version', SST()->version );

		if ( ! class_exists( 'WC_Admin_Notices' ) ) {
			require WC()->plugin_path() . '/admin/class-wc-admin-notices.php';
		}

		WC_Admin_Notices::remove_notice( 'sst_update' );

		parent::complete();
	}
}
