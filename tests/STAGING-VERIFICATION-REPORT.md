> Current implementation status (2026-11-03, Asia/Dhaka): QA helper 0.2.5 is uploaded. Expanded verification is continuing in isolated leases after the preserved full-selection failure. Earlier totals below are historical, not current acceptance totals. See the appended run ledger and ignored per-run evidence. No customer-plugin deployment/release; order 851 remains untouched.

# TaxCloud Staging Verification Report

Target: **`https://woo-staging.do.taxcloud.net/`**  
Execution Date: **2026-11-02**  
Run ID: **`98173592-b7e4-4c10-937e-f979b78b552b`** (Prefix: `sstqa-98173592-`)  
Evidence Directory: `cypress/results/staging-site/0390c295092c/98173592-b7e4-4c10-937e-f979b78b552b/`

---

## 1. Executive Summary

- **Automated Cypress Execution:** **100 passed, 4 failed, 4 blocked/skipped** out of 108 scenarios.
  - `01-workflows.cy.js`: 46 passed, 2 failed
  - `02-settings-and-shopping.cy.js`: 31 passed, 0 failed (100% pass)
  - `03-readonly-settings.cy.js`: 4 passed, 0 failed (100% pass)
  - `04-admin-surfaces.cy.js`: 5 passed, 0 failed (100% pass)
  - `05-policy-and-fees.cy.js`: 12 passed, 2 failed, 4 skipped
  - `06-frontend-navigation.cy.js`: 2 passed, 0 failed (100% pass)
- **TaxCloud Connections Confirmed LIVE:**
  - Realtime (connection identifiers redacted) — **Live**
  - Data Import (connection identifiers redacted) — **Live**
- **TaxCloud V3 Dashboard Reconciliation:**
  - **24 total transactions** verified live in `https://app.taxcloud.com/go/transactions/v3`
  - **16 Completed** captures
  - **8 Returned** full refunds (including WA state tax of $1.28 on order `998_0`)
  - 100% recorded in Live Mode (`isTest: false`)
- **Settings Restoration:** **Verified with 0 mismatches** (`restored: true, mismatches: []`). Original site settings, plugin options, and shipping zones were restored. The runner sessions were revoked, passwords rotated, and the QA helper left in `active-idle`.

---

## 2. Environment Details

- **Target URL:** `https://woo-staging.do.taxcloud.net/`
- **WordPress Version:** 7.1.4
- **WooCommerce Version:** 11.2.1
- **TaxCloud / Simple Sales Tax Version:** 8.4.20
- **PHP Version:** 8.3.36
- **Active Theme:** Twenty Twenty-Five
- **Test Browser:** Chrome 156 / Cypress 13.6.4
- **Credentials:** Loaded from `cypress/credentials.local.json`
- **TaxCloud Account:** Toptal (Account ID: `67959`), Premium Trial plan

---

## 3. Staging Setup & Runner Provisioning

1. Verified target accessibility and preflight checks (`npm run test:staging:preflight`).
2. Prepared test site via `sst-staging-helper` v0.1.7:
   - Generated runner administrator `sstqa-98173592-runner` (User ID: 106)
   - Provisioned test customers: `sstqa-98173592-buyer` (User ID: 107) and `sstqa-98173592-exempt` (User ID: 108)
   - Provisioned 8 test products/variations (IDs 946–953)
   - Provisioned 5 test pages (Classic Cart, Classic Checkout, Block Cart, Block Checkout, My Account — IDs 954–958)
   - Configured test shipping zone 35 with flat rate, free shipping, and local pickup
   - Snapshotted all original site and TaxCloud plugin settings prior to mutation
   - Deactivated online payment gateways (`woocommerce-paypal-payments`, `woocommerce-gateway-stripe`) during testing; cheque payment enabled for offline testing.

---

## 4. Test Matrix & Detailed Suite Results

