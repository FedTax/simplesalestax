<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use \TaxCloud\ExemptionCertificateBase;
use \TaxCloud\ExemptionCertificate;

/**
 * Abstract Cart.
 *
 * Provides a consistent interface for performing tax lookups for a cart.
 * Extended by both SST_Checkout and SST_Order.
 *
 * @author  Simple Sales Tax
 * @package SST
 * @since   5.0
 */
abstract class SST_Abstract_Cart {

	/**
	 * TaxCloud API ID.
	 *
	 * @var string
	 */
	protected $api_id;

	/**
	 * TaxCloud API Key.
	 *
	 * @var string
	 */
	protected $api_key;

	/**
	 * Constructor.
	 *
	 * Sets the TaxCloud API ID and API Key.
	 */
	public function __construct() {
		$this->api_id  = SST_Settings::get( 'tc_id' );
		$this->api_key = SST_Settings::get( 'tc_key' );
		// Modify the TIC for the Colorado Excise Tax fee.
		add_filter( 'wootax_fee_tic', array( $this, 'filter_excise_tax_tic' ), 10, 2 );
	}

	/**
	 * Ensures the Colorado Excise Tax fee is sent with TIC 99990.
	 *
	 * @param int $tic Original TIC.
	 * @param object $fee Fee object.
	 * @return int
	 */
	public function filter_excise_tax_tic( $tic, $fee ) {
		$id = is_object( $fee ) && isset( $fee->id ) ? $fee->id : ( is_string( $fee ) ? $fee : '' );
		$name = is_object( $fee ) && isset( $fee->name ) ? $fee->name : '';
		if ( $id === 'co-excise-tax' || $id === 'CO EXCISE TAX' || $name === 'CO EXCISE TAX' ) {
			return 99990;
		}
		return $tic;
	}

	/**
	 * Perform a tax lookup and update the sales tax for all items.
	 *
	 * @return bool True on success, false on error.
	 * @since 5.0
	 */
	public function calculate_taxes() {
		$this->reset_taxes();

		// No API Login ID or API Key? Bail.
		if ( empty( $this->api_id ) || empty( $this->api_key ) ) {
			SST_Logger::add( __( 'API Login ID or API Key is empty. Skipping lookup.', 'simple-sales-tax' ) );
			return false;
		}

		// Perform tax lookup(s).
		foreach ( $this->do_lookup() as $package ) {

			// Real-time tax calculation is disabled?.
			if ( 'yes' === SST_Settings::get( 'disable_real_time_calc' )) {
				SST_Logger::add( __( 'Real-time tax calculation is disabled. Calculating tax from saved packages.', 'simple-sales-tax' ), $package );
				continue;
			}

			$response = isset( $package['response'] ) && !empty( $package['response'] ) ? $package['response'] : false;

			// No response? Bail.
			if ( ! $response ) {
				SST_Logger::add( __( 'No response from TaxCloud. Skipping lookup.', 'simple-sales-tax' ) );
				continue;
			}

			if ( ! is_wp_error( $response ) ) {
				$tax_totals = current( $package['response'] );
				$cart_items = $package['cart_items'];

				foreach ( $cart_items as $index => $item ) {
					$tax_total = $tax_totals[ $index ];
					switch ( $item['type'] ) {
						case 'shipping':
							$this->set_shipping_tax(
								$item['cart_id'],
								$tax_total
							);
							break;
						case 'line_item':
							$this->set_product_tax(
								$item['cart_id'],
								$tax_total
							);
							break;
						case 'fee':
							// Don't apply tax to CO EXCISE TAX fee - it's a tax itself
							$fee_id = isset( $item['id'] ) ? $item['id'] : '';
							if ( $fee_id === 'co-excise-tax' || $fee_id === 'CO EXCISE TAX' ) {
								// Skip setting tax on excise tax fee - TaxCloud may return tax for it
								// but we don't want to charge tax on a tax
								break;
							}
							$this->set_fee_tax(
								$item['cart_id'],
								$tax_total
							);
					}
				}
			} else {
				$this->handle_error(
					sprintf(
						/* translators: error message from TaxCloud API response. */
						__( 'Failed to calculate sales tax: %s', 'simple-sales-tax' ),
						$response->get_error_message()
					)
				);

				return false;
			}
		}

		// Update tax totals.
		$this->update_taxes();

		return true;
	}

