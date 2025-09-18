<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Logger.
 *
 * Used for logging error messages.
 *
 * @author  Simple Sales Tax
 * @package SST
 * @since   5.0
 */
class SST_Logger {

	/**
	 * Log handle.
	 *
	 * @var string
	 * @since 5.0
	 */
	protected static $handle = 'wootax';

	/**
	 * Logger instance.
	 *
	 * @var WC_Logger
	 * @since 5.0
	 */
	protected static $logger = null;

	/**
	 * Initialize the logger instance.
	 *
	 * @since 5.0
	 */
	public static function init() {
		if ( 'yes' === SST_Settings::get( 'log_requests' ) ) {
			/**
			 * Create a new log file every day.
			 */
			self::$handle = self::$handle . '-' . gmdate( 'Y-m-d' );
			self::$logger = function_exists( 'wc_get_logger' ) ? wc_get_logger() : new WC_Logger();
		}
	}

	/**
	 * Get log file path.
	 *
	 * @return string
	 * @since 5.0
	 */
	public static function get_log_path() {
		return wc_get_log_file_path( self::$handle );
	}

	/**
	 * Add a log entry.
	 *
	 * @param string $message Log message.
	 *
	 * @since 5.0
	 */
	public static function add( $message, $context = array() ) {
		if ( ! is_null( self::$logger ) ) {
			self::$logger->notice( $message, array( 
				'source' => self::$handle, 
				'_context' => (array) $context 
				) 
			);
		}
	}


	/**
	 * Log a debug message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Log context.
	 *
	 * @since 8.3.4
	 */
	public static function debug( $message, $context = array() ) {
		if ( ! is_null( self::$logger ) ) {
			self::$logger->debug( $message, array( 
				'source' => self::$handle, 
				'_context' => (array) $context 
				) 
			);
		}
	}

	/**
	 * Log an order message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Log context.
	 *
	 * @since 8.3.4
	 */
	public static function order_log( $message, $order_id, $context = array() ) {
		if ( ! is_null( self::$logger ) ) {
			self::$logger->debug( $message, array( 
				'source' => 'wootax-order-' . $order_id, 
				'_context' => (array) $context 
				) 
			);
		}
	}


}

SST_Logger::init();