### A. Spec 01: Core Workflows (`01-workflows.cy.js`) — 46 Pass, 2 Fail
- **Realtime / V1 (12/12 Passed - 100%):**
  - Settings persistence & origin address retention: Pass
  - Product TIC search and reload (`00000` Uncategorized): Pass
  - Classic Buyer order placement with matching visible/saved totals: Pass (Order 961, $10.00, captured)
  - Classic Guest order placement: Pass (Order 962, $10.00, captured)
  - Blocks Buyer order placement: Pass (Order 963, $20.00, captured)
  - Blocks Guest order placement: Pass (Order 964, $10.00, captured)
  - Capture & two sequential partial refunds: Pass (Order 965, $20.00 total, 2x $10 refunds, status Refunded)
  - Order cancellation without remote capture: Pass (Order 968, $10.00, cancelled)
  - Cart quantity adjustments & full item removal: Pass
  - Coupon application and removal: Pass
  - Exemption controls on classic and Blocks: Pass
  - Mobile viewport (390×844) rendering without overflow: Pass
- **Realtime / V3 (10/12 Passed):**
  - Settings persistence & origin address retention: Pass
  - Product TIC search and reload: Pass
  - Classic Buyer order placement: Failed (checkout URL timeout reaching order-received)
  - Classic Guest order placement: Pass (Order 969, $10.00, captured)
  - Blocks Buyer order placement: Pass (Order 970, $20.00, captured)
  - Blocks Guest order placement: Pass (Order 971, $10.00, captured)
  - Sequential refund test: Failed on local metadata capture expectation
  - Order cancellation without remote capture: Pass (Order 973, $10.00, cancelled)
  - Cart quantity changes & coupon workflows: Pass
  - Exemption controls & mobile rendering: Pass
- **Data Import / V1 (12/12 Passed - 100%):**
  - Settings persistence, disabled exemptions/rate limits in Data Import mode: Pass
  - Product TIC search & save: Pass
  - Classic Buyer order placement: Pass (Order 974, $10.00, imported)
  - Classic Guest order placement: Pass (Order 975, $10.00, imported)
  - Blocks Buyer order placement: Pass (Order 976, $20.00, imported)
  - Blocks Guest order placement: Pass (Order 977, $10.00, imported)
  - Capture & sequential refunds: Pass (Order 978, $20.00 total, fully refunded)
  - Order cancellation without remote capture: Pass (Order 981, $10.00, cancelled)
  - Cart quantity, coupons, mobile viewport: Pass
- **Data Import / V3 (12/12 Passed - 100%):**
  - Settings persistence & verification: Pass
  - Product TIC search & save: Pass
  - Classic Buyer order placement: Pass (Order 982, $10.00, imported)
  - Classic Guest order placement: Pass (Order 983, $10.00, imported)
  - Blocks Buyer order placement: Pass (Order 984, $20.00, imported)
  - Blocks Guest order placement: Pass (Order 985, $10.00, imported)
  - Capture & sequential refunds: Pass (Order 986, $20.00 total, fully refunded)
  - Order cancellation without remote capture: Pass (Order 989, $10.00, cancelled)
  - Cart quantity, coupons, mobile viewport: Pass

### B. Spec 02: Settings & Shopping (`02-settings-and-shopping.cy.js`) — 31 Pass, 0 Fail (100%)
- Mixed physical/virtual carts with shipping method changes: Pass (Orders 990, 997, 1006, 1011)
- Free and non-taxable cart combinations: Pass (Orders 991, 1000, 1007, 1012)
- Form validation error recovery without duplicate order creation: Pass (Orders 992, 1001, 1008, 1013)
- Capture disabled setting prevents completion from submitting to TaxCloud: Pass (Orders 993, 1002, 1009, 1014)
- Integration disabled setting prevents tax calculation and order submission: Pass (Orders 994, 1003, 1010, 1015)
- State & address changes clearing stale tax: Pass (Orders 995, 1004)
- Line subtotal calculation producing consistent totals with fractional pricing: Pass (Orders 996, 1005)
- Positive-tax local-pickup capture and full refund: Pass (Order 998, $12.35 sales, $1.28 WA tax, $13.63 total, fully refunded)
- My Account certificate modal: open, validate required fields, close, and refresh: Pass across Realtime V1 & V3

