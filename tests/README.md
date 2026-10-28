# Testing the plugin

Run commands from the repository root with the seeded Docker WordPress site
running at `http://localhost:8080`.

## Everyday commands

```sh
# Verify the plugin with controlled TaxCloud responses (no external API calls).
npm run test:e2e

# Verify both realtime and Data Import against your TaxCloud test connections.
npm run test:e2e -- --live

# Open Cypress to watch/debug the same tests.
npm run test:e2e:open
# For live tests in the interactive app:
npm run test:e2e:open -- --live
```

In Cypress Open, select a browser and click **plugin.cy.js**. Run the full spec:
its tests share a customer/order lifecycle. Use the rerun control after edits
and inspect commands in the left-hand log for snapshots and failed assertions.
Close the Cypress app before starting another plugin run.

| Mode | Expected result | What it proves |
| --- | --- | --- |
| Default (fixture) | **28 passing**, zero failures | Real WooCommerce/plugin workflows, known positive tax, rejected requests and recovery; only TaxCloud HTTP responses are replaced |
| `--live` | **15 passing**, zero failures | The supported workflows authenticate and work against both configured TaxCloud connections |

A successful headless run ends with **All specs passed!** and exit code **0**.
Setup failures or skipped workflows do not count as successful verification.

## Colorado category TIC reproduction

With the same local Docker site running, use:

```sh
npm run test:e2e
# Open Cypress and select plugin.cy.js to watch/debug:
npm run test:e2e:open
```

The six Colorado cases run at the end of the existing plugin fixture suite and
need no TaxCloud credentials. They are omitted in live mode. This characterizes
the current behavior reported in ticket #86850; a passing
reproduction of `99990` does **not** mean that assigning this code to products is
correct. The browser, category save, checkout, plugin request construction and
persisted WooCommerce orders are real. TaxCloud HTTP responses are simulated.
The fixture assumes 7.5% ordinary Colorado sales tax and a full-price tax response
for `99990`; it does not independently verify either rate against the live API.

| Scenario ($100 product) | Sales tax | CO excise fee | Order total |
| --- | --- | --- | --- |
| Category `99990`, no product override | $100.00 | None | $200.00 |
| Category firearms `90505` | $7.50 | $6.50 | $114.00 |
| Category ammunition `90506` | $7.50 | $6.50 | $114.00 |
| Category `99990`, product override `90505` | $7.50 | $6.50 | $114.00 |
| Category `90505`, quantity changed from 1 to 2 | $15.00 | $13.00 | $228.00 |
| Category `90505`, Minnesota destination | $10.00 (fixture) | None | $110.00 |

Expect **28 passing** overall, including these **6 Colorado cases**. The first
Colorado test selects and saves category `99990` through the admin UI. Every
Colorado test checks the effective product TIC, outgoing API payload,
visible checkout totals and persisted order totals. Eligible Colorado orders
send a single fee with TIC `99990`; the test also checks that the API's simulated
fee tax is not charged a second time. Other tests seed the run's category/product
codes through WP-CLI to isolate the checkout scenarios.

Evidence is included in `cypress/results/plugin-v3/latest-fixture.json` with the
other plugin workflows. The Colorado cases reuse the run’s product/customer and
create their own category, which cleanup removes along with the other disposable
fixtures. Test customers/orders remain for inspection. The existing
`npm run test:cleanup` command recovers an interrupted run.

## Set credentials once

The local file for this checkout is **cypress/credentials.local.json**. To set up
another checkout:

```sh
cp cypress/credentials.example.json cypress/credentials.local.json
chmod 600 cypress/credentials.local.json
```

Fill in the four fields:

```json
{
  "apiLoginIDRealtime": "YOUR_REALTIME_LOGIN_ID",
  "apiKeyRealtime": "YOUR_REALTIME_API_KEY",
  "apiLoginIDDataImport": "YOUR_DATA_IMPORT_LOGIN_ID",
  "apiKeyDataImport": "YOUR_DATA_IMPORT_API_KEY"
}
```

