# TaxCloud staging verification

Before the candidate deployment, the implemented functional suite had **97 latest recorded passes, five failures and three blocked cases across 105 scenarios**. These are consolidated results from preserved runs, not one uninterrupted clean run. **Whole-plan acceptance has not passed.**

Latest dashboard follow-up: access is restored under a Premium Trial. Order 745's remote full return was completed and verified after reload; order 835's full return is independently confirmed in the dashboard. Order 745's local TaxCloud badge remains cached as Captured; a private reconciliation note records the completed remote return. Imported orders 561–567 remain absent from the searched dashboard views. See the final follow-up section for evidence.

Target: https://woo-staging.do.taxcloud.net/ . Deployed TaxCloud 8.4.20, WordPress 7.1.4, WooCommerce 11.2.1, PHP 8.3.36, Twenty Twenty-Five, Chrome 156/Cypress 13.6.4. Payments were offline. The user explicitly authorized Live TaxCloud captures/imports/refunds. The user subsequently instructed “ok upload the plugin”; the prepared candidate was uploaded and WordPress confirmed successful replacement on 2026-10-31. TaxCloud remains active at version 8.4.20.

## Candidate deployment

Candidate SHA-256: `80855b8ecad969a5c6d1c4d0752966b7c6eac66a12111761e19636d52f8995a0`. This staging update includes the frontend asset-registration fix and rebuilt Blocks exemption UI. It is not a public release. The public deployed Blocks bundle exactly matches the candidate (15,929 bytes; SHA-256 `16cd0294a9cd902b50e8624d5185ef489670ed76b65632fcd0ec115d07dd4e28`).

Settings were snapshotted before the update. Focused post-deployment verification uses run `e0fdc96e-6dc1-446d-8618-e213968cc044`; installation proof and build comparison are retained in that run directory. Post-deployment outcomes are recorded separately from the baseline below.

- **Four checkout exemption-control cases passed**: Realtime/Data Import × V1/V3. The previously absent V3 Blocks reason-description field is now present.
- **Both My Account certificate cases passed** in clean follow-up run `71e58458-a3a3-49bf-9e2c-d6f5c1e66d81`: full billing bootstrap, modal opening, required-field validation, close and refresh. No additional certificate was submitted.
- The first post-deployment run also selected four unrelated form-recovery tests because of the broad `shows` filter. They failed with Chrome View Transition `InvalidStateError`; its two certificate tests initially failed a visibility assertion against the zero-height outer wrapper despite screenshots showing the fixed-position dialog open. Four cleanup hooks also failed. These failures remain preserved; the test now checks the visible `.wc-backbone-modal-content`, and the certificate-only rerun passed with no hook failures. The View Transition finding needs separate investigation.
- **Both runs restored original settings with zero mismatches.** The helper is active and idle, original gateway activation/shipping priorities are restored, and the updated product remains deployed.
- These six focused passes verify the two UI changes; the remaining transaction/dashboard/policy findings and wider acceptance gaps below remain open.

## Sample certificate completed

**Certificate `1dba8bc9-6172-4fb9-bdce-86d5ff91fc17`** was created through the WooCommerce customer profile for retained QA customer **35**, using the supplied Realtime credentials and API V3. State: **MN**; synthetic state-issued identifier: **QA-ONLY-20261031**; business/reason: **Other**, with descriptions marking the record as staging QA. The user explicitly requested sample data and confirmed final submission after reviewing the form's liability warning.

Verified creation, View, Refresh Certificates, and the record in **TaxCloud → Settings → Exemption Certificates**, showing customer 35 / MN / Realtime. Direct API readback also returned HTTP 200 and the matching certificate. The API stores the reason description as `Automated staging QA`, matching the plugin's intentional 20-character truncation. The record is retained.

The initial sample `db67ed76-06d5-f111-aeb0-828f5f8d2536` was mistakenly created using the store's restored original connection, whose credentials differ from both supplied pairs. It was retained as requested for test data. The operation was corrected by snapshotting settings, configuring the supplied Realtime pair, selecting/saving the first origin again, and creating the intended record above. Original settings were then restored with **no mismatches**. Evidence: run `c012d096-6ce0-47f5-b2bf-4210d7bcae76`.

## Findings from the original deployed build