	/**
	 * Perform a tax lookup for each cart shipping package.
	 *
	 * @return array Array of packages.
	 * @since 5.0
	 */
	protected function do_lookup() {
		$packages = array();
		$data_mover = SST_Settings::get( 'data_mover' );

		foreach ( $this->create_packages() as $package ) {
			if ( ! $this->should_do_lookup( $package ) ) {
				continue;
			}

			if ( ! $this->is_destination_valid( $package ) ) {
				// Wait for a complete destination address.
				SST_Logger::add( __( 'Tax lookup failed: Shipping destination address is invalid.', 'simple-sales-tax' ), $package['destination'] );
				continue;
			}

			if ( ! $this->is_origin_valid( $package ) ) {
				SST_Logger::add(
					__(
						'Failed to calculate sales tax: Shipping origin address is invalid.',
						'simple-sales-tax'
					),
					$package
				);
				continue;
			}

			$package['request'] = $this->get_lookup_for_package( $package );

			$hash          		= $this->get_package_hash( $package );
			$saved_package 		= $this->get_saved_package( $hash );
			$force_tax_lookup	= SST_Settings::get( 'force_tax_lookup' );

			if ( 'yes' === $force_tax_lookup || false === $saved_package ) {
				$saved_package = $this->compress_package_data(
					$this->do_package_lookup( $package )
				);

				if ( $saved_package ) {
					$this->save_package( $hash, $saved_package );
				}
			} else {
				SST_Logger::debug( __( 'Tax lookup skipped: Package already saved.', 'simple-sales-tax' ), $saved_package );
			}

			if ( $saved_package ) {
				$packages[] = $saved_package;
			}
		}

		if ( apply_filters( 'wootax_save_packages_for_capture', true ) ) {
			$this->set_packages( $packages );
		}

		return $packages;
	}

	/**
	 * Perform a tax lookup for a shipping package.
	 *
	 * @param array $package Package to perform tax lookup for.
	 *
	 * @return array Updated package.
	 */
	protected function do_package_lookup( $package ) {
		// Skip lookup if real-time tax calculation is disabled. [Data Import Mode]
		if ( 'yes' === SST_Settings::get( 'disable_real_time_calc' )) {
			SST_Logger::add( __( 'Real-time tax calculation is disabled. Skipping lookup.', 'simple-sales-tax' ) );
			return $package;
		}

		// Check rate limit.
		$rate_limit = new SST_Rate_Limit();
		if ( $rate_limit->limit_reached() ) {
			$rate_limit->log_limit_reached();
			return $package;
		}

		try {
			$package['response'] = TaxCloud()->Lookup( $package['request'] );
			$package['cart_id']  = key( $package['response'] );

			$rate_limit->increment_count();

			SST_Logger::debug( __( 'Tax lookup response:', 'simple-sales-tax' ), $package );
		} catch ( Exception $ex ) {
			$package['response'] = new WP_Error( 'lookup_error', $ex->getMessage() );
			// Logging.
			SST_Logger::debug( __( 'Tax lookup failed. Exception:', 'simple-sales-tax' ), $ex );
		}

		return $package;
	}

