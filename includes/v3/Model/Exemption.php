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
	 * @param array|string|null $data     Exemption data or exemption ID.
	 * @param bool|null         $isExempt Is exempt.
	 * @since 8.4.1
	 */
	public function __construct( $data = null, $isExempt = null ) {
		if ( is_array( $data ) ) {
			if ( array_key_exists( 'exemptionId', $data ) && ! is_null( $data['exemptionId'] ) ) {
				$this->exemptionId = (string) $data['exemptionId'];
			}

			if ( array_key_exists( 'isExempt', $data ) && ! is_null( $data['isExempt'] ) ) {
				$this->isExempt = (bool) $data['isExempt'];
			}

			return;
		}

		$this->exemptionId = is_null( $data ) ? null : (string) $data;
		$this->isExempt    = is_null( $isExempt ) ? null : (bool) $isExempt;
	}
}
