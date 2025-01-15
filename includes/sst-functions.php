<?php
/**
 * SST functions.
 *
 * Utility functions used throughout the plugin.
 *
 * @author  Simple Sales Tax
 * @package SST
 * @since   5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use \TaxCloud\ExemptionCertificate;

/**
 * Output HTML for a help tip.
 *
 * @param string $tip Tooltip content.
 *
 * @since 5.0
 */
function sst_tip( $tip ) {
	if ( function_exists( 'wc_help_tip' ) ) {
		echo wc_help_tip( $tip ); // phpcs:ignore WordPress.Security.EscapeOutput
	} else {
		$img_path = WC()->plugin_url() . '/assets/images/help.png';
		$format   = '<img class="help_tip" data-tip="%s" src="%s" height="16" width="16" />';
		printf( $format, esc_attr( $tip ), esc_url( $img_path ) ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
}

/**
 * Given an "ugly" string, return the corresponding "pretty" string.
 *
 * @param string $ugly Ugly string to get pretty equivalent for.
 *
 * @return string Pretty string if found, otherwise original string.
 * @since 5.0
 */
function sst_prettify( $ugly ) {
	// Map from ugly string to pretty strings.
	$ugly_strings = array(
		'AccommodationAndFoodServices'            => 'Accommodation and Food Services',
		'Agricultural_Forestry_Fishing_Hunting'   => 'Agricultural/Forestry/Fishing/Hunting',
		'FinanceAndInsurance'                     => 'Finance and Insurance',
		'Information_PublishingAndCommunications' => 'Information Publishing and Communications',
		'RealEstate'                              => 'Real Estate',
		'RentalAndLeasing'                        => 'Rental and Leasing',
		'RetailTrade'                             => 'Retail Trade',
		'TransportationAndWarehousing'            => 'Transportation and Warehousing',
		'WholesaleTrade'                          => 'Wholesale Trade',
		'BusinessServices'                        => 'Business Services',
		'ProfessionalServices'                    => 'Professional Services',
		'EducationAndHealthCareServices'          => 'Education and Health Care Services',
		'NonprofitOrganization'                   => 'Nonprofit Organization',
		'NotABusiness'                            => 'Not a Business',
		'FederalGovernmentDepartment'             => 'Federal Government Department',
		'StateOrLocalGovernmentName'              => 'State or Local Government',
		'TribalGovernmentName'                    => 'Tribal Government',
		'ForeignDiplomat'                         => 'Foreign Diplomat',
		'CharitableOrganization'                  => 'Charitable Organization',
		'ReligiousOrEducationalOrganization'      => 'Religious or Educational Organization',
		'AgriculturalProduction'                  => 'Agricultural Production',
		'IndustrialProductionOrManufacturing'     => 'Industrial Production or Manufacturing',
		'DirectPayPermit'                         => 'Direct Pay Permit',
		'DirectMail'                              => 'Direct Mail',
		'AL'                                      => 'Alabama',
		'AK'                                      => 'Alaska',
		'AZ'                                      => 'Arizona',
		'AR'                                      => 'Arkansas',
		'CA'                                      => 'California',
		'CO'                                      => 'Colorado',
		'CT'                                      => 'Connecticut',
		'DE'                                      => 'Delaware',
		'FL'                                      => 'Florida',
		'GA'                                      => 'Georgia',
		'HI'                                      => 'Hawaii',
		'ID'                                      => 'Idaho',
		'IL'                                      => 'Illinois',
		'IN'                                      => 'Indiana',
		'IA'                                      => 'Iowa',
		'KS'                                      => 'Kansas',
		'KY'                                      => 'Kentucky',
		'LA'                                      => 'Louisiana',
		'ME'                                      => 'Maine',
		'MD'                                      => 'Maryland',
		'MA'                                      => 'Massachusetts',
		'MI'                                      => 'Michigan',
		'MN'                                      => 'Minnesota',
		'MS'                                      => 'Mississippi',
		'MO'                                      => 'Missouri',
		'MT'                                      => 'Montana',
		'NE'                                      => 'Nebraska',
		'NV'                                      => 'Nevada',
		'NH'                                      => 'New Hampshire',
		'NJ'                                      => 'New Jersey',
		'NM'                                      => 'New Mexico',
		'NY'                                      => 'New York',
		'NC'                                      => 'North Carolina',
		'ND'                                      => 'North Dakota',
		'OH'                                      => 'Ohio',
		'OK'                                      => 'Oklahoma',
		'OR'                                      => 'Oregon',
		'PA'                                      => 'Pennsylvania',
		'RI'                                      => 'Rhode Island',
		'SC'                                      => 'South Carolina',
		'SD'                                      => 'South Dakota',
		'TN'                                      => 'Tennessee',
		'TX'                                      => 'Texas',
		'UT'                                      => 'Utah',
		'VT'                                      => 'Vermont',
		'VA'                                      => 'Virginia',
		'WA'                                      => 'Washington',
		'DC'                                      => 'Washington DC',
		'WV'                                      => 'West Virginia',
		'WI'                                      => 'Wisconsin',
		'WY'                                      => 'Wyoming',
	);

	if ( array_key_exists( $ugly, $ugly_strings ) ) {
		return $ugly_strings[ $ugly ];
	} else {
		return $ugly;
	}
}

/**
 * Create a new shipping package from the given array, using default values
 * for all keys that are omitted.
 *
 * @param array $package Initial values for package.
 *
 * @return array
 * @since 5.0
 */
function sst_create_package( $package = array() ) {
	$defaults = array(
		'contents'    => array(),
		'fees'        => array(),
		'shipping'    => null,
		'map'         => array(),
		'user'        => array(),
		'request'     => null,
		'response'    => null,
		'origin'      => null,
		'destination' => null,
		'certificate' => null,
	);

	return wp_parse_args( $package, $defaults );
}

/**
 * Strip all slashes from a given value.
 *
 * @param string $value Value to strip slashes from.
 *
 * @return string
 * @since 5.4
 */
function sst_unslash( $value ) {
	while ( strstr( $value, '\\\\' ) ) {
		$value = stripslashes( $value );
	}

	return $value;
}

/**
 * Return an API client instance.
 *
 * @return \TaxCloud\Client
 * @since 5.0
 */
function TaxCloud() {
	return new TaxCloud\Client();
}

/**
 * Returns a list of all available TICs. The list will be updated if it is more
 * than one week old.
 *
 * @return SST_TIC[]
 * @since 5.9
 */
function sst_get_tics() {
	$tics = get_transient( 'sst_tics' );

	if ( false === $tics ) {
		$tics = array();

		try {
			$tics = TaxCloud()->GetTICs(
				new \TaxCloud\Request\GetTICs( SST_Settings::get( 'tc_id' ), SST_Settings::get( 'tc_key' ) )
			);

			set_transient( 'sst_tics', $tics, WEEK_IN_SECONDS );
		} catch ( Exception $ex ) {
			wc_get_logger()->error( "Failed to update TaxCloud TICs: {$ex->getMessage()}" );
		}
	}

	foreach ( $tics as $id => $description ) {
		$tics[ $id ] = new SST_TIC( $id, $description );
	}

	return $tics;
}

/**
 * Outputs the TIC select field.
 *
 * @param array $args Optional field args.
 */
function sst_output_tic_select_field( $args = array() ) {
	$defaults = array(
		'field_name'   => 'wootax_tic',
		'default_text' => __( 'Using site default', 'simple-sales-tax' ),
		'value'        => '',
		'button_class' => 'button',
		'field_class'  => '',
	);

	if ( isset( $args['product_id'] ) ) {
		$product_id = $args['product_id'];

		$defaults['field_name'] = sprintf( 'wootax_tic[%d]', $product_id );
		$defaults['value']      = get_post_meta( $product_id, 'wootax_tic', true );

		if ( 'product_variation' === get_post_type( $product_id ) ) {
			$defaults['default_text'] = __( 'Same as parent', 'simple-sales-tax' );
		}
	}

	$args = wp_parse_args( $args, $defaults );

	$script_data = array(
		'tic_list'               => sst_get_tics(),
		'tic_select_init_events' => sst_get_tic_select_init_events(),
	);
	wp_localize_script( 'sst-tic-select', 'ticSelectLocalizeScript', $script_data );
	wp_enqueue_script( 'sst-tic-select' );

	wp_enqueue_style( 'sst-tic-select-css' );

	?>
	<span class="sst-selected-tic" data-default="<?php echo esc_attr( $args['default_text'] ); ?>">
		<?php echo esc_html( $args['default_text'] ); ?>
	</span>
	<input type="hidden" name="<?php echo esc_attr( $args['field_name'] ); ?>"
	       class="sst-tic-input <?php echo esc_attr( $args['field_class'] ); ?>"
		   value="<?php echo esc_attr( $args['value'] ); ?>">
	<button type="button" class="<?php echo esc_attr( $args['button_class'] ); ?> sst-select-tic">
		<?php esc_html_e( 'Select', 'simple-sales-tax' ); ?>
	</button>
	<?php

	add_action( 'admin_footer', 'sst_print_tic_select_modal_template' );
	add_action( 'wp_footer', 'sst_print_tic_select_modal_template' );
}

/**
 * Prints the Underscores template for the TIC select modal.
 */
function sst_print_tic_select_modal_template() {
	require_once __DIR__ . '/views/html-select-tic-modal.php';
}

/**
 * Gets a list of JavaScript events that trigger initialization of TIC selects.
 *
 * @return string Space separated list of JS events to trigger TIC select init.
 */
function sst_get_tic_select_init_events() {
	return apply_filters( 'wootax_tic_select_init_events', 'woocommerce_variations_loaded' );
}

/**
 * Gets the help text / description to use for the TIC select field.
 *
 * @return string
 */
function sst_get_tic_select_help_text() {
	$default_help_text = __(
		'The TIC is used to determine the appropriate sales tax rate for your product. If your product is exempt from sales tax or qualifies for reduced tax rates, please make sure you select an appropriate TIC.',
		'simple-sales-tax'
	);

	return apply_filters( 'wootax_tic_select_help_text', $default_help_text );
}

/**
 * Calculates the taxes for an order using the TaxCloud API.
 *
 * @param WC_Order|int $order Order object or order ID.
 *
 * @return bool|WP_Error True on success, WP_Error instance on failure.
 */
function sst_order_calculate_taxes( $order ) {
	if ( is_numeric( $order ) ) {
		$order = wc_get_order( $order );
	}

	if ( ! is_a( $order, 'WC_Order' ) ) {
		return new WP_Error( 'invalid_order', 'Invalid order.' );
	}

	$_order = new SST_Order( $order );

	try {
		$_order->calculate_taxes();
		$_order->calculate_totals( false );
	} catch ( Exception $ex ) {
		return new WP_Error( 'calculate_error', $ex->getMessage() );
	}

	return true;
}

/**
 * Transforms a list of WooCommerce order items into a format that the SST tax
 * calculation logic can understand.
 *
 * @param WC_Order_Item[] $items Order items.
 *
 * @return array Order items formatted for tax calculations
 */
function sst_format_order_items( $items ) {
	$new_items = array();

	foreach ( $items as $item_id => $item ) {
		$product_id = $item['variation_id'] ? $item['variation_id'] : $item['product_id'];
		$product    = wc_get_product( $product_id );
		if ( $product ) {
			$new_items[ $item_id ] = array(
				'key'           => $item_id,
				'product_id'    => $item['product_id'],
				'variation_id'  => $item['variation_id'],
				'quantity'      => $item['qty'],
				'line_total'    => $item['line_total'],
				'line_subtotal' => $item['line_subtotal'],
				'data'          => $product,
			);
		}
	}

	return $new_items;
}

/**
 * Gets the shipping address for an order.
 *
 * @param int|WC_Order $order Order ID or order object.
 *
 * @return array
 */
function sst_get_order_shipping_address( $order ) {
	if ( ! is_object( $order ) ) {
		$order = wc_get_order( $order );
	}

	if ( ! $order ) {
		return array();
	}

	return array(
		'country'   => $order->get_shipping_country(),
		'address'   => $order->get_shipping_address_1(),
		'address_2' => $order->get_shipping_address_2(),
		'city'      => $order->get_shipping_city(),
		'state'     => $order->get_shipping_state(),
		'postcode'  => $order->get_shipping_postcode(),
	);
}

/**
 * Renders the Sales Tax meta box.
 *
 * @param SST_Order $order The order being edited.
 */
function sst_render_tax_meta_box( $order ) {
	$status      = $order->get_taxcloud_status( 'view' );
	$certificate = $order->get_single_purchase_certificate();

	if ( $certificate instanceof ExemptionCertificate ) {
		$certificate = SST_Certificates::format_certificate( $certificate );
	}

	wp_enqueue_script( 'sst-meta-box' );
	wp_localize_script(
		'sst-meta-box',
		'SSTMetaBox',
		array(
			'edit_user_url'               => add_query_arg(
				'user_id',
				'{user_id}',
				admin_url('user-edit.php#exemption_certificates')
			),
			'order_status'                => $order->get_taxcloud_status(),
			'selected_certificate'        => $order->get_certificate_id(),
			'single_purchase_certificate' => $certificate,
			'single_purchase_cert_id'     => SST_SINGLE_PURCHASE_CERT_ID,
			'get_certificates_nonce'      => wp_create_nonce( 'sst_get_certificates' ),
			'i18n'                        => array(
				'none'                        => __( 'None', 'simple-sales-tax' ),
				'single_purchase_certificate' => __( 'Single Purchase Certificate', 'simple-sales-tax' ),
				'certificate_added'           => __(
					"Certificate added successfully! Don't forget to recalculate taxes.",
					'simple-sales-tax'
				),
				'add_certificate_failed'      => __(
					'Failed to add certificate',
					'simple-sales-tax'
				),
			),
		)
	);

	sst_load_template(
		'includes/views/html-meta-box.php',
		array( 'status' => $status )
	);
	sst_load_template( 'includes/views/html-add-certificate-modal.php' );
	sst_load_template( 'includes/views/html-view-certificate.php' );
}

add_action( 'sst_output_tax_meta_box', 'sst_render_tax_meta_box' );

/**
 * Loads a SST template.
 *
 * @param string $path Template path relative to SST root.
 * @param array  $args Template args.
 */
function sst_load_template( $path, $args = array() ) {
	$full_path = SST()->path( $path );

	if ( ! file_exists( $full_path ) ) {
		return;
	}

	require $full_path;
}

/**
 * Renders a table of a user's exemption certificates and enqueues
 * all required assets to make it function.
 *
 * @param int   $user_id User ID.
 * @param array $options Options to pass to the certificate list template.
 */
function sst_render_certificate_table( $user_id = 0, $options = array() ) {
	if ( ! $user_id ) {
		$user_id = get_current_user_id();
	}

	$customer        = new WC_Customer( $user_id );
	$billing_address = array(
		'first_name' => $customer->get_billing_first_name(),
		'last_name'  => $customer->get_billing_last_name(),
		'address_1'  => $customer->get_billing_address_1(),
		'address_2'  => $customer->get_billing_address_2(),
		'country'    => $customer->get_billing_country(),
		'city'       => $customer->get_billing_city(),
		'state'      => $customer->get_billing_state(),
		'postcode'   => $customer->get_billing_postcode(),
	);

	$script_data = array(
		'delete_certificate_nonce' => wp_create_nonce( 'sst_delete_certificate' ),
		'ajaxurl'                  => admin_url( 'admin-ajax.php' ),
		'strings'                  => array(
			'delete_failed'      => __( 'Failed to delete certificate', 'simple-sales-tax' ),
			'add_failed'         => __( 'Failed to add certificate', 'simple-sales-tax' ),
			'delete_certificate' => __(
				'Are you sure you want to delete this certificate? This action is irreversible.',
				'simple-sales-tax'
			),
		),
		'user_id'                  => $user_id,
		'certificates'             => SST_Certificates::get_certificates_formatted( $user_id ),
		'billing_address'          => $billing_address,
	);

	wp_enqueue_style( 'sst-certificate-modal-css' );
	wp_enqueue_script( 'sst-certificate-table' );
	wp_localize_script(
		'sst-certificate-table',
		'SST_Certificate_Table_Data',
		$script_data
	);

	sst_load_template( 'includes/views/html-certificate-list.php', $options );

	$footer_hook = is_admin() ? 'admin_footer' : 'wp_footer';

	add_action( $footer_hook, function() {
		sst_load_template( 'includes/views/html-add-certificate-modal.php' );
		sst_load_template( 'includes/views/html-view-certificate.php' );
	} );
}

/**
 * Gets the TIC to use for shipping charges.
 *
 * @param string $method_id Shipping method ID.
 *
 * @return string TIC to use for shipping charges.
 */
function sst_get_shipping_tic( $method_id ) {
	$default_tic = SST_Settings::get( 'shipping_tic' );
	return apply_filters( 'sst_shipping_tic', $default_tic, $method_id );
}

/**
 * Checks whether the current logged in user has an exempt user role.
 *
 * @since 7.1
 * @return bool Does the current user have an exempt user role?
 */
function sst_is_user_tax_exempt() {
	$current_user = wp_get_current_user();
	$exempt_roles = SST_Settings::get( 'exempt_roles', array() );
	$user_roles   = is_user_logged_in() ? $current_user->roles : array();
	$is_exempt    = count( array_intersect( $exempt_roles, $user_roles ) ) > 0;

	return apply_filters(
		'sst_is_user_tax_exempt',
		$is_exempt,
		$exempt_roles,
		$current_user
	);
}

/**
 * Checks whether the tax exemption UI should be displayed to the current user.
 *
 * @since 7.1
 * @return bool Whether the tax exemption UI should be displayed
 */
function sst_should_show_tax_exemption_form() {
	$restricted = 'yes' === SST_Settings::get( 'restrict_exempt' );
	$enabled    = 'true' === SST_Settings::get( 'show_exempt' );

	return apply_filters(
		'sst_show_tax_exemption_form',
		$enabled && ( ! $restricted || sst_is_user_tax_exempt() )
	);
}

/**
 * Gets the label to use for the SST tax rate.
 *
 * @since 8.1
 * @return string Label for SST tax rate
 */
function sst_get_rate_label() {
	return apply_filters( 'wootax_rate_label', __( 'Sales Tax', 'simple-sales-tax' ) );
}

/**
 * Get the rate code to use for the SST tax rate.
 *
 * @since 8.1
 * @return string Rate code for SST tax rate
 */
function sst_get_rate_code() {
	return apply_filters( 'wootax_rate_code', 'SALES-TAX' );
}
