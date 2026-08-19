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
	 * Default setting values.
	 *
	 * @var array
	 * @since 8.4.14
	 */
	private static $defaults = array(
		'tc_id'                          => '',
		'tc_key'                         => '',
		'integration_mode'               => '',
		'default_origin_addresses'       => array(),
		'show_exempt'                    => 'false',
		'company_name'                   => '',
		'exempt_roles'                   => array( 'exempt-customer' ),
		'restrict_exempt'                => 'no',
		'show_zero_tax'                  => 'false',
		'order_show_zero_tax'            => 'true',
		'log_requests'                   => 'yes',
		'disable_integration'            => 'no',
		'force_tax_lookup'               => '',
		'disable_virtual_split'          => '',
		'capture_orders_in_taxcloud'     => 'yes',
		'tax_based_on'                   => 'item-price',
		'enable_taxcloud_rate_limit'     => 'no',
		'taxcloud_rate_limit_requests'   => '',
		'taxcloud_rate_limit_scope'      => 'customer',
		'taxcloud_rate_limit_window'     => 60,
		'taxcloud_rate_limit_skip_admin' => 'yes',
		'show_rate_limit_notice'         => 'no',
		'remove_all_data'                => 'no',
	);

	/**
	 * Load the plugin settings from the options table.
	 *
	 * @since 6.3.3
	 */
	public static function load_settings() {
		self::$settings = get_option( self::$options_key, array() );
	}

	/**
	 * Get the default value for a setting key.
	 *
	 * @param string $key Setting key.
	 *
	 * @return mixed Default value.
	 * @since 8.4.14
	 */
	public static function get_default( $key ) {
		if ( 'shipping_tic' === $key ) {
			return self::get_default_shipping_tic();
		}
		if ( 'capture_trigger' === $key ) {
			return self::get_default_capture_trigger();
		}
		if ( array_key_exists( $key, self::$defaults ) ) {
			return self::$defaults[ $key ];
		}
		$form_fields = self::get_form_fields();
		return isset( $form_fields[ $key ]['default'] ) ? $form_fields[ $key ]['default'] : '';
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
	 * Check whether Data Import mode is active.
	 *
	 * @return bool
	 */
	protected static function is_data_mover_mode() {
		if ( empty( self::$settings ) ) {
			self::load_settings();
		}

		return ! empty( self::$settings['data_mover'] );
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
		$disable_data_import_settings = self::is_data_mover_mode();
		$exemption_description        = __(
			'If you have tax exempt customers, be sure to enable tax exemptions and enter your company name.',
			'simple-sales-tax'
		);
		$rate_limit_description       = __(
			'Configure rate limiting for TaxCloud tax lookup requests to prevent excessive API calls.',
			'simple-sales-tax'
		);

		if ( $disable_data_import_settings ) {
			$exemption_description .= '<br><strong>' . __(
				'Tax exemptions are not supported in Data Import mode. These settings are disabled until Integration Mode is switched back to Real Time.',
				'simple-sales-tax'
			) . '</strong>';
			$rate_limit_description .= '<br><strong>' . __(
				'Real-time TaxCloud lookup requests are not made in Data Import mode. These settings are disabled until Integration Mode is switched back to Real Time.',
				'simple-sales-tax'
			) . '</strong>';
		}

		if ( empty( self::$settings ) ) {
			self::load_settings();
		}

		$disable_exemption_sub_settings = (
			$disable_data_import_settings ||
			'false' === ( self::$settings['show_exempt'] ?? 'false' )
		);

		$fields = array(
			'taxcloud_settings'           => array(
				'title'       => __( 'TaxCloud Settings', 'simple-sales-tax' ),
				'type'        => 'title',
				'description' => __(
					'You must enter a valid TaxCloud API ID and API Key for TaxCloud for WooCommerce to work properly. Use the "Verify Settings" button to test your settings.',
					'simple-sales-tax'
				),
			),
			'tc_id'                       => array(
				'title'       => __( 'TaxCloud API ID', 'simple-sales-tax' ),
				'type'        => 'text',
				'description' => __(
					'Your TaxCloud API ID. This can be found in your TaxCloud account on the "Websites" page.',
					'simple-sales-tax'
				),
				'desc_tip'    => true,
				'default'     => '',
			),
			'tc_key'                      => array(
				'title'       => __( 'TaxCloud API Key', 'simple-sales-tax' ),
				'type'        => 'text',
				'description' => __(
					'Your TaxCloud API Key. This can be found in your TaxCloud account on the "Websites" page.',
					'simple-sales-tax'
				),
				'desc_tip'    => true,
				'default'     => '',
			),
			// 'tc_connection_id'            => array(
			// 	'title'       => __( 'TaxCloud Connection ID', 'simple-sales-tax' ),
			// 	'type'        => 'text',
			// 	'description' => __(
			// 		'TaxCloud Connection ID (URL ID). Automatically retrieved.',
			// 		'simple-sales-tax'
			// 	),
			// 	'desc_tip'    => true,
			// 	'default'     => '',
			// 	'class'       => 'hidden',
			// 	'custom_attributes' => array(
			// 		'readonly' => 'readonly',
			// 	),
			// ),
			'verify_settings'             => array(
				'title'       => __( 'Verify TaxCloud Settings', 'simple-sales-tax' ),
				'label'       => __( 'Verify Settings', 'simple-sales-tax' ),
				'type'        => 'button',
				'description' => __(
					'Use this button to verify that your site can communicate with TaxCloud.',
					'simple-sales-tax'
				),
				'desc_tip'    => true,
				'id'          => 'verifySettings',
			),
			'integration_mode'            => array(
				'title'       => __( 'Integration Mode', 'simple-sales-tax' ),
				'type'        => 'integration_mode',
				'default'     => '',
				'desc_tip'    => true,
				'description' => __(
					'Click the refresh button to check TaxCloud for the current mode setting.',
					'simple-sales-tax'
				),
				'custom_attributes' => array(
					'readonly' => 'readonly',
					'disabled' => 'disabled',
				),
			),
			'address_settings'            => array(
				'title'       => __( 'Address Settings', 'simple-sales-tax' ),
				'type'        => 'title',
				'description' => __(
					'To accurately determine the sales tax for an order, TaxCloud for WooCommerce needs to know the locations you ship your products from.<br>You can select from the addresses entered on the <a href="https://app.taxcloud.com/go/locations" target="_blank">Locations</a> page in your TaxCloud account.',
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
				'description' => $exemption_description,
			),
			'show_exempt'                 => array(
				'title'       => __( 'Enable Tax Exemptions?', 'simple-sales-tax' ),
				'type'        => 'select',
				'options'     => array(
					'true'  => __( 'Yes', 'simple-sales-tax' ),
					'false' => __( 'No', 'simple-sales-tax' ),
				),
				'default'     => 'false',
				'description' => __( 'Set this to "Yes" to allow customers to apply or create exemption certificates during checkout.', 'simple-sales-tax' ),
				'desc_tip'    => true,
				'disabled'    => $disable_data_import_settings,
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
				'disabled'    => $disable_exemption_sub_settings,
			),
			'exempt_roles'                => array(
				'title'       => __( 'Exempt User Roles', 'simple-sales-tax' ),
				'type'        => 'multiselect',
				'class'       => 'wc-enhanced-select',
				'options'     => self::get_user_roles(),
				'default'     => array( 'exempt-customer' ),
				'description' => __(
					'When a user with one of these roles shops on your site, TaxCloud will automatically find and apply the first exemption certificate associated with their account. Convenient if you have repeat exempt customers.',
					'simple-sales-tax'
				),
				'desc_tip'    => false,
				'disabled'    => $disable_exemption_sub_settings,
			),
			'restrict_exempt'             => array(
				'title'       => __( 'Restrict to Exempt Roles', 'simple-sales-tax' ),
				'type'        => 'select',
				'default'     => 'no',
				'description' => __(
					'Set this to "Yes" so only customers assigned to an Exempt User Role can view and use the tax exemption form during checkout.',
					'simple-sales-tax'
				),
				'desc_tip'    => true,
				'options'     => array(
					'yes' => __( 'Yes', 'simple-sales-tax' ),
					'no'  => __( 'No', 'simple-sales-tax' ),
				),
				'disabled'    => $disable_exemption_sub_settings,
			),
			'display_settings'            => array(
				'title'       => __( 'Display Settings', 'simple-sales-tax' ),
				'type'        => 'title',
				'description' => __( 'Control how taxes are displayed during checkout.', 'simple-sales-tax' ),
			),
			'show_zero_tax'               => array(
				'title'       => __( 'Show Zero Tax On Cart Page?', 'simple-sales-tax' ),
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
			'order_show_zero_tax'					=> array(
				'title'       => __( 'Show Zero Tax on Order Page?', 'simple-sales-tax' ),
				'type'        => 'select',
				'options'     => array(
					'false' => __( 'No', 'simple-sales-tax' ),
					'true'  => __( 'Yes', 'simple-sales-tax' ),
				),
				'default'     => 'true',
				'description' => __(
					'When the sales tax due is zero, should the "Sales Tax" line be shown on the order page?',
					'simple-sales-tax'
				),
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
					'11013' => __(
						'11013 - Transportation, shipping, postage, and similar charges where the charge is marked up.',
						'simple-sales-tax'
					),
				),
				'default'     => self::get_default_shipping_tic(),
				'description' => __(
					'Enter TIC code on <a href="https://support.taxcloud.com/article/109-taxability-information-codes" target="_blank">TaxCloud website</a> for details.',
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
					'When selected, TaxCloud for WooCommerce will log all requests sent to TaxCloud for debugging purposes.',
					'simple-sales-tax'
				),
				'desc_tip'    => true,
			),
			'disable_integration'         => array(
				'title'       => __( 'Disable Integration', 'simple-sales-tax' ),
				'type'        => 'select',
				'options'     => array(
					'no'  => __( 'No', 'simple-sales-tax' ),
					'yes' => __( 'Yes', 'simple-sales-tax' ),
				),
				'default'     => 'no',
				'description' => __(
					'If enabled, TaxCloud integration will be disabled for both real-time and data mover.',
					'simple-sales-tax'
				),
				'desc_tip'    => true,
			),
			'force_tax_lookup'						=> array(
				'title'       => __( 'Force Tax Lookup', 'simple-sales-tax' ),
				'type'        => 'checkbox',
				'label'       => ' ',
				'default'     => '',
				'description' => __(
					'When selected, TaxCloud for WooCommerce will force a tax lookup for each order regardless of cached results. This affects on rate limit.',
					'simple-sales-tax'
				),
				'desc_tip'    => true,
				'disabled'    => $disable_data_import_settings,
			),
			'disable_virtual_split'       => array(
				'title'       => __( 'Do Not Split Virtual Products', 'simple-sales-tax' ),
				'type'        => 'checkbox',
				'label'       => ' ',
				'default'     => '',
				'description' => __(
					'When checked, virtual products will use the shipping address if available instead of splitting into a separate billing address package.',
					'simple-sales-tax'
				),
				'desc_tip'    => true,
			),
			'capture_orders_in_taxcloud'         => array(
				'title'       => __( 'Capture Orders in TaxCloud', 'simple-sales-tax' ),
				'label'       => ' ',
				'type'        => 'checkbox',
				'default'     => 'yes',
				'description' => __(
					'When enabled, orders are submitted to TaxCloud for reporting and filing. Orders are captured only if the integration connection is set to Live in TaxCloud.',
					'simple-sales-tax'
				),
				'desc_tip'    => true,
			),
			'capture_trigger'             => array(
				'title'       => __( 'TaxCloud Order Capture Trigger', 'simple-sales-tax' ),
				'type'        => 'select',
				'default'     => self::get_default_capture_trigger(),
				'description' => __(
					'Choose when WooCommerce orders should be captured in TaxCloud for reporting and filing.',
					'simple-sales-tax'
				),
				'desc_tip'    => false,
				'options'     => array(
					'completed'        => __( 'Completed order', 'simple-sales-tax' ),
					'processing'       => __( 'Processing order (paid)', 'simple-sales-tax' ),
					'both'             => __( 'Both Processing + Completed', 'simple-sales-tax' ),
				),
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
			'rate_limit_settings'         => array(
				'title'       => __( 'Rate Limit Settings', 'simple-sales-tax' ),
				'type'        => 'title',
				'description' => $rate_limit_description,
			),
			'enable_taxcloud_rate_limit'  => array(
				'title'       => __( 'Enable TaxCloud Rate Limiting', 'simple-sales-tax' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable rate limiting for TaxCloud tax lookup requests', 'simple-sales-tax' ),
				'default'     => 'no',
				'description' => __(
					'Limit how many TaxCloud tax lookup requests can be made within a defined time window.',
					'simple-sales-tax'
				),
				'desc_tip'    => false,
				'disabled'    => $disable_data_import_settings,
			),
			'taxcloud_rate_limit_requests' => array(
				'title'       => __( 'Number of Requests', 'simple-sales-tax' ),
				'type'        => 'number',
				'default'     => '',
				'description' => __(
					'Maximum number of TaxCloud tax lookup requests allowed within the configured time window. Leave empty to disable.',
					'simple-sales-tax'
				),
				'desc_tip'    => false,
				'custom_attributes' => array(
					'min' => 1,
				),
				'disabled'    => $disable_data_import_settings,
			),
			'taxcloud_rate_limit_scope'   => array(
				'title'       => __( 'Apply Rate Limit to', 'simple-sales-tax' ),
				'type'        => 'select',
				'default'     => 'customer',
				'description' => __(
					'Choose how rate limits are applied.',
					'simple-sales-tax'
				),
				'desc_tip'    => false,
				'options'     => array(
					'customer' => __( 'Customer / Visitor (per user or session)', 'simple-sales-tax' ),
					'global'   => __( 'Global (shared across all users)', 'simple-sales-tax' ),
				),
				'disabled'    => $disable_data_import_settings,
			),
			'taxcloud_rate_limit_window'  => array(
				'title'       => __( 'Time Window (Minutes)', 'simple-sales-tax' ),
				'type'        => 'number',
				'default'     => 60,
				'description' => __(
					'Time window (in minutes) for rate limiting. Requests reset after this duration.',
					'simple-sales-tax'
				),
				'desc_tip'    => false,
				'custom_attributes' => array(
					'min' => 1,
				),
				'disabled'    => $disable_data_import_settings,
			),
			'taxcloud_rate_limit_skip_admin' => array(
				'title'       => __( 'Skip Admin Order Rate Limiting', 'simple-sales-tax' ),
				'type'        => 'checkbox',
				'label'       => __( 'Exempt admin users and backend order calculations from rate limits', 'simple-sales-tax' ),
				'default'     => 'yes',
				'description' => __(
					'When enabled, TaxCloud rate limits will not apply to orders created or calculated by admin users.',
					'simple-sales-tax'
				),
				'desc_tip'    => false,
				'disabled'    => $disable_data_import_settings,
			),
			'show_rate_limit_notice'      => array(
				'title'       => __( 'Notify Customer When Limited', 'simple-sales-tax' ),
				'type'        => 'checkbox',
				'label'       => __( 'Display a notice to customers when tax lookup is skipped', 'simple-sales-tax' ),
				'default'     => 'no',
				'description' => __(
					'If enabled, customers will see a non-blocking message when TaxCloud tax calculation is temporarily unavailable.',
					'simple-sales-tax'
				),
				'desc_tip'    => false,
				'disabled'    => $disable_data_import_settings,
			),
			'remove_all_data'             => array(
				'title'       => __( 'Remove All Data', 'simple-sales-tax' ),
				'label'       => ' ',
				'type'        => 'checkbox',
				'default'     => 'no',
				'description' => __(
					'When this feature is enabled, all TaxCloud for WooCommerce options and data will be removed when you click deactivate and delete the plugin.',
					'simple-sales-tax'
				),
				'desc_tip'    => true,
			),
			'debug_report_button'         => array(
				'title'       => __( 'Debug Report', 'simple-sales-tax' ),
				'label'       => __( 'Download', 'simple-sales-tax' ),
				'type'        => 'anchor',
				'url'         => add_query_arg( array( 'download_debug_report' => true, 'nonce' => wp_create_nonce( 'sst_debug_report' ) ) ),
				'id'          => 'debug_report_button',
				'description' => __(
					'Send a copy of this report to TaxCloud support to help with debugging TaxCloud for WooCommerce issues.',
					'simple-sales-tax'
				),
				'desc_tip'    => true,
			),
		);

		return apply_filters( 'sst_settings_form_fields', $fields, self::get_settings() );
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
			self::$settings[ $key ] = self::get_default( $key );
		}

		$value = self::$settings[ $key ];

		if ( ! is_null( $empty_value ) && '' === $value ) {
			$value = $empty_value;
		}

		return apply_filters( 'sst_get_option', $value, $key );
	}

	/**
	 * Get the default capture trigger from saved settings.
	 *
	 * @return string Capture trigger option.
	 */
	protected static function get_default_capture_trigger() {
		if ( empty( self::$settings ) ) {
			self::load_settings();
		}

		if ( 'payment_complete' === ( self::$settings['capture_trigger'] ?? '' ) ) {
			return 'processing';
		}

		if ( ! empty( self::$settings['capture_trigger'] ) ) {
			return self::$settings['capture_trigger'];
		}

		return 'yes' === ( self::$settings['capture_immediately'] ?? '' ) ? 'processing' : 'completed';
	}

	/**
	 * Get the order capture trigger.
	 *
	 * @return string One of completed or processing.
	 */
	public static function get_capture_trigger() {
		$valid   = array( 'completed', 'processing', 'both' );
		$trigger = self::get_default_capture_trigger();

		if ( in_array( $trigger, $valid, true ) ) {
			return $trigger;
		}

		return 'completed';
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

	/**
	 * Get SST settings
	 *
	 * @return array
	 */
	public static function get_settings() {
		if ( empty( self::$settings ) ) {
			self::load_settings();
		}

		return self::$settings;
	}

}
