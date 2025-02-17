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

//		if ( false !== $raw_certs ) {
		if ( false ) {
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
			'PurchaserName'              => $detail->getPurchaserFirstName(),
			'CreatedDate'                => date( 'm/d/Y', strtotime( $detail->getCreatedDate() ) ),
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
		$id_type    = "";//sst_prettify( $detail->getPurchaserTaxID()->getTaxType() );
		$id_number  = "";//$detail->getPurchaserTaxID()->getIDNumber();
		$date       = date( 'm/d/Y', strtotime( $detail->getCreatedDate() ) );

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
			$user = new WP_User( $user_id );
		}

		if ( ! isset( $user->ID ) ) {
			return array(); /* Invalid user ID. */
		}

		try {
			$request = new TaxCloud\Request\GetExemptCertificates(
				SST_Settings::get( 'tc_id' ),
				SST_Settings::get( 'tc_key' ),
				$user->user_login
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
		} catch ( Exception $ex ) {
			return array();
		}
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

			if ( ! current_user_can( 'edit_user', $user_id ) ) {
				throw new Exception(
					'User does not have permission to add a certificate'
				);
			}

			// Add certificate
			$request = new TaxCloud\Request\AddExemptCertificate(
				SST_Settings::get( 'tc_id' ),
				SST_Settings::get( 'tc_key' ),
				$user->user_login,  // TODO: use user ID instead?
				$certificate
			);

			$certificate_id = TaxCloud()->AddExemptCertificate( $request );

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

		$request = new TaxCloud\Request\DeleteExemptCertificate(
			SST_Settings::get( 'tc_id' ),
			SST_Settings::get( 'tc_key' ),
			$certificate_id
		);

		TaxCloud()->DeleteExemptCertificate( $request );

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
