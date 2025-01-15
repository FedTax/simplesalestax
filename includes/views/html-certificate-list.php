<?php
/**
 * Certificate list table template.
 *
 * @author  Taxcloud
 * @package Simple Sales Tax
 * @version 7.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$table_class = $args['table_class'] ?? 'shop_table';
$show_inputs = $args['show_inputs'] ?? true;

?>
<table id="sst-certificates" class="<?php echo esc_attr( $table_class ); ?>">
	<thead>
	<tr>
		<?php if ( $show_inputs ): ?>
			<th><!-- Radio button column --></th>
		<?php endif; ?>
		<th><?php esc_html_e( 'ID', 'simple-sales-tax' ); ?></th>
		<th><?php esc_html_e( 'Issued To', 'simple-sales-tax' ); ?></th>
		<th><?php esc_html_e( 'Date', 'simple-sales-tax' ); ?></th>
		<th><?php esc_html_e( 'Actions', 'simple-sales-tax' ); ?></th>
	</tr>
	</thead>
	<tfoot>
	<tr>
		<td colspan="5">
			<a href="#" class="button sst-certificate-add">
				<?php esc_html_e( 'Add Certificate', 'simple-sales-tax' ); ?>
			</a>
		</td>
	</tr>
	</tfoot>
	<tbody></tbody>
</table>

<script type="text/html" id="tmpl-sst-certificate-row-blank">
	<tr>
		<td colspan="5">
			<span>
				<?php
				esc_html_e(
					"There are no certificates to display. Click 'Add Certificate' to add one.",
					'simple-sales-tax'
				);
				?>
			</span>
		</td>
	</tr>
</script>

<script type="text/html" id="tmpl-sst-certificate-row">
	<tr data-id="{{ data.CertificateID }}">
		<?php if ( $show_inputs ): ?>
			<td>
				<input
					type="radio"
					name="certificate_id"
					value="{{ data.CertificateID }}">
			</td>
		<?php endif; ?>
		<td>{{ data.Index }}</td>
		<td>{{ data.PurchaserName }}</td>
		<td>{{ data.CreatedDate }}</td>
		<td>
			<a href="#" class="sst-certificate-view" role="button">
				<?php esc_html_e( 'View', 'simple-sales-tax' ); ?>
			</a>
			<span class="table-action-sep" aria-hidden="true">|</span>
			<a href="#" class="sst-certificate-delete" role="button">
				<?php esc_html_e( 'Delete', 'simple-sales-tax' ); ?>
			</a>
		</td>
	</tr>
</script>
