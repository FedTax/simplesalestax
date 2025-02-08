<?php
/**
 * Simple Sales Tax WCFM Integration.
 *
 * Integrates Simple Sales Tax with WooCommerce Frontend Manager.
 *
 * @package simple-sales-tax
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( SST_FILE ) . '/includes/abstracts/class-sst-marketplace-integration.php';

/**
 * Class SST_WCFM
 */
class SST_WCFM extends SST_Marketplace_Integration {

	/**
	 * Singleton instance.
	 *
	 * @var SST_WCFM
	 */
	protected static $instance = null;

	/**
	 * Minimum supported version of WCFM.
	 *
	 * @var string
	 */
	protected $min_version = '6.5.0';

	/**
	 * Returns the singleton instance of this class.
	 *
	 * @return SST_WCFM
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * SST_WCFM constructor.
	 */
	private function __construct() {
		global $WCFMmp;

		// Bail if WCFM is not installed and activated.
		if ( ! defined( 'WCFM_VERSION' ) ) {
			return;
		}

		// Allow developers to disable this integration.
		if ( ! apply_filters( 'wootax_wcfm_integration_enabled', true ) ) {
			return;
		}

		// Print an error if the WCFM version is not supported.
		if ( version_compare( WCFM_VERSION, $this->min_version, '<' ) ) {
			add_action( 'admin_notices', array( $this, 'wcfm_version_notice' ) );
			return;
		}

		// Hide the default WCFM tax fields.
		add_filter( 'wcfm_product_simple_fields_tax', '__return_empty_array' );
		add_filter( 'show_shipping_zone_tax', '__return_false' );
		add_action( 'wcfm_products_manage_tax_end', array( $this, 'output_tic_select' ) );
		add_filter( 'wcfm_variation_edit_data', array( $this, 'add_tic_to_variation_edit_data' ), 10, 3 );
		add_filter( 'wcfm_product_manage_fields_variations', array( $this, 'filter_variation_form_fields' ), 20, 2 );
		add_filter( 'wootax_tic_select_init_events', array( $this, 'filter_tic_select_init_events' ) );
		add_action( 'after_wcfm_products_manage_meta_save', array( $this, 'save_product_tic' ), 10, 2 );
		add_action( 'after_wcfm_product_variation_meta_save', array( $this, 'save_variation_tic' ), 10, 3 );
		add_action( 'after_wcfm_orders_edit', array( $this, 'recalculate_order_taxes' ), 10, 2 );

		// Don't add package filters unless WCFM Marketplace is installed to
		// avoid adding filters in multiple integrations (WCFM & Dokan/WCV).
		// This assumes at most one marketplace plugin is active at any time.
		$this->should_add_package_filters = isset( $WCFMmp );

		parent::__construct();
	}

