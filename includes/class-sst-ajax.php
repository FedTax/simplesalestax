<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * SST Ajax.
 *
 * Ajax functions.
 *
 * @author  Simple Sales Tax
 * @package SST
 * @since   5.0
 */
class SST_Ajax {

	/**
	 * AJAX Hooks.
	 *
	 * @var array
	 * @since 5.0
	 */
	private static $hooks = array(
		'sst_verify_taxcloud'         => false,
		'sst_delete_certificate'      => false,
		'sst_add_certificate'         => false,
		'sst_refresh_certificates'    => false,
		'woocommerce_calc_line_taxes' => false,
		'sst_get_certificates'        => false,
		'sst_dismiss_taxcloud_notice'	=> false,
		'sst_get_order_log'						=> false,
		'sst_update_data_mover'				=> false,
	);

	/**
	 * Initialize hooks.
	 *
	 * @since 5.0
	 */
	public static function init() {
		foreach ( self::$hooks as $hook => $nopriv ) {
			$function = str_replace( array( 'woocommerce_', 'sst_' ), '', $hook );

			/* If we are overriding a woo hook, give ours higher priority */
			if ( 0 === strpos( $hook, 'woocommerce_' ) ) {
				$priority = 1;
			} else {
				$priority = 10;
			}

			add_action( "wp_ajax_$hook", array( __CLASS__, $function ), $priority );

			if ( $nopriv ) {
				add_action( "wp_ajax_nopriv_$hook", array( __CLASS__, $function ), $priority );
			}
		}
	}

	/**
	 * Verify the user's TaxCloud API Login ID and API Key.
	 *
	 * @since 5.0
	 */
	public static function verify_taxcloud() {
		$taxcloud_id  = '';
		$taxcloud_key = '';

		// Verify nonce.
		check_ajax_referer( 'sst_verify_taxcloud_nonce' );

		if ( isset( $_POST['wootax_tc_id'] ) ) {
			$taxcloud_id = sanitize_text_field( wp_unslash( $_POST['wootax_tc_id'] ) ); // phpcs:ignore WordPress.CSRF.NonceVerification
		}
		if ( isset( $_POST['wootax_tc_key'] ) ) {
			$taxcloud_key = sanitize_text_field( wp_unslash( $_POST['wootax_tc_key'] ) ); // phpcs:ignore WordPress.CSRF.NonceVerification
		}

		if ( empty( $taxcloud_id ) || empty( $taxcloud_key ) ) {
			wp_send_json_error();
		} else {
			try {
				TaxCloud()->Ping( new TaxCloud\Request\Ping( $taxcloud_id, $taxcloud_key ) );

				// Ping successful, update data mover settings
				SST_TaxCloud_V3_API::update_data_mover_settings( $taxcloud_id, $taxcloud_key );

				wp_send_json_success( array(
					'connection_id' => SST_Settings::get( 'tc_connection_id' ),
				) );
			} catch ( Exception $ex ) {
				wp_send_json_error( $ex->getMessage() );
			}
		}
	}

	/**
	 * Respond when user requests to delete a certificate.
	 *
	 * @since 5.0
	 */
	public static function delete_certificate() {
		$nonce = sanitize_text_field(
			wp_unslash( $_POST['nonce'] ?? '' )
		);

		if ( ! wp_verify_nonce( $nonce, 'sst_delete_certificate' ) ) {
			return;
		}

		$certificate_id = sanitize_text_field(
			wp_unslash( $_POST['certificate_id'] ?? '' )
		);
		$user_id        = absint(
			wp_unslash( $_POST['user_id'] ?? 0 )
		);

		try {
			SST_Certificates::delete_certificate( $certificate_id, $user_id );

			wp_send_json_success(
				array( 'certificates' => SST_Certificates::get_certificates_formatted( $user_id ) )
			);
		} catch ( Exception $ex ) { /* Failed to delete */
			wp_send_json_error( $ex->getMessage() );
		}
	}

