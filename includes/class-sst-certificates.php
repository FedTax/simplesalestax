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
	 * Get saved exemption certificates for the current customer.
	 *
	 * @param int $user_id WordPress user ID for customer (default: 0).
	 *
	 * @return TaxCloud\ExemptionCertificate[]
	 * @since 5.0
	 */
	public static function get_certificates( $user_id = 0 ) {
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
			$certificates = json_decode( $raw_certs, true );

			foreach ( $certificates as $key => $certificate ) {
				$certificates[ $key ] = TaxCloud\ExemptionCertificate::fromArray( $certificate );
			}
		} else {
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
		$detail    = $certificate->getDetail();
		$formatted = array(
			'CertificateID'              => $certificate->getCertificateID(),
			'PurchaserName'              => $detail->getPurchaserFirstName() . ' ' . $detail->getPurchaserLastName(),
			'CreatedDate'                => gmdate( 'm/d/Y', strtotime( $detail->getCreatedDate() ) ),
			'PurchaserAddress'           => $detail->getPurchaserAddress1(),
			'PurchaserState'             => sst_prettify( $detail->getPurchaserState() ),
			'PurchaserExemptionReason'   => sst_prettify( $detail->getPurchaserExemptionReason() ),
			'SinglePurchase'             => $detail->getSinglePurchase(),
			'SinglePurchaserOrderNumber' => $detail->getSinglePurchaseOrderNumber(),
			'TaxType'                    => sst_prettify( $detail->getPurchaserTaxID()->getTaxType() ),
			'IDNumber'                   => $detail->getPurchaserTaxID()->getIDNumber(),
			'PurchaserBusinessType'      => sst_prettify( $detail->getPurchaserBusinessType() ),
			'Description'                => self::get_certificate_description(
				$detail
			),
			'SellerName'                 => SST_Settings::get( 'company_name' ),
		);

		return $formatted;
	}

	/**
	 * Get a text description of a certificate.
	 *
	 * @param TaxCloud\ExemptionCertificateDetail $detail Certificate details.
	 *
	 * @return string
	 */
	protected static function get_certificate_description( $detail ) {
		$state      = current( $detail->GetExemptStates() );
		$state_abbr = $state->GetStateAbbr();
		$id_type    = sst_prettify( $detail->getPurchaserTaxID()->getTaxType() );
		$id_number  = $detail->getPurchaserTaxID()->getIDNumber();
		$date       = gmdate( 'm/d/Y', strtotime( $detail->getCreatedDate() ) );

		return sprintf(
			/* translators: 1 - state issued, 2 - tax id, 3 - date created */
			__( '%1$s - %2$s (created %3$s)', 'simple-sales-tax' ),
			$state_abbr,
			$id_number,
			$date
		);
	}

	/**
	 * Get saved exemption certificates for a customer, formatted for display
	 * in the certificate table.
	 *
	 * @param int $user_id WordPress user ID for customer (default: 0).
	 *
	 * @return array
	 * @since 5.0
	 */
	public static function get_certificates_formatted( $user_id = 0 ) {
		$certificates = array();
		foreach ( self::get_certificates( $user_id ) as $id => $raw_cert ) {
			if ( empty( $id ) ) {
				continue;
			}
			$certificates[ $id ] = self::format_certificate( $raw_cert );
		}

		// Sort by created date ascending.
		uasort( $certificates, function( $cert_a, $cert_b ) {
			$date_a = strtotime( $cert_a['CreatedDate'] );
			$date_b = strtotime( $cert_b['CreatedDate'] );
			if ( $date_a === $date_b ) {
				return 0;
			}
			return $date_a < $date_b ? -1 : 1;
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

		if ( ! isset( $user->ID ) ) {
			return array(); /* Invalid user ID. */
		}

		try {
			if ( sst_get_api_version() === 'v3' ) {
			
				$v3_exemptions = new \TaxCloud_V3\Exemptions();
				$final_certs   = array();

				// Try fetching by ID and Username to catch legacy certificates
				$lookup_ids = array( (string) $user->ID, $user->user_login );

				foreach ( $lookup_ids as $lookup_id ) {
					$response = $v3_exemptions->get_certificates( array(
						'customerId' => $lookup_id,
					) );

					if ( ! is_wp_error( $response ) && isset( $response['items'] ) && is_array( $response['items'] ) ) {
						foreach ( $response['items'] as $item ) {
							if ( empty( $item['singlePurchase'] ) ) { /* Skip single certs */
								$cert = self::build_v1_cert_from_v3( $item );
								$final_certs[ $cert->getCertificateID() ] = $cert;
							}
						}
					}
				}

				return $final_certs;
			}

	

			$request = new \TaxCloud\Request\GetExemptCertificates(
				SST_Settings::get( 'tc_id' ),
				SST_Settings::get( 'tc_key' ),
				$user->ID
			);

			$certificates = TaxCloud()->GetExemptCertificates( $request );

			$final_certs = array();

			foreach ( $certificates as $certificate ) {
				$detail = $certificate->getDetail();
				if ( ! $detail->getSinglePurchase() ) { /* Skip single certs */
					$final_certs[ $certificate->getCertificateID() ] = $certificate;
				}
			}

			return $final_certs;
		} catch ( \Exception $ex ) {
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
				$abbr = $state['abbreviation'] ?? '';
				if ( ! defined( '\TaxCloud\State::' . $abbr ) ) {
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

				$v3_args = array(
					'customerId'           => (string) $user->ID,
					'customerName'         => trim( $detail->getPurchaserFirstName() . ' ' . $detail->getPurchaserLastName() ),
					'customerBusinessType' => $detail->getPurchaserBusinessType() ?: 'Other',
					'customerBusinessDescription' => $detail->getPurchaserBusinessTypeOtherValue(),
					'reason'               => $v3_reason,
					'reasonDescription'    => $reason_description,
					'address'              => array(
						'line1' => $detail->getPurchaserAddress1(),
						'line2' => $detail->getPurchaserAddress2(),
						'city'  => $detail->getPurchaserCity(),
						'state' => $detail->getPurchaserState(),
						'zip'   => substr( $detail->getPurchaserZip(), 0, 5 ),
					),
					'states' => $states
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

			// Invalidate cached certificates
			SST_Certificates::delete_certificates( $user->ID );

			return $certificate_id;
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
