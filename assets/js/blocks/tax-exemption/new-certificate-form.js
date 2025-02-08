/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';
import { useSelect } from '@wordpress/data';
import { VALIDATION_STORE_KEY } from '@woocommerce/block-data';
import { CheckboxControl } from '@woocommerce/blocks-checkout';
import {
	RadioControl,
	ValidatedTextInput,
} from '@woocommerce/blocks-components';
import { Select } from '@woocommerce/components';
import { StateInput } from '@woocommerce/components';
import { ALLOWED_STATES } from '@woocommerce/settings';

/**
 * Internal dependencies
 */
import { BUSINESS_TYPE_OPTIONS, EXEMPTION_REASON_OPTIONS } from './constants';
import { useExtensionState } from './use-extension-state';

const DEFAULT_CERTIFICATE = {
	SinglePurchase: false,
	ExemptState: '',
	TaxType: 'StateIssued',
	StateOfIssue: '',
	IDNumber: '',
	PurchaserBusinessType: '',
	PurchaserBusinessTypeOtherValue: '',
	PurchaserExemptionReason: '',
	PurchaserExemptionReasonOtherValue: '',
};

export const NewCertificateForm = () => {
	const [ certificate, setCertificate ] = useExtensionState(
		'certificate',
		DEFAULT_CERTIFICATE
	);

	const updateCertificate = ( key, value ) => {
		setCertificate( {
			...certificate,
			[ key ]: value,
		} );
	};

	const { businessTypeOtherError, exemptionReasonOtherError } = useSelect(
		( select ) => {
			const store = select( VALIDATION_STORE_KEY );
			return {
				businessTypeOtherError: store.getValidationError(
					'purchase_business_type_other_value_error'
				),
				exemptionReasonOtherError: store.getValidationError(
					'purchaser_exemption_reason_value_error'
				),
			};
		}
	);

	const showError = ( error ) => !! error?.message && ! error?.hidden;

	return (
		<div className="sst-tax-exemption__form">
			{ /* TODO: Enable customization in block editor */ }
			<p class="sst-tax-exemption__form-disclaimer">
				<strong>{ __( 'WARNING:', 'simple-sales-tax' ) }</strong>
				{ __(
					'You are responsible for knowing if you qualify to claim exemption from tax in the state that is due tax on this sale. You  will be held liable for any tax and interest, as well as civil and criminal penalties imposed by the member state, if you are not eligible to claim this exemption.',
					'simple-sales-tax'
				) }
			</p>
			<CheckboxControl
				id="single_purchase"
				checked={ certificate.SinglePurchase }
				onChange={ ( value ) =>
					updateCertificate( 'SinglePurchase', value )
				}
			>
				{ __(
					'This is a single-purchase exemption certificate',
					'simple-sales-tax'
				) }
			</CheckboxControl>
			<StateInput
				id="exempt_state"
				label={ __( 'Exempt state', 'simple-sales-tax' ) }
				country="US"
				states={ ALLOWED_STATES }
				value={ certificate.ExemptState }
				onChange={ ( value ) =>
					updateCertificate( 'ExemptState', value )
				}
				required
			/>
			<div
				className="sst-radio-group"
				role="radiogroup"
				id="tax_type"
				ariaLabelledBy="tax_type_label"
			>
				<label id="tax_type_label">
					{ __( 'Tax ID type', 'simple-sales-tax' ) }
				</label>
				<RadioControl
					id="tax_type"
					selected={ certificate.TaxType }
					options={ [
						{
							label: __(
								'State Issued Exemption ID or Drivers License',
								'simple-sales-tax'
							),
							value: 'StateIssued',
						},
						{
							label: __(
								'Federal Employer ID',
								'simple-sales-tax'
							),
							value: 'FEIN',
						},
					] }
					onChange={ ( value ) =>
						updateCertificate( 'TaxType', value )
					}
				/>
			</div>
			{ certificate.TaxType === 'StateIssued' && (
				<StateInput
					id="state_of_issue"
					label={ __( 'Issuing state', 'simple-sales-tax' ) }
					country="US"
					states={ ALLOWED_STATES }
					value={ certificate.StateOfIssue }
					onChange={ ( value ) =>
						updateCertificate( 'StateOfIssue', value )
					}
					required
				/>
			) }
			<ValidatedTextInput
				id="id_number"
				errorId="id_number_error"
				label={ __( 'Tax ID', 'simple-sales-tax' ) }
				required
				type="text"
				value={ certificate.IDNumber }
				onChange={ ( value ) => updateCertificate( 'IDNumber', value ) }
			/>
			<Select
				id="purchaser_business_type"
				label={ __( 'Business type', 'simple-sales-tax' ) }
				onChange={ ( value ) =>
					updateCertificate( 'PurchaserBusinessType', value )
				}
				options={ BUSINESS_TYPE_OPTIONS }
				value={ certificate.PurchaserBusinessType }
				required
			/>
			{ certificate.PurchaserBusinessType === 'Other' && (
				<ValidatedTextInput
					id="purchase_business_type_other_value"
					errorId="purchase_business_type_other_value_error"
					label={ __(
						'Explain the nature of your business',
						'simple-sales-tax'
					) }
					required
					type="text"
					value={ certificate.PurchaserBusinessTypeOtherValue }
					onChange={ ( value ) =>
						updateCertificate(
							'PurchaserBusinessTypeOtherValue',
							value
						)
					}
					showError={ showError( businessTypeOtherError ) }
					errorMessage={ __(
						'Please explain the nature of your business',
						'simple-sales-tax'
					) }
				/>
			) }
			<Select
				id="purchaser_exemption_reason"
				label={ __( 'Reason for exemption', 'simple-sales-tax' ) }
				onChange={ ( value ) =>
					updateCertificate( 'PurchaserExemptionReason', value )
				}
				options={ EXEMPTION_REASON_OPTIONS }
				value={ certificate.PurchaserExemptionReason }
				required
			/>
			{ certificate.PurchaserExemptionReason === 'Other' && (
				<ValidatedTextInput
					id="purchaser_exemption_reason_value"
					errorId="purchaser_exemption_reason_value_error"
					label={ __( 'Please explain', 'simple-sales-tax' ) }
					required
					type="text"
					value={ certificate.PurchaserExemptionReasonOtherValue }
					onChange={ ( value ) =>
						updateCertificate(
							'PurchaserExemptionReasonOtherValue',
							value
						)
					}
					showError={ showError( exemptionReasonOtherError ) }
					errorMessage={ __(
						'Please explain why you are tax exempt',
						'simple-sales-tax'
					) }
				/>
			) }
		</div>
	);
};
