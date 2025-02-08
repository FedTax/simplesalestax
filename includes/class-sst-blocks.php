<?php
/**
 * Blocks.
 *
 * Registers Simple Sales Tax blocks.
 *
 * @package simple-sales-tax
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use \Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema;

/**
 * Class SST_Blocks.
 *
 * @package simple-sales-tax
 * @since 8.1
 */
class SST_Blocks {

	/**
	 * Singleton instance.
	 *
	 * @var SST_Blocks
	 */
	protected static $_instance = null;

	/**
	 * Singleton instance accessor.
	 *
	 * @return SST_Blocks
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * SST_Blocks constructor.
	 */
	protected function __construct() {
		add_action(
			'woocommerce_blocks_loaded',
			array( $this, 'register_blocks' )
		);
		add_action(
			'woocommerce_blocks_loaded',
			array( $this, 'register_update_callback' )
		);
		add_action(
			'woocommerce_blocks_loaded',
			array( $this, 'register_checkout_endpoint_data' )
		);
	}

	/**
	 * Registers all SST blocks.
	 */
	public function register_blocks() {
		require_once __DIR__ . '/class-sst-blocks-integration.php';

		add_action(
			'woocommerce_blocks_checkout_block_registration',
			function( $integration_registry ) {
				$integration_registry->register( new SST_Blocks_Integration() );
			}
		);
	}

	/**
	 * Register Store API update callback.
	 */
	public function register_update_callback() {
		woocommerce_store_api_register_update_callback(
			array(
				'namespace' => 'simple-sales-tax',
				'callback'  => array( $this, 'handle_cart_update' ),
			)
		);
	}

	/**
	 * Handle cart updates triggered by `extensionCartUpdate`.
	 *
	 * @param array $data Data payload from `extensionCartUpdate`
	 */
	public function handle_cart_update( $data ) {
		$action = $data['action'] ?? '';

		switch ( $action ) {
			case 'set_certificate_id':
				WC()->session->set(
					'sst_certificate_id',
					$data['certificate_id']
				);
				break;
		}
	}

	/**
	 * Get schema for checkout endpoint extension data
	 *
	 * @return array
	 */
	public function get_checkout_schema() {
		return array(
			'certificate_id' => array(
				'type' => 'string',
			),
			'certificate'    => array(
				'SinglePurchase'                     => array(
					'type' => 'boolean',
				),
				'ExemptState'                        => array(
					'type' => 'string',
				),
				'TaxType'                            => array(
					'type' => 'string',
				),
				'StateOfIssue'                       => array(
					'type' => 'string',
				),
				'IDNumber'                           => array(
					'type' => 'string',
				),
				'PurchaserBusinessType'              => array(
					'type' => 'string',
				),
				'PurchaserBusinessTypeOtherValue'    => array(
					'type' => 'string',
				),
				'PurchaserExemptionReason'           => array(
					'type' => 'string',
				),
				'PurchaserExemptionReasonOtherValue' => array(
					'type' => 'string',
				),
			),
		);
	}

	/**
	 * Register custom extension data for checkout endpoint.
	 */
	public function register_checkout_endpoint_data() {
		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => CheckoutSchema::IDENTIFIER,
				'namespace'       => 'simple-sales-tax',
				'data_callback'   => null,
				'schema_callback' => array( $this, 'get_checkout_schema' ),
				'schema_type'     => ARRAY_A,
			)
		);
	}

}

SST_Blocks::instance();
