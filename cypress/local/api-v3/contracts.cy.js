const { endpoints, cart, order, date } = require('./endpoints');

const environments = {
  production: {
    base: 'https://api.v3.taxcloud.com',
    auth: 'https://taxcloudapi-appservice-core-prod.azurewebsites.net/api/v3/auth/token',
  },
  staging: {
    base: 'https://api.v3.taxcloud.net',
    auth: 'https://staging-taxcloudapi.azurewebsites.net/api/v3/auth/token',
  },
};

Object.entries(environments).forEach(([environment, urls]) => {
  const fixture = (endpoint, status = endpoint.status || 200, body) => ({
    method: endpoint.method,
    url: urls.base + endpoint.path + (endpoint.query || ''),
    status,
    body: body === undefined ? (status === 204 ? '' : JSON.stringify(endpoint.response)) : body,
  });
  const call = (endpoint, overrides = {}) => cy.task('taxcloudV3Contract', {
    environment, operation: endpoint.operation, args: endpoint.args,
    responses: [fixture(endpoint)], ...overrides,
  });
  const checkRequest = (request, endpoint, token = 'test-token') => {
    expect(request.method).to.equal(endpoint.method);
    expect(request.url).to.equal(urls.base + endpoint.path + (endpoint.query || ''));
    expect(request.args.headers).to.include({
      Authorization: `Bearer ${token}`, 'Content-Type': 'application/json',
      'User-Agent': 'SimpleSalesTax/V3-contract-tests',
    });
    expect(request.args.timeout).to.equal(30);
    if (endpoint.body === undefined) {
      expect(request.args).not.to.have.property('body');
    } else {
      expect(JSON.parse(request.args.body)).to.deep.equal(endpoint.body);
    }
  };

  describe(`TaxCloud V3 contracts: ${environment} URL configuration (offline)`, () => {
    endpoints.forEach((endpoint) => {
      describe(`${endpoint.operation}: ${endpoint.method} ${endpoint.path}`, () => {
        it('sends the expected payload and returns the successful response', () => {
          call(endpoint).then((output) => {
            expect(output.error).to.equal(null);
            expect(output.result).to.deep.equal(endpoint.response);
            expect(output.requests).to.have.length(1);
            checkRequest(output.requests[0], endpoint);
          });
        });

        it('exchanges credentials before the API call when no token is cached', () => {
          call(endpoint, {
            cacheToken: false,
            responses: [
              { method: 'POST', url: urls.auth, status: 200, body: JSON.stringify({ access_token: 'issued-token' }) },
              fixture(endpoint),
            ],
          }).then((output) => {
            expect(output.error).to.equal(null);
            expect(output.result).to.deep.equal(endpoint.response);
            expect(output.requests).to.have.length(2);
            const auth = output.requests[0];
            expect(JSON.parse(auth.args.body)).to.deep.equal({ apiLoginID: 'test-login', apiKey: 'test-connection' });
            expect(auth.args.headers['Content-Type']).to.equal('application/json');
            expect(auth.args.timeout).to.equal(30);
            checkRequest(output.requests[1], endpoint, 'issued-token');
          });
        });

        it('stops before calling the endpoint when authentication is rejected', () => {
          call(endpoint, {
            cacheToken: false,
            responses: [{ method: 'POST', url: urls.auth, status: 401, body: '{"message":"Invalid credentials"}' }],
          }).then((output) => {
            expect(output.error.code).to.equal('sst_v3_auth_error');
            expect(output.error.message).to.include('Invalid credentials');
            expect(output.requests).to.have.length(1);
            expect(output.requests[0].url).to.equal(urls.auth);
          });
        });

        [401, 422, 429, 500].forEach((status) => {
          it(`returns HTTP ${status} as an error without retries or V1 failover`, () => {
            call(endpoint, { responses: [fixture(endpoint, status, '{"detail":"TaxCloud rejected this request."}')] }).then((output) => {
              expect(output.error.code).to.equal(endpoint.error);
              expect(output.error.message).to.include('TaxCloud rejected this request.');
              expect(output.result).to.equal(null);
              expect(output.requests).to.have.length(1);
            });
          });
        });

        it('returns transport errors without retries or failover', () => {
          call(endpoint, { responses: [{ ...fixture(endpoint), transportError: 'Connection timed out.' }] }).then((output) => {
            expect(output.error).to.deep.equal({ code: 'http_request_failed', message: 'Connection timed out.' });
            expect(output.requests).to.have.length(1);
          });
        });

        if (endpoint.method !== 'DELETE') {
          it('rejects malformed JSON in a successful response', () => {
            call(endpoint, { responses: [fixture(endpoint, 200, '{')] }).then((output) => {
              expect(output.error.code).to.equal(endpoint.error);
              expect(output.error.message).to.include('invalid JSON');
            });
          });
        }
      });
    });

    describe('authentication and management APIs', () => {
      const auth = { operation: 'authenticate', method: 'POST', args: [] };
      const authCall = (status, body, args = []) => call(auth, {
        cacheToken: false, args,
        responses: [{ method: 'POST', url: urls.auth, status, body: JSON.stringify(body) }],
      });
      it('POST /api/v3/auth/token caches the token for twelve hours and stores the integration ID', () => {
        authCall(200, { access_token: 'issued-token', connection_id: 'integration-123456' }).then((output) => {
          expect(output.result).to.equal('issued-token');
          expect(output.error).to.equal(null);
          expect(JSON.parse(output.requests[0].args.body)).to.deep.equal({ apiLoginID: 'test-login', apiKey: 'test-connection' });
          expect(Object.values(output.transients)).to.deep.equal([{ value: 'issued-token', expiration: 43200 }]);
          expect(output.settings.tc_integration_id).to.equal('integration-123456');
        });
      });
      it('POST /api/v3/auth/token rejects responses without an access token', () => {
        authCall(200, {}).then((output) => {
          expect(output.error.code).to.equal('sst_v3_auth_error');
          expect(output.transients).to.deep.equal([]);
        });
      });
      it('POST /api/v3/auth/token supports explicit credential overrides', () => {
        authCall(200, { access_token: 'override-token' }, ['override-login', 'override-key']).then((output) => {
          expect(output.result).to.equal('override-token');
          expect(JSON.parse(output.requests[0].args.body)).to.deep.equal({ apiLoginID: 'override-login', apiKey: 'override-key' });
        });
      });
      const settings = {
        operation: 'connectionSettings', method: 'GET', path: '/mgmt/connections/test-connection',
        args: ['test-connection', 'management-token'],
        response: { id: 'test-connection', options: { data_mover: { flag: true } } },
      };
      it('GET /mgmt/connections/{connectionId} returns connection settings', () => {
        call(settings).then((output) => {
          expect(output.result).to.deep.equal(settings.response);
          expect(output.error).to.equal(null);
          expect(output.requests).to.have.length(1);
          expect(output.requests[0].args.headers.Authorization).to.equal('Bearer management-token');
          expect(output.requests[0].args).not.to.have.property('body');
        });
      });
      it('GET /mgmt/connections/{connectionId} treats a missing connection as empty settings', () => {
        call(settings, { responses: [fixture(settings, 404, '{}')] }).then((output) => {
          expect(output.error).to.equal(null);
          expect(output.result).to.deep.equal([]);
        });
      });
      it('GET /mgmt/connections/{connectionId} returns rejected credentials as a settings error', () => {
        call(settings, { responses: [fixture(settings, 401, '{"message":"Unauthorized"}')] }).then((output) => {
          expect(output.error.code).to.equal('sst_v3_settings_error');
          expect(output.error.message).to.include('Unauthorized');
        });
      });
    });

    describe('payload validation and identifiers', () => {
      [
        ['calculateCarts', [{ items: [] }], 'sst_v3_carts_invalid_request'],
        ['createOrderFromCart', ['', 'order-1'], 'sst_v3_carts_invalid_request'],
        ['getCart', [''], 'sst_v3_carts_error'],
        ['createOrder', [{}], 'sst_v3_orders_invalid_request'],
        ['getOrder', [''], 'sst_v3_orders_error'],
        ['updateOrder', [''], 'sst_v3_orders_error'],
        ['refundOrder', ['order-1', { items: [{ itemId: 'product-1' }] }], 'sst_v3_refunds_invalid_request'],
        ['createCertificate', [{}], 'sst_v3_exemptions_invalid_request'],
        ['getCertificate', [''], 'sst_v3_exemptions_invalid_request'],
        ['disableCertificate', [''], 'sst_v3_exemptions_invalid_request'],
        ['searchTics', ['  '], 'sst_v3_tic_search_invalid_request'],
        ['verifyAddress', [{}], 'sst_v3_verify_address_invalid_address'],
      ].forEach(([operation, args, error]) => {
        it(`${operation} rejects invalid input before authentication or HTTP`, () => {
          call({ operation, args }, { cacheToken: false, responses: [] }).then((output) => {
            expect(output.error.code).to.equal(error);
            expect(output.requests).to.deep.equal([]);
          });
        });
      });
      ['getCart', 'getOrder', 'getCertificate', 'disableCertificate', 'refundOrder'].forEach((operation) => {
        it(`${operation} encodes reserved characters in connection and resource IDs`, () => {
          const original = endpoints.find((endpoint) => endpoint.operation === operation);
          const endpoint = {
            ...original,
            path: original.path.replace('test-connection', 'connection%20%2F%3F%23').replace(/(cart-1|order-1|cert-1)$/, 'resource%20%2F%3F%23'),
            args: ['resource /?#', ...original.args.slice(1)],
          };
          call(endpoint, { settings: { tc_key: 'connection /?#' } }).then((output) => {
            expect(output.error).to.equal(null);
            checkRequest(output.requests[0], endpoint);
          });
        });
      });
      it('cart payloads preserve zero prices, fractional quantities, exemptions and multiple carts', () => {
        const original = endpoints[0];
        const first = { ...cart, exemption: { exemptionId: 'cert-1', isExempt: false }, lineItems: [{ index: '0', itemId: 'free-product', price: '0', quantity: '1.5', tic: '0' }] };
        const second = { ...cart, cartId: 'cart-2', customerId: 'customer-2', deliveredBySeller: true };
        call(original, { args: [{ items: [first, second], transactionDate: date }] }).then((output) => {
          expect(output.error).to.equal(null);
          const body = JSON.parse(output.requests[0].args.body);
          expect(body.items).to.have.length(2);
          expect(body.items[0].lineItems[0]).to.deep.equal({ index: 0, itemId: 'free-product', price: 0, quantity: 1.5, tic: 0 });
          expect(body.items[0].exemption).to.deep.equal(first.exemption);
          expect(body.items[1].deliveredBySeller).to.equal(true);
        });
      });
      it('order payloads round tax amounts while preserving tax rate precision', () => {
        const original = endpoints.find((endpoint) => endpoint.operation === 'createOrder');
        const args = { ...order, lineItems: [{ ...order.lineItems[0], tax: { amount: 2.034, rate: 0.08125 } }] };
        call(original, { args: [args] }).then((output) => {
          expect(output.error).to.equal(null);
          expect(JSON.parse(output.requests[0].args.body).lineItems[0].tax).to.deep.equal({ amount: 2.03, rate: 0.08125 });
        });
      });
      it('a full refund sends an empty JSON object', () => {
        const original = endpoints.find((endpoint) => endpoint.operation === 'refundOrder');
        call(original, { args: ['order-1'] }).then((output) => {
          expect(output.error).to.equal(null);
          expect(output.requests[0].args.body).to.equal('{}');
        });
      });
    });
  });
});
