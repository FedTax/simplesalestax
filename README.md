# WooCommerce Sales Tax Automation by TaxCloud

Simple Sales Tax connects your WooCommerce store to [TaxCloud](https://taxcloud.com) to automate sales tax calculation, filing, and remittance.

For more information, please check our [plugin page](https://wordpress.org/plugins/simple-sales-tax/) on WordPress.org or contact [TaxCloud Customer Service](https://support.taxcloud.com) at service@taxcloud.net.

## Requirements

- WordPress 4.5+
- WooCommerce 6.9+
- [TaxCloud account](https://app.taxcloud.com/register)

## Getting Started

1. Download the Simple Sales Tax .zip file from [WordPress.org](https://wordpress.org/plugins/simple-sales-tax/).
2. Go to **WordPress Admin > Plugins > Add New** and **Upload Plugin** with the zip file.
3. **Install Now** and **Activate** the plugin.
4. Follow the instructions in our [Setup Guide](https://wordpress.org/plugins/simple-sales-tax/#installation)

## FooSales in-store orders

FooSales calculates tax in its apps before payment using the WooCommerce tax
rates synced to the device. Configure the storefront's rates and the appropriate
product tax classes under **WooCommerce > Settings > Tax**, and keep those rates
when TaxCloud detects them. For a physical storefront, select **Shop base address**
as WooCommerce's tax basis, then reset/sync FooSales store data after changes.
TaxCloud's online checkout continues to use the customer's destination address.

In real-time mode, TaxCloud excludes matching WooCommerce rates from US cart and
checkout calculations so retaining POS rates does not charge tax twice online.
Manual rates remain available to FooSales, non-US tax calculations, and Data
Import mode. The FooSales apps do not request live TaxCloud cart quotes; their
configured rates and tax classes must be maintained for the products sold.

FooSales orders retain the taxes collected by the POS. TaxCloud reporting uses
the WooCommerce storefront as origin and destination for orders without shipping
lines, including guest sales without billing or shipping addresses. Orders with
shipping lines retain their normal delivery destinations.

## Support

If you find a bug in our plugin, [open a new issue](https://github.com/bporcelli/simplesalestax/issues/new) right here in GitHub. For other issues please [contact us](https://taxcloud.com/contact-us/).

## Contributing

* [Submit an issue](https://github.com/bporcelli/simplesalestax/issues/new) or work on a feature branch and [submit a pull request](https://github.com/bporcelli/simplesalestax/compare).
* Follow [WordPress Coding Standards](http://codex.wordpress.org/WordPress_Coding_Standards)
