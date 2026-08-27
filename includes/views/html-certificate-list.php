<?php
/**
 * Certificate list table template.
 *
 * @author  Brett Porcelli
 * @package Simple Sales Tax
 * @version 7.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$table_class = $args['table_class'] ?? 'shop_table';
$show_inputs = $args['show_inputs'] ?? true;
$column_count = $show_inputs ? 5 : 4;

?>
<table id="sst-certificates" class="<?php echo esc_attr( $table_class ); ?>">
	<thead>
	<tr>
		<?php if ( $show_inputs ): ?>
			<th class="sst-certificate-select-column">
				<span class="screen-reader-text"><?php esc_html_e( 'Select', 'simple-sales-tax' ); ?></span>
			</th>
		<?php endif; ?>
		<th><?php esc_html_e( 'Certificate', 'simple-sales-tax' ); ?></th>
		<th><?php esc_html_e( 'Issued To', 'simple-sales-tax' ); ?></th>
		<th><?php esc_html_e( 'Added', 'simple-sales-tax' ); ?></th>
		<th><?php esc_html_e( 'Actions', 'simple-sales-tax' ); ?></th>
	</tr>
	</thead>
	<tfoot>
	<tr>
		<td colspan="<?php echo esc_attr( $column_count ); ?>">
			<div class="sst-certificate-table-footer">
				<div class="sst-certificate-table-actions">
					<a href="#" class="button sst-certificate-add">
						<?php esc_html_e( 'Add Certificate', 'simple-sales-tax' ); ?>
					</a>
					<a href="#" class="button sst-certificate-refresh">
						<?php esc_html_e( 'Refresh Certificates', 'simple-sales-tax' ); ?>
					</a>
				</div>
				<div class="sst-certificate-table-pagination" hidden>
					<span class="sst-certificate-count" aria-live="polite"></span>
					<button type="button" class="button-link sst-certificate-show-more">
						<?php esc_html_e( 'Show more', 'simple-sales-tax' ); ?>
					</button>
				</div>
			</div>
		</td>
	</tr>
	</tfoot>
	<tbody></tbody>
</table>

<script type="text/html" id="tmpl-sst-certificate-row-blank">
	<tr>
		<td colspan="<?php echo esc_attr( $column_count ); ?>" class="sst-certificate-table-message">
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

<script type="text/html" id="tmpl-sst-certificate-row-loading">
	<tr class="sst-certificate-row-loading">
		<td colspan="<?php echo esc_attr( $column_count ); ?>" class="sst-certificate-table-message">
			<span class="spinner is-active"></span>
			<span>
				<?php esc_html_e( 'Loading exemption certificates...', 'simple-sales-tax' ); ?>
			</span>
		</td>
	</tr>
</script>

<script type="text/html" id="tmpl-sst-certificate-row">
	<tr data-id="{{ data.CertificateID }}">
		<?php if ( $show_inputs ): ?>
			<td class="sst-certificate-select-column" data-title="<?php esc_attr_e( 'Select', 'simple-sales-tax' ); ?>">
				<input
					type="radio"
					name="certificate_id"
					value="{{ data.CertificateID }}"
					aria-label="<?php esc_attr_e( 'Select certificate', 'simple-sales-tax' ); ?> {{ data.CertificateLabel }}">
			</td>
		<?php endif; ?>
		<td class="sst-certificate-summary" data-title="<?php esc_attr_e( 'Certificate', 'simple-sales-tax' ); ?>">
			<strong>{{ data.PurchaserExemptionReason }}</strong>
			<span class="sst-certificate-meta">
				<?php esc_html_e( 'States:', 'simple-sales-tax' ); ?> {{ data.ExemptStatesLabel }}
			</span>
			<code>{{ data.CertificateLabel }}</code>
		</td>
		<td data-title="<?php esc_attr_e( 'Issued To', 'simple-sales-tax' ); ?>">
			<strong>{{ data.PurchaserName }}</strong>
			<span class="sst-certificate-meta">{{ data.PurchaserBusinessType }}</span>
		</td>
		<td data-title="<?php esc_attr_e( 'Added', 'simple-sales-tax' ); ?>">
			<span>{{ data.CreatedDate }}</span>
		</td>
		<td data-title="<?php esc_attr_e( 'Actions', 'simple-sales-tax' ); ?>">
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
