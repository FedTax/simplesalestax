<?php

/**
 * Plugin Name:          TaxCloud for WooCommerce (formerly Simple Sales Tax)
 * Plugin Slug:          simple-sales-tax
 * Description:          Harness the power of TaxCloud to accurately calculate sales tax for your WooCommerce store.
 * Author:               TaxCloud
 * Author URI:           https://taxcloud.com
 * GitHub Plugin URI:    https://github.com/bporcelli/simplesalestax
 * Version:              8.4.7
 * Text Domain:          simple-sales-tax
 * Domain Path:          /languages/
 * License:              GPLv2 or later
 *
 * Requires at least:    4.5.0
 * Tested up to:         6.9.1
 * WC requires at least: 6.9.0
 * WC tested up to:      10.5.2
 * Requires PHP:         7.4
 *
 * @category             Plugin
 * @copyright            Copyright © 2026 The Federal Tax Authority, LLC
 * @author               Brett Porcelli
 * @license              GPL2
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
