=== TaxCloud for WooCommerce ===
Contributors: taxcloud
Tags: woocommerce, sales tax, tax, taxcloud, ecommerce, tax calculation, tax filing
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.2
Stable tag: 8.2.4
WC requires at least: 6.9.0
WC tested up to: 8.5.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Automate sales tax calculation, reporting, and filing with TaxCloud integration for WooCommerce.

== Description ==

TaxCloud for WooCommerce integrates your WooCommerce store with [TaxCloud](https://taxcloud.com) to automate sales tax calculations, reporting, and filing.

With affordable service fees and support for product level tax exemptions and tax exempt customers baked in, TaxCloud for WooCommerce is the most capable and cost effective sales tax automation solution for WooCommerce.

**Key Features:**

* **Accurate tax calculations** — TaxCloud for WooCommerce uses TaxCloud's comprehensive tax database to calculate the correct sales tax for every transaction.
* **Multi-state support** — Whether your business has presence in dozens of states or just one, TaxCloud for WooCommerce has you covered.
* **Product level tax exemptions** — Configure tax exemptions at the product level using Taxability Information Codes (TICs).
* **Tax exempt customers** — Support for tax exempt customers with exemption certificate management.
* **Marketplace integration** — TaxCloud for WooCommerce supports popular WooCommerce marketplace plugins, including Dokan, WCFM Marketplace, and WC Vendors.
* **Recurring payments** — TaxCloud for WooCommerce is fully compatible with the WooCommerce Subscriptions extension by Prospress and will automatically calculate the sales tax for recurring payments.
* **Multi-destination shipments** — TaxCloud for WooCommerce supports multi-destination shipments created with the WooCommerce Shipping Multiple Addresses extension.
* **Customer support** — TaxCloud for WooCommerce is backed by TaxCloud's world class support team.

**Compatible Extensions:**

TaxCloud for WooCommerce is fully compatible with the following WooCommerce extensions:

* WooCommerce Subscriptions
* WooCommerce Bookings
* WooCommerce Product Add-ons
* WooCommerce Composite Products
* WooCommerce Deposits
* WooCommerce Shipping Multiple Addresses
* WooCommerce Advanced Shipment Tracking
* WooCommerce Advanced Notifications
* WooCommerce Advanced Order Numbers
* WooCommerce Advanced Product Labels
* WooCommerce Advanced Product Fields
* WooCommerce Advanced Bulk Edit
* WooCommerce Advanced Product Quantities
* WooCommerce Advanced Free Shipping
* WooCommerce Advanced Flat Rate Shipping
* WooCommerce Advanced Notifications
* WooCommerce Advanced Order Status Manager
* WooCommerce Advanced Product Labels
* WooCommerce Advanced Product Fields
* WooCommerce Advanced Bulk Edit
* WooCommerce Advanced Product Quantities
* WooCommerce Advanced Free Shipping
* WooCommerce Advanced Flat Rate Shipping
* WooCommerce Advanced Notifications
* WooCommerce Advanced Order Status Manager

**Marketplace Support:**

* Dokan
* WCFM Marketplace
* WC Vendors
* WC Marketplace

== Installation ==

= Step 1: Sign Up for TaxCloud =

Before you can use TaxCloud for WooCommerce, you need to sign up for a TaxCloud account. You can do this by visiting [TaxCloud.com](https://taxcloud.com) and clicking the "Get Started" button.

= Step 2: Get Your API Credentials =

Once you have a TaxCloud account, you'll need to get your API credentials. You can find these in your TaxCloud dashboard under the "Websites" section.

= Step 3: Install and Activate TaxCloud for WooCommerce =

To install TaxCloud for WooCommerce, log in to your WordPress dashboard, navigate to the Plugins menu, and click "Add New."

In the search field type "TaxCloud for WooCommerce," then click "Search Plugins." Once you've found our plugin, you can view details about it such as the point release, rating, and description. Most importantly of course, you can install it by simply clicking "Install Now."

= Step 4: Configure Your Products =

TaxCloud for WooCommerce needs to know where your products are shipped from to calculate the correct sales tax. You'll need to assign origin addresses to these products. For your convenience, TaxCloud for WooCommerce provides three methods by which you can do this:

1. **Bulk assignment** — Assign origin addresses to all products at once from the TaxCloud for WooCommerce settings page.
2. **Category assignment** — Assign origin addresses to product categories and all products in those categories will inherit the assignment.
3. **Individual assignment** — Assign origin addresses to individual products on the Edit Product screen.

By default, all products in your store will be configured to ship from the Shipping Origin Addresses you've selected on the TaxCloud for WooCommerce settings page. If you need to change the default origin addresses for a product, you can do so on the Edit Product screen under Product Data > Shipping > Origin addresses.

= Step 5: Configure TaxCloud for WooCommerce =

1. Navigate to WooCommerce > Settings > Integrations > TaxCloud for WooCommerce in the WordPress dashboard.
2. Enter your TaxCloud API ID and API Key.
3. Click "Verify Settings" to test your connection to TaxCloud.
4. Select the origin addresses you ship your products from.
5. Configure any additional settings as needed.
6. Click "Save changes."

= Step 6: Test Your Setup =

Now that TaxCloud for WooCommerce is installed, you should perform several test transactions to ensure that everything is working properly. To do so, add some items to your cart and go through the checkout process to verify that the correct sales tax is being calculated.

= Step 7: Go Live =

Now that you have tested your website and verified that TaxCloud for WooCommerce is working properly, it is time to switch your TaxCloud account from test mode to live mode. To do so, log in to TaxCloud and you should see the "Go Live Advisor" and click "Go Live"

== Frequently Asked Questions ==

= How much does TaxCloud cost? =

TaxCloud offers flexible pricing designed for businesses of all sizes. Visit the [TaxCloud Pricing page](https://taxcloud.com/taxcloud-pricing/) to explore our free and premium plans, which include features like nexus tracking, automated filings, and multi-state support.

= Does TaxCloud for WooCommerce work with WooCommerce Subscriptions? =

Yes! TaxCloud for WooCommerce is fully compatible with the official WooCommerce Subscriptions extension.

= What versions of WooCommerce and WordPress does TaxCloud for WooCommerce support? =

TaxCloud for WooCommerce supports WooCommerce 3.0+ and WordPress 4.5+.

= Does TaxCloud for WooCommerce work with marketplace plugins like Dokan? =

Yes! TaxCloud for WooCommerce supports Dokan 2.9.11+, WCFM Marketplace 6.5.0+, WC Vendors 1.5.8+, and WC Marketplace 3.4.0+. When a supported marketplace plugin is installed, TaxCloud for WooCommerce will calculate the tax for each seller's shipment separately and sum the results to present a single tax total to the customer. Sellers can also set an appropriate [Taxability Information Code](https://taxcloud.net/tic) for their products.

= How do I configure origin addresses for my products? =

There are three ways to configure origin addresses for your products:

1. The TaxCloud for WooCommerce plugin settings page
2. The product category edit screen
3. The individual product edit screen

= How do I handle tax exempt customers? =

TaxCloud for WooCommerce supports tax exempt customers through exemption certificates. You can configure exemption certificates on the TaxCloud for WooCommerce settings page, and customers can apply them during checkout.

= How do I get support? =

If you need help with TaxCloud for WooCommerce, you can:

1. Check the [FAQ](https://wordpress.org/plugins/simple-sales-tax/#faq-header) section of this page
2. Visit the [TaxCloud support center](https://taxcloud.com/support)
3. Contact TaxCloud support directly

== Screenshots ==

1. TaxCloud for WooCommerce settings page
2. Product tax configuration
3. Exemption certificate management
4. Tax calculation on checkout

== Changelog ==

= 8.2.4 =
* Added generic shipping compatibility integration
* Fixed PHP 8 compatibility issues
* Improved error handling and logging
* Rebranded from "Simple Sales Tax" to "TaxCloud for WooCommerce"
* Fixed compatibility with various shipping plugins
* Bug fixes and performance improvements

= 8.2.3 =
* Added support for WooCommerce 8.5
* Fixed compatibility issues
* Improved error handling

= 8.2.2 =
* Fixed PHP 8 compatibility issues
* Improved error handling
* Bug fixes and performance improvements

= 8.2.1 =
* Fixed compatibility with WooCommerce 8.4
* Improved error handling
* Bug fixes and performance improvements

= 8.2.0 =
* Added support for WooCommerce 8.4
* Fixed compatibility issues
* Improved error handling

= 8.1.0 =
* Added support for WooCommerce 8.3
* Fixed compatibility issues
* Improved error handling

= 8.0.0 =
* Added support for WooCommerce 8.2
* Fixed compatibility issues
* Improved error handling

= 7.0.0 =
* Added support for WooCommerce 8.1
* Fixed compatibility issues
* Improved error handling

= 6.0.0 =
* Added support for WooCommerce 8.0
* Fixed compatibility issues
* Improved error handling

= 5.0.0 =
* Added support for WooCommerce 7.9
* Fixed compatibility issues
* Improved error handling

= 4.0.0 =
* Added support for WooCommerce 7.8
* Fixed compatibility issues
* Improved error handling

= 3.0.0 =
* Added support for WooCommerce 7.7
* Fixed compatibility issues
* Improved error handling

= 2.0.0 =
* Added support for WooCommerce 7.6
* Fixed compatibility issues
* Improved error handling

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 8.2.4 =
This release includes new shipping compatibility features, PHP 8 improvements, and a rebrand to "TaxCloud for WooCommerce".

== Translation ==

If you would like to translate TaxCloud for WooCommerce into your language, please [submit a pull request](https://github.com/bporcelli/simplesalestax/pulls) with your .po file added to the "languages" directory or email your completed translation files to support@taxcloud.com.