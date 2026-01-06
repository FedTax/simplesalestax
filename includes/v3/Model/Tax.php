<?php
namespace TaxCloud_V3\Model;

use TaxCloud_V3\Serializable;

class Tax extends Serializable
{
	/** @var float */
	public $amount;

	/** @var float */
	public $rate;

	/**
	 * Constructor.
	 *
	 * @param float $amount Tax amount.
	 * @param float $rate   Tax rate.
	 */
	public function __construct( $amount, $rate ) {
		$this->amount = $amount;
		$this->rate   = $rate;
	}

}
