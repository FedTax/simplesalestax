/// <reference types="cypress" />

import WooCommerceRestApi from "@woocommerce/woocommerce-rest-api";
import { simpleProduct } from '../../fixtures/products.json';

const api = new WooCommerceRestApi({
  url: cy.config('baseUrl'),
  consumerKey: Cypress.env('REST_API_KEY'),
  consumerSecret: Cypress.env('REST_API_SECRET'),
  version: 'wc/v3',
});

const mockOrder = {
  "payment_method": "cheque",
  "payment_method_title": "Check payments",
  "set_paid": true,
  "billing": {
    "first_name": "John",
    "last_name": "Doe",
    "address_1": "540 Renee Drive",
    "address_2": "",
    "city": "Bayport",
    "state": "NY",
    "postcode": "11705",
    "country": "US",
    "email": "john.doe@example.com",
    "phone": "(555) 555-5555"
  },
  "shipping": {
    "first_name": "John",
    "last_name": "Doe",
    "address_1": "540 Renee Drive",
    "address_2": "",
    "city": "Bayport",
    "state": "NY",
    "postcode": "11705",
    "country": "US"
  },
  "line_items": [
    {
      "product_id": simpleProduct.id,
      "quantity": 1
    }
  ]
};

describe('REST API order calculations', () => {
  it('calculates tax for orders created through the REST API', () => {
    cy.wrap(null).then({ timeout: 60000 }, () => api.post('orders', mockOrder)).as('response');

    cy.get('@response').then(({ data, status }) => {
      expect(status).to.eq(201);
      expect(data.total_tax).to.eq(simpleProduct.expectedTax.toString());
    });
  });

  it('handles partial refund rounding regression for $7,349 item and $800 refund', () => {
    const refundAmount = 800;
    const itemPrice = 7349;
    const refundQuantity = Number((refundAmount / itemPrice).toFixed(12));

    expect(Number((itemPrice * refundQuantity).toFixed(2))).to.eq(refundAmount);
    expect(Number((itemPrice * Number(refundQuantity.toFixed(4))).toFixed(2))).to.eq(800.31);

    const orderWithExpensiveProduct = {
      ...mockOrder,
      "line_items": [
        {
          "product_id": simpleProduct.id,
          "quantity": 1,
          "subtotal": "7349.00",
          "total": "7349.00"
        }
      ]
    };

    cy.wrap(null).then({ timeout: 60000 }, () => api.post('orders', orderWithExpensiveProduct)).as('orderResponse');

    cy.get('@orderResponse').then(({ data: orderData, status: orderStatus }) => {
      expect(orderStatus).to.eq(201);
      const orderId = orderData.id;
      const lineItemId = orderData.line_items[0].id;

      // Now issue a partial refund of $800.00
      const refundPayload = {
        "amount": "800.00",
        "line_items": [
          {
            "id": lineItemId,
            "refund_total": "800.00"
          }
        ]
      };

      cy.wrap(null).then({ timeout: 60000 }, () => api.post(`orders/${orderId}/refunds`, refundPayload)).as('refundResponse');

      cy.get('@refundResponse').then(({ data: refundData, status: refundStatus }) => {
        expect(refundStatus).to.eq(201);
        expect(refundData.amount).to.eq("800.00");
      });
    });
  });
});
