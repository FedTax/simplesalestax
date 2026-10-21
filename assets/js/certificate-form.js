jQuery( function( $ ) {
	function toggleField( selector, show ) {
		$( selector ).toggleClass( 'sst-hidden-field', !show );
	}

	// Toggle visibility of certain form fields based on the value of a select box
	$( document ).on( 'change', '#purchaser_business_type', function() {
		toggleField(
			'#purchase_business_type_other_value_field',
			'Other' === $( this ).val()
		);
	} );

	$( document ).on( 'change', '#tax_type', function() {
		toggleField(
			'#state_of_issue_field',
			'StateIssued' === $( this ).val()
		);
	} );

	$( document ).on( 'change', '#purchaser_exemption_reason', function() {
		var value = $( this ).val();
		var showField = '' !== value;
		var $field = $( '#purchaser_exemption_reason_value' );
		var required = showField && ( 'Other' === value || 'yes' === $field.attr( 'data-require-description' ) );

		toggleField(
			'#purchaser_exemption_reason_value_field',
			showField
		);

		// Toggle required validation and revalidate
		$( '#purchaser_exemption_reason_value_field' )
			.toggleClass( 'validate-required', required )
			.find( 'input' )
			.prop( 'required', required )
			.attr( 'aria-required', required ? 'true' : 'false' )
			.trigger( 'change' );

		if ( ! showField ) {
			return;
		}

		// Set label depending on value
		var labels = {
			'FederalGovernmentDepartment': 'Dept. Name',
			'StateOrLocalGovernmentName': 'Govt. Name',
			'TribalGovernmentName': 'Tribe Name',
			'ForeignDiplomat': 'Diplomat ID',
			'CharitableOrganization': 'Organization ID',
			'ReligiousOrEducationalOrganization': 'Organization ID',
			'Resale': 'Resale ID',
			'AgriculturalProduction': 'Agricultural Prod. ID',
			'IndustrialProductionOrManufacturing': 'Production ID',
			'DirectPayPermit': 'Permit ID',
			'DirectMail': 'Direct Mail ID',
			'Other': 'Please explain'
		};

		var $label = $( 'label[for="purchaser_exemption_reason_value"]' );

		$label.text( labels[ value ] );

		if ( required ) {
			$label.append( ' <abbr class="required" title="required">*</abbr>' );
		}
	} );

	// Remove red border from invalid fields when value changes
	$( document ).on( 'input', '.sst-input', function( e ) {
		var $parent = $( this ).closest( '.form-row' );

		$parent.removeClass(
			'woocommerce-invalid woocommerce-invalid-required-field woocommerce-invalid-email woocommerce-validated'
		);
	} );

	// Enhance select boxes
	function enhanceSelectBoxes(args = {}) {
		$( 'select.sst-input' ).each( function() {
			$( this ).selectWoo( {
				minimumResultsForSearch: 10,
				allowClear: $( this ).data( 'allow_clear' ) ? true : false,
				placeholder: $( this ).data( 'placeholder' ),
				...args,
			} );
		} );
	}

	enhanceSelectBoxes();

	window.SST_Certificate_Form = {
		enhanceSelectBoxes,
	};
} );
