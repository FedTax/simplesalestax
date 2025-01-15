<?php
/**
 * Checkout tax exemption form template.
 * Override by copying to `THEME_PATH/sst/html-checkout.php`.
 *
 * @since   8.0.0
 * @author  Taxcloud
 * @package Simple Sales Tax
 * @version 8.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

?>
<h3>
	<?php esc_html_e( 'Tax exemption', 'simple-sales-tax' ); ?>
</h3>
<div id="tax_details">
	<noscript>
		<p>
			<?php
			printf(
				'<strong>%s</strong> %s',
				esc_html__( 'Warning:', 'simple-sales-tax' ),
				esc_html__(
					'This interface will not function properly with JavaScript disabled. Please enable JavaScript to continue.',
					'simple-sales-tax'
				)
			);
			?>
		</p>
	</noscript>

	<?php if ( is_user_logged_in() ) : ?>
		<?php
		woocommerce_form_field(
			'certificate_id',
			array(
				'type'        => 'select',
				'placeholder' => 'None',
				'options'     => $args['options'],
				'label'       => esc_html__( 'Exemption certificate', 'simple-sales-tax' ),
				'input_class' => array( 'sst-input' ),
			),
			$args['selected']
		);
		?>

		<p id="exemption_certificates_link">
			<a
				href="<?php echo esc_url( wc_get_account_endpoint_url( 'exemption-certificates' ) ); ?>"
				target="_blank"
			>
				<?php esc_html_e( 'Manage exemption certificates →', 'simple-sales-tax' ); ?>
			</a>
		</p>

		<div id="exempt_certificate_form" style="display: none;">
			<?php
			wc_get_template(
				'html-certificate-form.php',
				array(
					'allow_single' => true,
				),
				'sst/',
				SST()->path( 'includes/views/' )
			);
			?>
		</div>
	<?php else : ?>
		<p><?php esc_html_e( 'Please log in or register to apply an exemption certificate.' ); ?></p>
	<?php endif; ?>
</div>
