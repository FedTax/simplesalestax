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

use TaxCloud\Exceptions\AddressException;

class Address extends Serializable
{
  protected $Address1;
  protected $Address2;
  protected $City;
  protected $State;
  protected $Zip5;
  protected $Zip4;

  public function __construct($Address1, $Address2, $City, $State, $Zip5, $Zip4 = NULL)
  {
    $this->setAddress1($Address1);
    $this->setAddress2($Address2);
    $this->setCity($City);
    $this->setState($State);
    $this->setZip5($Zip5);
    $this->setZip4($Zip4);
  }

  public function setAddress1($address1)
  {
    $this->Address1 = $address1;
  }

  public function getAddress1()
  {
    return $this->Address1;
  }

  public function setAddress2($address2)
  {
    $this->Address2 = $address2;
  }

  public function getAddress2()
  {
    return (isset($this->Address2)) ? $this->Address2 : NULL;
  }

  public function setCity($city)
  {
    $this->City = $city;
  }

  public function getCity()
  {
    return $this->City;
  }

  public function setState($state)
  {
    $this->State = $state;
  }

  public function getState()
  {
    return $this->State;
  }

  public function setZip5($zip5)
  {
    if (!preg_match('#[0-9]{5}#', $zip5)) {
      throw new AddressException('Zip5 must be five numeric characters.');
    }
    $this->Zip5 = $zip5;
  }

  public function getZip5()
  {
    return $this->Zip5;
  }

  public function setZip4($zip4)
  {
    if (!empty($zip4) && !preg_match('#[0-9]{4}#', $zip4)) {
      throw new AddressException('Zip4 must be four numeric characters.');
    }
    $this->Zip4 = $zip4;
  }

  public function getZip4()
  {
    return $this->Zip4;
  }

  public function getZip()
  {
    return $this->Zip5 . '-' . $this->Zip4;
  }
}
