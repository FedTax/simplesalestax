/// <reference types="cypress" />

import products from '../../fixtures/products.json';

describe('Admin create order', () => {
  const simpleProduct = products['simpleProduct'];
  const downloadableProduct = products['downloadableProduct'];
  const expectedTaxAmounts = {
    [simpleProduct.name]: simpleProduct.expectedTax,
    [downloadableProduct.name]: downloadableProduct.expectedTax,
  };

  beforeEach(() => {
    cy.intercept('POST', '/wp-admin/admin-ajax.php', (req) => {
      if (req.body.includes('action=woocommerce_add_order_item')) {
        req.alias = 'saveProducts';
      } else if (req.body.includes('action=woocommerce_calc_line_taxes')) {
        req.alias = 'recalcRequest';
      }
    });
  });

  const createNewOrder = (orderStatus, productNames) => {
    cy.loginAsAdmin();
    cy.visit('/wp-admin/admin.php?page=wc-orders&action=new');

    setOrderStatus(orderStatus);

    cy.contains('Billing').findByRole('link', {name: 'Edit'}).click();
    cy.get('#_billing_first_name').type('John');
    cy.get('#_billing_last_name').type('Doe');
    cy.get('#_billing_address_1').type('540 Renee Drive');
    cy.get('#_billing_city').type('Bayport');
    cy.get('#_billing_state').select('NY', {force: true});
    cy.get('#_billing_postcode').type('11705');
    cy.get('#_billing_email').type('john.doe@example.com');

    cy.contains('Shipping').findByRole('link', {name: 'Edit'}).click();
    cy.findByRole('link', {name: 'Copy billing address'}).click();

    addProducts(productNames);

    cy.findByRole('button', {name: 'Recalculate'}).click();

    cy.wait('@recalcRequest', {timeout: 30000});
    cy.findByRole('button', {name: 'Create'}).click();
  };

  const setOrderStatus = (status) => {
    cy.get('#order_status').select(status, {force: true});
  };

  const addProducts = (names) => {
    cy.findByRole('button', {name: 'Add item(s)'}).click();
    cy.findByRole('button', {name: 'Add product(s)'}).click();

    names.forEach((productName) => {
      cy.get('.wc-backbone-modal')
        .find('.wc-product-search')
        .last()
        .next('.select2-container')
        .click();
      cy.get('.select2-container--open')
        .find('.select2-search__field')
        .type(productName);
      cy.contains('.select2-results__option', productName, {timeout: 20000})
        .click();
    });

    cy.get('#btn-ok').click();
    cy.wait('@saveProducts', {timeout: 20000});
  };

  it('should calculate tax, capture order when completed, and return order when refunded', () => {
    createNewOrder('Pending payment', Object.keys(expectedTaxAmounts));

    // Tax should be calculated
    cy.findByRole('columnheader', {name: 'Sales Tax'}).should('exist');
    cy.contains('tr', 'Sales Tax:').should('exist');

    Object.entries(expectedTaxAmounts).forEach(([productName, taxAmount]) => {
      cy.contains('tr', productName)
        .contains('.line_tax', taxAmount)
        .should('exist');
    });

    // Order should be captured in TaxCloud when marked Completed
    setOrderStatus('Completed');
    cy.get('button.save_order').click();

    cy.contains('TaxCloud Status')
      .closest('div')
      .contains('Captured')
      .should('exist');

    // Order should be refunded in TaxCloud when marked Refunded
    setOrderStatus('Refunded');
    cy.get('button.save_order').click();

    cy.contains('TaxCloud Status')
      .closest('div')
      .contains('Refunded')
      .should('exist');
  });
});
