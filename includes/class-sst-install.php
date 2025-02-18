<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * SST Install.
 *
 * Handles plugin installation and upgrades.
 *
 * @author  Simple Sales Tax
 * @package SST
 * @since   5.0
 */
class SST_Install {

	/**
	 * Callbacks that need to run for each update.
	 *
	 * @var array
	 */
	private static $update_hooks = array(
		'2.6'   => array(
			'sst_update_26_remove_shipping_taxable_option',
		),
		'3.8'   => array(
			'sst_update_38_update_addresses',
		),
		'4.2'   => array(
			'sst_update_42_migrate_settings',
			'sst_update_42_migrate_order_data',
		),
		'4.5'   => array(
			'sst_update_45_remove_license_option',
		),
		'5.0'   => array(
			'sst_update_50_origin_addresses',
			'sst_update_50_category_tics',
			'sst_update_50_order_data',
		),
		'5.9'   => array(
			'sst_update_59_tic_table',
		),
		'6.0.6' => array(
			'sst_update_606_fix_duplicate_transactions',
		),
		'6.2.0' => array(
			'sst_update_620_import_origin_addresses',
		),
		'7.0.0' => array(
			'sst_update_700_delete_package_cache',
			'sst_update_700_delete_null_certificates',
			'sst_update_700_migrate_certificates',
			'sst_update_700_compress_packages',
		),
	);

	/**
	 * Background updater.
	 *
	 * @var SST_Updater
	 */
	private static $background_updater;

	/**
	 * Initialize installer.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'init_background_updater' ), 5 );
		add_action( 'init', array( __CLASS__, 'check_version' ), 5 );
		add_action( 'admin_init', array( __CLASS__, 'trigger_update' ) );
		add_action( 'admin_init', array( __CLASS__, 'trigger_rate_removal' ) );
		add_filter( 'plugin_action_links_' . SST_PLUGIN_BASENAME, array( __CLASS__, 'add_action_links' ) );
		add_filter( 'woocommerce_rate_code', array( __CLASS__, 'get_rate_code' ), 10, 2 );
		add_filter( 'woocommerce_rate_label', array( __CLASS__, 'get_rate_label' ), 10, 2 );
		add_action( 'plugins_loaded', array( __CLASS__, 'disable_wcms_order_items_hook' ), 100 );
	}

	/**
	 * Runs on plugin deactivation. Removes all admin notices.
	 */
	public static function deactivate() {
		self::remove_notices();
	}

	/**
	 * Initialize the background updater.
	 *
	 * @return SST_Updater
	 */
	public static function init_background_updater() {
		if ( ! isset( self::$background_updater ) ) {
			require_once 'class-sst-updater.php';
			self::$background_updater = new SST_Updater();
		}

		return self::$background_updater;
	}

	/**
	 * Compares the current version of the plugin against the version stored
	 * in the database and runs the installer if necessary.
	 */
	public static function check_version() {
		if ( ! defined( 'IFRAME_REQUEST' ) && get_option( 'wootax_version' ) !== SST()->version ) {
			self::install();
		}
	}

	/**
	 * Remove all SST admin notices.
	 */
	private static function remove_notices() {
		if ( ! class_exists( 'WC_Admin_Notices' ) ) {
			require WC()->plugin_path() . '/admin/class-wc-admin-notices.php';
		}

		WC_Admin_Notices::remove_notice( 'sst_update' );
	}

	/**
	 * Install Simple Sales Tax.
	 */
	public static function install() {
		// Include required classes.
		if ( ! class_exists( 'WC_Admin_Notices' ) ) {
			require WC()->plugin_path() . '/admin/class-wc-admin-notices.php';
		}

		// Install.
		self::add_roles();
		self::add_tax_rate();

		// Remove existing notices, if any.
		self::remove_notices();

		// Queue updates if needed.
		$db_version = get_option( 'wootax_version' );

		if ( false !== $db_version && version_compare( $db_version, max( array_keys( self::$update_hooks ) ), '<' ) ) {
			WC_Admin_Notices::add_custom_notice( 'sst_update', self::update_notice() );
		} else {
			update_option( 'wootax_version', SST()->version );
		}

		// Prompt user to remove rates if any are present.
		if ( 'yes' !== get_option( 'wootax_keep_rates' ) && self::has_other_rates() ) {
			$keep_url   = esc_url( admin_url( '?sst_keep_rates=yes' ) );
			$delete_url = esc_url( admin_url( '?sst_keep_rates=no' ) );
			$notice     = sprintf(
				/* translators: 1 - URL to keep found rates, 2 - URL to delete found rates */
				__(
					'Simple Sales Tax found extra rates in your tax tables. Please choose to <a href="%1$s">keep the rates</a> or <a href="%2$s">delete them</a>.',
					'simple-sales-tax'
				),
				$keep_url,
				$delete_url
			);
			WC_Admin_Notices::add_custom_notice( 'sst_rates', $notice );
		}

		// Flush rewrite rules
		flush_rewrite_rules();
	}

	/**
	 * Start update when a user clicks the "Update" button in the dashboard.
	 */
	public static function trigger_update() {
		if ( ! empty( $_GET['do_sst_update'] ) ) { // phpcs:ignore WordPress.CSRF.NonceVerification
			self::update();

			// Update notice content.
			WC_Admin_Notices::remove_notice( 'sst_update' );
			WC_Admin_Notices::add_custom_notice( 'sst_update', self::update_notice() );
		}
	}

