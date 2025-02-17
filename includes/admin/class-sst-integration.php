<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Utilities\RestApiUtil;

/**
 * SST Integration.
 *
 * WooCommerce integration for Simple Sales Tax.
 *
 * @author  Simple Sales Tax
 * @package SST
 * @since   5.0
 */
class SST_Integration extends WC_Integration {

	/**
	 * Constructor. Initialize the integration.
	 *
	 * @since 4.5
	 */
	public function __construct() {
		$this->id                 = 'wootax';
		$this->method_title       = __( 'Simple Sales Tax', 'simple-sales-tax' );
		$this->method_description = __(
			'<p>Simple Sales Tax makes sales tax easy by connecting your store with <a href="https://www.taxcloud.com" target="_blank">TaxCloud</a>. If you have trouble with Simple Sales Tax, please consult the <a href="https://wordpress.org/plugins/simple-sales-tax/#faq-header" target="_blank">FAQ</a> and the <a href="https://wordpress.org/plugins/simple-sales-tax/#installation" target="_blank">Installation Guide</a> before contacting support.</p><p>Need help? <a href="https://taxcloud.com/contact-us/" target="_blank">Contact us</a>.</p>',
			'simple-sales-tax'
		);

		// Load the settings.
		$this->init_form_fields();

		// Register action hooks.
		add_action( 'woocommerce_update_options_integration_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_update_options_integration_' . $this->id, array( $this, 'refresh_origin_address_list' ), 15 );
		add_action( 'admin_init', array( $this, 'maybe_download_debug_report' ) );
		add_action( 'woocommerce_hide_sst_address_mismatch_notice', array( $this, 'maybe_dismiss_address_notice' ) );
	}

	/**
	 * Initialize form fields for integration settings.
	 *
	 * @since 4.5
	 */
	public function init_form_fields() {
		$this->form_fields = SST_Settings::get_form_fields();
	}

	/**
	 * Display admin options.
	 *
	 * @since 5.0
	 */
	public function admin_options() {
		wp_enqueue_script( 'sst-admin-js' );

		$this->display_errors();
		parent::admin_options();
	}

