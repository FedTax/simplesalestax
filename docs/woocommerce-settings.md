# WooCommerce settings

Open **WooCommerce → Settings → Integration → TaxCloud for WooCommerce**.

## Background capture for Data Import

In Data Import mode, enable **Capture Orders in TaxCloud**, then select **Capture Data Import orders in the background**. Background capture is off by default. The selected Processing or Completed trigger queues the saved order for Action Scheduler. Selecting Both queues the first eligible transition once.

Open the order’s **TaxCloud Status** panel to see the queue state, last error, and next retry. A job more than 15 minutes past due shows **Overdue**. Check **WooCommerce → Status → Scheduled Actions** for job history. **Capture in TaxCloud** can finish an eligible order immediately, including when WP-Cron is stalled. A refund before confirmation requires reconciliation in TaxCloud.

Disabling background capture lets queued orders finish. Disabling automatic capture or the integration, or switching away from Data Import, stops outstanding uploads. A changed connection requires review.

## WooCommerce Product Bundles

TaxCloud uses the prices WooCommerce records on the bundle and its components.
No separate TaxCloud switch is needed for the official Product Bundles extension.

| Bundle pricing | Where to set the TIC |
| --- | --- |
| Fixed price | Set the TIC on the bundle. Included components are recorded at $0. |
| Priced Individually | Set the TIC on each component or variation. TaxCloud uses the price after the bundle discount. |
| Base price plus individually priced components | Set the TIC on the bundle for the base price and on each separately priced component for its share. |

Packed components use the bundle's shipment and origin. Separately shipped and
digital components follow their own shipping or virtual product settings.

For a partial refund, select the original WooCommerce order lines. In a fixed
price bundle, refund the priced bundle line (or part of its amount) to return
tax; a $0 component has no tax to return. WooCommerce can issue a partial refund
as an amount alone, but TaxCloud cannot allocate it across lines and TICs.

If Product Bundles is deactivated, regular products can still be purchased.
Bundle listings cannot be purchased, and existing bundle orders remain
available for TaxCloud capture and refunds.

## Require a verified shipping address at checkout

Under **Advanced Settings**, enable **Require a verified shipping address at
checkout** to stop checkout when TaxCloud rejects an address (for example, an
address it cannot find or a city/ZIP mismatch). The default is off.

Both classic and block checkout show:

> We couldn't verify your shipping address. Please check the street, city and ZIP code.

Timeouts, server errors, authentication errors, and rate limits allow checkout
to continue. Admin-created orders, REST-created orders, and subscription renewals
are never blocked by this setting.

TaxCloud uses the corrected address for tax calculation when verification
succeeds. A private order note lists city, state, or five-digit ZIP corrections.
Street standardization or adding ZIP+4 alone does not create a note. Verification
failures and skipped verification create a private note explaining the fallback.
These behaviors apply to both V1 and V3.

See [address verification troubleshooting](troubleshooting.md) for note messages
and retry behavior.
