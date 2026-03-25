/* global jQuery */
jQuery(function ($) {
    // Update checkout totals when certificate changes.
    function updateCheckoutTotals() {
        jQuery(document.body).trigger('update_checkout');
    }

    // Toggle visibility of tax details form based on value of "Tax exempt?" checkbox.
    jQuery(document).on('change', '#certificate_id', function () {
        // If the value is 'none', hide the form and return.
        if (jQuery(this).val() === 'none') {
            jQuery('#exempt_certificate_form').hide();
            return;
        }

        jQuery('#exempt_certificate_form').toggle(
            jQuery('#certificate_id').val() === 'new'
        );

        updateCheckoutTotals();
    });

    jQuery('#certificate_id').trigger('change');
});
