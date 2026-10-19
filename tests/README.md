# Local testing guide

Run all commands below from the plugin repository root. For verifying the V3
plugin implementation, start with the fixture E2E suite, then run the live E2E
suite against a TaxCloud Test-mode connection.

## Choose a suite

| Command | What it verifies | Expected full-run result | Live TaxCloud requests? |
| --- | --- | --- | --- |
| `npm run cypress:run` | Admin product TIC editing, plugin settings link, settings actions and API-version persistence | **8 passing across 4 specs** | Yes: TIC search and Verify Settings use the saved connection |
| `npm run cypress:plugin:v3:fixture` | Actual WooCommerce/SST workflows with controlled TaxCloud responses, including a rejected cart | **11 passing in `plugin.cy.js`** | No |
| `npm run cypress:plugin:v3 -- --env taxcloudTestModeConfirmed=true` | Actual WooCommerce/SST workflows against the saved Test-mode connection | **10 passing in `plugin.cy.js`** | Yes; creates test records |
| `npm run cypress:api:v3` | Isolated PHP-client request/response contracts | **302 passing in `contracts.cy.js`** | No |
| `npm run cypress:api:v3:staging` | Direct calls to the legacy staging hosts; does not exercise WooCommerce workflows | **18 passing in `live.cy.js`**, with accepted staging credentials | Yes; creates staging records |
| `npm run test:api:v3` | Isolated PHP contracts for both URL configurations | **316 passed, 0 failed**, twice | No |
| `npm run test:regression` | Exemptions, pickup addresses, refunds and API routing | Four success messages and exit code **0** | No |

Counts describe the current complete suites, without a spec/test filter. A
successful headless Cypress run ends with **All specs passed!**, zero failing
tests and exit code **0**. A setup/authentication error, skipped workflow or
missing API coverage is not a successful verification. Passing isolated contracts
checks the clients; passing plugin E2E also checks the WooCommerce integration.

## Prepare the local Docker site

You need Node.js/npm compatible with the installed dependencies, PHP CLI and
Composer for host PHP commands, Docker Compose, and `rsync`. Install dependencies
and build the plugin when setting up a checkout or after changing assets:

```sh
npm ci
composer install
npm run build
```

For the existing repository Docker fixture:

```sh
# Start/resume the site; omit this if it is already running.
PHP_VERSION=8.2 docker compose -f docker/docker-compose.yml up -d --wait
docker compose -f docker/docker-compose.yml ps
```

Both services should be healthy and WordPress should open at
`http://localhost:8080`. A first-time image build can be requested with `--build`.
The database seed is imported only when the MySQL volume is first initialized;
starting containers preserves the existing site.

Use the repository's seeded test site with:

- Admin login `admin` / `password` (the Cypress helpers use these fixture values).
- WooCommerce, Simple Sales Tax and SST Test Helper active, with the Storefront
  theme installed. A fresh Docker site may need these installed/activated before
  running tests; the containers do not install WooCommerce themselves.
- The seeded products **General Product** and **Lumber**, and the seeded
  cart/checkout pages. The standard admin suite uses their names/page IDs; the
  V3 plugin suite creates its own products and pages.
- For standard admin tests and live plugin tests, valid credentials saved in
  **WooCommerce > Settings > Integration > TaxCloud for WooCommerce**. Select
  **API Version: V3**, save, and check that **Verify Settings** succeeds. Fixture
  mode supplies fake credentials for its scoped requests.

Docker mounts `docker/plugin`, rather than this checkout's root. Before running
the standard admin suite, sync the current plugin code into that directory:

```sh
mkdir -p docker/plugin
rsync -a --exclude-from=.distignore ./ docker/plugin/
```

The V3 plugin suite performs this sync automatically. Keep the build and Composer
dependencies current so the mounted plugin includes its compiled assets and
autoload files. No Docker rebuild is needed for a PHP-only change after syncing.

## Standard Cypress admin tests

```sh
# Headless: all four admin specs.
npm run cypress:run

# Visible Cypress app: choose E2E Testing, a browser, then a spec.
npm run cypress:open

# Debug only the settings spec in a visible browser.
npm run cypress:run -- --spec cypress/e2e/admin/settings-page.cy.js --headed --browser electron
```

In Cypress Open, the Specs list should contain the four files below. Click a
spec to run it, use the rerun control after an edit, and inspect commands in the
left-hand log for browser snapshots and assertion details. Open mode lets you
run specs individually; use the headless command to verify the complete suite.

| Spec | Tests | Expected behavior |
| --- | --- | --- |
| `bulk-editor.cy.js` | 1 | Select General Product and Lumber, search for Uncategorized, apply it in bulk, see “2 products updated.” and verify both saved TICs |
| `edit-product.cy.js` | 1 | Set General Product to Uncategorized, search/select Gift card, save and verify it; then save Uncategorized again |
| `plugins-page.cy.js` | 1 | The plugin's Settings link opens the TaxCloud for WooCommerce settings page |
| `settings-page.cy.js` | 5 | Heading appears; Verify Settings returns success; Download returns HTTP 200 with a debug-report filename; changing API version survives reload and the original version is restored; Show Zero Tax is disabled with Blocks pages selected |

