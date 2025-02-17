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

use DateTime;
use TaxCloud\Exceptions\DataException;

/**
 * Class Transaction
 *
 * TaxCloud Transaction object.
 *
 * @package TaxCloud
 * @author  Taxcloud <integrations@taxcloud.com>
 * @see     https://dev.taxcloud.com/docs/versions/1.0/resources/taxcloud-us-advanced-apis/endpoints/list-taxcloud-us-advanced-apis-35962eeb-4ada-43bb-87a4-cfa5cdf0be08
 */
class Transaction extends Serializable
{
  /**
   * The unique identifier for the customer entity making a purchase.
   *
   * @var string
   */
  protected $customerID;

  /**
   * Commerce system-issued unique transaction/order identifier.
   *
   * @var string
   */
  protected $cartID;

  /**
   * Commerce system-issued unique transaction/order identifier.
   *
   * @var string
   */
  protected $orderID;

  /**
   * An array of AddTransactionCartItems being purchased by the customer.
   *
   * @var AddTransactionCartItem[]
   */
  protected $cartItems = [];

  /**
   * The origin address of the shipment (your store or warehouse).
   *
   * @var Address
   */
  protected $origin;

  /**
   * Your customer's delivery address. For digital goods or services, this is your customer's billing/payment address.
   *
   * @var Address
   */
  protected $destination;

  /**
   * Is this purchase going to be delivered by the seller in a seller-owned vehicle, and not by common carrier
   *
   * @var bool
   */
  protected $deliveredBySeller;

  /**
   * The customer exemption certificate, if any.
   *
   * @var ExemptionCertificateBase
   */
  protected $exemptCert;

  /**
   * The date the order was placed, e.g. 2019-06-14.
   *
   * @var string
   */
  protected $dateTransaction;

  /**
   * The date the order was authorized with the payments processor, e.g. 2019-06-14
   *
   * @var string
   */
  protected $dateAuthorized;

  /**
   * The date the order was shipped to the customer and payment was captured with the payments processor.
   *
   * @var string
   */
  protected $dateCaptured;

  /**
   * Transaction constructor.
   *
   * @param string                   $customerID
   * @param string                   $cartID
   * @param string                   $orderID
   * @param AddTransactionCartItem[] $cartItems
   * @param Address                  $origin
   * @param Address                  $destination
   * @param bool                     $deliveredBySeller
   * @param ExemptionCertificateBase $exemptCert
   * @param string                   $dateTransaction
   * @param string                   $dateAuthorized
   * @param string                   $dateCaptured
   *
   * @throws DataException If dateTransaction, dateAuthorized, or dateCaptured is invalid.
   */
  public function __construct($customerID, $cartID, $orderID, array $cartItems, Address $origin, Address $destination, $deliveredBySeller, $exemptCert, $dateTransaction, $dateAuthorized, $dateCaptured)
  {
    $this->setCustomerID($customerID);
    $this->setCartID($cartID);
    $this->setOrderID($orderID);
    $this->setCartItems($cartItems);
    $this->setOrigin($origin);
    $this->setDestination($destination);
    $this->setDeliveredBySeller($deliveredBySeller);
    $this->setExemptCert($exemptCert);
    $this->setDateTransaction($dateTransaction);
    $this->setDateAuthorized($dateAuthorized);
    $this->setDateCaptured($dateCaptured);
  }

  /**
   * @return string
   */
  public function getCustomerID()
  {
    return $this->customerID;
  }

  /**
   * @param string $customerID
   */
  public function setCustomerID($customerID)
  {
    $this->customerID = $customerID;
  }

  /**
   * @return string
   */
  public function getCartID()
  {
    return $this->cartID;
  }

  /**
   * @param string $cartID
   */
  public function setCartID($cartID)
  {
    $this->cartID = $cartID;
  }

  /**
   * @return string
   */
  public function getOrderID()
  {
    return $this->orderID;
  }

  /**
   * @param string $orderID
   */
  public function setOrderID($orderID)
  {
    $this->orderID = $orderID;
  }

  /**
   * @return AddTransactionCartItem[]
   */
  public function getCartItems()
  {
    return $this->cartItems;
  }

