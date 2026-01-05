<?php

namespace TaxCloud_V3\Model;

use TaxCloud_V3\Serializable;

/**
 * TaxCloud Exemption Model.
 *
 * @author  Simple Sales Tax
 * @package SST
 * @since   8.4.0
 */
class Exemption extends Serializable {

	/** @var string|null */
	public $exemptionId;

	/** @var bool|null */
	public $isExempt;

	/**
	 * Constructor.
	 *
	 * @param string|null $exemptionId Exemption ID.
	 * @param bool|null   $isExempt    Is exempt.
	 */
	public function __construct( $exemptionId = null, $isExempt = null ) {
		$this->exemptionId = $exemptionId;
		$this->isExempt    = $isExempt;
	}
}
