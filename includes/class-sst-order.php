<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use \TaxCloud\ExemptionCertificate;
use \TaxCloud\ExemptionCertificateBase;

/**
 * Order.
 *
 * Extends WC_Order to add extra functionality.
 *
 * @author  Simple Sales Tax
 * @package SST
 * @since   5.0
 */
class SST_Order extends SST_Abstract_Cart {
	/** Certificate data classes used by orders saved before SST 7.0. */
	private const LEGACY_CERTIFICATE_CLASSES = array(
		'TaxCloud\ExemptionCertificateBase',
		'TaxCloud\ExemptionCertificate',
		'TaxCloud\ExemptionCertificateDetail',
		'TaxCloud\ExemptState',
		'TaxCloud\TaxID',
	);

	/**
	 * Prefix for meta keys.
	 *
	 * @var string
	 * @since 5.0
	 */
	protected static $prefix = '_wootax_';

	/**
	 * Default values for order meta fields.
	 *
	 * @var array
	 * @since 4.4
	 */
	protected static $defaults = array(
		'packages'             => array(),
		'exempt_cert'          => '',
		'single_purchase_cert' => null,
		'status'               => 'pending',
	);

	/**
	 * WooCommerce order.
	 *
	 * @var WC_Order
	 * @since 5.0
	 */
	protected $order = null;

	/**
	 * Constructor.
	 *
	 * @param mixed $order Order ID or WC_Order instance.
	 *
	 * @since 5.0
	 */
	public function __construct( $order ) {
		if ( is_numeric( $order ) ) {
			$this->order = wc_get_order( $order );
		} else {
			if ( is_a( $order, 'WC_Order' ) ) {
				$this->order = $order;
			}
		}

		parent::__construct();
	}

	/**
	 * Recalculate tax and remove a saved certificate if no package used it.
	 *
	 * @return bool
	 */
	public function calculate_taxes() {
		// Captured lookup data is needed for returns and must not be replaced
		// when an order is edited or its totals are recalculated.
		if ( 'pending' !== $this->get_taxcloud_status() || 0 < $this->order->get_total_refunded() ) {
			return true;
		}

		$result = parent::calculate_taxes();
		$certificate_id = $this->get_certificate_id();

		if ( ! $result || ! $certificate_id ) {
			return $result;
		}

		$packages = $this->get_packages();
		if ( ! $packages ) {
			return $result;
		}

		foreach ( $packages as $package ) {
			if (
				! empty( $package['certificate_id'] )
				&& (
					SST_SINGLE_PURCHASE_CERT_ID === $certificate_id
					|| $certificate_id === $package['certificate_id']
				)
			) {
				return $result;
			}
		}

		$this->set_certificate_id( '' );
		return $result;
	}

	/**
	 * Forward method calls to the encapsulated WC_Order instance.
	 *
	 * @param string $name Method name.
	 * @param array  $args Method arguments.
	 *
	 * @return mixed
	 * @since 5.0
	 */
	public function __call( $name, $args = array() ) {
		if ( is_callable( array( $this->order, $name ) ) ) {
			return call_user_func_array( array( $this->order, $name ), $args );
		}

		return null;
	}

	/**
	 * Can we perform a lookup for the given package?
	 *
	 * @param array $package Shipping package.
	 *
	 * @return bool
	 * @since 7.0.2
	 */
	protected function should_do_lookup( $package ) {
		return (
			'pending' === $this->get_taxcloud_status() &&
			0 === (int) $this->order->get_total_refunded()
		);
	}

	/**
	 * Get saved packages for this order.
	 *
	 * @return array
	 * @since 5.0
	 */
	public function get_packages() {
		// use array_values so package keys are integers (we want nice order IDs like 9004_0, 9004_1,... in TaxCloud).
		$packages = $this->get_meta( 'packages' );

		if ( ! is_array( $packages ) ) {
			return array();
		}

		// Compress package data just in case the order hasn't been
		// migrated to the new data format used in SST 7.0+ yet.
		// For orders that are already using the new data format
		// this is a no-op.
		$packages = array_map(
			array( $this, 'compress_package_data' ),
			$packages
		);

		return array_values( $packages );
	}

	/**
	 * Set saved packages for this order.
	 *
	 * @param array $packages Packages to save for order (default: array()).
	 *
	 * @since 5.0
	 */
	public function set_packages( $packages = array() ) {
		if ( ! is_array( $packages ) ) {
			$packages = array();
		}

		$this->update_meta( 'packages', $packages );
	}

	/**
	 * Transform an array of cart items to match the format expected during
	 * checkout.
	 *
	 * @param WC_Order_Item[] $cart_items WooCommerce order items.
	 *
	 * @return array
	 * @since 5.0
	 */
	protected function transform_items( $cart_items ) {
		return apply_filters( 'sst_order_package_items', sst_format_order_items( $cart_items ), $cart_items );
	}

	/**
	 * Get base shipping packages for order.
	 *
	 * @return array
	 * @since 5.5
	 */
	protected function get_base_packages() {
		$packages = array();
		$items    = $this->transform_items( $this->order->get_items() );

		/* Create a virtual package for all items that don't need shipping unless virtual split is disabled */
		if ( 'yes' !== SST_Settings::get( 'disable_virtual_split' ) ) {
			$virtual_package = $this->create_virtual_package( $items );
			if ( $virtual_package ) {
				$packages[] = $virtual_package;
				SST_Logger::order_log( __( 'Virtual package created.', 'simple-sales-tax' ), $this->order->get_id() );
			}
		}

		/* Create an additional package for each shipping method. */
		$ship_methods = $this->order->get_shipping_methods();

		if ( $ship_methods && $items ) {
			$items_per_package = ceil( count( $items ) / count( $ship_methods ) );
			$package_items     = array_chunk( $items, $items_per_package, true );

			foreach ( $package_items as $contents ) {
				$method = current( $ship_methods );
				$method_taxes = is_object( $method ) && method_exists( $method, 'get_taxes' ) ? $method->get_taxes() : array();
				$shipping_taxes = isset( $method_taxes['total'] ) && is_array( $method_taxes['total'] ) ? $method_taxes['total'] : array();

				/* Assign shipping method to package. */
				$package = sst_create_package(
					array(
						'contents' => $contents,
						'shipping' => new WC_Shipping_Rate(
							key( $ship_methods ),
							'',
							$method['cost'],
							$shipping_taxes,
							$method['method_id']
						),
						'user'     => array(
							'ID' => $this->order->get_user_id(),
						),
					)
				);

				$packages[] = $package;

				next( $ship_methods );
			}

			// Logging
			SST_Logger::order_log( __( 'Shipping methods and items assigned to packages.', 'simple-sales-tax' ), $this->order->get_id(), $packages );
		} elseif ( $items ) {
			/**
			 * If there are no shipping lines added to the order, we assume that
			 * there is a single shipment with all products that need shipping.
			 */
			$shippable_items = array();
			foreach ( $items as $item_id => $item ) {
				if ( isset( $item['data'] ) && ( $item['sst_needs_shipping'] ?? $item['data']->needs_shipping() ) ) {
					$shippable_items[ $item_id ] = $item;
				}
			}

			if ( empty( $shippable_items ) || 'yes' === SST_Settings::get( 'disable_virtual_split' ) ) {
				$shippable_items = $items;
			}

			$packages[] = sst_create_package(
				array(
					'contents' => $shippable_items,
					'user'     => array(
						'ID' => $this->order->get_user_id(),
					),
				)
			);

			// Logging
			SST_Logger::order_log( __( 'No shipping method but items assigned to packages.', 'simple-sales-tax' ), $this->order->get_id(), $packages );
		}

		return $packages;
	}

