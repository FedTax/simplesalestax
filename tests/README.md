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

## Local Cypress V3 API suites

Both suites use separate configurations and specs under `cypress/local/api-v3`.
The normal `cypress.config.js` only discovers `cypress/e2e` specs, so neither V3
suite is included in the GitHub Actions Cypress job. Both V3 configurations also
reject execution when `CI` or `GITHUB_ACTIONS` is enabled. The dedicated V3
contract workflow has been removed, along with the PHP V3 contract commands in
the Verify workflow. They remain available via `npm run test:api:v3` locally.

### Live staging API tests

```sh
npm run cypress:api:v3:staging
```

This suite makes real HTTP calls to the staging hosts used by the plugin:
`https://api.v3.taxcloud.net` and
`https://staging-taxcloudapi.azurewebsites.net/api/v3/auth/token`. These hosts are
fixed in the runner; there is no production URL override.

Credentials are read in this order:

1. `TAXCLOUD_V3_LOGIN_ID` and `TAXCLOUD_V3_API_KEY` environment variables. Set
   `TAXCLOUD_V3_CONNECTION_ID` too if the connection ID differs from the API key.
2. The ignored local file `cypress.api-v3.staging.env.json`:

   ```json
   {
     "apiLoginID": "YOUR_STAGING_LOGIN_ID",
     "apiKey": "YOUR_STAGING_API_KEY"
   }
   ```

   An optional `connectionId` field overrides the API key as the connection ID.
   Copy `cypress.api-v3.staging.env.example.json` to this filename and fill in the
   staging values. The credentials file is excluded from Git and distribution.
3. The existing Docker WordPress settings, read with WP-CLI without loading
   plugins or changing settings. Override the default container name
   `docker-wordpress-1` with `--env apiV3WordpressContainer=YOUR_CONTAINER`.

Credentials and bearer tokens stay in the Node task process; they are not passed
to the browser or printed in the command log. Failed API responses are redacted.
The auth exchange must succeed before the endpoint tests run. Production account
credentials may not be accepted by the staging auth service.

There are 18 live tests: authentication, connection settings, each of the 14
Sales Tax endpoints, and two negative cases. They cover address verification,
TIC search, certificate create/get/list/disable, cart calculate/get/convert,
order import/get/update, and refund with an explicit idempotency retry.

Each run uses unique customer, cart, order, and refund IDs. The converted order
is left uncompleted; the imported order is marked `excludeFromFiling: true`.
The suite disables its own certificate, including in the cleanup hook after
failed runs. Test carts, orders, and refunds remain in the staging account for
inspection. It never changes the WordPress API version or saved credentials.
Automatic test retries are disabled to avoid repeating mutations.

