jQuery(function($) {
	var CertificateSelectModel = Backbone.Model.extend({
		defaults: {
			certificates: {},
			customerId: '',
			customerProfileUrl: '',
			selectedCertificate: '',
			loading: true,
			isEditable: true,
		},

		initialize: function() {
			this.loadCustomerCertificates();
			this.setCustomerProfileUrl();

			this.on('change:customerId', this.loadCustomerCertificates);
			this.on('change:customerId', this.setCustomerProfileUrl);
			this.on('change:certificates', this.maybeResetSelection);
		},

		loadCustomerCertificates: function() {
			var _this = this;
			var customerId = _this.get('customerId');

			_this.set('certificates', {});

			if (!customerId) {
				_this.set('loading', false);
				return;
			}

			_this.set('loading', true);

			var requestData = {
				action: 'sst_get_certificates',
				nonce: SSTMetaBox.get_certificates_nonce,
				customerId: customerId,
			};

			$.get(ajaxurl, requestData).then(function(response) {
				var certificates = response.data;

				if (SSTMetaBox.single_purchase_certificate) {
					certificates[SSTMetaBox.single_purchase_cert_id] = SSTMetaBox.single_purchase_certificate;
				}

				_this.set('certificates', certificates);
			}).catch(function(err) {
				console.error('Failed to load certificates:', err.message);
			}).always(function() {
				_this.set('loading', false);
			});
		},

		setCustomerProfileUrl: function() {
			var customerId = this.get('customerId');

			if (!customerId) {
				this.set('customerProfileUrl', '');
				return;
			}

			var urlTemplate = SSTMetaBox.edit_user_url;
			var profileUrl = urlTemplate.replace('{user_id}', customerId);

			this.set('customerProfileUrl', profileUrl);
		},

		maybeResetSelection: function() {
			var selectedCertificate = this.get('selectedCertificate');

			if (!selectedCertificate) {
				return;
			}

			var certificates = this.get('certificates');
			var selectionValid = selectedCertificate in certificates;

			if (!selectionValid) {
				this.set('selectedCertificate', '');
			}
		}
	});

	var CertificateSelectView = Backbone.View.extend({
		template: wp.template('exempt-cert-select'),

		events: {
			'change select': 'updateSelectedCertificate',
			'click .sst-view-certificate': 'viewCertificate',
			'click .sst-add-certificate': 'addCertificate',
		},

		initialize: function() {
			this.listenTo(
				this.model,
				'change:loading change:customerId',
				this.render
			);
			this.listenTo(
				this.model,
				'change:certificates',
				this.updateDropdownOptions
			);
			this.listenTo(
				this.model,
				'change:selectedCertificate',
				this.toggleViewButton
			);
			jQuery(document).ajaxSend(this.filterRecalcRequest.bind(this));
		},

		render: function() {
			this.$el.html(
				this.template(this.model.attributes)
			);

			this.initDropdown();
			this.toggleViewButton();

			return this;
		},

		initDropdown: function() {
			this.$('select').selectWoo({
				minimumResultsForSearch: Infinity,
				placeholder: SSTMetaBox.i18n.none,
				allowClear: true,
				data: this.getDropdownOptions()
			});
		},

		updateDropdownOptions: function() {
			if (!this.model.get('loading')) {
				this.initDropdown();
			}
		},

		getDropdownOptions: function() {
			var certificates = this.model.get('certificates');
			var selectedCertificate = this.model.get('selectedCertificate');
			var options = [
				{
					id: '',
					text: SSTMetaBox.i18n.none,
					selected: selectedCertificate === '',
				}
			];

			if (SSTMetaBox.single_purchase_cert_id in certificates) {
				options.push({
					id: SSTMetaBox.single_purchase_cert_id,
					text: SSTMetaBox.i18n.single_purchase_certificate,
					selected: selectedCertificate === SSTMetaBox.single_purchase_cert_id,
				});
			}

			for (var key in certificates) {
				if (!certificates.hasOwnProperty(key)) {
					continue;
				}

				var certificate = certificates[key];
				var certificateId = certificate.CertificateID;
				var isSelected = selectedCertificate === certificateId;

				options.push({
					id: certificateId,
					text: certificate.Description,
					selected: isSelected,
				});
			}

			return options;
		},

		updateSelectedCertificate: function() {
			this.model.set(
				'selectedCertificate',
				this.$('select').val()
			);
		},

		toggleViewButton: function() {
			var showButton = !!this.model.get('selectedCertificate');
			this.$('.sst-view-certificate').toggle(showButton);
		},

		viewCertificate: function() {
			var certificates = this.model.get('certificates');
			var selectedId = this.model.get('selectedCertificate');
			var certificate = certificates[selectedId];

			if (!certificate) {
				return;
			}

			jQuery(this).SSTBackboneModal({
				template: 'sst-modal-view-certificate',
				variable: certificate,
			});
		},

		addCertificate: function() {
			SST_Add_Certificate_Modal.open({
				address: this.getBillingAddress(),
				onAddCertificate: this.addCertificateHandler.bind(this),
			});
		},

		addCertificateHandler: function(post_data) {
			jQuery('#sales_tax_meta').block({
				message: null,
				overlayCSS: {
					background: '#fff',
					opacity: 0.6
				}
			});

			var postUrl = ajaxurl + '?action=sst_add_certificate';
			var data = {
				nonce: SST_Add_Certificate_Data.nonce,
				user_id: this.getCustomerId(),
				address: this.getBillingAddress(),
				...post_data,
			};

			jQuery.post(postUrl, data)
				.then(function(response) {
					if (!response.success) {
						throw new Error(response.data);
					}

					var certificates = response.data.certificates;

					if (SSTMetaBox.single_purchase_certificate) {
						certificates[SSTMetaBox.single_purchase_cert_id] = SSTMetaBox.single_purchase_certificate;
					}

					model.set({
						'selectedCertificate': response.data.certificate_id,
						'certificates': certificates,
					});

					alert(SSTMetaBox.i18n.certificate_added);
				})
				.fail(function(e) {
					alert(SSTMetaBox.i18n.add_certificate_failed + ': ' + e);
				})
				.always(function() {
					jQuery('#sales_tax_meta').unblock();
				});
		},

		getBillingAddress: function() {
			var address = {
				first_name: '',
				last_name: '',
				address_1: '',
				address_2: '',
				country: '',
				city: '',
				state: '',
				postcode: '',
			};

			for (var key in address) {
				if (!address.hasOwnProperty(key)) {
					continue;
				}
				address[key] = $('#_billing_' + key).val().trim();
			}

			return address;
		},

		getCustomerId: function() {
			return $('#customer_user').val();
		},

		filterRecalcRequest: function(event, jqXHR, settings) {
			if (typeof settings.data !== 'string') {
				return;
			}

			if (settings.data.indexOf('action=woocommerce_calc_line_taxes') >= 0) {
				var certificateId = this.model.get('selectedCertificate');
				settings.data += '&exemption_certificate=' + encodeURIComponent(certificateId);
			}
		}
	});

	var orderIsEditable = $('button.calculate-action').is(':visible');
	var model = new CertificateSelectModel({
		customerId: $('#customer_user').val(),
		selectedCertificate: SSTMetaBox.selected_certificate,
		isEditable: orderIsEditable && 'pending' === SSTMetaBox.order_status,
	});

	var view = new CertificateSelectView({
		el: $('#exempt-cert-select'),
		model: model,
	});

	view.render();

	$('#customer_user').on('change', function() {
		model.set('customerId', $(this).val());
	});
});