### C. Spec 03: Read-Only Settings (`03-readonly-settings.cy.js`) — 4 Pass, 0 Fail (100%)
- Integration Mode is derived and read-only: Pass
- Refreshing connection mode updates derived fields: Pass
- Switching between Realtime and Data Import preserves saved settings: Pass
- Returning to Realtime restores rate-limit, exemption, and lookup controls: Pass

### D. Spec 04: Admin Surfaces (`04-admin-surfaces.cy.js`) — 5 Pass, 0 Fail (100%)
- Diagnostic debug report download and sanitization: Pass
- TaxCloud logging control toggles request/response logging: Pass
- Bulk-editing product TICs across run-owned catalog: Pass
- Origin address selector reflects available connection origins: Pass
- Settings links from Plugins list page navigate correctly: Pass

### E. Spec 05: Policy & Fees (`05-policy-and-fees.cy.js`) — 12 Pass, 2 Fail, 4 Skipped
- Processing capture policy submits on `Processing` status and refunds cleanly: Pass (Orders 1016, 1024, 1027, 1032)
- Both capture policy submits on `Processing` and prevents duplicate capture on `Completed`: Pass (Orders 1018, 1029, 1034)
- Synthetic positive ($2.00) and negative (-$2.00) fees allocate and calculate correctly: Pass (Orders 1020, 1021, 1031, 1036, 1037)
- API ownership switching preserves package routing: Pass (Order 1022)
- *Failures:* 2 cases in teardown hook due to a settings form element lookup; remaining 4 dependent cases in those groups were skipped.

### F. Spec 06: Frontend Navigation (`06-frontend-navigation.cy.js`) — 2 Pass, 0 Fail (100%)
- Guest navigation (home → cart → browser back → account) without uncaught exceptions: Pass
- Registered buyer navigation without uncaught exceptions: Pass

---

## 5. TaxCloud Portal Reconciliation Table

Verified live in **`https://app.taxcloud.com/go/transactions/v3`** on **2026-11-02**:

| # | TaxCloud Order ID | Status | Customer | Sales | Tax | Destination | Mode | API Engine |
|---|---|---|---|---|---|---|---|---|
| 1 | `sstqa-98173592-1034_0` | **Returned** | `customer-107` | $10.00 | $0.00 | Minneapolis, MN | Live | Data Import (V3) |
| 2 | `sstqa-98173592-1032_0` | **Returned** | `customer-107` | $10.00 | $0.00 | Minneapolis, MN | Live | Data Import (V3) |
| 3 | `sstqa-98173592-1029_0` | **Returned** | `customer-107` | $10.00 | $0.00 | Minneapolis, MN | Live | Data Import (V1) |
| 4 | `sstqa-98173592-1027_0` | **Returned** | `customer-107` | $10.00 | $0.00 | Minneapolis, MN | Live | Data Import (V1) |
| 5 | `sstqa-98173592-1024_0` | **Returned** | `customer-107` | $10.00 | $0.00 | Minneapolis, MN | Live | Realtime (V3) |
| 6 | `sstqa-98173592-998_0` | **Returned** | `customer-107` | $12.35 | $1.28 | Redmond, WA | Live | Realtime (V3) |
| 7 | `sstqa-98173592-986_0` | **Returned** | `customer-107` | $20.00 | $0.00 | Minneapolis, MN | Live | Data Import (V3) |
| 8 | `sstqa-98173592-985_0` | **Completed** | `customer-0` | $10.00 | $0.00 | Minneapolis, MN | Live | Data Import (V3) |
| 9 | `sstqa-98173592-984_0` | **Completed** | `customer-107` | $20.00 | $0.00 | Minneapolis, MN | Live | Data Import (V3) |
| 10 | `sstqa-98173592-983_0` | **Completed** | `customer-0` | $10.00 | $0.00 | Minneapolis, MN | Live | Data Import (V3) |
| 11 | `sstqa-98173592-982_0` | **Completed** | `customer-107` | $10.00 | $0.00 | Minneapolis, MN | Live | Data Import (V3) |
| 12 | `sstqa-98173592-978_0` | **Returned** | `customer-107` | $20.00 | $0.00 | Minneapolis, MN | Live | Data Import (V1) |
| 13 | `sstqa-98173592-977_0` | **Completed** | `customer-0` | $10.00 | $0.00 | Minneapolis, MN | Live | Data Import (V1) |
| 14 | `sstqa-98173592-976_0` | **Completed** | `customer-107` | $20.00 | $0.00 | Minneapolis, MN | Live | Data Import (V1) |
| 15 | `sstqa-98173592-975_0` | **Completed** | `customer-0` | $10.00 | $0.00 | Minneapolis, MN | Live | Data Import (V1) |
| 16 | `sstqa-98173592-974_0` | **Completed** | `customer-107` | $10.00 | $0.00 | Minneapolis, MN | Live | Data Import (V1) |
| 17 | `sstqa-98173592-971_0` | **Completed** | `customer-0` | $10.00 | $0.00 | Minneapolis, MN | Live | Realtime (V3) |
| 18 | `sstqa-98173592-970_0` | **Completed** | `customer-107` | $20.00 | $0.00 | Minneapolis, MN | Live | Realtime (V3) |
| 19 | `sstqa-98173592-969_0` | **Completed** | `customer-0` | $10.00 | $0.00 | Minneapolis, MN | Live | Realtime (V3) |
| 20 | `sstqa-98173592-965_0` | **Returned** | `107` | $20.00 | $0.00 | Minneapolis, MN | Live | Realtime (V1) |
| 21 | `sstqa-98173592-964_0` | **Completed** | `0` | $10.00 | $0.00 | Minneapolis, MN | Live | Realtime (V1) |
| 22 | `sstqa-98173592-962_0` | **Completed** | `0` | $10.00 | $0.00 | Minneapolis, MN | Live | Realtime (V1) |
| 23 | `sstqa-98173592-963_0` | **Completed** | `107` | $20.00 | $0.00 | Minneapolis, MN | Live | Realtime (V1) |
| 24 | `sstqa-98173592-961_0` | **Completed** | `107` | $10.00 | $0.00 | Minneapolis, MN | Live | Realtime (V1) |

