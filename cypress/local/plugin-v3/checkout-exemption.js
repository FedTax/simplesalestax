// DEV-10620: forge real checkout requests; only TaxCloud HTTP responses are simulated.
module.exports = ({ task, records, login, cart, checkout, visibleTax, placeOrder, getSite }) => {
  describe('DEV-10620 checkout exemption validation (local fixtures only)', () => {
    let fixture;
    let recordOffset;

    const fieldsFrom = (body) => new URLSearchParams(body);
    const certificateFields = (fields, certificateId) => {
      // Preserve WooCommerce's nonce, billing fields and gateway. Reproduce the
      // exploit by keeping only the state from the submitted certificate form.
      [...fields.keys()].forEach((key) => {
        if (key === 'certificate_id' || key.startsWith('certificate[')) fields.delete(key);
      });
      if (certificateId !== null) fields.set('certificate_id', certificateId);
      fields.set('certificate[ExemptState]', 'MN');
    };
    const forgeReview = (certificateId) => {
      cy.intercept('POST', '**/*wc-ajax=update_order_review*', (req) => {
        const outer = fieldsFrom(req.body);
        const fields = fieldsFrom(outer.get('post_data'));
        certificateFields(fields, certificateId);
        outer.set('post_data', fields.toString());
        req.body = outer.toString();
      }).as('securityReview');
      cy.window().then((win) => win.jQuery(win.document.body).trigger('update_checkout'));
      cy.wait('@securityReview', { timeout: 60000 }).then(({ request, response }) => {
        const fields = fieldsFrom(fieldsFrom(request.body).get('post_data'));
        expect(fields.get('certificate_id'), 'forged selection reached WooCommerce').to.equal(certificateId);
        expect(fields.get('certificate[ExemptState]')).to.equal('MN');
        expect(response.statusCode).to.equal(200);
      });
      cy.get('.blockUI', { timeout: 60000 }).should('not.exist');
    };
    const forgeCheckout = (certificateId) => {
      cy.intercept('POST', '**/*wc-ajax=checkout*', (req) => {
        const fields = fieldsFrom(req.body);
        certificateFields(fields, certificateId);
        req.body = fields.toString();
      }).as('securityCheckout');
    };
    const submittedCheckout = (certificateId, result) => cy.wait('@securityCheckout', { timeout: 60000 }).then(({ request, response }) => {
      const fields = fieldsFrom(request.body);
      expect(fields.has('certificate_id')).to.equal(certificateId !== null);
      if (certificateId !== null) expect(fields.get('certificate_id')).to.equal(certificateId);
      expect(fields.get('certificate[ExemptState]')).to.equal('MN');
      expect(fields.has('certificate[IDNumber]')).to.equal(false);
      expect(response.statusCode).to.equal(200);
      expect(response.body.result, JSON.stringify(response.body)).to.equal(result);
    });
    const sessionSelection = (selection) => cy.request({
      url: '/', qs: { sst_e2e_checkout_exemption_session: '1' },
    }).then(({ body }) => {
      expect(body.success).to.equal(true);
      expect(body.data.certificateId, 'raw WooCommerce session selection').to.equal(selection);
    });
    const noCertificateCreated = () => records().then((rows) => {
      expect(rows.slice(recordOffset).filter((row) => row.method === 'POST' && /\/exemption-certificates$/.test(row.path)),
        'checkout never registers a certificate for the forged request').to.be.empty;
    });
    const taxableOrder = (id, customerId) => task('order', { id }).then((order) => {
      expect(order.customerId).to.equal(customerId);
      expect(order.tax).to.equal(10);
      expect(order.total).to.equal(110);
      expect(order.certificate).to.equal('');
      expect(order.packages).to.be.an('array').and.not.be.empty;
      order.packages.forEach((pack) => {
        expect(pack.customer_id).to.equal(customerId);
        expect(pack.certificate_id).to.equal('');
      });
    });

    beforeEach(() => {
      task('checkoutExemption').then((data) => { fixture = data; });
      records().then((rows) => { recordOffset = rows.length; });
    });
    afterEach(() => task('configure', { showExempt: true, checkoutExemption: false }));

    it('taxes a guest order when exemptions are disabled despite a forged new selection', () => {
      const site = getSite();
      task('configure', { showExempt: false });
      cy.clearAllCookies();
      cy.visit('/wp-login.php');
      cy.setCookie('sst_e2e_run', Cypress.env('pluginV3Run'));
      cy.getAllCookies().should((cookies) => {
        expect(cookies.some((cookie) => cookie.name.startsWith('wordpress_logged_in_'))).to.equal(false);
      });
      cart(); checkout();
      cy.get('#certificate_id').should('not.exist');
      cy.get('#billing_email').clear().type(`${site.username}-guest@example.test`).blur();
      cy.get('.blockUI', { timeout: 60000 }).should('not.exist');
      forgeReview('new');
      sessionSelection('');
      visibleTax(10);

      // Omit the final selection while retaining the matching exemption state.
      forgeCheckout(null);
      placeOrder().then((id) => taxableOrder(id, 0));
      submittedCheckout(null, 'success');
      noCertificateCreated();
    });

    it('rejects incomplete certificate data when the final POST omits a session selection of new', () => {
      login(); cart(); checkout();
      cy.get('#certificate_id').select('new', { force: true });
      cy.get('#exempt_certificate_form').should('be.visible');
      // Complete the visible form so browser validation permits submission.
      // The intercepted final request removes the details server validation needs.
      cy.get('#single_purchase').check();
      cy.get('#exempt_state').select('MN', { force: true });
      cy.get('#tax_type').select('StateIssued', { force: true });
      cy.get('#state_of_issue').select('MN', { force: true });
      cy.get('#id_number').type('QA-DEV-10620');
      cy.get('#purchaser_business_type').select('RetailTrade', { force: true });
      cy.get('#purchaser_exemption_reason').select('Resale', { force: true });
      cy.get('#purchaser_exemption_reason_value').type('QA resale exemption').blur();
      cy.intercept('POST', '**/*wc-ajax=update_order_review*').as('newCertificateReview');
      cy.window().then((win) => win.jQuery(win.document.body).trigger('update_checkout'));
      cy.wait('@newCertificateReview', { timeout: 60000 }).then(({ request, response }) => {
        const fields = fieldsFrom(fieldsFrom(request.body).get('post_data'));
        expect(fields.get('certificate_id')).to.equal('new');
        expect(fields.get('certificate[ExemptState]')).to.equal('MN');
        expect(response.statusCode).to.equal(200);
      });
      cy.get('.blockUI', { timeout: 60000 }).should('not.exist');
      visibleTax(0);
      sessionSelection('new');

      let before;
      task('checkoutExemptionState').then((state) => { before = state; });
      forgeCheckout(null);
      cy.get('#payment_method_cheque').check({ force: true });
      cy.get('#place_order').click();
      submittedCheckout(null, 'failure');
      cy.get('.woocommerce-error').should('contain.text', 'Required exemption certificate fields are missing');
      cy.location('pathname').should('not.include', '/order-received/');
      sessionSelection('new');
      task('checkoutExemptionState').then((after) => {
        expect(after.orderIds, 'validation failure creates no order').to.deep.equal(before.orderIds);
        expect(after.certificateIds, 'validation failure creates no certificate').to.deep.equal(before.certificateIds);
      });
      noCertificateCreated();
    });

    it('ignores another customer’s matching-state certificate and persists a taxable order', () => {
      const site = getSite();
      cy.then(() => expect(fixture.foreignCustomerId).not.to.equal(site.customerId));
      login(); cart(); checkout();
      cy.then(() => {
        cy.get(`#certificate_id option[value="${fixture.foreignCertificateId}"]`).should('not.exist');
        forgeReview(fixture.foreignCertificateId);
      });
      sessionSelection('');
      visibleTax(10);
      cy.then(() => forgeCheckout(fixture.foreignCertificateId));
      placeOrder().then((id) => taxableOrder(id, site.customerId));
      cy.then(() => submittedCheckout(fixture.foreignCertificateId, 'success'));
      noCertificateCreated();
    });
  });
};