| Finding | Evidence and current state |
| --- | --- |
| V3 Blocks checkout omits required Resale description | Runtime flag `requireExemptionReasonDescription` is true, but `#purchaser_exemption_reason_value` is absent. Classic renders it. Deployed bundle is 15,702 bytes and lacks the flag/label; final candidate bundle is 15,929 bytes and contains them. Resolved in the candidate’s focused post-deployment check; the original failing evidence is retained. |
| My Account cannot open Add Certificate with a complete billing address | Confirmed on both realtime API selections after saving all required billing fields. `SST_Certificate_Table_Data` is absent from the returned page. Block themes render shortcodes before `wp_head`/`wp_enqueue_scripts`, while the plugin registers handles too late for render-time localization. The customer-profile admin path works, as the completed certificate demonstrates. Resolved in the candidate’s focused post-deployment checks for both API selections. |
| V3 capture HTTP 500 | Order **491** remained Processing with empty TaxCloud sync status. `/carts/orders` returned HTTP 500. Dashboard searches found no record; later direct API GET returned **404**. The capture was not retried. Fresh cart calculations had returned HTTP 200. Root cause is unconfirmed. |
| Data Import/V3 classic guest checkout failed once | Latest run showed WooCommerce's “There was an error processing your order” notice and never reached confirmation. Other runs passed this journey. Preserve this intermittent failure; do not claim it is resolved. The corresponding read-only WordPress audit found the 15 successfully created core orders. No fatal-error log was available. |
| Latest seven Data Import records absent from dashboard search | Orders **561–567** returned HTTP 201 at import and HTTP **200** on later direct GET, with matching IDs/completed dates. Searches in both Live/Test modes, including explicit Offline filtering, did not show them. Backend persistence is verified; dashboard reconciliation remains unresolved. |
| PayPal compatibility exception | Original active configuration: PayPal Payments 4.1.3 throws React error #299 on TaxCloud settings. Initial run had four failed setup hooks and 44 skipped cases. |
| Authorize.net compatibility exception | Original configuration: Authorize.net Payments 1.0.5 throws `Cannot set properties of undefined (setting selectedIndex)` in `anet-credit-card-update-payment-method.js:21` on My Account. |

TaxCloud's [transaction lifecycle documentation](https://docs.taxcloud.com/guides/core-concepts/transaction-lifecycle) permits recreating a cart under the same ID after conversion. Therefore, identical cart IDs in orders 489/491 do not prove the HTTP 500's cause. A speculative cart-ID change/test was removed; no product change is proposed for that unexplained failure.

Online gateways were temporarily deactivated for isolated offline TaxCloud workflows and restored afterward. Those passes do not resolve the original gateway compatibility findings.

## Latest core checkout evidence

All successful rows below compared visible and saved totals and completed the real capture/import. Registered Blocks journeys use quantity two. The original matrix run used remote IDs `sstqa-812dd9e7-<order>_0`; later reruns are described below.

| Connection / API selection | Classic customer | Classic guest | Blocks customer | Blocks guest |
| --- | --- | --- | --- | --- |
| Realtime / V1 | Pass 553 | Pass 554 | Pass 555 | Pass 556 |
| Realtime / V3 | Pass 557 | Pass 558 | Pass 559 | Pass 560 |
| Data Import / V1 | Pass 561 | Pass 562 | Pass 563 | Pass 564 |
| Data Import / V3 | Pass 565 | Failed once on order 558; passed on rerun as order 680 | Pass 566 | Pass 567 |

The dashboard independently confirms realtime **553–560** as Completed, with $10/$20 sales and $0 tax. Imported **561–567** are persisted according to direct API GET; their missing dashboard rows remain explicit above.

## Additional verified coverage

- Settings/profile/API persistence, actual management-derived mode, Verify Settings, and first Shipping Origin Address selected and saved again after credential changes.
- Product/variation/category/bulk TIC editing, cart quantity/removal/reload, coupon application/removal, guest validation/recovery, non-taxable/free carts, mobile overflow checks and debug-report retrieval.
- All five admin-surface checks passed in the follow-up run.
- Completed/Processing/Both capture policies, duplicate-completion protection, two sequential partial refunds and full refunds across the matrix. Order **491** remains a historical V3 capture HTTP 500; a fresh V3 capture/refund rerun passed for order **696** (TaxCloud HTTP 201/201), so the failure did not reproduce and its original cause is still unknown.
- Positive/negative fee totals in all groups. V1 realtime API-ownership switching, exempt-role restrictions and per-customer rate-limit notice/recovery passed. Focused V3 reruns also passed API-ownership switching, exempt-role restrictions and per-customer rate-limit recovery.
- Data Import/V3 classic guest checkout failed once in order 558, then passed on a clean rerun with order **680** and import HTTP 201. Preserve the first failure; it is intermittent/unconfirmed rather than a demonstrated fix.
- Positive tax: local pickup mixed carts saved **$1.28** tax on the physical item, zero on the virtual item, and used the origin as the physical destination while retaining the buyer's MN virtual destination. Orders **568/569** compared visible and saved **$33.63** totals; DI orders 570/571 preserved visible/saved totals. A cart reload once repeated its add-to-cart query; the cleaned-cart harness refinement still needs its own rerun.

