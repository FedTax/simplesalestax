// Local characterization of ticket #86850. The browser, WooCommerce, SST,
// category save and persisted orders are real. Only TaxCloud HTTP is mocked.
// The fixture assumes 7.5% ordinary CO sales tax and a 100% response for 99990;
// it does not prove these rates against the live TaxCloud service.
module.exports = (getSite) => describe('Colorado category TIC and excise behavior (local fixtures only)', () => {
  let site;
  let recordOffset;
  const task = (action, data = {}) => cy.task('pluginV3', { action, ...data }, { log: false });
  const money = ($element) => Number($element.text().replace(/[^\d.]/g, ''));
  const login = (admin = false) => {
    cy.clearAllCookies();
    cy.visit('/wp-login.php');
    cy.setCookie('sst_e2e_run', Cypress.env('pluginV3Run'));
    cy.request({ method: 'POST', url: '/wp-login.php', form: true, log: false,
      body: { log: admin ? 'admin' : site.username, pwd: admin ? 'password' : site.password,
        'wp-submit': 'Log In', testcookie: '1' } });
    cy.getAllCookies().should((cookies) => {
      expect(cookies.some((cookie) => cookie.name.startsWith('wordpress_logged_in_'))).to.equal(true);
    });
  };

  before(() => { site = getSite(); });
  beforeEach(() => task('records').then((rows) => { recordOffset = rows.length; }));

  const scenarios = [
    { name: 'reproduces inherited 99990: full-price sales tax and no excise fee',
      categoryTic: '99990', productTic: '', state: 'CO', quantity: 1, tax: 100, excise: 0, saveCategory: true },
    { name: 'adds 6.5% excise for a product inheriting firearms TIC 90505',
      categoryTic: '90505', productTic: '', state: 'CO', quantity: 1, tax: 7.5, excise: 6.5 },
    { name: 'adds 6.5% excise for a product inheriting ammunition TIC 90506',
      categoryTic: '90506', productTic: '', state: 'CO', quantity: 1, tax: 7.5, excise: 6.5 },
    { name: 'uses an explicit product TIC instead of category 99990',
      categoryTic: '99990', productTic: '90505', state: 'CO', quantity: 1, tax: 7.5, excise: 6.5 },
    { name: 'recalculates sales tax and a single excise fee when quantity changes',
      categoryTic: '90505', productTic: '', state: 'CO', quantity: 2, tax: 15, excise: 13 },
    { name: 'does not add Colorado excise to a Minnesota firearms order',
      categoryTic: '90505', productTic: '', state: 'MN', quantity: 1, tax: 10, excise: 0 },
  ];

  scenarios.forEach((scenario) => {
    it(scenario.name, () => {
      task('coTax', { categoryTic: scenario.saveCategory ? '' : scenario.categoryTic,
        productTic: scenario.productTic, state: scenario.state }).then((fixture) => {
        if (scenario.saveCategory) {
          login(true);
          cy.visit(`/wp-admin/term.php?taxonomy=product_cat&tag_ID=${fixture.categoryId}&post_type=product`);
          cy.get('.sst-select-tic').click();
          cy.get('.sst-tic-search').type('99990');
          cy.contains('.tic-row', 'Colorado Excise Tax').find('button').click();
          cy.get('.sst-tic-input').should('have.value', '99990');
          cy.get('input[type="submit"]').click();
          cy.get('.sst-tic-input').should('have.value', '99990');
        }
        task('coTaxState').then((state) => {
          expect(state.categoryTic).to.equal(scenario.categoryTic);
          expect(state.productTic).to.equal(scenario.productTic);
          expect(state.effectiveTic).to.equal(scenario.productTic || scenario.categoryTic);
        });

        login();
        cy.request({ method: 'DELETE', url: '/wp-json/wc/store/v1/cart/items' });
        cy.visit(`/?add-to-cart=${site.productId}&quantity=1`);
        if (scenario.quantity !== 1) {
          cy.visit(site.cartClassic);
          cy.get('input.qty').clear().type(String(scenario.quantity));
          cy.get('button[name="update_cart"]').click();
          cy.get('.blockUI', { timeout: 60000 }).should('not.exist');
          cy.get('input.qty').should('have.value', String(scenario.quantity));
        }
        cy.intercept('POST', '**/*wc-ajax=update_order_review*').as('review');
        cy.visit(site.checkoutClassic);
        cy.wait('@review', { timeout: 60000 });
        cy.get('.blockUI', { timeout: 60000 }).should('not.exist');
        cy.get('#billing_state').should('have.value', scenario.state);
        cy.get('#billing_postcode').should('have.value', fixture.address.postcode);
        cy.get('#billing_first_name').clear().type('Cypress');
        cy.get('#billing_last_name').clear().type('Colorado');
        cy.get('#billing_phone').clear().type('3035550100');
        cy.get('#billing_email').clear().type(`${site.username}@example.test`).blur();
        cy.get('.blockUI', { timeout: 60000 }).should('not.exist');

        const total = 100 * scenario.quantity + scenario.tax + scenario.excise;
        cy.get('#order_review .tax-rate .amount').should(($amount) => expect(money($amount)).to.equal(scenario.tax));
        cy.get('#order_review .order-total .amount').should(($amount) => expect(money($amount)).to.equal(total));
        if (scenario.excise) {
          cy.get('#order_review tr.fee').should('have.length', 1).and('contain.text', 'CO EXCISE TAX');
          cy.get('#order_review tr.fee .amount').should(($amount) => expect(money($amount)).to.equal(scenario.excise));
        } else {
          cy.get('#order_review tr.fee').should('not.exist');
        }
        task('records').then((rows) => {
          const quotes = rows.slice(recordOffset).filter((row) => row.method === 'POST' && /\/carts$/.test(row.path));
          expect(quotes, 'checkout called the PHP TaxCloud client').not.to.be.empty;
          const quote = quotes[quotes.length - 1];
          expect(quote.status).to.equal(200);
          expect(quote.credentialsMatched).to.equal(true);
          const cart = quote.request.items[0];
          expect(cart.destination.state).to.equal(scenario.state);
          expect(cart).not.to.have.property('exemption');
          const product = cart.lineItems.find((item) => item.itemId === String(site.productId));
          expect(product.tic).to.equal(Number(scenario.productTic || scenario.categoryTic));
          expect(product.price * product.quantity).to.equal(100 * scenario.quantity);
          const productTax = quote.response.items[0].lineItems.find((item) => item.index === product.index).tax.amount;
          expect(productTax).to.equal(scenario.tax);
          const fees = cart.lineItems.filter((item) => item.itemId === 'co-excise-tax');
          expect(fees).to.have.length(scenario.excise ? 1 : 0);
          if (scenario.excise) {
            expect(fees[0].tic).to.equal(99990);
            expect(fees[0].price).to.equal(scenario.excise);
            const feeTax = quote.response.items[0].lineItems.find((item) => item.index === fees[0].index).tax.amount;
            expect(feeTax, 'fixture returns the whole excise amount; plugin must not charge it twice').to.equal(scenario.excise);
          }
        });

        cy.get('#payment_method_cheque').check({ force: true });
        cy.get('#place_order').click();
        cy.location('pathname', { timeout: 60000 }).should('include', '/order-received/');
        cy.location('pathname').then((pathname) => {
          const id = Number(pathname.match(/order-received\/(\d+)/)[1]);
          task('order', { id }).then((order) => {
            expect(order.tax).to.equal(scenario.tax);
            expect(order.total).to.equal(total);
            expect(order.fees).to.deep.equal(scenario.excise
              ? [{ name: 'CO EXCISE TAX', total: scenario.excise, tax: 0 }] : []);
            expect(order.packages).not.to.be.empty;
            order.packages.forEach((pack) => expect(pack.api_version).to.equal('v3'));
            cy.log(`Order ${id}: sales tax $${order.tax}, excise $${scenario.excise}, total $${order.total}`);
          });
        });
      });
    });
  });
});
