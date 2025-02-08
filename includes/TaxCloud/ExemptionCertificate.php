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
 * Modifications made April 25, 2017 by Taxcloud
 */

namespace TaxCloud;

class ExemptionCertificate extends ExemptionCertificateBase
{
  protected $Detail; // ExemptionCertificateDetail

  public function __construct(array $ExemptStates, $SinglePurchase, $SinglePurchaseOrderNumber, $PurchaserFirstName, $PurchaserLastName, $PurchaserTitle, $PurchaserAddress1, $PurchaserAddress2, $PurchaserCity, $PurchaserState, $PurchaserZip, TaxID $PurchaserTaxID, $PurchaserBusinessType, $PurchaserBusinessTypeOtherValue, $PurchaserExemptionReason, $PurchaseExemptionReasonValue, $CreatedDate = NULL)
  {
    parent::__construct();

    $this->Detail = new ExemptionCertificateDetail(
      $ExemptStates,
      $SinglePurchase,
      $SinglePurchaseOrderNumber,
      $PurchaserFirstName,
      $PurchaserLastName,
      $PurchaserTitle,
      $PurchaserAddress1,
      $PurchaserAddress2,
      $PurchaserCity,
      $PurchaserState,
      $PurchaserZip,
      $PurchaserTaxID,
      $PurchaserBusinessType,
      $PurchaserBusinessTypeOtherValue,
      $PurchaserExemptionReason,
      $PurchaseExemptionReasonValue,
      $CreatedDate
    );
  }

  public function getDetail()
  {
    return $this->Detail;
  }

  /**
   * Create ExemptionCertificate given array.
   *
   * @since 0.2.0
   *
   * @param  array $certificate
   * @return ExemptionCertificate
   */
  public static function fromArray($certificate) {
    $states = array();

    $detail = $certificate;

    foreach ($detail['states'] as $state) {
      $states[] = new ExemptState($state['abbreviation'], '', '');
    }

    $taxID = new TaxID(
        'SSN',//$detail['PurchaserTaxID']['TaxType'],
        'SSN',//$detail['PurchaserTaxID']['IDNumber'],
        'SSN',//$detail['PurchaserTaxID']['StateOfIssue']
    );
    $convertV1ResonFlipped = [
          "FederalGovernment" => "FederalGovernmentDepartment",
          "StateOrLocalGovernment" => "StateOrLocalGovernmentName",
          "TribalGovernment" => "TribalGovernmentName",
          "ForeignDiplomat" => "ForeignDiplomat",
          "CharitableOrganization" => "CharitableOrganization",
          "EducationalOrganization" => "ReligiousOrEducationalOrganization",
          "Resale" => "Resale",
          "AgriculturalProduction" => "AgriculturalProduction",
          "IndustrialProductionOrManufacturing" => "IndustrialProductionOrManufacturing",
          "DirectPayPermit" => "DirectPayPermit",
          "DirectMail" => "DirectMail",
          "Other" => "Other",
          "ReligiousOrganization" => "ReligiousOrganization",
      ];
    $cert = new self(
      $states, 
      $detail['singlePurchase'],
      '',
      $detail['customerName'],
      $detail['customerName'],
      $detail['singlePurchase'],
      $detail['address']['line1'],
      isset($detail['address']['line2'])?$detail['address']['line2']:"",
      $detail['address']['city'],
      $detail['address']['state'],
      $detail['address']['zip'],
      $taxID,
      isset($detail['customerBusinessType'])?$detail['customerBusinessType']:"",
      isset($detail['customerBusinessDescription'])?$detail['customerBusinessDescription']:"",
        $convertV1ResonFlipped[$detail['reason']],
      $detail['reasonDescription'],
      $detail['createdDate']
    );

    $cert->CertificateID = $certificate['certificateId'];

    return $cert;
  }
}
