<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Certificates.
 *
 * Used for creating, updating, and deleting customer exemption certificates.
 *
 * @author  Simple Sales Tax
 * @package SST
 * @since   5.0
 */
class SST_Certificates {

	/**
	 * Prefix for certificate transients.
	 *
	 * @var string
	 * @since 5.0
	 */
	const TRANS_PREFIX = '_sst_certificates_';

	/**
	 * Check whether certificates are cached for a customer without triggering an API fetch.
	 *
	 * @param int $user_id WordPress user ID (default: 0).
	 *
	 * @return bool True if cached in transient, false otherwise.
	 * @since 8.4.2
	 */
	public static function has_cached_certificates( $user_id = 0 ) {
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		if ( ! $user_id ) {
			return false;
		}

		return false !== get_transient( self::get_transient_name( $user_id ) );
	}

	/**
	 * Get saved exemption certificates for the current customer.
	 *
	 * @param int  $user_id          WordPress user ID for customer (default: 0).
	 * @param bool $fetch_if_missing Whether to fetch from API if not cached (default: true).
	 *
	 * @return TaxCloud\ExemptionCertificate[]
	 * @since 5.0
	 */
	public static function get_certificates( $user_id = 0, $fetch_if_missing = true ) {
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		if ( ! $user_id ) {
			return array();
		}

		// Get certificates, using cached certificates if possible.
		$trans_key    = self::get_transient_name( $user_id );
		$raw_certs    = get_transient( $trans_key );
		$certificates = array();

		if ( false !== $raw_certs ) {
			$raw_list = json_decode( $raw_certs, true );

			if ( is_array( $raw_list ) ) {
				foreach ( $raw_list as $key => $certificate ) {
					$cert_obj = TaxCloud\ExemptionCertificate::fromArray( $certificate );
					if ( $cert_obj ) {
						$certificates[ $key ] = $cert_obj;
					}
				}
			}
		} elseif ( $fetch_if_missing ) {
			$certificates = self::fetch_certificates( $user_id );
			self::set_certificates( $user_id, $certificates );
		}

		return $certificates;
	}

	/**
	 * Get a certificate by ID.
	 *
	 * @param string $id      Certificate ID.
	 * @param int    $user_id WordPress user ID (default: 0).
	 *
	 * @return TaxCloud\ExemptionCertificate|NULL
	 * @since 5.0
	 */
	public static function get_certificate( $id, $user_id = 0 ) {
		$certificates = self::get_certificates( $user_id );

		if ( isset( $certificates[ $id ] ) ) {
			return $certificates[ $id ];
		} else {
			return null;
		}
	}

	/**
	 * Get a certificate and return it formatted for display.
	 *
	 * @param string $id      Certificate ID.
	 * @param int    $user_id WordPress user ID (default: 0).
	 *
	 * @return array|NULL
	 * @since 5.0
	 */
	public static function get_certificate_formatted( $id, $user_id = 0 ) {
		$certificate = self::get_certificate( $id, $user_id );

		if ( ! is_null( $certificate ) ) {
			$certificate = self::format_certificate( $certificate );
		}

		return $certificate;
	}

