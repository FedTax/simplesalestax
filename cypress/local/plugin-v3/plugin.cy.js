// Real browser -> WordPress/WooCommerce -> SST PHP -> TaxCloud -> order database.
// Live mode uses the saved Test-mode connection. Fixture mode changes only the
// server's external HTTP boundary; the plugin and WooCommerce remain real.
describe('WooCommerce TaxCloud V3 integration (local only)', () => {
  let site;
  let certificateId;
  let recordOffset = 0;
  const fixture = Cypress.env('pluginV3Mode') === 'fixture';
  const task = (action, data = {}) => cy.task('pluginV3', { action, ...data }, { log: false });
  const records = () => task('records');
  const call = (method, pattern, successfulOnly = false) => records().then((rows) => {
    const matched = (successfulOnly ? rows : rows.slice(recordOffset)).filter((row) => row.method === method && pattern.test(row.path) && (!successfulOnly || (row.status >= 200 && row.status < 300)));
    expect(matched, `${method} ${pattern} reached through the plugin`).not.to.be.empty;
    const last = matched[matched.length - 1];
    expect(last.status, JSON.stringify(last.response)).to.be.within(200, 299);
    return last;
  });
  const login = (role = 'customer') => {
    cy.session([Cypress.env('pluginV3Run'), role], () => {
      cy.visit('/wp-login.php');
      cy.setCookie('sst_e2e_run', Cypress.env('pluginV3Run'));
      cy.request({ method: 'POST', url: '/wp-login.php', form: true, log: false,
        body: { log: role === 'admin' ? 'admin' : site.username,
          pwd: role === 'admin' ? 'password' : site.password, 'wp-submit': 'Log In', testcookie: '1' } });
      cy.getAllCookies().should((cookies) => expect(cookies.some((cookie) => cookie.name.startsWith('wordpress_logged_in_'))).to.equal(true));
    });
    cy.setCookie('sst_e2e_run', Cypress.env('pluginV3Run'));
  };
  const cart = () => {
    cy.request({ method: 'DELETE', url: '/wp-json/wc/store/v1/cart/items' });
    cy.visit(`/?add-to-cart=${site.productId}`);
  };
  const observeReview = (alias, predicate) => cy.intercept('POST', '**/*wc-ajax=update_order_review*', (req) => {
    const outer = typeof req.body === 'string' ? new URLSearchParams(req.body) : null;
    const fields = new URLSearchParams(outer ? outer.get('post_data') : req.body.post_data);
    if (predicate(fields)) req.alias = alias;
  });
  const checkout = () => {
    cy.visit(site.checkoutClassic);
    records().then((rows) => {
      const quotes = rows.slice(recordOffset).filter((row) => row.method === 'POST' && /\/carts$/.test(row.path));
      if (quotes.length) {
        const last = quotes[quotes.length - 1];
        expect(last.status, `TaxCloud checkout quote: ${JSON.stringify(last.response || last.error)}`).to.be.within(200, 299);
      }
    });
    cy.get('#billing_first_name').clear().type('Cypress');
    cy.get('#billing_last_name').clear().type('Buyer');
    cy.get('#billing_address_1').clear().type('323 Washington Ave N');
    cy.get('#billing_city').clear().type('Minneapolis');
    cy.get('#billing_state').select('MN', { force: true });
    cy.get('#billing_postcode').clear().type('55401').blur();
    cy.get('#billing_phone').clear().type('6125550100');
    cy.get('#billing_email').clear().type(`${site.username}@example.test`).blur();
    cy.get('.blockUI', { timeout: 60000 }).should('not.exist');
  };
  const visibleTax = (amount) => cy.get('#order_review .tax-rate .amount').should(($row) => {
    const value = Number($row.text().replace(/[^\d.]/g, ''));
    expect(value).to.equal(amount);
  });
  const placeOrder = () => {
    cy.get('#payment_method_cheque').check({ force: true });
    cy.get('#place_order').click();
    cy.location('pathname', { timeout: 60000 }).should('include', '/order-received/');
    return cy.location('pathname').then((pathname) => Number(pathname.match(/order-received\/(\d+)/)[1]));
  };
  const completeOrder = (id) => {
    login('admin');
    cy.visit(`/wp-admin/post.php?post=${id}&action=edit`);
    cy.get('#order_status').select('wc-completed', { force: true });
    cy.get('#save-order, button.save_order').first().click();
    cy.get('#order_status').should('have.value', 'wc-completed');
    task('order', { id }).then((order) => expect(order.taxcloudStatus).to.equal('captured'));
  };

  before(() => task('begin').then((info) => {
    site = info;
    certificateId = undefined;
    recordOffset = 0;
    Cypress.env('pluginV3Run', info.run);
  }));
  after(() => task('end'));
  beforeEach(() => records().then((rows) => { recordOffset = rows.length; }));
  afterEach(() => task('configure', { dataMover: false, blocks: false, failure: false }));

  it('Verify Settings exchanges credentials, pings V3 and loads management settings', () => {
    login('admin');
    task('clearCaches');
    cy.visit('/wp-admin/admin.php?page=wc-settings&tab=integration&section=wootax');
    cy.get('#woocommerce_wootax_api_version').should('have.value', 'v3');
    cy.intercept('POST', '/wp-admin/admin-ajax.php').as('verify');
    cy.on('window:alert', (text) => expect(text).to.equal('Success! Your TaxCloud settings are valid.'));
    cy.get('#verifySettings').click();
    cy.wait('@verify', { timeout: 60000 }).its('response.body.success').should('equal', true);
    call('POST', /\/auth\/token$/);
    call('GET', /\/ping$/);
    call('GET', /^\/mgmt\/connections\//);
  });

  it('product TIC search reaches V3 and saves the selected TIC', () => {
    login('admin');
    cy.visit(`/wp-admin/post.php?post=${site.productId}&action=edit`);
    cy.get('.sst-select-tic').first().click();
    cy.get('.sst-tic-search').clear().type('Uncategorized');
    cy.contains('.tic-row', 'Uncategorized').find('button').click();
    cy.get('#publish').click();
    cy.contains('Product updated').should('be.visible');
    cy.get('.sst-tic-input').should('have.value', '00000');
    call('POST', /^\/tax\/tic\/search$/).its('request.query').should('equal', 'Uncategorized');
  });

  it('classic checkout verifies addresses, calculates tax, persists, captures and refunds an order', () => {
    login(); cart(); checkout();
    let expectedTax;
    call('POST', /\/carts$/).then((row) => {
      const quoted = row.response.items[0];
      expectedTax = Number(quoted.lineItems.reduce((sum, item) => sum + item.tax.amount, 0).toFixed(2));
      if (fixture) expect(expectedTax).to.equal(10);
      expect(row.request.items[0].destination.state).to.equal('MN');
      expect(row.request.items[0].origin.zip).to.equal('55401');
      visibleTax(expectedTax);
    });
    call('POST', /^\/tax\/verify-address$/);
    placeOrder().then((id) => {
      task('order', { id }).then((order) => {
        expect(order.tax).to.equal(expectedTax);
        expect(order.total).to.equal(100 + expectedTax);
        expect(order.packages).to.be.an('array').and.not.be.empty;
        order.packages.forEach((pack) => expect(pack.api_version).to.equal('v3'));
        const pack = order.packages[0];
        task('clientReads', { cartId: pack.cart_id }).its('cart.cartId').should('equal', pack.cart_id);
      });
      completeOrder(id);
      call('POST', /\/carts\/orders$/).then((row) => {
        expect(row.request.completed).to.equal(true);
        task('clientReads', { orderId: row.request.orderId }).then((data) => {
          expect(data.order.orderId).to.equal(row.request.orderId);
          expect(data.order.completedDate).to.be.a('string');
        });
      });
      cy.intercept('POST', '/wp-admin/admin-ajax.php', (req) => {
        if (String(req.body).includes('action=woocommerce_refund_line_items')) req.alias = 'refundOrder';
      });
      cy.on('window:confirm', () => true);
      cy.get('button.refund-items').click();
      cy.get('input.refund_order_item_qty').first().clear().type('1').blur();
      cy.get('#refund_amount').should('have.value', (100 + expectedTax).toFixed(2));
      cy.get('button.do-manual-refund').click();
      cy.wait('@refundOrder', { timeout: 60000 }).its('response.body.success').should('equal', true);
      call('POST', /\/orders\/refunds\//);
      task('order', { id }).its('taxcloudStatus').should('equal', 'refunded');
    });
  });

  it('the PHP Update Order client completes a separate pending test order', () => {
    call('POST', /\/carts$/, true).then((row) => {
      task('clientUpdate', { cart: row.request.items[0] }).then((data) => {
        expect(data.pendingOrder.completedDate == null).to.equal(true);
        expect(data.updatedOrder.orderId).to.equal(data.pendingOrder.orderId);
        expect(data.persistedOrder.orderId).to.equal(data.pendingOrder.orderId);
        expect(data.persistedOrder.completedDate).to.be.a('string');
        expect(Date.parse(data.persistedOrder.completedDate)).to.equal(Date.parse(data.completedDate));
      });
    });
    call('PATCH', /\/orders\//);
  });

  it('changing cart quantity recalculates the quoted and displayed tax', () => {
    login(); cart();
    cy.visit(site.cartClassic);
    cy.get('input.qty').clear().type('2');
    cy.get('button[name="update_cart"]').click();
    cy.get('.blockUI', { timeout: 60000 }).should('not.exist');
    checkout();
    call('POST', /\/carts$/).then((row) => {
      expect(row.request.items[0].lineItems[0].quantity).to.equal(2);
      expect(row.request.items[0].lineItems[0].price).to.equal(100);
      const tax = Number(row.response.items[0].lineItems.reduce((sum, item) => sum + item.tax.amount, 0).toFixed(2));
      if (fixture) expect(tax).to.equal(20);
      visibleTax(tax);
    });
  });

  it('My Account adds/lists a certificate; checkout applies it only to its covered state', () => {
    login();
    cy.visit('/my-account/exemption-certificates/');
    cy.get('.sst-certificate-add').click();
    cy.get('.wc-backbone-modal').within(() => {
      cy.get('#exempt_state').select('MN', { force: true });
      cy.get('#tax_type').select('FEIN', { force: true });
      cy.get('#id_number').type('123456789');
      cy.get('#purchaser_business_type').select('RetailTrade', { force: true });
      cy.get('#purchaser_exemption_reason').select('Resale', { force: true });
      cy.get('#purchaser_exemption_reason_value').type('E2E resale');
      cy.get('#btn-ok').click();
    });
    cy.get('#sst-certificates tbody tr[data-id]').should('have.length', 1).invoke('attr', 'data-id').then((id) => {
      certificateId = id;
      task('clientReads', { certificateId: id }).its('certificate.certificateId').should('equal', id);
    });
    call('POST', /\/exemption-certificates$/).then((row) => {
      expect(row.request.reason).to.equal('Resale');
      expect(row.request.customerId).to.equal(String(site.customerId));
    });
    cy.get('.sst-certificate-refresh').click();
    cy.get('#sst-certificates tbody tr[data-id]').should('have.length', 1);
    call('GET', /^\/tax\/exemption-certificates$/);
    cart(); checkout();
    observeReview('exemptionApplied', (fields) => fields.get('certificate_id') === certificateId);
    cy.then(() => cy.get(`#certificate_id option[value="${certificateId}"]`).invoke('text').then((label) => {
      cy.get('#select2-certificate_id-container').click();
      cy.contains('.select2-results__option', label).click();
    }));
    cy.wait('@exemptionApplied', { timeout: 60000 });
    visibleTax(0);
    call('POST', /\/carts$/).then((row) => expect(row.request.items[0].exemption.exemptionId).to.equal(certificateId));
    // Same buyer/certificate, destination outside MN: exemption must not leak.
    observeReview('destinationChanged', (fields) => fields.get('billing_state') === 'CA' && fields.get('billing_postcode') === '90012');
    cy.get('#billing_state').select('CA', { force: true });
    cy.get('#billing_city').clear().type('Los Angeles');
    cy.get('#billing_address_1').clear().type('200 N Spring St');
    cy.get('#billing_postcode').clear().type('90012').blur();
    cy.wait('@destinationChanged', { timeout: 60000 });
    cy.get('.blockUI', { timeout: 60000 }).should('not.exist');
    call('POST', /\/carts$/).then((row) => {
      expect(row.request.items[0].destination.state).to.equal('CA');
      expect(row.request.items[0]).not.to.have.property('exemption');
      if (fixture) visibleTax(10);
    });
  });

  it('My Account disables only the certificate created by this run', () => {
    expect(certificateId).to.be.a('string');
    login();
    cy.visit('/my-account/exemption-certificates/');
    cy.on('window:confirm', () => true);
    cy.get(`tr[data-id="${certificateId}"] .sst-certificate-delete`).click();
    cy.get(`tr[data-id="${certificateId}"]`).should('not.exist');
    call('DELETE', /\/exemption-certificates\//);
  });

  it('Data Import captures through Orders rather than the realtime Carts API', () => {
    task('configure', { dataMover: true });
    login(); cart(); checkout();
    placeOrder().then((id) => {
      completeOrder(id);
      call('POST', /\/connections\/:connection\/orders$/).then((row) => {
        expect(row.request.lineItems).not.to.be.empty;
        expect(row.request.currency.currencyCode).to.equal('USD');
        task('order', { id }).then((order) => {
          const total = row.request.lineItems.reduce((sum, item) => sum + (item.tax ? item.tax.amount : 0), 0);
          expect(Number(total.toFixed(2))).to.equal(order.tax);
        });
      });
    });
    task('configure', { dataMover: false });
  });

  it('Checkout Blocks uses the Store API and displays the plugin tax result', () => {
    let expectedTax;
    let expectedTotal;
    const displayedTotals = () => {
      cy.get('.wc-block-components-totals-footer-item .wc-block-components-totals-item__value').should(($value) => {
        expect(Number($value.text().replace(/[^\d.]/g, ''))).to.equal(expectedTotal);
      });
      if (expectedTax > 0) {
        cy.get('.wc-block-components-totals-taxes').should('be.visible').find('.wc-block-components-totals-item__value').should(($value) => {
          expect(Number($value.text().replace(/[^\d.]/g, ''))).to.equal(expectedTax);
        });
      } else {
        // WooCommerce Blocks omits its tax row when the quote is zero.
        cy.get('body').then(($body) => $body.find('.wc-block-components-totals-taxes .wc-block-components-totals-item__value').each((_, value) => {
          expect(Number(value.textContent.replace(/[^\d.]/g, ''))).to.equal(0);
        }));
      }
    };
    task('configure', { blocks: true });
    login(); cart();
    cy.visit(site.cartBlock);
    cy.get('.wc-block-cart').should('be.visible');
    cy.request('/wp-json/wc/store/v1/cart').then(({ body }) => {
      call('POST', /\/carts$/).then((row) => {
        const amount = row.response.items[0].lineItems.reduce((sum, item) => sum + item.tax.amount, 0);
        expect(Number(body.totals.total_tax)).to.equal(Math.round(amount * 100));
        expect(body.errors).to.be.empty;
        expectedTax = amount;
        expectedTotal = Number(body.totals.total_price) / (10 ** body.totals.currency_minor_unit);
        if (fixture) expect(Number(body.totals.total_tax)).to.equal(1000);
        displayedTotals();
      });
    });
    cy.visit(site.checkoutBlock);
    cy.get('.wc-block-checkout').should('be.visible');
    cy.then(displayedTotals);
    task('configure', { blocks: false });
  });

  if (fixture) {
    it('a rejected TaxCloud cart is surfaced by the WooCommerce Store API', () => {
      task('configure', { failure: true });
      task('clearCaches');
      login(); cart();
      cy.request({ url: '/wp-json/wc/store/v1/cart', failOnStatusCode: false }).then(({ body }) => {
        expect(JSON.stringify(body.errors || body)).to.contain('Cypress injected cart rejection');
      });
      records().then((rows) => expect(rows.some((row) => /\/carts$/.test(row.path) && row.status === 422)).to.equal(true));
      task('configure', { failure: false });
    });
  }

  it('records successful coverage for all 14 V3 Sales Tax routes plus auth/settings', () => {
    [
      ['POST', /\/auth\/token$/], ['GET', /^\/mgmt\/connections\//], ['GET', /\/ping$/],
      ['POST', /^\/tax\/verify-address$/], ['POST', /^\/tax\/tic\/search$/],
      ['POST', /\/carts$/], ['GET', /\/carts\//], ['POST', /\/carts\/orders$/],
      ['POST', /\/connections\/:connection\/orders$/], ['GET', /\/connections\/:connection\/orders\//],
      ['PATCH', /\/connections\/:connection\/orders\//], ['POST', /\/orders\/refunds\//],
      ['POST', /\/exemption-certificates$/], ['GET', /\/connections\/:connection\/exemption-certificates\//],
      ['GET', /^\/tax\/exemption-certificates$/], ['DELETE', /\/exemption-certificates\//],
    ].forEach(([method, pattern]) => call(method, pattern, true));
  });
});
