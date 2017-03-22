<?php

/**
 * WooTax functions.
 *
 * Utility functions used throughout the plugin.
 *
 * @author 	Simple Sales Tax
 * @package SST
 * @since 	4.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * If the passed address has not been validated already, run it through
 * TaxCloud's VerifyAddress API.
 *
 * @since 4.2
 *
 * @param  array $address
 * @param  int $order_id -1 if the function is called during checkout. Otherwise, curent order ID.
 * @return array
 */
function maybe_validate_address( $address, $order_id = -1 ) {
	$hash = md5( json_encode( $address ) );

	// Determine if validation is necessary
	if ( $order_id == -1 ) {
		$validated_addresses = WC()->session->get( 'validated_addresses', array() );
	} else {
		$validated_addresses = get_post_meta( $order_id, '_wootax_validated_addresses', true );
		$validated_addresses = ! is_array( $validated_addresses ) ? array() : $validated_addresses;
	}

	$needs_validate = ! array_key_exists( $hash, $validated_addresses );

	// Either validate address or return validated address
	if ( ! $needs_validate ) {
		return $validated_addresses[ $hash ];
	} else {
		$final_address = $address;

		// All array keys/values must be lowercase for validation to work properly
		$address = array_map( 'strtolower', $address );
		$address = array_change_key_case( $address );

		// Unset country field; persist for later restoration
		$country = '';
		
		if ( isset( $address[ 'country' ] ) ) {
			$country = $address[ 'country' ];
			unset( $address[ 'country' ] );
		}

		$usps_id = SST()->get_option( 'usps_id' );

		if ( $usps_id ) {
			$address[ 'uspsUserID' ] = $usps_id; // USPS Web Tools ID is required for calls to VerifyAddress

			$res = TaxCloud()->send_request( 'VerifyAddress', $address );

			// Check for errors
			if ( $res !== false ) {
				unset( $res->ErrNumber );
				unset( $res->ErrDescription );

				// Restore Country field
				$res->Country = $country;

				// Restore address field
				if ( ! isset( $res->Address2 ) ) {
					$res->Address2 = '';
				}

				$final_address = (array) $res;
			} 
		}

		// Update address in $validated_addresses array
		$validated_addresses[ $hash ] = $final_address;

		// Store validated addresses in session or order meta depending on context
		if ( $order_id == -1 ) {
			WC()->session->set( 'validated_addresses', $validated_addresses );
		} else {
			update_post_meta( $order_id, '_wootax_validated_addresses', $validated_addresses );
		}
	} 
		
	return $final_address;
}

/**
 * Returns an array of origin addresses for a given product. If no origin
 * addresses have been configured, returns default origin address.
 *
 * @since 3.8
 *
 * @param  int $product_id
 * @return array
 */
function fetch_product_origin_addresses( $product_id ) {
	// We might receive a product variation id; to ensure that we have the actual product id, instantiate a WC_Product object
	$product = get_product( $product_id );
	$id      = isset( $product->parent ) ? $product->parent->id : $product_id;

	// Fetch origin addresses array
	$raw_origin_addresses = get_post_meta( $product_id, '_wootax_origin_addresses', true );		

	// Set origin address array to default if it hasn't been configured for this product
	if ( ! is_array( $raw_origin_addresses ) || count( $raw_origin_addresses ) == 0 ) {
		$default_address = SST()->get_option( 'default_address' ) == false ? 0 : SST()->get_option( 'default_address' );
		$origin_addresses = array( $default_address );
	} else {
		$origin_addresses = $raw_origin_addresses;
	} 

	return $origin_addresses;
}

/**
 * Returns an array of all businesses addresses configured by the admin.
 *
 * @since 3.8
 *
 * @return array
 */
function fetch_business_addresses() {
	$addresses = SST()->get_option( 'addresses' );

	// Ensures that users who upgraded from older versions of the plugin are still good to go
	if ( ! is_array( $addresses ) ) {
		$addresses = array(
			array(
				'address_1' => get_option( 'wootax_address1' ),
				'address_2' => get_option( 'wootax_address2' ),
				'country' 	=> 'United States', // hardcoded because this is the only option as of right now
				'state'		=> get_option( 'wootax_state' ),
				'city' 		=> get_option( 'wootax_city' ),
				'zip5'		=> get_option( 'wootax_zip5' ),
				'zip4'		=> get_option( 'wootax_zip4' ),
			)
		);
	}

	return $addresses;
}

/**
 * Converts an address array to a formatted string.
 *
 * @since 4.4
 *
 * @param  array $address
 * @return string
 */
function get_formatted_address( $address ) {
	return $address[ 'address_1' ] .', '. $address[ 'city' ] .', '. $address[ 'state' ] .' '. $address[ 'zip5' ];
}

/**
 * Generates an order ID for requests sent to TaxCloud.
 *
 * @since 4.2
 *
 * @return string
 */
function wootax_generate_order_id() { 
	return md5( $_SERVER['REMOTE_ADDR'] . microtime() );
}

/**
 * Parse a ZIP code and extract its 5 and 4 digit parts.
 *
 * @since 4.2
 *
 * @param  mixed $zip ZIP code.
 * @return array Associative array with two keys: 'zip5' and 'zip4'
 */
function parse_zip( $zip ) {
	$parsed_zip = array( 
		'zip5' => '',
		'zip4' => '',
	);

	if ( empty( $zip ) )
		return $parsed_zip;

	$new_zip = str_replace( array( ' ', '-' ), '', $zip );

	if ( strlen( $new_zip ) == 5 ) {
		$parsed_zip[ 'zip5' ] = $new_zip;
	} else if ( strlen( $new_zip ) == 4 ) {
		$parsed_zip[ 'zip4' ] = $new_zip;
	} else if ( strlen( $new_zip ) == 9 ) {
		$parsed_zip[ 'zip5' ] = substr( $new_zip, 0, 5 );
		$parsed_zip[ 'zip4' ] = substr( $new_zip, 5, 10 );
	} else {
		$parsed_zip[ 'zip5' ] = $zip; // Use original ZIP if ZIP does not fit required format (i.e. if it is an international postcode)
	}

	return $parsed_zip;
}

