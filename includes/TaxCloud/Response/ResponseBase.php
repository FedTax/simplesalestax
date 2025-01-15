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
 * Modifications made May 12, 2023 by Taxcloud
 */

namespace TaxCloud\Response;

use TaxCloud\MessageType;

class ResponseBase
{
  protected $Response; // string
  protected $ResponseType; // MessageType
  protected $Messages; // ArrayOfResponseMessage

  /**
   * Constructor.
   *
   * @since 0.2.0
   *
   * @param string $response HTTP Response.
   */
  public function __construct($response) {
    $this->Response = json_decode($response, true);
  }

  /**
   * Decode response messages.
   *
   * @since 0.2.0
   */
  private function decodeMessages() {
    $messages = array();
    foreach ($this->Response['Messages'] as $message) {
      $messages[] = new ResponseMessage($message);
    }
    $this->Messages = $messages;
  }

  public function getResponseType()
  {
    return MessageType::fromValue($this->Response['ResponseType']);
  }

  public function getMessages()
  {
    if (!isset($this->Messages)) {
      $this->decodeMessages();
    }
    return $this->Messages;
  }
}
