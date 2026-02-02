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
});
