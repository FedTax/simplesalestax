# Advanced test checks

For normal plugin verification, use the [E2E guide](../README.md). There is one
root `cypress.config.js`; `cypress/suites.js` selects the requested suite. The
small `cypress/run.js` launcher forwards Cypress options and selects the suite
without needing multiple config files. No new dependencies are required.

## Offline PHP checks

```sh
npm run test:regression
npm run test:api:v3
```

These need host PHP CLI and the project's Composer dependencies. They do not
load WordPress, contact TaxCloud or create database/remote records.

`test:regression` prints four success messages and exits 0. It checks destination
state exemptions for V1/V3, local pickup, captured order/refund preservation, and
API routing/persistence. Missing settings default to V1, explicit V3 persists,
and API failures never trigger calls to the other version. Capture/refund use
the original package's version; historical unmarked packages remain V1. Data
Import continues to skip realtime lookups.

`test:api:v3` covers all 14 Sales Tax endpoints, auth, request bodies, encoding,
responses and errors. Expect **316 passed, 0 failed** for each of the production
and staging URL configurations. These names describe expected URLs, not live
server requests.

## Offline PHP-client contracts in Cypress

```sh
npm run test:contracts
npm run test:contracts -- --open
npm run test:contracts -- --env apiV3Backend=docker
```

Expect **302 passing** in `contracts.cy.js`. The suite checks the actual clients'
outgoing URLs, methods, headers, payloads, authentication, responses, validation,
precision and errors for both URL configurations. Every HTTP request is blocked
unless a fixture explicitly expects it. No WordPress site or account is needed.

By default, it uses host PHP. Override the binary with
`--env apiV3PhpBinary=/path/to/php`. Docker mode uses the existing
`docker-wordpress` image, mounts the checkout read-only, disables networking and
removes its temporary helper container afterward. It does not change the site
or database. Override the image with
`--env apiV3Backend=docker,apiV3DockerImage=YOUR_IMAGE`. Docker mode supports
headless runs; use host PHP for interactive runs.

## Direct legacy staging APIs

This optional suite calls TaxCloud directly, bypassing WooCommerce. It does not
replace the plugin's live E2E tests and requires credentials accepted by the
legacy staging service:

```sh
node cypress/run.js staging
```

It uses fixed hosts `https://api.v3.taxcloud.net` and
`https://staging-taxcloudapi.azurewebsites.net/api/v3/auth/token`.
Credentials are loaded in this order:

1. `TAXCLOUD_V3_LOGIN_ID` / `TAXCLOUD_V3_API_KEY`, with optional
   `TAXCLOUD_V3_CONNECTION_ID`.
2. Ignored `cypress/local/api-v3/staging.env.local.json`, copied from
   `staging.env.example.json` in the same directory. It has `apiLoginID`, `apiKey`
   and an optional `connectionId` (defaults to the key).
3. Saved Docker WordPress settings, read without changing them. Override the
   container with `--env apiV3WordpressContainer=YOUR_CONTAINER`.

Expect **18 passing** when staging authentication succeeds. A Test-mode pair
that works on the normal hosts may be rejected by these legacy staging hosts.
The currently supplied credentials previously returned HTTP 400, “Invalid
connection credentials”; endpoint tests cannot run until staging auth succeeds.

Coverage includes auth/settings, all 14 Sales Tax routes, and two negative cases,
including a refund idempotency retry. Each run uses unique customer/cart/order
IDs. Converted orders stay uncompleted; imported orders set
`excludeFromFiling: true`. The suite disables its certificate, including after
failed runs; other staging records remain for inspection. Retries are disabled.
Credentials/tokens stay in the Node task process and errors are redacted.

## Standard admin tests and CI

```sh
npm run cypress:run
npm run cypress:open
```

Expect **8 passing across 4 specs**:

| Spec | Expected behavior |
| --- | --- |
| `bulk-editor.cy.js` (1) | Bulk-edit General Product and Lumber to Uncategorized; both saved TICs match |
| `edit-product.cy.js` (1) | Save Gift card on General Product, verify it, then restore Uncategorized |
| `plugins-page.cy.js` (1) | The plugin Settings link opens TaxCloud for WooCommerce settings |
| `settings-page.cy.js` (5) | Heading, successful Verify Settings, debug download HTTP 200/filename, API-version save/reload/restore, and disabled Show Zero Tax with Blocks |

These tests use saved WordPress credentials/settings, not
`cypress/credentials.local.json`. Valid TaxCloud credentials and the seeded
products/pages are needed. They leave both products as Uncategorized and select
Blocks cart/checkout page IDs 10/11. The API-version test restores the initial
selection after success; check it manually after an interruption. This verifies
settings persistence, not full checkout coverage for both V1 and V3.

Unlike the plugin suite, the admin command does not sync source automatically:

```sh
mkdir -p docker/plugin
rsync -a --exclude-from=.distignore ./ docker/plugin/
npm run cypress:run
```

GitHub Actions uses the default admin suite only. Plugin E2E, direct staging and
Cypress contracts reject `CI`/`GITHUB_ACTIONS`. PHP test commands are not invoked
by the current Verify workflow.

## Commands replaced during cleanup

| Previous command | Current command |
| --- | --- |
| `cypress:plugin:v3:fixture` | `test:e2e` |
| `cypress:plugin:v3` with confirmation flag | `test:e2e -- --live` |
| `cypress:plugin:v3:open:fixture` | `test:e2e:open` |
| `cypress:plugin:v3:open` with confirmation flag | `test:e2e:open -- --live` |
| `cypress:plugin:v3:cleanup` | `test:cleanup` |
| `cypress:api:v3` | `test:contracts` |
| `cypress:api:v3:open` | `test:contracts -- --open` |
| `cypress:api:v3:docker` | `test:contracts -- --env apiV3Backend=docker` |
| `cypress:api:v3:staging` | `node cypress/run.js staging` |

The plugin credentials moved from the root to `cypress/credentials.local.json`;
their four fields remain unchanged. The extra root Cypress configs were removed.
