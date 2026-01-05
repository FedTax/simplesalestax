<?php

namespace TaxCloud_V3\Model;

use TaxCloud\ExemptionCertificateBase;
use TaxCloud\ExemptionCertificate;
use SST_Addresses;

class CompressedPackage {

	private $package;

	public function __construct( $package ) {
		$this->set_package( $package );
	}

	public function get_package() {
		return $this->package;
	}

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
		$package['origin_address']      = '';
		$package['destination_address'] = '';
		$package['certificate_id']      = '';

		if ( ! empty( $package['shipping'] ) ) {
			$package['shipping_method'] = $package['shipping']->method_id;
			$package['shipping_cost']   = $package['shipping']->cost;
		}

		if ( is_a( $package['origin'], 'TaxCloud\Address' ) ) {
			$package['origin_address'] = SST_Addresses::format(
				$package['origin']
			);
		}

		if ( is_a( $package['destination'], 'TaxCloud\Address' ) ) {
			$package['destination_address'] = SST_Addresses::format(
				$package['destination']
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
			'origin',
			'destination',
			'certificate',
		);

		$package = array_diff_key( $package, array_flip( $extra_keys ) );

		$this->package = $package;
	}

	/**
	 * Get the cart items for a given package.
	 *
	 * @param array $package Package data.
	 *
	 * @return array Cart items.
	 * @since 7.0.0
	 */
	protected function get_package_items_from_v1( $package ) {
		$map        = $package['map'];
		$cart_items = array();

		foreach ( $package['request']['cartItems'] as $key => $item ) {
			$map_entry    = $package['map'][ $key ];
			$cart_items[] = array_merge(
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