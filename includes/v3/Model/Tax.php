<?php
namespace TaxCloud_V3\Model;

use TaxCloud_V3\Serializable;

/**
 * TaxCloud Tax Model.
 *
 * @author  Simple Sales Tax
 * @package SST
 * @since   8.4.1
 */
class Tax extends Serializable
{
	/**
	 * @var float Tax amount.
	 * @since 8.4.1
	 */
	public $amount;

	/**
	 * @var float Tax rate.
	 * @since 8.4.1
	 */
	public $rate;

	/**
	 * Constructor.
	 *
	 * @param float $amount Tax amount.
	 * @param float $rate   Tax rate.
	 * @since 8.4.1
	 */
	public function __construct( $amount, $rate ) {
		$this->amount = $amount;
		$this->rate   = $rate;
	}

}