## Frontend and transaction investigation follow-up — 2026-10-31

- **Confirmed staging frontend exception in regular Chrome:** the home/cart navigation emits `InvalidStateError: Transition was aborted because of invalid state. ViewTransition opt-in disabled`. The same error stopped the initial Cypress guest visit. After confirming it in Chrome, the harness now records its count and ignores only that exact exception so functional checkout assertions can continue; other uncaught exceptions still fail. The page/cart remains usable in the observed checks. The loaded script was WordPress's `wp-includes/js/dist/script-modules/block-library/navigation/view.min.js`; attribution to WordPress navigation/theme behavior is likely but not proven to one component.
- **Positive-tax order 745:** capture passed for the local-pickup order with $1.28 tax and $13.63 total. A manual WooCommerce admin refund was then created for the full $13.63. WooCommerce shows Refund #789 and Refunded, but the TaxCloud panel remains Captured and the order note records that TaxCloud could not find `745_0`. This occurred after the Cypress run that assigned the custom remote ID had ended: the staging-only helper's `sst_package_order_id` filter only maps orders it currently owns, so the expired run's ID `sstqa-f4b5cbf0-745_0` was not applied during this recovery. This is a QA-helper/run-lifecycle artifact, not evidence of a customer-plugin ID defect. The live QA order is still unreconciled in TaxCloud. The corrected Cypress test now imports the WooCommerce UI refund helper, and its same-run refund path subsequently passed for order 835 (see ordered fix verification below).
- **QA helper restore defect found and corrected:** helper v0.1.5's admin restore button built a JSON body without a JSON content-type, causing `No matching QA run`. Version 0.1.6 sets `Content-Type: application/json`; the PHP boundary regression now exercises the admin wrapper as well as the REST restore path. The helper update was uploaded through WordPress. Its restore action returned the active run to idle, and the later prepared-but-unused run was also restored. This helper-only change is not part of TaxCloud product version 8.4.20.
- A targeted selector typo once ran **zero Cypress tests** (run `49c0f086-c42c-4cf9-b69f-e072ca4cb050`); the CLI correctly marked the selection invalid and restored settings. It is not counted as a pass. Five focused reruns did pass: Data Import/V3 guest checkout (order 680), V3 Both capture/full refund (order 696), V3 API-ownership switching, V3 exempt-role restrictions and V3 rate-limit recovery. Their evidence remains in their per-run directories.
- **TaxCloud dashboard verification is currently blocked:** the logged-in session lands on `/go/account/edit-plan` with a notice that the plan has expired. Transactions and dashboard records are inaccessible until TaxCloud restores account access. This does not invalidate the separate direct API 201/200 evidence, and it does not resolve previously missing dashboard rows.
- Run `9de2c72e-b644-4e54-9068-9c1bbe4a6578` was prepared but no Cypress test was launched from it; it was restored through the corrected helper. Original WordPress settings are restored and the helper shows idle.

Earlier dashboard reconciliation is saved for run `5be2dc92-d668-4437-8054-f4992dd24910`: realtime captures 416/417/418 and 423/424, plus returned orders **419/425** with two distinct $10 returns each; Data Import captures 429/430/431 and 436/437, plus returned **432/438**, also with two distinct $10 returns each. Those imported records appeared with Test badges even though the connection overview said Live.

Policy run `8dd5ed7c-5db4-4119-a016-a50e60b1b85c` has dashboard proof for fully returned realtime **481/483/487/489** and imported **492/494/498/500**. The latter four each have one $10 return verified in their Returns tabs. Check the mode of each record rather than assuming it from the integration's Live label. The new view shows original sales amounts for Returned orders; the legacy view shows net zero.

