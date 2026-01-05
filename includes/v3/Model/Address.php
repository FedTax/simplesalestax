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
 * @since   8.4.0
 */
class Address extends Serializable {

	/** @var string */
	public $city;

	/** @var string */
	public $countryCode = 'US';

	/** @var string */
	public $line1;

	/** @var string */
	public $line2;

	/** @var string */
	public $state;

	/** @var string */
	public $zip;

	/**
	 * Constructor.
	 *
	 * @param array $data Address data.
	 */
	public function __construct( $data = array() ) {
		foreach ( $data as $key => $value ) {
			if ( property_exists( $this, $key ) ) {
				$this->$key = $value;
			}
		}
	}
}
