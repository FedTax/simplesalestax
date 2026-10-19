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

## Support

If you find a bug in our plugin, [open a new issue](https://github.com/bporcelli/simplesalestax/issues/new) right here in GitHub. For other issues please [contact us](https://taxcloud.com/contact-us/).

## Contributing

* [Submit an issue](https://github.com/bporcelli/simplesalestax/issues/new) or work on a feature branch and [submit a pull request](https://github.com/bporcelli/simplesalestax/compare).
* Follow [WordPress Coding Standards](http://codex.wordpress.org/WordPress_Coding_Standards)

## Testing

See the [local testing guide](tests/README.md) for Docker setup, Cypress commands,
expected results, API coverage, cleanup, and troubleshooting.

With the local Docker test site ready, run `npm run cypress:run` for the admin
tests or `npm run cypress:plugin:v3:fixture` for the V3 plugin workflows with
controlled TaxCloud responses. Use `npm run cypress:open` or
`npm run cypress:plugin:v3:open:fixture` to watch and debug them in Cypress.
The V3 suites and PHP regression tests remain local; they do not run in GitHub Actions.
