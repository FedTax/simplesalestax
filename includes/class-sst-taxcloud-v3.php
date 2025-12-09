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
 * @since   8.4.0
 */
class SST_TaxCloud_V3 {

	/**
	 * Singleton instance.
	 *
	 * @var SST_TaxCloud_V3
	 */
	protected static $_instance = null;

	/**
	 * Singleton instance accessor.
	 *
	 * @return SST_TaxCloud_V3
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * SST_TaxCloud_V3 constructor.
	 */
	protected function __construct() {
		add_action( 'sst_update_data_mover_settings', array( 'SST_TaxCloud_V3_API', 'update_data_mover_settings' ) );
		// add_filter( 'sst_get_option', array( $this, 'update_realtime_calc_option' ), 10, 2 );
		// add_filter( 'sst_settings_form_fields', array( $this, 'disable_real_time_calc_option' ), 10, 2 );
	}

	/**
	 * Disable the disable_real_time_calc option if data mover is true.
	 *
	 * @param array $fields Array of settings fields.
	 *
	 * @return array
	 */
	public function disable_real_time_calc_option( $fields, $settings ) {
		$data_mover = isset( $settings['data_mover'] ) ? (bool) $settings['data_mover'] : false;
		if( $data_mover ) {
			$fields['disable_real_time_calc']['disabled'] =  $data_mover;
			$fields['disable_real_time_calc']['options'] = array(
				'yes' => __( 'Yes', 'simple-sales-tax' ),
			);
		}
		return $fields;
	}


	/**
	 * Update the disable_real_time_calc option if data mover is true.
	 *
	 * @param string $value Value of the option.
	 * @param string $key   Key of the option.
	 *
	 * @return string
	 */
	public function update_realtime_calc_option( $value, $key ) {
		if( 'disable_real_time_calc' === $key ) {
			$data_mover = SST_Settings::get( 'data_mover', false );
			if( $data_mover ) {
				return 'yes';
			}
		}
		return $value;
	}

}

// Initialize the instance.
SST_TaxCloud_V3::instance();