	/**
	 * Format a certificate for display.
	 *
	 * @param TaxCloud\ExemptionCertificate $certificate Exemption certificate to display.
	 *
	 * @return array
	 * @since 5.0
	 */
	public static function format_certificate( $certificate ) {
		$detail         = $certificate->getDetail();
		$certificate_id = $certificate->getCertificateID();
		$created_date   = $detail->getCreatedDate();
		$created_time   = strtotime( $created_date );
		$exempt_states  = self::get_exempt_state_abbreviations( $detail );
		$purchaser_name = trim( $detail->getPurchaserFirstName() . ' ' . $detail->getPurchaserLastName() );
		$short_id       = self::get_short_certificate_id( $certificate_id );
		$formatted      = array(
			'CertificateID'              => $certificate_id,
			'CertificateLabel'           => $short_id
				? sprintf( __( 'ID %s…', 'simple-sales-tax' ), $short_id )
				: __( 'ID unavailable', 'simple-sales-tax' ),
			'PurchaserName'              => $purchaser_name ?: __( 'Purchaser not specified', 'simple-sales-tax' ),
			'CreatedDate'                => self::format_certificate_datetime( $created_time ),
			'CreatedTimestamp'           => $created_time ?: 0,
			'ExemptStates'               => $exempt_states,
			'ExemptStatesLabel'          => $exempt_states
				? implode( ', ', $exempt_states )
				: __( 'States not specified', 'simple-sales-tax' ),
			'PurchaserAddress'           => $detail->getPurchaserAddress1(),
			'PurchaserState'             => sst_prettify( $detail->getPurchaserState() ),
			'PurchaserExemptionReason'   => sst_prettify( $detail->getPurchaserExemptionReason() ) ?: __( 'Exemption certificate', 'simple-sales-tax' ),
			'SinglePurchase'             => $detail->getSinglePurchase(),
			'SinglePurchaserOrderNumber' => $detail->getSinglePurchaseOrderNumber(),
			'TaxType'                    => sst_prettify( $detail->getPurchaserTaxID()->getTaxType() ),
			'IDNumber'                   => $detail->getPurchaserTaxID()->getIDNumber(),
			'PurchaserBusinessType'      => sst_prettify( $detail->getPurchaserBusinessType() ) ?: __( 'Business type not specified', 'simple-sales-tax' ),
			'Description'                => self::get_certificate_description(
				$detail,
				$certificate_id
			),
			'CheckoutDescription'        => self::get_checkout_certificate_description(
				$detail,
				$certificate_id
			),
			'SellerName'                 => SST_Settings::get( 'company_name' ),
		);

		return $formatted;
	}

	/**
	 * Get a text description of a certificate.
	 *
	 * @param TaxCloud\ExemptionCertificateDetail $detail         Certificate details.
	 * @param string                              $certificate_id Certificate ID.
	 *
	 * @return string
	 */
	protected static function get_certificate_description( $detail, $certificate_id = '' ) {
		$states         = self::get_exempt_state_abbreviations( $detail );
		$state_label    = $states ? implode( ', ', $states ) : __( 'States not specified', 'simple-sales-tax' );
		$reason         = sst_prettify( $detail->getPurchaserExemptionReason() ) ?: __( 'Exemption certificate', 'simple-sales-tax' );
		$purchaser_name = trim( $detail->getPurchaserFirstName() . ' ' . $detail->getPurchaserLastName() );
		$created_time   = strtotime( $detail->getCreatedDate() );
		$created_date   = self::format_certificate_datetime( $created_time, true );
		$short_id       = self::get_short_certificate_id( $certificate_id );
		$parts          = array(
			$state_label,
			$reason,
		);

		if ( $purchaser_name ) {
			$parts[] = $purchaser_name;
		}

		/* translators: %s: certificate creation date. */
		$parts[] = sprintf( __( 'Added %s', 'simple-sales-tax' ), $created_date );

		if ( $short_id ) {
			/* translators: %s: first characters of the certificate ID. */
			$parts[] = sprintf( __( 'ID %s…', 'simple-sales-tax' ), $short_id );
		}

		return implode( ' • ', $parts );
	}

	/**
	 * Get a concise certificate description for checkout selectors.
	 *
	 * @param TaxCloud\ExemptionCertificateDetail $detail         Certificate details.
	 * @param string                              $certificate_id Certificate ID.
	 *
	 * @return string
	 */
	protected static function get_checkout_certificate_description( $detail, $certificate_id = '' ) {
		$states       = self::get_exempt_state_abbreviations( $detail );
		$state_label  = $states ? implode( ', ', $states ) : __( 'States not specified', 'simple-sales-tax' );
		$created_time = strtotime( $detail->getCreatedDate() );
		$short_id     = self::get_short_certificate_id( $certificate_id );
		$parts        = array(
			$state_label,
			self::format_checkout_certificate_datetime( $created_time ),
		);

		if ( $short_id ) {
			/* translators: %s: first characters of the certificate ID. */
			$parts[] = sprintf( __( '#%s', 'simple-sales-tax' ), $short_id );
		}

		return implode( ' · ', $parts );
	}

