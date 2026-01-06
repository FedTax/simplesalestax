<?php

namespace TaxCloud_V3\Model;

use TaxCloud\ExemptionCertificateBase;
use TaxCloud\ExemptionCertificate;
use SST_Addresses;

/**
 * TaxCloud Compressed Package Model.
 *
 * @author  Simple Sales Tax
 * @package SST
 * @since   8.4.1
 */
class CompressedPackage {

	/**
	 * @var array Package data.
	 * @since 8.4.1
	 */
	private $package;

	/**
	 * Constructor.
	 *
	 * @param array $package Package data.
	 * @since 8.4.1
	 */
	public function __construct( $package ) {
		$this->set_package( $package );
	}

	/**
	 * Get package data.
	 *
	 * @return array Package data.
	 * @since 8.4.1
	 */
	public function get_package() {
		return $this->package;
	}

	/**
	 * Set package data.
	 *
	 * @param array $package Package data.
	 * @since 8.4.1
	 */
	public function set_package( $package ) {
		
		$already_compressed = isset( $package['cart_items'] );

		if ( $already_compressed ) {
			$this->package = $package;
			return;
		}

		// Set new keys with compressed data.
		$package['customer_id']         = $package['user']['ID'];
		$package['cart_items']          = $this->get_package_items_from_v1( $package );
		$package['shipping_method']     = '';
		$package['shipping_cost']       = 0;
		$package['certificate_id']      = '';

		if ( ! empty( $package['shipping'] ) ) {
			$package['shipping_method'] = $package['shipping']->method_id;
			$package['shipping_cost']   = $package['shipping']->cost;
		}

		if ( is_a( $package['origin'], 'TaxCloud\Address' ) ) {
      $address = $package['origin'];
			$package['origin'] = array(
        'city' => $address->getCity(),
        'line1' => $address->getAddress1(),
        'state' => $address->getState(),
        'zip' => $this->trim_dashes( $address->getZip() ),
      );
		}

		if ( is_a( $package['destination'], 'TaxCloud\Address' ) ) {
      $address = $package['destination'];
			$package['destination'] = array(
        'city' => $address->getCity(),
        'line1' => $address->getAddress1(),
        'state' => $address->getState(),
        'zip' => $this->trim_dashes( $address->getZip() ),
      );
		}

		$certificate = $package['certificate'];

		if ( $certificate instanceof ExemptionCertificate ) {
			// Single-purchase certificate without an ID. Use a stable hash of the certificate detail object as the ID.
			$detail       = $certificate->getDetail();
			$detail_array = json_decode( wp_json_encode( $detail ), true );
			unset( $detail_array['CreatedDate'] );
			$package['certificate_id'] = md5( wp_json_encode( $detail_array ) );
		} else if ( $certificate instanceof ExemptionCertificateBase ) {
			// Entity-based exemption certificate with ID
			$package['certificate_id'] = $package['certificate']->getCertificateId();
		}

		// Remove keys not required to set tax amounts or capture/refund orders.
		$extra_keys = array(
			'contents',
			'fees',
			'shipping',
			'map',
			'user',
			'request',
			'certificate',
		);

		$package = array_diff_key( $package, array_flip( $extra_keys ) );

		$this->package = $package;
	}

  /**
   * Remove trailing dashes from a zip code.
   *
   * @param string $zip Zip code.
   *
   * @return string Zip code without trailing dashes.
   * @since 8.4.1
   */
  public function trim_dashes( $zip ) {
    return trim($zip, '-'); 
  }

	/**
	 * Get the cart items for a given package.
	 *
	 * @param array $package Package data.
	 *
	 * @return array Cart items.
	 * @since 8.4.1
	 */
	protected function get_package_items_from_v1( $package ) {
		$map        = $package['map'];
		$cart_items = array();

		foreach ( $package['request']['cartItems'] as $key => $item ) {
			$map_entry    = $package['map'][ $key ];
      // Move v3_data to cart_items
      $v3_data = isset( $map_entry['v3_data'] ) ? $map_entry['v3_data']->get_item() : array();
      if( $v3_data ) {
        unset( $map_entry['v3_data'] );
      }
			$cart_items[] = array_merge(
				$v3_data,
        $map_entry,
				array(
					'qty'   => $item->getQty(),
					'tic'   => $item->getTIC(),
					'price' => $item->getPrice(),
				)
			);
		}

		return $cart_items;
	}
}