The helpers authenticate through the normal WordPress login form endpoint and
validate the saved admin session. The suite edits the two seeded products,
leaves their TICs as **Uncategorized**, and selects the seeded Blocks cart and
checkout pages (IDs **10** and **11**). The API-version test restores the original
selection after a successful run. If interrupted during that test, check the
saved API version manually before continuing.

This suite verifies admin behavior using the selected API version. Switching
V1/V3 in the settings test checks persistence; it does not run a full checkout
for both versions. Use the V3 plugin suite below for checkout, order, refund and
certificate behavior.

## Offline regression tests

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

### Expected plugin results

Fixture mode must finish with **11 passing**; live mode must finish with
**10 passing**. The extra fixture test verifies a deliberately rejected cart.
Expected behavior for the shared tests:

| Check | Expected result |
| --- | --- |
| Verify Settings | Successful authentication, Ping and management-settings requests; the settings UI shows V3 |
| Product TIC | Searching Uncategorized reaches the V3 TIC API and saves TIC `00000` |
| Classic checkout, capture and refund | Verified address and valid quote; visible tax matches saved order tax; packages record `api_version: v3`; admin completion captures the order and manual refund records `refunded` |
| Update Order client | A separate pending API order receives a completion date, verified by reading it back |
| Cart quantity change | Recalculated displayed tax agrees with the latest TaxCloud quote |
| Certificate creation and coverage | My Account creates/lists the certificate; it applies only to its covered destination state |
| Certificate removal | The certificate created by this run is disabled |
| Data Import | Admin completion submits through Create Order rather than realtime cart conversion |
| Checkout Blocks | Store API totals and rendered cart/checkout agree with the plugin tax result |
| API coverage | Successful recorded calls cover all 14 Sales Tax routes plus authentication and management settings |

For the initial one-item fixture checkout, the $100 product has **$10 tax** and a
**$110 total**. Live tax can legitimately be **$0**; the live tests compare the
displayed and persisted amounts to the returned quote. A live pass alone does
not prove positive-tax rendering; fixture mode supplies that check.

The four supporting Get/Update client checks listed below run inside bootstrapped
WordPress because those methods have no UI caller. Their coverage is integration
coverage, while the other listed workflows exercise the browser UI and plugin
hooks. The suite does not test payment-gateway money transfers or Blocks order
placement.

### Interactive runs and API coverage

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

## Troubleshooting and inspecting failures

| Symptom | What to check or do |
| --- | --- |
| Cypress cannot reach `localhost:8080`, or Docker reports a missing container | Start Docker, run the Compose commands above and check both services. V3 runners default to `docker-wordpress-1`; use `--env apiV3WordpressContainer=YOUR_CONTAINER` if different |
| Product names or cart/checkout pages cannot be found | Use the seeded Docker database. The standard admin tests depend on its products and page IDs |
| Your PHP changes do not appear in the browser | Sync the checkout to `docker/plugin` before the standard suite; rebuild assets if JavaScript/CSS changed |
| Admin login fails | Check that the local fixture still accepts `admin` / `password`. TaxCloud API credentials do not authenticate the WordPress admin |
| Verify Settings or live E2E reports invalid credentials | Check the saved login ID, API key and selected API version. Use the WordPress Verify Settings button first |
| Direct staging auth returns “Invalid connection credentials” | These legacy staging hosts need accepted staging credentials. A Test-mode connection working on the normal hosts does not imply staging access; use the plugin live command to verify the saved connection through the plugin |
| Live plugin command asks for Test-mode confirmation | Confirm the saved connection is in Test mode, then append `-- --env taxcloudTestModeConfirmed=true`. The flag does not change the account's mode |
| Checkout fails before billing fields appear | Inspect the first TaxCloud quote failure in the Cypress log and V3 evidence. A rejected quote can prevent the checkout form from rendering |
| A previous-run/helper error blocks setup | Exit the old Cypress process, then run `npm run cypress:plugin:v3:cleanup` before retrying. For another container, prefix cleanup with `SST_E2E_WORDPRESS_CONTAINER=YOUR_CONTAINER` |
| PHP is not found by the offline Cypress contracts | Use `npm run cypress:api:v3 -- --env apiV3PhpBinary=/path/to/php` or `npm run cypress:api:v3:docker` |

For headless browser failures, inspect `cypress/screenshots/`. For plugin V3 API
requests, inspect the redacted `cypress/results/plugin-v3/latest-live.json` or
`latest-fixture.json` after cleanup; check the route, HTTP status and response
against the first failing assertion. These files are local evidence, not test
sources. A passing fixture run does not confirm live credentials or connectivity.

The default GitHub Actions job still runs the four admin specs. All dedicated
V3 Cypress suites reject CI execution, and neither PHP regression command is
invoked by the current Verify workflow. Keep the V3 verification local.
