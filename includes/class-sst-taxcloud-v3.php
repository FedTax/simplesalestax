<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TaxCloud v3 Client.
 *
 * Handles the data v3 settings.
 *
 * @author  Simple Sales Tax
 * @package SST
 * @since   8.4.1
 */
class SST_TaxCloud_V3 {

	/**
	 * Singleton instance.
	 *
	 * @var SST_TaxCloud_V3
	 * @since 8.4.1
	 */
	protected static $_instance = null;

	/**
	 * Singleton instance accessor.
	 *
	 * @return SST_TaxCloud_V3 Singleton instance.
	 * @since 8.4.1
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Constructor.
	 *
	 * @since 8.4.1
	 */
	protected function __construct() {
		add_action( 'sst_update_data_mover_settings', array( 'SST_TaxCloud_V3_API', 'update_data_mover_settings' ) );
		add_filter( 'sst_get_option', array( $this, 'update_show_tax_option' ), 10, 2 );
	}

	/**
	 * Update the show_zero_tax option if disable_integration is true.
	 *
	 * @param string $value Value of the option.
	 * @param string $key   Key of the option.
	 *
	 * @return string Updated value.
	 * @since 8.4.1
	 */
	public function update_show_tax_option( $value, $key ) {
		if ( 'show_zero_tax' === $key ) {
			$disable_integration = SST_Settings::get( 'disable_integration', 'no' );
			if( 'yes' === $disable_integration ) {
				return 'no';
			}
		} elseif ( 'order_show_zero_tax' === $key ) {
			$disable_integration = SST_Settings::get( 'disable_integration', 'no' );
			if( 'yes' === $disable_integration ) {
				return 'no';
			}
		}
		return $value;
	}

}

// Initialize the instance.
SST_TaxCloud_V3::instance();