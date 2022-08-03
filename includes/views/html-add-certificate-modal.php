<?php
/**
 * Add certificate modal template.
 *
 * @version 7.0.1
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

?>
<script type="text/html" id="tmpl-sst-modal-add-certificate">
    <div class="wc-backbone-modal">
        <div class="wc-backbone-modal-content sst-certificate-modal-content woocommerce">
            <section class="wc-backbone-modal-main" role="main">
                <header class="wc-backbone-modal-header">
                    <h1><?php esc_html_e( 'Add certificate', 'simple-sales-tax' ); ?></h1>
                    <button class="modal-close modal-close-link dashicons dashicons-no-alt">
                        <span class="screen-reader-text">
                            <?php esc_html_e( 'Close modal panel', 'simple-sales-tax' ); ?>
                        </span>
                    </button>
                </header>
                <article>
                    <form action="" method="post">
                        <?php
                        printf(
                            '<strong>%s</strong> %s',
                            esc_html__( 'Warning', 'simple-sales-tax' ),
                            esc_html__(
                                'You are responsible for knowing if you qualify to claim exemption from tax in the state that is due tax on this sale. You  will be held liable for any tax and interest, as well as civil and criminal penalties imposed by the member state, if you are not eligible to claim this exemption.',
                                'simple-sales-tax'
                            )
                        );
                        ?>

                        <?php
                        woocommerce_form_field(
                            'ExemptState',
                            array(
                                'type'     => 'state',
                                'country'  => 'US',
                                'label'    => esc_html__( 'Where does this exemption apply?', 'simple-sales-tax' ),
                                'required' => true,
                                'class'    => array( 'sst-input' ),
                            )
                        );
                        ?>

                        <?php
                        woocommerce_form_field(
                            'TaxType',
                            array(
                                'type'     => 'select',
                                'label'    => esc_html__( 'Tax ID Type', 'simple-sales-tax' ),
                                'required' => true,
                                'class'    => array( 'sst-input' ),
                                'options'  => array(
                                    ''            => esc_html__( 'Select one', 'simple-sales-tax' ),
                                    'FEIN'        => esc_html__( 'Federal Employer ID', 'simple-sales-tax' ),
                                    'StateIssued' => esc_html__(
                                        'State Issued Exemption ID or Drivers License',
                                        'simple-sales-tax'
                                    ),
                                ),
                            )
                        );
                        ?>

                        <?php
                        woocommerce_form_field(
                            'IDNumber',
                            array(
                                'type'        => 'text',
                                'label'       => esc_html__( 'Tax ID', 'simple-sales-tax' ),
                                'placeholder' => '123-4567-89',
                                'required'    => true,
                                'class'       => array( 'sst-input' ),
                            )
                        );
                        ?>

                        <?php
                        woocommerce_form_field(
                            'StateOfIssue',
                            array(
                                'type'        => 'state',
                                'label'       => esc_html__( 'ID issued by...', 'simple-sales-tax' ),
                                'placeholder' => esc_html__( 'Select if your ID is state issued.', 'simple-sales-tax' ),
                                'id'          => 'issuing-state',
                                'class'       => array( 'sst-hidden-field', 'sst-input' ),
                            )
                        );
                        ?>

                        <?php
                        woocommerce_form_field(
                            'PurchaserBusinessType',
                            array(
                                'type'     => 'select',
                                'label'    => esc_html__( 'Business Type', 'simple-sales-tax' ),
                                'required' => true,
                                'class'    => array( 'sst-input' ),
                                'options'  => array(
                                    ''                 => esc_html__(
                                        'Select one',
                                        'simple-sales-tax'
                                    ),
                                    'AccommodationAndFoodServices' => esc_html__(
                                        'Accommodation And Food Services',
                                        'simple-sales-tax'
                                    ),
                                    'Agricultural_Forestry_Fishing_Hunting' => esc_html__(
                                        'Agricultural/Forestry/Fishing/Hunting',
                                        'simple-sales-tax'
                                    ),
                                    'Construction'     => esc_html__(
                                        'Construction',
                                        'simple-sales-tax'
                                    ),
                                    'FinanceAndInsurance' => esc_html__(
                                        'Finance or Insurance',
                                        'simple-sales-tax'
                                    ),
                                    'Information_PublishingAndCommunications' => esc_html__(
                                        'Information Publishing and Communications',
                                        'simple-sales-tax'
                                    ),
                                    'Manufacturing'    => esc_html__(
                                        'Manufacturing',
                                        'simple-sales-tax'
                                    ),
                                    'Mining'           => esc_html__( 'Mining', 'simple-sales-tax' ),
                                    'RealEstate'       => esc_html__(
                                        'Real Estate',
                                        'simple-sales-tax'
                                    ),
                                    'RentalAndLeasing' => esc_html__(
                                        'Rental and Leasing',
                                        'simple-sales-tax'
                                    ),
                                    'RetailTrade'      => esc_html__(
                                        'Retail Trade',
                                        'simple-sales-tax'
                                    ),
                                    'TransportationAndWarehousing' => esc_html__(
                                        'Transportation and Warehousing',
                                        'simple-sales-tax'
                                    ),
                                    'Utilities'        => esc_html__(
                                        'Utilities',
                                        'simple-sales-tax'
                                    ),
                                    'WholesaleTrade'   => esc_html__(
                                        'Wholesale Trade',
                                        'simple-sales-tax'
                                    ),
                                    'BusinessServices' => esc_html__(
                                        'Business Services',
                                        'simple-sales-tax'
                                    ),
                                    'ProfessionalServices' => esc_html__(
                                        'Professional Services',
                                        'simple-sales-tax'
                                    ),
                                    'EducationAndHealthCareServices' => esc_html__(
                                        'Education and Health Care Services',
                                        'simple-sales-tax'
                                    ),
                                    'NonprofitOrganization' => esc_html__(
                                        'Nonprofit Organization',
                                        'simple-sales-tax'
                                    ),
                                    'Government'       => esc_html__(
                                        'Government',
                                        'simple-sales-tax'
                                    ),
                                    'NotABusiness'     => esc_html__(
                                        'Not a Business',
                                        'simple-sales-tax'
                                    ),
                                    'Other'            => esc_html__( 'Other', 'simple-sales-tax' ),
                                ),
                            )
                        );
                        ?>

                        <?php
                        woocommerce_form_field(
                            'PurchaserBusinessTypeOtherValue',
                            array(
                                'type'        => 'text',
                                'label'       => esc_html__( 'Please explain', 'simple-sales-tax' ),
                                'placeholder' => esc_html__( 'Explain the nature of your business.', 'simple-sales-tax' ),
                                'id'          => 'business-type-other',
                                'class'       => array( 'sst-hidden-field', 'sst-input' ),
                            )
                        );
                        ?>

                        <?php
                        woocommerce_form_field(
                            'PurchaserExemptionReason',
                            array(
                                'type'     => 'select',
                                'label'    => esc_html__( 'Reason for Exemption', 'simple-sales-tax' ),
                                'required' => true,
                                'class'    => array( 'sst-input' ),
                                'options'  => array(
                                    ''                => esc_html__( 'Select one', 'simple-sales-tax' ),
                                    'FederalGovernmentDepartment' => esc_html__(
                                        'Federal Government Department',
                                        'simple-sales-tax'
                                    ),
                                    'StateOrLocalGovernmentName' => esc_html__(
                                        'State Or Local Government',
                                        'simple-sales-tax'
                                    ),
                                    'TribalGovernmentName' => esc_html__(
                                        'Tribal Government',
                                        'simple-sales-tax'
                                    ),
                                    'ForeignDiplomat' => esc_html__(
                                        'Foreign Diplomat',
                                        'simple-sales-tax'
                                    ),
                                    'CharitableOrganization' => esc_html__(
                                        'Charitable Organization',
                                        'simple-sales-tax'
                                    ),
                                    'ReligiousOrEducationalOrganization' => esc_html__(
                                        'Religious or Educational Organization',
                                        'simple-sales-tax'
                                    ),
                                    'Resale'          => esc_html__( 'Resale', 'simple-sales-tax' ),
                                    'AgriculturalProduction' => esc_html__(
                                        'Agricultural Production',
                                        'simple-sales-tax'
                                    ),
                                    'IndustrialProductionOrManufacturing' => esc_html__(
                                        'Industrial Production or Manufacturing',
                                        'simple-sales-tax'
                                    ),
                                    'DirectPayPermit' => esc_html__(
                                        'Direct Pay Permit',
                                        'simple-sales-tax'
                                    ),
                                    'DirectMail'      => esc_html__( 'Direct Mail', 'simple-sales-tax' ),
                                    'Other'           => esc_html__( 'Other', 'simple-sales-tax' ),
                                ),
                            )
                        );
                        ?>

                        <?php
                        woocommerce_form_field(
                            'PurchaserExemptionReasonValue',
                            array(
                                'type'  => 'text',
                                'label' => esc_html__( 'Please explain', 'simple-sales-tax' ),
                                'id'    => 'exempt-other-reason',
                                'class' => array( 'sst-hidden-field', 'sst-input' ),
                            )
                        );
                        ?>

                        <input type="hidden" name="CertificateID" value="{{{ data.CertificateID }}}">
                    </form>
                </article>
                <footer>
                    <div class="inner">
                        <button id="btn-ok" class="button alt">
                            <?php esc_html_e( 'Add certificate', 'simple-sales-tax' ); ?>
                        </button>
                    </div>
                </footer>
            </section>
        </div>
    </div>
    <div class="wc-backbone-modal-backdrop modal-close"></div>
</script>
