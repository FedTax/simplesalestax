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
		if ( is_null( $address ) || ! self::is_address_object( $address ) ) {
			return $address;
		}

		// TaxCloud VerifyAddress requires a street address (Address1) and Zip5.
		// If either is missing, skip the API call to avoid 400 Bad Request errors.
		if ( empty( $address->getAddress1() ) || empty( $address->getZip5() ) ) {
			return $address;
		}

		$addresses = get_transient( 'sst_verified_addresses' );

		if ( ! is_array( $addresses ) ) {
			$addresses = array();
		}

		$md5_hash = md5( wp_json_encode( $address ) );

		if ( array_key_exists( $md5_hash, $addresses ) ) {
			$decoded = json_decode( $addresses[ $md5_hash ], true );
			if ( sst_get_api_version() === 'v3' ) {
				$address = new \TaxCloud_V3\Model\Address( $decoded );
			} else {
				$address = new \TaxCloud\Address(
					isset( $decoded['Address1'] ) ? $decoded['Address1'] : ( isset( $decoded['line1'] ) ? $decoded['line1'] : '' ),
					isset( $decoded['Address2'] ) ? $decoded['Address2'] : ( isset( $decoded['line2'] ) ? $decoded['line2'] : null ),
					isset( $decoded['City'] ) ? $decoded['City'] : ( isset( $decoded['city'] ) ? $decoded['city'] : '' ),
					isset( $decoded['State'] ) ? $decoded['State'] : ( isset( $decoded['state'] ) ? $decoded['state'] : '' ),
					isset( $decoded['Zip5'] ) ? $decoded['Zip5'] : ( isset( $decoded['zip'] ) ? substr( preg_replace( '/[^0-9]/', '', $decoded['zip'] ), 0, 5 ) : '' ),
					isset( $decoded['Zip4'] ) ? $decoded['Zip4'] : ( isset( $decoded['zip'] ) && strlen( preg_replace( '/[^0-9]/', '', $decoded['zip'] ) ) > 5 ? substr( preg_replace( '/[^0-9]/', '', $decoded['zip'] ), 5, 4 ) : null )
				);
			}
		} else {
			try {
				// Check rate limit.
				$rate_limit = new SST_Rate_Limit();
				if ( $rate_limit->limit_reached() ) {
					$rate_limit->log_limit_reached();
					return $address;
				}

				$rate_limit->increment_count();

				if ( sst_get_api_version() === 'v3' ) {
					$address = self::verify_address_v3( $address );
				} else {
					$v1_address = is_a( $address, 'TaxCloud\Address' ) ? $address : new \TaxCloud\Address(
						$address->getAddress1(),
						$address->getAddress2(),
						$address->getCity(),
						$address->getState(),
						$address->getZip5(),
						$address->getZip4()
					);

					$request = new \TaxCloud\Request\VerifyAddress(
						SST_Settings::get( 'tc_id' ),
						SST_Settings::get( 'tc_key' ),
						$v1_address
					);
					$address = TaxCloud()->VerifyAddress( $request );
				}

				// Cache verified address.
				$addresses[ $md5_hash ] = wp_json_encode( $address );

				// Cache validated addresses for 2 days.
				set_transient( 'sst_verified_addresses', $addresses, 2 * DAY_IN_SECONDS );
			} catch ( Exception $ex ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				SST_Logger::add( 'Failed to verify address: ' . $ex->getMessage() );
				// Cache unverified address as-is to avoid repeated failed API requests.
				$addresses[ $md5_hash ] = wp_json_encode( $address );
				set_transient( 'sst_verified_addresses', $addresses, 2 * DAY_IN_SECONDS );
			}

		}

		return $address;
	}

	/**
	 * Verify an address via TaxCloud v3 API.
	 *
	 * @param object $address Address to verify.
	 *
	 * @return \TaxCloud_V3\Model\Address
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
			if ( sst_get_api_version() === 'v3' ) {
				SST_Logger::add( __( 'Origin address fetch from TaxCloud is not available in the v3 address flow. Using saved origin addresses.', 'simple-sales-tax' ) );
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
			} else {
				$api_id  = SST_Settings::get( 'tc_id' );
				$api_key = SST_Settings::get( 'tc_key' );
				if ( $api_id && $api_key ) {
					try {
						$request   = new \TaxCloud\Request\GetLocations( $api_id, $api_key );
						$locations = TaxCloud()->GetLocations( $request );
						foreach ( $locations as $location ) {
							$location_id = $location->getLocationID();
							$is_default  = false;
							if ( isset( $saved_addresses[ $location_id ] ) ) {
								$is_default = $saved_addresses[ $location_id ]->Default;
							}
							$addresses[ $location_id ] = new SST_Origin_Address(
								$location_id,
								$is_default,
								$location->GetAddress1(),
								$location->getAddress2(),
								$location->getCity(),
								$location->getState(),
								$location->getZip5(),
								$location->getZip4()
							);
						}
					} catch ( \TaxCloud\Exceptions\GetLocationsException $ex ) {
						SST_Logger::error( 'GetLocations request failed. Error was: ' . $ex->getMessage() );
					}
				}
			}
		} else {
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
		}

		return $addresses;
	}

	/**
	 * Convert an SST_Origin_Address object to an Address object.
	 *
	 * @param SST_Origin_Address $address Origin address to convert to Address object.
	 *
	 * @return \TaxCloud_V3\Model\Address|\TaxCloud\Address|null
	 * @since 5.0
	 */
	public static function to_address( $address ) {
		if ( is_null( $address ) || ! is_a( $address, 'SST_Origin_Address' ) ) {
			return null;
		}

		try {
			if ( sst_get_api_version() === 'v3' ) {
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
			}

			return new \TaxCloud\Address(
				$address->getAddress1(),
				$address->getAddress2(),
				$address->getCity(),
				$address->getState(),
				$address->getZip5(),
				$address->getZip4()
			);
		} catch ( Exception $ex ) {
			return null;
		}
	}

}