	/**
	 * Creates a virtual shipping package containing all items that don't need
	 * shipping.
	 *
	 * @param array $items Items from order.
	 *
	 * @return array|false Package, or false if all items need shipping.
	 */
	protected function create_virtual_package( &$items ) {
		$virtual_items = array();

		foreach ( $items as $key => $item ) {
			if ( isset( $item['data'] ) && ! ( $item['sst_needs_shipping'] ?? $item['data']->needs_shipping() ) ) {
				$virtual_items[ $key ] = $item;
				unset( $items[ $key ] );
			}
		}

		if ( ! empty( $virtual_items ) ) {
			return sst_create_package(
				array(
					'contents'    => $virtual_items,
					'destination' => sst_get_virtual_package_destination( $this->get_billing_address(), $this->get_shipping_address() ),
					'user'        => array(
						'ID' => $this->order->get_user_id(),
					),
				)
			);
		}

		return array();
	}

	/**
	 * Create shipping packages for order.
	 *
	 * @return array
	 * @since 5.0
	 */
	public function create_packages() {
		$packages = array();

		// Let devs change the packages before we split them.
		$raw_packages = apply_filters(
			'wootax_order_packages_before_split',
			$this->get_base_packages(),
			$this->order
		);

		// Set the destination address for each package, changing the destination
		// address to the pickup address if the shipping method is Local Pickup.
		foreach ( $raw_packages as $key => $package ) {
			$is_local_pickup_package = (
				! empty( $package['shipping'] )
				&& SST_Shipping::is_local_pickup( array( $package['shipping']->method_id ) )
			);

			if ( $is_local_pickup_package ) {
				$pickup_address = apply_filters(
					'wootax_pickup_address',
					SST_Addresses::get_default_address(),
					$this->order
				);

				// Keep the customer destination when no pickup address is available.
				if ( ! is_null( $pickup_address ) ) {
					$raw_packages[ $key ]['destination'] = array(
						'country'   => 'US',
						'address'   => $pickup_address->getAddress1(),
						'address_2' => $pickup_address->getAddress2(),
						'city'      => $pickup_address->getCity(),
						'state'     => $pickup_address->getState(),
						'postcode'  => $pickup_address->getZip5(),
					);
				}
			}

			if ( ! isset( $raw_packages[ $key ]['destination'] ) ) {
				$raw_packages[ $key ]['destination'] = $this->get_shipping_address();
			}
		}

		// Filter out packages with invalid destinations.
		$raw_packages = array_filter( $raw_packages, array( $this, 'is_package_destination_valid' ) );

		// Split packages by origin address.
		foreach ( $raw_packages as $raw_package ) {
			$packages = array_merge( $packages, $this->split_package( $raw_package ) );
		}

		// Add fees to packages.
		if ( ! empty( $packages ) && apply_filters( 'wootax_add_fees', true ) ) {
			foreach ( $this->order->get_fees() as $item_id => $fee ) {
				$name   = empty( $fee['name'] ) ? __( 'Fee', 'simple-sales-tax' ) : $fee['name'];
				$fee_id = sanitize_title( $name );

				$fee_obj = (object) array(
					'id'     => $fee_id,
					'amount' => $fee['line_total'],
					'tax'    => isset( $fee['line_tax'] ) ? $fee['line_tax'] : 0,
				);

				$target_package_key = key( $packages );

				if ( $fee_id === 'co-excise-tax' || 'CO EXCISE TAX' === strtoupper( $name ) ) {
					foreach ( $packages as $pkg_key => $pkg ) {
						foreach ( $pkg['contents'] as $content ) {
							$tic = SST_Product::get_tic( $content['product_id'], $content['variation_id'] );
							if ( SST_Product::is_gun_or_ammo_tic( $tic ) ) {
								$target_package_key = $pkg_key;
								break 2;
							}
						}
					}
				}

				if ( ! isset( $packages[ $target_package_key ] ) ) {
					$target_package_key = key( $packages );
				}

				$packages[ $target_package_key ]['fees'][ $item_id ] = $fee_obj;
			}
		}

		// Logging
		SST_Logger::order_log( __( 'Final shipping packages:', 'simple-sales-tax' ), $this->order->get_id(), $packages );

		return apply_filters( 'wootax_order_packages', $packages, $this->order );
	}

	/** Admin, REST and renewal tax calculations record outcomes without blocking. */
	protected function handle_address_verification( $outcome ) {
		SST_Addresses::save_order_verification( $this->order, array( $outcome['input_hash'] => $outcome ) );
	}

	/**
	 * Reset sales tax totals.
	 *
	 * @since 5.0
	 */
	protected function reset_taxes() {
		$item_ids = array_keys(
			$this->order->get_items( array( 'line_item', 'fee', 'shipping' ) )
		);

		foreach ( $item_ids as $item_id ) {
			$this->set_product_tax( $item_id, 0 );
		}

		$this->update_taxes();
	}

	/**
	 * Update sales tax totals.
	 *
	 * @since 5.0
	 */
	protected function update_taxes() {
		$this->order->update_taxes();
	}

	/**
	 * Set the tax for a product.
	 *
	 * @param mixed $id  Item ID.
	 * @param float $tax Sales tax for product.
	 *
	 * @since 5.0
	 */
	protected function set_product_tax( $id, $tax ) {
		$item     = $this->order->get_item( $id, false );
		$tax_data = $item->get_taxes( 'edit' );

		if ( ! is_array( $tax_data ) ) {
			$tax_data = array(
				'total'    => array(),
				'subtotal' => array(),
			);
		}

		$tax_data['total'][ SST_RATE_ID ]    = $tax;
		$tax_data['subtotal'][ SST_RATE_ID ] = $tax;

		$item->set_taxes( $tax_data );
		$item->save();

		// Add the modified item to the order so the changes take effect.
		$this->order->add_item( $item );
	}

	/**
	 * Set the tax for a shipping item.
	 *
	 * @param mixed $id  Item ID.
	 * @param float $tax Sales tax for item.
	 *
	 * @since 5.0
	 */
	protected function set_shipping_tax( $id, $tax ) {
		$this->set_product_tax( $id, $tax );
	}

	/**
	 * Set the tax for a fee.
	 *
	 * @param mixed $id  Fee ID.
	 * @param float $tax Sales tax for fee.
	 *
	 * @since 5.0
	 */
	protected function set_fee_tax( $id, $tax ) {
		$this->set_product_tax( $id, $tax );
	}

	/**
	 * Sets the exemption certificate for the order.
	 *
	 * @param object $certificate Exemption certificate object.
	 *
	 * @since 6.0.7
	 */
	public function set_certificate( $certificate ) {
		// TODO: Remove this method in v8.
		_deprecated_function(
			__CLASS__ . '::' . __METHOD__,
			'7.0.0',
			__CLASS__ . '::set_certificate_id'
		);

		$certificate_id = '';
		if ( is_object( $certificate ) && method_exists( $certificate, 'getCertificateID' ) ) {
			$certificate_id = $certificate->getCertificateID();
		} elseif ( is_object( $certificate ) && method_exists( $certificate, 'getCertificateId' ) ) {
			$certificate_id = $certificate->getCertificateId();
		}

		$this->set_certificate_id( $certificate_id );
	}

	/**
	 * Get the exemption certificate to apply for this order.
	 *
	 * @return TaxCloud_V3\Model\Exemption|TaxCloud\ExemptionCertificate|null
	 * @since 7.0.0
	 */
	public function get_certificate() {
		$cert_id = $this->get_certificate_id();

		if ( ! $cert_id ) {
			return null;
		}

		if ( $cert_id === SST_SINGLE_PURCHASE_CERT_ID ) {
			return $this->get_single_purchase_certificate();
		}

		if ( 'v3' === sst_get_api_version() ) {
			return new \TaxCloud_V3\Model\Exemption( $cert_id );
		}

		return new ExemptionCertificateBase( $cert_id );
	}

