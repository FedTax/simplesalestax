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
 *
 *
 * Modifications made August 20, 2013 by TaxCloud
 */

namespace TaxCloud\Request;

use TaxCloud\Address;

/**
 * @file
 * Contains class to build VerifyAddress request object.
 */

class VerifyAddress extends RequestBase
{
  protected $address1;
  protected $address2;
  protected $city;
  protected $state;
  protected $zip5;
  protected $zip4;

  public function __construct($apiLoginID, $apiKey, Address $address)
  {
    parent::__construct($apiLoginID, $apiKey);
    $this->setAddress1($address->getAddress1());
    $this->setAddress2($address->getAddress2());
    $this->setCity($address->getCity());
    $this->setState($address->getState());
    $this->setZip5($address->getZip5());
    $this->setZip4($address->getZip4());
  }

  private function setAddress1($address1)
  {
    $this->address1 = $address1;
  }

  public function getAddress1()
  {
    return $this->address1;
  }

  private function setAddress2($address2)
  {
    $this->address2 = $address2;
  }

  public function getAddress2()
  {
    return $this->address2;
  }

  private function setCity($city)
  {
    $this->city = $city;
  }

  public function getCity()
  {
    return $this->city;
  }

  private function setState($state)
  {
    $this->state = $state;
  }

  public function getState()
  {
    return $this->state;
  }

  private function setZip5($zip5)
  {
    $this->zip5 = $zip5;
  }

  public function getZip5()
  {
    return $this->zip5;
  }

  private function setZip4($zip4)
  {
    $this->zip4 = $zip4;
  }

  public function getZip4()
  {
    return $this->zip4;
  }
}
