<?php
/**
 * My Account view template.
 *
 * @package SST
 * @since   1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<p>
	<?php
	esc_html_e(
		'Exemption certificates added below will be available to apply each time you checkout. Use the form on the checkout page to add a single-purchase exemption certificate.',
		'simple-sales-tax'
	);
	?>
</p>
<?php

sst_render_certificate_table(
	get_current_user_id(),
	array( 'show_inputs' => false )
);
