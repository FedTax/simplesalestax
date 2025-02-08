<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use \Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils;

/**
 * SST Settings.
 *
 * Contains methods for getting/setting plugin options.
 *
 * @author  Simple Sales Tax
 * @package SST
 * @since   5.0
 */
class SST_Settings {

	/**
	 * Options key.
	 *
	 * @var string
	 * @since 5.0
	 */
	private static $options_key = 'woocommerce_wootax_settings';

	/**
	 * Plugin settings.
	 *
	 * @var array
	 * @since 5.0
	 */
	private static $settings = array();

	/**
	 * Load the plugin settings from the options table.
	 *
	 * @since 6.3.3
	 */
	public static function load_settings() {
		self::$settings = get_option( self::$options_key, array() );
	}

	/**
	 * Check whether cart block is being used on default cart page.
	 *
	 * @return bool
	 */
	protected static function is_using_cart_block() {
		if ( class_exists( CartCheckoutUtils::class ) ) {
			return CartCheckoutUtils::is_cart_block_default();
		}

		$page_id = wc_get_page_id( 'cart' );

		return WC_Blocks_Utils::has_block_in_page( $page_id, 'woocommerce/cart' );
	}

	/**
	 * Check whether checkout block is being used on default checkout page.
	 *
	 * @return bool
	 */
	protected static function is_using_checkout_block() {
		if ( class_exists( CartCheckoutUtils::class ) ) {
			return CartCheckoutUtils::is_checkout_block_default();
		}

		$page_id = wc_get_page_id( 'checkout' );

		return WC_Blocks_Utils::has_block_in_page( $page_id, 'woocommerce/checkout' );
	}

