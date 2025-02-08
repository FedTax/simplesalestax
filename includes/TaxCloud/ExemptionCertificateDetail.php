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

use TaxCloud\BusinessType;
use TaxCloud\ExemptionReason;

class ExemptionCertificateDetail extends Serializable
{
  protected $ExemptStates; // ArrayOfExemptState
  protected $SinglePurchase; // boolean
  protected $SinglePurchaseOrderNumber; // string
  protected $PurchaserFirstName; // string
  protected $PurchaserLastName; // string
  protected $PurchaserTitle; // string
  protected $PurchaserAddress1; // string
  protected $PurchaserAddress2; // string
  protected $PurchaserCity; // string
  protected $PurchaserState; // State
  protected $PurchaserZip; // string
  protected $PurchaserTaxID; // TaxID
  protected $PurchaserBusinessType; // BusinessType
  protected $PurchaserBusinessTypeOtherValue; // string
  protected $PurchaserExemptionReason; // ExemptionReason
  protected $PurchaserExemptionReasonValue; // string
  protected $CreatedDate; // dateTime
  protected static $DatePattern = "/\/Date\(\d+\)\//"; // Match JavaScript serialized dates

  public function __construct($ExemptStates, $SinglePurchase, $SinglePurchaseOrderNumber, $PurchaserFirstName, $PurchaserLastName, $PurchaserTitle, $PurchaserAddress1, $PurchaserAddress2, $PurchaserCity, $PurchaserState, $PurchaserZip, $PurchaserTaxID, $PurchaserBusinessType, $PurchaserBusinessTypeOtherValue, $PurchaserExemptionReason, $PurchaserExemptionReasonValue, $CreatedDate = NULL)
  {
    $this->setExemptStates($ExemptStates);
    $this->setSinglePurchase($SinglePurchase);
    $this->setSinglePurchaseOrderNumber($SinglePurchaseOrderNumber);
    $this->setPurchaserFirstName($PurchaserFirstName);
    $this->setPurchaserLastName($PurchaserLastName);
    $this->setPurchaserTitle($PurchaserTitle);
    $this->setPurchaserAddress1($PurchaserAddress1);
    $this->setPurchaserAddress2($PurchaserAddress2);
    $this->setPurchaserCity($PurchaserCity);
    $this->setPurchaserState($PurchaserState);
    $this->setPurchaserZip($PurchaserZip);
    $this->setPurchaserTaxID($PurchaserTaxID);
    $this->setPurchaserBusinessType($PurchaserBusinessType);
    $this->setPurchaserBusinessTypeOtherValue($PurchaserBusinessTypeOtherValue);
    $this->setPurchaserExemptionReason($PurchaserExemptionReason);
    $this->setPurchaserExemptionReasonValue($PurchaserExemptionReasonValue);
    $this->setCreatedDate($CreatedDate);
  }

  private function setExemptStates($ExemptStates)
  {
    $this->ExemptStates = $ExemptStates;
  }

  public function getExemptStates()
  {
    return $this->ExemptStates;
  }

  private function setSinglePurchase($SinglePurchase)
  {
    $this->SinglePurchase = $SinglePurchase;
  }

  public function getSinglePurchase()
  {
    return $this->SinglePurchase;
  }

  private function setSinglePurchaseOrderNumber($SinglePurchaseOrderNumber)
  {
    $this->SinglePurchaseOrderNumber = $SinglePurchaseOrderNumber;
  }

  public function getSinglePurchaseOrderNumber()
  {
    return $this->SinglePurchaseOrderNumber;
  }

  private function setPurchaserFirstName($PurchaserFirstName)
  {
    $this->PurchaserFirstName = $PurchaserFirstName;
  }

  public function getPurchaserFirstName()
  {
    return $this->PurchaserFirstName;
  }

  private function setPurchaserLastName($PurchaserLastName)
  {
    $this->PurchaserLastName = $PurchaserLastName;
  }

  public function getPurchaserLastName()
  {
    return $this->PurchaserLastName;
  }

  private function setPurchaserTitle($PurchaserTitle)
  {
    $this->PurchaserTitle = $PurchaserTitle;
  }

  public function getPurchaserTitle()
  {
    return $this->PurchaserTitle;
  }

  private function setPurchaserAddress1($PurchaserAddress1)
  {
    $this->PurchaserAddress1 = $PurchaserAddress1;
  }

  public function getPurchaserAddress1()
  {
    return $this->PurchaserAddress1;
  }

  private function setPurchaserAddress2($PurchaserAddress2)
  {
    $this->PurchaserAddress2 = $PurchaserAddress2;
  }

  public function getPurchaserAddress2()
  {
    return $this->PurchaserAddress2;
  }

  private function setPurchaserCity($PurchaserCity)
  {
    $this->PurchaserCity = $PurchaserCity;
  }

  public function getPurchaserCity()
  {
    return $this->PurchaserCity;
  }

  private function setPurchaserState($PurchaserState)
  {
    $this->PurchaserState = $PurchaserState;
  }

  public function getPurchaserState()
  {
    return $this->PurchaserState;
  }

  private function setPurchaserZip($PurchaserZip)
  {
    $this->PurchaserZip = $PurchaserZip;
  }

  public function getPurchaserZip()
  {
    return $this->PurchaserZip;
  }

  private function setPurchaserTaxID(TaxID $PurchaserTaxID)
  {
    $this->PurchaserTaxID = $PurchaserTaxID;
  }

  public function getPurchaserTaxID()
  {
    return $this->PurchaserTaxID;
  }

  private function setPurchaserBusinessType($PurchaserBusinessType)
  {
    $this->PurchaserBusinessType = constant("TaxCloud\\BusinessType::$PurchaserBusinessType");
  }

  public function getPurchaserBusinessType()
  {
    return $this->PurchaserBusinessType;
  }

  private function setPurchaserBusinessTypeOtherValue($PurchaserBusinessTypeOtherValue)
  {
    $this->PurchaserBusinessTypeOtherValue = $PurchaserBusinessTypeOtherValue;
  }

  public function getPurchaserBusinessTypeOtherValue()
  {
    return $this->PurchaserBusinessTypeOtherValue;
  }

  private function setPurchaserExemptionReason($PurchaserExemptionReason)
  {
    $this->PurchaserExemptionReason = constant("TaxCloud\\ExemptionReason::$PurchaserExemptionReason");
  }

  public function getPurchaserExemptionReason()
  {
    return $this->PurchaserExemptionReason;
  }

  private function setPurchaserExemptionReasonValue($PurchaserExemptionReasonValue)
  {
    $this->PurchaserExemptionReasonValue = $PurchaserExemptionReasonValue;
  }

  public function getPurchaserExemptionReasonValue()
  {
    return $this->PurchaserExemptionReasonValue;
  }

  private function setCreatedDate($CreatedDate)
  {
    if ($CreatedDate && preg_match(self::$DatePattern, $CreatedDate)) {
      /* Decode serialized date. */
      $timestamp = preg_replace('/[^0-9]/', '', $CreatedDate);
      $this->CreatedDate = date("c", $timestamp / 1000);
    } else if ($CreatedDate) {
      /* Use provided date. */
      $this->CreatedDate = $CreatedDate;
    } else {
      /* Use current date. */
      $this->CreatedDate = date("c");
    }
  }
  
  public function getCreatedDate()
  {
    return $this->CreatedDate;
  }
}
