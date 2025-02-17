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
 * Modifications made April 15, 2017 by Taxcloud.
 */

namespace TaxCloud\Request;

use TaxCloud\Request\RequestBase;

class Authorized extends RequestBase
{
  protected $customerID; // string
  protected $cartID; // string
  protected $orderID; // string
  protected $dateAuthorized; // dateTime

  public function __construct($apiLoginId, $apiKey, $customerId, $cartId, $orderId, $dateAuthorized)
  {
    $this->customerID = $customerId;
    $this->cartID = $cartId;
    $this->orderID = $orderId;
    $this->dateAuthorized = $dateAuthorized;
    parent::__construct($apiLoginId, $apiKey);
  }

  public function setCustomerID($customerId)
  {
    $this->customerID = $customerId;
  }

  public function getCustomerID()
  {
    return $this->customerID;
  }

  public function setCartID($cartId)
  {
    $this->cartID = $cartId;
  }

  public function getCartID()
  {
    return $this->cartID;
  }

  public function setOrderID($orderId)
  {
    $this->orderID = $orderId;
  }

  public function getOrderID()
  {
    return $this->orderID;
  }

  public function setAuthorizedDate($authorizedDate)
  {
    $this->authorizedDate = $authorizedDate;
  }

  public function getAuthorizedDate()
  {
    return $this->authorizedDate;
  }
}
