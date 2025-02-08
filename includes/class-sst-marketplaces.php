<?php
/**
 * SST Marketplaces.
 *
 * Handles integration with marketplace plugins like Dokan and WCFM.
 *
 * @package simple-sales-tax
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SST_Marketplaces.
 *
 * @package simple-sales-tax
 */
class SST_Marketplaces {

	/**
	 * Whether a marketplace integration was loaded.
	 *
	 * @var bool
	 */
	protected $integration_loaded = false;

	/**
     * Flag to indicate whether SST should split the order by seller ID
     * before calculating taxes. Should be true whenever the marketplace
     * plugin would normally split the WooCommerce cart packages.
     *
     * @var bool
     */
    protected $should_split_packages = false;

	/**
	 * Singleton instance.
	 *
	 * @var SST_Marketplaces
	 */
	protected static $_instance = null;

	/**
	 * Singleton instance accessor.
	 *
	 * @return SST_Marketplaces
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * SST_Marketplaces constructor.
	 */
	protected function __construct() {
		$this->load_integration();

		// todo: warn if giving tax to vendors?
		if ( $this->integration_loaded ) {
			// Hide the origin address dropdown. We always use the vendor's
			// address as the origin address in the marketplace setting.
			add_filter( 'sst_show_origin_address_dropdown', '__return_false' );
			add_filter( 'sst_settings_form_fields', array( $this, 'change_origin_addresses_description' ) );
		}
	}

	/**
	 * Loads the appropriate marketplace integration based on the active
	 * plugins.
	 */
	protected function load_integration() {
		$integrations_dir = __DIR__ . '/integrations';

		// WC Vendors Pro.
		if ( defined( 'WCV_PRO_VERSION' ) ) {
			require_once $integrations_dir . '/class-sst-wc-vendors.php';
			$this->integration_loaded = true;
		}

		// Dokan.
		if ( defined( 'DOKAN_PLUGIN_VERSION' ) ) {
			require_once $integrations_dir . '/class-sst-dokan.php';
			$this->integration_loaded = true;
		}

		// WooCommerce Frontend Manager.
		if ( defined( 'WCFM_VERSION' ) ) {
			require_once $integrations_dir . '/class-sst-wcfm.php';
			$this->integration_loaded = true;
		}

		// WC Marketplace.
		if ( defined( 'WCMp_PLUGIN_VERSION' ) ) {
			require_once $integrations_dir . '/class-sst-wcmp.php';
			$this->integration_loaded = true;
		}
	}

	/**
	 * Changes the description for the Shipping Origin Addresses field when a
	 * marketplace integration is active to reflect that the addresses will
	 * only be used as a fallback when a vendor's address isn't known.
	 *
	 * @param array $fields Fields for SST settings form.
	 *
	 * @return array
	 */
	public function change_origin_addresses_description( $fields ) {
		if ( ! isset( $fields['default_origin_addresses'] ) ) {
			return $fields;
		}

		$fields['default_origin_addresses']['description'] = __(
			"Select the addresses you ship your products from. These will be used when calculating tax for your products and for vendors who haven't provided an address yet.",
			'simple-sales-tax'
		);

		return $fields;
	}

}

SST_Marketplaces::instance();
