<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

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

		// Integration disabled?
		if ( 'yes' === SST_Settings::get( 'disable_integration' )) {
			SST_Logger::add( __( 'Integration disabled. Skipping tax calculation.', 'simple-sales-tax' ) );
			return false;
		}

		$this->reset_taxes();

		// No API Login ID or API Key? Bail.
		if ( empty( $this->api_id ) || empty( $this->api_key ) ) {
			SST_Logger::add( __( 'API Login ID or API Key is empty. Skipping lookup.', 'simple-sales-tax' ) );
			return false;
		}

		// Perform tax lookup(s).
		foreach ( $this->do_lookup() as $package ) {

			// Real-time tax calculation is disabled?.
			if ( 'data_mover' === sst_integration_mode() ) {
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

		foreach ( $this->create_packages() as $key => $package ) {
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

			$hash          		= $this->get_package_hash( $package );
			$saved_package 		= $this->get_saved_package( $hash );
			$force_tax_lookup	= SST_Settings::get( 'force_tax_lookup' );

			if ( 'yes' === $force_tax_lookup || false === $saved_package ) {
				$saved_package = $this->compress_package_data(
					$this->do_package_lookup( $package, $key )
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
	 * @param mixed $key     Package key.
	 *
	 * @return array Updated package.
	 */
	protected function do_package_lookup( $package, $key = null ) {
		// Skip lookup if real-time tax calculation is disabled. [Data Import Mode]
		if ( 'data_mover' === sst_integration_mode() ) {
			SST_Logger::add( __( 'Real-time tax calculation is disabled. Skipping lookup.', 'simple-sales-tax' ) );
			return $package;
		}

		// Check rate limit.
		$rate_limit = new SST_Rate_Limit();
		if ( $rate_limit->limit_reached() ) {
			$rate_limit->log_limit_reached();
			return $package;
		}

		// If V3
		if ( 'v3' === sst_get_api_version() ) {
			$package = $this->do_v3_package_lookup( $package, $key, $rate_limit );
			return $package;
		}

		try {
			$package['response'] = TaxCloud()->Lookup( $package['request'] );

			if ( ! is_array( $package['response'] ) || empty( $package['response'] ) ) {
				throw new UnexpectedValueException(
					__( 'TaxCloud returned an invalid lookup response.', 'simple-sales-tax' )
				);
			}

			$package['cart_id']  = key( $package['response'] );

			$rate_limit->increment_count();

			SST_Logger::debug( __( 'Tax lookup response:', 'simple-sales-tax' ), $package );
		} catch ( Throwable $ex ) {
			$package['response'] = new WP_Error( 'lookup_error', $ex->getMessage() );
			// Logging.
			SST_Logger::debug( __( 'Tax lookup failed. Exception:', 'simple-sales-tax' ), $ex );
		}

		return $package;
	}

	/**
	 * Perform a V3 tax lookup for a shipping package.
	 *
	 * @param array          $package    Package to perform tax lookup for.
	 * @param mixed          $key        Package key.
	 * @param SST_Rate_Limit $rate_limit Rate limit object.
	 *
	 * @return array Updated package.
	 * @since 8.4.7
	 */
	protected function do_v3_package_lookup( $package, $key, $rate_limit ) {
		$carts_api = new TaxCloud_V3\Carts();
		$request   = $this->get_v3_lookup_for_package( $package, $key );

		try {
			$response = $carts_api->calculate_tax( $request );

			if ( is_wp_error( $response ) || ! is_array( $response ) || empty( $response ) ) {
				throw new UnexpectedValueException(
					__( 'TaxCloud returned an invalid lookup response.', 'simple-sales-tax' )
				);
			}

			$package['response'] = $this->prepare_v3_response( $response );
			$package['cart_id']  = key( $package['response'] );
			$rate_limit->increment_count();

			SST_Logger::debug( __( 'TaxCloud V3 lookup response:', 'simple-sales-tax' ), $package );
		} catch ( Throwable $ex ) {
			$package['response'] = new WP_Error( 'lookup_error', $ex->getMessage() );
			// Logging.
			SST_Logger::debug( __( 'TaxCloud V3 lookup failed. Exception:', 'simple-sales-tax' ), $ex );
		}

		return $package;
	}

	/**
	 * Generate a V3 Lookup request for a given package.
	 *
	 * @param array $package Package to construct Lookup request for.
	 * @param mixed $key     Package key.
	 *
	 * @return array
	 * @since 8.4.7
	 */
	protected function get_v3_lookup_for_package( $package, $key ) {
		$cart_id  = $this->get_package_order_id( $key, $package );
		$items    = array();
		$index    = 0;
		$based_on = SST_Settings::get( 'tax_based_on' );

		foreach ( $package['contents'] as $item ) {
			$line_total = $item['line_total'];
			$qty        = $item['quantity'];

			/* Mirror the tax_based_on logic from the v1 lookup path. */
			if ( 'line-subtotal' === $based_on ) {
				$price    = $line_total;
				$quantity = 1;
			} else {
				/* Guard against division-by-zero (PHP 8 throws DivisionByZeroError). */
				$price    = $qty > 0 ? round( $line_total / $qty, wc_get_price_decimals() ) : 0.0;
				$quantity = $qty;
			}

			$price = apply_filters( 'wootax_product_price', $price, $item['data'], $item );

			$items[] = array(
				'index'    => $index++,
				'itemId'   => (string) ( $item['variation_id'] ? $item['variation_id'] : $item['product_id'] ),
				'price'    => (float) $price,
				'quantity' => (float) $quantity,
				'tic'      => (int) SST_Product::get_tic( $item['product_id'], $item['variation_id'] ),
			);
		}

		foreach ( $package['fees'] as $fee ) {
			$items[] = array(
				'index'    => $index++,
				'itemId'   => (string) $fee->id,
				'price'    => (float) apply_filters( 'wootax_fee_price', $fee->amount, $fee ),
				'quantity' => 1.0,
				'tic'      => (int) apply_filters( 'wootax_fee_tic', SST_DEFAULT_FEE_TIC, $fee ),
			);
		}

		$local_delivery = false;

		if ( ! is_null( $package['shipping'] ) ) {
			$local_delivery = SST_Shipping::is_local_delivery( $package['shipping']->method_id );

			$items[] = array(
				'index'    => $index++,
				'itemId'   => SST_SHIPPING_ITEM,
				'price'    => (float) apply_filters( 'wootax_shipping_price', $package['shipping']->cost, $package['shipping'] ),
				'quantity' => 1.0,
				'tic'      => (int) sst_get_shipping_tic( $package['shipping']->method_id ),
			);
		}

		$cart = array(
			'cartId'           => $cart_id,
			'customerId'       => 'customer-' . $package['user']['ID'],
			'currencyCode'     => get_woocommerce_currency(),
			'deliveredBySeller' => $local_delivery,
			'destination'      => array(
				'city'  => $package['destination']->getCity(),
				'line1' => $package['destination']->getAddress1(),
				'state' => $package['destination']->getState(),
				'zip'   => $package['destination']->getZip5(),
			),
			'origin'           => array(
				'city'  => $package['origin']->getCity(),
				'line1' => $package['origin']->getAddress1(),
				'state' => $package['origin']->getState(),
				'zip'   => $package['origin']->getZip5(),
			),
			'lineItems'        => $items,
		);

		if ( ! is_null( $package['certificate'] ) ) {
			$certificate    = $package['certificate'];
			$certificate_id = '';

			if ( is_object( $certificate ) && method_exists( $certificate, 'getCertificateID' ) ) {
				$certificate_id = $certificate->getCertificateID();
			} elseif ( is_object( $certificate ) && method_exists( $certificate, 'getCertificateId' ) ) {
				$certificate_id = $certificate->getCertificateId();
			} elseif ( is_array( $certificate ) && ! empty( $certificate['exemptionId'] ) ) {
				$certificate_id = $certificate['exemptionId'];
			}

			if ( empty( $certificate_id ) && is_object( $certificate ) && method_exists( $certificate, 'getDetail' ) ) {
				try {
					$user_id = isset( $package['user']['ID'] ) ? $package['user']['ID'] : 0;
					$certificate_id = SST_Certificates::add_certificate_object( $certificate, $user_id );

					if ( ! empty( $certificate_id ) && is_a( $this, 'SST_Order' ) ) {
						$this->set_single_purchase_certificate( $certificate );
						$this->save();
					}
				} catch ( Exception $ex ) {
					SST_Logger::add( 'Failed to register single-purchase certificate in V3: ' . $ex->getMessage() );
				}
			}

			if ( ! empty( $certificate_id ) ) {
				$cart['exemption'] = array(
					'exemptionId' => $certificate_id,
				);
			} else {
				SST_Logger::add( 'Skipping V3 exemption payload because certificate ID is empty.' );
			}
		}

		return array(
			'items' => array( $cart ),
		);
	}

	/**
	 * Prepare V3 response for use by calculate_taxes.
	 *
	 * @param array $response V3 API response.
	 *
	 * @return array
	 * @since 8.4.7
	 */
	protected function prepare_v3_response( $response ) {
		$formatted_response = array();

		if ( isset( $response['items'] ) ) {
			foreach ( $response['items'] as $cart ) {
				$tax_amounts = array();
				foreach ( $cart['lineItems'] as $item ) {
					$tax_amounts[ $item['index'] ] = $item['tax']['amount'];
				}
				$formatted_response[ $cart['cartId'] ] = $tax_amounts;
			}
		}

		return $formatted_response;
	}

	/**
	 * Get a unique ID for a package for use as a CartID in TaxCloud.
	 *
	 * Note: SST_Order overrides this method to return '{order_id}_{key}'
	 * instead of an MD5 hash of the package contents and destination.
	 * The cart-side lookup uses this MD5-based version, whereas the order-side
	 * capture uses the override. This works properly because $package['cart_id']
	 * is preserved through compress_package_data.
	 *
	 * @param mixed $key     Package key.
	 * @param array $package Package data.
	 * @return string ID.
	 * @since 8.4.7
	 */
	protected function get_package_order_id( $key, $package ) {
		$certificate_id = '';
		if ( isset( $package['certificate'] ) && is_object( $package['certificate'] ) && method_exists( $package['certificate'], 'getCertificateID' ) ) {
			$certificate_id = $package['certificate']->getCertificateID();

			if ( empty( $certificate_id ) && method_exists( $package['certificate'], 'getDetail' ) ) {
				$certificate_id = md5( wp_json_encode( $package['certificate']->getDetail() ) );
			}
		} else if ( isset( $package['certificate_id'] ) ) {
			$certificate_id = $package['certificate_id'];
		}

		$session_customer_id = '';
		if ( function_exists( 'WC' ) && WC()->session && method_exists( WC()->session, 'get_customer_id' ) ) {
			$session_customer_id = WC()->session->get_customer_id();
		}

		return md5(
			serialize( $package['contents'] ) .
			serialize( $package['destination'] ) .
			serialize( $key ) .
			serialize( isset( $package['user']['ID'] ) ? $package['user']['ID'] : 0 ) .
			serialize( $certificate_id ) .
			serialize( $session_customer_id )
		);
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
			$destination = new \TaxCloud_V3\Model\Address(
				array(
					'city'        => $package['destination']['city'],
					'countryCode' => isset( $package['destination']['country'] ) ? $package['destination']['country'] : 'US',
					'line1'       => isset( $package['destination']['address_1'] ) ? $package['destination']['address_1'] : $package['destination']['address'],
					'line2'       => isset( $package['destination']['address_2'] ) ? $package['destination']['address_2'] : '',
					'state'       => $package['destination']['state'],
					'zip'         => $package['destination']['postcode'],
				)
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
	 * @param array  $item        Associative array with details about product.
	 * @param object $destination Shipping destination address.
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
		$is_address_object = isset( $package['origin'] ) && SST_Addresses::is_address_object( $package['origin'] );

		// Debug: Check if valid origin
		SST_Logger::add(
			__(
				'Checking package origin address validity',
				'simple-sales-tax'
			),
			array(
				'isset'             => isset( $package['origin'] ),
				'type'              => isset( $package['origin'] ) && is_object( $package['origin'] ) ? get_class( $package['origin'] ) : null,
				'is_address_object' => $is_address_object,
			)
		);

		return $is_address_object;
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

		if ( isset( $package['origin'] ) && SST_Addresses::is_address_object( $package['origin'] ) ) {
			$package['origin_address'] = SST_Addresses::format(
				$package['origin']
			);
		}

		if ( isset( $package['destination'] ) && SST_Addresses::is_address_object( $package['destination'] ) ) {
			$package['destination_address'] = SST_Addresses::format(
				$package['destination']
			);
		}

		$certificate = $package['certificate'];

		if ( is_object( $certificate ) && method_exists( $certificate, 'getDetail' ) ) {
			// Single-purchase certificate without an ID. Use a stable hash of the certificate detail object as the ID.
			$detail       = $certificate->getDetail();
			$detail_array = json_decode( wp_json_encode( $detail ), true );
			unset( $detail_array['CreatedDate'] );
			$package['certificate_id'] = md5( wp_json_encode( $detail_array ) );
		} elseif ( is_object( $certificate ) && method_exists( $certificate, 'getCertificateId' ) ) {
			// Entity-based exemption certificate with ID
			$package['certificate_id'] = $certificate->getCertificateId();
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
		$cart_items = array();
		$based_on   = SST_Settings::get( 'tax_based_on' );

		foreach ( $package['contents'] as $item ) {
			$line_total = $item['line_total'];
			$qty        = $item['quantity'];

			if ( 'line-subtotal' === $based_on ) {
				$price    = $line_total;
				$quantity = 1;
			} else {
				$price    = $qty > 0 ? round( $line_total / $qty, wc_get_price_decimals() ) : 0.0;
				$quantity = $qty;
			}

			$price   = apply_filters( 'wootax_product_price', $price, $item['data'], $item );
			$item_id = (string) ( $item['variation_id'] ? $item['variation_id'] : $item['product_id'] );

			$cart_items[] = array(
				'type'    => 'line_item',
				'id'      => $item['data']->get_id(),
				'cart_id' => isset( $item['shipping_item_key'] ) ? $item['shipping_item_key'] : $item['key'],
				'itemId'  => $item_id,
				'qty'     => $quantity,
				'tic'     => (int) SST_Product::get_tic( $item['product_id'], $item['variation_id'] ),
				'price'   => (float) $price,
			);
		}

		foreach ( $package['fees'] as $cart_id => $fee ) {
			$cart_items[] = array(
				'type'    => 'fee',
				'id'      => $fee->id,
				'cart_id' => $cart_id,
				'itemId'  => (string) $fee->id,
				'qty'     => 1,
				'tic'     => (int) apply_filters( 'wootax_fee_tic', SST_DEFAULT_FEE_TIC, $fee ),
				'price'   => (float) apply_filters( 'wootax_fee_price', $fee->amount, $fee ),
			);
		}

		if ( ! empty( $package['shipping'] ) ) {
			$cart_items[] = array(
				'type'    => 'shipping',
				'id'      => SST_SHIPPING_ITEM,
				'cart_id' => $package['shipping']->id,
				'itemId'  => SST_SHIPPING_ITEM,
				'qty'     => 1,
				'tic'     => (int) sst_get_shipping_tic( $package['shipping']->method_id ),
				'price'   => (float) apply_filters( 'wootax_shipping_price', $package['shipping']->cost, $package['shipping'] ),
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
	 *      'origin'        => object,
	 *      'destination'   => object,
	 *      'certificate'   => object|null
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
	 * @return object|null
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

	/**
	 * Calculates the Colorado excise tax for a given set of items and destination state.
	 *
	 * @param array  $items List of cart/order items.
	 * @param string $state Destination state code.
	 * @return float Calculated excise tax.
	 */
	protected function calculate_excise_tax( $items, $state ) {
		$is_colorado_order = 'CO' === strtoupper( $state );

		if ( ! $is_colorado_order || current_time( 'Ymd' ) < 20250401 ) {
			return 0;
		}

		if ( ! is_null( $this->get_certificate() ) ) {
			return 0;
		}

		$excise_tax                = 0;
		$has_gun_or_ammo_tic_item  = false;

		foreach ( $items as $item ) {
			$product_id   = $item['product_id'] ?? 0;
			$variation_id = $item['variation_id'] ?? 0;

			if ( ! $product_id ) {
				continue;
			}

			$tic = SST_Product::get_tic( $product_id, $variation_id );

			if ( SST_Product::is_gun_or_ammo_tic( $tic ) ) {
				$has_gun_or_ammo_tic_item = true;
				$line_total               = $item['line_total'] ?? 0;
				$excise_tax              += $line_total * 0.065;
			}
		}

		if ( ! $has_gun_or_ammo_tic_item ) {
			return 0;
		}

		return $excise_tax;
	}

}
