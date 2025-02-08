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

use TaxCloud\Address;

/**
 * Location.
 *
 * A business location associated with a TaxCloud account.
 *
 * @package TaxCloud\Request
 * @author  Taxcloud <integrations@taxcloud.com>
 */
class Location extends Address
{
  protected $LocationID;

  public function __construct($LocationID, $Address1, $Address2, $City, $State, $Zip5, $Zip4 = NULL)
  {
    $this->setLocationID($LocationID);

    parent::__construct($Address1, $Address2, $City, $State, $Zip5, $Zip4);
  }

  public function setLocationID($locationID)
  {
    $this->LocationID = $locationID;
  }

  public function getLocationID() {
    return $this->LocationID;
  }
}
