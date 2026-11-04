# WooCommerce settings

Open **WooCommerce → Settings → Integration → TaxCloud for WooCommerce**.

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