	/**
	 * Admin notice displayed when an unsupported version of WCFM is detected.
	 */
	public function wcfm_version_notice() {
		?>
		<div class="notice notice-error">
			<p>
				<?php
				printf(
					/* translators: minimum supported WCFM version */
					esc_html__(
						'Simple Sales Tax does not support the installed version of WCFM. WCFM %s+ is required.',
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
	 * Adds the variation TIC to the variation data for the product edit form.
	 *
	 * @param array $variations    Variation data.
	 * @param int   $variation_id  Variation post ID.
	 * @param int   $variation_key Variation key.
	 *
	 * @return array
	 */
	public function add_tic_to_variation_edit_data( $variations, $variation_id, $variation_key ) {
		$variations[ $variation_key ]['wootax_tic'] = get_post_meta( $variation_id, 'wootax_tic', true );

		return $variations;
	}

	/**
	 * Registers the TIC field for variations and removes the Tax Class field.
	 *
	 * @param array $variation_fields Registered WCFM variation form fields.
	 * @param array $variations       Variations.
	 *
	 * @return array
	 */
	public function filter_variation_form_fields( $variation_fields, $variations ) {
		unset( $variation_fields['tax_class'] );

		$select_args = array(
			'button_class' => 'wcfm_submit_button',
			'field_class'  => 'multi_input_block_element',
			'default_text' => __( 'Same as parent', 'simple-sales-tax' ),
		);

		ob_start();
		sst_output_tic_select_field( $select_args );
		$select_html = ob_get_clean();

		$variation_fields['wootax_tic'] = array(
			'label'       => __( 'Taxability Information Code', 'simple-sales-tax' ),
			'hints'       => sst_get_tic_select_help_text(),
			'type'        => 'html',
			'class'       => 'wcfm-html wcfm_ele wcfm-sst-html variable variable-subscription pw-gift-card',
			'label_class' => 'wcfm_title html_title',
			'value'       => $select_html,
		);

		// Load script to set values and names for variation TIC inputs on load.
		wp_enqueue_script( 'sst-wcfm-tic-select' );
		$tic_select_data = array(
			'variation_tics' => wp_list_pluck( $variations, 'wootax_tic' ),
		);
		wp_localize_script( 'sst-wcfm-tic-select', 'wcfm_tic_select_data', $tic_select_data );

		return $variation_fields;
	}

	/**
	 * Outputs the TIC select field for a product.
	 *
	 * @param int $product_id ID of the product being edited.
	 */
	public function output_tic_select( $product_id ) {
		global $WCFM;

		$select_args = array(
			'button_class' => 'wcfm_submit_button',
			'product_id'   => $product_id,
		);

		if ( isset( $_REQUEST['wootax_tic'][ $product_id ] ) ) {
			$select_args['value'] = sanitize_text_field(
				$_REQUEST['wootax_tic'][ $product_id ]
			);
		}

		ob_start();
		sst_output_tic_select_field( $select_args );
		$select_html = ob_get_clean();

		$field_args = array(
			'label'       => __( 'Taxability Information Code', 'simple-sales-tax' ),
			'hints'       => sst_get_tic_select_help_text(),
			'type'        => 'html',
			'class'       => 'wcfm-html wcfm-sst-html',
			'label_class' => 'wcfm_title html_title',
			'value'       => $select_html,
		);

		$WCFM->wcfm_fields->wcfm_generate_form_field( array( 'wootax_tic' => $field_args ) );
	}

	/**
	 * Checks whether a user is a seller.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return bool
	 */
	public function is_user_seller( $user_id ) {
		return wcfm_is_vendor( $user_id );
	}

	/**
	 * Adds wcfm_variation_added to the list of JS events that trigger TIC
	 * select initialization.
	 *
	 * @param string $events JS events that trigger TIC select initialization.
	 *
	 * @return string
	 */
	public function filter_tic_select_init_events( $events ) {
		return trim( "{$events} wcfm_variation_added" );
	}

	/**
	 * Saves the TIC for a product.
	 *
	 * @param int   $product_id ID of product being saved.
	 * @param array $form_data  Product form data.
	 */
	public function save_product_tic( $product_id, $form_data ) {
		if ( isset( $form_data['wootax_tic'] ) ) {
			$_REQUEST['wootax_tic'] = $form_data['wootax_tic'];
		}

		$this->save_tic( $product_id );
	}

	/**
	 * Saves the TIC for a variation.
	 *
	 * @param int   $product_id     ID of product being saved.
	 * @param int   $variation_id   Variation ID.
	 * @param array $variation_data Variation data.
	 *
	 * @return array
	 */
	public function save_variation_tic( $product_id, $variation_id, $variation_data ) {
		if ( isset( $variation_data['wootax_tic'] ) ) {
			$_REQUEST['wootax_tic'][ $variation_id ] = $variation_data['wootax_tic'];
		}

		$this->save_tic( $variation_id );
	}

	/**
	 * Recalculates the tax for an order after it's edited by the admin or a vendor.
	 *
	 * @param int      $order_id Order ID.
	 * @param WC_Order $order    Order object.
	 */
	public function recalculate_order_taxes( $order_id, $order ) {
		sst_order_calculate_taxes( $order );
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
			'country'   => '',
			'address'   => '',
			'address_2' => '',
			'city'      => '',
			'state'     => '',
			'postcode'  => '',
		);

		if ( apply_filters( 'wcfm_is_allow_store_address', true ) ) {
			$vendor_data = get_user_meta( $seller_id, 'wcfmmp_profile_settings', true );

			if ( isset( $vendor_data['address'] ) ) {
				$address = array(
					'country'   => isset( $vendor_data['address']['country'] ) ? $vendor_data['address']['country'] : '',
					'address'   => isset( $vendor_data['address']['street_1'] ) ? $vendor_data['address']['street_1'] : '',
					'address_2' => isset( $vendor_data['address']['street_2'] ) ? $vendor_data['address']['street_2'] : '',
					'city'      => isset( $vendor_data['address']['city'] ) ? $vendor_data['address']['city'] : '',
					'state'     => isset( $vendor_data['address']['state'] ) ? $vendor_data['address']['state'] : '',
					'postcode'  => isset( $vendor_data['address']['zip'] ) ? $vendor_data['address']['zip'] : '',
				);
			}
		}

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

}

SST_WCFM::instance();
