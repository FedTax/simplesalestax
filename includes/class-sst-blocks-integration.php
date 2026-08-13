<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

/**
 * Class for integrating with WooCommerce Blocks
 */
class SST_Blocks_Integration implements IntegrationInterface {

	/**
	 * Block context for this integration.
	 *
	 * @var string
	 */
	protected $block_context;

	/**
	 * Constructor.
	 *
	 * @param string $block_context Block context: cart or checkout.
	 */
	public function __construct( $block_context = 'checkout' ) {
		$this->block_context = $block_context;
	}

	/**
	 * The name of the integration.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'simple-sales-tax';
	}

	/**
	 * When called invokes any initialization/setup for the integration.
	 */
	public function initialize() {
		$this->register_rate_limit_notice_script();

		if ( 'checkout' !== $this->block_context ) {
			return;
		}

		$this->register_frontend_scripts();
		$this->register_editor_scripts();
		$this->register_block_styles();

		add_filter(
			'the_content',
			array( $this, 'force_exemption_block' ),
			1
		);
	}

	/**
	 * Returns an array of script handles to enqueue in the frontend context.
	 *
	 * @return string[]
	 */
	public function get_script_handles() {
		$handles = array( 'sst-rate-limit-notice' );

		if ( 'checkout' === $this->block_context ) {
			$handles[] = 'sst-tax-exemption-block-frontend';
		}

		return $handles;
	}

	/**
	 * Returns an array of script handles to enqueue in the editor context.
	 *
	 * @return string[]
	 */
	public function get_editor_script_handles() {
		if ( 'checkout' !== $this->block_context ) {
			return array();
		}

		return array( 'sst-tax-exemption-block-editor' );
	}

	/**
	 * An array of key, value pairs of data made available to the block on the client side.
	 *
	 * @return array
	 */
	public function get_script_data() {
		if ( 'checkout' !== $this->block_context ) {
			return array();
		}

		if ( ! sst_should_show_tax_exemption_form() ) {
			return array(
				'showExemptionForm'    => false,
				'certificateOptions'   => array(),
				'selectedCertificate'  => '',
				'isUserLoggedIn'       => is_user_logged_in(),
				'myAccountEndpointUrl' => '',
			);
		}

		$certificates = SST_Certificates::get_certificates_formatted();
		$options      = array(
			'new'  => 'Add new certificate',
		);

		foreach ( $certificates as $cert ) {
			$options[ $cert['CertificateID'] ] = $cert['Description'];
		}

		$selected = WC()->session
			? WC()->session->get( 'sst_certificate_id', '' )
			: '';

		if ( empty( $selected ) && sst_is_user_tax_exempt() && ! empty( $certificates ) && ! ( WC()->session && WC()->session->get( 'sst_cert_explicitly_cleared' ) ) ) {
			$selected = current( array_keys( $certificates ) );
			if ( WC()->session ) {
				WC()->session->set( 'sst_certificate_id', $selected );
			}
		}

		return array(
			'showExemptionForm'    => sst_should_show_tax_exemption_form(),
			'certificateOptions'   => $options,
			'selectedCertificate'  => $selected,
			'isUserLoggedIn'       => is_user_logged_in(),
			'myAccountEndpointUrl' => wc_get_account_endpoint_url( 'exemption-certificates' ),
		);
	}

	/**
	 * Register the rate-limit notice frontend script.
	 */
	public function register_rate_limit_notice_script() {
		$script_url        = SST()->url( 'build/rate-limit-notice.js' );
		$script_asset_path = SST()->path( 'build/rate-limit-notice.asset.php' );
		$script_asset      = file_exists( $script_asset_path )
			? require $script_asset_path
			: array(
				'dependencies' => array(),
				'version'      => $this->get_file_version( $script_asset_path ),
			);

		wp_register_script(
			'sst-rate-limit-notice',
			$script_url,
			$script_asset['dependencies'],
			$script_asset['version'],
			true
		);
	}

	public function register_block_styles() {
		add_action( 'enqueue_block_assets', array( $this, 'enqueue_block_styles' ) );
	}

