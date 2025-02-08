<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Set the CSS classes to apply to the buttons in the TIC select modal.
 *
 * @since 6.3.0
 *
 * @param array $classes Classes to apply to the buttons in the TIC select modal.
 */
$button_classes   = apply_filters( 'sst_tic_select_button_classes', array( 'button', 'button-primary' ) );
$button_classes[] = 'sst-select-done';

/**
 * Set the CSS classes to apply to the search field in the TIC select modal.
 *
 * @since 6.3.0
 *
 * @param array $classes Classes to apply to the search field in the TIC select modal.
 */
$input_classes   = apply_filters( 'sst_tic_select_input_classes', array() );
$input_classes[] = 'sst-tic-search';

?>
<script type="text/html" id="tmpl-sst-tic-row">
	<tr class="tic-row" data-id="{{ data.id }}">
		<td>
			<h4>{{ data.id }}</h4>
			<p>{{ data.description }}</p>
		</td>
		<td width="1%">
			<button type="button" class="<?php echo esc_attr( implode( ' ', $button_classes ) ); ?>">
				<?php esc_html_e( 'Select', 'simple-sales-tax' ); ?>
			</button>
		</td>
	</tr>
</script>

<script type="text/html" id="tmpl-sst-tic-select-modal">
	<div class="wc-backbone-modal">
		<div class="wc-backbone-modal-content sst-select-tic-modal-content woocommerce">
			<section class="wc-backbone-modal-main" role="main">
				<header class="wc-backbone-modal-header">
					<h1><?php esc_html_e( 'Select TIC', 'simple-sales-tax' ); ?></h1>
					<button class="modal-close modal-close-link">
						&times;
						<span class="screen-reader-text">
							<?php esc_html_e( 'Close modal panel', 'simple-sales-tax' ); ?>
						</span>
					</button>
				</header>
				<article>
					<form action="" method="post">
						<input name="search" class="<?php echo esc_attr( implode( ' ', $input_classes ) ); ?>"
							   placeholder="<?php esc_attr_e( 'Start typing to search', 'simple-sales-tax' ); ?>" type="text"
							   data-list=".sst-tic-list">
						<table>
							<tbody class="sst-tic-list"></tbody>
						</table>
						<input type="hidden" name="tic" value="">
						<input type="submit" id="btn-ok" name="btn-ok" value="Submit" style="display: none;">
					</form>
				</article>
			</section>
		</div>
	</div>
	<div class="wc-backbone-modal-backdrop modal-close"></div>
</script>
