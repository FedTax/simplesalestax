jQuery( function( $ ) {
	var script_data = window.SST_Certificate_Table_Data || { strings: {}, certificates: {}, user_id: 0 };
	var $row_template = wp.template( 'sst-certificate-row' );
	var $blank_template = wp.template( 'sst-certificate-row-blank' );
	var $loading_template = wp.template( 'sst-certificate-row-loading' );
	var pageSize = 20;

	// Backbone model
	var CertificateTable = Backbone.Model.extend( {
		certificates: {},
		selected: ''
	} );

	// Backbone view
	var CertificateTableView = Backbone.View.extend( {
		userId: 0,
		addressFields: {},
		fetchOnLoad: false,
		isLoading: false,
		visibleCount: pageSize,
		initialize: function( options ) {
			this.userId = options.user_id ? +options.user_id : 0;
			this.addressFields = options.address_fields || {};
			this.fetchOnLoad = !! options.fetch_on_load;
			this.isLoading = false;
			this.visibleCount = pageSize;
			this.$table = this.$el.closest( 'table' );

			this.listenTo( this.model, 'change:certificates', this.resetAndRender );
			this.listenTo( this.model, 'change:selected', this.fireChangeHook );

			$( document.body ).on(
				'click',
				'.sst-certificate-add',
				{ view: this },
				this.onAddCertificate
			);

			$( document.body ).on(
				'click',
				'.sst-certificate-refresh',
				{ view: this },
				this.onRefreshCertificates
			);

			this.$table.on(
				'click',
				'.sst-certificate-show-more',
				{ view: this },
				this.onShowMore
			);

			if ( this.fetchOnLoad ) {
				this.fetchCertificates();
			}
		},
		resetAndRender: function() {
			this.visibleCount = pageSize;
			this.render();
		},
		render: function() {
			if ( this.isLoading ) {
				this.$el.empty();
				this.updateTableStatus( 0 );
				if ( typeof $loading_template === 'function' ) {
					this.$el.append( $loading_template() );
				}
				return;
			}

			var certificates = _.values( this.model.get( 'certificates' ) ),
				selected     = this.model.get( 'selected' ),
				view         = this;

			// Blank out the contents.
			this.$el.empty();

			if ( certificates.length ) {
				// Populate $tbody with the current certificates
				$.each( certificates.slice( 0, this.visibleCount ), function( index, rowData ) {
					view.$el.append( $row_template( rowData ) );
				} );
				this.updateTableStatus( certificates.length );

				// Make the rows function
				view.$el.find( '.sst-certificate-delete' ).on( 'click', { view: this }, this.onDeleteRow );
				view.$el.find( '.sst-certificate-view' ).on( 'click', { view: this }, this.onViewCertificate );
				view.$el.find( 'input[name="certificate_id"]' ).on( 'change', this.updateSelected.bind( this ) );

				// Select certificate (first certificate selected by default)
				if ( selected ) {
					view.$el.find( 'input[name="certificate_id"][value="' + selected + '"]' ).prop( 'checked', true );
				} else {
					var first = view.$el.find( 'input[name="certificate_id"]' ).first();
					if ( first.length ) {
						first.prop( 'checked', true );
						this.model.set( 'selected', first.val() );
					}
				}
			} else {
				this.updateTableStatus( 0 );
				view.$el.append( $blank_template );
			}
		},
		updateTableStatus: function( total ) {
			var $pagination = this.$table.find( '.sst-certificate-table-pagination' );

			if ( ! total ) {
				$pagination.prop( 'hidden', true );
				return;
			}

			var shown = Math.min( this.visibleCount, total );
			var countText = script_data.strings.showing_certificates || 'Showing %1$d of %2$d certificates';
			countText = countText.replace( '%1$d', shown ).replace( '%2$d', total );

			$pagination.prop( 'hidden', false );
			$pagination.find( '.sst-certificate-count' ).text( countText );
			$pagination.find( '.sst-certificate-show-more' ).toggle( shown < total );
		},
		onShowMore: function( event ) {
			event.preventDefault();

			var view = event.data.view;
			view.visibleCount += pageSize;
			view.render();
		},
		fetchCertificates: function() {
			var view = this;
			view.isLoading = true;
			view.render();

			var requestData = {
				nonce: script_data.get_certificates_nonce,
			};

			if ( view.userId ) {
				requestData.customerId = view.userId;
				requestData.user_id = view.userId;
			}

			$.post( script_data.ajaxurl + '?action=sst_get_certificates', requestData )
				.then( function( response ) {
					view.isLoading = false;
					if ( response.success && response.data ) {
						view.model.set( 'certificates', response.data );
					} else {
						view.render();
					}
				} )
				.fail( function() {
					view.isLoading = false;
					view.render();
				} );
		},
		fireChangeHook: function() {
			var selected = this.model.get( 'selected' );
			wp.hooks.doAction( 'sst_certificate_changed', selected );
		},
		block: function() {
			$( this.el ).block( {
				message: null,
				overlayCSS: {
					background: '#fff',
					opacity: 0.6
				}
			} );
		},
		unblock: function() {
			$( this.el ).unblock();
		},
		updateSelected: function( event ) {
			this.model.set( 'selected', $( event.target ).val() );
		},
		onDeleteRow: function( event ) {
			var view           = event.data.view,
				model          = view.model,
				certificates   = _.indexBy( model.get( 'certificates' ), 'CertificateID' ),
				selected       = view.model.get( 'selected' ),
				certificate_id = $( this ).closest( 'tr' ).data( 'id' );

			event.preventDefault();

			if ( ! confirm( script_data.strings.delete_certificate ) ) {
				return;
			}

			view.block();

			// Delete certificate via ajax call
			var requestData = {
				nonce: script_data.delete_certificate_nonce,
				certificate_id: certificate_id,
			};

			if ( view.userId ) {
				requestData.user_id = view.userId;
			}

			$.post( script_data.ajaxurl + '?action=sst_delete_certificate', requestData )
				.then( function( response ) {
					if ( ! response.success ) {
						throw new Error( response.data );
					}

					// Re-render
					if ( selected == certificate_id ) {
						view.model.set( 'selected', '' );
					}

					view.model.set( 'certificates', response.data.certificates );
				} )
				.fail( function() {
					alert( script_data.strings.delete_failed );
				} )
				.always( function() {
					view.unblock();
				} );
		},
		onViewCertificate: function( event ) {
			var view           = event.data.view,
				model          = view.model,
				certificates   = _.indexBy( model.get( 'certificates' ), 'CertificateID' ),
				certificate_id = $( this ).closest( 'tr' ).data( 'id' ),
				certificate    = certificates[ certificate_id ];

			event.preventDefault();

			if ( certificate ) {
				$( this ).SSTBackboneModal( {
					template: 'sst-modal-view-certificate',
					variable: certificate,
				} );
			}
		},
		onAddCertificate: function( event ) {
			event.preventDefault();

			var view = event.data.view;

			SST_Add_Certificate_Modal.open( {
				address: view.getBillingAddress(),
				onAddCertificate: view.addCertificateHandler.bind( view ),
			} );
		},
		onRefreshCertificates: function( event ) {
			var view = event.data.view;
			event.preventDefault();

			view.block();

			var requestData = {
				nonce: script_data.refresh_certificates_nonce,
			};

			if ( view.userId ) {
				requestData.user_id = view.userId;
			}

			$.post( script_data.ajaxurl + '?action=sst_refresh_certificates', requestData )
				.then( function( response ) {
					if ( ! response.success ) {
						throw new Error( response.data );
					}
					if ( response.data && response.data.certificates ) {
						view.model.set( 'certificates', response.data.certificates );
					} else {
						window.location.reload();
					}
				} )
				.fail( function() {
					alert( script_data.strings.refresh_failed || 'Failed to refresh certificates' );
				} )
				.always( function() {
					view.unblock();
				} );
		},
		getBillingAddress: function() {
			var address = script_data.billing_address;
			var addressFields = this.addressFields;

			// Override default billing address with values from address fields
			for ( var key in address ) {
				if ( address.hasOwnProperty( key ) && key in addressFields ) {
					var fieldName = addressFields[ key ];
					var $field = $( '[name="' + fieldName + '"]' );
					if ( $field.length ) {
						address[ key ] = $field.val();
					}
				}
			}

			return address;
		},
		addCertificateHandler: function( posted_data ) {
			var view = this;

			view.block();

			var requestData = {
				nonce: SST_Add_Certificate_Data.nonce,
				address: view.getBillingAddress(),
				...posted_data,
			};

			if ( view.userId ) {
				requestData.user_id = view.userId;
			}

			// Add certificate via ajax call
			$.post( script_data.ajaxurl + '?action=sst_add_certificate', requestData )
				.then( function( response ) {
					if ( ! response.success ) {
						var errorMessage = response.data
							? script_data.strings.add_failed + ': ' + response.data
							: script_data.strings.add_failed;

						alert( errorMessage );
						return;
					}

					// Re-render
					view.model.set( 'selected', response.data.certificate_id );
					view.model.set( 'certificates', response.data.certificates );
				} )
				.fail( function( jqXHR ) {
					var errorMessage = script_data.strings.add_failed;

					if ( jqXHR.responseJSON && jqXHR.responseJSON.data ) {
						errorMessage += ': ' + jqXHR.responseJSON.data;
					} else if ( jqXHR.responseText ) {
						errorMessage += ': ' + jqXHR.responseText;
					}

					alert( errorMessage );
				} )
				.always( function() {
					view.unblock();
				} );
		},
	} );

	function renderCertificateTable(options) {
		options = options || {};

		var defaults = {
			selector: '#sst-certificates',
			address_fields: {
				first_name: 'billing_first_name',
				last_name: 'billing_last_name',
				address_1: 'billing_address_1',
				address_2: 'billing_address_2',
				country: 'billing_country',
				city: 'billing_city',
				state: 'billing_state',
				postcode: 'billing_postcode',
			},
			certificates: {},
			selected: '',
			user_id: 0,
			fetch_on_load: false,
		};

		for ( var key in defaults ) {
			if ( defaults.hasOwnProperty( key ) && !options.hasOwnProperty( key ) ) {
				options[ key ] = defaults[ key ];
			}
		}

		options.model = new CertificateTable( {
			certificates: options.certificates,
			selected: options.selected
		} );
		options.el = $( options.selector ).find( 'tbody' );

		var view = new CertificateTableView( options );
		view.render();
	}

	renderCertificateTable( {
		user_id: script_data.user_id,
		certificates: script_data.certificates,
		fetch_on_load: script_data.fetch_on_load,
	} );
} );