	/**
	 * Generate a Lookup request for a given package.
	 *
	 * @param array $package Package to construct Lookup request for.
	 *
	 * @return TaxCloud\Request\Lookup
	 * @since 5.0
	 */
	protected function get_lookup_for_package( &$package ) {
		$cart_items = array();
		$based_on   = SST_Settings::get( 'tax_based_on' );
		// V3: Data Mover Mode.
		$data_mover = SST_Settings::get( 'data_mover', false );
		$v3_data    = array();

		/* Add products */
		foreach ( $package['contents'] as $cart_id => $item ) {

			$line_total       = $item['line_total'];
			$discounted_price = round( $line_total / $item['quantity'], wc_get_price_decimals() );

			/* Set quantity and price according to 'Tax Based On' setting. */
			if ( 'line-subtotal' === $based_on ) {
				$quantity = 1;
				$price    = $line_total;
			} else {
				$quantity = $item['quantity'];
				$price    = $discounted_price;
			}

			/* Give devs a chance to change the taxable product price. */
			$price = apply_filters( 'wootax_product_price', $price, $item['data'], $item );

			$cart_items[]     = new TaxCloud\CartItem(
				count( $cart_items ),
				$item['variation_id'] ? $item['variation_id'] : $item['product_id'],
				SST_Product::get_tic( $item['product_id'], $item['variation_id'] ),
				$price,
				$quantity
			);

			// V3: Data Mover Mode.
			if( $data_mover ) {
				// Calculate tax rate
				$tax_rate = $item['line_tax'] / $price;

				$v3_data = new TaxCloud_V3\Model\CartItem( array(
					'index' => count( $cart_items ),
					'itemId' => $item['variation_id'] ? $item['variation_id'] : $item['product_id'],
					'price' => $price,
					'quantity' => $quantity,
					'tax' => array(
						'amount' => number_format( $item['line_tax'], 2 ),
						'rate' => number_format( $tax_rate, 2 )
					),
					'tic' => SST_Product::get_tic( $item['product_id'], $item['variation_id'] ),
				) );
			}

			$package['map'][] = array(
				'type'    => 'line_item',
				'id'      => $item['data']->get_id(),
				'cart_id' => isset( $item['shipping_item_key'] ) ? $item['shipping_item_key'] : $item['key'],
				'v3_data' => $v3_data
			);
		}

		/* Add fees */
		foreach ( $package['fees'] as $cart_id => $fee ) {
			$cart_items[]     = new TaxCloud\CartItem(
				count( $cart_items ),
				$fee->id,
				apply_filters( 'wootax_fee_tic', SST_DEFAULT_FEE_TIC, $fee ),
				apply_filters( 'wootax_fee_price', $fee->amount, $fee ),
				1
			);

			// V3: Data Mover Mode.
			if( $data_mover ) {
				$v3_data = new TaxCloud_V3\Model\CartItem( array(
					'index' => count( $cart_items ),
					'itemId' => $fee->id,
					'price' => apply_filters( 'wootax_fee_price', $fee->amount, $fee ),
					'quantity' => 1,
					'tax' => array(
						'amount' => 0,
						'rate' => 0
					),
					'tic' => apply_filters( 'wootax_fee_tic', SST_DEFAULT_FEE_TIC ),
				) );
			}

			$package['map'][] = array(
				'type'    => 'fee',
				'id'      => $fee->id,
				'cart_id' => $cart_id,
				'v3_data' => $v3_data
			);
		}

		/* Add shipping */
		$shipping_rate  = $package['shipping'];
		$local_delivery = false;

		if ( ! is_null( $shipping_rate ) ) {
			$local_delivery = SST_Shipping::is_local_delivery( $shipping_rate->method_id );

			$cart_items[]     = new TaxCloud\CartItem(
				count( $cart_items ),
				SST_SHIPPING_ITEM,
				sst_get_shipping_tic( $shipping_rate->method_id ),
				apply_filters( 'wootax_shipping_price', $shipping_rate->cost, $shipping_rate ),
				1
			);

			// V3: Data Mover Mode.
			if( $data_mover ) {
				$v3_data = new TaxCloud_V3\Model\CartItem( array(
					'index' => count( $cart_items ),
					'itemId' => SST_SHIPPING_ITEM,
					'price' => apply_filters( 'wootax_shipping_price', $shipping_rate->cost, $shipping_rate ),
					'quantity' => 1,
					'tax' => array(
						'amount' => 0,
						'rate' => 0
					),
					'tic' => sst_get_shipping_tic( $shipping_rate->method_id ),
				) );
			}

			$package['map'][] = array(
				'type'    => 'shipping',
				'id'      => SST_SHIPPING_ITEM,
				'cart_id' => $shipping_rate->id,
				'v3_data' => $v3_data
			);
		}

		/* Build Lookup */
		if( $data_mover ) {
			/**
			 * V3: Data Mover Mode - Prepare request as array.
			 * @since 8.4.1
			 */
			$request = array(
				'customerId' => $package['user']['ID'],
				'cartItems' => $cart_items,
				'origin' => $package['origin'],
				'destination' => $package['destination'],
				'localDelivery' => $local_delivery,
				'certificate' => $package['certificate']
			);
		} else {
			$request = new TaxCloud\Request\Lookup(
					$this->api_id,
					$this->api_key,
					$package['user']['ID'],
					null,
					$cart_items,
					$package['origin'],
					$package['destination'],
					$local_delivery,
					$package['certificate']
			);
		}

		return $request;
	}

