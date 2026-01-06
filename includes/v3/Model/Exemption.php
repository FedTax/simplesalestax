<?php

namespace TaxCloud_V3\Model;

use TaxCloud_V3\Serializable;

/**
 * TaxCloud Exemption Model.
 *
 * @author  Simple Sales Tax
 * @package SST
 * @since   8.4.1
 */
class Exemption extends Serializable {

	/**
	 * @var string|null Exemption ID.
	 * @since 8.4.1
	 */
	public $exemptionId;

	/**
	 * @var bool|null Is exempt.
	 * @since 8.4.1
	 */
	public $isExempt;

	/**
	 * Constructor.
	 *
	 * @param string|null $exemptionId Exemption ID.
	 * @param bool|null   $isExempt    Is exempt.
	 * @since 8.4.1
	 */
	public function __construct( $exemptionId = null, $isExempt = null ) {
		$this->exemptionId = $exemptionId;
		$this->isExempt    = $isExempt;
	}
}
