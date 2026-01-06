<?php

namespace TaxCloud_V3\Model;

use TaxCloud_V3\Serializable;

/**
 * TaxCloud Cart Item Model.
 *
 * @author  Simple Sales Tax
 * @package SST
 * @since   8.4.1
 */
class CartItem extends Serializable
{
  
	/**
	 * @var int Item index.
	 * @since 8.4.1
	 */
	public $index;

	/**
	 * @var string Item ID.
	 * @since 8.4.1
	 */
	public $itemId;

	/**
	 * @var float Item price.
	 * @since 8.4.1
	 */
	public $price;

	/**
	 * @var string Product ID.
	 * @since 8.4.1
	 */
	public $productId;

	/**
	 * @var float Item quantity.
	 * @since 8.4.1
	 */
	public $quantity;

	/**
	 * @var Tax Item tax.
	 * @since 8.4.1
	 */
	public $tax;

	/**
	 * @var int Item TIC.
	 * @since 8.4.1
	 */
	public $tic = 0;

	/**
	 * Constructor.
	 *
	 * @param array $data Cart item data.
	 * @since 8.4.1
	 */
	public function __construct( $data = array() ) {
		foreach ( $data as $key => $value ) {
			if ( property_exists( $this, $key ) ) {
        $method = "set_" . $key;
        if ( method_exists( $this, $method ) ) {
          $this->$method( $value );
        } else {
          $this->$key = $value;
        }
    }
		}
	}

	/**
	 * Set item ID.
	 *
	 * @param mixed $value Item ID.
	 * @since 8.4.1
	 */
  public function set_itemId( $value ) {
    $this->itemId = (string) $value;
  }

	/**
	 * Set item price.
	 *
	 * @param mixed $value Item price.
	 * @since 8.4.1
	 */
  public function set_price( $value ) {
    $this->price = (float) $value;
  }

	/**
	 * Set item quantity.
	 *
	 * @param mixed $value Item quantity.
	 * @since 8.4.1
	 */
  public function set_quantity( $value ) {
    $this->quantity = (float) $value;
  }

	/**
	 * Set item tax.
	 *
	 * @param array $value Tax data.
	 * @since 8.4.1
	 */
  public function set_tax( $value ) {
    $this->tax = new Tax( $value['amount'], $value['rate'] );
  }

	/**
	 * Set item TIC.
	 *
	 * @param mixed $value TIC.
	 * @since 8.4.1
	 */
  public function set_tic( $value ) {
    $this->tic = (int) $value;
  }

	/**
	 * Get cart item data as array.
	 *
	 * @return array Cart item data.
	 * @since 8.4.1
	 */
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