## Local implementation and validation

The reusable harness/helper, CLI commands, suite routing, target pinning, credential redaction, private runner setup, run locks, settings restoration and evidence/JUnit reports are implemented under `cypress/staging-site/`. See `tests/STAGING-IMPLEMENTATION-PLAN.md` and `cypress/staging-site/README.md`.

- PHP regressions passed, including **471** API-routing assertions and the new block-theme asset-registration regression.
- V3 client checks passed **316 assertions each** for production/staging URL configurations.
- Cypress offline contracts: **302 passed**.
- Staging harness: **seven Node checks passed**; PHP helper authorization/ownership/restoration checks and PHP lint passed.
- The asset-registration regression fails against the original asset class and passes with the local fix. `includes/class-sst-assets.php` now registers frontend handles at init priority 20 so block-theme render-time customer data survives.
- Blocks build and Grunt JS/CSS build passed. The rebuilt product was subsequently deployed to staging under the user’s explicit instruction; see the candidate-deployment section.
- `releases/simple-sales-tax-8.4.20-staging-candidate.zip` contains the local asset-registration fix and rebuilt exemption UI, with runtime dependencies. QA sources, tests, credentials, `.idea` and `.env` are excluded. Integrity/credential scans passed. SHA-256: `80855b8ecad969a5c6d1c4d0752966b7c6eac66a12111761e19636d52f8995a0`. The standard archive command was unavailable because the local database/WP CLI package was unavailable; the candidate was assembled from tracked product files plus built assets/vendor files filtered by `.distignore`.

## Run integrity, remaining coverage and restoration

Evidence root: `cypress/results/staging-site/0390c295092c/`. `CONSOLIDATED-RESULTS.json` records each latest result and its source run. Per-run reports, JUnit, API/order records, screenshots, dashboard reconciliation and restoration manifests are retained. Scenario counts exclude hook failures, which are recorded separately.

The first full run was 61 passed/21 failed; the next 87-case run was 74 passed/13 failed. Harness corrections and focused reruns are preserved rather than rewriting those failures. CLI filtering now handles Cypress's pipe-to-comma normalization, records selections and rejects selected specs with zero tests. Partial afterEach results survive a headless Chrome crash. Run 99f53253 ended with seven passes/two harness failures before losing its Chrome session; all five created captures 533–537 were later verified in TaxCloud. Run 812dd9e7 was stopped after settings navigation stalled; it retained 20 completed passes/three failed cases and verified restoration. Its uncompleted policy work is not counted as passing.

**Every completed/stopped staging run, including the manual certificate checkpoint, has verified original-settings restoration with no mismatches.** Gateway activation and shipping priorities were restored. The helper remains installed, active and idle. Test users/products/pages/orders/shipping fixtures and certificates remain for inspection. Original restored credentials differ from the supplied test pairs: manually configure and verify the desired supplied pair before any later mutation, then select/save the first origin again.

Remaining acceptance gaps include imported-order dashboard reconciliation, the cached TaxCloud badge on manually reconciled order 745, certificate application/deletion and single-purchase end-to-end journeys, tax-inclusive pricing, broader shipping/fee-refund/multi-origin cases and optional integrations/browsers. Docker is unavailable here, so the local fixture E2E/Colorado/failure-injection suite has not run. These gaps prevent a whole-plugin “everything works” conclusion. The user supplied the separate deployment instruction for the current product candidate; helper v0.1.6 is a test-site tool only.


## Ordered fix verification — 2026-10-31