	/**
	 * Split package into one or more subpackages, one for each product
	 * origin address.
	 *
	 * @todo This method is doing too much and should be split into smaller methods.
	 *
	 * @param array $package Package.
	 *
	 * @return array Subpackages (empty on error).
	 * @since 5.0
	 */
	protected function split_package( $package ) {
		$packages = array();

		// Convert destination address to Address object.
		try {
			$destination = new TaxCloud\Address(
				isset( $package['destination']['address_1'] ) ? $package['destination']['address_1'] : $package['destination']['address'],
				$package['destination']['address_2'],
				$package['destination']['city'],
				$package['destination']['state'],
				substr( $package['destination']['postcode'], 0, 5 )
			);
			SST_Logger::add( __( 'Shipping destination verifying', 'simple-sales-tax' ), $destination );
			// TODO: substr sometimes include trailing dash, e.g. 12345-
			$package['destination'] = SST_Addresses::verify_address( $destination );
		} catch ( Exception $ex ) {
			SST_Logger::add( __( 'Shipping destination is not a verified address.', 'simple-sales-tax' ), $ex );
			return array();
		}

		// If the 'origin' key is already set, we can assume that some upstream
		// code (e.g. a marketplace integration) already split the packages by
		// origin address. In this case, we just make sure all required keys are
		// set and return the package as-is.
		if ( isset( $package['origin'] ) && $package['origin'] instanceof SST_Origin_Address ) {
			$origin_id              = $package['origin']->getID();
			$packages[ $origin_id ] = $this->format_package( $package );

			return $packages;
		}

		// Split package into subpackages.
		foreach ( $package['contents'] as $cart_key => $item ) {
			$origin = $this->get_origin_for_product( $item, $package['destination'] );

			if ( ! $origin ) {
				$this->handle_error(
					sprintf(
						/* translators: WooCommerce product ID. */
						__( 'Failed to calculate sales tax: no origin address for product %d.', 'simple-sales-tax' ),
						$item['product_id']
					)
				);

				return array();
			}

			$origin_id = $origin->getID();

			// Create subpackage for origin if need be and associate any
			// shipping charges with the first subpackage.
			if ( ! array_key_exists( $origin_id, $packages ) ) {
				$subpackage = array(
					'origin'      => $origin,
					'destination' => $package['destination'],
				);

				if ( isset( $package['shipping'] ) ) {
					$subpackage['shipping'] = $package['shipping'];
					unset( $package['shipping'] );
				}

				$packages[ $origin_id ] = $this->format_package( $subpackage );
			}

			// Add item to package.
			$packages[ $origin_id ]['contents'][ $cart_key ] = $item;
		}

		return array_values( $packages );
	}

	/**
	 * Formats a SST cart package, ensuring that all required fields are set
	 * and have the correct type.
	 *
	 * @param array $package Cart package.
	 *
	 * @return array
	 */
	protected function format_package( $package ) {
		if ( ! isset( $package['origin'] ) ) {
			$package['origin'] = SST_Addresses::get_default_address();
		} elseif ( ! ( $package['origin'] instanceof SST_Origin_Address ) ) {
			SST_Logger::add(
				__( 'Origin address for shipping package is invalid. Using default origin address from TaxCloud for WooCommerce settings.', 'simple-sales-tax' )
			);
			$package['origin'] = SST_Addresses::get_default_address();
		}

		$package['origin'] = SST_Addresses::to_address( $package['origin'] );

		if ( ! isset( $package['certificate'] ) ) {
			$package['certificate'] = $this->get_certificate();
		}

		if ( ! isset( $package['user'] ) ) {
			$package['user'] = array( 'ID' => get_current_user_id() );
		}

		return sst_create_package( $package );
	}

	/**
	 * Get the hash of a shipping package.
	 *
	 * @param array $package WooCommerce cart shipping package.
	 *
	 * @return string
	 * @since 5.0
	 */
	private function get_package_hash( $package ) {
		$package  = $this->compress_package_data( $package );
		$version  = WC_Cache_Helper::get_transient_version( 'shipping' );
		$md5_hash = md5( wp_json_encode( $package ) . $version );

		return "sst_pack_{$md5_hash}";
	}

