<?php
/**
 * Reposition the SST Tax Exemption Form.
 *
 * This script moves the SST Tax Exemption form from its default location
 * (after customer details) to a custom location (after the billing form).
 *
 * This can be placed in a theme's functions.php or treated as a standalone plugin file.
 */

add_action( 'wp', function () {
	global $wp_filter;

	// Define the source and destination hooks for the form output.
	$original_hook = 'woocommerce_checkout_after_customer_details';
	$new_hook      = 'woocommerce_after_checkout_billing_form';

	// If no filters are registered on the original hook, there's nothing for us to do.
	if ( empty( $wp_filter[ $original_hook ] ) ) {
		return;
	}

	/**
	 * Iterate through all callbacks attached to the original hook to find
	 * the specific 'output_exemption_form' method from the SST_Checkout class.
	 */
	foreach ( $wp_filter[ $original_hook ]->callbacks as $priority => $callbacks ) {

		foreach ( $callbacks as $callback ) {

			// Check if the callback is an array containing an instance of SST_Checkout.
			if (
				is_array( $callback['function'] ) &&
				is_object( $callback['function'][0] ) &&
				get_class( $callback['function'][0] ) === 'SST_Checkout'
			) {

				/** @var SST_Checkout $instance */
				$instance = $callback['function'][0];

				/**
				 * 1. Remove the action from the original hook.
				 * Use the same priority it was originally registered with.
				 */
				remove_action(
					$original_hook,
					array( $instance, 'output_exemption_form' ),
					$priority
				);

				/**
				 * 2. Add the action to the new desired hook.
				 * We use a priority of 15 to place it appropriately within the billing form.
				 */
				add_action(
					$new_hook,
					array( $instance, 'output_exemption_form' ),
					15
				);

				// Once we've found and moved the form, we can exit the loop.
				return;
			}
		}
	}

}, 20 );