- **QA identity fix deployed:** helper v0.1.7 now reuses the saved remote ID only when it matches both the order's QA owner tag and WooCommerce ID, including after restore, a later run, or removal of the current-run option. It preserves package suffixes and does not rewrite old metadata. The read/control ownership boundary remains unchanged. Offline PHP regression checks cover these cases plus ordinary/untagged/mismatched-ID orders and denied cross-run reads. PHP lint and all seven harness checks passed. The installed version was confirmed in logged-in Chrome.
- **Positive-tax full refund now passed:** run `9e2f218d-0178-41b7-a3d1-7b968ce79556`, order **835**, original remote ID `sstqa-9e2f218d-835_0`, tax **$1.28**, total **$13.63**. WooCommerce manual refund **836** refunded the full **$13.63** and the plugin status became Refunded. Capture and refund both returned HTTP **201**. This closes the previously missing clean same-run positive-tax refund test, but does not reconcile old order 745 or supply dashboard proof.
- **Strict navigation gate added and passed:** new spec 06 tests guest and buyer home → cart → browser back → account with all uncaught exceptions fatal. Both tests passed in the same run. Current environment: WordPress **7.1.4**, WooCommerce **11.2.1**, Twenty Twenty-Five, PHP **8.3.36**. Regular logged-in Chrome home/cart checks also did not reproduce the old transition exception. No navigation source fix is claimed: the earlier error's cause remains unknown. Other functional specs retain the exact logged exception allowance; spec 06 is included by default and never suppresses it.
- **Original gateway configuration remains separate:** Chrome on the restored gateway configuration logged a PayPal SDK eligibility/client-token error. PayPal is not connected (the admin setup notice is present). The isolated offline run temporarily deactivated gateways as documented; its green navigation result does not validate online PayPal payments. Gateway credentials/configuration and compatibility checks remain in the plan.
- All **three selected Cypress tests passed**. The run restored original settings with **no mismatches**, revoked runner access and returned the helper to active-idle. Temporary access files were removed after restoration. Screenshots: `cypress/results/staging-site/helper-0.1.7-installed.png` and the accompanying order/refund/idle evidence.
- TaxCloud's existing sign-in form succeeded, but the account again redirected to `/go/account/edit-plan` with 'Your TaxCloud plan has expired'. Sign-in is complete; dashboard access still requires the account owner/TaxCloud to resolve the plan state. Order **745** and earlier missing dashboard rows remain open pending reconciliation. The helper fix prevents the same ID mismatch in future lifecycle calls while active; it does not automatically replay an existing failed refund.


## Dashboard reconciliation after restored login — 2026-10-31

- The user restored access. The current account dashboard displays **Premium Trial / 30 days left**, and Transactions is accessible. The earlier expired-plan blocker is historical; no subscription purchase was performed by QA.
- **Order 745 remote refund completed:** opened exact remote ID `sstqa-f4b5cbf0-745_0`, verified Realtime / WooCommerce / customer-68, Completed, $12.35 sales + $1.28 tax = $13.63, and **no existing returns**. Used the previously authorized TaxCloud full-order Return action once. After a reload, the status persisted as **Returned** and the Returns tab showed item 731, quantity one, $12.35 subtotal, $1.28 tax, $13.63 total, plus a $0 shipping return. The dashboard displayed return creation as Oct 31, 2026, 7:17 PM. This reconciles the remote tax ledger with the existing WooCommerce refund 789; no new WooCommerce refund or payment refund was created.
- **Order 745 cached local badge:** the WooCommerce order is already Refunded, while its TaxCloud panel still says Captured. A private order note records the verified dashboard return, exact remote ID and amount, and that the refund must not be replayed. This manual dashboard action did not exercise a product status synchronization path; do not report the cached badge as fixed or change it merely to make the UI appear green.
- **Order 835 dashboard proof completed:** exact remote ID `sstqa-9e2f218d-835_0` is Returned on Realtime; the Returns tab shows item 821, quantity one, $12.35 sales + $1.28 tax = $13.63. This adds dashboard proof to the earlier passing WooCommerce/API capture/refund test. The headline transaction sales/tax figures remain the original order amounts; the separate Returns table is the refund evidence.
- **Import dashboard gap persists:** Order ID Starts with `sstqa-812dd9e7-` in Live mode returned exactly the eight Realtime orders 553–560. Test mode returned no matches. Live mode with Offline Is True also returned no matches. Orders 561–567 remain absent from these views despite the prior import 201 / direct GET 200 evidence. No duplicate imports were submitted. The later Data Import guest order 680 was visible as Completed in the Live list, and 696/713 were visible as Returned.
- Screenshots: `cypress/results/staging-site/taxcloud-745-returned.png`, `taxcloud-835-returned.png`, `woocommerce-745-reconciliation-note.png`, `import-812dd9e7-test-no-results.png`, and `import-812dd9e7-live-offline-no-results.png` (all under the same directory). These dashboard checks did not change WordPress settings or start a helper run.


## Manual Chrome capture retest — 2026-10-31

