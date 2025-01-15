<?php
/**
 * Template for tax exemption certificate form. You may override this template by copying it to `THEME_PATH/sst/html-certificate-form.php`.
 *
 * @version 8.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$post_data = $_POST['certificate'] ?? [];

?>
<p>
	<strong><?php esc_html_e( 'WARNING:', 'simple-sales-tax' ); ?></strong>
	<?php
	esc_html_e(
		'You are responsible for knowing if you qualify to claim exemption from tax in the state that is due tax on this sale. You  will be held liable for any tax and interest, as well as civil and criminal penalties imposed by the member state, if you are not eligible to claim this exemption.',
		'simple-sales-tax'
	);
	?>
</p>

<?php if ( ! empty( $args['allow_single' ] ) ) : ?>
	<?php
	woocommerce_form_field(
		'certificate[SinglePurchase]',
		array(
			'type'        => 'checkbox',
			'label'       => esc_html__(
				'This is a single-purchase exemption certificate',
				'simple-sales-tax'
			),
			'id'          => 'single_purchase',
			'class'       => array( 'sst-form-row' ),
			'input_class' => array( 'sst-input' ),
		),
		$post_data['SinglePurchase'] ?? null
	);
	?>
<?php endif; ?>

<?php
woocommerce_form_field(
	'certificate[ExemptState]',
	array(
		'type'        => 'state',
		'country'     => 'US',
		'label'       => esc_html__( 'Where does this exemption apply?', 'simple-sales-tax' ),
		'required'    => true,
		'id'          => 'exempt_state',
		'class'       => array( 'sst-form-row' ),
		'input_class' => array( 'sst-input' ),
	),
	$post_data['ExemptState'] ?? null
);
?>

<?php
woocommerce_form_field(
	'certificate[TaxType]',
	array(
		'type'        => 'select',
		'label'       => esc_html__( 'Tax ID type', 'simple-sales-tax' ),
		'required'    => true,
		'id'          => 'tax_type',
		'class'       => array( 'sst-form-row' ),
		'input_class' => array( 'sst-input' ),
		'options'     => array(
				''            => esc_html__( 'Select one', 'simple-sales-tax' ),
				'FEIN'        => esc_html__( 'Federal Employer ID', 'simple-sales-tax' ),
				'StateIssued' => esc_html__(
						'State Issued Exemption ID or Drivers License',
						'simple-sales-tax'
				),
		),
	),
	$post_data['TaxType'] ?? null
);
?>

<?php
woocommerce_form_field(
	'certificate[IDNumber]',
	array(
		'type'        => 'text',
		'label'       => esc_html__( 'Tax ID', 'simple-sales-tax' ),
		'placeholder' => '123-4567-89',
		'required'    => true,
		'id'          => 'id_number',
		'class'       => array( 'sst-form-row' ),
		'input_class' => array( 'sst-input' ),
	),
	$post_data['IDNumber'] ?? null
);
?>

<?php
woocommerce_form_field(
	'certificate[StateOfIssue]',
	array(
		'type'        => 'state',
		'country'     => 'US',
		'label'       => esc_html__( 'ID issued by...', 'simple-sales-tax' ),
		'placeholder' => esc_html__( 'Select one', 'simple-sales-tax' ),
		'required'    => true,
		'id'          => 'state_of_issue',
		'class'       => array( 'sst-form-row', 'sst-hidden-field' ),
		'input_class' => array( 'sst-input' ),
	),
	$post_data['StateOfIssue'] ?? null
);
?>

<?php
woocommerce_form_field(
	'certificate[PurchaserBusinessType]',
	array(
		'type'        => 'select',
		'label'       => esc_html__(
			'Business type',
			'simple-sales-tax'
		),
		'required'    => true,
		'id'          => 'purchaser_business_type',
		'class'       => array( 'sst-form-row' ),
		'input_class' => array( 'sst-input' ),
		'options'     => array(
			''                                        => esc_html__(
				'Select one',
				'simple-sales-tax'
			),
			'AccommodationAndFoodServices'            => esc_html__(
				'Accommodation And Food Services',
				'simple-sales-tax'
			),
			'Agricultural_Forestry_Fishing_Hunting'   => esc_html__(
				'Agricultural/Forestry/Fishing/Hunting',
				'simple-sales-tax'
			),
			'Construction'                            => esc_html__(
				'Construction',
				'simple-sales-tax'
			),
			'FinanceAndInsurance'                     => esc_html__(
				'Finance or Insurance',
				'simple-sales-tax'
			),
			'Information_PublishingAndCommunications' => esc_html__(
				'Information Publishing and Communications',
				'simple-sales-tax'
			),
			'Manufacturing'                           => esc_html__(
				'Manufacturing',
				'simple-sales-tax'
			),
			'Mining'                                  => esc_html__(
				'Mining',
				'simple-sales-tax'
			),
			'RealEstate'                              => esc_html__(
				'Real Estate',
				'simple-sales-tax'
			),
			'RentalAndLeasing'                        => esc_html__(
				'Rental and Leasing',
				'simple-sales-tax'
			),
			'RetailTrade'                             => esc_html__(
				'Retail Trade',
				'simple-sales-tax'
			),
			'TransportationAndWarehousing'            => esc_html__(
				'Transportation and Warehousing',
				'simple-sales-tax'
			),
			'Utilities'                               => esc_html__(
				'Utilities',
				'simple-sales-tax'
			),
			'WholesaleTrade'                          => esc_html__(
				'Wholesale Trade',
				'simple-sales-tax'
			),
			'BusinessServices'                        => esc_html__(
				'Business Services',
				'simple-sales-tax'
			),
			'ProfessionalServices'                    => esc_html__(
				'Professional Services',
				'simple-sales-tax'
			),
			'EducationAndHealthCareServices'          => esc_html__(
				'Education and Health Care Services',
				'simple-sales-tax'
			),
			'NonprofitOrganization'                   => esc_html__(
				'Nonprofit Organization',
				'simple-sales-tax'
			),
			'Government'                              => esc_html__(
				'Government',
				'simple-sales-tax'
			),
			'NotABusiness'                            => esc_html__(
				'Not a Business',
				'simple-sales-tax'
			),
			'Other'                                   => esc_html__(
				'Other',
				'simple-sales-tax'
			),
		),
	),
	$post_data['PurchaserBusinessType'] ?? null
);
?>

<?php
woocommerce_form_field(
	'certificate[PurchaserBusinessTypeOtherValue]',
	array(
		'type'        => 'text',
		'label'       => esc_html__( 'Please explain', 'simple-sales-tax' ),
		'placeholder' => esc_html__( 'Explain the nature of your business.', 'simple-sales-tax' ),
		'required'    => true,
		'id'          => 'purchase_business_type_other_value',
		'class'       => array( 'sst-hidden-field', 'sst-form-row' ),
		'input_class' => array( 'sst-input' ),
	),
	$post_data['PurchaserBusinessTypeOtherValue'] ?? null,
);
?>

<?php
woocommerce_form_field(
	'certificate[PurchaserExemptionReason]',
	array(
		'type'        => 'select',
		'label'       => esc_html__( 'Reason for exemption', 'simple-sales-tax' ),
		'required'    => true,
		'id'          => 'purchaser_exemption_reason',
		'class'       => array( 'sst-form-row' ),
		'input_class' => array( 'sst-input' ),
		'options'     => array(
			''                                    => esc_html__( 'Select one', 'simple-sales-tax' ),
			'FederalGovernmentDepartment'         => esc_html__(
				'Federal Government Department',
				'simple-sales-tax'
			),
			'StateOrLocalGovernmentName'          => esc_html__(
				'State Or Local Government',
				'simple-sales-tax'
			),
			'TribalGovernmentName'                => esc_html__(
				'Tribal Government',
				'simple-sales-tax'
			),
			'ForeignDiplomat'                     => esc_html__(
				'Foreign Diplomat',
				'simple-sales-tax'
			),
			'CharitableOrganization'              => esc_html__(
				'Charitable Organization',
				'simple-sales-tax'
			),
			'ReligiousOrEducationalOrganization'  => esc_html__(
				'Religious or Educational Organization',
				'simple-sales-tax'
			),
			'Resale'                              => esc_html__(
				'Resale',
				'simple-sales-tax'
			),
			'AgriculturalProduction'              => esc_html__(
				'Agricultural Production',
				'simple-sales-tax'
			),
			'IndustrialProductionOrManufacturing' => esc_html__(
				'Industrial Production or Manufacturing',
				'simple-sales-tax'
			),
			'DirectPayPermit'                     => esc_html__(
				'Direct Pay Permit',
				'simple-sales-tax'
			),
			'DirectMail'                          => esc_html__(
				'Direct Mail',
				'simple-sales-tax'
			),
			'Other'                               => esc_html__(
				'Other',
				'simple-sales-tax'
			),
		),
	),
	$post_data['PurchaserExemptionReason'] ?? null,
);
?>

<?php
// Note: This field is required when "Other" is selected as the exemption
// reason. See certificate-form.js.
woocommerce_form_field(
	'certificate[PurchaserExemptionReasonOtherValue]',
	array(
		'type'        => 'text',
		'label'       => esc_html__( 'Please explain', 'simple-sales-tax' ),
		'required'    => false,
		'id'          => 'purchaser_exemption_reason_value',
		'class'       => array( 'sst-hidden-field', 'sst-form-row' ),
		'input_class' => array( 'sst-input' ),
	),
	$post_data['PurchaserExemptionReasonOtherValue'] ?? null,
);
?>