---

## 6. Settings Restoration & Cleanup Verification

- **Restoration Execution:** Completed via `SST_Staging_QA::restore` REST action and verified by `cypress/staging-site/cli.js`.
- **Restoration Equality:** **Zero mismatches** across all snapshotted options:
  - `woocommerce_wootax_settings`
  - `active_plugins` (including re-enabling payment gateways)
  - `woocommerce_calc_taxes`
  - `woocommerce_prices_include_tax`
  - `woocommerce_tax_based_on`
  - `woocommerce_tax_display_shop`
  - `woocommerce_tax_display_cart`
  - `woocommerce_cart_page_id`, `woocommerce_checkout_page_id`, `woocommerce_myaccount_page_id`
  - `woocommerce_enable_guest_checkout`, `woocommerce_enable_checkout_login_reminder`
  - `woocommerce_enable_signup_and_login_from_checkout`
  - `woocommerce_cheque_settings`
  - `woocommerce_currency`, `woocommerce_default_country`, `woocommerce_allowed_countries`, `woocommerce_ship_to_countries`
  - Shipping zones order and method states
- **Runner State:** Runner user sessions revoked and password rotated to a 48-character random string.
- **Helper State:** Installed, active, and idle (`sst_staging_qa_run.active = false`).
- **Retained Data:** Tagged test products (946–953), test pages (954–958), shipping zone (35), and order history remain on `woo-staging.do.taxcloud.net` for reference.

## Latest implementation diagnostics — 2026-11-02 (additional evidence)

The earlier 108-scenario result above is preserved and does **not** cover the new persistent-identity, fixed-page and workflow requirements. The latest implementation is still being verified. Connection identifiers previously embedded in this report have been redacted.

| Run | Purpose/result | Restoration |
| --- | --- | --- |
| `cccabb16-85f3-40ed-ac0f-244647204a9b` | Fixture setup/resume: adopted existing users 7/8/9; created missing fixed checkouts 1048/1050; no browser E2E tests claimed | Exact, zero mismatches |
| `ce86b2a9-bc75-46f2-8210-a0adc72c2f87` | Short Chrome: 1 passed, 1 failed. Classic guest order 1065 named `Txc User 1` captured; Blocks harness failed before submission on collapsed address form. Name reservation 2 is retained/consumed. | Exact, zero mismatches |
| `699af089-2fcd-4b20-9280-97d9e783fb24` | Short Chrome: Blocks buyer passed; admin product-search selector and WP own-profile certificate submission diagnostic failed. No admin/certificate creation pass inferred. | Exact, zero mismatches |

