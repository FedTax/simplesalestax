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

namespace TaxCloud\Request;

use TaxCloud\Exceptions\RequestException;
use TaxCloud\Transaction;

/**
 * Class AddTransactions
 *
 * AddTransactions request.
 *
 * @package TaxCloud\Request
 * @author  Taxcloud <integrations@taxcloud.com>
 * @see     https://dev.taxcloud.com/docs/versions/1.0/resources/taxcloud-us-advanced-apis/endpoints/list-taxcloud-us-advanced-apis-35962eeb-4ada-43bb-87a4-cfa5cdf0be08
 */
class AddTransactions extends RequestBase
{
  /**
   * The maximum number of transactions that can be uploaded in one request.
   */
  const MAX_TRANSACTIONS = 25;

  /**
   * The transactions to upload.
   *
   * @var Transaction[]
   */
  protected $transactions;

  /**
   * AddTransactions constructor.
   *
   * @param string        $apiLoginID
   * @param string        $apiKey
   * @param Transaction[] $transactions
   *
   * @throws RequestException
   */
  public function __construct($apiLoginID, $apiKey, $transactions = [])
  {
    parent::__construct($apiLoginID, $apiKey);

    $this->setTransactions($transactions);
  }

  /**
   * @return Transaction[]
   */
  public function getTransactions()
  {
    return $this->transactions;
  }

  /**
   * @param Transaction[] $transactions
   *
   * @throws RequestException
   */
  public function setTransactions($transactions)
  {
    if (sizeof($transactions) > self::MAX_TRANSACTIONS) {
      throw new RequestException(sprintf('The number of transactions (%d) exceeds the maximum for one AddTransactions request (%d).', sizeof($transactions), self::MAX_TRANSACTIONS));
    }

    $this->transactions = $transactions;
  }
}
