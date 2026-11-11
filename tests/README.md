# Testing the plugin

Run commands from the repository root with the seeded Docker WordPress site
running at `http://localhost:8080`.

## Marketplace virtual item tax regression (DEV-10624)

Run `php tests/marketplace-cart-regression.php` for Dokan, WCFM, WCMp and WC
Vendors Pro. It is included in `npm run test:regression` and uses modeled
marketplace data and tax responses without a running store or TaxCloud account.

The test runs checkout's package splitter for virtual-only and mixed carts.
It checks seller origins, billing and shipping destinations, one occurrence
of each virtual line, and the tax on selected shipping charges. It also covers
packages without a seller ID, separate shipping packages with the same seller
origin, and seller grouping when order tax is recalculated.

## Shipping address verification (DEV-10605)

Run `php tests/address-verification-regression.php` for controlled V1/V3
verification, cache/retry behavior, private notes, classic and Store API checkout
validation, and nonblocking order-side behavior. No external API calls are made.
The same check is included in `npm run test:regression`.

`npm run test:e2e` also includes **14 Cypress fixture scenarios** for this ticket:
classic and Block Checkout corrections, rejection with the requirement on/off,
recovery after editing a rejected address, nonblocking timeouts with another
verification attempt for the same address, quiet successful standardization,
HTTP 401/429/503 failures, and admin orders recalculated twice without duplicate
notes. These use a physical product and assert the outgoing tax destination,
checkout result, persisted tax totals, verification metadata, and private notes.
The scenarios run only in fixture mode; live mode never injects address errors.

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
| Default (fixture) | **64 passing**, zero failures | Real WooCommerce/plugin workflows, known positive tax, rejected requests and recovery; only TaxCloud HTTP responses are replaced |
| `--live` | **15 passing**, zero failures | The supported workflows authenticate and work against both configured TaxCloud connections |

A successful headless run ends with **All specs passed!** and exit code **0**.
Setup failures or skipped workflows do not count as successful verification.

## Checkout rate limit regression (DEV-10623)

`npm run test:e2e` includes six fixture-only Cypress cases: guest and logged-in
customers using classic and Blocks checkout, plus new exemption certificates on
both checkout routes. Each case starts with an empty
quota, obtains a real plugin quote, and exhausts the five-request limit through
WooCommerce requests. The tests never seed an already-reached counter.

With forced lookups enabled and the optional notice disabled, changing quantity
must leave the estimate available but reject order submission with the tax error.
Classic checkout creates no order; any Store API draft stays unpaid. Restoring
the quoted quantity must permit checkout with $10 tax and a $110 persisted total.
The tests check saved packages, absence of additional TaxCloud lookup requests,
and that skipped lookups never enter the quote cache. A scoped read-only endpoint
observes the real limiter/cache; only external TaxCloud responses are simulated.
Settings apply only to the run's cookie/CLI context and reset after each case.
The two new-certificate cases change to an uncached cart after exhausting the
quota, submit a complete certificate covering that destination, and verify the
certificate is created exactly once and the order saves $0 tax and a $200 total.
Ten screenshots are saved under `cypress/screenshots/` with `DEV-10623` names.
These cases do not run in live mode.

Run `php tests/rate-limit-regression.php` or `npm run test:regression`.
The standalone check runs the real lookup, package hash/cache, rate limiter and
checkout validators with controlled API clients and a modeled WooCommerce boundary.
Its 248 assertions cover V1/V3 and guest/authenticated sessions:

- A reached limit blocks uncached classic and Store API order submissions, even
  when the optional rate-limit notice is disabled or no estimate was calculated.
- Cached positive-tax and legitimate zero-tax quotes permit checkout. Forced
  refreshes retain a matching cached quote when the limit has been reached.
- Changed carts, response-less legacy caches and partially quoted multi-package
  carts cannot bypass validation. An expired quota permits a fresh lookup.
- Estimates and Store API PATCH requests stay available. Disabled integrations,
  Data Import and the existing external tax-exemption flag retain their behavior.
- A complete new certificate covering every package can proceed at the limit
  without a cached quote. Missing, incomplete, malformed, mismatched-state and
  unselected certificates still reject checkout, including mixed destinations.
  Final submission cannot substitute a previously previewed certificate state.

Skipped lookups are no longer cached as quotes, and existing response-less cache
entries are ignored. These standalone checks do not make external requests.

## Checkout exemption security regression (DEV-10620)

`npm run test:e2e` includes three fixture-only cases that tamper with actual
classic checkout requests:

