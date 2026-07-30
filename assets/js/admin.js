/* global SST */
(function (data) {
  jQuery(function ($) {

    // Verify TaxCloud settings
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
            if (resp.data.connection_id) {
              jQuery('#woocommerce_wootax_tc_connection_id').val(resp.data.connection_id);
              jQuery('.tc-connection-link').attr('href', 'https://app.taxcloud.com/go/integrations/' + resp.data.connection_id + '#settings');
            }
            alert(data.strings.settings_valid);
          } else {
            alert(data.strings.verify_failed + ' ' + resp.data + '.');
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

    // View Order Debug Log
    jQuery(document).on('click', '.sst-debug-order', function (e) {
      e.preventDefault();
      var orderID = jQuery(this).data('order-id');
      if (!orderID) {
        return;
      }
      var nonce = jQuery(this).data('nonce');
      var redirect = jQuery(this).data('redirect');

      // Disable button while fetching log
      var $btn = jQuery(this).prop('disabled', true);

      // Fetch log via AJAX
      jQuery.post(ajaxurl, {
        action: 'sst_get_order_log',
        order_id: orderID,
        _wpnonce: nonce,
      })
        .done(function (resp) {
          if (resp.success) {
            // Open redirect link in new tab
            window.open(redirect, '_blank');
          }
        })
        .fail(function (xhr, status, error) {
          console.error('AJAX POST failed:', xhr, status, error);
          alert(data.strings.went_wrong);
        })
        .always(function () {
          // Re-enable button after request
          $btn.prop('disabled', false);
        });
    });

    // Refresh integration mode
    jQuery(document).on('click', '.sst-update-data-mover', function (e) {
      e.preventDefault();
      var $btn = jQuery(this).prop('disabled', true).addClass('is-busy');
      var nonce = jQuery(this).data('nonce');

      jQuery.post(ajaxurl, {
        action: 'sst_update_data_mover',
        _wpnonce: nonce,
      })
        .done(function (resp) {
          if (resp.success) {
            var integrationMode = resp.data.integration_mode;
            jQuery('#woocommerce_wootax_integration_mode').val(integrationMode);
            if (resp.data.connection_id) {
              jQuery('#woocommerce_wootax_tc_connection_id').val(resp.data.connection_id);
              jQuery('.tc-connection-link').attr('href', 'https://app.taxcloud.com/go/integrations/' + resp.data.connection_id + '#settings');
            }
            alert(data.strings.mode_refreshed + integrationMode);
          } else {
            alert(data.strings.went_wrong);
          }
        })
        .fail(function (xhr, status, error) {
          console.error('AJAX POST failed:', xhr, status, error);
          alert(data.strings.went_wrong);
        })
        .always(function () {
          // Re-enable button after request
          $btn.prop('disabled', false).removeClass('is-busy');
        });
    });

    // Hide integration-dependent settings when the integration is disabled.
    var dependentSettingIds = [
      '#woocommerce_wootax_force_tax_lookup',
      '#woocommerce_wootax_capture_orders_in_taxcloud',
      '#woocommerce_wootax_capture_trigger',
      '#woocommerce_wootax_tax_based_on',
      '#woocommerce_wootax_enable_taxcloud_rate_limit',
      '#woocommerce_wootax_taxcloud_rate_limit_requests',
      '#woocommerce_wootax_taxcloud_rate_limit_scope',
      '#woocommerce_wootax_taxcloud_rate_limit_window',
      '#woocommerce_wootax_taxcloud_rate_limit_skip_admin',
      '#woocommerce_wootax_show_rate_limit_notice',
    ];

    function toggleIntegrationDependentSettings() {
      var integrationDisabled = jQuery('#woocommerce_wootax_disable_integration').val() === 'yes';

      jQuery.each(dependentSettingIds, function (index, selector) {
        jQuery(selector).closest('tr').toggle(!integrationDisabled);
      });
    }

    jQuery('#woocommerce_wootax_disable_integration')
      .on('change', toggleIntegrationDependentSettings);

    toggleIntegrationDependentSettings();

    // Disable exemption-dependent settings when "Enable Tax Exemptions?" is set to "No".
    var exemptionSettingSelectors = [
      '#woocommerce_wootax_company_name',
      '#woocommerce_wootax_exempt_roles',
      '#woocommerce_wootax_restrict_exempt',
    ];

    function toggleExemptionSettings() {
      var exemptionDisabled = jQuery('#woocommerce_wootax_show_exempt').val() === 'false';

      jQuery.each(exemptionSettingSelectors, function (index, selector) {
        var $el = jQuery(selector);
        $el.prop('disabled', exemptionDisabled);
      });
    }

    jQuery('#woocommerce_wootax_show_exempt')
      .on('change', toggleExemptionSettings);

    toggleExemptionSettings();

  });
})(SST);
