<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rate Limit.
 *
 * Tracks and enforces rate limits for TaxCloud lookup requests.
 *
 * @author  Simple Sales Tax
 * @package SST
 * @since   8.4.1
 */
class SST_Rate_Limit {

	/**
	 * Identifier for the request.
	 *
	 * @var string
	 */
	private $identifier;

	/**
	 * Constructor.
	 *
	 * @param string $identifier Identifier for the request (optional).
	 */
	public function __construct( $identifier = null ) {
		$this->identifier = $identifier ? $identifier : $this->get_identifier();
	}

	/**
	 * Get the identifier for the current customer context.
	 *
	 * @return string
	 */
	protected function get_identifier() {
		$scope = SST_Settings::get( 'taxcloud_rate_limit_scope', 'customer' );

		if ( 'global' === $scope ) {
			return 'global';
		}

		// Automatic (User ID → Customer ID → Session ID)
		if ( is_user_logged_in() ) {
			return 'user_' . get_current_user_id();
		}

		if ( ! is_null( WC()->session ) ) {
			$customer_id = WC()->session->get_customer_id();
			if ( $customer_id ) {
				return 'customer_' . $customer_id;
			}
		}

		return 'guest_' . md5( $_SERVER['REMOTE_ADDR'] );
	}

	/**
	 * Check if the rate limit has been reached.
	 *
	 * @return bool
	 */
	public function limit_reached() {
		// Rate limiting disabled?
		if ( 'yes' !== SST_Settings::get( 'enable_taxcloud_rate_limit', 'no' ) ) {
			return false;
		}

		// Admin or background jobs (cron) should bypass the limit.
		if ( is_admin() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return false;
		}

		$limit = SST_Settings::get( 'taxcloud_rate_limit_requests', '' );

		if ( '' === $limit || $limit <= 0 ) {
			return false;
		}

		$stats = $this->get_stats();

		if ( $stats['count'] >= (int) $limit ) {
			return true;
		}

		return false;
	}

	/**
	 * Increment the request counter.
	 */
	public function increment_count() {
		// Rate limiting disabled?
		if ( 'yes' !== SST_Settings::get( 'enable_taxcloud_rate_limit', 'no' ) ) {
			return;
		}

		$stats = $this->get_stats();
		$stats['count']++;

		$this->update_stats( $stats );
	}

	/**
	 * Get the current request stats for the identifier.
	 *
	 * @return array
	 */
	protected function get_stats() {
		$stats = get_transient( 'sst_rate_limit_' . $this->identifier );

		if ( false === $stats ) {
			$stats = array(
				'count'      => 0,
				'first_req'  => time(),
			);
		}

		return $stats;
	}

	/**
	 * Update the request stats for the identifier.
	 *
	 * @param array $stats
	 */
	protected function update_stats( $stats ) {
		$window_minutes = (int) SST_Settings::get( 'taxcloud_rate_limit_window', 60 );
		$window_seconds = $window_minutes * MINUTE_IN_SECONDS;
		$expiration     = $window_seconds - ( time() - $stats['first_req'] );

		if ( $expiration <= 0 ) {
			// Reset if expired
			$stats = array(
				'count'     => 1,
				'first_req' => time(),
			);
			$expiration = $window_seconds;
		}

		set_transient( 'sst_rate_limit_' . $this->identifier, $stats, $expiration );
	}

	/**
	 * Log a message when the limit is reached.
	 */
	public function log_limit_reached() {
		$message = sprintf(
			__( 'TaxCloud lookup rate limit reached for customer/session. ID: %s.', 'simple-sales-tax' ),
			$this->identifier,
		);

		SST_Logger::error( $message, array(
			'uri' => isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : 'N/A',
		) );
	}

}
