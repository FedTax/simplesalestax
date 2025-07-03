import '@testing-library/cypress/add-commands';
import WooCommerceRestApi from "@woocommerce/woocommerce-rest-api";

const storeApi = new WooCommerceRestApi({
  url: cy.config('baseUrl'),
  consumerKey: Cypress.env('REST_API_KEY'),
  consumerSecret: Cypress.env('REST_API_SECRET'),
  version: 'wc/store/v1',
});

Cypress.Commands.add('visitAdminPage', (pagePath = '/wp-admin/') => {
  cy.session('admin', () => {
    cy.visit(pagePath);
    cy.get('body').then(($body) => {
      if ($body.hasClass('login')) {
        cy.findByRole('textbox', {name: 'Username or Email Address'})
          .clear()
          .type('admin');
        cy.findByRole('button', {name: 'Show password'}).click();
        cy.findByRole('textbox', {name: 'Password'}).type('password');
        cy.findByRole('button', {name: 'Log In'}).click();
      }
    });
  });
});

Cypress.Commands.add('loginAsAdmin', () => {
  cy.session('admin', () => {
    cy.visit('/wp-login.php');
    cy.findByRole('textbox', {name: 'Username or Email Address'})
      .clear()
      .type('admin');
    cy.findByRole('button', {name: 'Show password'}).click();
    cy.findByRole('textbox', {name: 'Password'}).type('password');
    cy.findByRole('button', {name: 'Log In'}).click();
    
    // Wait for login to complete and verify we're logged in
    cy.url().should('not.include', 'wp-login.php');
    cy.get('body').should('not.have.class', 'login');
  }, {cacheAcrossSpecs: true});
});

Cypress.Commands.add('goToSettingsPage', () => {
  cy.visit('/wp-admin/admin.php?page=wc-settings&tab=integration&section=wootax');
  cy.get('body').should('not.have.class', 'login');
  cy.url().should('include', 'wc-settings');
});

Cypress.Commands.add('addProductToCart', (productOrVariationId, quantity = 1) => {
  // https://docs.cypress.io/api/utilities/promise#Waiting-for-Promises
  cy.wrap(null).then({ timeout: 10000 }, () => {
    return storeApi.post('cart/add-item', {
      id: productOrVariationId,
      quantity,
    });
  });
});

Cypress.Commands.add('emptyCart', () => {
  cy.wrap(null).then({ timeout: 10000 }, () => {
    return storeApi.delete('cart/items');
  });
});

Cypress.Commands.add('updateShippingAddress', (address) => {
  cy.wrap(null).then({ timeout: 10000 }, () => {
    return storeApi.post('cart/update-customer', { shipping_address: address });
  });
});

Cypress.Commands.add('resetShippingAddress', () => {
  cy.updateShippingAddress({
    country: 'US',
    city: 'Bayport',
    state: 'NY',
    postcode: '11705',
  });
});

Cypress.Commands.add('applyCoupon', (code) => {
  cy.wrap(null).then({ timeout: 15000 }, () => {
    return storeApi.post('cart/coupons', { code });
  });
});

Cypress.Commands.add('removeAllCoupons', () => {
  cy.wrap(null).then({ timeout: 10000 }, () => {
    return storeApi.delete('cart/coupons');
  });
});

Cypress.Commands.add('resetCart', () => {
  cy.emptyCart();
  cy.resetShippingAddress();
  cy.removeAllCoupons();
});

Cypress.Commands.add('assertTaxTotal', (expectedTax) => {
  cy.findByText('Sales Tax', { timeout: 15000 }).parent().within(() => {
    cy.contains(expectedTax).should('exist');
  });
});

Cypress.Commands.add('selectShippingMethod', (methodName) => {
  cy.findByRole('radio', { name: new RegExp(methodName, 'i') }).click();
});

Cypress.Commands.add('waitForBlockedElements', (timeout = 30000) => {
  cy.get('.blockOverlay', {timeout}).should('not.exist');
});

Cypress.Commands.add('useClassicCart', (useClassicCart) => {
  cy.visit('/wp-admin/admin.php?page=wc-settings&tab=advanced');

  const cartPageId = useClassicCart ? 90927 : 10;
  const checkoutPageId = useClassicCart ? 90929 : 11;

  cy.get('#woocommerce_cart_page_id').then(($select) => {
    $select
      .html(`<option value="${cartPageId}"></option>`)
      .val(cartPageId)
      .trigger('change');
  });
  cy.get('#woocommerce_checkout_page_id').then(($select) => {
    $select
      .html(`<option value="${checkoutPageId}"></option>`)
      .val(checkoutPageId)
      .trigger('change');
  });

  cy.findByRole('button', { name: 'Save changes' }).then(($button) => {
    $button.prop('disabled', false);
    $button.click();
  });

  cy.findByText(/saved/i, { timeout: 15000 }).should('exist');
});
