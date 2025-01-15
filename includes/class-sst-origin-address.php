<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use TaxCloud\Address;

/**
 * Origin Address.
 *
 * Represents an origin address.
 *
 * @author  Simple Sales Tax
 * @package SST
 * @since   5.0
 */
class SST_Origin_Address extends Address {

	/**
	 * Address ID.
	 *
	 * @var string
	 */
	protected $ID;

	/**
	 * Is this a default address?
	 *
	 * @var bool
	 */
	protected $Default;

	/**
	 * Constructor.
	 *
	 * @param string $ID       Address ID.
	 * @param bool   $Default  Whether this is a default address.
	 * @param string $Address1 Street address 1.
	 * @param string $Address2 Street address 2.
	 * @param string $City     City.
	 * @param string $State    State.
	 * @param string $Zip5     5 digit component of ZIP code.
	 * @param string $Zip4     Optional 4 digit component of ZIP code.
	 *
	 * @since 5.0
	 */
	public function __construct( $ID, $Default, $Address1, $Address2, $City, $State, $Zip5, $Zip4 = null ) {
		$this->setID( $ID );
		$this->setDefault( $Default );

		parent::__construct( $Address1, $Address2, $City, $State, $Zip5, $Zip4 );
	}

	/**
	 * Set ID.
	 *
	 * @param string $ID Address ID.
	 *
	 * @since 5.0
	 */
	public function setID( $ID ) {
		$this->ID = $ID;
	}

	/**
	 * Get ID.
	 *
	 * @return string
	 * @since 5.0
	 */
	public function getID() {
		return $this->ID;
	}

	/**
	 * Set Default.
	 *
	 * @param bool $Default Whether the address is a default origin address.
	 *
	 * @since 5.0
	 */
	public function setDefault( $Default ) {
		$this->Default = $Default;
	}

	/**
	 * Get Default.
	 *
	 * @return bool
	 * @since 5.0
	 */
	public function getDefault() {
		return $this->Default;
	}

	/**
	 * Set Zip5.
	 *
	 * @param string $Zip5 5 digit ZIP code.
	 */
	public function setZip5( $Zip5 ) {
		$this->Zip5 = substr( $Zip5, 0, 5 );
	}

	/**
	 * Set Zip4.
	 *
	 * @param string $Zip4 4 digit component of ZIP code.
	 */
	public function setZip4( $Zip4 ) {
		$this->Zip4 = substr( $Zip4, 0, 4 );
	}

}
