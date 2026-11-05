// DEV-10621: real WooCommerce requests, order metadata and admin JavaScript.
// Only TaxCloud HTTP responses are simulated. The canary has no external effects.
module.exports = ({ task, records, login, cart, checkout, visibleTax, placeOrder, getSite }) => {
  describe('DEV-10621 certificate ID security (local fixtures only)', () => {
    let fixture;
    let recordOffset;
    const begin = (scenario) => {
      task('certificateSecurity', { scenario }).then((data) => { fixture = data; });
      records().then((rows) => { recordOffset = rows.length; });
    };
    const sessionSelection = (selection) => cy.request({
      url: '/', qs: { sst_e2e_checkout_exemption_session: '1' },
    }).then(({ body }) => {
      expect(body.success).to.equal(true);
      expect(body.data.certificateId, 'raw WC session selection').to.equal(selection);
    });
    const taxableOrder = (id) => task('order', { id }).then((order) => {
      expect(order.customerId).to.equal(getSite().customerId);
      expect(order.tax).to.equal(10);
      expect(order.total).to.equal(110);
      expect(order.certificate, 'malformed ID is never saved on the order').to.equal('');
      expect(order.packages).to.be.an('array').and.not.be.empty;
      order.packages.forEach((pack) => expect(pack.certificate_id).to.equal(''));
    });
    const taxableRequests = () => records().then((rows) => {
      const requests = rows.slice(recordOffset);
      expect(requests.filter((row) => row.method === 'POST' && /\/exemption-certificates$/.test(row.path)),
        'malformed selection never creates a certificate').to.be.empty;
      const quotes = requests.filter((row) => row.method === 'POST' && /\/carts$/.test(row.path));
      expect(quotes, 'real checkout requested tax from TaxCloud').not.to.be.empty;
      quotes.forEach((quote) => quote.request.items.forEach((item) => expect(item).not.to.have.property('exemption')));
    });
    const visitOrder = () => {
      login('admin');
      cy.then(() => cy.visit(fixture.editUrl));
      cy.get('#exempt_cert').should('exist');
    };

    afterEach(() => task('certificateSecurityCleanup'));

    it('ignores a customer-owned serialized ID in classic cart refresh and checkout', () => {
      begin('checkout');
      login(); cart(); checkout();
      cy.intercept('POST', '**/*wc-ajax=update_order_review*', (req) => {
        const fields = new URLSearchParams(new URLSearchParams(req.body).get('post_data'));
        if (fields.get('certificate_id') === fixture.serializedPayload) req.alias = 'serializedClassicReview';
      });
      // The malformed value comes from the customer's fixture certificate list,
      // so the ownership check succeeds unless the GUID format is also checked.
      cy.then(() => cy.get('#certificate_id').select(fixture.serializedPayload, { force: true }));
      cy.wait('@serializedClassicReview', { timeout: 60000 }).its('response.statusCode').should('equal', 200);
      cy.get('.blockUI', { timeout: 60000 }).should('not.exist');
      sessionSelection('');
      visibleTax(10);
      cy.intercept('POST', '**/*wc-ajax=checkout*', (req) => {
        const fields = new URLSearchParams(req.body);
        fields.set('certificate_id', fixture.serializedPayload);
        req.body = fields.toString();
      }).as('serializedClassicCheckout');
      placeOrder().then(taxableOrder);
      cy.wait('@serializedClassicCheckout').then(({ request, response }) => {
        expect(new URLSearchParams(request.body).get('certificate_id')).to.equal(fixture.serializedPayload);
        expect(response.body.result).to.equal('success');
      });
      taxableRequests();
    });

    it('ignores a customer-owned serialized ID in Blocks cart updates and Store API checkout', () => {
      begin('checkout');
      task('configure', { blocks: true });
      login(); cart();
      cy.then(() => cy.visit(getSite().checkoutBlock));
      cy.get('.wc-block-checkout').should('be.visible');
      cy.intercept('POST', '**/wc/store/v1/cart/extensions*', (req) => {
        if (req.body.namespace === 'simple-sales-tax' && req.body.data.certificate_id === fixture.serializedPayload) {
          req.alias = 'serializedBlocksUpdate';
        }
      });
      cy.then(() => cy.get('#exemption-certificate-input').select(fixture.serializedPayload, { force: true }));
      cy.wait('@serializedBlocksUpdate', { timeout: 60000 }).then(({ request, response }) => {
        expect(request.body.data).to.include({ action: 'set_certificate_id', certificate_id: fixture.serializedPayload });
        expect(response.statusCode).to.equal(200);
        expect(response.body.errors).to.be.empty;
        expect(Number(response.body.totals.total_tax)).to.equal(1000);
        expect(Number(response.body.totals.total_price)).to.equal(11000);
      });
      sessionSelection('');
      cy.get('.wc-block-components-totals-taxes .wc-block-components-totals-item__value')
        .should(($tax) => expect(Number($tax.text().replace(/[^\d.]/g, ''))).to.equal(10));
      // Submit the real Store API endpoint with the same malformed extension
      // data, retaining the authenticated browser's WooCommerce session.
      cy.then(() => cy.request({
        method: 'POST', url: '/wp-json/wc/store/v1/checkout',
        body: { billing_address: fixture.billingAddress, payment_method: 'cheque', payment_data: [],
          extensions: { 'simple-sales-tax': { certificate_id: fixture.serializedPayload } } },
      })).then(({ status, body }) => {
        expect(status, JSON.stringify(body)).to.equal(200);
        expect(body.payment_result.payment_status).to.equal('success');
        expect(body.order_id).to.be.a('number').and.be.greaterThan(0);
        taxableOrder(body.order_id);
      });
      taxableRequests();
    });

    it('shows the certificate ID from a pre-7.0 serialized certificate on the admin order', () => {
      begin('legacy');
      task('certificateSecurityState').then(({ orders }) => {
        expect(orders[fixture.orderId].certificate).to.be.a('string').and.match(/^O:/);
        expect(orders[fixture.orderId].certificate).to.contain('TaxCloud\\ExemptionCertificateBase');
      });
      visitOrder();
      cy.then(() => cy.get('#exempt_cert').should('have.value', fixture.certificateId));
      cy.window().then((win) => expect(win.SSTMetaBox.selected_certificate).to.equal(fixture.certificateId));
      task('certificateSecurityState').its('wakeups').should('equal', 0);
    });

    it('does not instantiate a serialized class when staff view an order or its debug log', () => {
      begin('object');
      task('certificateSecurityState').then(({ orders, wakeups }) => {
        expect(wakeups).to.equal(0);
        expect(orders[fixture.orderId].certificate).to.be.a('string').and.contain('SST_E2E_Certificate_Gadget');
      });
      visitOrder();
      task('certificateSecurityState').its('wakeups').should('equal', 0);
      cy.window().then((win) => cy.stub(win, 'open').as('openOrderLog'));
      cy.intercept('POST', '/wp-admin/admin-ajax.php', (req) => {
        if (new URLSearchParams(req.body).get('action') === 'sst_get_order_log') req.alias = 'safeOrderLog';
      });
      cy.get('.sst-debug-order').click();
      cy.wait('@safeOrderLog').then(({ response }) => {
        expect(response.statusCode).to.equal(200);
        expect(response.body.success).to.equal(true);
      });
      cy.get('@openOrderLog').should('have.been.calledOnce');
      task('certificateSecurityState').its('wakeups').should('equal', 0);
    });

    it('encodes an injected order_id during admin recalculation and leaves both orders untouched', () => {
      let before;
      begin('recalculation');
      task('certificateSecurityState').then((state) => {
        before = state.orders;
        expect(before[fixture.orderId].total).to.equal(100);
        expect(before[fixture.protectedOrderId].total).to.equal(200);
      });
      visitOrder();
      cy.then(() => cy.get('#exempt_cert').should('have.value', fixture.queryPayload));
      cy.on('window:confirm', () => true);
      cy.intercept('POST', '/wp-admin/admin-ajax.php', (req) => {
        if (new URLSearchParams(req.body).get('action') === 'woocommerce_calc_line_taxes') req.alias = 'injectedRecalculation';
      });
      cy.get('button.calculate-action').click();
      cy.wait('@injectedRecalculation', { timeout: 60000 }).then(({ request, response }) => {
        const fields = new URLSearchParams(request.body);
        expect(fields.getAll('order_id'), 'only the original order ID is sent').to.deep.equal([String(fixture.orderId)]);
        expect(fields.get('exemption_certificate'), 'payload remains a single encoded value').to.equal(fixture.queryPayload);
        expect(response.statusCode).to.equal(200);
        expect(String(response.body)).to.contain('Invalid exemption certificate ID.');
      });
      task('certificateSecurityState').then(({ orders, wakeups }) => {
        expect(orders[fixture.protectedOrderId], 'other order items, totals and metadata').to.deep.equal(before[fixture.protectedOrderId]);
        expect(orders[fixture.orderId], 'rejected request saves no changes on the original order').to.deep.equal(before[fixture.orderId]);
        expect(wakeups).to.equal(0);
      });
      records().then((rows) => expect(rows.slice(recordOffset).filter((row) => row.method === 'POST' && /\/carts$/.test(row.path)),
        'invalid certificate is rejected before any tax lookup').to.be.empty);
    });
  });
};