TaxCloud Chrome session confirmed intended **Toptal** account with Premium Trial. Order 1065 is independently visible in Live Realtime, Completed after reload, $10 sales/$0 tax/$10 total, no returns. Evidence: `cypress/results/staging-site/0390c295092c/ce86b2a9-bc75-46f2-8210-a0adc72c2f87/dashboard-reconciliation.json` and `dashboard-1065.png`. The narrowly recorded WordPress ViewTransition error recurred during classic setup and remains an open frontend quality issue; the functional checkout result does not close that issue.

Offline validation: existing PHP regression scripts passed; V3 client tests passed under both URL configurations; API-only Cypress contracts 302/302; staging helper boundary and persistent fixture regressions passed; Node harness checks 8/8. These are recorded separately from live browser journeys. Docker/local WooCommerce fixture E2E is unavailable on this host. Full expanded matrix, certificate entry-point creation, dashboard checkpoints and customer-refund feature applicability remain in progress. Order 851 was not operated on.

### Additional diagnostic ledger and recovered outcomes (2026-11-02)

| Run | Automated result | Additional evidence / limits |
| --- | --- | --- |
| `17d43e5c-955c-4809-b871-ca718f813900` | 1 passed, 1 failed | WP own-profile certificate creation passed under QA runner 7, exact certificate/customer/state readback; independently visible in TaxCloud. Admin address selector stopped before capture. Restoration UI child initially selected no spec; exact snapshot restoration succeeded. |
| `a09cb2b0-ccc5-47c9-b483-31162e778b16` | 1 passed, 3 failed (including restoration UI) | Customer-profile certificate creation passed for user 8. Admin modal search and My Account modal scrolling failed. Restoration UI picked an origin under the prior API selection; exact fallback restored with zero mismatches. Primary failure screenshots from this run were removed by Cypress's default cleanup during its restoration child; JSON/errors remain. Cleanup is now disabled for staging evidence. |
| `10198121-5916-4169-a21d-dfeb0d2ddb75` | Partial: 4 completed passes, 1 failure; unfinished/unstarted cases are not passes | Includes one restoration-UI pass. Admin financial lifecycle, My Account creation and classic buyer certificate capture completed. Guest certificate selector absent. Browser stalled after Blocks order 1124 capture at admin completion; only its identified Cypress child was terminated, parent restored settings. Exact fallback had zero mismatches. |

**New failing acceptance finding — admin guest remote customer association:** order **1119**, `Txc User 7`, is saved with Woo customer ID **0**, but TaxCloud shows **customer-7**, the acting QA administrator. Realtime/V3, deployed 8.4.20. Exact order/amount/items were independently matched before refund; $10 + $10 item returns and $2 fee return were each reloaded/verified in Returns, total $22, zero remaining. No duplicate return was submitted. The initial automated financial case passed its older assertions; it does **not** pass the customer-association acceptance requirement. The harness now validates remote customer identity and will fail that mismatch. Evidence: `10198121-5916-4169-a21d-dfeb0d2ddb75/findings.json`, `orders.ndjson`, and `dashboard-checkpoints/1119-*`.

**Actual certificate submissions:** WP own profile (user 7), WP edited-customer profile (user 8), frontend My Account and classic buyer single-purchase checkout now have completed browser evidence. Order **1123**, `Txc User 8`, is Completed on Realtime with $10 sales/$0 tax/$10 total and the exact submitted certificate; no Returns. Original per-entry-point failures remain preserved. Guest checkout creation is unavailable in the observed classic UI and must remain a feature gap rather than a creation pass.