	/**
	 * Get the origin address to use for a given product.
	 *
	 * By default, we use the following procedure to determine the address
	 * to use:
	 *
	 *  1) If there is only one shipment origin for the product, use it.
	 *  2) If there are multiple shipment origins, use one in the customer's state.
	 *  3) If there are no origins in the customers state, use the first  origin.
	 *
	 * @param array            $item        Associative array with details about product.
	 * @param TaxCloud\Address $destination Shipping destination address.
	 *
	 * @return SST_Origin_Address
	 * @since 5.0
	 */
	protected function get_origin_for_product( $item, $destination ) {
		$origins = SST_Product::get_origin_addresses( $item['product_id'] );
		$origin  = null;

		if ( ! empty( $origins ) ) {
			$origin = current( $origins );

			foreach ( $origins as $candidate ) {
				if ( $candidate->getState() === $destination->getState() ) {
					$origin = $candidate;
					break;
				}
			}
		}

		return apply_filters( 'wootax_origin_address', $origin, $item, $destination );
	}

	/**
	 * Is the package origin address valid?
	 *
	 * @param array $package WooCommerce shipping package.
	 *
	 * @return bool
	 * @since 7.0.2
	 */
	protected function is_origin_valid( $package ) {
		// Debug: Check if valid origin
		SST_Logger::add(
			__(
				'Checking package origin address validity',
				'simple-sales-tax'
			),
			array(
				'isset' => isset( $package['origin'] ),
				'type'  => is_a( $package['origin'], 'TaxCloud\Address' ),
				'instanceof' => isset( $package['origin'] ) && $package['origin'] instanceof TaxCloud\Address,
			)
		);

		// Return
		return (
			isset( $package['origin'] ) &&
			$package['origin'] instanceof TaxCloud\Address
		);
	}

	/**
	 * Is the package destination address valid?
	 *
	 * @param array $package WooCommerce shipping package.
	 *
	 * @return bool
	 * @since 7.0.2
	 */
	protected function is_destination_valid( $package ) {
		return (
			isset( $package['destination'] ) &&
			SST_Addresses::is_valid( $package['destination'] )
		);
	}

	/**
	 * Can we perform a lookup for the given package?
	 *
	 * @param array $package WooCommerce shipping package.
	 *
	 * @return bool
	 * @since 7.0.2
	 */
	protected function should_do_lookup( $package ) {
		return true;
	}

	/**
	 * Checks whether a cart package has a valid destination for TaxCloud
	 * lookup requests.
	 *
	 * @param array $package Cart package.
	 *
	 * @return bool True if cart package destination is valid, else false.
	 * @since 6.2.2
	 */
	public function is_package_destination_valid( $package ) {
		$valid = true;

		if ( ! isset( $package['destination'], $package['destination']['country'] ) ) {
			$valid = false;
		} else {
			if ( 'US' !== $package['destination']['country'] ) {
				$valid = false;
			}
		}

		return apply_filters( 'sst_is_package_destination_valid', $valid, $package );
	}

	/**
	 * Prepares a cart package for saving to the session or the database.
	 * Strips out unnecessary data to reduce space usage.
	 *
	 * @todo Rewrite lookup code to use compressed data format so this is not necessary.
	 *
	 * @param array $package Package data.
	 *
	 * @return array Compressed package data.
	 */
	public function compress_package_data( $package ) {
		// V3: Data Mover Mode.
		$data_mover = SST_Settings::get( 'data_mover', false );
		if ( $data_mover ) {
			return ( new TaxCloud_V3\Model\CompressedPackage( $package ) )->get_package();
		}

		$already_compressed = isset( $package['cart_items'] );

		if ( $already_compressed ) {
			return $package;
		}

		// Set new keys with compressed data.
		$package['customer_id']         = $package['user']['ID'];
		$package['cart_items']          = $this->get_package_cart_items( $package );
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

		return $package;
	}

