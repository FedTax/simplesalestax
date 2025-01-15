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
 * Modifications made April 15, 2017
 */

namespace TaxCloud;

class MessageType
{
  const Error = 'Error';
  const Warning = 'Warning';
  const Informational = 'Informational';
  const OK = 'OK';

  /**
   * Converts an integer ResponseType into a MessageType.
   *
   * @since 0.2.0
   *
   * @param  int $value
   * @return string
   */
  public static function fromValue($value) {
  	switch ($value) {
  		case 0:
  			return self::Error;
  		case 1:
  			return self::Warning;
  		case 2:
  			return self::Informational;
  		case 3:
  			return self::OK;
  		default:
  			return self::Error;
  	}
  }
}