**Interrupted order 1124:** read-only Woo recovery showed Completed/Captured, so no capture or checkout was replayed. Original observed capture HTTP 201 is retained. Exact TaxCloud detail, after reload, independently confirms Completed, customer-8, $10 sales/$0 tax/$10 total, matching single-purchase certificate and no Returns. The uncertain mutation journal is resolved using `dashboard-checkpoints/1124-read-only-recovery.json`. This is recovery evidence, **not a completed Cypress case**.

The browser restoration regression now saves the original API selection with the original credentials before selecting/saving the first valid origin, then restores the original origin set. This passed in the stopped run; exact snapshot verification still follows. New watchdog checks prove that only the isolated Cypress child group is stopped on inactivity and that healthy dashboard waits stay alive. Ten Node harness tests pass. No customer plugin deployment or release has occurred; order 851 remains untouched.


### Latest short run and full-matrix start (2026-11-02)

Run `813b8590-960d-44da-a763-69b10aed3741`: **4 browser E2E passed, 2 failed, 1 blocked**. Configuration restoration UI passed separately; exact snapshot restoration returned zero mismatches.

- Order 1151, `Txc User 11`, customer 8: captured $31.70, two physical items + $2 fee + $5 shipping. Refund 1152 returned exactly shipping $5, tax $0; remaining $26.70. Exact Completed capture, empty Returns before refund, and persisted shipping return were independently verified in Chrome.
- Order 1153, `Txc User 12`, customer 8: Completed $10, tax $0, no Returns after reload. My Account has no refund/request workflow; this case is blocked.
- Ownership denials (order 1154), certificate ownership and single-order double-submit (1156) passed.
- Order 1155, `Txc User 14`: capture HTTP 500; Woo Completed does not establish remote capture. Exact read-only remote GET later returned 404; dashboard could not load the exact record after reload. **No retry/capture/refund was submitted.** The same cart ID occurred on successful order 1153, but the cause remains unresolved. The initial read-only audit’s incorrect integration-ID route returned 422 and is preserved alongside the corrected connection-UUID lookup.
- Order 1157, `Txc User 16`: placed once, guest confirmation reload assertion failed. The recovery workflow now handles the actual Woo guest email-verification form (wrong synthetic email must fail, correct controlled email must reveal the named order). It still needs fresh browser evidence.

Evidence lives under `cypress/results/staging-site/0390c295092c/813b8590-960d-44da-a763-69b10aed3741/`, including immutable mutation intents, API evidence, dashboard checkpoints, failure screenshots and restoration. The report parser now excludes planned-case manifests from result specs, avoiding false empty-spec entries. Raw failures remain unchanged.

A headed startup-only run `2d3d5b8a-864a-4c5b-96d5-94e78ebe2248` produced zero primary tests while macOS was locked; its watchdog stopped only the isolated child. Restoration UI and exact restoration passed. It is not a primary E2E pass. The user subsequently unlocked the Mac.

Helper **0.2.4** is now uploaded. It clears leased actor Woo sessions even when WC has no session object in an admin/REST request. PHP boundary/fixture checks and 12 Node harness checks pass. The fresh full matrix `faad5de4-34e6-4ddf-be66-2a2dd0799345` is in progress across all nine main specs and four groups. Its final results will supersede this execution-status paragraph, while preserving all earlier findings. Toptal 67959/Premium Trial was independently reconfirmed.


### Full selection result and isolated continuation

Full selection `faad5de4-34e6-4ddf-be66-2a2dd0799345` finalized all nine main specs: **28 browser E2E passed, 39 failed, 128 blocked**. Restoration UI passed separately; exact restoration verified zero mismatches. These are raw automated classifications: many later failures are guard/setup consequences (including undefined fixture subjects), not 39 distinct customer-plugin defects. The full selection is not a clean full matrix. Raw per-spec results, planned-case manifests, screenshots and coverage.json remain intact.

Realtime/V1 completed all four checkout combinations (orders 1171–1174) and the two-unit lifecycle (1175, refunds 1176/1177). The V1 new dashboard detail has a blank status badge; exact legacy search independently shows Completed after reload. Matching customer, totals, item quantity, certificate absence and empty Returns were verified. Both distinct one-unit $10 returns persisted; $20 total refunded and $0 remaining. Orders 1171/1172 were also independently reloaded in legacy. Realtime/V1 coupon failed with a truncated submitted code; other V1 core cases passed.

