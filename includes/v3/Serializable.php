<?php
namespace TaxCloud_V3;

use SST_Settings;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


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