	/**
	 * Set the single-purchase exemption certificate for the order.
	 *
	 * @param object $certificate Single-purchase exemption certificate object.
	 *
	 * @since 8.0.0
	 */
	public function set_single_purchase_certificate( $certificate ) {
		if ( ! is_object( $certificate ) || ! method_exists( $certificate, 'getDetail' ) ) {
			return;
		}
		$this->update_meta(
			'single_purchase_cert',
			wp_json_encode( $certificate )
		);
		$this->set_certificate_id( SST_SINGLE_PURCHASE_CERT_ID );
	}

	/**
	 * Get the single-purchase exemption certificate for the order.
	 *
	 * @return TaxCloud\ExemptionCertificate|null
	 *
	 * @since 8.0.0
	 */
	public function get_single_purchase_certificate() {
		$cert = $this->get_meta( 'single_purchase_cert' );

		if ( ! $cert ) {
			return null;
		}

		$decoded_cert = json_decode( $cert, true );

		return is_array( $decoded_cert )
			? ExemptionCertificate::fromArray( $decoded_cert )
			: null;
	}

	/**
	 * Get the ID of the applied exemption certificate.
	 *
	 * @return string Exemption certificate ID
	 *
	 * @since 7.0.0
	 */
	public function get_certificate_id() {
		$certificate_or_id = $this->get_meta( 'exempt_cert' );

		// Prior to SST 7.0 we saved the entire certificate object.
		// Now we just save the certificate ID.
		if ( $certificate_or_id instanceof ExemptionCertificateBase ) {
			$certificate_or_id = $certificate_or_id->getCertificateID();
		}

		return is_string( $certificate_or_id ) ? $certificate_or_id : '';
	}

	/**
	 * Set the ID of the applied exemption certificate.
	 *
	 * @param string $certificate_id Exemption certificate ID.
	 *
	 * @since 7.0.0
	 */
	public function set_certificate_id( $certificate_id ) {
		$certificate_id = is_string( $certificate_id ) ? $certificate_id : '';
		$this->update_meta( 'exempt_cert', $certificate_id );
	}

	/**
	 * Handle an error by logging it or displaying it to the user.
	 *
	 * @param string $message Message describing the error.
	 *
	 * @since 5.0
	 */
	protected function handle_error( $message ) {
		SST_Logger::add( $message );

		if ( ! defined( 'DOING_AJAX' ) && function_exists( 'sst_add_message' ) ) {
			sst_add_message( $message, 'error' );
		}
	}

	/**
	 * Get TaxCloud status.
	 *
	 * @param string $context The context to format the status for. Can be 'edit' or 'view' (default: 'edit').
	 *
	 * @return string
	 * @since 5.0
	 */
	public function get_taxcloud_status( $context = 'edit' ) {
		$status = $this->get_meta( 'status' );

		if ( 'view' === $context ) {
			$status = ucfirst( $status );
		}

		return $status;
	}

	/**
	 * Get billing address.
	 *
	 * @return array
	 * @since 5.5
	 */
	protected function get_billing_address() {
		return sst_get_order_billing_address( $this->order );
	}

	/**
	 * Get shipping address.
	 *
	 * @return array
	 * @since 5.5
	 */
	protected function get_shipping_address() {
		return sst_get_order_shipping_address( $this->order );
	}

	/**
	 * Get destination address.
	 *
	 * NOTE: This method is solely maintained for backward compatibility with
	 * the sst_update_50_order_data update routine. It should generally not be
	 * used elsewhere.
	 *
	 * @return TaxCloud_V3\Model\Address|NULL
	 * @since 5.0
	 */
	public function get_destination_address() {
		$raw_address = $this->get_shipping_address();

		try {
			if ( 'v3' === sst_get_api_version() ) {
				$address = new \TaxCloud_V3\Model\Address(
					array(
						'city'        => $raw_address['city'],
						'countryCode' => isset( $raw_address['country'] ) ? $raw_address['country'] : 'US',
						'line1'       => $raw_address['address'],
						'line2'       => isset( $raw_address['address_2'] ) ? $raw_address['address_2'] : '',
						'state'       => $raw_address['state'],
						'zip'         => $raw_address['postcode'],
					)
				);
			} else {
				$address = new TaxCloud\Address(
					$raw_address['address'],
					isset( $raw_address['address_2'] ) ? $raw_address['address_2'] : '',
					$raw_address['city'],
					$raw_address['state'],
					substr( $raw_address['postcode'], 0, 5 ),
					substr( preg_replace( '/[^0-9]/', '', $raw_address['postcode'] ), 5, 4 ) ?: null
				);
			}

			// Logging
			SST_Logger::order_log( __( 'Verifying destination address.', 'simple-sales-tax' ), $this->order->get_id(), $address );

			$address = SST_Addresses::verify_address( $address, $outcome );
			$this->handle_address_verification( $outcome );
			return $address;
		} catch ( Exception $ex ) {
			if ( empty( $raw_address['postcode'] ) ) {
				$this->handle_address_verification( array(
					'input_hash' => md5( wp_json_encode( $raw_address ) ),
					'status'     => 'skipped',
					'reason'     => 'missing_street_or_zip',
					'changes'    => array(),
				) );
			}
			// Logging
			SST_Logger::order_log( __( 'Destination address is invalid.', 'simple-sales-tax' ), $this->order->get_id(), $ex->getMessage() );
			return null;
		}
	}

	/**
	 * Get order id for given package.
	 *
	 * Note: This overrides SST_Abstract_Cart::get_package_order_id().
	 * Cart-side lookup uses the MD5-hash version, while order-side capture
	 * uses this override returning '{order_id}_{key}'. This works because
	 * $package['cart_id'] is preserved through compress_package_data.
	 *
	 * @param string $package_key Package key.
	 * @param array  $package     Package (default: array()).
	 *
	 * @return string
	 * @since 5.0
	 */
	protected function get_package_order_id( $package_key, $package = array() ) {
		if ( isset( $package['order_id'] ) ) { /* Legacy (pre 5.0) order */
			return $package['order_id'];
		}

		$order_id = $this->order->get_id() . '_' . $package_key;

		return apply_filters(
			'sst_package_order_id',
			$order_id,
			$this->order,
			$package_key
		);
	}

