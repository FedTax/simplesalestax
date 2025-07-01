<?php

/**
 * Plugin Name:          TaxCloud for WooCommerce
 * Plugin URI:           https://wordpress.org/plugins/simple-sales-tax/
 * Description:          Automate sales tax calculation, reporting, and filing with TaxCloud integration for WooCommerce.
 * Version:              8.2.5-beta.3
 * Requires at least:    5.0
 * Tested up to:         6.4
 * Requires PHP:         7.2
 * WC requires at least: 6.9.0
 * WC tested up to:      8.5.0
 * Author:               TaxCloud
 * Author URI:           https://taxcloud.com
 * GitHub Plugin URI:    https://github.com/bporcelli/simplesalestax
 * License:              GPL v2 or later
 * License URI:          http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:          simple-sales-tax
 * Domain Path:          /languages
 * Network:              false
 *
 * TaxCloud for WooCommerce is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * any later version.
 *
 * TaxCloud for WooCommerce is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with TaxCloud for WooCommerce. If not, see http://www.gnu.org/licenses/gpl-2.0.txt.
 *
 * @package SST
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require __DIR__ . '/includes/vendor/autoload.php';
require __DIR__ . '/includes/class-simplesalestax.php';

/**
 * Get the singleton SST instance.
 *
 * @return SimpleSalesTax
 * @since 4.2
 */
function SST() {
	return SimpleSalesTax::instance();
}

SST();
