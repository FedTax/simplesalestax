<?php
/**
 * Admin View: Notice - Update
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
		esc_html__( 'We need to update your database to the latest version.', 'simple-sales-tax' )
	);
	?>
</p>
<p class="submit">
	<a href="<?php echo esc_url( admin_url( '?do_sst_update=true' ) ); ?>" class="wc-update-now button-primary">
		<?php esc_html_e( 'Run the updater', 'simple-sales-tax' ); ?>
	</a>
</p>
