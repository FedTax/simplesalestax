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
		if ( 'v3' === sst_get_api_version() && ! self::is_address_object( $address ) ) {
			return false;
		}

		if ( 'v3' !== sst_get_api_version() && is_null( $address ) ) {
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
	 * @param object     $address Address to verify with TaxCloud VerifyAddress API.
	 * @param array|null $outcome Verification status, reason and jurisdiction changes.
	 *
	 * @return object
	 * @since 5.0
	 */
	public static function verify_address( $address, &$outcome = null ) {
		$outcome = array(
			'input_hash' => md5( wp_json_encode( $address ) ),
			'status'     => 'skipped',
			'reason'     => 'missing_street_or_zip',
			'changes'    => array(),
		);
		$is_v3   = 'v3' === sst_get_api_version();
		if (
			is_null( $address )
			|| ( $is_v3 && ! self::is_address_object( $address ) )
			|| ( ! $is_v3 && ! is_a( $address, 'TaxCloud\Address' ) )
		) {
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

		// Old entries cannot distinguish a success from a cached outage. Reverify
		// them once, and keep API versions separate in the existing transient.
		$cache_key = 'v2:' . sst_get_api_version() . ':' . $outcome['input_hash'];
		$cached    = $addresses[ $cache_key ] ?? null;
		if ( is_array( $cached ) && isset( $cached['outcome'], $cached['address'] ) ) {
			$outcome = $cached['outcome'];
			if ( 'rejected' === $outcome['status'] ) {
				return $address;
			}
			$data = $cached['address'];
			return $is_v3 ? new \TaxCloud_V3\Model\Address( $data ) : new \TaxCloud\Address(
				$data['Address1'], $data['Address2'], $data['City'], $data['State'], $data['Zip5'], $data['Zip4']
			);
		}

		try {
			// Check rate limit.
			$rate_limit = new SST_Rate_Limit();
			if ( $rate_limit->limit_reached() ) {
				$rate_limit->log_limit_reached();
				$outcome['reason'] = 'rate_limit_reached';
				return $address;
			}

			$rate_limit->increment_count();

			if ( $is_v3 ) {
				$verified = self::verify_address_v3( $address );
			} else {
				$request = new \TaxCloud\Request\VerifyAddress(
					SST_Settings::get( 'tc_id' ),
					SST_Settings::get( 'tc_key' ),
					$address
				);
				$verified = TaxCloud()->VerifyAddress( $request );
			}

			if ( is_wp_error( $verified ) ) {
				$data     = $verified->get_error_data();
				$status   = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
				$rejected = 'sst_v3_verify_address_error' === $verified->get_error_code()
					&& in_array( $status, array( 400, 422 ), true )
					&& ! preg_match( '/auth|credential|api ?key|login/i', $verified->get_error_message() );
				$outcome = array_merge( $outcome, self::verification_failure( $verified->get_error_message(), $rejected, 429 === $status ) );
				SST_Logger::add( 'Failed to verify address: ' . $verified->get_error_message() );
			} elseif ( ! self::is_address_object( $verified ) || ! self::is_valid( $verified ) || empty( $verified->getAddress1() ) ) {
				$outcome['status'] = 'transient_failure';
				$outcome['reason'] = 'taxcloud_unavailable';
			} else {
				$outcome['changes'] = self::get_jurisdiction_changes( $address, $verified );
				$outcome['status']  = $outcome['changes'] ? 'corrected' : 'verified';
				$outcome['reason']  = '';
				$address            = $verified;
			}
		} catch ( Exception $ex ) {
			SST_Logger::add( 'Failed to verify address: ' . $ex->getMessage() );
			// The v1 client wraps transport errors in VerifyAddressException too.
			// Only recognizable address errors are permanent rejections.
			$message  = $ex->getMessage();
			$rejected = $ex instanceof \TaxCloud\Exceptions\VerifyAddressException
				&& preg_match( '/address (?:was )?not found|invalid address|multiple addresses|(?:street|house|box) number.*(?:range|invalid)|(?:invalid|missing) (?:city|state|zip)|(?:city|state|zip).*mismatch|no (?:address )?match/i', $message )
				&& ! preg_match( '/auth|credential|api ?key|login|timeout|timed out|unavailable|rate.?limit|too many|server error|HTTP\s*5\d\d/i', $message );
			$outcome = array_merge( $outcome, self::verification_failure( $message, (bool) $rejected, (bool) preg_match( '/rate.?limit|too many requests/i', $message ) ) );
		}

		if ( in_array( $outcome['status'], array( 'verified', 'corrected', 'rejected' ), true ) ) {
			$addresses[ $cache_key ] = array( 'address' => json_decode( wp_json_encode( $address ), true ), 'outcome' => $outcome );
			set_transient( 'sst_verified_addresses', $addresses, 2 * DAY_IN_SECONDS );
		}

		return $address;
	}

	/** Build a safe, merchant-facing failure reason without exposing API errors. */
	private static function verification_failure( $message, $rejected, $rate_limited ) {
		return array(
			'status' => $rejected ? 'rejected' : 'transient_failure',
			'reason' => $rejected
				? ( preg_match( '/city|state|zip|postal|mismatch/i', $message ) ? 'city_zip_mismatch' : 'address_not_found' )
				: ( $rate_limited ? 'rate_limit_reached' : 'taxcloud_unavailable' ),
		);
	}

	/** Compare jurisdiction fields, ignoring case, street formatting and ZIP+4 alone. */
	private static function get_jurisdiction_changes( $input, $verified ) {
		$changes = array();
		foreach ( array( 'city' => 'getCity', 'state' => 'getState', 'zip' => 'getZip5' ) as $field => $getter ) {
			if ( 0 !== strcasecmp( trim( (string) $input->$getter() ), trim( (string) $verified->$getter() ) ) ) {
				$changes[ $field ] = array( 'from' => $input->$getter(), 'to' => $verified->$getter() );
			}
		}
		if ( $changes && ( isset( $changes['zip'] ) || (string) $input->getZip4() !== (string) $verified->getZip4() ) ) {
			$changes['zip'] = array( 'from' => self::verification_zip( $input ), 'to' => self::verification_zip( $verified ) );
		}
		return $changes;
	}

	private static function verification_zip( $address ) {
		return $address->getZip5() . ( $address->getZip4() ? '-' . $address->getZip4() : '' );
	}

	/** Format the private order note for an address verification outcome. */
	public static function get_verification_note( $outcome ) {
		if ( 'verified' === $outcome['status'] ) {
			return '';
		}
		if ( 'corrected' === $outcome['status'] ) {
			$changes = array();
			$labels  = array( 'city' => __( 'city', 'simple-sales-tax' ), 'state' => __( 'state', 'simple-sales-tax' ), 'zip' => __( 'ZIP', 'simple-sales-tax' ) );
			foreach ( $outcome['changes'] as $field => $change ) {
				$format    = 'zip' === $field ? '%s %s → %s' : '%s "%s" → "%s"';
				$changes[] = sprintf( $format, $labels[ $field ], sanitize_text_field( $change['from'] ), sanitize_text_field( $change['to'] ) );
			}
			/* translators: List of corrected city, state and ZIP fields. */
			return sprintf( __( 'TaxCloud verified the shipping address: %s', 'simple-sales-tax' ), implode( ', ', $changes ) );
		}
		$reasons = array(
			'address_not_found'     => __( 'address not found', 'simple-sales-tax' ),
			'city_zip_mismatch'     => __( 'city/ZIP mismatch', 'simple-sales-tax' ),
			'missing_street_or_zip' => __( 'missing street or ZIP', 'simple-sales-tax' ),
			'taxcloud_unavailable'  => __( 'TaxCloud unavailable', 'simple-sales-tax' ),
			'rate_limit_reached'   => __( 'rate limit reached', 'simple-sales-tax' ),
		);
		/* translators: Reason the shipping address could not be verified. */
		return sprintf( __( 'TaxCloud could not verify the shipping address (%s). Tax was calculated on the address as entered.', 'simple-sales-tax' ), $reasons[ $outcome['reason'] ] ?? $reasons['taxcloud_unavailable'] );
	}

	/** Persist outcomes and write each distinct private note once per order. */
	public static function save_order_verification( $order, $outcomes ) {
		if ( ! $outcomes ) {
			return;
		}
		$saved    = $order->get_meta( '_sst_shipping_address_verification', true );
		$outcomes = array_merge( is_array( $saved ) ? $saved : array(), $outcomes );
		$order->update_meta_data( '_sst_shipping_address_verification', $outcomes );
		$noted    = $order->get_meta( '_sst_shipping_address_verification_notes', true );
		$noted    = is_array( $noted ) ? $noted : array();
		foreach ( $outcomes as $outcome ) {
			$note = self::get_verification_note( $outcome );
			$key  = md5( wp_json_encode( $outcome ) );
			if ( $note && ! in_array( $key, $noted, true ) && $order->get_id() ) {
				$order->add_order_note( $note );
				$noted[] = $key;
			}
		}
		$order->update_meta_data( '_sst_shipping_address_verification_notes', $noted );
		$order->save_meta_data();
	}

	/**
	 * Verify an address via TaxCloud v3 API.
	 *
	 * @param object $address Address to verify.
	 *
	 * @return \TaxCloud_V3\Model\Address|WP_Error
	 * @since 8.4.10
	 */
	private static function verify_address_v3( $address ) {
		$utilities = new \TaxCloud_V3\Utilities();
		$response  = $utilities->verify_address( $address );

		if ( is_wp_error( $response ) ) {
			return $response;
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
				$zip = $address->getZip5();
				if ( '' !== (string) $address->getZip4() ) {
					$zip .= '-' . $address->getZip4();
				}

				return new \TaxCloud_V3\Model\Address(
					array(
						'city'        => $address->getCity(),
						'countryCode' => 'US',
						'line1'       => $address->getAddress1(),
						'line2'       => $address->getAddress2(),
						'state'       => $address->getState(),
						'zip'         => $zip,
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
