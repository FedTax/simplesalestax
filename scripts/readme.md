# WooCommerce Custom Scripts

This directory contains standalone WooCommerce customization scripts maintained separately from the core theme and plugin files.

These scripts are intended to be installed manually and are not auto-loaded by the repository.

---

# Installation Instructions

## Recommended Method: Install as a Custom Plugin

This approach ensures the customization remains update-safe and isolated from theme changes.

### Step 1 — Create a New Plugin File

Navigate to:

```
/wp-content/plugins/
```

Create a new file, for example:

```
custom-sst-overrides.php
```

---

### Step 2 — Add Plugin Header

At the top of the file, add:

```php
<?php
/**
 * Plugin Name: Custom SST Overrides
 * Description: Custom WooCommerce modifications related to SST integration.
 * Version: 1.0
 */
```

---

### Step 3 — Copy Script Contents

Open the required script from this `/scripts/` directory (e.g. `sst-tax-exemption-form-reposition.php`) and paste its contents below the plugin header.

---

### Step 4 — Activate the Plugin

Go to:

```
WordPress Admin → Plugins
```

Locate **Custom SST Overrides** and click **Activate**.

---

# Alternative Method (If Using a Child Theme)

If preferred, the script may be added to:

```
/wp-content/themes/your-child-theme/functions.php
```

⚠️ Do not install in a parent theme, as updates will overwrite changes.

---

# Included Script

## `sst-tax-exemption-form-reposition.php`

### Purpose

Repositions the SST Tax Exemption form so that it appears **after the Billing Details section** on the WooCommerce checkout page.

### Technical Scope

* Targets WooCommerce **classic (shortcode-based) checkout**
* Does not modify block-based checkout
* Removes the original hook:

  ```
  woocommerce_checkout_after_customer_details
  ```
* Re-attaches output to:

  ```
  woocommerce_after_checkout_billing_form
  ```

---

# Compatibility Notes

* Requires WooCommerce (classic checkout template)
* Requires SST integration plugin to be active
* Safe for production once tested in staging
* Does not modify core WooCommerce files

---

# Deployment Recommendation

We recommend:

1. Deploying to staging first
2. Verifying checkout behavior
3. Confirming tax exemption flow works as expected
4. Then promoting to production