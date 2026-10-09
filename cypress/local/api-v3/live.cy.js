// Live HTTP tests for the 14 Sales Tax endpoints used by the plugin, plus auth
// and connection settings. Calls go through Node to keep credentials/tokens out
// of Cypress's command log. No HTTP intercepts or mocked responses are used.
const connection = '/tax/connections/:connectionId';
const address = {
  line1: '323 Washington Ave N', city: 'Minneapolis', state: 'MN',
  zip: '55401-2427', countryCode: 'US',
};

describe('TaxCloud V3 live staging APIs (local only)', () => {
  let runId;
  let customerId;
  let now;
  let cart;
  let certificateId;
  let certificateDisabled = false;
  let importedOrderCreated = false;
  let auth;
  let importedOrderId;
  let convertedOrderId;
  const api = (method, route, body, options = {}) => cy.task('taxcloudV3StagingRequest', {
    method, route, ...(body === undefined ? {} : { body }), ...options,
  }, { log: false });
  const successful = (response, status) => {
    expect(response.status, JSON.stringify(response.body)).to.equal(status);
    expect(response.body).to.be.an('object');
    return response.body;
  };
  const assertOrder = (body, id) => {
    expect(body.orderId).to.equal(id);
    expect(body.customerId).to.equal(customerId);
    expect(body.currency.currencyCode).to.equal('USD');
    expect(body.lineItems).to.have.length(1);
    expect(body.lineItems[0].tax.amount).to.be.a('number').and.be.at.least(0);
    expect(body.lineItems[0].tax.rate).to.be.a('number').and.be.at.least(0);
  };

  before(() => {
    runId = `cy-v3-${Date.now().toString(36)}-${Cypress._.random(100000, 999999)}`;
    customerId = `${runId}-customer`;
    importedOrderId = `${runId}-import`;
    convertedOrderId = `${runId}-converted`;
    now = new Date().toISOString();
    cy.task('taxcloudV3StagingAuthenticate', null, { log: false }).then((result) => { auth = result; });
  });

  after(() => {
    // Clean up only the certificate created by this run, including failed runs.
    if (certificateId && !certificateDisabled) {
      api('DELETE', `${connection}/exemption-certificates/${encodeURIComponent(certificateId)}`).then((response) => {
        expect(response.status, 'cleanup of this run’s certificate').to.equal(204);
      });
    }
  });

  it('POST /api/v3/auth/token exchanges test credentials for a bearer token', () => {
    expect(auth).to.include({ status: 200, hasAccessToken: true, environment: 'staging' });
  });

  it('GET /mgmt/connections/{connectionId} retrieves connection settings', () => {
    api('GET', '/mgmt/connections/:connectionId').then((response) => {
      const body = successful(response, 200);
      expect(body).to.have.property('id').that.is.a('string');
      expect(body.options).to.be.an('object');
    });
  });

  it('GET /tax/connections/{connectionId}/ping verifies authentication', () => {
    api('GET', `${connection}/ping`).then((response) => {
      expect(successful(response, 200).message).to.be.a('string').and.not.be.empty;
    });
  });

  it('POST /tax/verify-address returns a normalized US address', () => {
    api('POST', '/tax/verify-address', address).then((response) => {
      const body = successful(response, 200);
      ['line1', 'city', 'state', 'zip'].forEach((key) => expect(body[key]).to.be.a('string').and.not.be.empty);
      expect(body.state).to.equal('MN');
      expect(body.zip).to.match(/^55401(?:-\d{4})?$/);
    });
  });

  it('POST /tax/tic/search returns ranked TIC results', () => {
    api('POST', '/tax/tic/search', { query: 'Laptop Computer', limit: 5 }).then((response) => {
      const body = successful(response, 200);
      expect(body.query).to.equal('Laptop Computer');
      expect(body.results).to.be.an('array').and.not.be.empty;
      expect(body.results.length).to.be.at.most(5);
      body.results.forEach((result) => {
        expect(result.ticId).to.be.a('number');
        expect(result.description).to.be.a('string');
      });
    });
  });

  it('POST /tax/connections/{connectionId}/exemption-certificates creates a test certificate', () => {
    api('POST', `${connection}/exemption-certificates`, {
      address, customerBusinessType: 'RetailTrade', customerId,
      customerName: 'Cypress Staging Buyer', reason: 'Resale',
      reasonDescription: 'Resale', states: [{ abbreviation: 'MN' }],
    }).then((response) => {
      // Remember the ID before assertions so after() can clean up on failure.
      certificateId = response.body && response.body.certificateId;
      const body = successful(response, 201);
      expect(certificateId).to.be.a('string').and.not.be.empty;
      expect(body.customerId).to.equal(customerId);
      expect(body.reason).to.equal('Resale');
      expect(body.states).to.deep.include({ abbreviation: 'MN' });
      expect(body.disabledAt).to.equal(null);
    });
  });

  it('GET /tax/connections/{connectionId}/exemption-certificates/{id} retrieves that certificate', () => {
    expect(certificateId, 'certificate creation must succeed').to.be.a('string');
    api('GET', `${connection}/exemption-certificates/${encodeURIComponent(certificateId)}`).then((response) => {
      const body = successful(response, 200);
      expect(body.certificateId).to.equal(certificateId);
      expect(body.customerId).to.equal(customerId);
    });
  });

  it('GET /tax/exemption-certificates lists certificates filtered to this test customer', () => {
    expect(certificateId, 'certificate creation must succeed').to.be.a('string');
    api('GET', `/tax/exemption-certificates?customerId=${encodeURIComponent(customerId)}&limit=100&disabled=false`).then((response) => {
      const body = successful(response, 200);
      expect(body.items).to.be.an('array');
      expect(body.items.map((item) => item.certificateId)).to.include(certificateId);
      body.items.forEach((item) => expect(item.customerId).to.equal(customerId));
    });
  });

  it('POST /tax/connections/{connectionId}/carts calculates tax for a unique test cart', () => {
    api('POST', `${connection}/carts`, {
      transactionDate: now,
      items: [{
        cartId: `${runId}-cart`, customerId, currency: { currencyCode: 'USD' },
        origin: address, destination: address, deliveredBySeller: false,
        lineItems: [{ index: 0, itemId: 'cy-product', price: 12.5, quantity: 2, tic: 0 }],
      }],
    }).then((response) => {
      const body = successful(response, 200);
      expect(body.items).to.have.length(1);
      cart = body.items[0];
      expect(cart.cartId).to.equal(`${runId}-cart`);
      expect(cart.customerId).to.equal(customerId);
      expect(cart.lineItems).to.have.length(1);
      expect(cart.lineItems[0].itemId).to.equal('cy-product');
      expect(cart.lineItems[0].tax.amount).to.be.a('number').and.be.at.least(0);
      expect(cart.lineItems[0].tax.rate).to.be.a('number').and.be.at.least(0);
    });
  });

  it('GET /tax/connections/{connectionId}/carts/{id} retrieves the calculated cart', () => {
    expect(cart, 'cart calculation must succeed').to.be.an('object');
    api('GET', `${connection}/carts/${encodeURIComponent(cart.cartId)}`).then((response) => {
      const body = successful(response, 200);
      expect(body.cartId).to.equal(cart.cartId);
      expect(body.customerId).to.equal(customerId);
      expect(body.lineItems[0].tax).to.deep.equal(cart.lineItems[0].tax);
    });
  });

  it('POST /tax/connections/{connectionId}/carts/orders converts the cart to an uncompleted order', () => {
    expect(cart, 'cart calculation must succeed').to.be.an('object');
    api('POST', `${connection}/carts/orders`, {
      cartId: cart.cartId, orderId: convertedOrderId, completed: false,
    }).then((response) => {
      const body = successful(response, 201);
      assertOrder(body, convertedOrderId);
      expect(body.completedDate == null).to.equal(true);
    });
  });

  it('POST /tax/connections/{connectionId}/orders imports a test order excluded from filing', () => {
    expect(cart, 'cart calculation must succeed').to.be.an('object');
    api('POST', `${connection}/orders`, {
      orderId: importedOrderId, customerId, channel: 'woocommerce',
      currency: { currencyCode: 'USD' }, origin: address, destination: address,
      completedDate: now, transactionDate: now, excludeFromFiling: true,
      lineItems: cart.lineItems.map(({ index, itemId, price, quantity, tic, tax }) => ({ index, itemId, price, quantity, tic, tax })),
    }).then((response) => {
      if (response.status === 201) importedOrderCreated = true;
      const body = successful(response, 201);
      assertOrder(body, importedOrderId);
      expect(body.excludeFromFiling).to.equal(true);
    });
  });

  it('GET /tax/connections/{connectionId}/orders/{id} retrieves the imported order', () => {
    expect(importedOrderCreated, 'order creation must succeed').to.equal(true);
    api('GET', `${connection}/orders/${encodeURIComponent(importedOrderId)}`).then((response) => {
      const body = successful(response, 200);
      assertOrder(body, importedOrderId);
      expect(body.excludeFromFiling).to.equal(true);
    });
  });

  it('PATCH /tax/connections/{connectionId}/orders/{id} updates its completion date', () => {
    expect(importedOrderCreated, 'order creation must succeed').to.equal(true);
    const completedDate = new Date().toISOString();
    api('PATCH', `${connection}/orders/${encodeURIComponent(importedOrderId)}`, { completedDate }).then((response) => {
      const body = successful(response, 200);
      assertOrder(body, importedOrderId);
      expect(Date.parse(body.completedDate)).to.equal(Date.parse(completedDate));
      expect(body.excludeFromFiling).to.equal(true);
    });
  });

  it('POST /tax/connections/{connectionId}/orders/refunds/{id} refunds a test line and supports idempotency', () => {
    expect(importedOrderCreated, 'order creation must succeed').to.equal(true);
    const body = { items: [{ itemId: 'cy-product', quantity: 1, cartItemIndex: 0 }], idempotencyKey: `${runId}-refund` };
    const route = `${connection}/orders/refunds/${encodeURIComponent(importedOrderId)}`;
    api('POST', route, body).then((response) => {
      expect(response.status, JSON.stringify(response.body)).to.equal(201);
      expect(response.body).to.be.an('array').and.not.be.empty;
      const refund = response.body.find((item) => item.idempotencyKey === body.idempotencyKey);
      expect(refund).to.be.an('object');
      expect(refund.items).to.have.length(1);
      expect(refund.items[0]).to.include({ itemId: 'cy-product', quantity: 1 });
      expect(refund.items[0].tax.amount).to.be.a('number').and.be.at.least(0);
      api('POST', route, body).then((retry) => {
        expect(retry.status).to.equal(200);
        expect(retry.body).to.deep.equal(response.body);
      });
    });
  });

  it('DELETE /tax/connections/{connectionId}/exemption-certificates/{id} disables the test certificate', () => {
    expect(certificateId, 'certificate creation must succeed').to.be.a('string');
    api('DELETE', `${connection}/exemption-certificates/${encodeURIComponent(certificateId)}`).then((response) => {
      certificateDisabled = response.status === 204;
      expect(response.status, JSON.stringify(response.body)).to.equal(204);
      expect(response.body).to.equal(null);
    });
  });

  it('rejects an unauthenticated ping without touching existing records', () => {
    api('GET', `${connection}/ping`, undefined, { authenticated: false }).then((response) => {
      expect(response.status).to.equal(401);
    });
  });

  it('rejects a cart missing required customer and line item data', () => {
    api('POST', `${connection}/carts`, { items: [{ cartId: `${runId}-invalid` }] }).then((response) => {
      expect(response.status).to.equal(422);
    });
  });
});
