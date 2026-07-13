<?php

namespace TaxCloud;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
    if ( defined( "TaxCloud\\BusinessType::$PurchaserBusinessType" ) ) {
      $this->PurchaserBusinessType = constant( "TaxCloud\\BusinessType::$PurchaserBusinessType" );
    } else {
      $this->PurchaserBusinessType = 'Other';
    }
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
    if ( defined( "TaxCloud\\ExemptionReason::$PurchaserExemptionReason" ) ) {
      $this->PurchaserExemptionReason = constant( "TaxCloud\\ExemptionReason::$PurchaserExemptionReason" );
    } else {
      $this->PurchaserExemptionReason = 'Other';
    }
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
