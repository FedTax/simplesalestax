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
		return sst_format_order_items( $cart_items );
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

		/* Create a virtual package for all items that don't need shipping */
		$virtual_package = $this->create_virtual_package( $items );
		if ( $virtual_package ) {
			$packages[] = $virtual_package;
		}

		/* Create an additional package for each shipping method. */
		$ship_methods = $this->order->get_shipping_methods();

		if ( $ship_methods && $items ) {
			$items_per_package = ceil( count( $items ) / count( $ship_methods ) );
			$package_items     = array_chunk( $items, $items_per_package, true );

			foreach ( $package_items as $contents ) {
				$method = current( $ship_methods );

				/* Assign shipping method to package. */
				$package = sst_create_package(
					array(
						'contents' => $contents,
						'shipping' => new WC_Shipping_Rate(
							key( $ship_methods ),
							'',
							$method['cost'],
							array(),
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
		} elseif ( $items ) {
			/**
			 * If there are no shipping lines added to the order, we assume that
			 * there is a single shipment with all products that need shipping.
			 */
			$shippable_items = array();
			foreach ( $items as $item_id => $item ) {
				if ( isset( $item['data'] ) && $item['data']->needs_shipping() ) {
					$shippable_items[ $item_id ] = $item;
				}
			}
			$packages[] = sst_create_package(
				array(
					'contents' => $shippable_items,
					'user'     => array(
						'ID' => $this->order->get_user_id(),
					),
				)
			);
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
			if ( isset( $item['data'] ) && ! $item['data']->needs_shipping() ) {
				$virtual_items[ $key ] = $item;
				unset( $items[ $key ] );
			}
		}

		if ( ! empty( $virtual_items ) ) {
			return sst_create_package(
				array(
					'contents'    => $virtual_items,
					'destination' => $this->get_billing_address(),
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

				$raw_packages[ $key ]['destination'] = array(
					'country'   => 'US',
					'address'   => $pickup_address->getAddress1(),
					'address_2' => $pickup_address->getAddress2(),
					'city'      => $pickup_address->getCity(),
					'state'     => $pickup_address->getState(),
					'postcode'  => $pickup_address->getZip5(),
				);
			} elseif ( ! isset( $package['destination'] ) ) {
				$raw_packages[ $key ]['destination'] = $this->get_shipping_address();
			}
		}

		// Filter out packages with invalid destinations.
		$raw_packages = array_filter( $raw_packages, array( $this, 'is_package_destination_valid' ) );

		// Split packages by origin address.
		foreach ( $raw_packages as $raw_package ) {
			$packages = array_merge( $packages, $this->split_package( $raw_package ) );
		}

		// Add fees to first package.
		if ( apply_filters( 'wootax_add_fees', true ) ) {
			$fees = array();

			foreach ( $this->order->get_fees() as $item_id => $fee ) {
				$name   = empty( $fee['name'] ) ? __( 'Fee', 'simple-sales-tax' ) : $fee['name'];
				$fee_id = sanitize_title( $name );

				$fees[ $item_id ] = (object) array(
					'id'     => $fee_id,
					'amount' => $fee['line_total'],
				);
			}

			$packages[ key( $packages ) ]['fees'] = $fees;
		}

		return apply_filters( 'wootax_order_packages', $packages, $this->order );
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
	 * @param TaxCloud\ExemptionCertificateBase $certificate Exemption certificate object.
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
		if ( is_a( $certificate, 'TaxCloud\ExemptionCertificateBase' ) ) {
			$certificate_id = $certificate->getCertificateID();
		}

		$this->set_certificate_id( $certificate_id );
	}

	/**
	 * Get the exemption certificate to apply for this order.
	 *
	 * @return TaxCloud\ExemptionCertificateBase
	 * @since 7.0.0
	 */
	public function get_certificate() {
		$cert_id = $this->get_certificate_id();

		if ( ! $cert_id ) {
			return null;
		}

		if ( $cert_id === SST_SINGLE_PURCHASE_CERT_ID ) {
			return $this->get_single_purchase_certificate();
		} else {
			return new ExemptionCertificateBase( $cert_id );
		}
	}

	/**
	 * Set the single-purchase exemption certificate for the order.
	 *
	 * @param TaxCloud\ExemptionCertificate Single-purchase exemption certificate object.
	 *
	 * @since 8.0.0
	 */
	public function set_single_purchase_certificate( $certificate ) {
		if ( ! is_a( $certificate, 'TaxCloud\ExemptionCertificate' ) ) {
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
		if ( is_a( $certificate_or_id, 'TaxCloud\ExemptionCertificateBase' ) ) {
			return $certificate_or_id->getCertificateID();
		}

		return $certificate_or_id;
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
		return array(
			'country'   => $this->order->get_billing_country(),
			'address'   => $this->order->get_billing_address_1(),
			'address_2' => $this->order->get_billing_address_2(),
			'city'      => $this->order->get_billing_city(),
			'state'     => $this->order->get_billing_state(),
			'postcode'  => $this->order->get_billing_postcode(),
		);
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
	 * @return TaxCloud\Address|NULL
	 * @since 5.0
	 */
	public function get_destination_address() {
		$raw_address = $this->get_shipping_address();

		try {
			$address = new TaxCloud\Address(
				$raw_address['address'],
				$raw_address['address_2'],
				$raw_address['city'],
				$raw_address['state'],
				substr( $raw_address['postcode'], 0, 5 )
			);

			return SST_Addresses::verify_address( $address );
		} catch ( Exception $ex ) {
			return null;
		}
	}

	/**
	 * Get order id for given package.
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

		// Let devs control whether the order is captured in TaxCloud.
		if ( ! apply_filters( 'sst_should_capture_order', true, $order, $this ) ) {
			// Note: This is considered a success for consistency with do_refund.
			// We should change this sooner than later.
			return true;
		}

		$taxcloud_status = $this->get_taxcloud_status();
		$packages        = $this->get_packages();

		// Handle error cases.
		if ( 'captured' === $taxcloud_status ) {
			if ( 'no' === SST_Settings::get( 'capture_immediately' ) ) {
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

		// Send AuthorizedWithCapture for all packages.
		foreach ( $packages as $key => $package ) {
			$now      = date( 'c' );
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

				TaxCloud()->AuthorizedWithCapture( $request );
			} catch ( Exception $ex ) {
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
		$order->save();

		return true;
	}

	/**
	 * Send Returned request to fully or partially refund an order.
	 *
	 * @param WC_Order|array $refund_or_items Refund order or array of items to
	 *                                        refund. Items array is deprecated
	 *                                        and should no longer be used.
	 *
	 * @return bool True on success, false on failure.
	 *
	 * @since 5.0
	 */
	public function do_refund( $refund_or_items ) {
		$order = $this->order;

		// Let devs control whether the order is refunded in TaxCloud.
		if ( ! apply_filters( 'sst_should_refund_order', true, $order, $this ) ) {
			// Note: This condition needs to be considered a success or SST
			// will delete the refund created by WooCommerce. We should find
			// a better way to communicate what's happening in this scenario
			// to the caller.
			return true;
		}

		if ( 'captured' !== $this->get_taxcloud_status() ) {
			$this->handle_error(
				sprintf(
					/* translators: WooCommerce order ID */
					__( "Can't refund order %d: order must be completed first.", 'simple-sales-tax' ),
					$order->get_id()
				)
			);

			return false;
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

		if ( empty( $items ) ) {
			// Refund all items if items not explicitly passed.
			$items = $order->get_items(
				array( 'fee', 'shipping', 'line_item' )
			);
		}

		$refund_amounts = $this->get_refund_amounts( $items );

		// Process refunds while items remain.
		$packages = $this->get_packages();

		foreach ( $packages as $package_key => $package ) {
			$cart_items      = $package['cart_items'];
			$shipping_method = $this->process_method_id(
				$package['shipping_method']
			);
			$refund_items    = array();

			foreach ( $cart_items as $item_index => $cart_item ) {
				$item_id = $cart_item['id'];

				if ( 'shipping' === $cart_item['type'] ) {
					$item_id = $shipping_method;
				}

				if ( ! isset( $refund_amounts[ $item_id ] ) ) {
					continue;
				}

				$refund_amount = $refund_amounts[ $item_id ];

				if ( $refund_amount <= 0 ) {
					continue;
				}

				$refund_qty = min(
					$cart_item['qty'],
					$refund_amount / $cart_item['price']
				);

				$refund_items[] = new TaxCloud\CartItem(
					$item_index,
					$cart_item['id'],
					$cart_item['tic'],
					$cart_item['price'],
					$refund_qty
				);

				$refund_amount -= $refund_qty * $cart_item['price'];
			}

			if ( ! empty( $refund_items ) ) {
				$order_id = $this->get_package_order_id(
					$package_key,
					$package
				);

				try {
					$request = new TaxCloud\Request\Returned(
						$this->api_id,
						$this->api_key,
						$order_id,
						$refund_items,
						date( 'c' )
					);

					TaxCloud()->Returned( $request );
				} catch ( Exception $ex ) {
					$this->handle_error(
						sprintf(
							/* translators: 1 - WooCommerce order ID, 2 - Error message from TaxCloud */
							__( 'Failed to refund order %1$d: %2$s.', 'simple-sales-tax' ),
							$order->get_id(),
							$ex->getMessage()
						)
					);

					return false;
				}
			}
		}

		// If order was fully refunded, set status accordingly.
		if ( 0 >= $order->get_remaining_refund_amount() ) {
			$this->update_meta( 'status', 'refunded' );
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
	 * Get refund amounts for each refund item.
	 *
	 * @since 7.0.5
     *
	 * @param array $items Refund items.
	 *
	 * @return array Associative array where keys are item IDs and values
	 *               are amounts to refund.
	 */
	protected function get_refund_amounts( $items ) {
		$refund_amounts = array();

		foreach ( $items as $item ) {
			switch ( $item->get_type() ) {
				case 'line_item':
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
			$refund_amounts[ $item_id ] = abs( $item->get_total() );
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

		if ( ! is_string( $value ) ) {
			$value = serialize( $value ); /* $value must be a string */
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

		if ( is_string( $value ) ) {
			$value = maybe_unserialize( sst_unslash( $value ) ); /* for WC 3.1.0+ */
		}

		if ( empty( $value ) && array_key_exists( $key, self::$defaults ) ) {
			$value = self::$defaults[ $key ];
		}

		return $value;
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

}