	/**
	 * Remove rates when user clicks 'keep the rates' or 'delete them.'
	 */
	public static function trigger_rate_removal() {
		global $wpdb;

		$keep_rates = ! empty( $_GET['sst_keep_rates'] ) ? sanitize_text_field( wp_unslash( $_GET['sst_keep_rates'] ) ) : ''; // phpcs:ignore WordPress.CSRF.NonceVerification

		if ( ! empty( $keep_rates ) ) {
			if ( 'no' === $keep_rates ) {
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_id != %d",
						SST_RATE_ID
					)
				);
				// Clear tax rate cache.
				$tools_controller = new WC_REST_System_Status_Tools_Controller();
				$tools_controller->execute_tool( 'wootax_rate_tool' );
			} else {
				update_option( 'wootax_keep_rates', 'yes' );
			}
			WC_Admin_Notices::remove_notice( 'sst_rates' );
		}
	}

	/**
	 * Get content for update notice.
	 *
	 * @return string
	 */
	private static function update_notice() {
		$db_version = get_option( 'wootax_version' );

		ob_start();

		if ( version_compare( $db_version, max( array_keys( self::$update_hooks ) ), '<' ) ) {
			if ( self::$background_updater->is_updating() || ! empty( $_GET['do_sst_update'] ) ) { // phpcs:ignore WordPress.CSRF.NonceVerification
				require __DIR__ . '/admin/views/html-notice-updating.php';
			} else {
				require __DIR__ . '/admin/views/html-notice-update.php';
			}
		}

		return ob_get_clean();
	}

	/**
	 * Queue all required updates to run in the background. Ripped from
	 * WooCommerce core.
	 */
	private static function update() {
		$current_db_version = get_option( 'wootax_version' );
		$logger             = new WC_Logger();
		$update_queued      = false;

		foreach ( self::$update_hooks as $version => $update_callbacks ) {
			if ( version_compare( $current_db_version, $version, '<' ) ) {
				foreach ( $update_callbacks as $update_callback ) {
					$logger->add( 'sst_db_updates', sprintf( 'Queuing %s - %s', $version, $update_callback ) );
					self::$background_updater->push_to_queue( $update_callback );
					$update_queued = true;
				}
			}
		}

		if ( $update_queued ) {
			self::$background_updater->save()->dispatch();
		}
	}

	/**
	 * Add custom user roles.
	 */
	public static function add_roles() {
		add_role(
			'exempt-customer',
			__( 'Exempt Customer', 'simple-sales-tax' ),
			array(
				'read'         => true,
				'edit_posts'   => false,
				'delete_posts' => false,
			)
		);
	}

	/**
	 * Add plugin action links.
	 *
	 * @param array $links Existing action links for plugin.
	 *
	 * @return array
	 */
	public static function add_action_links( $links ) {
		$link_text     = __( 'Settings', 'simple-sales-tax' );
		$settings_link = '<a href="admin.php?page=wc-settings&tab=integration&section=wootax">' . $link_text . '</a>';
		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Add a tax rate so we can persist calculate tax totals after checkout.
	 */
	private static function add_tax_rate() {
		global $wpdb;

		$tax_rates_table = $wpdb->prefix . 'woocommerce_tax_rates';

		// Get existing rate, if any.
		$rate_id  = get_option( 'wootax_rate_id', 0 );
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $tax_rates_table WHERE tax_rate_id = %d;",
				$rate_id
			)
		);

		// Add or update tax rate.
		$_tax_rate = array(
			'tax_rate_country'  => 'WT',
			'tax_rate_state'    => 'RATE',
			'tax_rate'          => 0,
			'tax_rate_name'     => 'DO-NOT-REMOVE',
			'tax_rate_priority' => 0,
			'tax_rate_compound' => 1,
			'tax_rate_shipping' => 1,
			'tax_rate_order'    => 0,
			'tax_rate_class'    => 'standard',
		);

		if ( is_null( $existing ) ) {
			$wpdb->insert( $tax_rates_table, $_tax_rate );
			update_option( 'wootax_rate_id', $wpdb->insert_id );
		} else {
			$where = array( 'tax_rate_id' => $rate_id );
			$wpdb->update( $tax_rates_table, $_tax_rate, $where );
		}
	}

	/**
	 * Are any extra tax rates present in the tax tables?
	 *
	 * @return bool
	 */
	private static function has_other_rates() {
		global $wpdb;
		$rate_count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_id != %d",
				SST_RATE_ID
			)
		);

		return $rate_count > 0;
	}

	/**
	 * Return correct rate code for our tax rate (SALES-TAX).
	 *
	 * @param string $code Rate code.
	 * @param int    $key  Tax rate ID.
	 *
	 * @return string
	 */
	public static function get_rate_code( $code, $key ) {
		if ( (int) SST_RATE_ID === (int) $key ) {
			return sst_get_rate_code();
		} else {
			return $code;
		}
	}

	/**
	 * Return correct label for our tax rate ("Sales Tax").
	 *
	 * @param string $label Original label.
	 * @param int    $key   Tax rate id.
	 *
	 * @return string
	 */
	public static function get_rate_label( $label, $key ) {
		if ( (int) SST_RATE_ID === (int) $key ) {
			return sst_get_rate_label();
		} else {
			return $label;
		}
	}

	/**
	 * Disable the WC Multiple Shipping woocommerce_order_get_items hook. The
	 * hook is not needed when SST is active and leads to corruption of line
	 * item taxes.
	 */
	public static function disable_wcms_order_items_hook() {
		if ( sst_wcms_active() ) {
			remove_filter( 'woocommerce_order_get_items', array( $GLOBALS['wcms']->order, 'order_item_taxes' ), 30 );
		}
	}
}

SST_Install::init();