	/**
	 * Refresh (delete cache of) exemption certificates for the customer.
	 *
	 * @since 8.4.0
	 */
	public static function refresh_certificates() {
		$nonce = sanitize_text_field(
			wp_unslash( $_POST['nonce'] ?? '' )
		);

		if ( ! wp_verify_nonce( $nonce, 'sst_refresh_certificates' ) ) {
			wp_send_json_error( __( 'Invalid nonce.', 'simple-sales-tax' ) );
		}

		$user_id = absint(
			wp_unslash( $_POST['user_id'] ?? 0 )
		);

		if ( 0 === $user_id ) {
			$user_id = get_current_user_id();
		}

		if ( ! current_user_can( 'edit_user', $user_id ) && $user_id !== get_current_user_id() ) {
			wp_send_json_error( __( 'Unauthorized.', 'simple-sales-tax' ) );
		}

		try {
			SST_Certificates::delete_certificates( $user_id );
			$certificates = SST_Certificates::get_certificates_formatted( $user_id );
			wp_send_json_success( array(
				'certificates' => $certificates,
			) );
		} catch ( Exception $ex ) {
			SST_Logger::add( sprintf( 'refresh_certificates exception for user_id=%d: %s', $user_id, $ex->getMessage() ) );
			wp_send_json_error( $ex->getMessage() );
		}
	}

	/**
	 * Add an exemption certificate for the customer.
	 *
	 * NOTE: Single purchase exemption certificates not supported at this
	 * time.
	 *
	 * @since 5.0
	 */
	public static function add_certificate() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		// Handle invalid requests.
		if ( ! wp_verify_nonce( $nonce, 'sst_add_certificate' ) ) {
			return;
		}

		if ( ! isset( $_POST['address'], $_POST['certificate'] ) ) {
			wp_send_json_error( __( 'Invalid request.', 'simple-sales-tax' ) );
		}

		// Get data.
		$certificate = array_map(
			'sanitize_text_field',
			wp_unslash( $_POST['certificate'] )
		); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$address     = array_map(
			'sanitize_text_field',
			wp_unslash( $_POST['address'] )
		); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$user_id     = absint( $_POST['user_id'] ?? 0 );