	/**
	 * Output HTML for field of type 'button.'
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field data.
	 *
	 * @since 4.5
	 */
	public function generate_button_html( $key, $data ) {
		$field = $this->plugin_id . $this->id . '_' . $key;

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field ); ?>">
					<?php echo wp_kses_post( $data['title'] ); ?><?php echo $this->get_tooltip_html( $data ); ?>
				</label>
			</th>
			<td class="forminp">
				<fieldset>
					<legend class="screen-reader-text">
						<span><?php echo wp_kses_post( $data['title'] ); ?></span>
					</legend>
					<button class="wp-core-ui button button-secondary" type="button" id="<?php echo esc_attr( $data['id'] ); ?>">
						<?php echo wp_kses_post( $data['label'] ); ?>
					</button>
					<?php echo $this->get_description_html( $data ); ?>
				</fieldset>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Output HTML for field of type 'anchor.'
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field data.
	 *
	 * @since 5.0
	 */
	public function generate_anchor_html( $key, $data ) {
		$field = $this->plugin_id . $this->id . '_' . $key;

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field ); ?>">
					<?php echo wp_kses_post( $data['title'] ); ?><?php echo $this->get_tooltip_html( $data ); ?>
				</label>
			</th>
			<td class="forminp">
				<fieldset>
					<legend class="screen-reader-text">
						<span><?php echo wp_kses_post( $data['title'] ); ?></span>
					</legend>
					<a href="<?php echo esc_url( $data['url'] ); ?>" target="_blank"
					   class="wp-core-ui button button-secondary" id="<?php echo esc_attr( $data['id'] ); ?>">
						<?php echo wp_kses_post( $data['label'] ); ?>
					</a>
					<?php echo $this->get_description_html( $data ); ?>
				</fieldset>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Output HTML for field of type 'origin_address_select'.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field data.
	 *
	 * @since 6.2
	 */
	public function generate_origin_address_select_html( $key, $data ) {
		$field            = "{$this->plugin_id}{$this->id}_{$key}";
		$origin_addresses = SST_Addresses::get_origin_addresses();
		$api_id           = SST_Settings::get( 'tc_id' );
		$api_key          = SST_Settings::get( 'tc_key' );

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field ); ?>">
					<?php echo wp_kses_post( $data['title'] ); ?><?php echo $this->get_tooltip_html( $data ); ?>
				</label>
			</th>
			<td class="forminp">
				<fieldset>
					<legend class="screen-reader-text">
						<span><?php echo wp_kses_post( $data['title'] ); ?></span>
					</legend>
					<?php if ( ! empty( $origin_addresses ) ): ?>
						<select id="<?php echo esc_attr( $field ); ?>" name="<?php echo esc_attr( $field ); ?>[]"
							    class="wc-enhanced-select origin-address-select" multiple="multiple"
							    data-placeholder="<?php esc_attr_e( 'Select origin addresses', 'simple-sales-tax' ); ?>">
							<?php
							foreach ( $origin_addresses as $origin_address ) {
								printf(
									'<option value="%1$s"%2$s>%3$s</option>',
									esc_attr( $origin_address->getID() ),
									selected( $origin_address->getDefault(), true, false ),
									esc_html( SST_Addresses::format( $origin_address ) )
								);
							}
							?>
						</select>
					<?php elseif ( empty( $api_id ) || empty( $api_key ) ): ?>
						<div class="notice notice-info inline sst-settings-notice">
							<p>
								<?php
								_e(
									'Enter your TaxCloud API credentials and click <strong>Save changes</strong> to configure your Origin Addresses.',
									'simple-sales-tax'
								);
								?>
							</p>
						</div>
					<?php else: ?>
						<div class="notice notice-warning inline sst-settings-notice">
							<p>
								<?php
								_e(
									'Oops! It appears there are no addresses in your TaxCloud account. Please add at least one address on the <a href="https://app.taxcloud.com/go/locations" target="_blank">Locations</a> page in TaxCloud and then save your settings to refresh the address list.',
									'simple-sales-tax'
								);
								?>
							</p>
						</div>
					<?php endif; ?>
					<?php echo $this->get_description_html( $data ); ?>
				</fieldset>
			</td>
		</tr>
		<?php

