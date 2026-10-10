# Offline regression tests

Run from the plugin directory with PHP CLI and Composer dependencies installed:

```sh
npm run test:regression
npm run test:api:v3
```

`test:regression` checks destination-state exemptions (V1 and V3), local pickup,
captured order/refund preservation, and API selection. API selection checks use
the real settings reader/writer and plugin routing with in-memory WordPress and
WooCommerce doubles. They verify that missing settings default to V1, explicit V3
selection persists, and API failures never trigger calls to the other version.
Capture and refund use the version recorded on the original package even if the
current selection changes; historical unmarked packages remain V1. Existing Data
Import mode continues to skip realtime lookups.

`test:api:v3` checks all 14 Sales Tax V3 endpoints, authentication, request bodies,
URL encoding, responses, and errors. Expected result: **316 passed, 0 failed**
for each of the production and staging URL configurations.

These tests do not contact TaxCloud, load the local WordPress database, or create
real carts, orders, certificates, or refunds. Production/staging here refers to
the expected URL configuration, not a live server test. Browser checkout and live
API verification require a running WordPress test site and configured credentials.