	/**
	 * Get a list of plugin options and their default values.
	 *
	 * @since 5.0
	 */
	public static function get_form_fields() {
		$disable_show_zero_tax = (
			self::is_using_cart_block() ||
			self::is_using_checkout_block()
		);

		$fields = array(
			'taxcloud_settings'           => array(
				'title'       => __( 'TaxCloud Settings', 'simple-sales-tax' ),
				'type'        => 'title',
				'description' => __(
					'You must enter a valid TaxCloud API ID and API Key for Simple Sales Tax to work properly. Use the "Verify Settings" button to test your settings.',
					'simple-sales-tax'
				),
			),
			'tc_id'                       => array(
				'title'       => __( 'TaxCloud X-API-Key', 'simple-sales-tax' ),
				'type'        => 'text',
				'description' => __(
					'Your TaxCloud API ID. This can be found in your TaxCloud account on the "Websites" page.',
					'simple-sales-tax'
				),
				'desc_tip'    => true,
				'default'     => '',
			),
			'tc_key'                      => array(
				'title'       => __( 'Connection ID', 'simple-sales-tax' ),
				'type'        => 'text',
				'description' => __(
					'Your TaxCloud API Key. This can be found in your TaxCloud account on the "Websites" page.',
					'simple-sales-tax'
				),
				'desc_tip'    => true,
				'default'     => '',
			),

			'verify_settings'             => array(
				'title'       => __( 'Verify TaxCloud Settings', 'simple-sales-tax' ),
				'label'       => __( 'Verify Settings', 'simple-sales-tax' ),
				'type'        => 'button',
				'description' => __(
					'Use this button to verify that your site can communicate with TaxCloud.',
					'woocommerce-integration-demo'
				),
				'desc_tip'    => true,
				'id'          => 'verifySettings',
			),
			'address_settings'            => array(
				'title'       => __( 'Address Settings', 'simple-sales-tax' ),
				'type'        => 'title',
				'description' => __(
					'To accurately determine the sales tax for an order, Simple Sales Tax needs to know the locations you ship your products from.<br>You can select from the addresses entered on the <a href="https://app.taxcloud.com/go/locations" target="_blank">Locations</a> page in TaxCloud.',
					'simple-sales-tax'
				),
			),
			'default_origin_addresses'      => array(
				'title'       => __( 'Shipping Origin Addresses', 'simple-sales-tax' ),
				'type'        => 'origin_address_select',
				'description' => __(
					'Select the addresses you ship your products from. You can choose a different set of origin addresses for a specific product under the Shipping tab on the Edit Product screen.',
					'simple-sales-tax'
				),
				'desc_tip'    => true,
				'default'     => array(),
			),
			'exemption_settings'          => array(
				'title'       => __( 'Exemption Settings', 'simple-sales-tax' ),
				'type'        => 'title',
				'description' => __(
					'If you have tax exempt customers, be sure to enable tax exemptions and enter your company name.',
					'simple-sales-tax'
				),
			),
			'show_exempt'                 => array(
				'title'       => __( 'Enable Tax Exemptions?', 'simple-sales-tax' ),
				'type'        => 'select',
				'options'     => array(
					'true'  => __( 'Yes', 'simple-sales-tax' ),
					'false' => __( 'No', 'simple-sales-tax' ),
				),
				'default'     => 'false',
				'description' => __( 'Set this to "Yes" if you have tax exempt customers.', 'simple-sales-tax' ),
				'desc_tip'    => true,
			),
			'company_name'                => array(
				'title'       => __( 'Company Name', 'simple-sales-tax' ),
				'type'        => 'text',
				'default'     => '',
				'description' => __(
					'Enter your company name as it should be displayed on exemption certificates.',
					'simple-sales-tax'
				),
				'desc_tip'    => true,
			),
			'exempt_roles'                => array(
				'title'       => __( 'Exempt User Roles', 'simple-sales-tax' ),
				'type'        => 'multiselect',
				'class'       => 'wc-enhanced-select',
				'options'     => self::get_user_roles(),
				'default'     => array( 'exempt-customer' ),
				'description' => __(
					'When a user with one of these roles shops on your site, WooTax will automatically find and apply the first exemption certificate associated with their account. Convenient if you have repeat exempt customers.',
					'simple-sales-tax'
				),
				'desc_tip'    => true,
			),
			'restrict_exempt'             => array(
				'title'       => __( 'Restrict to Exempt Roles', 'simple-sales-tax' ),
				'type'        => 'select',
				'default'     => 'no',
				'description' => __(
					'Set this to "Yes" to restrict users aside from those specified above from seeing the exemption form during checkout.',
					'simple-sales-tax'
				),
				'desc_tip'    => true,
				'options'     => array(
					'yes' => __( 'Yes', 'simple-sales-tax' ),
					'no'  => __( 'No', 'simple-sales-tax' ),
				),
			),
			'display_settings'            => array(
				'title'       => __( 'Display Settings', 'simple-sales-tax' ),
				'type'        => 'title',
				'description' => __( 'Control how taxes are displayed during checkout.', 'simple-sales-tax' ),
			),
			'show_zero_tax'               => array(
				'title'       => __( 'Show Zero Tax?', 'simple-sales-tax' ),
				'type'        => 'select',
				'options'     => array(
					'true'  => __( 'Yes', 'simple-sales-tax' ),
					'false' => __( 'No', 'simple-sales-tax' ),
				),
				'default'     => 'false',
				'description' => __(
					'When the sales tax due is zero, should the "Sales Tax" line be shown? Disabled when cart or checkout block is in use.',
					'simple-sales-tax'
				),
				'disabled'    => $disable_show_zero_tax,
				'desc_tip'    => true,
			),
			'advanced_settings'           => array(
				'title'       => __( 'Advanced Settings', 'simple-sales-tax' ),
				'type'        => 'title',
				'description' => __(
					'For advanced users only. Leave these settings untouched if you are not sure how to use them.',
					'simple-sales-tax'
				),
			),
			'shipping_tic'                => array(
				'title'       => __( 'Shipping TIC', 'simple-sales-tax' ),
				'type'        => 'select',
				'options'     => array(
					'11000' => __(
						'11000 - Handling, crating, packing, preparation for mailing or delivery, and similar charges',
						'simple-sales-tax'
					),
					'11010' => __(
						'11010 - Transportation, shipping, postage, and similar charges',
						'simple-sales-tax'
					),
					'11011' => __(
						'11011 - Transportation, shipping, postage, and similar charges by USPS',
						'simple-sales-tax'
					),
					'11012' => __(
						'11012 - Transportation, shipping, postage, and similar charges with pick-up option',
						'simple-sales-tax'
					),
					'11098' => __( '11098 - Colorado Retail Delivery Fees', 'simple-sales-tax' ),
				),
				'default'     => self::get_default_shipping_tic(),
				'description' => __(
					'Enter TIC code on <a href="https://taxcloud.net/tic" target="_blank">TaxCloud website</a> for details.',
					'simple-sales-tax'
				),
				'desc_tip'    => __(
					'Select the Taxability Information Code to apply to shipping charges.',
					'simple-sales-tax'
				),
			),
			'log_requests'                => array(
				'title'       => __( 'Log Requests', 'simple-sales-tax' ),
				'type'        => 'checkbox',
				'label'       => ' ',
				'default'     => 'yes',
				'description' => __(
					'When selected, Simple Sales Tax will log all requests sent to TaxCloud for debugging purposes.',
					'simple-sales-tax'
				),
				'desc_tip'    => true,
			),
			'capture_immediately'         => array(
				'title'       => __( 'Capture Orders Immediately', 'simple-sales-tax' ),
				'label'       => ' ',
				'type'        => 'checkbox',
				'default'     => 'no',
				'description' => __(
					'By default, orders are marked as Captured in TaxCloud when they are shipped. Select this option to mark orders as Captured immediately when payment is received. Useful for stores that have items with long lead times.',
					'simple-sales-tax'
				),
				'desc_tip'    => true,
			),
			'tax_based_on'                => array(
				'title'       => __( 'Tax Based On', 'simple-sales-tax' ),
				'type'        => 'select',
				'options'     => array(
					'item-price'    => __( 'Item Price', 'simple-sales-tax' ),
					'line-subtotal' => __( 'Line Subtotal', 'simple-sales-tax' ),
				),
				'default'     => 'item-price',
				'description' => __(
					'"Item Price": TaxCloud determines the taxable amount for a line item by multiplying the item price by its quantity. "Line Subtotal": the taxable amount is determined by the line subtotal. Useful in instances where rounding becomes an issue.',
					'simple-sales-tax'
				),
				'desc_tip'    => true,
			),
			'remove_all_data'             => array(
				'title'       => __( 'Remove All Data', 'simple-sales-tax' ),
				'label'       => ' ',
				'type'        => 'checkbox',
				'default'     => 'no',
				'description' => __(
					'When this feature is enabled, all Simple Sales Tax options and data will be removed when you click deactivate and delete the plugin.',
					'simple-sales-tax'
				),
				'desc_tip'    => true,
			),
			'debug_report_button'         => array(
				'title'       => __( 'Debug Report', 'simple-sales-tax' ),
				'label'       => __( 'Download', 'simple-sales-tax' ),
				'type'        => 'anchor',
				'url'         => add_query_arg( 'download_debug_report', true ),
				'id'          => 'debug_report_button',
				'description' => __(
					'Send a copy of this report to TaxCloud support to help with debugging Simple Sales Tax issues.',
					'simple-sales-tax'
				),
				'desc_tip'    => true,
			),
		);

		return apply_filters( 'sst_settings_form_fields', $fields );
	}