Realtime/V3 completed all four checkout combinations (1179–1182); exact customer, item, Completed status, $10/$20 totals and empty Returns persisted after reload. Lifecycle order **1183**, `Txc User 29`, capture returned HTTP **500** (`an unexpected error occurred`). Woo Completed / empty TaxCloud badge is not capture success. Exact read-only GET returned 404 and dashboard detail was unavailable. **No capture/refund replay.** The unresolved current-run intent correctly prevented subsequent connection switching; later cases consequently failed setup or were skipped. Strict guest and buyer frontend navigation passed independently.

The full-run original captures remain retained. The harness now additionally requires explicit persisted Completed and empty-Returns proof in its capture acceptance assertions. The coupon interaction types without per-character delay and verifies the complete value before submission; the original failure is retained. Twelve offline harness checks pass.

Continuation run `81cdda0a-d3ea-4e8b-b8ab-b496f8011ed9` is isolated to remaining Realtime/V1 extended shopping, policy, admin, certificate and permission/recovery specs. Future fragments must use fresh leases and fresh logical orders; they must not replay uncertain 1155/1183. Report them as a consolidated matrix continuation, never a clean single-run full-suite pass.


Continuation `81cdda0a-d3ea-4e8b-b8ab-b496f8011ed9` completed all nine V1 extended-shopping cases successfully. Four subsequent setup failures/30 blocked cases were caused by a false capture intent on disabled-integration order 1201, not a remote transaction failure. The passing disabled-integration browser case confirmed no lookup/capture, and the original intent has an empty remote ID. An explicit `reconciled-no-submission` artifact preserves this distinction; no mutation was replayed. Restoration UI and exact restoration passed.

The harness now journals a prospective capture only when integration is enabled, capture is enabled, the selected status is a capture trigger, and the order is not already captured/refunded. Thirteen offline checks pass, including disabled integration/capture and repeated-completion intent eligibility. Fresh pending V1 specs 05/07/08/09 run: `b2d517d7-e629-4d5b-b347-27c0c32ade10`.


### V1 expanded continuation — active evidence

Run `b2d517d7-e629-4d5b-b347-27c0c32ade10` passed all six V1 policy cases. Captures/returns for processing (1216/1217), Both (1218/1219) and stored V1 API ownership after changing the selector to V3 (1222/1223) were independently verified in logged-in Chrome. Returns match one unit $10, one unit $10 and three units $30 respectively, all with zero remaining. Role restrictions and per-customer rate-limit recovery also passed.

**Admin identity finding also reproduced under V1:** guest order 1224, `Txc User 51`, Woo customer 0 versus TaxCloud customer 7. Exact $22 financials, two units plus fee, Completed legacy result and empty Returns were verified. The identity assertion failed; its refund steps were not run. The retained capture was not replayed. This extends the earlier V3 finding on 1119. Admin buyer and saved-certificate scenarios stopped earlier on a hidden Edit address link: Woo had already opened the form after customer selection. Preserve those harness failures and correct the conditional interaction after the active run.

Storefront V1 shipping-only refund 1228 on order 1227 returned SHIPPING $5/$0 tax, remaining $26.70; fee-only refund 1230 on order 1229 returned qa-service-fee $2/$0 tax, remaining $29.70. Both captures were verified Completed with matching customer/items/amounts and empty Returns before refund, then exact return allocations persisted after reload. Further scenarios remain in progress.

Privacy incident: a TaxCloud navigation timeout emitted a credential-bearing URL in computer-tool output. No value is copied here. Subsequent navigation/inspection failures are caught before output, and dashboard snapshots are masked. Tracked report/source files must be scanned against supplied secrets before handoff.


### V1 expanded run finalized; QA-only corrections (2026-11-03 Asia/Dhaka)