Reference: [TaxCloud Sales Tax V3 API](https://docs.taxcloud.com/api-reference/api-reference/sales-tax-api/cart/create-cart).

### Offline PHP-client contracts in Cypress

```sh
npm run cypress:api:v3
npm run cypress:api:v3:open
npm run cypress:api:v3:docker
```

The 302 contract tests cover all 14 Sales Tax endpoints plus auth and management
settings, in both production and staging URL configurations. Cypress asserts the
actual PHP clients' outgoing URLs, methods, headers, payloads, parsed responses,
authentication, validation, URL encoding, precision, and error handling.

These tests reuse the existing isolated PHP boundary in `tests/api-v3/bootstrap.php`.
Every HTTP request is blocked unless a fixture explicitly expects it. They do
not bootstrap WordPress, use account credentials, or contact TaxCloud.

The default backend needs PHP CLI. Set `--env apiV3PhpBinary=/path/to/php` if PHP
is not on your PATH. The Docker command uses a temporary container based on the
existing `docker-wordpress` image, mounting the current project read-only with no
network. It uses PHP from that image without changing the running WordPress
container, its plugin files, or database. The helper container is removed after
the run. Override its image with `--env apiV3Backend=docker,apiV3DockerImage=YOUR_IMAGE`.

Docker must be running and its WordPress image must already be built. Docker
mode supports `cypress run`; use the PHP backend for the interactive Cypress UI.

## WooCommerce V3 end-to-end tests (local only)

These tests exercise the running Docker WordPress site, WooCommerce, and the
actual SST plugin. They do not invoke TaxCloud directly from the browser. The
runner first syncs the current plugin checkout into `docker/plugin` using
`.distignore`, so the site tests the code being edited.

```sh
# Real WooCommerce/PHP with controlled TaxCloud HTTP responses:
npm run cypress:plugin:v3:fixture

# Real WooCommerce/PHP and live TaxCloud, after confirming Test mode:
npm run cypress:plugin:v3 -- --env taxcloudTestModeConfirmed=true

# Watch the same tests in a visible browser:
npm run cypress:plugin:v3:fixture -- --headed --browser electron

# Interactive Cypress app, with live TaxCloud:
npm run cypress:plugin:v3:open -- --env taxcloudTestModeConfirmed=true

# Interactive Cypress app, with controlled TaxCloud responses:
npm run cypress:plugin:v3:open:fixture
```

For the interactive commands, keep Docker running, select a browser and click
`plugin.cy.js` in the Specs list. The full suite runs automatically. Use the
runner's rerun button to run it again, and click commands in the left-hand log
to inspect browser snapshots. The tests share a lifecycle, so run the full spec
rather than isolating individual tests with `.only`.

Interactive setup runs in the spec's `before` hook and cleanup in its `after`
hook. Each rerun uses a new customer, product, pages and run ID. Cypress's
[interactive run events](https://docs.cypress.io/api/node-events/after-run-api)
also enable cleanup when the browser closes. Each live rerun creates new
TaxCloud test records. Close the Cypress app before starting another suite.

The live command uses the credentials already saved in WordPress and the API
hosts selected by the plugin. It requires a TaxCloud **Test-mode connection**;
the confirmation flag is your attestation, not an automatic account-mode check.
TaxCloud's [testing guide](https://docs.taxcloud.com/guides/getting-started/testing-and-going-live)
documents using the same API hosts for test and live connections. Test-mode
credentials do not necessarily authenticate against the plugin's legacy staging
hosts. This suite does not force those staging hosts.

| API | Workflow exercised |
| --- | --- |
| Authentication, Ping, connection settings | Admin Verify Settings button |
| TIC search | Search/select/save on the product edit screen |
| Address verification, cart calculation | Classic checkout address and displayed tax |
| Cart conversion to order | Complete the WooCommerce order in admin |
| Refund | Admin Refund button and manual refund submission invoke WooCommerce/SST hooks |
| Certificate create/list/disable | My Account form, refresh, and delete controls |
| Create Order | Data Import checkout followed by admin completion |
| Get Cart, Get Order, Update Order, Get Certificate | Supporting PHP client integration checks in bootstrapped WordPress; these methods have no UI caller |

The Update Order check creates a separate pending test order before setting its
completion date. An order already completed by the admin capture flow cannot
have its completion date updated. The test reads the order back to verify the
saved date; the live PATCH response can omit the new completion date. See TaxCloud's
[transaction lifecycle](https://docs.taxcloud.com/guides/core-concepts/transaction-lifecycle).

The suite checks quoted tax against visible checkout and persisted WooCommerce
totals, saved `api_version`, capture/refund status, certificate state coverage,
and Checkout Blocks/Store API tax totals. Fixture mode uses a known 10% tax rule
and also checks a rejected cart. Live mode compares totals to TaxCloud's real
quote; it is not an independent tax-rate audit. Blocks checks cover
zero-tax totals too: WooCommerce Blocks can omit the tax row when TaxCloud's
quote is zero. Fixture mode still requires the known positive tax amount.
Blocks tests check cart totals and checkout rendering; order placement uses classic
checkout. Refunds use the admin manual-refund action; no payment gateway refund
or money transfer is performed.

A temporary MU plugin records the PHP HTTP boundary. Fixture mode replaces only
TaxCloud HTTP responses; WordPress, plugin hooks, AJAX handlers, templates, and
order persistence are real. Overrides are scoped to the test-run cookie/CLI
process. Other visitors keep their normal settings. No new public test endpoint
is installed. Fixture mode uses separate fake credentials and never sends
TaxCloud requests. The suite is excluded from the normal Cypress configuration
and rejects GitHub Actions/CI execution.

Each run creates its own customer, $100 virtual product, and classic/block
pages. TaxCloud orders use a recognizable `cy-v3-` prefix. It temporarily enables the cheque gateway for that test session and
suppresses test emails. It uses the local Docker fixture's existing admin login
(`admin` / `password`) and existing Store API test helper. It records successful
requests for all 14 Sales Tax endpoints plus authentication and management.
Redacted evidence is saved to `cypress/results/plugin-v3/latest.json`, ignored by
Git, and separately to `latest-live.json` or `latest-fixture.json` so a fixture
run preserves the previous live result. Credentials and bearer tokens are
excluded from that evidence. Checkout failures report TaxCloud's response before
attempting to find billing fields; a rejected quote can prevent the checkout
form from rendering.

The scoped overrides never persist to the site's saved SST settings. After a
normal run, the runner trashes its
test product/pages, removes its temporary MU plugin, and disables remaining
certificates belonging to the new customer in live mode. Test customers/orders
remain for inspection. Interrupted processes can leave the helper and run
context behind; the next run refuses to overwrite an existing helper. After the
old Cypress process has exited, run `npm run cypress:plugin:v3:cleanup` to recover.
Set `SST_E2E_WORDPRESS_CONTAINER` if you use a different container name. Do not
run two copies simultaneously or run cleanup while Cypress is still active.

Known validation mismatch: the certificate form treats the description for
reasons such as Resale as optional, while the V3 client requires a nonempty
`reasonDescription`. The valid certificate-flow test fills in the Resale ID
description. Submitting it empty currently fails validation.
