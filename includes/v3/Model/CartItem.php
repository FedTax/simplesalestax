<?php

namespace TaxCloud_V3\Model;

use TaxCloud_V3\Serializable;

class CartItem extends Serializable
{
  
	/** @var int */
	public $index;

	/** @var string */
	public $itemId;

	/** @var float */
	public $price;

	/** @var string */
	public $productId;

	/** @var float */
	public $quantity;

	/** @var TaxCloud_V3_Tax */
	public $tax;

	/** @var int */
	public $tic = 0;

	/**
	 * Constructor.
	 *
	 * @param array $data Cart item data.
	 */
	public function __construct( $data = array() ) {
		foreach ( $data as $key => $value ) {
			if ( property_exists( $this, $key ) ) {
				if ( 'tax' === $key && is_array( $value ) ) {
					$this->tax = new Tax( $value['amount'], $value['rate'] );
				} else {
          $method = "set_" . $key;
          if ( method_exists( $this, $method ) ) {
            $this->$method( $value );
          } else {
            $this->$key = $value;
          }
				}
			}
		}
	}

  public function set_itemId( $value ) {
    $this->itemId = (string) $value;
  }

  public function set_price( $value ) {
    $this->price = (float) $value;
  }

  public function set_quantity( $value ) {
    $this->quantity = (float) $value;
  }

  public function set_tax( $value ) {
    $this->tax = new Tax( $value['amount'], $value['rate'] );
  }

  public function set_tic( $value ) {
    $this->tic = (int) $value;
  }

  public function get_item() {
    return array(
      'index' => $this->index,
      'itemId' => $this->itemId,
      'price' => $this->price,
      'productId' => $this->productId,
      'quantity' => $this->quantity,
      'tax' => array(
        'amount' => $this->tax->amount,
        'rate' => $this->tax->rate
      ),
      'tic' => $this->tic
    );
  }
}
