<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Addresses.
 *
 * Contains methods for getting and validating origin and destination addresses.
 *
 * @author  Simple Sales Tax
 * @package SST
 * @since   5.0
 */
class SST_Addresses {

	/**
	 * Check whether a value behaves like an address object.
	 *
	 * @param mixed $address Address to inspect.
	 *
	 * @return bool
	 * @since 8.4.10
	 */
	public static function is_address_object( $address ) {
		return (
			is_object( $address ) &&
			method_exists( $address, 'getAddress1' ) &&
			method_exists( $address, 'getAddress2' ) &&
			method_exists( $address, 'getCity' ) &&
			method_exists( $address, 'getState' ) &&
			method_exists( $address, 'getZip5' ) &&
			method_exists( $address, 'getZip4' ) &&
			method_exists( $address, 'getZip' )
		);
	}

	/**
	 * Converts an Address to a formatted string.
	 *
	 * @param object $address Address to format.
	 *
	 * @return string
	 * @since 5.0
	 */
	public static function format( $address ) {
		return sprintf(
			'%s, %s, %s %s-%s',
			$address->getAddress1(),
			$address->getCity(),
			$address->getState(),
			$address->getZip5(),
			$address->getZip4()
		);
	}

	/**
	 * Determines whether an address is "valid." An address is considered to be
	 * valid if a city, state, address, and ZIP code are provided.
	 *
	 * @param object $address Address to check for validity.
	 *
	 * @return bool
	 * @since 5.0
	 */
	public static function is_valid( $address ) {
		if ( ! self::is_address_object( $address ) ) {
			return false;
		}

		$required = array( $address->getCity(), $address->getState(), $address->getZip5() );

		foreach ( $required as $value ) {
			if ( empty( $value ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Return business address with given location key.
	 *
	 * @param int $index Location key.
	 *
	 * @return SST_Origin_Address|NULL
	 * @since 5.0
	 */
	public static function get_address( $index ) {
		$addresses = self::get_origin_addresses();

		if ( isset( $addresses[ $index ] ) ) {
			return $addresses[ $index ];
		}

		return null;
	}

	/**
	 * Verify an address.
	 *
	 * @param object $address Address to verify with TaxCloud VerifyAddress API.
	 *
	 * @return object
	 * @since 5.0
	 */
	public static function verify_address( $address ) {
		$addresses = get_transient( 'sst_verified_addresses' );

		if ( ! is_array( $addresses ) ) {
			$addresses = array();
		}

		$md5_hash = md5( wp_json_encode( $address ) );

		if ( array_key_exists( $md5_hash, $addresses ) ) {
			$decoded = json_decode( $addresses[ $md5_hash ], true );
			$address = new \TaxCloud_V3\Model\Address( $decoded );
		} else {
			try {
				// Check rate limit.
				$rate_limit = new SST_Rate_Limit();
				if ( $rate_limit->limit_reached() ) {
					$rate_limit->log_limit_reached();
					return $address;
				}

				$address = self::verify_address_v3( $address );

				$rate_limit->increment_count();
				
				// Cache verified address.
				$addresses[ $md5_hash ] = wp_json_encode( $address );

				// Cache validated addresses for 3 days.
				set_transient( 'sst_verified_addresses', $addresses, 2 * DAY_IN_SECONDS );
			} catch ( Exception $ex ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				SST_Logger::add( 'Failed to verify address: ' . $ex->getMessage() );
				// Leave address as-is.
			}

		}

		return $address;
	}

	/**
	 * Verify an address via TaxCloud v3 API.
	 *
	 * @param object $address Address to verify.
	 *
	 * @return TaxCloud_V3\Model\Address
	 * @throws Exception When verification fails.
	 * @since 8.4.10
	 */
	private static function verify_address_v3( $address ) {
		$utilities = new \TaxCloud_V3\Utilities();
		$response  = $utilities->verify_address( $address );

		if ( is_wp_error( $response ) ) {
			throw new Exception( $response->get_error_message() );
		}

		if ( ! array_key_exists( 'line2', $response ) && method_exists( $address, 'getAddress2' ) ) {
			$response['line2'] = $address->getAddress2();
		}

		return new \TaxCloud_V3\Model\Address( $response );
	}

	/**
	 * Get all default origin addresses.
	 *
	 * @return SST_Origin_Address[]
	 * @since 5.0
	 */
	public static function get_default_addresses() {
		$return    = array();
		$addresses = self::get_origin_addresses();

		foreach ( $addresses as $address ) {
			if ( $address->getDefault() ) {
				$return[ $address->getID() ] = $address;
			}
		}

		return $return;
	}

	/**
	 * Get default pickup address.
	 *
	 * @return SST_Origin_Address|NULL
	 * @since 5.0
	 */
	public static function get_default_address() {
		$defaults = self::get_default_addresses();

		if ( ! empty( $defaults ) ) {
			return current( $defaults );
		}

		return null;
	}

	/**
	 * Get all business addresses configured by the admin.
	 *
	 * @param bool $fetch Set to fetch addresses from TaxCloud account.
	 *
	 * @return SST_Origin_Address[] Array of SST_Origin_Address.
	 * @since 5.0
	 */
	public static function get_origin_addresses( $fetch = false ) {
		$addresses       = [];
		$saved_addresses = array_map( 'json_decode', (array) SST_Settings::get( 'addresses', array() ) );

		if ( $fetch ) {
			SST_Logger::add( __( 'Origin address fetch from TaxCloud is not available in the v3 address flow. Using saved origin addresses.', 'simple-sales-tax' ) );
		}

		foreach ( $saved_addresses as $address ) {
			$addresses[ $address->ID ] = new SST_Origin_Address(
				$address->ID,
				$address->Default,
				$address->Address1,
				$address->Address2,
				$address->City,
				$address->State,
				$address->Zip5,
				$address->Zip4
			);
		}

		return $addresses;
	}

	/**
	 * Convert an SST_Origin_Address object to an Address object.
	 *
	 * @param SST_Origin_Address $address Origin address to convert to Address object.
	 *
	 * @return TaxCloud_V3\Model\Address|null
	 * @since 5.0
	 */
	public static function to_address( $address ) {
		if ( is_null( $address ) || ! is_a( $address, 'SST_Origin_Address' ) ) {
			return null;
		}

		try {
			return new \TaxCloud_V3\Model\Address(
				array(
					'city'        => $address->getCity(),
					'countryCode' => 'US',
					'line1'       => $address->getAddress1(),
					'line2'       => $address->getAddress2(),
					'state'       => $address->getState(),
					'zip'         => $address->getZip(),
				)
			);
		} catch ( Exception $ex ) {
			return null;
		}
	}

}
