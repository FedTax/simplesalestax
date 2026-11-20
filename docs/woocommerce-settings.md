# WooCommerce settings

Open **WooCommerce → Settings → Integration → TaxCloud for WooCommerce**.

## TaxCloud API credentials

**API Version** defaults to **V1 (Legacy)**. For V1, enter the TaxCloud API ID
and API Key shown on the connection. For V3, enter the **Connection ID** shown
on the WooCommerce connection and an **API Key** from **Developer > API**.
When upgrading an existing store, keep its Connection ID. WooCommerce already
stores that value in the V1 API Key field.

Click **Verify Settings** to check the credentials and load available shipping
origins. Select the origins you ship from, then click **Save changes**. A blank
V3 API Key field keeps the previously saved key. Existing V3 stores can continue
using their V1 credentials until they save a V3 API key.

Native V3 activation requires TaxCloud to return both the integration mode and
shipping origins. If verification fails, WooCommerce keeps the previous
connection settings.

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