/**
 * Determines whether or not an address is "valid." 
 * For an address to be valid, country, city, state, address1, and ZIP must be provided.
 *
 * @since 4.6
 *
 */
/**
 * Determines whether an address is "valid." An address is considered to be
 * valid if a country, city, state, address, and ZIP code are provided.
 *
 * In addition, if $dest is true, the destination country must be the US.
 *
 * @since 4.6
 *
 * @param  array $address
 * @param  bool $dest True if destination address is being validated (default: false)
 * @return bool
 */
function wootax_is_valid_address( $address, $dest = false ) {
	// Normalize address array by converting all keys and values to lowercase
	$address = array_change_key_case( array_map( 'strtolower', $address ) );

	$required_fields = array( 'country', 'city', 'state', /*'address1',*/ 'zip5' );

	foreach ( $required_fields as $required ) {
		$val = isset( $address[ $required ] ) ? $address[ $required ] : '';
		
		if ( empty( $val ) )
			return false;
	}
	
	// If the destination country is not the US, return false
	if ( $dest == true && !in_array( strtolower( $address['country'] ), array( 'us', 'united states' ) ) )
		return false;
		
	return true;
}

/**
 * Get a list of user roles.
 *
 * @since 4.3
 *
 * @return array
 */
function wootax_get_user_roles() {
	global $wp_roles;

	if ( ! isset( $wp_roles ) ) {
	    $wp_roles = new WP_Roles();
	}

	return $wp_roles->get_names();
}

/**
 * Returns the email to which important notifications should be sent. Default
 * is admin email.
 *
 * @since Version
 *
 * @return string
 */
function wootax_get_notification_email() {
	$email = SST()->get_option( 'notification_email' );

	if ( $email ) {
		return $email;
	}

	$all_admins = get_users( array( 
		'role'   => 'administrator', 
		'number' => 1,
	) );

	return $all_admins[0]->user_email;
}

/**
 * Return business address with given location key.
 *
 * @since 4.4
 *
 * @param  int $index Location key.
 * @return array
 */
function wootax_get_address( $index ) {
	$addresses = fetch_business_addresses();

	$address = array(
		'Address1' => '',
		'Address2' => '',
		'Country'  => '',
		'State'    => '',
		'City'     => '',
		'Zip5'     => '',
		'Zip4'     => '',
	);

	if ( is_array( $addresses ) && isset( $addresses[ $index ] ) ) {
		$address[ 'Address1' ] = $addresses[ $index ][ 'address_1' ];
		$address[ 'Address2' ] = isset( $addresses[ $index ][ 'address_2' ] ) ? $addresses[ $index ][ 'address_2' ] : '';
		$address[ 'Country' ]  = $addresses[ $index ][ 'country' ];
		$address[ 'State' ]    = $addresses[ $index ][ 'state' ];
		$address[ 'City' ]     = $addresses[ $index ][ 'city' ];
		$address[ 'Zip5' ]     = $addresses[ $index ][ 'zip5' ];
		$address[ 'Zip4' ]     = $addresses[ $index ][ 'zip4' ];
	}

	return $address;
}

/**
 * Is the provided shipping method a local pickup method?
 *
 * @since 4.4
 *
 * @param  string $method Shipping method slug or name.
 * @return bool
 */
function wt_is_local_pickup( $method ) {
	return in_array( $method, apply_filters( 'wootax_local_pickup_methods', array( 'local_pickup', 'Local Pickup' ) ) );
}

/**
 * Is the provided shipping method a local delivery method?
 *
 * @since 4.4
 *
 * @param  string $method Method name or slug.
 * @return bool
 */
function wt_is_local_delivery( $method ) {
	return in_array( $method, apply_filters( 'wootax_local_delivery_methods', array( 'local_delivery', 'Local Delivery' ) ) );
}

/**
 * Are any extra tax rates present in the tax tables?
 *
 * @since 4.5
 *
 * @return bool
 */
function wt_has_other_rates() {
	global $wpdb;

	$query = "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_tax_rates";

	if ( SST_RATE_ID ) {
		$query .= " WHERE tax_rate_id != ". SST_RATE_ID;
	}

	$rate_count = $wpdb->get_var( $query );

	return $rate_count > 0;
}

/**
 * Get the TIC for a product. Return false if no TIC is set.
 *
 * @since 4.4
 *
 * @param  int $product_id
 * @param  int $variation_id
 * @return mixed
 */
function wt_get_product_tic( $product_id, $variation_id = null ) {
	$product_tic = get_post_meta( $product_id, 'wootax_tic', true );

	$variation_tic = false;

	if ( $variation_id )
		$variation_tic = get_post_meta( $variation_id, 'wootax_tic', true );

	$tic = $variation_tic ? $variation_tic : $product_tic;

	if ( empty( $tic ) )
		return false;

	return $tic;
}

/**
 * Output HTML for a WooTax help tip.
 *
 * @since 4.6
 *
 * @param string $tip Tooltip content.
 */
function wootax_tip( $tip ) {
	if ( function_exists( 'wc_help_tip' ) ) {
		echo wc_help_tip( $tip );
	} else {
		$img_path = WC()->plugin_url() . '/assets/images/help.png';
		$format = '<img class="help_tip" data-tip="%s" src="%s" height="16" width="16" />';
		printf( $format, $tip, $img_path );
	}
}
