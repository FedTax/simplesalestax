# Remote staging QA

The complete acceptance checklist is in `tests/STAGING-IMPLEMENTATION-PLAN.md`.
This suite tests the deployed plugin; it never uploads the local TaxCloud product code.

## Initial preparation in logged-in Chrome

1. Use the user's @Chrome profile signed into WordPress and `https://app.taxcloud.com/`.
2. Package `helper/sst-staging-helper.php` as `sst-staging-helper/sst-staging-helper.php` in a ZIP; install/activate it through Plugins → Add Plugin → Upload.
3. Open Tools → SST Staging QA and click **Prepare test site**. It snapshots settings, creates tagged retained fixtures and reuses validated QA actors, creating only missing accounts, and enables offline payment. Known PayPal admin and Authorize.net My Account exceptions are isolated by temporarily deactivating online gateways (PayPal, Authorize.net, Stripe, WooPayments); their original activation states are restored with settings.
4. Download private runner access. If the browser download is intercepted, expand **Show private runner access**, save that visible field to an owner-readable local JSON file without printing it, and collapse it before taking screenshots.
5. Import that file with `npm run test:staging:setup -- --access-file=/absolute/path/access.json`.

API credentials stay in `cypress/credentials.local.json` with the existing four fields. Runner access and generated results are ignored by Git. Never include private access or original settings in reports.

## Commands

```sh
npm run test:staging:preflight
npm run test:staging:setup -- --access-file=/absolute/path/access.json
npm run test:staging
npm run test:staging:open
npm run test:staging:restore -- --run-id=YOUR_RUN_ID
```

Append `--target-url=https://your-authorized-test-site/` to any command to use another target. The setup file must belong to that exact target. Subdirectory installs are supported. Preflight and setup instructions cannot automatically drive an existing Chrome session from Node; browser-tool setup is the prerequisite.

Cypress uses its own Chrome instance and the generated runner login, never the user's browser cookies. The suite changes credentials/API selection through real settings forms, **selects the first available origin and saves again after every credential save**, and verifies persisted settings. No HTTP response fixtures are used remotely. API requests, site changes and orders are real.

The CLI restores original options in `finally`, revokes runner sessions and rotates its generated password. Test records and the helper remain. If the process is forcibly killed, use Tools → SST Staging QA → **Restore original settings and end run** in logged-in Chrome. A remaining local lock must be inspected against the server run before removal; never start another run while a prior process is active. Prepare/import a new run after restoration.

Evidence is saved in `cypress/results/staging-site/<target-id>/<run-id>/`. Cypress failure output and screenshots must be treated as private until inspected for redaction. Dashboard reconciliation uses the logged-in @Chrome tab and must be recorded separately from Cypress results. A green subset does not establish that the entire plan passed.

## Harness checks

```sh
node --test tests/staging-harness.test.js
php tests/staging-helper-regression.php
php -l cypress/staging-site/helper/sst-staging-helper.php
```

The helper's controls require administrator capability and normal REST nonce authentication. Run IDs and order ownership are checked. It never permits arbitrary PHP execution or arbitrary option/file access. V1 cURL requests are only counted as attempts; response/capture verification requires persisted order data and TaxCloud evidence.

## Focused reruns and dashboard modes

After restoring a run, prepare/import a fresh run before rerunning scenarios. Pass Cypress options through the staging command, for example:

```sh
npm run test:staging -- --spec=cypress/staging-site/specs/01-workflows.cy.js --env 'qaGroup=realtime/v3,qaCase=places|TIC'
npm run test:staging -- --spec=cypress/staging-site/specs/05-policy-and-fees.cy.js
```

`qaGroup` and `qaCase` filter the core and extended workflow specs; policy scenarios also support `qaGroup`. Separate case-title substrings with `|`; whitespace is ignored and commas are also accepted because Cypress normalizes pipe-separated CLI values to commas. The normal run includes specs 01–09. Spec 00 is a separate compatibility reproduction on the original gateway configuration; keep that evidence separate from runs that isolate online gateways.

Reconcile the tagged `sstqa-<run-prefix>-<order-id>_0` records in both legacy/new views as needed and in both Live and Test transaction modes. During verification, Data Import transactions appeared with **Test** badges even though the connection overview said **Live**. Record the observed transaction mode; the integration label alone is insufficient. The new view displays original sales amounts for Returned orders; verify the separate Returns tab instead of expecting its headline sales amount to become zero.

The helper ZIP and all QA sources are excluded from the distributable TaxCloud plugin by `.distignore`.

## Recorded verification

See `tests/STAGING-VERIFICATION-REPORT.md` for the latest recorded 105-case results and remaining failures. Manual certificate creation was confirmed in the supplied Realtime connection and TaxCloud dashboard; sample/customer IDs and evidence are recorded there. Restored original credentials can differ from the supplied test pairs, so verify the selected pair before any manual action after restoration.