At the user's request, repeated the capture through the logged-in Chrome session. Preparation run `645c68c1-8782-4610-894a-08d0d25ac95a` snapshotted settings and enabled offline payment. Configured the supplied Realtime credentials/API V3, selected the first shipping origin and saved again. Removed a pre-existing cart item, added one fresh physical QA product (838, $12.35), chose local pickup, and submitted the actual classic checkout in Chrome. The checkout and saved order both showed $1.28 tax / $13.63 total.

Fresh order **851** started On hold with TaxCloud **Pending**. Changed WooCommerce to Completed through the admin UI; the plugin panel changed to **Captured**. Independently opened exact remote ID **851_0** in TaxCloud's Live transaction list and full details: **Completed**, Realtime/WooCommerce, customer-1, Redmond WA local-pickup destination, $12.35 sales + $1.28 tax = $13.63. Reloaded the remote details and confirmed Completed persisted; Returns showed **No returns found**. The order is intentionally retained captured for inspection. No refund was submitted for 851.

This order used the original logged-in administrator's normal order ID (no staging helper prefix), so the observed capture used the ordinary customer-plugin order-ID path. This was a manual Chrome retest, not another Cypress pass. No new raw HTTP response-status evidence is claimed for this manual capture. An initial cart visit encountered a tax validation notice with the old cart's shipping destination; selecting local pickup and reaching checkout produced the correct $1.28 tax. That initial notice is retained as observed and is not called fixed.

Capture screenshots: `cypress/results/staging-site/taxcloud-851-captured.png`, `woocommerce-851-captured.png`, `manual-capture-checkout-645c68c1.png`.

The helper restore action completed successfully and the Chrome UI showed run `645c68c1-8782-4610-894a-08d0d25ac95a` idle. Original WordPress settings were restored; order 851 remains captured. Restoration proof: `cypress/results/staging-site/helper-645c68c1-restored.png`.

After capture had been independently verified and reloaded in the user-provided Premium Trial session, TaxCloud signed out again while collecting an additional top-of-page screenshot. Submitting the existing saved sign-in form landed on an expired-plan page. Account/session selection may differ from the user-provided session; do not infer the intended account's plan changed or invalidate the earlier observed capture. No further TaxCloud mutations were performed. The preserved dashboard image shows financials and empty Returns; the WordPress screenshot supplies the visible Captured badge.


## Data Import investigation — 2026-11-01

**Read-only routing/persistence checks passed.** Auth and management reads succeeded for both supplied connections. Orders 561–567 and control 680 each returned HTTP 200 on Data Import with exact order IDs and connection matches; each returned HTTP 404 on Realtime. Correct persisted totals: 561/562/564/565/567/680 are $10 and 563/566 are $20, all $0 tax. This rules out wrong-connection persistence among these two supplied pairs. No WP settings changes, capture/import/refund mutations or duplicate records were made.

**Payload comparison:** after explicitly excluding order/customer/item identifiers, timestamps and the fixture quantity difference (one versus two), the seven original import requests have the same remaining structure and business fields as 680: same origin/destination, currency, WooCommerce channel, tax/TIC and deliveredBySeller flag. Original responses all returned 201, completedDate and excludeFromFiling false. Current GET summaries retain completedDate and excludeFromFiling false. They show null/missing channel for 561–567 versus `woocommerce` for 680; dates are normalized to minutes for both groups. This is a concrete readback difference, not proof of why the dashboard omits the older records. The [official GET schema](https://docs.taxcloud.com/api-reference/api-reference/sales-tax-api/orders/get-order) permits a nullable channel. The [transaction lifecycle](https://docs.taxcloud.com/guides/core-concepts/transaction-lifecycle) describes completedDate as the liability/reporting period; these records have one.

**Future E2E coverage strengthened:** spec 01 now verifies direct remote readback immediately after supported core captures: exact ID, valid completion date, matching sales/tax/total, and sanitized evidence. Added offline regression cases for wrong IDs, missing completion and financial drift. All eight harness tests passed; ESM syntax checks passed. Validation against eight preserved actual receipts passed. No new staging Cypress run was performed for this change, so its live execution remains pending. This change affects the QA harness; no customer-plugin fix is proposed from an unproven cause.

Evidence: `cypress/results/staging-site/import-routing-comparison.json` and `import-payload-comparison.json`. Dashboard visibility remains based on the last Chrome check; it was not checked again during this API-only investigation. The remaining gap is dashboard reconciliation and identifying why the seven older records differ from visible 680. A support-ready investigation outline is in the implementation plan; no message was sent to TaxCloud.