The realtime connection must report `options.data_mover.flag: false`; the Data
Import connection must report `true`. Tests verify these values against TaxCloud.
The plugin exchanges these login ID/API key pairs (sometimes labeled V1
credentials) for V3 authentication. **API Version stays V3 in both flows.**

`--live` explicitly selects live requests and confirms that both connections are
suitable Test-mode connections. It does not change or automatically detect the
account's Test/live status. Live runs create TaxCloud test records, including
orders, certificates and refunds.

One run uses the realtime pair, switches automatically to the Data Import pair
for its import/refund workflow, then returns to realtime for Blocks. Both pairs
are required in live mode. Fixture mode needs no credentials file and uses
distinct fake pairs to test the same switching behavior.

The local file is ignored by Git and excluded from plugin distributions. Keys
are not passed through Cypress's browser environment or stored in API evidence.
The runner transfers them over stdin to a temporary container file readable
only by `www-data`; cleanup removes that file. The site's saved settings and
credentials are preserved.

## What the E2E tests check

| Workflow | Expected behavior |
| --- | --- |
| Verify Settings | V3 authentication, Ping and management settings succeed; TaxCloud identifies the realtime connection correctly |
| Product TIC | Search/select Uncategorized and save TIC `00000` through the product editor |
| Classic checkout | Verify the address, calculate tax, and match the visible quote to persisted WooCommerce order totals; saved packages record `api_version: v3` |
| Capture and refund | Admin completion captures the order; a manual admin refund calls TaxCloud and saves `refunded` |
| Partial refunds and duplicate completion | In both modes, completing the same order twice sends one capture; two one-unit refunds have different idempotency keys, refund only two units in total, and preserve the original packages |
| Cancellation | In both modes, cancelling an uncaptured order sends neither capture nor refund requests |
| Empty cart | Removing all items clears tax and order totals, including on the next Store API read |
| Quantity change | Changing quantity recalculates tax and the displayed amount matches the new quote |
| Certificates | My Account creates/lists a certificate, checkout applies it only to the covered state, and the test disables it |
| New single-purchase certificate (fixture only) | Checkout accepts matching V3 destinations, bounds both descriptions to 20 Unicode characters, saves a remote certificate ID and $0 tax, preserves it when changing an order back to Pending payment, and reuses that ID on admin recalculation without registering a duplicate |
| Data Import | Its own credentials authenticate and report Data Import mode; checkout avoids realtime cart calls; admin completion imports the order; GET reads it back; manual refund uses the Data Import connection |
| Checkout Blocks | Store API totals and rendered cart/checkout agree with the plugin tax result |
| API coverage | Successful requests cover all 14 V3 Sales Tax routes plus authentication and management settings |
| Failure recovery (fixture only) | Rejected capture preserves the prior sync state and can be retried from admin; rejected refund preserves capture state and records an error note, then resends the same refund/idempotency key successfully |
| Zero-tax destination (fixture only) | Changing from a positive-tax destination to the fixture's zero-tax destination clears tax without applying an exemption |

For the initial $100 fixture checkout, expect **$10 tax / $110 total**. Quantity
2 produces **$20 tax**; an exempt checkout produces **$0 tax**. Live quotes can
legitimately return $0. Live tests compare the displayed/persisted amounts to
the quote; they are not an independent tax-rate audit. Fixtures verify positive
tax behavior and a rejected cart.

Get Cart, Get Order, Update Order and Get Certificate have no browser UI caller;
they are checked through PHP clients in bootstrapped WordPress. Update Order
completes a separate pending remote order and reads the completion date back.
Blocks checks cover rendering/totals; order placement uses classic checkout.
Manual refunds do not transfer money through a payment gateway.

