<?php
/**
 * SST Review Notice
 *
 * Handles WordPress.org review rating notice banner.
 * Self-contained component that only loads CSS and JS when the notice is required to be shown.
 *
 * @author  Simple Sales Tax
 * @package SST
 * @since   8.4.17
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SST_Review_Notice {

	/**
	 * Snooze duration in seconds (14 days).
	 *
	 * @var int
	 */
	const SNOOZE_DURATION = 1209600; // 14 * DAY_IN_SECONDS

	/**
	 * Minimum install delay in seconds before showing notice (7 days).
	 *
	 * @var int
	 */
	const INSTALL_DELAY = 604800; // 7 * DAY_IN_SECONDS

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'maybe_display_notice' ) );
	}

	/**
	 * Check whether the review notice should be displayed.
	 *
	 * @return bool
	 */
	public static function should_display() {
		// Only show to users with store management capabilities.
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		// Don't show in iframe requests.
		if ( defined( 'IFRAME_REQUEST' ) && IFRAME_REQUEST ) {
			return false;
		}

		// Check dismissal status.
		$status = get_option( 'wootax_review_notice_status', '' );
		if ( 'dismissed' === $status ) {
			return false;
		}

		if ( 'snoozed' === $status ) {
			$dismissed_until = (int) get_option( 'wootax_review_notice_dismissed_until', 0 );
			if ( time() < $dismissed_until ) {
				return false;
			}
		}

		// Check install date (7 days delay for new installations).
		$install_date = get_option( 'wootax_install_date' );
		if ( false === $install_date ) {
			if ( get_option( 'wootax_version' ) || get_option( 'woocommerce_wootax_settings' ) ) {
				// Existing user prior to this version: eligible immediately.
				$install_date = time() - self::INSTALL_DELAY;
				update_option( 'wootax_install_date', $install_date );
			} else {
				// Brand new installation: record timestamp and wait 7 days.
				$install_date = time();
				update_option( 'wootax_install_date', $install_date );
				return false;
			}
		}

		if ( ( time() - (int) $install_date ) < self::INSTALL_DELAY ) {
			return false;
		}

		return true;
	}

	/**
	 * Display the review notice if conditions are met.
	 */
	public static function maybe_display_notice() {
		if ( ! self::should_display() ) {
			return;
		}

		include __DIR__ . '/views/html-notice-review.php';
	}

	/**
	 * Handle AJAX actions for the review notice.
	 */
	public static function handle_ajax_action() {
		check_ajax_referer( 'sst_review_notice_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'simple-sales-tax' ) );
		}

		$notice_action = isset( $_POST['notice_action'] ) ? sanitize_text_field( wp_unslash( $_POST['notice_action'] ) ) : '';

		if ( in_array( $notice_action, array( 'rate', 'already_did' ), true ) ) {
			update_option( 'wootax_review_notice_status', 'dismissed' );
			delete_option( 'wootax_review_notice_dismissed_until' );
			wp_send_json_success( array( 'status' => 'dismissed' ) );
		} elseif ( in_array( $notice_action, array( 'maybe_later', 'dismiss' ), true ) ) {
			$snooze_until = time() + self::SNOOZE_DURATION;
			update_option( 'wootax_review_notice_status', 'snoozed' );
			update_option( 'wootax_review_notice_dismissed_until', $snooze_until );
			wp_send_json_success( array(
				'status'          => 'snoozed',
				'dismissed_until' => $snooze_until,
			) );
		}

		wp_send_json_error( __( 'Invalid action.', 'simple-sales-tax' ) );
	}

}
