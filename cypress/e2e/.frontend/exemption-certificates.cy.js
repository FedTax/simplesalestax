/// <reference types="cypress" />

import products from '../../fixtures/products.json';
import { getCartTestCases, shouldRunBlockTests } from '../../support/helpers';

const certificateTypes = [
  {
    type: 'single-purchase',
    idPattern: /single\-purchase/
  },
  {
    type: 'entity-based',
    idPattern: /^[0-9a-fA-F]{8}\b-[0-9a-fA-F]{4}\b-[0-9a-fA-F]{4}\b-[0-9a-fA-F]{4}\b-[0-9a-fA-F]{12}$/,
  },
];

const testCases = getCartTestCases();

describe('Exemption certificates', () => {
  beforeEach(() => {
    cy.loginAsAdmin();
  });

  describe('when tax exemptions are enabled', () => {
    const alwaysRequiredFields = [
      'exempt_state',
      'tax_type',
      'id_number',
      'purchaser_business_type',
      'purchaser_exemption_reason',
    ];

    const fillBasicFields = () => {
      cy.get('#exempt_state').select('New York', {force: true});
      cy.get('#tax_type').then(($el) => {
        // Block checkout uses radiogroup; classic uses select
        if ($el.is('select')) {
          cy.wrap($el).select('Federal Employer ID', {force: true});
        } else {
          cy.wrap($el).within(() => {
            cy.findByRole('radio', {name: 'Federal Employer ID'}).check();
          });
        }
      });
      cy.get('#id_number').type('123-45-6789');
      cy.get('#purchaser_business_type').select('Construction', {force: true});
      cy.get('#purchaser_exemption_reason').select('Federal Government Department', {force: true});
    };

    const validateRequiredFieldLabel = (id) => {
      cy.get(`label[for="${id}"]`)
        .invoke('text')
        .should('match', /.*\*$/);
    };

    const validateFieldHasError = (id) => {
      cy.get(`#${id}`).then(($el) => {
        if ($el.closest('.form-row').length) {
          // Classic checkout/my account
          cy.wrap($el)
            .closest('.form-row')
            .should('have.class', 'woocommerce-invalid');
        } else {
          // Block checkout
          cy.wrap($el).should('have.attr', 'aria-invalid', 'true');
        }
      });
    };

    const assertTotal = (expectedTotal) => {
      cy.findByText('Total')
        .parent()
        .should('contain', expectedTotal);
    };

    before(() => {
      cy.loginAsAdmin();
      cy.useClassicCart(true);
      cy.goToSettingsPage();
      cy.get('#woocommerce_wootax_show_exempt').select('Yes', {force: true});
      cy.get('#woocommerce_wootax_restrict_exempt').select('No', {force: true});
      cy.get('#woocommerce_wootax_show_zero_tax').select('Yes', {force: true});
      cy.findByRole('button', {name: 'Save changes'}).click({ force: true });
      cy.resetCart();
      cy.addProductToCart(products.simpleProduct.id);
    });

    describe('classic checkout', () => {
      const selectCertificate = (option) => {
        cy.get('@certificateSelect').select(option, {force: true});
        cy.wait('@updateOrderReview', {timeout: 15000});
      };

      beforeEach(() => {
        cy.intercept('POST', '/?wc-ajax=update_order_review').as('updateOrderReview');
        cy.resetCart();
        cy.addProductToCart(products.simpleProduct.id);
        cy.visit('/legacy-checkout/');
        cy.get('#certificate_id').as('certificateSelect');
      });

      it('applies a zero rate when a certificate is selected', () => {
        cy.selectShippingMethod('Free shipping');
        cy.waitForBlockedElements();

        // Tax is applied when no certificate is selected
        selectCertificate('None');
        cy.assertTaxTotal(products.simpleProduct.expectedTax);

        // Tax is zero when saved certificate is selected
        selectCertificate(2);
        assertTotal(products.simpleProduct.price);

        // Tax is zero when a new certificate is being added
        selectCertificate('Add new certificate');
        assertTotal(products.simpleProduct.price);
      });

      certificateTypes.forEach(({ type, idPattern }) => {
        it(`allows user to add a new ${type} certificate`, () => {
          selectCertificate('Add new certificate');

          // Fill out form
          if (type === 'single-purchase') {
            cy.get('#single_purchase').check();
          }

          fillBasicFields();

          // Checkout
          cy.intercept('POST', '/?wc-ajax=checkout').as('doCheckout');
          cy.findByRole('button', {name: 'Place order'}).click({force: true});
          cy.wait('@doCheckout', {timeout: 60000});

          // Edit order
          cy.url().then((url) => {
            const orderId = /\/(\d+)\//.exec(url)[1];
            cy.visit(`/wp-admin/admin.php?page=wc-orders&action=edit&id=${orderId}`);
          });

          // Confirm tax is zero
          cy.contains('Order Total:')
            .siblings('.total')
            .should('contain', products.simpleProduct.price);

          // Confirm exemption certifiate was saved
          cy.get('#exempt_cert', {timeout: 30000})
            .invoke('val')
            .should('match', idPattern);
        });
      });

      describe('validation', () => {
        beforeEach(() => {
          selectCertificate('Add new certificate');
        });

        it('requires basic fields', () => {
          cy.findByRole('button', {name: 'Place order'}).click({force: true});
          cy.waitForBlockedElements();
          cy.contains('Required exemption certificate fields are missing').should('be.visible');

          for (const field of alwaysRequiredFields) {
            validateRequiredFieldLabel(field);
            validateFieldHasError(field);
          }
        });

        it('requires state when id type is state', () => {
          fillBasicFields();

          cy.get('#state_of_issue_field').should('not.be.visible');
          cy.get('#tax_type').select('State Issued Exemption ID or Drivers License', {force: true});
          cy.get('#state_of_issue_field').should('be.visible');

          validateRequiredFieldLabel('state_of_issue');

          cy.findByRole('button', {name: 'Place order'}).click({force: true});
          cy.waitForBlockedElements();
          cy.contains('Required exemption certificate fields are missing').should('be.visible');
          validateFieldHasError('state_of_issue');
        });

        it('requires other type value when business type is other', () => {
          fillBasicFields();

          cy.get('#purchase_business_type_other_value').should('not.be.visible');
          cy.get('#purchaser_business_type').select('Other', {force: true});
          cy.get('#purchase_business_type_other_value').should('be.visible');

          validateRequiredFieldLabel('purchase_business_type_other_value');

          cy.findByRole('button', {name: 'Place order'}).click({force: true});
          cy.waitForBlockedElements();
          cy.contains('Required exemption certificate fields are missing').should('be.visible');
          validateFieldHasError('purchase_business_type_other_value');
        });

        it('requires other reason value when exemption reason is other', () => {
          fillBasicFields();

          cy.get('#purchaser_exemption_reason').select('Other', {force: true});

          validateRequiredFieldLabel('purchaser_exemption_reason_value');

          cy.findByRole('button', {name: 'Place order'}).click({force: true});
          cy.waitForBlockedElements();
          cy.contains('Required exemption certificate fields are missing').should('be.visible');
          validateFieldHasError('purchaser_exemption_reason_value');
        });
      });
    });

    describe('block checkout', () => {
      if (!shouldRunBlockTests()) {
        return;
      }

      const selectCertificate = (option) => {
        cy.get('@certificateSelect').select(option);
        cy.wait('@storeApiRequest', {timeout: 30000});
        cy.get('@placeOrder').should('be.enabled');
      };

      before(() => {
        cy.loginAsAdmin();
        cy.useClassicCart(false);
      });

      beforeEach(() => {
        cy.intercept('POST', '/wp-json/wc/store/v1/batch?_locale=user').as('storeApiRequest');
        cy.resetCart();
        cy.addProductToCart(products.simpleProduct.id);

        cy.visit('/checkout/');
        cy.findByRole('listbox', {name: 'Exemption certificate'}).as('certificateSelect');
        cy.findByRole('button', {name: /place order/i}).as('placeOrder');

        cy.get('@placeOrder', {timeout: 15000}).should('be.enabled');
        cy.selectShippingMethod('Free shipping');
      });

      it('applies a zero rate when a certificate is selected', () => {
        // Tax is zero when saved certificate is selected
        selectCertificate(2);
        cy.findByText('Sales Tax').should('not.exist');

        // Tax is zero when a new certificate is being added
        selectCertificate('Add new certificate');
        cy.findByText('Sales Tax').should('not.exist');

        // Tax is applied when no certificate is selected
        selectCertificate('');
        cy.assertTaxTotal(products.simpleProduct.expectedTax);
      });

      certificateTypes.forEach(({ type, idPattern }) => {
        it(`allows user to add a new ${type} certificate`, () => {
          selectCertificate('Add new certificate');

          // Fill out form
          if (type === 'single-purchase') {
            cy.get('#single_purchase').check();
          }

          fillBasicFields();

          // Checkout
          cy.get('@placeOrder').click();
          cy.url({ timeout: 60000 }).should('match', /\/(\d+)\//);

          // Edit order
          cy.url().then((url) => {
            const orderId = /\/(\d+)\//.exec(url)[1];
            cy.visit(`/wp-admin/admin.php?page=wc-orders&action=edit&id=${orderId}`);
          });

          // Confirm tax is zero
          cy.contains('Order Total:')
            .siblings('.total')
            .should('contain', products.simpleProduct.price);

          // Confirm exemption certifiate was saved
          cy.get('#exempt_cert', {timeout: 30000})
            .invoke('val')
            .should('match', idPattern);
        });
      });

      describe('validation', () => {
        beforeEach(() => {
          selectCertificate('Add new certificate');
        });

        it('requires basic fields', () => {
          cy.get('@placeOrder').click();

          // In block checkout, tax type is a radio and cannot be unchecked
          const fieldsToCheck = alwaysRequiredFields.filter((field) => field !== 'tax_type');

          for (const field of fieldsToCheck) {
            validateFieldHasError(field);
          }
        });

        it('requires state when id type is state', () => {
          cy.findByRole('radio', {name: /federal/i }).check();
          cy.get('#state_of_issue').should('not.exist');
          cy.findByRole('radio', {name: /state issued exemption id/i}).check();
          cy.get('#state_of_issue').should('be.visible');
          cy.get('@placeOrder').click();

          validateFieldHasError('state_of_issue');
        });

        it('requires other type value when business type is other', () => {
          cy.get('#purchase_business_type_other_value').should('not.exist');
          cy.get('#purchaser_business_type').select('Other');
          cy.get('#purchase_business_type_other_value').should('be.visible');
          cy.get('@placeOrder').click();

          validateFieldHasError('purchase_business_type_other_value');
        });

        it('requires other reason value when exemption reason is other', () => {
          cy.get('#purchaser_exemption_reason').select('Other');
          cy.get('@placeOrder').click();

          validateFieldHasError('purchaser_exemption_reason_value');
        });
      });
    });

    describe('my account', () => {
      it('renders exemption certificates menu item', () => {
        cy.visit('/my-account/');
        cy.findByRole('link', {name: 'Exemption certificates'}).click();
        cy.url().should('contain', '/my-account/exemption-certificates/');
      });

      describe('exemption certificates page', () => {
        beforeEach(() => {
          cy.visit('/my-account/exemption-certificates/');
        });

        it('allows user to add a cerificate', () => {
          cy.findByRole('link', {name: 'Add Certificate'}).click();
          fillBasicFields();
          cy.get('#sst-certificates tbody tr[data-id]').its('length').as('origNumCerts');

          cy.findByRole('button', {name: 'Add certificate'}).click();
          cy.waitForBlockedElements(60000);

          cy.get('#sst-certificates tbody tr[data-id]').its('length').then(function(newNumCerts) {
            expect(newNumCerts).to.eq(parseInt(this.origNumCerts) + 1);
          });
        });

        it('allows user to view the cerificate', () => {
          cy.get('#sst-certificates tbody tr[data-id]')
            .last()
            .findByRole('button', {name: 'View'})
            .click();
          cy.findByRole('heading', {name: 'View certificate'});
          cy.get('button.modal-close').click();
        });

        it('allows user to delete the ceriticate', () => {
          cy.get('#sst-certificates tbody tr[data-id]').its('length').as('origNumCerts');
          cy.get('#sst-certificates tbody tr[data-id]')
            .last()
            .findByRole('button', {name: 'Delete'})
            .click();
          cy.waitForBlockedElements(60000);
          cy.get('#sst-certificates tbody tr[data-id]').its('length').then(function(newNumCerts) {
            expect(newNumCerts).to.eq(this.origNumCerts - 1);
          });
        });

        describe('validation', () => {
          beforeEach(() => {
            cy.findByRole('link', {name: 'Add Certificate'}).click();
            fillBasicFields();
          });

          it('requires basic fields', () => {
            for (const field of alwaysRequiredFields) {
              // Should be labeled as required in UI
              validateRequiredFieldLabel(field);

              // Should show error if field is empty
              cy.get(`#${field}`).invoke('val').then((origVal) => {
                cy.get(`#${field}`).invoke('val', '');

                cy.findByRole('button', {name: 'Add certificate'}).click();
                cy.waitForBlockedElements(60000);
                cy.get('.blockOverlay').should('not.exist');
                validateFieldHasError(field);

                cy.get(`#${field}`).invoke('val', origVal);
              });
            }
          });

          it('requires state when id type is state', () => {
            cy.get('#state_of_issue_field').should('not.be.visible');
            cy.get('#tax_type').select('State Issued Exemption ID or Drivers License', {force: true});
            cy.get('#state_of_issue_field').should('be.visible');

            validateRequiredFieldLabel('state_of_issue');

            cy.findByRole('button', {name: 'Add certificate'}).click();
            cy.get('.blockOverlay').should('not.exist');
            validateFieldHasError('state_of_issue');
          });

          it('requires other type value when business type is other', () => {
            cy.get('#purchase_business_type_other_value').should('not.be.visible');
            cy.get('#purchaser_business_type').select('Other', {force: true});
            cy.get('#purchase_business_type_other_value').should('be.visible');

            validateRequiredFieldLabel('purchase_business_type_other_value');

            cy.findByRole('button', {name: 'Add certificate'}).click();
            cy.get('.blockOverlay').should('not.exist');
            validateFieldHasError('purchase_business_type_other_value');
          });

          it('requires other reason value when exemption reason is other', () => {
            cy.get('#purchaser_exemption_reason').select('Other', {force: true});

            validateRequiredFieldLabel('purchaser_exemption_reason_value');

            cy.findByRole('button', {name: 'Add certificate'}).click();
            cy.get('.blockOverlay').should('not.exist');
            validateFieldHasError('purchaser_exemption_reason_value');
          });
        });
      });
    });
  });

  describe('when "restrict to exempt roles" is enabled', () => {
    describe('when user has exempt role', () => {
      before(() => {
        cy.loginAsAdmin();
        cy.goToSettingsPage();
        cy.get('#woocommerce_wootax_show_exempt').select('Yes', {force: true});
        cy.get('#woocommerce_wootax_restrict_exempt').select('Yes', {force: true});
        cy.get('#woocommerce_wootax_exempt_roles').select('Administrator', {force: true});
        cy.findByRole('button', {name: 'Save changes'}).click({ force: true });
      });

      testCases.forEach(({ label, useClassicCart, checkoutUrl }) => {
        it(`renders tax exemption form on ${label} page`, () => {
          cy.useClassicCart(useClassicCart);
          cy.addProductToCart(products.simpleProduct.id);
          cy.visit(checkoutUrl);
          cy.findByRole('heading', {name: 'Tax exemption'}).should('be.visible');
        });
      });

      it('renders exemption certificates menu item in my account', () => {
        cy.visit('/my-account/');
        cy.findByRole('link', {name: 'Exemption certificates'}).should('be.visible');
      });
    });

    describe('when user does not have exempt role', () => {
      before(() => {
        cy.loginAsAdmin();
        cy.goToSettingsPage();
        cy.get('#woocommerce_wootax_show_exempt').select('Yes', {force: true});
        cy.get('#woocommerce_wootax_restrict_exempt').select('Yes', {force: true});
        cy.get('#woocommerce_wootax_exempt_roles').select('Exempt Customer', {force: true});
        cy.findByRole('button', {name: 'Save changes'}).click({ force: true });
      });

      testCases.forEach(({ label, checkoutUrl, useClassicCart }) => {
        it(`does not render exemption form on ${label} page`, () => {
          cy.useClassicCart(useClassicCart);
          cy.addProductToCart(products.simpleProduct.id);
          cy.visit(checkoutUrl);
          cy.findByRole('heading', {name: 'Tax exemption'}).should('not.exist');
        });
      });

      it('does not render exemption certificates menu item in my account', () => {
        cy.visit('/my-account/');
        cy.findByRole('link', {name: 'Exemption certificates'}).should('not.exist');
      });
    });
  });

  describe('when tax exemptions are disabled', () => {
    before(() => {
      cy.loginAsAdmin();
      cy.goToSettingsPage();
      cy.get('#woocommerce_wootax_show_exempt').select('No', {force: true});
      cy.findByRole('button', {name: 'Save changes'}).click({ force: true });
    });

    testCases.forEach(({ label, checkoutUrl, useClassicCart }) => {
      it(`does not render exemption form on ${label} page`, () => {
        cy.useClassicCart(useClassicCart);
        cy.visit(checkoutUrl);
        cy.findByRole('heading', {name: 'Tax exemption'}).should('not.exist');
      });
    });

    it('does not render exemption certificates menu item in my account', () => {
      cy.visit('/my-account/');
      cy.findByRole('link', {name: 'Exemption certificates'}).should('not.exist');
    });
  });
});
