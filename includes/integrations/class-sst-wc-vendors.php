<?php
/**
 * Simple Sales Tax WC Vendors Pro Integration.
 *
 * Integrates Simple Sales Tax with WC Vendors Pro.
 *
 * @package simple-sales-tax
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( SST_FILE ) . '/includes/abstracts/class-sst-marketplace-integration.php';

/**
 * Class SST_WC_Vendors
 */
class SST_WC_Vendors extends SST_Marketplace_Integration {

	/**
	 * Singleton instance.
	 *
	 * @var SST_WC_Vendors
	 */
	protected static $instance = null;

	/**
	 * Minimum supported version of WC Vendors Pro.
	 *
	 * @var string
	 */
	protected $min_version = '1.5.8';

	/**
	 * Returns the singleton instance of this class.
	 *
	 * @return SST_WC_Vendors
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * SST_WC_Vendors constructor.
	 */
	private function __construct() {
		// Bail if WC Vendors Pro is not installed and activated.
		if ( ! defined( 'WCV_PRO_VERSION' ) ) {
			return;
		}

		// Allow developers to disable this integration.
		if ( ! apply_filters( 'wootax_wc_vendors_integration_enabled', true ) ) {
			return;
		}

		// Print an error if the WC Vendors Pro version is not supported.
		if ( version_compare( WCV_PRO_VERSION, $this->min_version, '<' ) ) {
			add_action( 'admin_notices', array( $this, 'wcv_pro_version_notice' ) );
			return;
		}

		add_action( 'wcv_after_product_details', array( $this, 'output_tic_select' ) );
		add_action( 'wcv_product_variation_before_tax_class', array( $this, 'output_tic_select_for_variation' ), 10, 2 );
		add_action( 'wcvendors_before_product_form', array( $this, 'hide_tax_fields' ) );
		add_action( 'wcv_save_product', array( $this, 'save_tic' ) );
		add_action( 'wcv_save_product_variation', array( $this, 'save_tic' ) );

		parent::__construct();
	}

	/**
	 * Admin notice displayed when an unsupported version of WC Vendors Pro
	 * is detected.
	 */
	public function wcv_pro_version_notice() {
		?>
		<div class="notice notice-error">
			<p>
				<?php
				printf(
					/* translators: minimum supported WC Vendors Pro version */
					esc_html__(
						'Simple Sales Tax does not support the installed version of WC Vendors Pro. WC Vendors Pro %s+ is required.',
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
	 * @param int $loop         Variation loop index.
	 * @param int $variation_id Variation ID.
	 */
	public function output_tic_select_for_variation( $loop, $variation_id ) {
		?>
		<div class="wcv-cols-group wcv-horizontal-gutters">
			<div class="all-100">
				<?php $this->output_tic_select( $variation_id ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Outputs the TIC select field for a product.
	 *
	 * @param int $product_id ID of post being edited.
	 */
	public function output_tic_select( $product_id ) {
		$select_args = array(
			'button_class' => 'wcv-button',
			'product_id'   => $product_id,
		);

		wp_enqueue_script( 'sst-wcv-tic-select' );

		?>
		<div class="control-group sst-tic-select-wrap">
			<label for="wootax_tic[<?php echo esc_attr( $product_id ); ?>]">
				<?php esc_html_e( 'Taxability Information Code (TIC)', 'simple-sales-tax' ); ?>
			</label>
			<div class="control">
				<?php sst_output_tic_select_field( $select_args ); ?>
			</div>
			<p class="tip">
				<?php echo esc_html( sst_get_tic_select_help_text() ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Adds some filters to hide the tax fields in the product form.
	 */
	public function hide_tax_fields() {
		add_filter( 'pre_option_wcvendors_hide_product_variations_tax_class', array( $this, 'return_yes' ) );
		add_filter( 'pre_option_wcvendors_hide_product_general_tax', array( $this, 'return_yes' ) );
	}

	/**
	 * Returns yes. Used to hide the Tax Class and Tax Status fields
	 * by setting the value for the corresponding "hide" options to
	 * 'yes'.
	 *
	 * @return string
	 */
	public function return_yes() {
		return 'yes';
	}

	/**
	 * Checks whether a user is a seller.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return bool
	 */
	public function is_user_seller( $user_id ) {
		return WCV_Vendors::is_vendor( $user_id );
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
			'country'   => get_user_meta( $seller_id, '_wcv_store_country', true ),
			'address'   => get_user_meta( $seller_id, '_wcv_store_address1', true ),
			'address_2' => get_user_meta( $seller_id, '_wcv_store_address2', true ),
			'city'      => get_user_meta( $seller_id, '_wcv_store_city', true ),
			'state'     => get_user_meta( $seller_id, '_wcv_store_state', true ),
			'postcode'  => get_user_meta( $seller_id, '_wcv_store_postcode', true ),
		);

		$shipping_details = get_user_meta( $seller_id, '_wcv_shipping', true );

		if ( is_array( $shipping_details ) && isset( $shipping_details['shipping_from'] ) ) {
			if ( 'other' === $shipping_details['shipping_from'] ) {
				$shipping_address = $shipping_details['shipping_address'];
				$address          = array(
					'country'   => $shipping_address['country'],
					'address'   => $shipping_address['address1'],
					'address_2' => $shipping_address['address2'],
					'city'      => $shipping_address['city'],
					'state'     => $shipping_address['state'],
					'postcode'  => $shipping_address['postcode'],
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

SST_WC_Vendors::instance();