Failure injection is restricted to fixture mode and affects only the chosen
capture/refund HTTP request. After a failed TaxCloud refund, WooCommerce can still
retain the local refund. The test verifies the error note and unchanged TaxCloud
sync state, then invokes the plugin's PHP refund method with that existing refund
ID to recover without creating another local refund. There is no browser UI for
that retry, so it is a supporting integration check. Other capture/refund actions
and capture retries use the WooCommerce admin UI. No automatic retries of live
mutations are enabled.

V3 certificate forms require a reason description for every exemption reason,
including Resale. The browser suite verifies that the modal blocks an empty
description and that Blocks displays the description field for Resale. Server
regressions cover missing/blank descriptions, V1 compatibility, malformed
successful quotes, and rate-limit accounting for rejected quote attempts.

## Docker setup

For an existing test site, start/resume containers if needed:

```sh
PHP_VERSION=8.2 docker compose -f docker/docker-compose.yml up -d --wait
docker compose -f docker/docker-compose.yml ps
```

Both services should be healthy. Starting containers preserves the existing
site; the SQL seed is imported only when MySQL's volume is first initialized.
For a new checkout, install dependencies/build assets before testing:

```sh
npm ci
composer install
npm run build
```

The seeded site needs admin `admin` / `password`, WooCommerce, Simple Sales Tax,
SST Test Helper and the Storefront theme. A fresh site may need these installed
and activated; Docker does not install WooCommerce itself. The helper supports
the Store API operations used by these tests.

The plugin suite automatically syncs the current checkout into `docker/plugin`,
using `.distignore`. Keep dependencies and compiled assets current. No image
rebuild is needed for a PHP-only edit. Each run creates its own customer, $100
virtual product and classic/Blocks pages. Overrides affect only the test run's
cookie/CLI process, temporarily enabling cheque payment and suppressing emails.

## Results and cleanup

Redacted API evidence is saved under `cypress/results/plugin-v3/`:

- `latest-fixture.json` — the last fixture run.
- `latest-live.json` — the last live run.
- `latest.json` — the most recent run of either mode.

Inspect the first failing assertion, route, HTTP status and response. Each
record labels `connectionProfile` as `realtime` or `dataImport` and records
whether the request used the expected credentials, without revealing them.
Failure screenshots are under `cypress/screenshots/`. These artifacts are ignored
by Git. A fixture pass does not verify live credentials/connectivity.

Normal cleanup removes the temporary MU helper and secret file, trashes the
run's product/pages, and disables leftover test certificates. Test customers and
orders remain for inspection; TaxCloud order IDs use a recognizable `cy-v3-`
prefix. Saved store settings remain unchanged.

If a process was interrupted, exit it first, then recover with:

```sh
npm run test:cleanup
```

Do not run two plugin suites concurrently or run cleanup while Cypress is active.
For a nondefault container, append `--env apiV3WordpressContainer=YOUR_CONTAINER`
to the test command; prefix cleanup with
`SST_E2E_WORDPRESS_CONTAINER=YOUR_CONTAINER`.

## Troubleshooting

| Symptom | Check |
| --- | --- |
| Cannot reach localhost or Docker | Start Docker/containers and check their health; the default container is `docker-wordpress-1` |
| Admin login fails | The fixture must accept `admin` / `password`; TaxCloud credentials are separate |
| Credentials file missing/invalid | Fill in both pairs in `cypress/credentials.local.json`; unchanged placeholders are rejected |
| Connection-mode assertion fails | Confirm each pair belongs to the correct realtime or Data Import connection |
| Checkout billing fields never appear | Inspect the first TaxCloud quote error; a rejected quote can prevent the form from rendering |
| Previous-run/helper error | Exit the old Cypress process, run `npm run test:cleanup`, then retry |

## Other checks

The smaller admin suite remains available as `npm run cypress:run` or
`npm run cypress:open`. GitHub Actions runs this suite only; all dedicated V3
suites reject CI execution. PHP regressions also remain local.

For isolated PHP-client contracts, PHP regressions, direct legacy staging tests,
and details of the admin specs, see [advanced checks](api-v3/README.md). These
checks supplement the plugin workflows; they are not required for the everyday
E2E commands above.
