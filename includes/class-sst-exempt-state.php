<?php

namespace TaxCloud;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ExemptState extends Serializable
{
  protected $StateAbbr; // State
  protected $ReasonForExemption; // ExemptionReason
  protected $IdentificationNumber; // string

  public function __construct($StateAbbr, $ReasonForExemption, $IdentificationNumber)
  {
    $this->setStateAbbr($StateAbbr);
    $this->setReasonForExemption($ReasonForExemption);
    $this->setIdentificationNumber($IdentificationNumber);
  }

  private function setStateAbbr($StateAbbr)
  {
    if ( defined( "TaxCloud\\State::$StateAbbr" ) ) {
      $this->StateAbbr = constant( "TaxCloud\\State::$StateAbbr" );
    } elseif ( $StateAbbr === 'OR' && defined( "TaxCloud\\State::_OR" ) ) {
      $this->StateAbbr = constant( "TaxCloud\\State::_OR" );
    } else {
      $this->StateAbbr = $StateAbbr;
    }
  }

  public function getStateAbbr()
  {
    return $this->StateAbbr;
  }

  private function setReasonForExemption($ReasonForExemption)
  {
    if ( defined( "TaxCloud\\ExemptionReason::$ReasonForExemption" ) ) {
      $this->ReasonForExemption = constant( "TaxCloud\\ExemptionReason::$ReasonForExemption" );
    } else {
      $this->ReasonForExemption = 'Other';
    }
  }

  public function getReasonForExemption()
  {
    return $this->ReasonForExemption;
  }

  private function setIdentificationNumber($IdentificationNumber)
  {
    $this->IdentificationNumber = $IdentificationNumber;
  }

  public function getIdentificationNumber()
  {
    return $this->IdentificationNumber;
  }
}
