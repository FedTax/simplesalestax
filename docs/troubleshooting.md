# Address verification troubleshooting

## “TaxCloud verified the shipping address: …”

TaxCloud corrected the city, state, or five-digit ZIP and used the corrected
destination to calculate tax. For example:

> TaxCloud verified the shipping address: city "Riverisde" → "Riverside", ZIP 92508 → 92508-8114

The customer's stored shipping details are preserved. The note describes the
address TaxCloud used to calculate tax. Adding ZIP+4 or standardizing a street
without changing the city, state, or five-digit ZIP does not create a note.

## “TaxCloud could not verify the shipping address (…). Tax was calculated on the address as entered.”

The plugin fell back to the entered address. In a ZIP spanning several tax
jurisdictions, a five-digit ZIP may produce a different local rate than a
verified street address. Review the reason in the private order note:

| Reason | What to check |
| --- | --- |
| address not found | Confirm the street number, street name, and unit with the customer. |
| city/ZIP mismatch | Confirm the city, state, and ZIP belong together. |
| missing street or ZIP | Supply the missing address details before recalculating. |
| TaxCloud unavailable | Check the configured credentials, TaxCloud service availability, and request logs. |
| rate limit reached | Review the plugin's rate limit settings and try again after the limit resets. |

Successful verifications and address rejections are cached for two days.
Temporary failures and rate-limit skips are not cached; the next lookup attempts
verification again. Older cache entries without a verification outcome are
reverified once. Recalculating an unchanged result does not duplicate the note.

To stop customer checkout for address rejections, enable
[Require a verified shipping address at checkout](woocommerce-settings.md).
Temporary failures always allow checkout. This setting never blocks admin or
REST orders or subscription renewals. Tax lookup itself still follows the
plugin's existing error handling; an address verification failure note does not
guarantee a subsequent tax lookup succeeded.
