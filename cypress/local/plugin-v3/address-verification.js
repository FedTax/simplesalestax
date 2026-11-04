// DEV-10605: real WooCommerce checkout/order persistence; only TaxCloud HTTP is simulated.
module.exports = ({ task, records, getSite }) => describe('DEV-10605 shipping address verification (local fixtures only)', () => {
  const error = "We couldn't verify your shipping address. Please check the street, city and ZIP code.";
  const correction = 'TaxCloud verified the shipping address: city "Riverisde" → "Riverside", ZIP 92508 → 92508-8114';
  const failure = (reason) => `TaxCloud could not verify the shipping address (${reason}). Tax was calculated on the address as entered.`;
  let site;
  let address;
  let offset;

  before(() => { site = getSite(); });
  beforeEach(() => records().then((rows) => { offset = rows.length; }));
  afterEach(() => task('addressVerificationReset'));

  const start = (outcome, required, blocks = false) => task('addressVerification', { outcome, required, blocks }).then((fixture) => {
    address = fixture.address;
    // Fresh sessions pick up the scenario's shipping address and discard any
    // exemptions/cart left by earlier plugin workflows.
    cy.clearAllCookies();
    cy.visit('/wp-login.php');
    cy.setCookie('sst_e2e_run', Cypress.env('pluginV3Run'));
    cy.request({ method: 'POST', url: '/wp-login.php', form: true, log: false,
      body: { log: site.username, pwd: site.password, 'wp-submit': 'Log In', testcookie: '1' } });
    cy.getAllCookies().should((cookies) => {
      expect(cookies.some((cookie) => cookie.name.startsWith('wordpress_logged_in_')), 'customer is logged in').to.equal(true);
    });
    cy.visit(`/?add-to-cart=${fixture.productId}&quantity=1`);
    if (blocks) {
      cy.intercept('POST', '**/wc/store/v1/checkout*').as('addressSubmit');
      cy.visit(site.checkoutBlock);
      cy.get('.wc-block-checkout').should('be.visible');
      cy.contains('.wc-block-checkout', address.address_1).should('be.visible');
      cy.get('.wc-block-components-totals-footer-item-tax-value').should(($values) => {
        const rendered = $values.filter((_, value) => value.getClientRects().length > 0);
        expect(rendered.length, 'rendered total amounts').to.be.greaterThan(0);
        rendered.each((_, value) => expect(Number(value.textContent.replace(/[^\d.]/g, ''))).to.equal(110));
      });
    } else {
      cy.intercept('POST', '**/*wc-ajax=update_order_review*').as('addressReview');
      cy.intercept('POST', '**/*wc-ajax=checkout*').as('addressSubmit');
      cy.visit(site.checkoutClassic);
      cy.wait('@addressReview', { timeout: 60000 });
      cy.get('.blockUI', { timeout: 60000 }).should('not.exist');
      cy.get('#billing_address_1').should('have.value', address.address_1);
      cy.get('#billing_city').should('have.value', address.city);
      cy.get('#order_review .tax-rate .amount').should('have.text', '$10.00');
      cy.get('#payment_method_cheque').check({ force: true });
    }
  });
  const submit = (blocks) => blocks
    ? cy.get('.wc-block-components-checkout-place-order-button').click()
    : cy.get('#place_order').click();
  const placed = () => {
    cy.location('pathname', { timeout: 60000 }).should('include', '/order-received/');
    return cy.location('pathname').then((path) => Number(path.match(/order-received\/(\d+)/)[1]));
  };
  const verifyOrder = (id, status, note) => task('order', { id }).then((order) => {
    expect(order.tax).to.equal(10);
    expect(order.total).to.equal(110);
    expect(Object.values(order.verification).map((outcome) => outcome.status)).to.deep.equal([status]);
    expect(order.verificationNotes, 'one private verification note').to.deep.equal(note
      ? [{ content: note, customerNote: false }] : []);
  });
  const verifications = (rows) => rows.slice(offset).filter((row) => row.path === '/tax/verify-address');
  const verifyDestination = (city, zip) => records().then((rows) => {
    const quotes = rows.slice(offset).filter((row) => row.method === 'POST' && /\/carts$/.test(row.path));
    expect(quotes, 'server-side TaxCloud quote').not.to.be.empty;
    expect(quotes[quotes.length - 1].request.items[0].destination).to.include({ city, zip });
  });
  const retries = () => {
    let count;
    records().then((rows) => { count = verifications(rows).length; expect(count).to.be.greaterThan(0); });
    // A second real cart lookup for the exact same address must verify again.
    cy.request('/wp-json/wc/store/v1/cart').its('status').should('equal', 200);
    records().then((rows) => expect(verifications(rows).length, 'temporary verification failure was not cached').to.be.greaterThan(count));
  };

  [false, true].forEach((blocks) => {
    const checkout = blocks ? 'Blocks' : 'classic';
    it(`${checkout}: taxes the corrected city/ZIP+4 and writes one correction note`, () => {
      start('corrected', true, blocks);
      verifyDestination('Riverside', '92508-8114');
      submit(blocks);
      placed().then((id) => verifyOrder(id, 'corrected', correction));
      records().then((rows) => expect(verifications(rows), 'successful verification is cached').to.have.length(1));
    });

    it(`${checkout}: setting off allows a rejected address and writes a failure note`, () => {
      start('rejected', false, blocks);
      verifyDestination('Riverisde', '92508');
      submit(blocks);
      placed().then((id) => verifyOrder(id, 'rejected', failure('address not found')));
      records().then((rows) => expect(verifications(rows), 'permanent rejection is cached').to.have.length(1));
    });

    it(`${checkout}: setting on blocks rejection without creating an order and recovers after an address edit`, () => {
      let orderIds;
      start('rejected', true, blocks);
      task('addressOrderIds').then((ids) => { orderIds = ids; });
      submit(blocks);
      cy.wait('@addressSubmit', { timeout: 60000 }).then(({ response }) => {
        if (blocks) expect(response.statusCode).to.equal(400);
        else expect(response.body.result).to.equal('failure');
      });
      cy.contains(error).should('be.visible');
      cy.location('pathname').should('not.include', '/order-received/');
      task('addressOrderIds').then((ids) => expect(ids, 'rejected checkout creates no order').to.deep.equal(orderIds));
      cy.screenshot(`DEV-10605-${checkout}-rejected-address`, { capture: 'fullPage' });

      task('addressResponse', { outcome: 'corrected' });
      if (blocks) {
        cy.get('[aria-label="Edit shipping address"]').click();
        cy.then(() => cy.get('#shipping-address_1').clear().type(`${address.address_1} Corrected`).blur());
      } else {
        cy.then(() => cy.get('#billing_address_1').clear().type(`${address.address_1} Corrected`).blur());
        cy.wait('@addressReview', { timeout: 60000 });
        cy.get('.blockUI', { timeout: 60000 }).should('not.exist');
      }
      submit(blocks);
      placed().then((id) => verifyOrder(id, 'corrected', correction));
    });

    it(`${checkout}: timeout never blocks, writes a failure note, and the next lookup verifies again`, () => {
      start('timeout', true, blocks);
      retries();
      submit(blocks);
      placed().then((id) => verifyOrder(id, 'transient_failure', failure('TaxCloud unavailable')));
    });
  });

  ['verified', 'standardized'].forEach((outcome) => {
    it(`does not add a note for ${outcome === 'verified' ? 'an unchanged verified address' : 'street/case standardization and ZIP+4 alone'}`, () => {
      start(outcome, true);
      submit(false);
      placed().then((id) => verifyOrder(id, 'verified', ''));
    });
  });

  [
    { outcome: 'unavailable', status: 503, reason: 'TaxCloud unavailable' },
    { outcome: 'unauthorized', status: 401, reason: 'TaxCloud unavailable' },
    { outcome: 'rateLimited', status: 429, reason: 'rate limit reached' },
  ].forEach(({ outcome, status, reason }) => {
    it(`${status} verification errors allow checkout with the requirement on and are retried`, () => {
      start(outcome, true);
      retries();
      submit(false);
      placed().then((id) => verifyOrder(id, 'transient_failure', failure(reason)));
      records().then((rows) => verifications(rows).forEach((row) => expect(row.status).to.equal(status)));
    });
  });

  it('admin-created orders remain nonblocking and repeated recalculation does not duplicate the note', () => {
    task('addressVerification', { outcome: 'rejected', required: true });
    task('addressAdminOrder').then(({ id }) => verifyOrder(id, 'rejected', failure('address not found')));
  });
});
