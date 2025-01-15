<?php
/**
 * Template for My Account > Exemption Certificates page.
 * You may override this template by copying it to THEME_PATH/sst/html-my-account.php.
 *
 * @since   8.0.0
 * @author  Taxcloud
 * @package Simple Sales Tax
 * @version 8.0.0
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
