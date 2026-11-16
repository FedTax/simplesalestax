describe('DEV-9565 WooCommerce Product Bundles compatibility', () => {
  let site;
  let products;
  const task = (action, data = {}) => cy.task('pluginV3', { action, ...data }, { log: false });
  const storeCart = () => cy.request('/wp-json/wc/store/v1/cart').its('body.items');

  before(() => {
    task('begin').then((info) => { site = info; });
    task('productBundlesSetup').then((fixture) => { products = fixture; });
  });
  after(() => task('end'));

  const mode = (active, blocks = false) => {
    task('productBundlesMode', { active, blocks });
    task('productBundlesState').should('equal', active);
  };
  const freshCart = () => {
    cy.visit('/wp-login.php');
    cy.setCookie('sst_e2e_run', site.run);
    cy.request({ method: 'POST', url: '/wp-login.php', form: true, log: false,
      body: { log: site.username, pwd: site.password, 'wp-submit': 'Log In', testcookie: '1' } });
    cy.getAllCookies().should((cookies) => {
      expect(cookies.some((cookie) => cookie.name.startsWith('wordpress_logged_in_'))).to.equal(true);
    });
    cy.request({ method: 'DELETE', url: '/wp-json/wc/store/v1/cart/items' });
  };
  const addBundle = (url) => {
    cy.visit(url);
    cy.get('button.bundle_add_to_cart_button').should('be.enabled').click();
    cy.contains('has been added to your cart').should('be.visible');
  };
  const addStandalone = () => cy.visit(`/?add-to-cart=${products.component}`);

  const checkout = (tax, blocks = false) => {
    if (blocks) {
      cy.visit(site.checkoutBlock);
      cy.get('.wc-block-checkout').should('be.visible');
      cy.get('input[value="cheque"]').check({ force: true });
      cy.get('.wc-block-components-checkout-place-order-button').click();
    } else {
      cy.intercept('POST', '**/*wc-ajax=update_order_review*').as('bundleReview');
      cy.visit(site.checkoutClassic);
      cy.wait('@bundleReview');
      cy.get('#order_review .tax-rate .amount').should('have.text', `$${tax.toFixed(2)}`);
      cy.get('#payment_method_cheque').check({ force: true });
      cy.get('#place_order').click();
    }
    cy.location('pathname', { timeout: 60000 }).should('include', '/order-received/');
    return cy.location('pathname').then((pathname) => Number(pathname.match(/order-received\/(\d+)/)[1]));
  };

  const verifyCapturedOrder = (id, expectedLines, tax, total) => {
    task('order', { id }).then((order) => {
      expect(order.tax).to.equal(tax);
      expect(order.total).to.equal(total);
      expect(order.packages).to.have.length(1);
      task('records').then((rows) => {
        const request = rows.find((row) => row.method === 'POST' && /\/carts$/.test(row.path)
          && row.request.items.some((cart) => cart.cartId === order.packages[0].cart_id));
        expect(request, 'saved package has a matching TaxCloud lookup').to.exist;
        expect(request.status).to.equal(200);
        const cart = request.request.items.find(({ cartId }) => cartId === order.packages[0].cart_id);
        const lines = cart.lineItems.map(({ itemId, quantity, price, tic }) => [itemId, quantity, price, tic]);
        expect(lines).to.have.deep.members(expectedLines);
      });
    });
    task('productBundlesCapture', { id }).should('equal', 'completed');
    task('order', { id }).its('taxcloudStatus').should('equal', 'captured');
  };

  it('taxes a fixed-price bundle once with the parent TIC in classic checkout', () => {
    mode(true);
    freshCart();
    addBundle(products.fixedUrl);
    checkout(8.5).then((id) => verifyCapturedOrder(id, [
      [String(products.fixed), 1, 80, 40010],
      [String(products.component), 2, 0, 20010],
      ['SHIPPING', 1, 5, 11010],
    ], 8.5, 93.5));
  });

  it('taxes packed components at their discounted price in Blocks and refunds the original line with Bundles off', () => {
    mode(true, true);
    freshCart();
    addBundle(products.mixedUrl);
    addStandalone();
    checkout(11.5, true).then((id) => {
      verifyCapturedOrder(id, [
        [String(products.mixed), 1, 10, 40010],
        [String(products.component), 2, 30, 20010],
        [String(products.component), 1, 40, 20010],
        ['SHIPPING', 1, 5, 11010],
      ], 11.5, 126.5);
      mode(false);
      task('order', { id }).then((order) => {
        const child = order.lines.find((line) => line.bundleChild && line.productId === products.component);
        expect(child).to.include({ quantity: 2, total: 60 });
        const captured = order.packages[0].cart_items.find((line) => line.itemId === String(products.component) && line.price === 30);
        task('productBundlesRefund', { id, lineId: child.id }).then((refundId) => {
          task('records').then((rows) => {
            const refund = rows.find((row) => row.method === 'POST' && /\/orders\/refunds\//.test(row.path)
              && row.request.idempotencyKey === `woocommerce-refund-${refundId}`);
            expect(refund, 'TaxCloud received the WooCommerce refund').to.exist;
            expect(refund.status).to.equal(201);
            expect(refund.request.items).to.deep.equal([
              { itemId: String(products.component), quantity: 1, cartItemIndex: captured.index },
            ]);
          });
          task('order', { id }).its('refunded').should('equal', 33);
        });
      });
    });
  });

  it('removes a saved bundle with Bundles off and still taxes and captures a standalone product', () => {
    mode(true);
    freshCart();
    addBundle(products.fixedUrl);
    addStandalone();
    storeCart().should((items) => expect(items.map(({ id }) => id)).to.include(products.fixed));
    mode(false);
    cy.visit(products.fixedUrl);
    cy.get('form.cart button[name="add-to-cart"]').should('not.exist');
    cy.visit(`/?add-to-cart=${products.fixed}`);
    storeCart().should((items) => {
      expect(items.map(({ id, quantity }) => [id, quantity])).to.deep.equal([[products.component, 1]]);
    });
    checkout(4.5).then((id) => verifyCapturedOrder(id, [
      [String(products.component), 1, 40, 20010],
      ['SHIPPING', 1, 5, 11010],
    ], 4.5, 49.5));
  });
});
