/// <reference types="cypress" />

import products from '../../fixtures/products.json';
import { getCartTestCases } from '../../support/helpers';

describe('Create order', () => {
  const testCases = getCartTestCases();

  testCases.forEach(({ label, useClassicCart, cartUrl, checkoutUrl }) => {
    describe(label, () => {
      before(() => {
        cy.loginAsAdmin();
        cy.useClassicCart(useClassicCart);
      });

      beforeEach(() => {
        cy.loginAsAdmin();
        cy.resetCart();
      });

      const createOrder = () => {
        cy.addProductToCart(products.simpleProduct.id);
        cy.visit(cartUrl);
        cy.selectShippingMethod('Flat rate');
        cy.assertTaxTotal(2.15); // Ensures new shipping method is selected
        cy.visit(checkoutUrl);
        cy.findByRole('button', {name: /Place order/i}, {timeout: 15000}).click();
        cy.url({timeout: 60000}).should('match', /\/(\d+)\//);

        return cy.url().then((url) => {
          return /\/(\d+)\//.exec(url)[1];
        });
      };

      const editOrder = (orderId) => {
        cy.visit(`/wp-admin/admin.php?page=wc-orders&action=edit&id=${orderId}`);
      };

      const round = (number) => {
        return Math.round((number + Number.EPSILON) * 100) / 100;
      };

      const getOrderSummaryTotals = () => {
        const totals = {
          'Subtotal': 'subtotal',
          'Shipping': 'shipping',
          'Sales Tax': 'salesTax',
          'Total': 'total',
        };

        Object.entries(totals).forEach(([label, alias]) => {
          cy.contains('th', `${label}:`)
            .closest('tr')
            .find('td')
            .invoke('text')
            .then((text) => {
              cy.wrap(parseFloat(text.replace(/[^\d.]/, ''))).as(alias);
            });
        });
      };

      it('saves correct tax total for order', () => {
        createOrder().then(function() {
          getOrderSummaryTotals();

          cy.then(function() {
            expect(this.total).to.eq(round(this.subtotal + this.shipping + this.salesTax));
          });
        });
      });

      it('captures Processing orders in TaxCloud if "Capture orders immediately" is enabled', () => {
        cy.goToSettingsPage();
        cy.get('#woocommerce_wootax_capture_immediately').check();
        cy.findByRole('button', {name: 'Save changes'}).click({ force: true });

        createOrder().then((orderId) => {
          editOrder(orderId);
          cy.contains('TaxCloud Status')
            .closest('div')
            .contains('Captured')
            .should('exist');
        });
      });

      it('does not capture Processing orders in TaxCloud "Capture orders immediately" is disabled', () => {
        cy.goToSettingsPage();
        cy.get('#woocommerce_wootax_capture_immediately').uncheck();
        cy.findByRole('button', {name: 'Save changes'}).click({ force: true });

        createOrder().then((orderId) => {
          editOrder(orderId);
          cy.contains('TaxCloud Status')
            .closest('div')
            .contains('Pending')
            .should('exist');
        });
      });
    });
  });
});