	/**
	 * Format a compact certificate creation time for checkout selectors.
	 *
	 * The current year is omitted to keep frequently used options concise.
	 *
	 * @param int $timestamp Unix timestamp.
	 *
	 * @return string
	 */
	protected static function format_checkout_certificate_datetime( $timestamp ) {
		if ( ! $timestamp ) {
			return __( 'Date unavailable', 'simple-sales-tax' );
		}

		$date_format = wp_date( 'Y', $timestamp ) === wp_date( 'Y' ) ? 'M j' : 'M j, Y';
		$time_format = get_option( 'time_format', 'g:i a' );

		return sprintf(
			/* translators: 1: certificate creation date, 2: certificate creation time. */
			__( '%1$s, %2$s', 'simple-sales-tax' ),
			wp_date( $date_format, $timestamp ),
			wp_date( $time_format, $timestamp )
		);
	}

	/**
	 * Format a certificate creation time in the WordPress site timezone.
	 *
	 * @param int  $timestamp Unix timestamp.
	 * @param bool $compact   Whether to use a compact date for option labels.
	 *
	 * @return string
	 */
	protected static function format_certificate_datetime( $timestamp, $compact = false ) {
		if ( ! $timestamp ) {
			return __( 'Date unavailable', 'simple-sales-tax' );
		}

		$date_format = $compact ? 'M j, Y' : get_option( 'date_format', 'F j, Y' );
		$time_format = get_option( 'time_format', 'g:i a' );

		return sprintf(
			/* translators: 1: certificate creation date, 2: certificate creation time. */
			__( '%1$s at %2$s', 'simple-sales-tax' ),
			wp_date( $date_format, $timestamp ),
			wp_date( $time_format, $timestamp )
		);
	}

	/**
	 * Get the state abbreviations covered by a certificate.
	 *
	 * @param TaxCloud\ExemptionCertificateDetail $detail Certificate details.
	 *
	 * @return string[]
	 */
	protected static function get_exempt_state_abbreviations( $detail ) {
		$states = array();

		foreach ( (array) $detail->getExemptStates() as $state ) {
			if ( is_object( $state ) && method_exists( $state, 'getStateAbbr' ) ) {
				$state_abbr = strtoupper( (string) $state->getStateAbbr() );
				if ( $state_abbr ) {
					$states[] = $state_abbr;
				}
			}
		}

		return array_values( array_unique( $states ) );
	}

	/**
	 * Get a compact, recognizable portion of a certificate ID.
	 *
	 * @param string $certificate_id Certificate ID.
	 *
	 * @return string
	 */
	protected static function get_short_certificate_id( $certificate_id ) {
		$certificate_id = trim( (string) $certificate_id );

		return $certificate_id ? substr( $certificate_id, 0, 8 ) : '';
	}

	/**
	 * Get saved exemption certificates for a customer, formatted for display
	 * in the certificate table.
	 *
	 * @param int  $user_id          WordPress user ID for customer (default: 0).
	 * @param bool $fetch_if_missing Whether to fetch from API if not cached (default: true).
	 *
	 * @return array
	 * @since 5.0
	 */
	public static function get_certificates_formatted( $user_id = 0, $fetch_if_missing = true ) {
		$certificates = array();
		$raw_certs    = self::get_certificates( $user_id, $fetch_if_missing );

		foreach ( $raw_certs as $id => $raw_cert ) {
			if ( empty( $id ) ) {
				continue;
			}
			try {
				$certificates[ $id ] = self::format_certificate( $raw_cert );
			} catch ( \Throwable $ex ) {
				SST_Logger::debug( sprintf( 'Error formatting certificate ID %s: %s', $id, $ex->getMessage() ) );
			}
		}

		// Sort newest first so the most relevant certificate is easiest to find.
		uasort( $certificates, function( $cert_a, $cert_b ) {
			$date_a = isset( $cert_a['CreatedTimestamp'] ) ? (int) $cert_a['CreatedTimestamp'] : 0;
			$date_b = isset( $cert_b['CreatedTimestamp'] ) ? (int) $cert_b['CreatedTimestamp'] : 0;
			if ( $date_a === $date_b ) {
				return strcmp( $cert_b['CertificateID'], $cert_a['CertificateID'] );
			}
			return $date_a < $date_b ? 1 : -1;
		} );

		return $certificates;
	}