		// Add certificate.
		try {
			$certificate_id = SST_Certificates::add_certificate(
				$certificate,
				$address,
				$user_id
			);

			wp_send_json_success(
				array(
					'certificate_id' => $certificate_id,
					'certificates'   => SST_Certificates::get_certificates_formatted( $user_id ),
				)
			);
		} catch ( Throwable $ex ) {
			wp_send_json_error( $ex->getMessage() );
		}
	}

	/**
	 * Lists all exemption certificates available for a customer.
	 * Used to populate the Exemption Certificate dropdown on the
	 * Edit Order screen.
	 *
	 * @since 7.0.0
	 */
	public static function get_certificates() {
		check_ajax_referer( 'sst_get_certificates', 'nonce' );

		$user_id = 0;
		if ( isset( $_REQUEST['customerId'] ) ) {
			$user_id = absint( wp_unslash( $_REQUEST['customerId'] ) );
		} elseif ( isset( $_REQUEST['user_id'] ) ) {
			$user_id = absint( wp_unslash( $_REQUEST['user_id'] ) );
		}

		if ( 0 === $user_id ) {
			$user_id = get_current_user_id();
		}

		$certificates = array();

		if ( ( $user_id && $user_id === get_current_user_id() ) || current_user_can( 'edit_user', $user_id ) ) {
			$certificates = SST_Certificates::get_certificates_formatted(
				$user_id
			);
		}

		wp_send_json_success( $certificates );
	}

	/**
	 * Gets a string describing an exemption certificate.
	 *
	 * @param array $certificate Certificate data.
	 *
	 * @return string
	 */
	protected static function get_certificate_text( $certificate ) {
		return $certificate['CertificateID'];
	}

	/**
	 * Recalculate sales tax via AJAX.
	 *
	 * @since 4.2
	 */
	public static function calc_line_taxes() {
		check_ajax_referer( 'calc-totals', 'security' );

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( -1 );
		}

		if ( ! isset( $_POST['order_id'], $_POST['country'], $_POST['state'], $_POST['postcode'], $_POST['city'], $_POST['items'] ) ) {
			wp_die( -1 );
		}

		$items          = array();
		$order_id       = absint( $_POST['order_id'] );
		$country        = strtoupper( sanitize_text_field( wp_unslash( $_POST['country'] ) ) );
		$state          = strtoupper( sanitize_text_field( wp_unslash( $_POST['state'] ) ) );
		$postcode       = strtoupper( sanitize_text_field( wp_unslash( $_POST['postcode'] ) ) );
		$city           = sanitize_text_field( wp_unslash( $_POST['city'] ) );
		$certificate_id = sanitize_text_field(
			wp_unslash( $_POST['exemption_certificate'] ?? '' )
		);

		// Let Woo take the reins if the customer is international.
		if ( 'US' !== $country ) {
			return;
		}

		// Parse jQuery serialized items.
		$raw_items = sanitize_text_field( wp_unslash( $_POST['items'] ) );
		parse_str( $raw_items, $items );

		$items = wc_clean( $items );

		// Save items and recalc taxes.
		wc_save_order_items( $order_id, $items );

		$order          = wc_get_order( $order_id );
		$posted_address = compact(
			'country',
			'state',
			'city',
			'postcode'
		);

		self::ensure_order_address( $order, 'billing', $posted_address );
		self::ensure_order_address( $order, 'shipping', $posted_address );

		$order->update_meta_data( '_wootax_exempt_cert', $certificate_id );

		$result = sst_order_calculate_taxes( $order );

		if ( is_wp_error( $result ) ) {
			wp_die( $result->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput
		}

		include WC()->plugin_path() . '/includes/admin/meta-boxes/views/html-order-items.php';

		wp_die();
	}

	/**
	 * Ensures that an order has an address set before taxes are calculated.
	 *
	 * @param WC_Order $order   Order object.
	 * @param string   $type    Address type. Can be 'billing' or 'shipping'.
	 * @param array    $address POSTed address data.
	 */
	protected static function ensure_order_address( $order, $type, $address ) {
		try {
			foreach ( $address as $field => $value ) {
				if ( empty( $order->{"get_{$type}_{$field}"}() ) ) {
					$order->{"set_{$type}_{$field}"}( $value );
				}
			}
		} catch ( WC_Data_Exception $ex ) {
			wc_get_logger()->error(
				sprintf( 'Failed to set %s address for order #%d: %s', $type, $order->get_id(), $ex->getMessage() )
			);
		}
	}

	/**
	 * Dismiss a TaxCloud admin notice.
	 *
	 * @since 8.3.6
	 */
	public static function dismiss_taxcloud_notice() {
		check_ajax_referer( 'dismiss_taxcloud_notice', 'nonce' );
		$id = sanitize_text_field( wp_unslash( $_POST['notice_id'] ) );
		$dismissed_notices = SST_Settings::get( 'dismissed_notices', [] );
		if ( ! in_array( $id, $dismissed_notices, true ) ) {
			$dismissed_notices[] = $id;
			SST_Settings::set( 'dismissed_notices', $dismissed_notices );
		}
		wp_send_json_success( SST_Settings::get( 'dismissed_notices', [] ) );
	}

	/**
	 * Get the order log.
	 *
	 * @since 8.3.5
	 */
	public static function get_order_log() {
		// Verify nonce.
		check_ajax_referer( 'sst-debug-order-nonce' );

		// Get the order ID.
		$order_id = (int) sanitize_text_field( wp_unslash( $_POST['order_id'] ) );

		// Order object.
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( __( 'Invalid order ID.', 'simple-sales-tax' ) );
		}

		// Meta keys
		$meta_keys = [
			'_wootax_status',
			'_wootax_db_version',
			'_wootax_exempt_cert',
			'_shipping_address_index',
			'_wootax_packages',
		];

		// Get Woo Order status
		$order_meta['order_status'] = $order->get_status();

		// Get customer ID
		$order_meta['customer_id'] = $order->get_customer_id();

		// Totals
		$order_meta['total']        = $order->get_total();
		$order_meta['shipping']     = $order->get_shipping_total();
		$order_meta['tax']          = $order->get_total_tax();

		// Get order meta.
		foreach ( $meta_keys as $key ) {
			$order_meta[ $key ] = maybe_unserialize( $order->get_meta( $key, true ) );
		}

		// Logging
		SST_Logger::order_log( __( 'View Order Debug Log triggered.', 'simple-sales-tax' ), $order_id, $order_meta );

		wp_send_json_success();
	}

	/**
	 * Update Data Mover settings.
	 *
	 * @since 8.3.5
	 */
	public static function update_data_mover() {
		// Verify nonce.
		check_ajax_referer( 'sst-update-data-mover-nonce' );

		// Update data mover settings
		SST_TaxCloud_V3_API::update_data_mover_settings();

		// Get data mover settings
		$data_mover       = SST_Settings::get( 'data_mover', false );
		$integration_mode = $data_mover == false ? __( 'Real Time', 'simple-sales-tax' ) : __( 'Data Import', 'simple-sales-tax' );

		// Response
		wp_send_json_success( [
			'integration_mode' => $integration_mode,
			'data_mover'       => $data_mover,
			'connection_id'    => SST_Settings::get( 'tc_connection_id' ),
		] );
	}

}

SST_Ajax::init();
