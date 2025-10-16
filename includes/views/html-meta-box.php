<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
?>

<div>
	<h3>
		<?php esc_html_e( 'TaxCloud Status', 'simple-sales-tax' ); ?>
		<?php
		sst_tip(
			esc_html__(
				"Status of order in TaxCloud ('Pending', 'Captured', or 'Refunded'). All orders should eventually be 'Captured' or 'Refunded.'",
				'simple-sales-tax'
			)
		);
		?>
	</h3>
	<?php echo esc_html( $args['status'] ); ?>
</div>

<div>
	<h3>
		<?php esc_html_e( 'Exemption Certificate', 'simple-sales-tax' ); ?>
		<?php sst_tip( esc_html__( 'An exemption certificate must be applied if the customer is tax exempt.', 'simple-sales-tax' ) ); ?>
	</h3>
	<div id="exempt-cert-select">
		<div class="sst-loader-wrapper">
			<div class="sst-loader" aria-hidden="true">
				<div></div>
				<div></div>
				<div></div>
			</div>
			<span class="screen-reader-text">
				<?php esc_html_e( 'Loading...', 'simple-sales-tax' ); ?>
			</span>
		</div>
	</div>
</div>

<?php if ( 'yes' === SST_Settings::get( 'log_requests' ) ) : ?>
	<div>
		<h3>
			<?php esc_html_e( 'Order Debug', 'simple-sales-tax' ); ?>
			<?php sst_tip( esc_html__( 'Debug information for the order.', 'simple-sales-tax' ) ); ?>
		</h3>
		<button class="button button-secondary sst-debug-order" data-redirect="<?php echo esc_url( admin_url( 'admin.php?page=wc-status&tab=logs&source=wootax-order-' . $args['order_id'] ) ); ?>" data-order-id="<?php echo esc_attr( $args['order_id'] ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'sst-debug-order-nonce' ) ); ?>">
			<?php esc_html_e( '↗ View Debug Log', 'simple-sales-tax' ); ?>
		</button>
	</div>
<?php endif; ?>

<script type="text/html" id="tmpl-exempt-cert-select">
	<# if (data.loading) { #>
		<div class="sst-loader-wrapper">
			<div class="sst-loader" aria-hidden="true">
				<div></div>
				<div></div>
				<div></div>
			</div>
			<span class="screen-reader-text">
				<?php esc_html_e( 'Loading...', 'simple-sales-tax' ); ?>
			</span>
		</div>
	<# } else if (!data.customerId) { #>
		<span class="no-customer-warning">
			<?php esc_html_e( 'Please select a customer to add an exemption certificate.', 'simple-sales-tax' ); ?>
		</span>
	<# } else { #>
		<# if (!data.isEditable) { #>
			<p class="description">
				<?php
				esc_html_e(
					'Certificate is no longer editable. The certificate can only be edited when the TaxCloud Status is Pending.',
					'simple-sales-tax'
				);
				?>
			</p>
		<# } #>
		<a
			href="{{data.customerProfileUrl}}"
			target="_blank"
			class="customer-profile-url">
			<?php esc_html_e( 'Manage customer certificates →', 'simple-sales-tax' ); ?>
		</a>
		<div>
			<label for="exempt_cert" class="screen-reader-text">
				<?php esc_html_e( 'Select certificate', 'simple-sales-tax' ); ?>
			</label>
			<# var disabled = data.isEditable ? '' : 'disabled'; #>
			<select id="exempt_cert" name="exempt_cert" {{disabled}}>
				<option></option>
			</select>
		</div>
		<div class="certificate-actions">
			<button
				type="button"
				class="button button-primary sst-view-certificate">
				<?php esc_html_e( 'View Selected', 'simple-sales-tax' ); ?>
			</button>
			<# if (data.isEditable) { #>
				<button
					type="button"
					class="button button-secondary sst-add-certificate">
					<?php esc_html_e( 'Add New', 'simple-sales-tax' ); ?>
				</button>
			<# } #>
		</div>
	<# } #>
</script>