- A guest with exemptions disabled posts `certificate_id=new` during a cart
  refresh, then omits the final selection while retaining the matching state.
  The saved guest order must retain $10 tax and a $110 total, with no certificate.
- An eligible customer gets a zero-tax preview for `new`, then submits a final
  request containing only the certificate state. Checkout must reject the missing
  fields without creating an order or certificate.
- A customer posts another customer's valid MN certificate ID. The session must
  ignore it and the saved order must retain $10 tax and no certificate.

WooCommerce requests, sessions, validation and persisted orders are real. Only
TaxCloud HTTP responses are simulated. A read-only helper observes the raw WC
session; it does not select or validate certificates. Fixture settings apply only
to the run's cookie and are restored after each case. The extra test customer and
successful orders remain for inspection. These cases are omitted in live mode
and the plugin suite remains local only.

## Certificate access security regression (DEV-10622)

`npm run test:e2e` includes four fixture-only cases:

- A customer sends another customer's ID to add, read, refresh and delete with
  valid nonces. Each request returns HTTP 403 with no certificate data and no
  TaxCloud request.
- A customer saves another customer's email through WooCommerce's billing address
  form. Refreshing still returns only their own certificates. A cache seeded under
  the old prefix cannot expose the foreign certificate either.
- An administrator and a temporary shop manager create and read certificates for
  a permitted customer. The shop manager cannot read an administrator's records.
- A shop manager submits forged empty, `none`, and replacement certificate values
  through the real order form for an administrator's order. The saved exemption
  must remain unchanged. An administrator can then explicitly clear it normally.

Only TaxCloud HTTP responses are simulated. The billing email is restored after
each case, and suite cleanup demotes the temporary shop manager to customer.
These cases do not run against live TaxCloud connections.

The offline `php tests/certificate-access-regression.php` check covers both API
paths, unauthorized requests without side effects, self-service, target-user
permissions, nonce validation, deletion membership, cache invalidation, saved
checkout selections, server-rendered certificate data, order debug authorization
and background processing.
It is included in `npm run test:regression`.

The metadata regression also checks both recalculation and regular order saves:
omitting the field preserves the selection, and editors without certificate
access cannot clear or replace it. Rejected regular saves do not write certificate
or package metadata. Authorized users can still explicitly select or clear
certificates, including on guest orders.
Recalculation also reloads the order after WooCommerce saves its items so it
uses the newly persisted totals rather than properties from an earlier instance.

Certificates stored only under a billing email that differs from the account's
email no longer appear. Authorized staff can recreate them for the correct
customer. Existing certificate caches are ignored automatically after the fix.

## Certificate ID and metadata security regression (DEV-10621)

Test-runner compatibility fixes retained alongside these scenarios unset
`ELECTRON_RUN_AS_NODE` before launching Cypress and support Store API batch
requests. The Sizzle quote-selector workaround is active only during the two
serialized-option selections and is restored on completion or test failure.
These adjustments do not alter plugin authorization or TaxCloud responses.

`npm run test:e2e` includes five fixture-only Cypress cases:

- Classic checkout selects a customer-owned serialized-object ID, then submits
  that ID again. The session clears it and the saved order retains $10 tax and
  a $110 total, with no certificate.
- Checkout Blocks sends the same selection through its real cart-extension
  request. A final Store API checkout request also submits that ID and must save
  the same taxable order. The test checks rendered Blocks tax and API totals.
- An admin order containing a pre-7.0 serialized TaxCloud certificate displays
  its GUID as the selected certificate.
- Opening an order and clicking **View Debug Log** must not instantiate a
  serialized test class. Its harmless `__wakeup()` only increments a scoped
  fixture counter, which must remain zero after both actions.
- Recalculating an order with a selection containing `&order_id=` must send only
  the original order ID and receive an invalid-certificate response. Both the
  original order and the other order must retain their items, totals and metadata.

These cases use real WordPress serialization, WooCommerce sessions, browser
scripts and persisted orders. Fixtures deliberately return malformed IDs as
customer-owned certificates so ownership checks alone cannot satisfy the tests.
Normal fixture certificate IDs are GUIDs. The temporary helper and scoped
settings are removed on cleanup; test orders remain for inspection. All five
cases are omitted in live mode, which remains at **15 expected passing** cases.

Run `npm run test:regression` for the standalone checks; no database or TaxCloud
connection is needed. Coverage includes both API versions and both checkout
paths, with serialized-object and query-string payloads placed in the certificate
cache and session. Malformed IDs must not persist even when cached package IDs
match. Valid customer GUIDs, `new`, and clearing a selection remain supported.

