<?php

namespace TaxCloud_V3\Model;

use TaxCloud_V3\Serializable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TaxCloud Address Model.
 *
 * @author  Simple Sales Tax
 * @package SST
 * @since   8.4.1
 */
class Address extends Serializable {

	/**
	 * @var string City.
	 * @since 8.4.1
	 */
	public $city;

	/**
	 * @var string Country code.
	 * @since 8.4.1
	 */
	public $countryCode = 'US';

	/**
	 * @var string Line 1.
	 * @since 8.4.1
	 */
	public $line1;

	/**
	 * @var string Line 2.
	 * @since 8.4.1
	 */
	public $line2;

	/**
	 * @var string State.
	 * @since 8.4.1
	 */
	public $state;

	/**
	 * @var string Zip code.
	 * @since 8.4.1
	 */
	public $zip;

	/**
	 * Constructor.
	 *
	 * @param array $data Address data.
	 * @since 8.4.1
	 */
	public function __construct( $data = array() ) {
		$data = $this->normalize_data( $data );

		foreach ( $data as $key => $value ) {
			if ( property_exists( $this, $key ) ) {
				$this->$key = $value;
			}
		}
	}

	/**
	 * Normalize v1/WooCommerce address keys into the v3 address shape.
	 *
	 * @param array $data Address data.
	 *
	 * @return array Normalized address data.
	 * @since 8.4.10
	 */
	private function normalize_data( $data ) {
		if ( ! is_array( $data ) ) {
			return array();
		}

		$normalized = array(
			'city'        => $this->first_non_empty( $data, array( 'city', 'City' ) ),
			'countryCode' => $this->first_non_empty( $data, array( 'countryCode', 'country', 'Country' ), 'US' ),
			'line1'       => $this->first_non_empty( $data, array( 'line1', 'address1', 'address_1', 'address', 'Address1' ) ),
			'line2'       => $this->first_non_empty( $data, array( 'line2', 'address2', 'address_2', 'Address2' ) ),
			'state'       => $this->first_non_empty( $data, array( 'state', 'State' ) ),
			'zip'         => $this->first_non_empty( $data, array( 'zip', 'postcode', 'Zip', 'Zip5' ) ),
		);

		if ( isset( $data['Zip5'] ) && ! empty( $data['Zip4'] ) ) {
			$normalized['zip'] = $this->format_zip( $data['Zip5'], $data['Zip4'] );
		}

		return array_merge( $data, $normalized );
	}

	/**
	 * Return the first non-empty value for a set of possible keys.
	 *
	 * @param array  $data    Data to inspect.
	 * @param array  $keys    Candidate keys.
	 * @param string $default Default value.
	 *
	 * @return string
	 * @since 8.4.10
	 */
	private function first_non_empty( $data, $keys, $default = '' ) {
		foreach ( $keys as $key ) {
			if ( isset( $data[ $key ] ) && '' !== $data[ $key ] && null !== $data[ $key ] ) {
				return (string) $data[ $key ];
			}
		}

		return $default;
	}

	/**
	 * Format ZIP code from ZIP5 and ZIP4 parts.
	 *
	 * @param string      $zip5 ZIP5.
	 * @param string|null $zip4 ZIP4.
	 *
	 * @return string
	 * @since 8.4.10
	 */
	private function format_zip( $zip5, $zip4 = null ) {
		$zip5 = trim( (string) $zip5, '-' );
		$zip4 = trim( (string) $zip4, '-' );

		return '' !== $zip4 ? $zip5 . '-' . $zip4 : $zip5;
	}

	/**
	 * Get address line 1.
	 *
	 * @return string
	 * @since 8.4.10
	 */
	public function getAddress1() {
		return $this->line1;
	}

	/**
	 * Get address line 2.
	 *
	 * @return string|null
	 * @since 8.4.10
	 */
	public function getAddress2() {
		return $this->line2;
	}

	/**
	 * Get city.
	 *
	 * @return string
	 * @since 8.4.10
	 */
	public function getCity() {
		return $this->city;
	}

	/**
	 * Get state.
	 *
	 * @return string
	 * @since 8.4.10
	 */
	public function getState() {
		return $this->state;
	}

	/**
	 * Get ZIP5.
	 *
	 * @return string
	 * @since 8.4.10
	 */
	public function getZip5() {
		$zip_digits = preg_replace( '/[^0-9]/', '', (string) $this->zip );

		return substr( $zip_digits, 0, 5 );
	}

	/**
	 * Get ZIP4.
	 *
	 * @return string|null
	 * @since 8.4.10
	 */
	public function getZip4() {
		$zip_digits = preg_replace( '/[^0-9]/', '', (string) $this->zip );

		return strlen( $zip_digits ) > 5 ? substr( $zip_digits, 5, 4 ) : null;
	}

	/**
	 * Get full ZIP.
	 *
	 * @return string
	 * @since 8.4.10
	 */
	public function getZip() {
		return $this->zip;
	}
}
