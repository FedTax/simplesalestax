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
		foreach ( $data as $key => $value ) {
			if ( property_exists( $this, $key ) ) {
				$this->$key = $value;
			}
		}
	}
}
