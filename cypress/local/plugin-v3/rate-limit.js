// DEV-10623: the real limiter is exhausted by WooCommerce requests, never seeded
// as reached. Only the existing TaxCloud HTTP boundary returns fixture responses.
module.exports = ({ task, records, login, cart, checkout, visibleTax, placeOrder, getSite }) => {
  describe('DEV-10623 checkout rate limiting (local fixtures only)', () => {
    const message = 'Sales tax could not be calculated. Please try again later or contact the store.';
    let limitedOffset;
    const state = () => cy.request({ url: '/', qs: { sst_e2e_rate_limit_state: 1 } }).then(({ body }) => {
      expect(body.success).to.equal(true);
      expect(body.data).to.include({ enabled: 'yes', requests: 5, forceLookup: 'yes', notice: 'no' });
      return body.data;
    });
    const exhaust = (remaining = 6) => state().then((current) => {
      if (current.limited) return current;
      expect(remaining, 'real tax requests reach the configured quota').to.be.greaterThan(0);
      // The Store API recalculates the current cart. Forced lookup is enabled,
      // so a cached quote must survive when the final allowed request is used.
      return cy.request('/wp-json/wc/store/v1/cart').then(() => exhaust(remaining - 1));
    });
    const quantity = (value) => {
      task('configure', { blocks: false });
      cy.visit(getSite().cartClassic);
      cy.get('input.qty').clear().type(String(value));
      cy.get('button[name="update_cart"]').click();
      cy.get('.blockUI', { timeout: 60000 }).should('not.exist');
      cy.get('input.qty').should('have.value', String(value));
    };
    const visitCheckout = (blocks, tax) => {
      const site = getSite();
      task('configure', { blocks });
      cy.visit(blocks ? site.checkoutBlock : site.checkoutClassic);
      if (blocks) {
        cy.get('.wc-block-checkout').should('be.visible');
        if (tax) {
          cy.get('.wc-block-components-totals-taxes .wc-block-components-totals-item__value').filter(':visible')
            .should(($amounts) => {
              expect($amounts.length).to.be.greaterThan(0);
              $amounts.each((_, value) => expect(Number(value.textContent.replace(/[^\d.]/g, ''))).to.equal(tax));
            });
        }
        // Reading the live Store API also verifies the preview remains usable.
        cy.request('/wp-json/wc/store/v1/cart').then(({ status, body }) => {
          expect(status).to.equal(200);
          expect(Number(body.totals.total_tax)).to.equal(tax * 100);
          expect(Number(body.totals.total_price)).to.equal(tax ? 11000 : 20000);
        });
        cy.contains('label', 'Check payments').click();
      } else {
        cy.get('.blockUI', { timeout: 60000 }).should('not.exist');
        visibleTax(tax);
        cy.get('#payment_method_cheque').check({ force: true });
      }
      cy.contains(message).should('not.exist');
    };
    const noMoreLookups = () => records().then((rows) => {
      expect(rows.slice(limitedOffset).filter((row) => row.method === 'POST'
        && (/\/carts$/.test(row.path) || row.path === '/tax/verify-address')),
      'exhausted quota prevents additional TaxCloud lookup/address calls').to.be.empty;
    });
    beforeEach(() => {
      task('configure', { rateLimit: true, showExempt: false });
      task('rateLimitReset');
    });
    afterEach(() => task('configure', { rateLimit: false, showExempt: true, blocks: false }));

    for (const guest of [false, true]) {
      for (const blocks of [false, true]) {
        const label = `${guest ? 'guest' : 'customer'} ${blocks ? 'Blocks' : 'classic'}`;
        it(`${label}: blocks an uncached cart at quota, then orders the matching cached cart with tax`, () => {
          const site = getSite();
          if (guest) {
            cy.clearAllCookies();
            cy.visit('/wp-login.php');
            cy.setCookie('sst_e2e_run', Cypress.env('pluginV3Run'));
          } else login();
          cart(); checkout();
          if (guest) {
            cy.get('#billing_email').clear().type(`${site.username}-guest@example.test`).blur();
            cy.get('.blockUI', { timeout: 60000 }).should('not.exist');
            // Classic review updates retain the guest address but omit their
            // name/email. Persist them through WooCommerce before navigating.
            cy.request('POST', '/wp-json/wc/store/v1/cart/update-customer', {
              billing_address: { first_name: 'Cypress', last_name: 'Buyer',
                email: `${site.username}-guest@example.test`, phone: '6125550100',
                address_1: '323 Washington Ave N', address_2: '', city: 'Minneapolis',
                state: 'MN', postcode: '55401', country: 'US' },
            }).then(({ status, body }) => {
              expect(status).to.equal(200);
              expect(body.billing_address).to.include({ first_name: 'Cypress', last_name: 'Buyer', email: `${site.username}-guest@example.test` });
            });
          }
          visibleTax(10);
          exhaust().then((current) => {
            expect(current.limited).to.equal(true);
            expect(current.customerId).to.equal(guest ? 0 : site.customerId);
            expect(current.cache).not.to.be.empty;
            current.cache.forEach((pack) => expect(pack).to.deep.equal({ hasResponse: true, hasError: false }));
          });
          records().then((rows) => { limitedOffset = rows.length; });

          // Keep the old quote in the session while changing its package hash.
          quantity(2);
          visitCheckout(blocks, 0);
          let before;
          task('rateLimitOrders', { guest }).then((orders) => { before = orders; });
          cy.intercept('POST', blocks ? '**/wc/store/v1/checkout*' : '**/*wc-ajax=checkout*').as('limitedCheckout');
          cy.get(blocks ? '.wc-block-components-checkout-place-order-button' : '#place_order').click();
          cy.wait('@limitedCheckout', { timeout: 60000 }).then(({ response }) => {
            expect(response.statusCode).to.equal(blocks ? 400 : 200);
            if (blocks) {
              expect(response.body.code).to.equal('sst_checkout_invalid_fields');
              expect(response.body.message).to.equal(message);
            } else {
              expect(response.body.result).to.equal('failure');
              expect(response.body.messages).to.contain(message);
            }
          });
          cy.contains(message).should('be.visible');
          cy.location('pathname').should('not.include', '/order-received/');
          task('rateLimitOrders', { guest }).then((after) => {
            expect(after.placed, 'failed checkout never places or pays an order').to.deep.equal(before.placed);
            after.drafts.forEach((order) => expect(order.paid, 'a Store API draft is unpaid').to.equal(false));
          });
          state().then((current) => {
            expect(current.limited).to.equal(true);
            current.cache.forEach((pack) => expect(pack).to.deep.equal({ hasResponse: true, hasError: false }));
          });
          noMoreLookups();
          cy.screenshot(`DEV-10623-${label}-uncached-blocked`);

          // Restoring the exact quoted contents succeeds while still limited.
          quantity(1);
          visitCheckout(blocks, 10);
          state().its('limited').should('equal', true);
          const ordered = blocks
            ? cy.get('.wc-block-components-checkout-place-order-button').click()
              .location('pathname', { timeout: 60000 }).should('include', '/order-received/')
              .then((pathname) => Number(pathname.match(/order-received\/(\d+)/)[1]))
            : placeOrder();
          ordered.then((id) => task('order', { id })).then((order) => {
            expect(order.customerId).to.equal(guest ? 0 : site.customerId);
            expect(order.tax).to.equal(10);
            expect(order.total).to.equal(110);
            expect(order.status).not.to.equal('checkout-draft');
            expect(order.packages).to.have.length(1);
            expect(Object.values(order.packages[0].response)[0]).to.deep.equal([10]);
          });
          noMoreLookups();
          cy.screenshot(`DEV-10623-${label}-cached-tax-preserved`);
        });
      }
    }

    for (const blocks of [false, true]) {
      const label = blocks ? 'Blocks' : 'classic';
      it(`${label}: creates a valid new exemption at quota without a quote for the current cart`, () => {
        task('configure', { showExempt: true });
        login(); cart(); checkout();
        visibleTax(10);
        exhaust().its('limited').should('equal', true);
        records().then((rows) => { limitedOffset = rows.length; });
        quantity(2);
        visitCheckout(blocks, 0);

        cy.get(blocks ? '#exemption-certificate-input' : '#certificate_id').select('new', { force: true });
        cy.get('#exempt_state').select('MN', { force: true });
        if (blocks) cy.contains('label', 'Federal Employer ID').click();
        else cy.get('#tax_type').select('FEIN', { force: true });
        cy.get('#id_number').clear().type('QA-RATE-LIMIT');
        cy.get('#purchaser_business_type').select('RetailTrade', { force: true });
        cy.get('#purchaser_exemption_reason').select('Resale', { force: true });
        cy.get('#purchaser_exemption_reason_value').clear().type('QA resale exemption').blur();
        cy.get('.blockUI', { timeout: 60000 }).should('not.exist');
        state().its('limited').should('equal', true);
        noMoreLookups();

        cy.intercept('POST', blocks ? '**/wc/store/v1/checkout*' : '**/*wc-ajax=checkout*').as('newExemptCheckout');
        cy.get(blocks ? '.wc-block-components-checkout-place-order-button' : '#place_order').should('not.be.disabled').click();
        cy.wait('@newExemptCheckout', { timeout: 60000 }).then(({ request, response }) => {
          expect(response.statusCode).to.equal(200);
          if (blocks) {
            expect(request.body.extensions['simple-sales-tax'].certificate).to.include({ ExemptState: 'MN', TaxType: 'FEIN', IDNumber: 'QA-RATE-LIMIT' });
            expect(response.body.payment_result.payment_status).to.equal('success');
          } else {
            const fields = new URLSearchParams(request.body);
            expect(fields.get('certificate[ExemptState]')).to.equal('MN');
            expect(fields.get('certificate[IDNumber]')).to.equal('QA-RATE-LIMIT');
            expect(response.body.result, JSON.stringify(response.body)).to.equal('success');
          }
        });
        cy.location('pathname', { timeout: 60000 }).should('include', '/order-received/').then((pathname) => {
          const id = Number(pathname.match(/order-received\/(\d+)/)[1]);
          records().then((rows) => {
            const certificates = rows.slice(limitedOffset).filter((row) => row.method === 'POST' && /\/exemption-certificates$/.test(row.path));
            expect(certificates, 'the new certificate is created exactly once before checkout succeeds').to.have.length(1);
            expect(certificates[0].status).to.equal(201);
            const certificateId = certificates[0].response.certificateId;
            task('order', { id }).then((order) => {
              expect(order.tax).to.equal(0);
              expect(order.total).to.equal(200);
              expect(order.certificate).to.equal(certificateId);
              expect(order.packages).not.to.be.empty;
              order.packages.forEach((pack) => expect(pack.certificate_id).to.equal(certificateId));
            });
          });
        });
        noMoreLookups();
        cy.screenshot(`DEV-10623-${label}-new-certificate-at-quota`);
      });
    }
  });
};