	/**
	 * Set saved exemption certificates for a customer.
	 *
	 * @param int                             $user_id      WordPress user ID (default: 0).
	 * @param TaxCloud\ExemptionCertificate[] $certificates Saved certificates for user (default: array()).
	 *
	 * @since 5.0
	 */
	public static function set_certificates( $user_id = 0, $certificates = array() ) {
		set_transient( self::get_transient_name( $user_id ), wp_json_encode( $certificates ), 3 * DAY_IN_SECONDS );
	}

	/**
	 * Get the customer's saved exemption certificates from TaxCloud.
	 *
	 * @param int $user_id WordPress user ID (default: 0).
	 *
	 * @return array
	 * @since 5.0
	 */
	private static function fetch_certificates( $user_id = 0 ) {
		if ( ! $user_id ) {
			$user = wp_get_current_user();
		} else {
			$user = new \WP_User( $user_id );
		}

		if ( ! isset( $user->ID ) || ! $user->ID ) {
			return array(); /* Invalid user ID. */
		}

		$api_version  = sst_get_api_version();
		$api_login_id = SST_Settings::get( 'tc_id' );
		$api_key      = SST_Settings::get( 'tc_key' );

		$raw_lookup_ids = array_filter(
			array(
				(string) $user->ID,
				$user->user_email,
				get_user_meta( $user->ID, 'billing_email', true ),
				$user->user_login,
				'customer-' . $user->ID,
			)
		);

		$lookup_ids = array_values( array_unique( array_filter( array_map( 'strval', $raw_lookup_ids ) ) ) );

		try {
			$final_certs = array();

			// 1. Query TaxCloud v3 exemption certificates API in parallel across customer IDs.
			$v3_exemptions = new \TaxCloud_V3\Exemptions();
			$v3_items      = $v3_exemptions->get_certificates_for_customer_ids( $lookup_ids );

			if ( ! empty( $v3_items ) && is_array( $v3_items ) ) {
				foreach ( $v3_items as $item ) {
					if ( empty( $item['singlePurchase'] ) ) { /* Skip single certs */
						$cert = self::build_v1_cert_from_v3( $item );
						if ( $cert && $cert->getCertificateID() ) {
							$final_certs[ $cert->getCertificateID() ] = $cert;
						}
					}
				}
			}

			// If certificates found via V3, return them.
			if ( ! empty( $final_certs ) ) {
				return $final_certs;
			}

			// 2. Fallback: Query legacy v1 TaxCloud GetExemptCertificates endpoint.
			foreach ( $lookup_ids as $lookup_id ) {
				try {
					$request = new \TaxCloud\Request\GetExemptCertificates(
						$api_login_id,
						$api_key,
						$lookup_id
					);

					$certificates = TaxCloud()->GetExemptCertificates( $request );

					if ( ! empty( $certificates ) && is_iterable( $certificates ) ) {
						foreach ( $certificates as $certificate ) {
							if ( is_object( $certificate ) && method_exists( $certificate, 'getDetail' ) ) {
								$detail = $certificate->getDetail();
								if ( $detail && ! $detail->getSinglePurchase() ) { /* Skip single certs */
									$final_certs[ $certificate->getCertificateID() ] = $certificate;
								}
							}
						}
					}
				} catch ( \Throwable $ex ) {
					SST_Logger::debug( sprintf( 'TaxCloud V1 GetExemptCertificates error for lookup ID %s: %s', $lookup_id, $ex->getMessage() ) );
				}
			}

			return $final_certs;
		} catch ( \Throwable $ex ) {
			SST_Logger::debug( 'TaxCloud fetch_certificates error: ' . $ex->getMessage() );
			return array();
		}
	}

