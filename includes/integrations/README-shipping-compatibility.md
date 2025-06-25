# Shipping Compatibility Integration

This integration provides generic compatibility between Simple Sales Tax and various shipping plugins that may conflict with SST's tax calculations.

## Supported Plugins

Currently supports:
- **LTL Freight Quotes - XPO Edition**
- **Small Package Quotes - UPS Edition**
- **General Freight Quotes**

## How It Works

The integration automatically detects active shipping plugins and:
1. Recognizes their shipping method IDs
2. Assigns appropriate Taxability Information Codes (TIC)
3. Fixes shipping package structure conflicts
4. Handles timing conflicts with tax calculations

## Adding Support for New Shipping Plugins

To add support for a new shipping plugin, edit `class-sst-shipping-compatibility.php` and add a new entry to the `$supported_plugins` array:

```php
'your_plugin_key' => array(
    'name' => 'Your Plugin Name',
    'detection' => array(
        'class' => 'Your_Plugin_Class',           // Optional: Main plugin class
        'function' => 'your_plugin_init',         // Optional: Init function
        'plugin_files' => array(                  // Plugin file paths to check
            'your-plugin/your-plugin.php',
            'woocommerce-your-plugin/woocommerce-your-plugin.php'
        )
    ),
    'method_identifiers' => array(                // Shipping method ID patterns
        'your_method', 'your_shipping', 'your_plugin'
    ),
    'tic_code' => 11010,                          // TIC code for shipping (usually 11010)
    'priority' => 10                              // Hook priority
)
```

## Detection Methods

The integration uses multiple methods to detect active plugins:

1. **Class Detection**: Checks if a specific class exists
2. **Function Detection**: Checks if a specific function exists  
3. **Plugin File Detection**: Checks if specific plugin files are active

## Method Identifiers

Method identifiers are patterns used to match shipping method IDs. The integration uses `stripos()` to check if the method ID contains any of these identifiers.

## TIC Codes

Most shipping methods use TIC code **11010** (Transportation, shipping, postage, and similar charges). You can customize this per plugin if needed.

## Admin Notices

When compatible plugins are detected, an admin notice is displayed to inform users that SST is working with their shipping plugins.

## Filters

Developers can use these filters to customize behavior:

- `wootax_shipping_compatibility_enabled` - Enable/disable the integration
- `wootax_shipping_method_ids` - Add custom shipping method IDs
- `sst_shipping_tic` - Customize TIC codes for shipping methods

## Example: Adding UPS Shipping Plugin

```php
'ups_shipping' => array(
    'name' => 'UPS Shipping Pro',
    'detection' => array(
        'class' => 'UPS_Shipping_Pro',
        'plugin_files' => array(
            'ups-shipping-pro/ups-shipping-pro.php'
        )
    ),
    'method_identifiers' => array(
        'ups', 'ups_ground', 'ups_air', 'ups_express'
    ),
    'tic_code' => 11010,
    'priority' => 10
)
```

## Testing

To test if a plugin is being detected:

1. Activate the shipping plugin
2. Check the WordPress admin for the compatibility notice
3. Test tax calculations with the shipping method
4. Verify the correct TIC code is being used

## Troubleshooting

If a plugin isn't being detected:

1. Check the detection configuration (class, function, plugin files)
2. Verify the plugin is actually active
3. Check if the shipping method IDs match the identifiers
4. Test with a simple method identifier first

## Performance

The integration only loads when compatible plugins are detected, so it doesn't impact performance on sites without these plugins. 