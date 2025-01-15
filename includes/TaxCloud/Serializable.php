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

class Serializable implements \JsonSerializable {
	/**
	 * Return JSON-serializable representation of an array.
	 *
	 * @since 0.2.0
	 *
	 * @param  array $array
	 * @return array
	 */
	private function serializeArray(&$array) {
		foreach ($array as $key => $val) {
			if ($val instanceof \JsonSerializable) {
				$array[$key] = $val->jsonSerialize();
			} elseif (is_array($val)) {
				$array[$key] = $this->serializeArray($val);
			} else {
				$array[$key] = $val;
			}
		}

		return $array;
	}

	/**
   	 * Return JSON-serializable representation of request.
     *
     * @since 0.2.0
     *
     * @return mixed
     */
	#[\ReturnTypeWillChange]
	public function jsonSerialize()
	{
		$request = array();
		$props = get_object_vars($this);

		foreach ($props as $key => $value) {
			if ($value instanceof \JsonSerializable) {
			  	$request[$key] = $value->jsonSerialize();
			} elseif (is_array($value)) {
			  	$request[$key] = $this->serializeArray($value);
			} else {
			  	$request[$key] = $value;
			}
		}

		return $request;
	}
}
