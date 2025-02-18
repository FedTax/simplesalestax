<?php

/**
 * Portions Copyright (c) 2009-2012 The Federal Tax Authority, LLC (FedTax).
 * All Rights Reserved.
 *
 * This file contains Original Code and/or Modifications of Original Code as
 * defined in and that are subject to the FedTax Public Source License (the
 * ‘License’). You may not use this file except in compliance with the License.
 * Please obtain a copy of the License at http://FedTax.net/ftpsl.pdf or
 * http://dev.taxcloud.net/ftpsl/ and read it before using this file.
 *
 * The Original Code and all software distributed under the License are
 * distributed on an ‘AS IS’ basis, WITHOUT WARRANTY OF ANY KIND, EITHER
 * EXPRESS OR IMPLIED, AND FEDTAX  HEREBY DISCLAIMS ALL SUCH WARRANTIES,
 * INCLUDING WITHOUT LIMITATION, ANY WARRANTIES OF MERCHANTABILITY, FITNESS FOR
 * A PARTICULAR PURPOSE, QUIET ENJOYMENT OR NON-INFRINGEMENT.
 *
 * Please see the License for the specific language governing rights and
 * limitations under the License.
 */

namespace TaxCloud;

/**
 * Class AddTransactionCartItem
 *
 * @package TaxCloud
 * @author  Taxcloud <integrations@taxcloud.com>
 */
class AddTransactionCartItem extends CartItem
{
  /**
   * The decimal representation of the sales tax rate charged to the customer at the time of sale, e.g. 0.0355
   *
   * @var float
   */
  protected $Rate;

  /**
   * The Order ID. This must match the OrderID for the transaction.
   *
   * @var string
   */
  protected $OrderID;

  /**
   * AddTransactionCartItem constructor.
   *
   * @param int    $index
   * @param string $itemId
   * @param string $tic
   * @param float  $price
   * @param int    $qty
   * @param float  $rate
   * @param string $orderId
   */
  public function __construct($index, $itemId, $tic, $price, $qty, $rate, $orderId)
  {
    parent::__construct($index, $itemId, $tic, $price, $qty);

    $this->setRate($rate);
    $this->setOrderID($orderId);
  }

  /**
   * @return float
   */
  public function getRate()
  {
    return $this->Rate;
  }

  /**
   * @param float $rate
   */
  public function setRate($rate)
  {
    $this->Rate = $rate;
  }

  /**
   * @return string
   */
  public function getOrderID()
  {
    return $this->OrderID;
  }

  /**
   * @param string $orderId
   */
  public function setOrderID($orderId)
  {
    $this->OrderID = $orderId;
  }
}