Cypress 13.6.4 / Chrome 156 encountered a DevTools disconnect and settings-navigation stalls in longer runs. Preserve partial results and restore before recovery; never call an interrupted or zero-selected spec a pass. Current reporting retains `afterEach` results if `after:spec` cannot complete.

## Recovery and strict frontend regression gates

Helper v0.1.7 preserves the saved remote ID on retained QA-tagged orders after a run ends or another run starts. Its read/control endpoints still enforce current-run ownership. The helper must remain active for later lifecycle actions on retained QA orders; verify the original TaxCloud connection before any recovery refund.

Spec `06-frontend-navigation.cy.js` tests guest and customer home/cart/account navigation with `qaStrictFrontend` enabled: the previously observed ViewTransition exception fails this gate rather than being suppressed. Other workflow specs retain the narrowly recorded exception allowance until the navigation root cause is resolved. Include this gate in future full runs.

Core capture checks now include immediate remote readback for Realtime/V3 and Data Import with either API selection: exact ID, valid completed date and matching financial totals for the single-package core fixture. Sanitized channel/completion evidence is saved separately from dashboard reconciliation; nullable channel is recorded rather than treated as a failure. See the 2026-11-01 investigation in the plan/report for the original seven missing dashboard rows.

## Persistent actors, checkouts and dashboard checkpoints (helper 0.2.x)

Prepare adopts validated retained helper users once and subsequently reuses the registry IDs. Missing actors alone are created. Name/email collisions, role/capability drift and another target's registry are rejected. A changed display name does not create an account. Fresh private access rotates only validated QA credentials. Every test resets QA address/cart/rate-limit state; certificates are retained. All generated sessions end at restoration.

Checkout pages use exactly `classic-checkout/` and `block-checkout/`; valid existing pages are reused regardless of title or helper ownership. Draft, moved, wrong-content and unrelated path conflicts stop preparation. Restore the interrupted run before correcting a conflict and preparing again. Completed Prepare is resumable without creating fixtures; interrupted partial preparation requires explicit restoration rather than automatic lock takeover.

Order-name reservations persist target-wide as `Txc User N`. Reservation keys are idempotent within a run; abandoned reservations consume their numbers. The helper records ownership/name metadata while Cypress enters names through actual UI fields. It never renames historical orders or grants ownership solely because two runs share a customer. Order 851 is explicitly excluded from helper tagging and refund checkpoints.

Default Chrome desktop viewport is 1440×900. Specs 07–09 extend the main selection. `scenario-inventory.json` records acceptance requirements separately from actual execution. Customer refund/request scenarios remain blocked if the deployed site has no product workflow; absence/security assertions and API reads are separate evidence.

Refund cases call `qaAwaitDashboard` before the first refund and after each return. The task writes `dashboard-checkpoints/<order>-<phase>[-refundIds]-pending.json` and waits up to ten minutes. The Chrome agent must inspect the exact intended account/connection/order, matching financials, persisted status and Returns before writing the corresponding `-verified.json`. The verified object must match id/phase/remoteId/total/tax/refunded and contain `accountVerified: true`, `reloaded: true`, and a relative `evidence` screenshot/file reference. Capture proofs also require `completedMatches: true`, `noReturns: true`, `customerMatches: true`, `certificateMatches: true`, and `lineItemsMatch: true`; a known negative identity/status finding must fail acceptance even when the remote outcome is reconciled. Preserve pending and verified files. Never generate an approval from API-only evidence. Each partial refund has its own immutable checkpoint filename.

The CLI now attempts `restoration-ui.cy.js` in Chrome at teardown: save original credentials, select first valid origin and save again, then restore the exact original origin selection. Exact helper snapshot restoration still runs if this browser step fails; the UI gap is retained as a failure. Private snapshot credentials are returned only by the admin-authenticated task and never written to evidence. Review all automatic images before sharing.

Helper 0.2.2 stores each actor's explicit baseline and records only notification type/suppression for generated test mail. Required Woo notification settings are enabled through saved options and restored exactly. Run-owned queued Woo email context is consumed once; it cannot suppress unrelated later mail. `NOTICE-EMAIL` validates browser order/refund outcomes plus notification generation, never delivery.

The runner records harness source hashes and strips unrelated global Cypress environment secrets. An inactivity watchdog stops only its isolated Cypress process group after four minutes with no task/result progress, preserving the restoring parent. Dashboard waits publish explicit activity heartbeats and keep a three-minute reconciliation deadline. A captured order recovered after a browser stall must be verified read-only; do not rerun its checkout or completion. Staging assets are retained across the restoration child, including previous failure images.

Helper 0.2.7 reuses the site-assigned Cart/My Account pages across leases and creates only a missing required page. It identifies generated notifications by Woo email ID and run-owned order context, independent of customized subjects. V1 dashboard UUIDs can be uppercase; compare UUIDs without letter-case significance and retain raw values. A compressed single-purchase certificate_id is a local detail hash. Validate the unique remote certificate by named order/customer/state before comparing its UUID with the actual dashboard exemption field. Reason/business/state/description readback remains a separate acceptance requirement.
