<?php

namespace TaxCloud_V3\Model;

use TaxCloud_V3\Serializable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TaxCloud Currency Model.
 *
 * @author  Simple Sales Tax
 * @package SST
 * @since   8.4.0
 */
class Currency extends Serializable {

	/** @var string */
	public $currencyCode = 'USD';

	/**
	 * Constructor.
	 *
	 * @param string $currencyCode Currency code.
	 */
	public function __construct( $currencyCode = 'USD' ) {
		$this->currencyCode = $currencyCode;
	}
}
