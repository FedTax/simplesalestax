/// <reference types="cypress" />

import products from '../../fixtures/products.json';
import { getCartTestCases } from '../../support/helpers';

describe('Basic calculations', () => {
  const testCases = getCartTestCases();

  testCases.forEach(({ label, useClassicCart, cartUrl, checkoutUrl }) => {
    describe(label, () => {
      before(() => {
        cy.loginAsAdmin();
        cy.useClassicCart(useClassicCart);
        cy.goToSettingsPage();

        if (useClassicCart) {
          cy.get('#woocommerce_wootax_show_zero_tax').select('Yes');
          cy.findByRole('button', {name: 'Save changes'}).click({ force: true });
        }
      });

      beforeEach(() => {
        cy.loginAsAdmin();
        cy.resetCart();
      });

      it('calculates tax correctly for simple products', () => {
        cy.addProductToCart(products.simpleProduct.id);
        cy.visit(cartUrl);
        cy.selectShippingMethod('Free shipping');
        cy.assertTaxTotal(products.simpleProduct.expectedTax);
      });

      const variableProduct = products.variableProduct;

      variableProduct.variations.forEach((variation) => {
        it(`calculates tax correctly for ${variableProduct.name} variation ${variation.name}`, () => {
          cy.addProductToCart(variation.id);
          cy.visit(cartUrl);
          cy.selectShippingMethod('Free shipping');
          cy.assertTaxTotal(variation.expectedTax);
        });
      });

      it('does not calculate tax for downloadable products', () => {
        cy.addProductToCart(products.downloadableProduct.id);
        cy.visit(cartUrl);

        if (useClassicCart) {
          cy.assertTaxTotal(products.downloadableProduct.expectedTax);
        } else {
          cy.findByText('Sales Tax').should('not.exist');
        }
      });

      it('uses correct origin address for multi-origin products when calculating tax', () => {
        cy.addProductToCart(products.multiOriginProduct.id);
        cy.visit(cartUrl);
        cy.assertTaxTotal(0.30)

        cy.updateShippingAddress({
          country: 'US',
          city: 'Altanta',
          state: 'GA',
          postcode: '30334',
        });
        cy.visit(cartUrl);
        cy.assertTaxTotal(0.27);
      });

      it('calculates tax for shipping charges with default shipping TIC', () => {
        cy.addProductToCart(products.simpleProduct.id);
        cy.visit(cartUrl);
        cy.selectShippingMethod('Free shipping');
        cy.assertTaxTotal(products.simpleProduct.expectedTax);
        cy.selectShippingMethod('Flat rate');
        cy.assertTaxTotal(2.15);
      });

      describe('when a negative fee is applied', () => {
        it('calculates tax for single taxable product', () => {
          cy.addProductToCart(products.simpleProduct.id);
          cy.visit(`${cartUrl}?add_negative_fee=yes`);
          cy.selectShippingMethod('Free shipping');

          // Negative fee amount: 19.99 * 0.1 = 1.999
          // Discount on simple product: 1.999 * (19.99 / 19.99) = 1.999
          // Expected tax: (19.99 - 1.999) * 0.08625 = 1.55
          cy.assertTaxTotal(1.55);
        });

        it('calculates tax for multiple taxable products', () => {
          cy.addProductToCart(products.simpleProduct.id);
          cy.addProductToCart(products.variableProduct.variations[0].id);
          cy.visit(`${cartUrl}?add_negative_fee=yes`);
          cy.selectShippingMethod('Free shipping');

          // Negative fee amount: 24.99 * 0.1 = 2.499
          // Discount on simple product: 2.499 * (19.99 / 24.99) = 1.999
          // Discount on variable product: 2.499 * (5 / 24.99) = 0.5
          // Expected tax: ((19.99 - 1.999) + (5 - 0.5)) * 0.08625 = 1.94
          cy.assertTaxTotal(1.94);
        });

        it('calculates tax for mix of taxable and non-taxable products', () => {
          cy.addProductToCart(products.simpleProduct.id);
          cy.addProductToCart(products.downloadableProduct.id);
          cy.visit(`${cartUrl}?add_negative_fee=yes`);
          cy.selectShippingMethod('Free shipping');

          // Negative fee amount: 29.98 * 0.1 = 2.998
          // Discount on simple product: 2.998 * (19.99 / 29.98) = 1.999
          // Expected tax: (19.99 - 1.999) * 0.08625 = 1.55
          cy.assertTaxTotal(1.55);
        });
      });

      if (useClassicCart) {
        it('does not show zero tax total if "Show Zero Tax?" is set to "No"', () => {
          cy.goToSettingsPage();
          cy.get('#woocommerce_wootax_show_zero_tax').select('No');
          cy.findByRole('button', {name: 'Save changes'}).click({ force: true });

          cy.addProductToCart(products.downloadableProduct.id);
          cy.visit(cartUrl);

          cy.findByText('Sales Tax').should('not.exist');
        });

        it('shows zero tax total if "Show Zero Tax?" is set to "Yes"', () => {
          cy.goToSettingsPage();
          cy.get('#woocommerce_wootax_show_zero_tax').select('Yes');
          cy.findByRole('button', {name: 'Save changes'}).click({ force: true });

          cy.addProductToCart(products.downloadableProduct.id);
          cy.visit(cartUrl);

          cy.findByText('Sales Tax').should('exist');
        });
      }
    });
  });
});
