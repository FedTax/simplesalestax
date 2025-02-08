<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
?>

</div> <!-- close "shipping class" options group -->

<div class="options_group">
	<p class="form-field" id="shipping_origin_field">
		<label for="_wootax_origin_addresses[]">
			<?php esc_html_e( 'Origin addresses', 'simple-sales-tax' ); ?>
		</label>
		<select class="wc-enhanced-select" name="_wootax_origin_addresses[]" multiple="multiple">
			<?php
			// Output select box.
			$origin_addresses = SST_Product::get_origin_addresses( $post->ID );
			$selected_ids     = array_keys( $origin_addresses );

			if ( is_array( $addresses ) && count( $addresses ) > 0 ) {
				foreach ( $addresses as $address_id => $address ) {
					printf(
						'<option value="%s" %s>%s</option>',
						esc_attr( $address_id ),
						selected( in_array( $address_id, $selected_ids ), true, false ),
						esc_html( SST_Addresses::format( $address ) )
					);
				}
			} else {
				printf(
					'<option value="">%s</option>',
					esc_html__( 'There are no addresses to select.', 'simple-sales-tax' )
				);
			}
			?>
		</select>
		<?php
		sst_tip(
			esc_html__(
				'Used by Simple Sales Tax for tax calculations. These are the addresses from which this product will be shipped.',
				'simple-sales-tax'
			)
		);
		?>
	</p>
