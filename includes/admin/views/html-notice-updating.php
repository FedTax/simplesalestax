<?php
/**
 * Admin View: Notice - Updating
 *
 * @package simple-sales-tax
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<p>
	<?php
	printf(
		'<strong>%s</strong> %s',
		esc_html__( 'Simple Sales Tax data update.', 'simple-sales-tax' ),
		esc_html__(
			'Your database is being updated in the background. This notice will disappear when the update is complete.',
			'simple-sales-tax'
		)
	);
	?>
</p>
