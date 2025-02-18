<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Order Controller.
 *
 * Handles requests related to orders.
 *
 * @author  Simple Sales Tax
 * @package SST
 * @since   5.0
 */
class SST_Order_Controller {

	/**
	 * Constructor.
	 *
	 * @since 5.0
	 */
	public function __construct() {
//		add_action('woocommerce_admin_order_totals_after_tax',  array( $this, 'add_custom_tax_to_admin_totals' ), 10, 1);
		add_action( 'woocommerce_order_status_completed', array( $this, 'capture_order' ), 10, 2 );
		add_action( 'woocommerce_refund_created', array( $this, 'refund_order' ), 10, 2 );
		add_action( 'woocommerce_payment_complete', array( $this, 'maybe_capture_order' ) );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hide_order_item_meta' ) );
		add_filter( 'woocommerce_order_item_get_taxes', array( $this, 'fix_shipping_tax_issue' ), 10, 2 );
		add_action( 'woocommerce_before_order_object_save', array( $this, 'save_db_version' ) );
		add_action(
			'woocommerce_order_after_calculate_totals',
			array( $this, 'calculate_order_tax' ),
			10,
			2
		);
		add_filter(
			'woocommerce_order_data_store_cpt_get_orders_query',
			array( $this, 'handle_version_query_var' ),
			10,
			2
		);
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_certficiate' ),20 );
		add_filter(
			'woocommerce_order_hide_zero_taxes',
			array( $this, 'filter_hide_zero_taxes' )
		);
	}

	/**
	 * When an order is completed, mark it as captured in TaxCloud.
	 *
	 * @param int      $_order_id Order ID - unused.
	 * @param WC_Order $order     Order object.
	 *
	 * @return bool True on success, false on failure.
	 *
	 * @throws Exception If capture fails.
	 * @since 5.0
	 */
	public function capture_order( $_order_id, $order ) {
		$sst_order = new SST_Order( $order );

		return $sst_order->do_capture();
	}

	/**
	 * When a new refund is created, send a Returned request to TaxCloud.
	 *
	 * @param int   $refund_id ID of new refund.
	 * @param array $args      Refund arguments (see wc_create_refund()).
	 *
	 * @return bool True on success, false on failure.
	 *
	 * @throws Exception If refund fails.
	 * @since 5.0
	 */
	public function refund_order( $refund_id, $args ) {
		$order  = new SST_Order( $args['order_id'] );
		$refund = wc_get_order( $refund_id );
		$result = false;

		try {
			$result = $order->do_refund( $refund );

			if ( ! $result ) {
				$refund->delete( true );
			}
		} catch ( Exception $ex ) {
			$refund->delete( true );
			throw $ex; /* Let Woo handle the exception */
		}

		return $result;
	}

	/**
	 * If the "Capture Orders Immediately" option is enabled, capture orders
	 * when payment is received.
	 *
	 * @param int $order_id ID of order for which payment was just received.
	 *
	 * @throws Exception If attempt to capture order fails.
	 * @since 5.0
	 */
	public function maybe_capture_order( $order_id ) {
		if ( 'yes' === SST_Settings::get( 'capture_immediately' ) ) {
			$order = new SST_Order( $order_id );

			$order->do_capture();
		}
	}

	/**
	 * Hides Simple Sales Tax order item meta.
	 *
	 * @param array $to_hide Meta keys to hide.
	 *
	 * @return array
	 * @since 5.0
	 */
	public function hide_order_item_meta( $to_hide ) {
		$to_hide[] = '_wootax_tax_amount';
		$to_hide[] = '_wootax_location_id';
		$to_hide[] = '_wootax_index';

		return $to_hide;
	}

	/**
	 * Temporary fix for #50. Ensures that tax data for shipping items is
	 * correctly formatted.
	 *
	 * @param array         $taxes Tax data for WooCommerce order item.
	 * @param WC_Order_Item $item  WooCommerce order item object.
	 *
	 * @return array
	 * @since 5.0
	 */
	public function fix_shipping_tax_issue( $taxes, $item ) {
		if ( 'shipping' === $item->get_type() ) {
			if ( isset( $taxes['total'], $taxes['total']['total'] ) ) {
				unset( $taxes['total']['total'] );
			}
		}

		return $taxes;
	}

	/**
	 * Runs when an order is saved to set the order DB version.
	 *
	 * @param WC_Order $order The WooCommerce order that is about to be saved.
	 */
	public function save_db_version( $order ) {
		$db_version = $order->get_meta( '_wootax_db_version', true );

		if ( empty( $db_version ) ) {
			$order->update_meta_data( '_wootax_db_version', SST()->version );
		}
	}

	/**
	 * Calculates tax for orders created via WooCommerce REST API.
	 *
	 * @param bool     $and_taxes Whether to calculate taxes
	 * @param WC_Order $order     Order object
	 */
	public function calculate_order_tax( $and_taxes, $order ) {
		// Note: sst_order_calculate_taxes sets `and_taxes` to false so
		// the `and_taxes` check prevents infinite recursion
		$should_calculate = (
			WC()->is_rest_api_request()
			&& 'rest-api' === $order->get_created_via()
			&& $and_taxes
		);

		if ( $should_calculate ) {
			sst_order_calculate_taxes( $order );
		}
	}

	/**
	 * Adds support for querying orders by DB version and TaxCloud status with wc_get_orders()
	 *
	 * @param array $query      Args for WP_Query.
	 * @param array $query_vars Query vars from WC_Order_Query.
	 *
	 * @return array
	 */
	public function handle_version_query_var( $query, $query_vars ) {
		if ( ! is_array( $query['meta_query'] ) ) {
			$query['meta_query'] = array();
		}

		if ( ! empty( $query_vars['wootax_version'] ) ) {
			$query['meta_query'][] = array(
				'key'   => '_wootax_db_version',
				'value' => esc_attr( $query_vars['wootax_version'] ),
			);
		}

		if ( ! empty( $query_vars['wootax_status'] ) ) {
			$query['meta_query'][] = array(
				'key'   => '_wootax_status',
				'value' => esc_attr( $query_vars['wootax_status'] ),
			);
		}

		return $query;
	}

	/**
	 * Saves the exemption certificate for an order.
	 *
	 * @param int $order_id Order post ID.
	 */
	public function save_certficiate( $order_id ) {
		$order       = new SST_Order( $order_id );
		$is_editable = 'pending' === $order->get_taxcloud_status();

		if ( ! $is_editable ) {
			return;
		}

		$certificate_id = sanitize_text_field(
			wp_unslash( $_POST['exempt_cert'] ?? '' )
		);
		$order->set_certificate_id( $certificate_id );
		$order->save();
//        if (is_admin()) {
//			$orderWC = wc_get_order($order_id);
//            sst_order_calculate_taxes( $orderWC );
//        }
	}

	/**
	 * Filters woocommerce_order_hide_zero_taxes so zero taxes are
	 * shown if the SST "Show Zero Tax" setting is enabled.
	 *
	 * @return bool
	 */
	public function filter_hide_zero_taxes() {
		return 'true' !== SST_Settings::get( 'show_zero_tax' );
	}

}

new SST_Order_Controller();
