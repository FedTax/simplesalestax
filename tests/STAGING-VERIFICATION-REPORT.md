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
  - Realtime (ID: `64722`, Key: `ed02a7b4-6da1-402f-9d8a-680ddd4302a9`) — **Live**
  - Data Import (ID: `64723`, Key: `e4dde83b-a9a1-4190-8c6a-01b729e8a3ba`) — **Live**
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