	/**
	 * Convert TaxCloud v3 exemption certificate format to equivalent v1.
	 *
	 * @param array $v3_cert V3 certificate formatted as array.
	 * @return \TaxCloud\ExemptionCertificate
	 */
	public static function build_v1_cert_from_v3( $v3_cert ) {
		$name_parts = explode( ' ', $v3_cert['customerName'] ?? '', 2 );
		$first_name = $name_parts[0];
		$last_name  = isset( $name_parts[1] ) ? $name_parts[1] : '';

		$v3_reason  = $v3_cert['reason'] ?? '';
		$reason_map = array(
			'ReligiousOrganization'   => 'ReligiousOrEducationalOrganization',
			'EducationalOrganization' => 'ReligiousOrEducationalOrganization',
			'FederalGovernment'       => 'FederalGovernmentDepartment',
			'StateOrLocalGovernment'  => 'StateOrLocalGovernmentName',
			'TribalGovernment'        => 'TribalGovernmentName',
			'IndustrialProduction'    => 'IndustrialProductionOrManufacturing',
		);
		$v1_reason = isset( $reason_map[ $v3_reason ] ) ? $reason_map[ $v3_reason ] : $v3_reason;
		if ( ! defined( '\TaxCloud\ExemptionReason::' . $v1_reason ) ) {
			$v1_reason = 'Other';
		}

		$exempt_states = array();
		if ( isset( $v3_cert['states'] ) && is_array( $v3_cert['states'] ) ) {
			foreach ( $v3_cert['states'] as $state ) {
				$abbr       = $state['abbreviation'] ?? '';
				$const_name = ( 'OR' === $abbr ) ? '_OR' : $abbr;
				if ( ! defined( '\TaxCloud\State::' . $const_name ) ) {
					continue; // Skip invalid states
				}

				$exempt_states[] = array(
					'StateAbbr'            => $abbr,
					'ReasonForExemption'   => $v1_reason,
					'IdentificationNumber' => '',
				);
			}
		}

		// TaxType and BusinessType mapped similarly
		$v3_business_type = $v3_cert['customerBusinessType'] ?? '';
		if ( ! defined( '\TaxCloud\BusinessType::' . $v3_business_type ) ) {
			$v3_business_type = 'Other';
		}

		$v1_cert = array(
			'CertificateID' => $v3_cert['certificateId'] ?? '',
			'Detail'        => array(
				'ExemptStates'                    => $exempt_states,
				'PurchaserTaxID'                  => array(
					'TaxType'      => 'FEIN',
					'IDNumber'     => '',
					'StateOfIssue' => '',
				),
				'SinglePurchase'                  => $v3_cert['singlePurchase'] ?? false,
				'SinglePurchaseOrderNumber'       => '',
				'PurchaserFirstName'              => $first_name,
				'PurchaserLastName'               => $last_name,
				'PurchaserTitle'                  => '',
				'PurchaserAddress1'               => $v3_cert['address']['line1'] ?? '',
				'PurchaserAddress2'               => $v3_cert['address']['line2'] ?? '',
				'PurchaserCity'                   => $v3_cert['address']['city'] ?? '',
				'PurchaserState'                  => $v3_cert['address']['state'] ?? '',
				'PurchaserZip'                    => $v3_cert['address']['zip'] ?? '',
				'PurchaserBusinessType'           => $v3_business_type,
				'PurchaserBusinessTypeOtherValue' => $v3_cert['customerBusinessDescription'] ?? '',
				'PurchaserExemptionReason'        => $v1_reason,
				'PurchaserExemptionReasonValue'   => $v3_cert['reasonDescription'] ?? '',
				'CreatedDate'                     => $v3_cert['createdDate'] ?? gmdate( 'c' ),
			),
		);

		return \TaxCloud\ExemptionCertificate::fromArray( $v1_cert );
	}

	/**
	 * Delete the customer's cached certificates.
	 *
	 * @param int $user_id WordPress user ID (default: 0).
	 *
	 * @since 5.0
	 */
	public static function delete_certificates( $user_id = 0 ) {
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		delete_transient( self::get_transient_name( $user_id ) );
	}

	/**
	 * Get name of transient where certificates are stored.
	 *
	 * @param int $user_id WordPress user ID.
	 *
	 * @return string
	 * @since 5.0
	 */
	private static function get_transient_name( $user_id ) {
		return self::TRANS_PREFIX . $user_id;
	}