	public function enqueue_block_styles() {
		$style_path = 'build/style-tax-exemption-block.css';
		$style_url  = SST()->url( $style_path );

		wp_enqueue_style(
			'sst-tax-exemption-block',
			$style_url,
			[],
			$this->get_file_version( $style_path )
		);
	}

	public function register_editor_scripts() {
		$script_url        = SST()->url( 'build/tax-exemption-block.js' );
		$script_asset_path = SST()->path( 'build/tax-exemption-block.asset.php' );
		$script_asset      = file_exists( $script_asset_path )
			? require $script_asset_path
			: array(
				'dependencies' => array(),
				'version'      => $this->get_file_version( $script_asset_path ),
			);

		wp_register_script(
			'sst-tax-exemption-block-editor',
			$script_url,
			$script_asset['dependencies'],
			$script_asset['version'],
			true
		);

		wp_set_script_translations(
			'sst-tax-exemption-block-editor',
			'simple-sales-tax',
			SST()->path( 'languages' )
		);
	}

	public function register_frontend_scripts() {
		$script_url        = SST()->url( 'build/tax-exemption-block-frontend.js' );
		$script_asset_path = SST()->path( 'build/tax-exemption-block-frontend.asset.php' );
		$script_asset      = file_exists( $script_asset_path )
			? require $script_asset_path
			: array(
				'dependencies' => array(),
				'version'      => $this->get_file_version( $script_asset_path ),
			);

		wp_register_script(
			'sst-tax-exemption-block-frontend',
			$script_url,
			$script_asset['dependencies'],
			$script_asset['version'],
			true
		);

		wp_set_script_translations(
			'sst-tax-exemption-block-frontend',
			'simple-sales-tax',
			SST()->path( 'languages' )
		);
	}

	/**
	 * Get the file modified time as a cache buster if we're in dev mode.
	 *
	 * @param string $file Local path to the file.
	 * @return string The cache buster value to use for the given file.
	 */
	protected function get_file_version( $file ) {
		$file_path = SST()->path( $file );
		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG && file_exists( $file_path ) ) {
			return filemtime( $file_path );
		}
		return SST()->version;
	}

	/**
	 * Inserts the exemption block into the WooCommerce checkout fields block.
	 *
	 * @param array &$blocks Checkout page blocks
	 */
	protected function insert_exemption_block( &$blocks ) {
		$exemption_block = array(
			'blockName'    => 'simple-sales-tax/tax-exemption',
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => '<div data-block-name="simple-sales-tax/tax-exemption" class="wp-block-simple-sales-tax-tax-exemption"></div>',
			'innerContent' => array(
				'<div data-block-name="simple-sales-tax/tax-exemption" class="wp-block-simple-sales-tax-tax-exemption"></div>',
			),
		);

		$insert_before = array(
			'woocommerce/checkout-terms-block',
			'woocommerce/checkout-actions-block',
		);

		foreach ( $blocks as &$block ) {
			$block_name = $block['blockName'];

			if ( $block_name === 'woocommerce/checkout-fields-block' ) {
				$insert_key = count( $block['innerBlocks'] );

				foreach ( $block['innerBlocks'] as $key => $inner_block ) {
					if ( in_array( $inner_block['blockName'], $insert_before ) ) {
						$insert_key = $key;
						break;
					}
				}

				array_splice(
					$block['innerBlocks'],
					$insert_key,
					0,
					array( $exemption_block )
				);

				return $blocks;
			} else {
				$block['innerBlocks'] = $this->insert_exemption_block(
					$block['innerBlocks']
				);
			}
		}

		return $blocks;
	}

	/**
	 * Force exemption block into checkout page markup after payment block.
	 */
	public function force_exemption_block( $content ) {
		if ( ! sst_should_show_tax_exemption_form() ) {
			return $content;
		}

		if ( ! has_block( 'woocommerce/checkout' ) ) {
			return $content;
		}

		if ( has_block( 'simple-sales-tax/tax-exemption' ) ) {
			return $content;
		}

		$blocks     = parse_blocks( $content );
		$new_blocks = $this->insert_exemption_block( $blocks );

		return serialize_blocks( $new_blocks );
	}

}