	/**
	 * Send AuthorizedWithCapture request to capture order in TaxCloud.
	 *
	 * @return bool true on success, false on failure.
	 *
	 * @since 5.0
	 */
	public function do_capture() {
		$order = $this->order;

		// Logging
		SST_Logger::order_log( __( 'Capturing order.', 'simple-sales-tax' ), $order->get_id() );

		if ( 'data_mover' === sst_integration_mode() ) {
			$result = $this->capture_data_import( false );
			if ( is_wp_error( $result ) ) {
				SST_Logger::order_log( $result->get_error_message(), $order->get_id() );
				if ( 'sst_capture_unavailable' !== $result->get_error_code() ) {
					$this->handle_error( sprintf( 'TaxCloud capture #%d: %s', $order->get_id(), $result->get_error_message() ) );
				}
				return false;
			}
			return true;
		}

		if ( '' !== $this->get_meta( 'data_import_upload' ) ) {
			// An interrupted Data Import upload may already exist at TaxCloud.
			return false;
		}

		// Capture Order In TaxCloud is disabled.
		if ( 'no' === SST_Settings::get( 'capture_orders_in_taxcloud' ) ) {
			// Logging
			SST_Logger::order_log( __( 'Capture Order In TaxCloud is disabled in plugin settings.', 'simple-sales-tax' ), $order->get_id() );
			
			SST_Logger::add(
				sprintf(
					'Capture Order In TaxCloud is disabled in plugin settings. Skipping Capture process for Order ID: %d',
					$order->get_id()
				)
			);
			return false;
		}

		if ( ! $this->is_integration_active() ) {
			SST_Logger::order_log( __( 'TaxCloud integration is disabled or unconfigured. Skipping order capture.', 'simple-sales-tax' ), $order->get_id() );
			return false;
		}

		// Let devs control whether the order is captured in TaxCloud.
		if ( ! apply_filters( 'sst_should_capture_order', true, $order, $this ) ) {
			// Note: This is considered a success for consistency with do_refund.
			// We should change this sooner than later.
			return true;
		}

		$taxcloud_status = $this->get_taxcloud_status();
		$packages        = $this->get_packages();

		if (
			'pending' === $taxcloud_status
			&& ! $this->has_capturable_packages( $packages )
		) {
			SST_Logger::order_log( __( 'No capturable packages found. Rebuilding packages before capture.', 'simple-sales-tax' ), $order->get_id() );
			$packages = $this->do_lookup();
		}

		// Handle error cases.
		if ( 'captured' === $taxcloud_status ) {
			// Logging
			SST_Logger::order_log( __( 'Order already captured.', 'simple-sales-tax' ), $order->get_id() );

			if ( 'completed' === SST_Settings::get_capture_trigger() ) {
				$this->handle_error(
					sprintf(
						/* translators: WooCommerce order ID */
						__( 'Failed to capture order %d: already captured.', 'simple-sales-tax' ),
						$order->get_id()
					)
				);
			}

			return false;
		} else {
			if ( 'refunded' === $taxcloud_status ) {
				// Logging
				SST_Logger::order_log( __( 'Order already refunded.', 'simple-sales-tax' ), $order->get_id() );

				$this->handle_error(
					sprintf(
						/* translators: WooCommerce order ID */
						__( 'Failed to capture order %d: order was refunded.', 'simple-sales-tax' ),
						$order->get_id()
					)
				);

				return false;
			}
		}
		
		// Logging
		SST_Logger::order_log( __( 'Capturing order packages:', 'simple-sales-tax' ), $order->get_id(), $packages );

		if ( ! $this->has_capturable_packages( $packages ) ) {
			$this->handle_error(
				sprintf(
					/* translators: WooCommerce order ID */
					__( 'Failed to capture order %d: no TaxCloud lookup packages were found.', 'simple-sales-tax' ),
					$order->get_id()
				)
			);

			return false;
		}

		/**
		 * Inspect real-time lookup amounts before either API captures them.
		 *
		 * @param WC_Order $order    Order being captured.
		 * @param array    $packages Saved or freshly rebuilt lookup packages.
		 */
		do_action( 'sst_before_order_capture', $order, $packages );

		// Capture with the API that created the saved lookup package.
		if ( 'v3' === $this->get_packages_api_version( $packages ) ) {
			return $this->capture_order_v3( $packages, $order );
		}

		// Send AuthorizedWithCapture for all packages.
		foreach ( $packages as $key => $package ) {
			$now      = gmdate( 'c' );
			$order_id = $this->get_package_order_id( $key, $package );

			try {
				$request = new TaxCloud\Request\AuthorizedWithCapture(
					$this->api_id,
					$this->api_key,
					$package['customer_id'],
					$package['cart_id'],
					$order_id,
					$now,
					$now
				);

				// Logging
				SST_Logger::order_log( __( 'Sending AuthorizedWithCapture request.', 'simple-sales-tax' ), $order->get_id(), wp_json_encode( $request ) );

				TaxCloud()->AuthorizedWithCapture( $request );

				// Logging
				SST_Logger::order_log( __( 'AuthorizedWithCapture request successfully sent.', 'simple-sales-tax' ), $order->get_id(), $request );
			} catch ( Exception $ex ) {
				// Logging
				SST_Logger::order_log( __( 'Failed to capture order.', 'simple-sales-tax' ), $order->get_id(), $ex->getMessage() );

				$this->handle_error(
					sprintf(
						/* translators: 1 - WooCommerce order ID, 2 - Error message from TaxCloud */
						__( 'Failed to capture order %1$d: %2$s.', 'simple-sales-tax' ),
						$order->get_id(),
						$ex->getMessage()
					)
				);

				return false;
			}
		}

		$this->update_meta( 'status', 'captured' );

		// Logging
		SST_Logger::order_log( __( 'Order status updated to captured.', 'simple-sales-tax' ), $order->get_id() );

		$order->save();

		return true;
	}