  /**
   * @param AddTransactionCartItem[] $cartItems
   */
  public function setCartItems($cartItems)
  {
    $this->cartItems = $cartItems;
  }

  /**
   * Helper to add a cart item. Sets the OrderID for the AddTransactionCartItem automatically.
   *
   * @param int    $index
   * @param string $itemId
   * @param string $tic
   * @param float  $price
   * @param int    $qty
   * @param float  $rate
   *
   * @throws DataException If called before the orderID is set.
   */
  public function addCartItem($index, $itemId, $tic, $price, $qty, $rate)
  {
    $orderID = $this->getOrderID();

    if (empty($orderID)) {
      throw new DataException('Please set the orderID before calling ' . __METHOD__);
    }

    $this->cartItems[] = new AddTransactionCartItem($index, $itemId, $tic, $price, $qty, $rate, $orderID);
  }

  /**
   * Helper to remove a cart item.
   *
   * @param int $index Cart item index.
   *
   * @return bool Was anything removed?
   */
  public function removeCartItem($index)
  {
    if (isset($this->cartItems[$index])) {
      unset($this->cartItems[$index]);
      return true;
    }

    return false;
  }

  /**
   * @return Address
   */
  public function getOrigin()
  {
    return $this->origin;
  }

  /**
   * @param Address $origin
   */
  public function setOrigin($origin)
  {
    $this->origin = $origin;
  }

  /**
   * @return Address
   */
  public function getDestination()
  {
    return $this->destination;
  }

  /**
   * @param Address $destination
   */
  public function setDestination($destination)
  {
    $this->destination = $destination;
  }

  /**
   * @return bool
   */
  public function isDeliveredBySeller()
  {
    return $this->deliveredBySeller;
  }

  /**
   * @param bool $deliveredBySeller
   */
  public function setDeliveredBySeller($deliveredBySeller)
  {
    $this->deliveredBySeller = (bool)$deliveredBySeller;
  }

  /**
   * @return ExemptionCertificateBase
   */
  public function getExemptCert()
  {
    return $this->exemptCert;
  }

  /**
   * @param ExemptionCertificateBase $exemptCert
   */
  public function setExemptCert($exemptCert)
  {
    $this->exemptCert = $exemptCert;
  }

  /**
   * @return string
   */
  public function getDateTransaction()
  {
    return $this->dateTransaction;
  }

  /**
   * @param string|int|DateTime $dateTransaction Date string, UTC timestamp, or DateTime object.
   *
   * @throws DataException If the date is invalid.
   */
  public function setDateTransaction($dateTransaction)
  {
    $this->setDateProp('dateTransaction', $dateTransaction);
  }

  /**
   * @return string
   */
  public function getDateAuthorized()
  {
    return $this->dateAuthorized;
  }

  /**
   * @param string|int|DateTime $dateAuthorized Date string, UTC timestamp, or DateTime object.
   *
   * @throws DataException If the date is invalid.
   */
  public function setDateAuthorized($dateAuthorized)
  {
    $this->setDateProp('dateAuthorized', $dateAuthorized);
  }

  /**
   * @return string
   */
  public function getDateCaptured()
  {
    return $this->dateCaptured;
  }

  /**
   * @param string|int|DateTime $dateCaptured Date string, UTC timestamp, or DateTime object.
   *
   * @throws DataException If the date is invalid.
   */
  public function setDateCaptured($dateCaptured)
  {
    $this->setDateProp('dateCaptured', $dateCaptured);
  }

  /**
   * Helper to set a date property.
   *
   * @param string              $property Property name.
   * @param string|int|DateTime $date     Date string, UTC timestamp, or DateTime object.
   *
   * @throws DataException If the date is invalid.
   */
  private function setDateProp($property, $date)
  {
    if (is_numeric($date)) {
      $this->$property = date('Y-m-d', $date);
    } else if (is_string($date)) {
      $this->$property = date('Y-m-d', strtotime($date));
    } else if ($date instanceof DateTime) {
      $this->$property = $date->format('Y-m-d');
    } else {
      throw new DataException(sprintf('%s must be a date string, UTC timestamp, or DateTime object.', $property));
    }
  }
}
