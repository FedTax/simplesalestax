// Independent request/response fixtures for every Sales Tax V3 client operation.
const date = '2026-09-24T12:00:00Z';
const address = {
  line1: '123 Main St', line2: 'Suite 2', city: 'Seattle',
  state: 'WA', zip: '98101', countryCode: 'US',
};
const item = { index: 0, itemId: 'product-1', price: 12.5, quantity: 2, tic: 10010 };
const cart = {
  cartId: 'cart-1', customerId: 'customer-1', currencyCode: 'USD',
  deliveredBySeller: false, origin: address, destination: address, lineItems: [item],
};
const cartBody = {
  cartId: 'cart-1', customerId: 'customer-1', currency: { currencyCode: 'USD' },
  deliveredBySeller: false, origin: address, destination: address, lineItems: [item],
};
const order = {
  orderId: 'order-1', customerId: 'customer-1', currencyCode: 'USD',
  completedDate: date, transactionDate: date, origin: address, destination: address,
  lineItems: [{ ...item, tax: { amount: 2.03, rate: 0.08125 } }],
};
const orderBody = {
  orderId: 'order-1', customerId: 'customer-1', currency: { currencyCode: 'USD' },
  channel: 'woocommerce', completedDate: date, transactionDate: date,
  origin: address, destination: address, lineItems: order.lineItems,
};
const certificate = {
  address, customerBusinessType: 'RetailTrade', customerId: 'customer-1',
  customerName: 'Test Buyer', reason: 'Resale', reasonDescription: 'Resale',
  states: [{ abbreviation: 'WA' }],
};
const connection = '/tax/connections/test-connection';
const endpoints = [
  {
    operation: 'calculateCarts', method: 'POST', path: `${connection}/carts`,
    args: [{ items: [cart], transactionDate: date }],
    body: { items: [cartBody], transactionDate: date },
    response: { items: [{ cartId: 'cart-1', lineItems: [{ index: 0, tax: { amount: 2.03, rate: 0.08125 } }] }] },
    error: 'sst_v3_carts_error',
  },
  {
    operation: 'createOrderFromCart', method: 'POST', path: `${connection}/carts/orders`,
    args: ['cart-1', 'order-1', false],
    body: { cartId: 'cart-1', orderId: 'order-1', completed: false },
    response: { orderId: 'order-1', completedDate: null }, status: 201,
    error: 'sst_v3_carts_error',
  },
  {
    operation: 'getCart', method: 'GET', path: `${connection}/carts/cart-1`,
    args: ['cart-1'], response: { ...cartBody }, error: 'sst_v3_carts_error',
  },
  {
    operation: 'createOrder', method: 'POST', path: `${connection}/orders`,
    args: [order], body: orderBody, response: { ...orderBody }, status: 201,
    error: 'sst_v3_orders_error',
  },
  {
    operation: 'getOrder', method: 'GET', path: `${connection}/orders/order-1`,
    args: ['order-1'], response: { ...orderBody }, error: 'sst_v3_orders_error',
  },
  {
    operation: 'updateOrder', method: 'PATCH', path: `${connection}/orders/order-1`,
    args: ['order-1', date], body: { completedDate: date },
    response: { ...orderBody }, error: 'sst_v3_orders_error',
  },
  {
    operation: 'refundOrder', method: 'POST', path: `${connection}/orders/refunds/order-1`,
    args: ['order-1', { items: [{ itemId: 'product-1', quantity: 1, cartItemIndex: 0 }], idempotencyKey: 'refund-1', returnedDate: date }],
    body: { items: [{ itemId: 'product-1', quantity: 1, cartItemIndex: 0 }], idempotencyKey: 'refund-1', returnedDate: date },
    response: [{ idempotencyKey: 'refund-1', returnedDate: date, items: [{ itemId: 'product-1', quantity: 1, tax: { amount: 1.02, rate: 0.08125 } }] }], status: 201,
    error: 'sst_v3_refunds_error',
  },
  {
    operation: 'createCertificate', method: 'POST', path: `${connection}/exemption-certificates`,
    args: [certificate], body: certificate,
    response: { certificateId: 'certificate-1', disabledAt: null, ...certificate }, status: 201,
    error: 'sst_v3_exemptions_error',
  },
  {
    operation: 'getCertificate', method: 'GET', path: `${connection}/exemption-certificates/cert-1`,
    args: ['cert-1'], response: { certificateId: 'cert-1', disabledAt: null, ...certificate },
    error: 'sst_v3_exemptions_error',
  },
  {
    operation: 'disableCertificate', method: 'DELETE', path: `${connection}/exemption-certificates/cert-1`,
    args: ['cert-1'], response: true, status: 204, error: 'sst_v3_exemptions_error',
  },
  {
    operation: 'listCertificates', method: 'GET', path: '/tax/exemption-certificates',
    args: [{ connectionId: 'test-connection', customerId: 'buyer+one&two@example.test', limit: 25, cursor: 'page/+?=&hash#', disabled: false }],
    query: '?connectionId=test-connection&customerId=buyer%2Bone%26two%40example.test&limit=25&cursor=page%2F%2B%3F%3D%26hash%23&disabled=false',
    response: { items: [{ certificateId: 'cert-1', ...certificate }], nextCursor: 'page-2' },
    error: 'sst_v3_exemptions_error',
  },
  {
    operation: 'ping', method: 'GET', path: `${connection}/ping`,
    args: [], response: { message: 'Successfully authenticated' }, error: 'sst_v3_ping_error',
  },
  {
    operation: 'searchTics', method: 'POST', path: '/tax/tic/search',
    args: ['  shoes  ', 150, 'page-2'], body: { query: 'shoes', limit: 100, cursor: 'page-2' },
    response: { query: 'shoes', results: [{ ticId: 10010, description: 'General merchandise' }], nextCursor: null },
    error: 'sst_v3_tic_search_error',
  },
  {
    operation: 'verifyAddress', method: 'POST', path: '/tax/verify-address',
    args: [address], body: address, response: address, error: 'sst_v3_verify_address_error',
  },
];

module.exports = { endpoints, address, cart, order, date };