		return ob_get_clean();
	}

	/**
	 * Validate and save default origin addresses when options are saved.
	 *
	 * @param string $key Settings field key.
	 * @param array $value Selected origin address IDs.
	 *
	 * @return array
	 * @since 6.2
	 * @throws Exception
	 */
	public function validate_default_origin_addresses_field( $key, $value ) {
		$addresses = SST_Addresses::get_origin_addresses();

		// You need addresses to have default addresses.
		if ( empty( $addresses ) ) {
			return array();
		}

		if ( empty( $value ) ) {
			throw new Exception( __( 'Please select at least one origin address.', 'simple-sales-tax' ) );
		}

		return array_map( 'sanitize_title', (array) $value );
	}

	/**
	 * Force download debug report if download button was clicked.
	 *
	 * @since 7.0
	 */
	public function maybe_download_debug_report() {
		if ( ! isset( $_GET['download_debug_report'] ) ) { // phpcs:ignore WordPress.CSRF.NonceVerification
			return;
		}

		// Generate report.
		$report         = $this->generate_debug_report();
		$report_length  = strlen( $report );
		$timestamp      = time();
		$filename       = "sst_debug_report_{$timestamp}.txt";

		// Force download.
		header( 'Content-Description: File Transfer' );
		header( 'Content-Type: application/octet-stream' );
		header( "Content-Disposition: attachment; filename={$filename}" );
		header( 'Expires: 0' );
		header( 'Cache-Control: must-revalidate' );
		header( 'Pragma: public' );
		header( "Content-Length: {$report_length}"  );

		die( $report );
	}

	/**
	 * Get WC System Status Report data.
	 *
	 * @return array
	 */
	protected function get_system_status_report() {
		if ( version_compare( WC_VERSION, '9.0', '>=' ) ) {
			return wc_get_container()
				->get( RestApiUtil::class )
				->get_endpoint_data( '/wc/v3/system_status' );
		}

		return wc()->api->get_endpoint_data( '/wc/v3/system_status' );
	}

	/**
	 * Generates the debug report.
	 *
	 * @return string Report content.
	 */
	protected function generate_debug_report() {
		$settings    = wp_json_encode(
			$this->get_settings_for_report(),
			JSON_PRETTY_PRINT
		);
		$report      = wp_json_encode(
			$this->get_system_status_report(),
			JSON_PRETTY_PRINT
		);
		$request_log = $this->tail_log( 'wootax', 100 );
		$error_log   = $this->tail_log( 'fatal-errors', 100 );

		return <<<REPORT
##################################
### System Status Report       ###
##################################

{$report}

##################################
### SST Settings               ###
##################################

{$settings}

##################################
### SST Request Log (last 100) ###
##################################

{$request_log}

##################################
### Fatal Error Log (last 100) ###
##################################

{$error_log}
REPORT;
	}

	/**
	 * Get SST settings formatted for the debug report.
	 *
	 * @return string
	 */
	protected function get_settings_for_report() {
		$settings             = array();
		$excluded_field_types = array(
			'title',
			'anchor',
			'button',
		);

		foreach ( SST_Settings::get_form_fields() as $key => $field ) {
			if ( ! in_array( $field['type'], $excluded_field_types, true ) ) {
				$settings[ $key ] = SST_Settings::get( $key );
			}
		}

		$addresses = array();
		foreach ( SST_Settings::get( 'addresses' ) as $address ) {
			$addresses[] = json_decode( wp_unslash( $address ), true );
		}

		$settings['addresses'] = $addresses;

		return $settings;
	}

	/**
	 * Gets the last N lines of a log file.
	 *
	 * @param string $handle Log handle.
	 * @param int    $lines  Number of lines to read from end of file.
	 *
	 * @return string
	 */
	protected function tail_log( $handle, $lines = 1 ) {
		$filepath = wc_get_log_file_path( $handle );

		if ( ! file_exists( $filepath ) ) {
			return 'N/A';
		}

		return trim( implode( '', array_slice( file( $filepath ), -$lines ) ) );
	}

	/**
	 * Refreshes the origin address list after the plugin settings are updated.
	 *
	 * Hold on refreshing until all legacy addresses have been mapped to
	 * TaxCloud Locations to avoid breaking backward compatibility with
	 * existing SST installations.
	 */
	public function refresh_origin_address_list() {
		$should_refresh_addresses = (
			version_compare( get_option( 'wootax_version' ), '6.2', '>=' )
			&& ! SST_Settings::get( 'address_mismatch', false )
		);

		if ( ! $should_refresh_addresses ) {
			return $result;
		}

		// Reload settings so new API key is used.
		SST_Settings::load_settings();

		// Refresh addresses.
		$default_origins = SST_Settings::get( 'default_origin_addresses', array() );
		$addresses       = SST_Addresses::get_origin_addresses( true );

		foreach ( $addresses as $address ) {
			$address->setDefault( in_array( $address->getID(), $default_origins ) );
		}

		SST_Settings::set(
			'addresses',
			array_map( 'json_encode', $addresses )
		);
	}

	/**
	 * Handles attempts to dismiss the address mismatch notice.
	 *
	 * The notice will be hidden and then the address update routine will be ran
	 * again. If there are no mismatches this time the mismatch notice will be
	 * permanently dismissed, otherwise it will  appear again with the new list
	 * of mismatched addresses.
	 */
	public function maybe_dismiss_address_notice() {
		WC_Admin_Notices::remove_notice( 'sst_address_mismatch' );

		$updater = SST_Install::init_background_updater();

		$updater->push_to_queue( 'sst_update_620_import_origin_addresses' );
		$updater->save()->dispatch();
	}

}