	/**
	 * Determine whether saved lookup packages contain the data needed to capture.
	 *
	 * @param array $packages Saved TaxCloud lookup packages.
	 *
	 * @return bool True if all packages can be captured.
	 */
	protected function has_capturable_packages( $packages ) {
		if ( empty( $packages ) || ! is_array( $packages ) ) {
			return false;
		}

		foreach ( $packages as $package ) {
			if (
				! isset( $package['customer_id'], $package['cart_id'] )
				|| '' === $package['customer_id']
				|| '' === $package['cart_id']
			) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get the API version used to create a set of saved packages.
	 *
	 * Packages created before the API switch was introduced have no version
	 * marker and must remain on V1. The itemId fallback identifies packages
	 * produced by the existing V3 Data Import format.
	 *
	 * @param array $packages Saved TaxCloud lookup packages.
	 *
	 * @return string V1 or V3.
	 */
	protected function get_packages_api_version( $packages ) {
		foreach ( (array) $packages as $package ) {
			if ( isset( $package['api_version'] ) ) {
				return 'v3' === $package['api_version'] ? 'v3' : 'v1';
			}

			foreach ( (array) ( $package['cart_items'] ?? array() ) as $cart_item ) {
				if ( isset( $cart_item['itemId'] ) ) {
					return 'v3';
				}
			}
		}

		return 'v1';
	}

	/**
	 * Send Returned request to fully or partially refund an order.
	 *
	 * @param WC_Order|array $refund_or_items Refund order or array of items to
	 *                                        refund. Items array is deprecated
	 *                                        and should no longer be used.
	 * @param array $args Arguments.
	 *
	 * @return bool True on success, false on failure.
	 *
	 * @since 5.0
	 */
	public function do_refund( $refund_or_items ) {
		$order = $this->order;

		// Logging
		SST_Logger::order_log( __( 'Sending Refund request.', 'simple-sales-tax' ), $order->get_id() );

		// Let devs control whether the order is refunded in TaxCloud.
		if ( ! apply_filters( 'sst_should_refund_order', true, $order, $this ) ) {
			// Note: This condition needs to be considered a success or SST
			// will delete the refund created by WooCommerce. We should find
			// a better way to communicate what's happening in this scenario
			// to the caller.
			return true;
		}

		if ( ! in_array( $this->get_taxcloud_status(), array( 'captured', 'partially_refunded' ), true ) ) {
			// Logging
			SST_Logger::order_log( __( 'Order must be captured first.', 'simple-sales-tax' ), $order->get_id() );

			$this->handle_error(
				sprintf(
					/* translators: WooCommerce order ID */
					__( "Can't refund order %d: order must be completed first.", 'simple-sales-tax' ),
					$order->get_id()
				)
			);

			return false;
		}

		// V3: Data Mover Mode - Refund order in TaxCloud.
		$data_mover = SST_Settings::get( 'data_mover' );
		if ( $data_mover ) {
			SST_Logger::order_log( __( 'Data Mover Mode enabled. Refunding using v3.', 'simple-sales-tax' ), $order->get_id() );
		}

		if ( is_array( $refund_or_items ) ) {
			// TODO: Drop support for items array in v8.
			wc_deprecated_argument(
				'items',
				'7.0.5',
				'Passing an items array into SST_Order::do_refund() is no longer supported. Please pass an instance of WC_Order_Refund instead.'
			);

			$items = $refund_or_items;
		} else {
			$items = $refund_or_items->get_items(
				array( 'fee', 'shipping', 'line_item' )
			);
		}

		$refund_id         = is_object( $refund_or_items ) && method_exists( $refund_or_items, 'get_id' ) ? $refund_or_items->get_id() : 0;
		$refund_full_order = empty( $items );

		if ( $refund_full_order && is_object( $refund_or_items ) && method_exists( $refund_or_items, 'get_amount' ) ) {
			// An amount alone cannot tell us which prices or TICs were refunded.
			// Use all captured lines only when this refund reverses the entire original order.
			if ( $refund_or_items->get_amount() < $order->get_total() || $order->get_remaining_refund_amount() > 0 ) {
				$message = sprintf(
					/* translators: %d: WooCommerce order ID */
					__( 'Could not sync the amount-only refund for order %d to TaxCloud. Refund specific order lines or record the return directly in TaxCloud.', 'simple-sales-tax' ),
					$order->get_id()
				);
				$this->handle_error( $message );
				$order->add_order_note( $message );
				return false;
			}
		}

		// Process refunds while items remain.
		$packages    = $this->get_packages();
		$api_version = $data_mover ? 'v3' : $this->get_packages_api_version( $packages );

		// Logging
		SST_Logger::order_log( __( 'Refunding order packages:', 'simple-sales-tax' ), $order->get_id(), $packages );

		if ( empty( $packages ) ) {
			$message = sprintf(
				/* translators: WooCommerce order ID */
				__( 'Failed to sync refund for order %d to TaxCloud: no saved TaxCloud packages were found.', 'simple-sales-tax' ),
				$order->get_id()
			);
			SST_Logger::order_log( $message, $order->get_id() );
			$this->handle_error( $message );
			$order->add_order_note( $message );
			return false;
		}

		$line_ids       = $refund_full_order ? array() : $this->get_captured_line_ids( $packages );
		$refund_amounts = $this->get_refund_amounts( $items, $line_ids );

		foreach ( $packages as $package_key => $package ) {
			$cart_items      = $package['cart_items'];
			$shipping_method = $this->process_method_id(
				$package['shipping_method']
			);
			$refund_items    = array();

			foreach ( $cart_items as $item_index => $cart_item ) {
				$item_id    = ( 'v3' === $api_version ) ? $cart_item['itemId'] : $cart_item['id'];
				$line_id    = $line_ids[ $package_key ][ $item_index ] ?? null;
				$refund_key = $item_id;

				if ( null !== $line_id ) {
					$refund_key = 'line:' . $line_id;
				} elseif ( 'shipping' === $cart_item['type'] ) {
					$refund_key = $shipping_method;

					if ( 'v3' !== $api_version ) {
						$item_id = $shipping_method;
					}
				}

				if ( (float) $cart_item['price'] <= 0 || ( ! $refund_full_order && empty( $refund_amounts[ $refund_key ] ) ) ) {
					continue;
				}

				$refund_qty = $refund_full_order ? $cart_item['qty'] : min(
					$cart_item['qty'],
					$refund_amounts[ $refund_key ] / $cart_item['price']
				);

				if ( 'v3' === $api_version ) {
					$refund_items[] = array(
						'itemId'       => $item_id,
						'quantity'     => $refund_qty,
						'cartItemIndex' => isset( $cart_item['index'] ) ? (int) $cart_item['index'] : (int) $item_index,
					);
				} else {
					$refund_items[] = new TaxCloud\CartItem(
						$item_index,
						$cart_item['id'],
						$cart_item['tic'],
						$cart_item['price'],
						$refund_qty
					);
				}
				
				if ( ! $refund_full_order ) {
					$refund_amounts[ $refund_key ] = max( 0, $refund_amounts[ $refund_key ] - $refund_qty * $cart_item['price'] );
				}
			}

			// Logging
			SST_Logger::order_log( __( 'Refunding order items:', 'simple-sales-tax' ), $order->get_id(), $refund_items );

			if ( ! empty( $refund_items ) ) {
				$order_id = $this->get_package_order_id(
					$package_key,
					$package
				);

				if ( 'v3' === $api_version ) {
					// Refund class
					$txc_refund = new TaxCloud_V3\Refunds();

					// Refund order
					$refund_args = array( 'items' => $refund_items );
					if ( $refund_id ) {
						$refund_args['idempotencyKey'] = 'woocommerce-refund-' . $refund_id;
					}

					$response = $txc_refund->refund_order( $order_id, $refund_args );

					if ( is_wp_error( $response ) ) {
						SST_Logger::order_log( sprintf( __( 'Failed to refund package %s in TaxCloud.', 'simple-sales-tax' ), $order_id ), $order->get_id(), $response->get_error_message() );
						$message = sprintf(
							/* translators: 1 - WooCommerce order ID, 2 - Error message from TaxCloud */
							__( 'Failed to sync refund for order %1$d to TaxCloud: %2$s.', 'simple-sales-tax' ),
							$order->get_id(),
							$response->get_error_message()
						);
						$this->handle_error( $message );
						$order->add_order_note( $message );
						return false;
					} elseif ( ! empty( $response ) ) {
						SST_Logger::order_log( sprintf( __( 'Refund request response for package %s in TaxCloud.', 'simple-sales-tax' ), $order_id ), $order->get_id(), $response );
					}
				} else {
					try {
						$request = new TaxCloud\Request\Returned(
							$this->api_id,
							$this->api_key,
							$order_id,
							$refund_items,
							gmdate( 'c' )
						);

						// Logging
						SST_Logger::order_log( __( 'Refund request sent.', 'simple-sales-tax' ), $order->get_id(), $request );

						TaxCloud()->Returned( $request );
					} catch ( Exception $ex ) {
						// Logging
						SST_Logger::order_log( __( 'Refund request failed.', 'simple-sales-tax' ), $order->get_id(), $ex->getMessage() );

						$message = sprintf(
							/* translators: 1 - WooCommerce order ID, 2 - Error message from TaxCloud */
							__( 'Failed to sync refund for order %1$d to TaxCloud: %2$s.', 'simple-sales-tax' ),
							$order->get_id(),
							$ex->getMessage()
						);
						$this->handle_error( $message );
						$order->add_order_note( $message );

						return false;
					}
				}
			}
		}

		// If order was fully refunded, set status accordingly.
		if ( 0 >= $order->get_remaining_refund_amount() || did_action( 'woocommerce_order_fully_refunded' ) ) {
			$this->update_meta( 'status', 'refunded' );

			// Logging
			SST_Logger::order_log( __( 'Order fully refunded.', 'simple-sales-tax' ), $order->get_id() );

			$order->save();
		} elseif ( did_action( 'woocommerce_order_partially_refunded' ) ) {
			$this->update_meta( 'status', 'partially_refunded' );

			// Logging
			SST_Logger::order_log( __( 'Order partially refunded.', 'simple-sales-tax' ), $order->get_id() );

			$order->save();
		}

		return true;
	}

	/**
	 * Shipping methods like WooCommerce FedEx Drop Shipping Pro use nonstandard
	 * method IDs. This method converts those nonstandard IDs into the standard
	 * format.
	 *
	 * @param string $method_id WooCommerce shipping method ID.
	 *
	 * @return string
	 * @since 5.3
	 */
	protected function process_method_id( $method_id ) {
		if ( class_exists( 'IgniteWoo_Shipping_Fedex_Drop_Shipping_Pro' ) ) {
			$method_id = preg_replace( '/FedEx - (.*)/', 'fedex_wsdl:$1', $method_id );
		}
		if ( class_exists( 'ups_drop_shipping_rate' ) ) {
			$method_id = preg_replace( '/ups_drop_shipping_rate_UPS (.*)/', 'ups_drop_shipping_rate:$1', $method_id );
		}

		return current( explode( ':', $method_id ) );
	}

	/**
	 * Resolve captured product lines back to their WooCommerce order item IDs.
	 * Checkout packages carry a cart key; order recalculations use the order item ID.
	 * Older Product Bundles orders can also supply the original cart key.
	 */
	protected function get_captured_line_ids( $packages ) {
		$order_items = $this->order->get_items( 'line_item' );
		$cart_keys   = array();
		$line_ids    = array();

		foreach ( $order_items as $id => $item ) {
			foreach ( array( '_sst_cart_item_key', '_bundle_cart_key' ) as $meta_key ) {
				$key = $item->get_meta( $meta_key, true );
				if ( '' !== $key ) {
					$cart_keys[ $key ] = $id;
				}
			}
		}

		foreach ( $packages as $package_key => $package ) {
			foreach ( $package['cart_items'] as $index => $line ) {
				if ( 'line_item' !== $line['type'] || ! isset( $line['cart_id'] ) ) {
					continue;
				}

				$key = $line['cart_id'];
				$id  = $cart_keys[ $key ] ?? $key;
				if ( isset( $order_items[ $id ] ) ) {
					$item = $order_items[ $id ];
					if ( (string) ( $item->get_variation_id() ?: $item->get_product_id() ) === (string) ( $line['itemId'] ?? $line['id'] ) ) {
						$line_ids[ $package_key ][ $index ] = $id;
					}
				}
			}
		}

		return $line_ids;
	}

	/**
	 * Get refund amounts for each refund item.
	 *
	 * @since 7.0.5
     *
	 * @param array $items    Refund items.
	 * @param array $line_ids Captured WooCommerce order line IDs by package and index.
	 *
	 * @return array Associative array where keys are item IDs and values
	 *               are amounts to refund.
	 */
	protected function get_refund_amounts( $items, $line_ids = array() ) {
		$refund_amounts = array();
		$captured_ids   = array();
		foreach ( $line_ids as $package_line_ids ) {
			$captured_ids += array_fill_keys( $package_line_ids, true );
		}

		foreach ( $items as $id => $item ) {
			switch ( $item->get_type() ) {
				case 'line_item':
					$original_id = $item->get_meta( '_refunded_item_id', true ) ?: $id;
					if ( isset( $captured_ids[ $original_id ] ) ) {
						$item_id = 'line:' . $original_id;
						break;
					}
					$item_id = $item->get_variation_id()
						? $item->get_variation_id()
						: $item->get_product_id();
					break;
				case 'shipping':  // TODO: handle packages w/ same method.
					$item_id = $this->process_method_id( $item->get_method_id() );
					break;
				case 'fee':
					$name    = ! empty( $item->get_name() )
						? $item->get_name()
						: __( 'Fee', 'simple-sales-tax' );
					$item_id = sanitize_title( $name );
					break;
				default:
					// Unsupported item type
					continue 2;
			}

			// Refund line item amounts are negative - use abs to get magnitude
			$refund_amounts[ $item_id ] = ( $refund_amounts[ $item_id ] ?? 0 ) + abs( $item->get_total() );
		}

		return $refund_amounts;
	}

	/**
	 * Update order meta.
	 *
	 * @param string $key   Meta key.
	 * @param mixed  $value Meta value.
	 *
	 * @since 5.0
	 */
	public function update_meta( $key, $value ) {
		$key = self::$prefix . $key;

		// The posts data store only slashes strings before WordPress recursively
		// unslashes metadata. Serialize arrays to preserve nested backslashes.
		if ( ! is_string( $value ) ) {
			$value = serialize( $value );
		}
		$this->order->update_meta_data( $key, $value );
	}

	/**
	 * Get meta value.
	 *
	 * @param string $key     Meta key.
	 * @param bool   $single  Return first found meta with key, or all with $key.
	 * @param string $context What the value is for. Valid values are view and edit.
	 *
	 * @return mixed empty string if key doesn't exist, otherwise value.
	 * @since 5.0
	 */
	public function get_meta( $key = '', $single = true, $context = 'view' ) {
		$value = $this->order->get_meta( self::$prefix . $key, $single, $context );

		if ( is_string( $value ) && 'exempt_cert' === $key && 0 === strpos( $value, 'O:' ) ) {
			// Pre-7.0 orders can contain a double-serialized certificate. Never
			// instantiate other plugins' classes from an order meta string.
			$certificate = $this->decode_legacy_meta( $value, self::LEGACY_CERTIFICATE_CLASSES );
			if ( $certificate instanceof ExemptionCertificateBase ) {
				$value = $certificate;
			}
		} elseif ( is_string( $value ) && in_array( $key, array( 'packages', 'removed_packages' ), true ) && 0 === strpos( $value, 'a:' ) ) {
			// Pre-7.0 packages need these data objects for compression/migration.
			// Never allow WC_Data/WC_Product or unrelated plugin classes to wake up.
			$packages = $this->decode_legacy_meta(
				$value,
				array_merge(
					self::LEGACY_CERTIFICATE_CLASSES,
					array( 'TaxCloud\Request\Lookup', 'TaxCloud\CartItem', 'TaxCloud\Address', 'WC_Shipping_Rate' )
				)
			);
			if ( is_array( $packages ) ) {
				$value = $packages;
			}
		}

		if ( empty( $value ) && array_key_exists( $key, self::$defaults ) ) {
			$value = self::$defaults[ $key ];
		}

		return $value;
	}

	/**
	 * Decode legacy metadata, retaining the same class restrictions on a retry.
	 *
	 * @param string $value           Serialized metadata.
	 * @param array  $allowed_classes Legacy data classes allowed for this key.
	 * @return mixed Decoded value, or false on failure.
	 */
	private function decode_legacy_meta( $value, $allowed_classes ) {
		$options = array( 'allowed_classes' => $allowed_classes );
		$decoded = @unserialize( $value, $options );
		if ( false === $decoded ) {
			// Some older WooCommerce versions left extra slashes in meta. Try the
			// original first so valid serialized strings keep their backslashes.
			$unslashed = sst_unslash( $value );
			if ( $unslashed !== $value ) {
				$decoded = @unserialize( $unslashed, $options );
			}
		}
		return $decoded;
	}

	/**
	 * Gets a saved package by its package hash.
	 *
	 * @param string $hash Package hash.
	 *
	 * @return array|bool The saved package with the given hash, or false if no such package exists.
	 */
	protected function get_saved_package( $hash ) {
		$saved_packages = $this->get_meta( 'packages' );

		if ( ! is_array( $saved_packages ) ) {
			return false;
		}

		foreach ( $saved_packages as $package ) {
			if ( $hash === $this->get_package_hash( $package ) ) {
				return $package;
			}
		}

		return false;
	}

	/**
	 * Saves a package.
	 *
	 * @param string $hash    Package hash.
	 * @param array  $package Package.
	 */
	protected function save_package( $hash, $package ) {
		// No op.
	}

	/**
	 * Check eligibility using the original metadata, before Data Import package
	 * compression can relabel a previous Real Time package as v3.
	 *
	 * @param bool $allow_realtime_packages Preserve automatic capture after a mode switch.
	 * @return bool
	 */
	public function is_data_import_capture_candidate( $allow_realtime_packages = false ) {
		if (
			'data_mover' !== sst_integration_mode()
			|| ! $this->order instanceof WC_Order
			|| 'shop_order' !== $this->order->get_type()
			|| ! $this->order->get_id()
			|| ! $this->order->has_status( array( 'processing', 'completed' ) )
			|| 'pending' !== $this->get_taxcloud_status()
			|| $this->order->get_refunds()
		) {
			return false;
		}

		return $allow_realtime_packages || ! $this->has_realtime_packages();
	}

	/** Inspect original package data before mode-dependent compression. */
	private function has_realtime_packages() {
		foreach ( (array) $this->get_meta( 'packages' ) as $package ) {
			if ( ! empty( $package['cart_id'] ) || ! empty( $package['response'] )
				|| 'v1' === ( $package['api_version'] ?? '' )
				|| ( $package['request'] ?? null ) instanceof TaxCloud\Request\Lookup
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return bool Whether this order can be uploaded under the current settings.
	 */
	public function can_capture_data_import_order() {
		return $this->is_data_import_capture_candidate() && $this->is_integration_active();
	}

	/**
	 * Capture the saved order on explicit request from the admin order panel.
	 *
	 * @return true|WP_Error
	 */
	public function capture_data_import_manually() {
		return $this->capture_data_import( true );
	}

	/**
	 * Serialize Data Import uploads across manual and automatic requests.
	 *
	 * @param bool $manual Whether to bypass the automatic capture setting.
	 * @return true|WP_Error
	 */
	private function capture_data_import( $manual ) {
		global $wpdb;

		if ( ! $manual && 'no' === SST_Settings::get( 'capture_orders_in_taxcloud' ) ) {
			return new WP_Error( 'sst_capture_unavailable', __( 'Automatic TaxCloud capture is turned off.', 'simple-sales-tax' ) );
		}

		$order_id  = $this->order->get_id();
		$lock_name = 'sst_capture_' . md5( DB_NAME . ':' . $wpdb->prefix . ':' . $order_id );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) ) ) {
			return new WP_Error( 'sst_capture_busy', __( 'TaxCloud capture could not start. Wait a moment and try again.', 'simple-sales-tax' ) );
		}

		try {
			// Construct a fresh WC order to avoid an instance cached before the lock.
			$this->order = new WC_Order( $order_id );
			$this->order->read_meta_data( true );

			if ( ! $this->is_data_import_capture_candidate( ! $manual ) ) {
				return new WP_Error( 'sst_capture_unavailable', __( 'This order is no longer eligible for Data Import capture.', 'simple-sales-tax' ) );
			}
			if ( ! $this->is_integration_active() ) {
				return new WP_Error( 'sst_capture_unavailable', __( 'Enable the TaxCloud integration and configure its API credentials to capture this order.', 'simple-sales-tax' ) );
			}
			if ( ! apply_filters( 'sst_should_capture_order', true, $this->order, $this ) ) {
				return new WP_Error( 'sst_capture_unavailable', __( 'TaxCloud capture was skipped by a store customization.', 'simple-sales-tax' ) );
			}

			return $this->upload_data_import_order();
		} catch ( Exception $ex ) {
			return new WP_Error( 'sst_capture_failed', __( 'TaxCloud capture could not finish. Check the order and try again.', 'simple-sales-tax' ) );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	/**
	 * Prepare missing packages from persisted order data without recalculating
	 * WooCommerce taxes or calling the Cart API.
	 *
	 * @return array
	 */
	private function build_data_import_packages() {
		$packages = array();
		foreach ( $this->create_packages() as $package ) {
			if ( ! $this->is_origin_valid( $package ) || ! $this->is_destination_valid( $package ) ) {
				return array();
			}
			$package['request'] = $this->get_lookup_for_package( $package );
			$packages[] = $this->compress_package_data( $package );
		}
		return $packages;
	}

	/**
	 * Detect changes to order data that feeds the upload. Ignore notes and
	 * metadata written by capture itself.
	 */
	private function data_import_order_fingerprint() {
		$data = array_intersect_key(
			$this->order->get_data(),
			array_flip( array( 'customer_id', 'billing', 'shipping', 'currency', 'date_created', 'date_completed', 'total', 'total_tax' ) )
		);
		foreach ( $this->order->get_items( array( 'line_item', 'shipping', 'fee' ) ) as $item ) {
			$item_data = $item->get_data();
			unset( $item_data['meta_data'] );
			$data['items'][ $item->get_id() ] = $item_data;
		}
		return hash( 'sha256', wp_json_encode( $data ) );
	}

	/**
	 * Prepare and validate the entire upload before creating any remote orders.
	 *
	 * @return array|WP_Error
	 */
	private function prepare_data_import_upload( $api ) {
		$invalid = new WP_Error( 'sst_capture_packages', __( 'The order has incomplete TaxCloud upload data. Check its products, shipping, and addresses.', 'simple-sales-tax' ) );
		$packages = $this->has_realtime_packages() ? array() : $this->get_packages();
		if ( ! $packages ) {
			$packages = $this->build_data_import_packages();
		}
		if ( ! $packages ) {
			return $invalid;
		}

		// Count product occurrences rather than comparing WooCommerce item IDs
		// to cart keys. Check shipping and fees too, so a dropped package fails.
		$expected = array();
		foreach ( $this->order->get_items( array( 'line_item', 'shipping', 'fee' ) ) as $item ) {
			$type = $item->get_type();
			if ( 'line_item' === $type && ! $item->get_product() ) {
				return $invalid;
			}
			$key = 'line_item' === $type ? $type . ':' . ( $item->get_variation_id() ?: $item->get_product_id() ) : $type;
			$expected[ $key ] = ( $expected[ $key ] ?? 0 ) + 1;
		}

		$actual   = array();
		$payloads = array();
		$now      = time();
		$created  = $this->order->get_date_created();
		$complete = $this->order->get_date_completed();
		foreach ( $packages as $key => &$package ) {
			foreach ( array( 'origin', 'destination' ) as $address ) {
				if ( empty( $package[ $address ]['line1'] ) || empty( $package[ $address ]['city'] )
					|| empty( $package[ $address ]['state'] ) || empty( $package[ $address ]['zip'] )
				) {
					return $invalid;
				}
			}

			$id = (string) $this->get_package_order_id( $key, $package );
			if ( '' === $id || strlen( $id ) > 50 || isset( $payloads[ $id ] ) || empty( $package['cart_items'] ) ) {
				return $invalid;
			}
			foreach ( $package['cart_items'] as $item ) {
				$type = $item['type'] ?? '';
				$line_key = 'line_item' === $type ? $type . ':' . ( $item['itemId'] ?? '' ) : $type;
				$actual[ $line_key ] = ( $actual[ $line_key ] ?? 0 ) + 1;
			}
			$package['order_id'] = $id;
			$payloads[ $id ] = array(
				'orderId'           => $id,
				'customerId'        => 'customer-' . $package['customer_id'],
				'currencyCode'      => $this->order->get_currency(),
				'transactionDate'   => gmdate( 'c', $created ? $created->getTimestamp() : $now ),
				'completedDate'     => gmdate( 'c', $complete ? $complete->getTimestamp() : $now ),
				'origin'            => $package['origin'],
				'destination'       => $package['destination'],
				'deliveredBySeller' => ! empty( $package['delivered_by_seller'] ),
				'lineItems'         => $package['cart_items'],
			);
			if ( is_wp_error( $api->prepare_item_for_request( $payloads[ $id ] ) ) ) {
				return $invalid;
			}
		}
		if ( $expected != $actual ) {
			return $invalid;
		}

		return array( 'packages' => $packages, 'payloads' => $payloads, 'confirmed' => array() );
	}

	/**
	 * Resume the same upload after a rejection, outage, or lost POST response.
	 *
	 * @return true|WP_Error
	 */
	private function upload_data_import_order() {
		$api         = new TaxCloud_V3\Orders();
		$connection  = (string) SST_Settings::get( 'tc_key' );
		$identity    = hash( 'sha256', $connection );
		$fingerprint = $this->data_import_order_fingerprint();
		$saved       = $this->get_meta( 'data_import_upload' );
		$upload      = json_decode( $saved, true );

		if ( '' !== $saved ) {
			if ( ! is_array( $upload['packages'] ?? null ) || empty( $upload['payloads'] ) || ! is_array( $upload['confirmed'] ?? null )
				|| $identity !== ( $upload['connection'] ?? null ) ) {
				return new WP_Error( 'sst_capture_changed', __( 'The order or TaxCloud connection changed during capture. Review the original upload in TaxCloud before continuing.', 'simple-sales-tax' ) );
			}
			if ( $fingerprint !== ( $upload['fingerprint'] ?? null ) ) {
				$result = $this->reset_missing_data_import_upload( $api, $upload );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$saved = '';
			}
		}
		if ( '' === $saved ) {
			$upload = $this->prepare_data_import_upload( $api );
			if ( is_wp_error( $upload ) ) {
				return $upload;
			}
			$upload['connection']  = $identity;
			$upload['fingerprint'] = $fingerprint;
		}

		foreach ( $upload['payloads'] as $id => $payload ) {
			if ( isset( $upload['confirmed'][ $id ] ) ) {
				continue;
			}
			$remote = $api->get_order( (string) $id );
			if ( is_wp_error( $remote ) ) {
				$data = $remote->get_error_data();
				if ( 404 !== (int) ( $data['status'] ?? 0 ) ) {
					return new WP_Error( 'sst_capture_lookup', __( 'TaxCloud could not verify whether this order already exists. Try again.', 'simple-sales-tax' ) );
				}

				if ( '' === $saved ) {
					// Persist the exact request before the first POST. A failed
					// response can mean the remote order was already created.
					$saved = wp_json_encode( $upload );
					if ( false === $saved ) {
						return new WP_Error( 'sst_capture_packages', __( 'The order upload could not be saved. Check the order and try again.', 'simple-sales-tax' ) );
					}
					$this->update_meta( 'data_import_upload', $saved );
					$this->order->save_meta_data();
				}

				$remote = $api->create_order( $payload );
				if ( is_wp_error( $remote ) ) {
					$data = $remote->get_error_data();
					SST_Logger::order_log( 'TaxCloud Data Import upload failed.', $this->order->get_id(), array( 'status' => (int) ( $data['status'] ?? 0 ) ) );
					return new WP_Error( 'sst_capture_failed', __( 'TaxCloud did not confirm capture. Check the order and try again.', 'simple-sales-tax' ) );
				}
			}

			if ( ! $this->matches_data_import_upload( $remote, $payload, $connection ) ) {
				return new WP_Error( 'sst_capture_mismatch', __( 'TaxCloud returned an order that could not be matched to this upload. Review the order in TaxCloud before retrying.', 'simple-sales-tax' ) );
			}
			$upload['confirmed'][ $id ] = true;
			$saved = wp_json_encode( $upload );
			$this->update_meta( 'data_import_upload', $saved );
			$this->order->save_meta_data();
		}

		$this->set_packages( $upload['packages'] );
		$this->update_meta( 'status', 'captured' );
		$this->order->save_meta_data();

		// Keep the recovery data until the final status and refund packages
		// have been persisted. A crash during this step can then be retried.
		$this->order->delete_meta_data( self::$prefix . 'data_import_upload' );
		$this->order->save_meta_data();
		return true;
	}

	/**
	 * Rebuild corrected orders only after every original remote ID is missing.
	 * A rejection body alone cannot prove that a failed POST created nothing.
	 *
	 * @return true|WP_Error
	 */
	private function reset_missing_data_import_upload( $api, $upload ) {
		$changed = new WP_Error( 'sst_capture_changed', __( 'The order changed after an upload reached TaxCloud. Review the original upload in TaxCloud before continuing.', 'simple-sales-tax' ) );
		if ( $upload['confirmed'] ) {
			return $changed;
		}
		foreach ( array_keys( $upload['payloads'] ) as $id ) {
			$remote = $api->get_order( (string) $id );
			if ( ! is_wp_error( $remote ) ) {
				return $changed;
			}
			$data = $remote->get_error_data();
			if ( 404 !== (int) ( $data['status'] ?? 0 ) ) {
				return new WP_Error( 'sst_capture_lookup', __( 'TaxCloud could not verify whether this order already exists. Try again.', 'simple-sales-tax' ) );
			}
		}

		// Old checkout packages may contain the rejected address or item values.
		// Clear them too so preparation uses the corrected persisted WC order.
		$this->set_packages( array() );
		$this->order->delete_meta_data( self::$prefix . 'data_import_upload' );
		$this->order->save_meta_data();
		return true;
	}

	/**
	 * Check the remote identity and line quantities. TaxCloud may recalculate tax.
	 */
	private function matches_data_import_upload( $remote, $payload, $connection ) {
		if ( ( $remote['connectionId'] ?? null ) !== $connection
			|| ( $remote['orderId'] ?? null ) !== $payload['orderId']
			|| ( $remote['customerId'] ?? null ) !== $payload['customerId']
			|| ( $remote['currency']['currencyCode'] ?? null ) !== $payload['currencyCode']
			|| ! is_array( $remote['lineItems'] ?? null )
		) {
			return false;
		}

		$lines = static function ( $items ) {
			$result = array();
			foreach ( $items as $item ) {
				if ( ! isset( $item['index'], $item['itemId'], $item['quantity'] ) || ! is_numeric( $item['quantity'] ) ) {
					return null;
				}
				$result[] = array( (int) $item['index'], (string) $item['itemId'], (float) $item['quantity'] );
			}
			sort( $result );
			return $result;
		};

		return $lines( $remote['lineItems'] ) === $lines( $payload['lineItems'] );
	}

	/**
	 * Capture order in TaxCloud using V3 Carts/Orders API.
	 *
	 * @param array    $packages Packages.
	 * @param WC_Order $order    Order.
	 *
	 * @return bool True on success, false on failure.
	 * @since 8.4.7
	 */
	protected function capture_order_v3( $packages, $order ) {
		$carts_api = new TaxCloud_V3\Carts();
		$completed_date = $order->get_date_completed();
		$completed_date = $completed_date ? gmdate( 'c', $completed_date->getTimestamp() ) : gmdate( 'c' );

		foreach ( $packages as $key => $package ) {
			$order_id = $this->get_package_order_id( $key, $package );
			$cart_id  = isset( $package['cart_id'] ) ? $package['cart_id'] : $order_id;

			$response = $carts_api->create_order(
				$cart_id,
				$order_id,
				true,
				array( 'completedDate' => $completed_date )
			);

			if ( is_wp_error( $response ) ) {
				SST_Logger::order_log( __( 'Failed to create order from cart in TaxCloud.', 'simple-sales-tax' ), $order->get_id(), $response->get_error_message() );
				$this->handle_error(
					sprintf(
						/* translators: 1 - WooCommerce order ID, 2 - Error message from TaxCloud */
						__( 'Failed to capture order %1$d in TaxCloud: %2$s', 'simple-sales-tax' ),
						$order->get_id(),
						$response->get_error_message()
					)
				);
				return false;
			}
		}

		// Update TaxCloud Order Status
		$this->update_meta( 'status', 'captured' );
		SST_Logger::order_log( __( 'Order status updated to captured (V3).', 'simple-sales-tax' ), $order->get_id() );
		$order->save();

		return true;
	}

}
