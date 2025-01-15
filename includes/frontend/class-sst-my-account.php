<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * My Account.
 *
 * Responsible for outputting the interface to manage exemption certificates on the WooCommerce My Account page.
 *
 * @author  Simple Sales Tax
 * @package SST
 * @since   7.1
 */
class SST_My_Account {
	/**
	 * Singleton instance.
	 *
	 * @var SST_My_Account
	 */
	protected static $_instance = null;

	/**
	 * Singleton instance accessor.
	 *
	 * @return SST_My_Account
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * SST_My_Account constructor.
	 */
	protected function __construct() {
		if ( sst_should_show_tax_exemption_form() ) {
			add_filter( 'woocommerce_account_menu_items', array( $this, 'add_tax_exemptions_menu_item' ) );
			add_action( 'woocommerce_account_exemption-certificates_endpoint', array( $this, 'render_tax_exemptions_page' ) );
		}
	}

	/**
	 * Adds a Tax Exemptions menu item to the My Account menu.
	 *
	 * @param array $items Menu items
	 * @return array Modified menu items
	 */
	public function add_tax_exemptions_menu_item( $items ) {
		$logout_offset = array_search( 'customer-logout', array_keys( $items ) );

		if ( false === $logout_offset ) {
			$logout_offset = count( $items );
		}

		$before_logout = array_slice( $items, 0, $logout_offset, true );
		$after_logout  = array_slice( $items, $logout_offset, null, true );
		$new_item      = array(
			'exemption-certificates' => __(
				'Exemption certificates',
				'simple-sales-tax'
			),
		);

		return $before_logout + $new_item + $after_logout;
	}

	/**
	 * Renders the contents of the Tax Exemptions page.
	 */
	public function render_tax_exemptions_page() {
		wc_get_template(
			'html-my-account.php',
			array(),
			'sst/',
			SST()->path( 'includes/frontend/views/' )
		);
	}
}

SST_My_Account::instance();
