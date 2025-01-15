<?php

/**
 * Plugin Name:          Simple Sales Tax
 * Description:          Harness the power of TaxCloud to accurately calculate sales tax for your WooCommerce store.
 * Author:               TaxCloud
 * Author URI:           https://taxcloud.com
 * GitHub Plugin URI:    https://github.com/bporcelli/simplesalestax
 * Version:              8.3.0
 * Text Domain:          simple-sales-tax
 * Domain Path:          /languages/
 *
 * Requires at least:    4.5.0
 * Tested up to:         6.6.0
 * WC requires at least: 6.9.0
 * WC tested up to:      9.3.1
 *
 * @category             Plugin
 * @copyright            Copyright © 2024 The Federal Tax Authority, LLC
 * @author               Taxcloud
 * @license              GPL2
 *
 * Simple Sales Tax is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by the
 * Free Software Foundation, either version 2 of the License, or any later
 * version.
 *
 * Simple Sales Tax is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * Simple Sales Tax. If not, see http://www.gnu.org/licenses/gpl-2.0.txt.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
spl_autoload_register(function ($class) {
    // Base directory for the namespace prefix
    $baseDir = __DIR__ . '/includes/TaxCloud/';

    // Project-specific namespace prefix
    $prefix = 'TaxCloud\\';

    // Does the class use the namespace prefix?
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        // If not, move to the next registered autoloader
        return;
    }

    // Get the relative class name
    $relativeClass = substr($class, $len);

    // Replace namespace separators with directory separators in the relative class name
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    // If the file exists, require it
    if (file_exists($file)) {
        require $file;
    }
});
require __DIR__ . '/includes/class-simplesalestax.php';

/**
 * Get the singleton SST instance.
 *vendor
 * @return SimpleSalesTax
 * @since 4.2
 */
function SST() {
	return SimpleSalesTax::instance();
}

SST();