The metadata checks verify that arbitrary classes cannot be instantiated by
order reads or debug logging, that pre-7.0 certificate objects still provide
their IDs and details, and that existing double-serialized package arrays remain
usable. Pre-7.0 object-based packages also remain readable and compress correctly
during the 7.0 background migration. Package reads allow only the legacy TaxCloud
data classes and `WC_Shipping_Rate`; products and unrelated classes remain blocked.
Package writes serialize arrays to strings before passing them to WooCommerce so
the posts data store preserves nested backslashes and stable package hashes.
`tests/order-meta-storage-regression.php` exercises WooCommerce's real posts-meta
writer with a modeled WordPress unslashing/persistence boundary, without a database.
Admin save, delete and recalculation reject malformed IDs; recalculation also preserves the
internal single-purchase certificate selection.

The JavaScript check executes the real request filter and parses its result to
verify that `&order_id=` inside a selection cannot replace the original order or
items. It checks the minified asset when present. After editing the source,
regenerate that asset with:

```sh
node node_modules/uglify-js/bin/uglifyjs assets/js/meta-box.js --compress --mangle --output assets/js/meta-box.min.js
```

These checks stub the WordPress/WooCommerce boundary; they do not replace the
browser suite or verify checkout against a running store.

## Admin order exemption regression (DEV-10586)

`npm run test:e2e` includes three fixture scenarios for an administrator editing
a pending Florida school order with ten product lines, no shipping method, and
$4,806.45 subtotal:

- No certificate: $288.40 tax and $5,094.85 total.
- Select the customer's FL certificate, recalculate, reload and recalculate again:
  $0 tax and $4,806.45 total; the customer and certificate remain saved.
- Select an NY-only certificate: it is rejected, and tax remains $288.40.

These cases assert the visible totals, outgoing TaxCloud customer/exemption IDs,
and persisted order packages. The administrator and customer are different users.
They fail if package splitting replaces the customer with the administrator.
Only TaxCloud HTTP responses are simulated; the fixture's 6% line-rounded tax
reproduces the report and is not a live tax-rate assertion. The extra product is
trashed on cleanup; pending test orders remain for inspection. A successful FL
case saves `DEV-10586-admin-FL-certificate-zero-tax.png` under
`cypress/screenshots/`. These three cases run only in fixture mode.

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

Expect **64 passing** overall, including these **6 Colorado cases**. The first
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

## LocalWP setup (without Docker)

The plugin suite can also run against a LocalWP site that loads this exact
checkout. Set its WordPress root and, if your PHP CLI needs it, its MySQL socket:

```sh
SST_E2E_WORDPRESS_PATH='/path/to/site/app/public' \
SST_E2E_MYSQL_SOCKET='/path/to/localwp/run/site-id/mysql/mysqld.sock' \
npm run test:e2e -- --browser chrome --config baseUrl=http://dev-taxcloud.local
```

Use the same environment variables with `test:e2e:open` or `test:cleanup`.
The supplied base URL must match WordPress's saved home URL and use a local
hostname. Customer logins use the run's generated account; admin tests use
LocalWP's existing `localwp_auto_login=1` flow. The runner uses `php` and `wp`
from PATH; `SST_E2E_PHP_BINARY` and `SST_E2E_WP_CLI` can override them.

The temporary MU helper provides the Docker test helper's Store API nonce and
cheque-payment behavior only for the test cookie/CLI context. Local test requests
omit the unused Stripe gateway, and disposable pages stay out of fallback menus.
The plugin suite uses a 1440×1000 desktop viewport. Saved merchant
settings and other visitors keep their normal behavior. Automatic cleanup
removes the helper, secret file and disposable product/pages, while preserving
the run's customers and orders for inspection. Docker remains the default when
`SST_E2E_WORDPRESS_PATH` is unset.

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

`npm run test:regression` includes the DEV-10620 checkout exemption security
regression for V1 and V3. It checks guest/disabled/restricted selections, ownership,
stale sessions, classic and Blocks updates, omitted final fields, certificate
creation failures, single-purchase certificates and Colorado excise fees. These
checks use in-memory WooCommerce/session and API boundaries; they make no external
requests. Run this regression alone with
`php tests/checkout-exemption-regression.php`.

The smaller admin suite remains available as `npm run cypress:run` or
`npm run cypress:open`. GitHub Actions runs this suite only; all dedicated V3
suites reject CI execution. PHP regressions also remain local.

For isolated PHP-client contracts, PHP regressions, direct legacy staging tests,
and details of the admin specs, see [advanced checks](api-v3/README.md). These
checks supplement the plugin workflows; they are not required for the everyday
E2E commands above.