	/**
	 * Build a certificate given certificate and purchaser data.
	 *
	 * @param array $certificate Certificate data.
	 * @param array $purchaser   Purchaser data.
	 *
	 * @return TaxCloud\ExemptionCertificate
	 * @throws Exception If certificate/purchaser data is invalid
	 */
	public static function build_certificate( $certificate, $purchaser ) {
		$exempt_state = new TaxCloud\ExemptState(
			$certificate['ExemptState'],
			$certificate['PurchaserExemptionReason'],
			$certificate['IDNumber']
		);

		$tax_id = new TaxCloud\TaxID(
			$certificate['TaxType'],
			$certificate['IDNumber'],
			$certificate['StateOfIssue']
		);

		return new TaxCloud\ExemptionCertificate(
			array( $exempt_state ),
			$certificate['SinglePurchase'] ?? false,
			$certificate['SinglePurchaserOrderNumber'] ?? '',
			$purchaser['first_name'],
			$purchaser['last_name'],
			'',
			$purchaser['address_1'],
			$purchaser['address_2'],
			$purchaser['city'],
			$purchaser['state'],
			$purchaser['postcode'],
			$tax_id,
			$certificate['PurchaserBusinessType'],
			$certificate['PurchaserBusinessTypeOtherValue'],
			$certificate['PurchaserExemptionReason'],
			$certificate['PurchaserExemptionReasonOtherValue']
		);
	}
	/**
	 * Add a new certificate for a particular user.
	 *
	 * @param array $certificate Certificate data.
	 * @param array $purchaser   Purchaser data.
	 * @param int   $user_id     Purchaser user ID (defaults to current user ID).
	 *
	 * @return string New certificate ID
	 * @throws If certificate creation fails
	 */
	public static function add_certificate( $certificate, $purchaser, $user_id = 0 ) {
		try {
			// Build certificate
			$certificate = self::build_certificate(
				$certificate,
				$purchaser
			);

			return self::add_certificate_object( $certificate, $user_id );
		} catch ( Throwable $ex ) {
			SST_Logger::add(
				sprintf(
					/* translators: 1 - error message */
					__(
						'Failed to add exemption certificate. Error was: %1$s',
						'simple-sales-tax'
					),
					$ex->getMessage()
				)
			);

			throw $ex;
		}
	}

	/**
	 * Add a pre-built exemption certificate object for a particular user.
	 *
	 * @param TaxCloud\ExemptionCertificate $certificate Certificate object.
	 * @param int                           $user_id     Purchaser user ID (defaults to current user ID).
	 *
	 * @return string New certificate ID
	 * @throws If certificate creation fails
	 */
	public static function add_certificate_object( $certificate, $user_id = 0 ) {
		try {
			// Validate user permissions
			$user = $user_id
				? get_user_by( 'id', $user_id )
				: wp_get_current_user();

			if ( ! $user ) {
				throw new Exception( "Invalid user ID '{$user_id}'" );
			}

			// Add certificate
			if ( sst_get_api_version() === 'v3' ) {
				$detail = $certificate->getDetail();
				
				$states = array();
				foreach ( $detail->getExemptStates() as $state ) {
					$states[] = array( 'abbreviation' => $state->getStateAbbr() );
				}

				// Map v1 exemption reason names to v3 API enum values.
				$v1_to_v3_reason_map = array(
					'FederalGovernmentDepartment'         => 'FederalGovernment',
					'StateOrLocalGovernmentName'          => 'StateOrLocalGovernment',
					'TribalGovernmentName'                => 'TribalGovernment',
					'ForeignDiplomat'                     => 'ForeignDiplomat',
					'CharitableOrganization'              => 'CharitableOrganization',
					'ReligiousOrEducationalOrganization'  => 'ReligiousOrganization',
					'Resale'                              => 'Resale',
					'AgriculturalProduction'              => 'AgriculturalProduction',
					'IndustrialProductionOrManufacturing' => 'IndustrialProductionOrManufacturing',
					'DirectPayPermit'                     => 'DirectPayPermit',
					'DirectMail'                          => 'DirectMail',
					'Other'                               => 'Other',
				);

				$v1_reason = $detail->getPurchaserExemptionReason() ?: 'Other';
				$v3_reason = isset( $v1_to_v3_reason_map[ $v1_reason ] ) ? $v1_to_v3_reason_map[ $v1_reason ] : 'Other';

				// v3 API enforces a strict 20-character limit on reasonDescription.
				$reason_description = $detail->getPurchaserExemptionReasonValue();
				if ( strlen( $reason_description ) > 20 ) {
					$reason_description = substr( $reason_description, 0, 20 );
				}

				// Map v1 business type names to v3 API enum values.
				$v1_to_v3_business_map = array(
					'Agricultural_Forestry_Fishing_Hunting'   => 'AgriculturalForestryFishingHunting',
					'Information_PublishingAndCommunications' => 'InformationPublishingAndCommunications',
				);

				$v1_business = $detail->getPurchaserBusinessType() ?: 'Other';
				$v3_business = isset( $v1_to_v3_business_map[ $v1_business ] ) ? $v1_to_v3_business_map[ $v1_business ] : $v1_business;

				$v3_args = array(
					'customerId'                  => (string) $user->ID,
					'customerName'                => trim( $detail->getPurchaserFirstName() . ' ' . $detail->getPurchaserLastName() ),
					'customerBusinessType'        => $v3_business,
					'customerBusinessDescription' => $detail->getPurchaserBusinessTypeOtherValue(),
					'reason'                      => $v3_reason,
					'reasonDescription'           => $reason_description,
					'address'                     => array(
						'line1' => $detail->getPurchaserAddress1(),
						'line2' => $detail->getPurchaserAddress2(),
						'city'  => $detail->getPurchaserCity(),
						'state' => $detail->getPurchaserState(),
						'zip'   => substr( $detail->getPurchaserZip(), 0, 5 ),
					),
					'states'                      => $states,
				);

				// Log the payload for debugging certificate creation issues.
				SST_Logger::add(
					__( 'V3 exemption certificate payload:', 'simple-sales-tax' ),
					$v3_args
				);

				$v3_exemptions = new \TaxCloud_V3\Exemptions();
				$response = $v3_exemptions->create_certificate( $v3_args );

				if ( is_wp_error( $response ) ) {
					throw new \Exception( $response->get_error_message() );
				}

				$certificate_id = $response['certificateId'];
			} else {
				$request = new \TaxCloud\Request\AddExemptCertificate(
					SST_Settings::get( 'tc_id' ),
					SST_Settings::get( 'tc_key' ),
					$user->ID,
					$certificate
				);

				$certificate_id = TaxCloud()->AddExemptCertificate( $request );
			}

			// Invalidate cached certificates if not a single purchase certificate
			if ( ! $certificate->getDetail()->getSinglePurchase() ) {
				SST_Certificates::delete_certificates( $user->ID );
			}

			return $certificate_id;
		} catch ( Throwable $ex ) {
			SST_Logger::add(
				sprintf(
					/* translators: 1 - error message */
					__(
						'Failed to add exemption certificate object. Error was: %1$s',
						'simple-sales-tax'
					),
					$ex->getMessage()
				)
			);

			throw $ex;
		}
	}

