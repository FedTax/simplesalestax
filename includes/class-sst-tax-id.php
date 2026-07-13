<?php

namespace TaxCloud;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TaxID extends Serializable
{
  protected $TaxType; // TaxIDType
  protected $IDNumber; // string
  protected $StateOfIssue; // string

  public function __construct($TaxType, $IDNumber = '', $StateOfIssue = '')
  {
    $this->setTaxType($TaxType);
    $this->setIDNumber($IDNumber);
    $this->setStateOfIssue($StateOfIssue);
  }

  private function setTaxType($TaxType)
  {
    if ( defined( "TaxCloud\\TaxIDType::$TaxType" ) ) {
      $this->TaxType = constant( "TaxCloud\\TaxIDType::$TaxType" );
    } else {
      $this->TaxType = $TaxType;
    }
  }

  public function getTaxType()
  {
    return $this->TaxType;
  }

  private function setIDNumber($IDNumber)
  {
    $this->IDNumber = $IDNumber;
  }

  public function getIDNumber()
  {
    return $this->IDNumber;
  }

  private function setStateOfIssue($StateOfIssue)
  {
    $this->StateOfIssue = $StateOfIssue;
  }

  public function getStateOfIssue()
  {
    return $this->StateOfIssue;
  }
}
