/* global SST */
(function (data) {
  jQuery(function ($) {

    var $apiVersion = $('#woocommerce_wootax_api_version');
    var $loginId = $('#woocommerce_wootax_tc_id');
    var $connection = $('#woocommerce_wootax_tc_key');
    var $v3Key = $('#woocommerce_wootax_tc_v3_api_key');
    var $origins = $('#woocommerce_wootax_default_origin_addresses');
    var $verifyResult = $('<p>', {role: 'status', hidden: true}).insertAfter('#verifySettings');
    var $verifiedOrigins = $('<input>', {type: 'hidden', name: 'sst_verified_v3_origins', value: '1'});
    var savedOriginOptions = null;

    function updateCredentialFields() {
      var isV3 = $apiVersion.val() === 'v3';
      $loginId.prop('disabled', isV3).closest('tr').prop('hidden', isV3);
      $v3Key.prop('disabled', !isV3).closest('tr').prop('hidden', !isV3);
      $('.sst-v1-connection').prop('hidden', isV3);
      $('.sst-v3-connection').prop('hidden', !isV3);
    }

    function resetOriginPreview() {
      if (savedOriginOptions) {
        $origins.empty().append(savedOriginOptions).trigger('change');
        $('#sst-origin-options').prop('hidden', !$origins.children().length);
        $('.sst-origin-notice').prop('hidden', false);
        $verifiedOrigins.detach();
        savedOriginOptions = null;
      }
    }

    $apiVersion.on('change', updateCredentialFields);
    $apiVersion.add($connection).add($loginId).add($v3Key).on('input change', function () {
      resetOriginPreview();
      $verifyResult.prop('hidden', true);
    });
    updateCredentialFields();

    function showOriginPreview(origins) {
      var selected = $origins.val() || [];
      if (!savedOriginOptions) {
        savedOriginOptions = $origins.children().clone();
        savedOriginOptions.each(function () {
          this.selected = selected.indexOf(this.value) !== -1;
        });
      }

      $origins.empty();
      origins.forEach(function (origin) {
        var useOrigin = origins.length === 1 || selected.indexOf(origin.id) !== -1;
        $origins.append(new Option(origin.label, origin.id, useOrigin, useOrigin));
      });
      $('#sst-origin-options').prop('hidden', false).append($verifiedOrigins);
      $('.sst-origin-notice').prop('hidden', true);
      $origins.trigger('change');
    }

    $('#verifySettings').on('click', function (e) {
      e.preventDefault();

      var version = $apiVersion.val();
      var loginId = $loginId.val();
      var connection = $connection.val();
      var v3Key = $v3Key.val();
      var nonce = $(this).data('nonce');

      if (version === 'v1' && (!loginId || !connection)) {
        alert(data.strings.enter_id_and_key);
        return;
      }

      var $btn = $(this).prop('disabled', true).text(data.strings.verifying);

      var request = {
        action: 'sst_verify_taxcloud',
        wootax_api_version: version,
        wootax_tc_key: connection,
        _wpnonce: nonce,
      };
      if (version === 'v3') {
        request.wootax_tc_v3_api_key = v3Key;
      } else {
        request.wootax_tc_id = loginId;
      }

      function showFailure(message) {
        resetOriginPreview();
        if (version === 'v3') {
          $verifyResult.text(message).prop('hidden', false);
        } else {
          alert(message);
        }
      }

      $verifyResult.prop('hidden', true);
      $.post(ajaxurl, request)
        .done(function (resp) {
          if ($apiVersion.val() !== version || $connection.val() !== connection ||
              $loginId.val() !== loginId || $v3Key.val() !== v3Key) {
            return;
          }
          if (!resp.success) {
            showFailure(data.strings.verify_failed + ' ' + resp.data);
            return;
          }
          if (resp.data.connection_id) {
            $('.tc-connection-link').attr('href', 'https://app.taxcloud.com/go/integrations/' + encodeURIComponent(resp.data.connection_id) + '#settings');
          }
          if (Array.isArray(resp.data.origins)) {
            showOriginPreview(resp.data.origins);
            $verifyResult.text(data.strings.settings_valid).prop('hidden', false);
          } else {
            alert(data.strings.settings_valid);
          }
        })
        .fail(function (xhr) {
          var error = xhr.responseJSON && xhr.responseJSON.data;
          showFailure(error || data.strings.verify_failed);
        })
        .always(function () {
          $btn.prop('disabled', false).text(data.strings.verify_btn);
        });
    });

    var $capture = $('.sst-capture-order');
    if ($capture.length && !$capture.prop('disabled')) {
      var orderFields = '#woocommerce-order-data :input[name], #postcustom :input[name]';
      var savedFields = $(orderFields).serialize();
      var capturePending = false;
      var captureAllowed = true;
      var updateCaptureButton = function () {
        var unsaved = $(orderFields).serialize() !== savedFields ||
          $('#woocommerce-order-items .edit:visible, #woocommerce-order-items .blockUI').length > 0;
        $capture.prop('disabled', capturePending || !captureAllowed || unsaved);
      };

      $(document).on('input change', orderFields, updateCaptureButton);
      $('#woocommerce-order-items').on('click', 'button, a', function () {
        window.setTimeout(updateCaptureButton, 0);
      });
      $(document).ajaxComplete(updateCaptureButton);

      $capture.on('click', function () {
        updateCaptureButton();
        if ($capture.prop('disabled')) {
          return;
        }
        var $panel = $capture.closest('#sales_tax_meta');
        var label = $capture.text().trim();
        var $result = $panel.find('.sst-capture-result');
        capturePending = true;
        updateCaptureButton();
        $capture.text($capture.data('busy-label')).attr('aria-busy', 'true');
        $result.prop('hidden', true);

        function showCaptureResult(response) {
          var result = response && response.data ? response.data : {};
          $result.text(result.message || data.strings.went_wrong).prop('hidden', false);
          if (typeof result.status === 'string') {
            $panel.find('.sst-taxcloud-status').text(result.status);
          }
          if (result.eligible === false) {
            captureAllowed = false;
            $panel.find('.sst-manual-capture').remove();
          }
        }

        $.post(ajaxurl, {
          action: 'sst_capture_order',
          order_id: $capture.data('order-id'),
          nonce: $capture.data('nonce'),
        }).done(showCaptureResult).fail(function (xhr) {
          showCaptureResult(xhr.responseJSON);
        }).always(function () {
          capturePending = false;
          $capture.text(label).removeAttr('aria-busy');
          updateCaptureButton();
        });
      });
    }

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
              jQuery('.tc-connection-link').attr('href', 'https://app.taxcloud.com/go/integrations/' + encodeURIComponent(resp.data.connection_id) + '#settings');
            }
            alert(data.strings.mode_refreshed + integrationMode);
          } else {
            alert(resp.data || data.strings.went_wrong);
          }
        })
        .fail(function (xhr) {
          var error = xhr.responseJSON && xhr.responseJSON.data;
          alert(error || data.strings.went_wrong);
        })
        .always(function () {
          $btn.prop('disabled', false).removeClass('is-busy');
        });
    });

    // Hide integration-dependent settings when the integration is disabled.
    var dependentSettingIds = [
      '#woocommerce_wootax_force_tax_lookup',
      '#woocommerce_wootax_disable_virtual_split',
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
