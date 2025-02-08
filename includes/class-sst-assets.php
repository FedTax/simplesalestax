<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SST_Assets
 *
 * Registers common assets.
 */
class SST_Assets {

	/**
	 * Plugin assets.
	 *
	 * @var array
	 */
	protected $assets = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'init_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'register_admin_assets' ) );
	}

	/**
	 * Initializes the assets array.
	 */
	public function init_assets() {
		$this->assets = array(
			'sst-hideseek'              => array(
				'type'       => 'script',
				'file'       => 'jquery.hideseek.min.js',
				'context'    => 'both',
				'compressed' => true,
				'options'    => array(
					'deps' => array( 'jquery' ),
					'ver'  => '0.7.1',
				),
			),
			'sst-backbone-modal'        => array(
				'type'    => 'script',
				'file'    => 'backbone-modal.js',
				'context' => 'both',
				'options' => array( 'deps' => array( 'underscore', 'backbone', 'wp-util' ) ),
			),
			'sst-tic-select'            => array(
				'type'    => 'script',
				'file'    => 'tic-select.js',
				'context' => 'both',
				'options' => array(
					'deps' => array(
						'jquery',
						'sst-hideseek',
						'sst-backbone-modal',
					),
				),
			),
			'sst-wcv-tic-select'        => array(
				'type'    => 'script',
				'file'    => 'wcv-tic-select.js',
				'context' => 'frontend',
				'options' => array(
					'deps' => array(
						'jquery',
					),
				),
			),
			'sst-wcfm-tic-select'       => array(
				'type'    => 'script',
				'file'    => 'wcfm-tic-select.js',
				'context' => 'frontend',
				'options' => array(
					'deps' => array(
						'jquery',
					),
				),
			),
			'sst-tic-select-css'            => array(
				'type'    => 'style',
				'file'    => 'tic-select.css',
				'context' => 'both',
				'options' => array(
					'deps' => array( 'sst-modal-css' ),
				),
			),
			'sst-admin-js'              => array(
				'type'    => 'script',
				'file'    => 'admin.js',
				'context' => 'admin',
				'options' => array(
					'deps'     => array( 'jquery' ),
					'localize' => array(
						'SST' => array(
							'strings' => array(
								'enter_id_and_key' => __(
									'Please enter your API Login ID and API Key.',
									'simple-sales-tax'
								),
								'settings_valid'   => __(
									'Success! Your TaxCloud settings are valid.',
									'simple-sales-tax'
								),
								'verify_failed'    => __( 'Connection to TaxCloud failed.', 'simple-sales-tax' ),
							),
						),
					),
				),
			),
			'sst-admin-css'             => array(
				'type'    => 'style',
				'file'    => 'admin.css',
				'context' => 'admin',
			),
			'sst-certificate-form'      => array(
				'type'    => 'script',
				'file'    => 'certificate-form.js',
				'context' => 'both',
				'options' => array(
					'deps' => array(
						'jquery',
						'selectWoo',
					),
				),
			),
			'sst-add-certificate-modal' => array(
				'type'    => 'script',
				'file'    => 'add-certificate-modal.js',
				'context' => 'both',
				'options' => array(
					'deps' => array(
						'jquery',
						'jquery-blockui',
						'sst-backbone-modal',
						'sst-certificate-form',
					),
					'localize' => array(
						'SST_Add_Certificate_Data' => array(
							'nonce'   => wp_create_nonce( 'sst_add_certificate' ),
							'strings' => array(
								'please_add_address' => __(
									'Please enter a complete billing address first.',
									'simple-sales-tax'
								),
								'invalid_country'    => __(
									'Billing address must be in the United States to add an exemption certificate.',
									'simple-sales-tax'
								),
							),
						),
					),
				)
			),
			'sst-certificate-table'     => array(
				'type'    => 'script',
				'file'    => 'certificate-table.js',
				'context' => 'both',
				'options' => array(
					'deps'     => array(
						'jquery',
						'wp-util',
						'wp-hooks',
						'underscore',
						'backbone',
						'sst-backbone-modal',
						'jquery-blockui',
						'sst-add-certificate-modal',
					),
				),
			),
			'sst-checkout'              => array(
				'type'    => 'script',
				'file'    => 'checkout.js',
				'context' => 'frontend',
				'options' => array(
					'deps'     => array(
						'jquery',
						'sst-certificate-form',
					),
				),
			),
			'sst-checkout-css'          => array(
				'type'    => 'style',
				'file'    => 'checkout.css',
				'context' => 'frontend',
			),
			'sst-modal-css'             => array(
				'type'    => 'style',
				'file'    => 'modal.css',
				'context' => 'both',
			),
			'sst-certificate-form-css'  => array(
				'type' => 'style',
				'file' => 'certificate-form.css',
			),
			'sst-certificate-modal-css' => array(
				'type'    => 'style',
				'file'    => 'certificate-modal.css',
				'context' => 'both',
				'options' => array(
					'deps' => array(
						'sst-modal-css',
						'sst-certificate-form-css',
					),
				),
			),
			'sst-meta-box'              => array(
				'type'    => 'script',
				'file'    => 'meta-box.js',
				'context' => 'admin',
				'options' => array(
					'deps' => array(
						'jquery',
						'backbone',
						'wp-util',
						'wc-enhanced-select',
						'sst-backbone-modal',
						'sst-add-certificate-modal',
					),
				),
			),
			'sst-address-table'         => array(
				'type'    => 'script',
				'file'    => 'address-table.js',
				'context' => 'admin',
				'options' => array(
					'deps' => array(
						'jquery',
						'wp-util',
						'underscore',
						'backbone',
					),
				),
			),
		);
	}

	/**
	 * Registers frontend assets.
	 */
	public function register_assets() {
		$this->register_assets_for_context( 'frontend' );
	}

	/**
	 * Registers admin assets.
	 */
	public function register_admin_assets() {
		$this->register_assets_for_context( 'admin' );
	}

	/**
	 * Helper for registering assets.
	 *
	 * @param string $context Context to register assets for. Can be 'admin' or 'frontend'.
	 */
	private function register_assets_for_context( $context ) {
		$js_base_url    = SST()->url( 'assets/js/' );
		$css_base_url   = SST()->url( 'assets/css/' );
		$plugin_version = SST()->version;
		$load_minified  = ! defined( 'SCRIPT_DEBUG' ) || ! SCRIPT_DEBUG;
		$defaults       = array(
			'type'       => '',
			'file'       => '',
			'compressed' => false,
			'context'    => 'both',
			'options'    => array(),
		);

		foreach ( $this->assets as $handle => $asset ) {
			$asset   = wp_parse_args( $asset, $defaults );
			$options = $asset['options'];
			$ver     = $options['ver'] ?? $plugin_version;
			$deps    = array();

			if ( isset( $options['deps'] ) ) {
				$deps = $options['deps'];
			}

			if ( 'both' === $asset['context'] || $context === $asset['context'] ) {
				if ( 'script' === $asset['type'] ) {
					$src = $js_base_url . $asset['file'];

					if ( ! $asset['compressed'] && $load_minified ) {
						$src = str_replace( '.js', '.min.js', $src );
					}

					wp_register_script( $handle, $src, $deps, $ver, true );

					if ( isset( $options['localize'] ) ) {
						foreach ( $options['localize'] as $object_name => $data ) {
							wp_localize_script( $handle, $object_name, $data );
						}
					}
				} else {
					$src = $css_base_url . $asset['file'];

					if ( ! $asset['compressed'] && $load_minified ) {
						$src = str_replace( '.css', '.min.css', $src );
					}

					wp_register_style( $handle, $src, $deps, $ver );
				}
			}
		}
	}

}

new SST_Assets();