	/**
	 * Get the default value for the Shipping TIC option.
	 *
	 * @return string
	 */
	protected static function get_default_shipping_tic() {
		$default_tic = SST_DEFAULT_SHIPPING_TIC;

		if ( has_filter( 'wootax_shipping_tic' ) ) {
			$default_tic = apply_filters_deprecated(
				'wootax_shipping_tic',
				array( $default_tic ),
				'7.0.0',
				'sst_shipping_tic'
			);
		}

		return $default_tic;
	}

	/**
	 * Get function.
	 *
	 * Gets an option from the settings API, using defaults if necessary to prevent undefined notices.
	 *
	 * @param string $key         Option key.
	 * @param mixed  $empty_value Value to return when option value is not set.
	 *
	 * @return string The value specified for the option or a default value for the option.
	 */
	public static function get( $key, $empty_value = null ) {
		if ( empty( self::$settings ) ) {
			self::load_settings();
		}

		// Get option default if unset.
		if ( ! isset( self::$settings[ $key ] ) ) {
			$form_fields            = self::get_form_fields();
			self::$settings[ $key ] = isset( $form_fields[ $key ] ) ? $form_fields[ $key ]['default'] : '';
		}

		if ( ! is_null( $empty_value ) && '' === self::$settings[ $key ] ) {
			self::$settings[ $key ] = $empty_value;
		}

		return self::$settings[ $key ];
	}

	/**
	 * Set function.
	 *
	 * Sets an option.
	 *
	 * @param string $key   Option key.
	 * @param mixed  $value Option value.
	 */
	public static function set( $key, $value ) {
		self::load_settings();
		self::$settings[ $key ] = $value;

		update_option( self::$options_key, self::$settings );
	}

	/**
	 * Get a list of user roles.
	 *
	 * @return array
	 * @since 5.0
	 */
	protected static function get_user_roles() {
		global $wp_roles;

		if ( ! isset( $wp_roles ) ) {
			$wp_roles = new WP_Roles();
		}

		return $wp_roles->get_names();
	}

}
