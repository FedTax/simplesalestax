<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class SST_TIC.
 *
 * Represents a Taxability Information Code (TIC).
 *
 * @author  Simple Sales Tax
 * @package SST
 * @since   5.0
 */
class SST_TIC implements JsonSerializable {

	/**
	 * ID.
	 *
	 * @var string
	 */
	protected $id;

	/**
	 * Description.
	 *
	 * @var string
	 */
	protected $description;

	/**
	 * Constructor.
	 *
	 * @param string $id          TIC ID.
	 * @param string $description TIC description.
	 */
	public function __construct( $id, $description ) {
		$this->id          = str_pad( $id, 5, '0' );
		$this->description = $description;
	}

	/**
	 * Get ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Get description.
	 *
	 * @return string
	 */
	public function get_description() {
		return $this->description;
	}

	/**
	 * Return in format that can be JSON serialized.
	 *
	 * @return array
	 */
	#[\ReturnTypeWillChange]
	public function jsonSerialize() {
		return get_object_vars( $this );
	}

}
