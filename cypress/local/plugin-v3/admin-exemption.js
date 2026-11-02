// DEV-10586: real admin selection/recalculation and persistence; TaxCloud HTTP is simulated.
module.exports = ({ task, records, login, getSite }) => describe('DEV-10586 admin order exemptions (local fixtures only)', () => {
  let order;
  let recordOffset;
  const money = ($element) => Number($element.text().replace(/[^\d.]/g, ''));
  const recalculate = (certificate) => {
    cy.get('#exempt_cert').select(certificate, { force: true }).should('have.value', certificate);
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
      cy.visit(order.editUrl);
      cy.get(`#exempt_cert option[value="${order.certificates.FL}"]`).should('exist');
      cy.get('#order_line_items tr.item').should('have.length', 10);
      cy.get('#order_shipping_line_items tr').should('not.exist');
      cy.on('window:confirm', () => true);
      cy.intercept('POST', '/wp-admin/admin-ajax.php', (req) => {
        const fields = new URLSearchParams(req.body);
        if (fields.get('action') === 'woocommerce_calc_line_taxes' && Number(fields.get('order_id')) === order.id) {
          req.alias = 'adminExemptionRecalculate';
        }
      });
      records().then((rows) => { recordOffset = rows.length; });
    });
  });

  it('retains the customer and FL certificate through repeated admin recalculation and reload', () => {
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
    cy.then(() => recalculate(order.certificates.NY));
    verify(288.40, '');
    cy.reload();
    cy.get('#exempt_cert').should('have.value', '');
  });

  it('charges tax when the administrator leaves the certificate empty', () => {
    recalculate('');
    verify(288.40, '');
  });
});
