<?php
use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

/**
 * Class for integrating with WooCommerce Blocks
 */
class SST_Blocks_Integration implements IntegrationInterface {

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
		$this->register_frontend_scripts();
		$this->register_editor_scripts();
		$this->register_editor_styles();

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
		return array( 'sst-tax-exemption-block-frontend' );
	}

	/**
	 * Returns an array of script handles to enqueue in the editor context.
	 *
	 * @return string[]
	 */
	public function get_editor_script_handles() {
		return array( 'sst-tax-exemption-block-editor' );
	}

	/**
	 * An array of key, value pairs of data made available to the block on the client side.
	 *
	 * @return array
	 */
	public function get_script_data() {
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

		return array(
			'showExemptionForm'    => sst_should_show_tax_exemption_form(),
			'certificateOptions'   => $options,
			'selectedCertificate'  => $selected,
			'isUserLoggedIn'       => is_user_logged_in(),
			'myAccountEndpointUrl' => wc_get_account_endpoint_url( 'exemption-certificates' ),
		);
	}

	public function register_editor_styles() {
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
