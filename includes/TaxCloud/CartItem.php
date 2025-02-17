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
 *
 * Modifications made April 15, 2017 by Taxcloud
 */

namespace TaxCloud;

class CartItem extends Serializable
{
  protected $Index; // int
  protected $ItemID; // string
  protected $TIC; // int
  protected $Price; // double
  protected $Qty; // float

  public function __construct($index, $itemId, $tic, $price, $qty)
  {
    $this->setIndex($index);
    $this->setItemID($itemId);
    $this->setTIC($tic);
    $this->setPrice($price);
    $this->setQty($qty);
  }

  private function setIndex($index)
  {
    $this->Index = $index;
  }

  public function getIndex()
  {
    return $this->Index;
  }

  private function setItemID($itemId)
  {
    $this->ItemID = $itemId;
  }

  public function getItemID()
  {
    return $this->ItemID;
  }

  private function setTIC($tic)
  {
    $this->TIC = $tic;
  }

  public function getTIC()
  {
    return $this->TIC;
  }

  private function setPrice($price)
  {
    // @todo this needs validation
    $this->Price = $price;
  }

  public function getPrice()
  {
    return $this->Price;
  }

  private function setQty($qty)
  {
    $this->Qty = $qty;
  }

  public function getQty()
  {
    return $this->Qty;
  }
}