	/**
	 * Get the cart items for a given package.
	 *
	 * @param array $package Package data.
	 *
	 * @return array Cart items.
	 * @since 7.0.0
	 */
	protected function get_package_cart_items( $package ) {
		$map        = $package['map'];
		$cart_items = array();

		foreach ( $package['request']->getCartItems() as $key => $item ) {
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

	/**
	 * Get saved packages for this cart.
	 *
	 * @return array
	 * @since 5.0
	 */
	abstract protected function get_packages();

	/**
	 * Set saved packages for this cart.
	 *
	 * @param array $packages Packages to save (default: array()).
	 *
	 * @since 5.0
	 */
	abstract protected function set_packages( $packages = array() );

	/**
	 * Get the base packages for the cart. The base packages are split by origin
	 * and otherwise processed to generate the packages sent to TaxCloud.
	 *
	 * The packages returned by this method must satisfy the following criteria.
	 *
	 * Completeness: The keys 'contents', 'user', 'shipping', and 'destination'
	 * must be defined.
	 *
	 * Inclusiveness: Every item in the cart must be included in exactly one
	 * package.
	 *
	 * @return array
	 * @since 5.5
	 */
	abstract protected function get_base_packages();

	/**
	 * Create shipping packages for this cart.
	 *
	 * Every package should have the following structure:
	 *
	 *  array(
	 *      'contents'      => array(
	 *          ...
	 *          array(
	 *              'variation_id' => 123,
	 *              'product_id'   => 456,
	 *              'quantity'     => 1,
	 *              'data'         => WC_Product,
	 *          )
	 *          ...
	 *      ),
	 *      'fees'          => array(
	 *          ...
	 *          object(
	 *              'id'     => 123,
	 *              'amount' => 2.50,
	 *          )
	 *          ...
	 *      ),
	 *      'shipping'      => array(
	 *          'method_id' => 'local_delivery',
	 *          'cost'      => 9.99,
	 *      ),
	 *      'map'           => array(
	 *          ...
	 *          array(
	 *              'type'    => 'cart'|'shipping'|'fee',
	 *              'id'      => 456,
	 *              'cart_id' => 'abc',
	 *          )
	 *          ...
	 *      ),
	 *      'user'          => array(
	 *          'ID' => 1,
	 *      ),
	 *      'request'       => null,
	 *      'response'      => null,
	 *      'origin'        => TaxCloud\Address,
	 *      'destination'   => TaxCloud\Address,
	 *      'certificate'   => TaxCloud\ExemptCertificate
	 *  )
	 *
	 * @return array
	 * @since 5.0
	 */
	abstract protected function create_packages();

	/**
	 * Gets a saved package by its package hash.
	 *
	 * @param string $hash Package hash.
	 *
	 * @return array|bool The saved package with the given hash, or false if no such package exists.
	 */
	abstract protected function get_saved_package( $hash );

	/**
	 * Saves a package.
	 *
	 * @param string $hash    Package hash.
	 * @param array  $package Package to save.
	 */
	abstract protected function save_package( $hash, $package );

	/**
	 * Reset sales tax totals.
	 *
	 * @since 5.0
	 */
	abstract protected function reset_taxes();

	/**
	 * Update sales tax totals.
	 *
	 * @since 5.0
	 */
	abstract protected function update_taxes();

	/**
	 * Set the tax for a product.
	 *
	 * @param mixed $id  Product ID.
	 * @param float $tax Sales tax for product.
	 *
	 * @since 5.0
	 */
	abstract protected function set_product_tax( $id, $tax );

	/**
	 * Set the tax for a shipping item.
	 *
	 * @param mixed $id  Item ID.
	 * @param float $tax Sales tax for item.
	 *
	 * @since 5.0
	 */
	abstract protected function set_shipping_tax( $id, $tax );

	/**
	 * Set the tax for a fee.
	 *
	 * @param mixed $id  Fee ID.
	 * @param float $tax Sales tax for fee.
	 *
	 * @since 5.0
	 */
	abstract protected function set_fee_tax( $id, $tax );

	/**
	 * Get the exemption certificate for the customer.
	 *
	 * @return TaxCloud\ExemptionCertificateBase
	 * @since 7.0.0
	 */
	abstract public function get_certificate();

	/**
	 * Handle an error by logging it or displaying it to the user.
	 *
	 * @param string $message Message describing the error.
	 *
	 * @since 5.0
	 */
	abstract protected function handle_error( $message );

}