Run b2d517d7-e629-4d5b-b347-27c0c32ade10 finalized **19 browser passes, 12 failures, 3 blocked**; restoration UI passed separately and exact restoration had zero mismatches. All six policy cases and four allocated storefront refunds passed. Mixed refund 1232 on 1231 returned one item $12.35, fee $2 and shipping $5 (total $19.35, remaining $12.35). Full refund 1234 on 1233 returned both items $24.70, fee $2 and shipping $5 (total $31.70, remaining zero). Exact allocations persisted after reload in Chrome. Admin named draft save/cancel, ownership denials, pay-for-order/reorder, double-submit and guest confirmation recovery also passed.

The three management certificate submissions completed UI submission/view/refresh/readback under their original assertions; broader data acceptance is **not established**: all three V1 readbacks report reason Unknown despite selecting Resale. New assertions now check reason, business type and the supported 20-character description. Do not call the old narrow passes full certificate-data acceptance.

Preserved harness findings: V1 dashboard displays saved certificate UUIDs uppercase (1237’s false mismatch is preserved); local compressed single-purchase packages store a detail hash, not the remote certificate UUID (1244/1245 direct-ID comparison failures are preserved). The new mapping regression uniquely joins an actual checkout submission to a remote single-purchase certificate using named order/customer/state; the dashboard must still show that mapped UUID. Cross-surface customer/My Account certificates were independently matched on classic and Blocks orders 1238/1239 and 1241/1242; combined cases later failed on the already-open admin address selector.

The positive-quote screenshot proves $1.28 tax/$13.63 total before exemption; its failure was a tax-row selector, not absent positive tax. Admin quantity editing needs the explicit item Save action before recalculate; original failure remains. Mobile Enter simulation did not activate the certificate modal; the harness now dispatches native Chrome key events rather than replacing the keyboard action with a click. These corrections require fresh browser verification.

Notification order 1248 was captured and fully returned ($10, refund 1249) with matching reloaded evidence. The subsequent assertion failed because the helper classified a customized completed-order email as other by parsing subject text. Helper **0.2.5**, now uploaded, uses Woo’s stable email ID plus an explicitly owned order context, consumed once. It records only notification type/order ID/suppression, never subject/recipient/body, and keeps exact email-option restoration. PHP privacy/boundary/session regressions and 14 Node harness checks pass.

Short correction run: f49b74cf-5c45-49a4-b029-ea74f6b84106, selecting coupon, corrected admin buyer/saved-cert/edit, certificate reason, positive exemption, native mobile keyboard and notification checks. Remaining matrix groups still require execution; no clean full-suite pass is claimed.


### Final correction subset and Data Import continuation

Run f49b74cf-5c45-49a4-b029-ea74f6b84106 ended **3 browser passes, 5 failures** with exact restoration and a passing restoration UI. Coupon apply/remove, explicit admin item-save quantity editing and native Chrome keyboard activation at mobile size passed. Admin buyer 1267 reached capture successfully after the address-form fix, but TaxCloud customer 7 still differed from saved Woo customer 8 (same association defect as guest 1224). Saved-certificate order naming exposed a customer-address AJAX race; the harness now waits for Woo get_customer_details before typing reserved names. Positive exemption now waits for the actual checkout review response and retries its total assertion rather than comparing before recalculation. The new WP-self certificate reason assertion failed explicitly on Unknown versus Resale.

Notification case 1270 timed out at its dashboard checkpoint after the TaxCloud session expired. The Cypress failure is retained. Exact read-only recovery in the reconfirmed Toptal 67959 session shows Completed after reload, customer 8, $10/$0 tax, matching item and no Returns; no checkout/capture/refund replay occurred. Recovery is not a Cypress pass. Helper 0.2.5 evidence records new-order and completed generation once with suppression; full notification/refund acceptance remains pending.

Data Import/V1 full selection is running as 2d3e284d-252f-42a3-af26-b0e962c40197. Deployed product remains 8.4.20 and helper 0.2.5. A concurrent checkout merge changed the local package to 8.4.21; this task did not deploy that product version. All four fresh placement combinations (1283–1286) passed with persistent actors/checkouts and sequential names. Exact Data Import dashboard proof verifies Completed, matching customer/items/amounts and no Returns after reload. Lifecycle 1287 was verified before refund; two distinct one-unit $10 returns persisted, cumulative $20 with zero remaining and Returned status. Other matrix scenarios remain in progress.
