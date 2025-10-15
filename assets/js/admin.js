/* global SST */
(function (data) {
  jQuery(function ($) {

    jQuery('#verifySettings').on('click', function (e) {
      e.preventDefault();

      var apiID = jQuery('#woocommerce_wootax_tc_id').val();
      var apiKey = jQuery('#woocommerce_wootax_tc_key').val();
      var nonce = jQuery(this).data('nonce');

      if (!apiID || !apiKey) {
        alert(data.strings.enter_id_and_key);
        return;
      }

      // Disable button while verifying
      var $btn = jQuery(this).prop('disabled', true).text(data.strings.verifying);

      // Send AJAX request to verify credentials
      jQuery.post(ajaxurl, {
        action: 'sst_verify_taxcloud',
        wootax_tc_id: apiID,
        wootax_tc_key: apiKey,
        _wpnonce: nonce,
      })
        .done(function (resp) {
          if (resp.success) {
            alert(data.strings.settings_valid);
          } else {
            alert( data.strings.verify_failed  + ' ' + resp.data + '.' );
          }
        })
        .fail(function (xhr, status, error) {
          // Handle network/server/timeouts
          console.error('AJAX POST failed:', xhr, status, error);
          let message = 'Request failed (' + status + ')';
          if (xhr.status >= 400) {
            message += ' - HTTP ' + xhr.status;
          }
          alert(data.strings.verify_failed + '\n\n' + message);
        })
        .always(function () {
          // Re-enable button after request
          $btn.prop('disabled', false).text(data.strings.verify_btn);
        });
    });
  });
})(SST);
