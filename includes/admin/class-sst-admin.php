<?php

use Automattic\WooCommerce\Utilities\OrderUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Admin.
 *
 * Handles the admin interface.
 *
 * @author  Simple Sales Tax
 * @package SST
 * @since   4.2
 */
class SST_Admin {

	/**
	 * Class constructor.
	 *
	 * @since 4.7
	 */
	public function __construct() {
		$this->includes();
		$this->hooks();
	}

	/**
	 * Bootstraps the admin class.
	 *
	 * @since 4.7
	 */
	private function hooks() {
		add_filter( 'woocommerce_integrations', array( __CLASS__, 'add_integration' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts_and_styles' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_metaboxes' ) );
		add_action( 'woocommerce_reports_charts', array( __CLASS__, 'add_reports_tab' ) );
		add_filter( 'woocommerce_debug_tools', array( __CLASS__, 'register_debug_tool' ) );
		add_action( 'product_cat_add_form_fields', array( __CLASS__, 'output_category_tic_select' ) );
		add_action( 'product_cat_edit_form_fields', array( __CLASS__, 'output_category_tic_select' ) );
		add_action( 'create_product_cat', array( __CLASS__, 'save_category_tic' ) );
		add_action( 'edited_product_cat', array( __CLASS__, 'save_category_tic' ) );
		add_action( 'woocommerce_before_settings_tax', array( __CLASS__, 'tax_based_on_notice' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_user_certificates' ), 11 );
		add_action( 'show_user_profile', array( __CLASS__, 'render_user_certificates' ), 11 );
	}

	/**
	 * Include required files.
	 *
	 * @since 5.0
	 */
	public function includes() {
		require_once dirname( __DIR__ ) . '/sst-message-functions.php';
		require_once __DIR__ . '/class-sst-integration.php';
	}

	/**
	 * Register our WooCommerce integration.
	 *
	 * @param array $integrations Registered WooCommerce integrations.
	 *
	 * @since 4.2
	 */
	public static function add_integration( $integrations ) {
		$integrations[] = 'SST_Integration';

		return $integrations;
	}

	/**
	 * Get the ID of the shop order administration screen.
	 *
	 * @return string
	 */
	protected static function get_order_screen_id() {
		return OrderUtil::custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @since 4.2
	 */
	public static function enqueue_scripts_and_styles() {
		// Global admin CSS.
		wp_enqueue_style( 'sst-admin-css' );

		// Edit Order screen CSS.
		$screen = get_current_screen();
		if ( $screen && $screen->id === self::get_order_screen_id() ) {
			wp_enqueue_style( 'sst-certificate-modal-css' );
		}
	}

	/**
	 * Register "Sales Tax" metabox.
	 *
	 * @since 4.2
	 */
	public static function add_metaboxes() {
		add_meta_box(
			'sales_tax_meta',
			__( 'Simple Sales Tax', 'simple-sales-tax' ),
			array( __CLASS__, 'output_tax_metabox' ),
			self::get_order_screen_id(),
			'side',
			'high'
		);
	}

	/**
	 * Output HTML for "Sales Tax" metabox.
	 *
	 * @param WP_Post|WC_Order $post_or_order Post or order object.
	 *
	 * @since 4.2
	 */
	public static function output_tax_metabox( $post_or_order ) {
		$order_id = ( $post_or_order instanceof WP_Post )
			? $post_or_order->ID
			: $post_or_order->get_id();
		$order    = new SST_Order( $order_id );

		do_action( 'sst_output_tax_meta_box', $order );
	}

	/**
	 * Link to TaxCloud "Reports" page from "Taxes" tab.
	 *
	 * @since 4.2
	 */
	public static function output_tax_report_button() {
		?>
		<div id="poststuff" class="wootax-reports-page">
			<a target="_blank" href="https://app.taxcloud.com/go/tax-reporting"
			   class="wp-core-ui button button-primary">
				<?php esc_html_e( 'Go to TaxCloud Reports Page', 'simple-sales-tax' ); ?>
			</a>
		</div>
		<?php
	}

	/**
	 * Add a "Taxes" tab to the WooCommerce reports page.
	 *
	 * @param array $charts Array of charts to be rendered on the reports page.
	 *
	 * @return array
	 * @since 4.2
	 */
	public static function add_reports_tab( $charts ) {
		$charts['taxes'] = array(
			'title'  => __( 'Taxes', 'simple-sales-tax' ),
			'charts' => array(
				'overview' => array(
					'title'       => __( 'Overview', 'simple-sales-tax' ),
					'description' => '',
					'hide_title'  => true,
					'function'    => array( __CLASS__, 'output_tax_report_button' ),
				),
			),
		);

		return $charts;
	}

	/**
	 * Delete cached tax rates.
	 *
	 * @since 4.5
	 */
	public static function remove_rate_transients() {
		wc_deprecated_function( __METHOD__, '6.1', __CLASS__ . '::invalidate_wc_tax_rates_cache' );
		self::invalidate_wc_tax_rates_cache();
	}

	/**
	 * WooCommerce will cache matched tax rates for a particular address. This can
	 * be problematic if the user had existing tax rates at the time SST was installed.
	 *
	 * To handle problem cases, we provide a "debug tool" that removes all cached
	 * tax rates. This method registers that debug tool with WooCommerce.
	 *
	 * Note that our tool can be accessed from WooCommerce -> System Status -> Tools.
	 *
	 * @param array $tools Array of registered debug tools.
	 *
	 * @return array
	 * @since 4.4
	 */
	public static function register_debug_tool( $tools ) {
		$tools['wootax_rate_tool'] = array(
			'name'     => __( 'Delete cached tax rates', 'simple-sales-tax' ),
			'button'   => __( 'Clear tax rate cache', 'simple-sales-tax' ),
			'desc'     => __( 'This tool will remove any tax rates cached by WooCommerce.', 'simple-sales-tax' ),
			'callback' => array( __CLASS__, 'invalidate_wc_tax_rates_cache' ),
		);

		return $tools;
	}

	/**
	 * Invalidates the WooCommerce tax rate cache.
	 *
	 * @return string Output from debug tool
	 */
	public static function invalidate_wc_tax_rates_cache() {
		if ( method_exists( 'WC_Cache_Helper', 'invalidate_cache_group' ) ) {  // WC 3.9+.
			WC_Cache_Helper::invalidate_cache_group( 'taxes' );
		} else {
			WC_Cache_Helper::incr_cache_prefix( 'taxes' );
		}

		return __( 'Tax rate cache cleared.', 'simple-sales-tax' );
	}

	/**
	 * Add a "Default TIC" field to the "Add Category" and "Edit Category" screens.
	 *
	 * @param WP_Term|string $term_or_taxonomy (default: NULL).
	 *
	 * @since 5.0
	 */
	public static function output_category_tic_select( $term_or_taxonomy = null ) {
		$wrapper_el       = 'div';
		$label_el         = 'label';
		$field_wrapper_el = 'div';
		$value            = '';
		$is_edit          = is_a( $term_or_taxonomy, 'WP_Term' );

		if ( $is_edit ) {
			$wrapper_el       = 'tr';
			$label_el         = 'th';
			$field_wrapper_el = 'td';
			$value            = get_term_meta( $term_or_taxonomy->term_id, 'tic', true );
		}

		printf( '<%s class="form-field">', $wrapper_el );
		printf(
			'<%1$s>%2$s</%1$s>',
			$label_el,
			esc_html__( 'Taxability Information Code', 'simple-sales-tax' )
		);
		printf( '<%s class="sst-tic-select-wrap">', $field_wrapper_el );

		sst_output_tic_select_field( compact( 'value' ) );

		printf(
			'<p class="description">%s</p>',
			esc_html__(
				'This TIC will be used as the default for all products in this category.',
				'simple-sales-tax'
			)
		);

		printf( '</%s>', $field_wrapper_el );
		printf( '</%s>', $wrapper_el );
	}

	/**
	 * Save Default TIC for category.
	 *
	 * @param int $term_id ID of category being saved.
	 *
	 * @since 4.5
	 */
	public static function save_category_tic( $term_id ) {
		$tic = isset( $_REQUEST['wootax_tic'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['wootax_tic'] ) ) : ''; // phpcs:ignore WordPress.CSRF.NonceVerification

		update_term_meta( $term_id, 'tic', $tic );
	}

	/**
	 * Outputs a notice under WooCommerce > Settings > Tax to indicate that the
	 * WooCommerce "Calculate tax based on" setting is not respected.
	 */
	public static function tax_based_on_notice() {
		$section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : ''; // phpcs:ignore WordPress.CSRF.NonceVerification

		if ( in_array( $section, array( '', 'tax' ), true ) ) {
			?>
			<div class="notice notice-warning">
				<p>
					<?php
					printf(
						'<strong>%1$s</strong> %2$s',
						esc_html__( 'Heads up!', 'simple-sales-tax' ),
						esc_html__(
							'The WooCommerce "Calculate tax based on" setting is not respected by Simple Sales Tax. The customer billing address will only be used for tax calculations when the shipping address is not provided (e.g. for sales of digital goods).',
							'simple-sales-tax'
						)
					);
					?>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Renders a table of the customer's exemption certificates
	 * on the Edit User screen.
	 *
	 * @param WP_User $user The user whose profile is being edited.
	 */
	public static function render_user_certificates( $user ) {
		$user_can_edit      = apply_filters(
			'woocommerce_current_user_can_edit_customer_meta_fields',
			current_user_can( 'manage_woocommerce' ),
			$user->ID
		);
		$exemptions_enabled = 'true' === SST_Settings::get( 'show_exempt' );
		$show_certificates  = $user_can_edit && $exemptions_enabled;

		if ( ! $show_certificates ) {
			return;
		}

		?>
		<h2 id="exemption_certificates">
			<?php esc_html_e( 'Exemption Certificates', 'simple-sales-tax' ); ?>
		</h2>
		<p>
			<?php
			esc_html_e(
				"Manage this customer's TaxCloud exemption certificates.",
				'simple-sales-tax'
			);
			?>
		</p>
		<?php

		$template_args = array(
			'show_inputs' => false,
			'table_class' => 'widefat',
		);

		sst_render_certificate_table( $user->ID, $template_args );
	}

}

new SST_Admin();
