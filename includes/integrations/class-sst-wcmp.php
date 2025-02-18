<?php
/**
 * Simple Sales Tax WCMp Integration.
 *
 * Integrates Simple Sales Tax with WC Marketplace.
 *
 * @package simple-sales-tax
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( SST_FILE ) . '/includes/abstracts/class-sst-marketplace-integration.php';

/**
 * Class SST_WCMP
 */
class SST_WCMP extends SST_Marketplace_Integration {

	/**
	 * Singleton instance.
	 *
	 * @var SST_WCMP
	 */
	protected static $instance = null;

	/**
	 * Minimum supported version of WCMp.
	 *
	 * @var string
	 */
	protected $min_version = '3.4.0';

	/**
	 * Returns the singleton instance of this class.
	 *
	 * @return SST_WCMP
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * SST_WCMP constructor.
	 */
	private function __construct() {
		// Bail if WCMp is not installed and activated.
		if ( ! defined( 'WCMp_PLUGIN_VERSION' ) ) {
			return;
		}

		// Allow developers to disable this integration.
		if ( ! apply_filters( 'wootax_wcmp_integration_enabled', true ) ) {
			return;
		}

		// Print an error if the Dokan version is not supported.
		if ( version_compare( WCMp_PLUGIN_VERSION, $this->min_version, '<' ) ) {
			add_action( 'admin_notices', array( $this, 'wcmp_version_notice' ) );
			return;
		}

		add_filter( 'wcmp_can_vendor_configure_tax', '__return_false' );
		add_filter( 'afm_can_vendor_configure_tax', '__return_false' );
		add_action( 'wcmp_afm_after_general_product_data', array( $this, 'output_tic_select' ) );
		add_action( 'wcmp_process_product_object', array( $this, 'save_product_tic' ) );
		add_filter( 'sst_tic_select_button_classes', array( $this, 'filter_tic_button_classes' ) );
		add_filter( 'sst_tic_select_input_classes', array( $this, 'filter_tic_input_classes' ) );
		add_action( 'wcmp_afm_product_after_variable_attributes', array( $this, 'output_variation_tic_select' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_tic' ) );
		add_action( 'woocommerce_saved_order_items', array( $this, 'recalculate_sub_order_taxes' ), 20 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'recalculate_sub_order_taxes' ), 20 );
		add_action( 'woocommerce_rest_insert_shop_order_object',array( $this,'recalculate_sub_order_taxes' ), 20 );

		parent::__construct();
	}

	/**
	 * Admin notice displayed when an unsupported version of WCMp is detected.
	 */
	public function wcmp_version_notice() {
		?>
		<div class="notice notice-error">
			<p>
				<?php
				printf(
					/* translators: minimum supported WCMp version */
					esc_html__(
						'Simple Sales Tax does not support the installed version of WC Marketplace. WC Marketplace %s+ is required.',
						'simple-sales-tax'
					),
					esc_html( $this->min_version )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Outputs the TIC select field for a variation.
	 *
	 * @param int     $loop           Variation index.
	 * @param array   $variation_data Variation data.
	 * @param WP_Post $variation      Variation post object.
	 */
	public function output_variation_tic_select( $loop, $variation_data, $variation ) {
		$variation_id = $variation->ID;
		$select_args  = array(
			'button_class' => 'btn btn-default',
			'product_id'   => $variation_id,
		);

		if ( isset( $_REQUEST['wootax_tic'][ $variation_id ] ) ) {
			$select_args['value'] = sanitize_text_field(
				$_REQUEST['wootax_tic'][ $variation_id ]
			);
		}

		?>
		<div class="row">
			<div class="col-md-12">
				<div class="form-group">
					<label class="control-label col-md-12" for="wootax_tic[<?php echo absint( $product_id ); ?>">
						<?php esc_html_e( 'Taxability Information Code', 'simple-sales-tax' ); ?>
					</label>
					<div class="col-md-12">
						<?php sst_output_tic_select_field( $select_args ); ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Outputs the TIC select field for a product.
	 *
	 * @param int $product_id ID of the product being edited.
	 */
	public function output_tic_select( $product_id ) {
		$select_args = array(
			'button_class' => 'btn btn-default',
			'product_id'   => $product_id,
		);

		if ( isset( $_REQUEST['wootax_tic'][ $product_id ] ) ) {
			$select_args['value'] = sanitize_text_field(
				$_REQUEST['wootax_tic'][ $product_id ]
			);
		}

		?>
		<div class="form-group">
			<label class="control-label col-sm-3 col-md-3" for="wootax_tic[<?php echo absint( $product_id ); ?>">
				<?php esc_html_e( 'Taxability Information Code', 'simple-sales-tax' ); ?>
			</label>
			<div class="col-md-6 col-sm-9">
				<?php sst_output_tic_select_field( $select_args ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Checks whether a user is a seller.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return bool
	 */
	public function is_user_seller( $user_id ) {
		return is_user_wcmp_vendor( $user_id );
	}

	/**
	 * Saves the TIC for a product.
	 *
	 * @param WC_Product $product   The product being saved.
	 */
	public function save_product_tic( $product ) {
		$this->save_tic( $product->get_id() );
	}

	/**
	 * Get the origin address for a seller.
	 *
	 * @param int $seller_id Seller user ID.
	 *
	 * @return SST_Origin_Address
	 */
	public function get_seller_address( $seller_id ) {
		$address = array(
			'country'   => get_user_meta( $seller_id, '_vendor_country', true ),
			'address'   => get_user_meta( $seller_id, '_vendor_address_1', true ),
			'address_2' => get_user_meta( $seller_id, '_vendor_address_2', true ),
			'city'      => get_user_meta( $seller_id, '_vendor_city', true ),
			'state'     => get_user_meta( $seller_id, '_vendor_state', true ),
			'postcode'  => get_user_meta( $seller_id, '_vendor_postcode', true ),
		);

		try {
			return new SST_Origin_Address(
				"S-{$seller_id}",
				false,
				$address['address'],
				$address['address_2'],
				$address['city'],
				$address['state'],
				$address['postcode']
			);
		} catch ( Exception $ex ) {
			SST_Logger::add( "Error encountered while constructing origin address for seller {$seller_id}: {$ex->getMessage()}. Falling back to default store origin." );

			return SST_Addresses::get_default_address();
		}
	}

	/**
	 * Adds the WCMp button classes to the TIC select button.
	 *
	 * @return array List of classes to add to buttons in the TIC select modal.
	 */
	public function filter_tic_button_classes() {
		return array(
			'btn',
			'btn-default',
		);
	}

	/**
	 * Adds the WCMp input classes to the TIC select input.
	 *
	 * @return array List of classes to add to the text input in the TIC select modal.
	 */
	public function filter_tic_input_classes() {
		return array( 'form-control' );
	}

	/**
	 * Recalculates the taxes for WCMp sub orders after they're created.
	 *
	 * @param int|WC_Order $order Parent order or parent order ID.
	 */
	public function recalculate_sub_order_taxes( $order ) {
		if ( ! $this->order_has_sub_orders( $order ) ) {
			return;
		}

		$order_id = $order;

		if ( $order instanceof WC_Order ) {
			$order_id = $order->get_id();
		}

		$sub_orders = $this->get_wcmp_suborders( $order_id );

		foreach ( $sub_orders as $order ) {
			sst_order_calculate_taxes( $order );
		}
	}

	/**
	 * Get the sub orders for an order.
	 *
	 * We implement our own version of this function for compatibility
	 * with versions of WCMp where `get_wcmp_suborders` was not defined.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return array
	 */
	protected function get_wcmp_suborders( $order_id ) {
		$args = array(
			'post_parent' => $order_id,
			'post_type'   => 'shop_order',
			'numberposts' => -1,
			'post_status' => 'any',
			'fields'      => 'ids',
		);

		return array_map( 'wc_get_order', get_posts( $args ) );
	}

	/**
	 * Checks whether an order has sub-orders.
	 *
	 * @param int|WC_Order $order Order or order ID.
	 *
	 * @return bool
	 */
	protected function order_has_sub_orders( $order ) {
		$has_sub_orders = false;

		if ( $order instanceof WC_Order ) {
			$has_sub_orders = $order->get_meta( 'has_wcmp_sub_order' );
		} elseif ( is_numeric( $order ) ) {
			$has_sub_orders = get_post_meta( $order, 'has_wcmp_sub_order', true );
		}

		return $has_sub_orders;
	}

}

SST_WCMP::instance();
