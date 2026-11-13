// Real admin selection/recalculation and persistence; TaxCloud HTTP is simulated.
module.exports = ({ task, records, login, getSite }) => describe('admin order exemptions (local fixtures only)', () => {
  let order;
  let recordOffset;
  const money = ($element) => Number($element.text().replace(/[^\d.]/g, ''));
  const observeRecalculation = () => {
    cy.on('window:confirm', () => true);
    cy.intercept('POST', '/wp-admin/admin-ajax.php', (req) => {
      if (new URLSearchParams(req.body).get('action') === 'woocommerce_calc_line_taxes') {
        req.alias = 'adminExemptionRecalculate';
      }
    });
  };
  const recalculate = (certificate) => {
    cy.get('#exempt_cert').select(certificate, { force: true }).should('have.value', certificate);
    clickRecalculate();
  };
  const clickRecalculate = () => {
    cy.get('button.calculate-action').click();
    cy.wait('@adminExemptionRecalculate', { timeout: 60000 }).its('response.statusCode').should('equal', 200);
    cy.get('.blockUI', { timeout: 60000 }).should('not.exist');
  };
  const verify = (tax, certificate) => {
    const customerId = getSite().customerId;
    cy.get('#woocommerce-order-items .wc-order-totals').contains('tr', 'Sales Tax:')
      .find('.amount').should(($amount) => expect(money($amount)).to.equal(tax));
    cy.get('#woocommerce-order-items .wc-order-totals').contains('tr', 'Order Total:')
      .find('.amount').should(($amount) => expect(money($amount)).to.equal(Number((4806.45 + tax).toFixed(2))));
    task('order', { id: order.id }).then((saved) => {
      expect(saved.tax).to.equal(tax);
      expect(saved.total).to.equal(Number((4806.45 + tax).toFixed(2)));
      expect(saved.certificate).to.equal(certificate);
      expect(saved.packages).to.have.length(1);
      expect(saved.packages[0]).to.include({ customer_id: customerId, certificate_id: certificate, api_version: 'v3' });
      expect(saved.packages[0].shipping_method).to.equal('');
      expect(saved.packages[0].cart_items).to.have.length(10);
    });
    records().then((rows) => {
      const quotes = rows.slice(recordOffset).filter((row) => row.method === 'POST' && /\/carts$/.test(row.path));
      expect(quotes, 'admin recalculation calls TaxCloud').not.to.be.empty;
      const quote = quotes[quotes.length - 1];
      expect(quote.status).to.equal(200);
      expect(quote.credentialsMatched).to.equal(true);
      const cart = quote.request.items[0];
      expect(cart.customerId).to.equal(`customer-${customerId}`);
      expect(cart.customerId).not.to.equal(`customer-${order.adminId}`);
      expect(cart.origin.state).to.equal('FL');
      expect(cart.destination.state).to.equal('FL');
      expect(cart.lineItems).to.have.length(10);
      if (certificate) expect(cart.exemption.exemptionId).to.equal(certificate);
      else expect(cart).not.to.have.property('exemption');
      expect(Number(quote.response.items[0].lineItems.reduce((sum, item) => sum + item.tax.amount, 0).toFixed(2))).to.equal(tax);
    });
  };

  beforeEach(() => {
    task('adminExemption').then((fixture) => {
      order = fixture;
      expect(order.adminId).not.to.equal(getSite().customerId);
      login('admin');
      observeRecalculation();
      records().then((rows) => { recordOffset = rows.length; });
    });
  });

  const openExistingOrder = () => {
    cy.visit(order.editUrl);
    cy.get(`#exempt_cert option[value="${order.certificates.FL}"]`).should('exist');
    cy.get('#order_line_items tr.item').should('have.length', 10);
    cy.get('#order_shipping_line_items tr').should('not.exist');
  };

  it('retains the customer and FL certificate through repeated admin recalculation and reload', () => {
    openExistingOrder();
    recalculate('');
    verify(288.40, '');
    cy.then(() => recalculate(order.certificates.FL));
    cy.then(() => verify(0, order.certificates.FL));
    cy.reload();
    cy.get('#exempt_cert').should('have.value', order.certificates.FL);
    cy.then(() => recalculate(order.certificates.FL));
    cy.then(() => verify(0, order.certificates.FL));
    cy.screenshot('DEV-10586-admin-FL-certificate-zero-tax', { capture: 'fullPage' });
  });

  it('rejects a NY-only certificate on an FL order without changing the customer', () => {
    openExistingOrder();
    cy.then(() => recalculate(order.certificates.NY));
    verify(288.40, '');
    cy.reload();
    cy.get('#exempt_cert').should('have.value', '');
  });

  it('charges tax when the administrator leaves the certificate empty', () => {
    openExistingOrder();
    recalculate('');
    verify(288.40, '');
  });

  it('DEV-10619 applies the selected customer’s certificate before a new admin order is saved', () => {
    const { customerId, productId, username } = getSite();
    const certificate = order.certificates.MN;
    cy.visit(order.newOrderUrl);
    cy.get('#select2-customer_user-container').click();
    cy.get('.select2-container--open .select2-search__field').type(username);
    cy.contains('.select2-results__option', username).click();
    cy.get('#customer_user').should('have.value', String(customerId));
    cy.get('#_billing_postcode').should('have.value', '55401');
    cy.get('#_shipping_postcode').should('have.value', '55401');
    cy.get(`select#exempt_cert option[value="${certificate}"]`).should('exist');

    cy.get('button.add-line-item').click();
    cy.get('button.add-order-item').click();
    cy.get('.select2-container--open .select2-search__field').type(String(productId));
    cy.contains('.select2-results__option', `Cypress V3 ${Cypress.env('pluginV3Run')}`).click();
    cy.get('.wc-backbone-modal-add-products #btn-ok').click();
    cy.get('#order_line_items tr.item').should('have.length', 1);

    const visibleTax = (tax) => cy.get('#woocommerce-order-items .wc-order-totals').contains('tr', 'Sales Tax:')
      .find('.amount').should(($amount) => expect(money($amount)).to.equal(tax));
    clickRecalculate();
    visibleTax(10);
    records().then((rows) => { recordOffset = rows.length; });
    cy.get('#select2-exempt_cert-container').click();
    cy.contains('.select2-results__option', /^MN •/).click();
    cy.get('#exempt_cert').should('have.value', certificate);
    clickRecalculate();
    visibleTax(0);
    cy.get('@adminExemptionRecalculate').then(({ request }) => {
      const fields = new URLSearchParams(request.body);
      const id = Number(fields.get('order_id'));
      expect(fields.get('exemption_certificate')).to.equal(certificate);
      records().then((rows) => {
        const quote = rows.slice(recordOffset).filter((row) => row.method === 'POST' && /\/carts$/.test(row.path)).at(-1);
        expect(quote, 'TaxCloud lookup for the new order').to.exist;
        expect(quote.status).to.equal(200);
        expect(quote.request.items[0]).to.deep.include({ customerId: `customer-${customerId}`, exemption: { exemptionId: certificate } });
        expect(quote.response.items[0].lineItems[0].tax.amount).to.equal(0);
      });
      task('order', { id }).then((saved) => {
        expect(saved).to.include({ status: 'auto-draft', customerId, tax: 0, total: 100, certificate });
        expect(saved.packages[0]).to.include({ customer_id: customerId, certificate_id: certificate });
      });
      cy.get('#publish, button.save_order').first().click();
      cy.get('#exempt_cert').should('have.value', certificate);
      visibleTax(0);
      task('order', { id }).then((saved) => {
        expect(saved).to.include({ status: 'pending', customerId, tax: 0, total: 100, certificate });
      });
    });
    cy.screenshot('DEV-10619-new-admin-order-zero-tax', { capture: 'fullPage' });
  });
});