	/**
	 * Delete one of a user's saved exemption certificates.
	 *
	 * @param string $certificate_id Certificate ID.
	 * @param int    $user_id        Purchaser user ID.
	 *
	 * @throws If certificate deletion fails
	 */
	public static function delete_certificate( $certificate_id, $user_id = 0 ) {
		if ( 0 === $user_id ) {
			$user_id = get_current_user_id();
		}

		if ( ! self::user_can_delete_certificate( $user_id, $certificate_id ) ) {
			throw new Exception( 'Unauthorized' );
		}

		if ( sst_get_api_version() === 'v3' ) {
			$v3_exemptions = new \TaxCloud_V3\Exemptions();
			$response = $v3_exemptions->delete_certificate( $certificate_id );

			if ( is_wp_error( $response ) ) {
				throw new \Exception( $response->get_error_message() );
			}
		} else {
			$request = new \TaxCloud\Request\DeleteExemptCertificate(
				SST_Settings::get( 'tc_id' ),
				SST_Settings::get( 'tc_key' ),
				$certificate_id
			);

			TaxCloud()->DeleteExemptCertificate( $request );
		}

		// Invalidate cached certificates.
		SST_Certificates::delete_certificates( $user_id );
	}

	/**
	 * Checks whether the current user can delete an exemption certificate.
	 *
	 * @param int    $user_id        User ID of certificate owner.
	 * @param string $certificate_id Certificate ID.
	 *
	 * @return bool Can the user delete the certificate?
	 */
	protected static function user_can_delete_certificate( $user_id, $certificate_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return false;
		}

		$user_certificates = SST_Certificates::get_certificates( $user_id );

		foreach ( $user_certificates as $certificate ) {
			if ( $certificate->getCertificateID() === $certificate_id ) {
				return true;
			}
		}

		return false;
	}

}
