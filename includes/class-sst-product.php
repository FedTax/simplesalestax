<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * SST Product.
 *
 * Contains methods for working with products.
 *
 * @author  Simple Sales Tax
 * @package SST
 * @since   5.0
 */
class SST_Product {

	/**
	 * Register action hooks.
	 *
	 * @since 5.0
	 */
	public static function init() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'woocommerce_product_options_shipping', array( __CLASS__, 'output_origin_select_box' ) );
		add_action( 'woocommerce_product_bulk_edit_start', array( __CLASS__, 'output_bulk_edit_fields' ) );
		add_action( 'woocommerce_product_bulk_edit_save', array( __CLASS__, 'save_bulk_edit_fields' ) );
		add_action( 'woocommerce_product_options_tax', array( __CLASS__, 'display_tic_field' ) );
		add_action( 'woocommerce_product_after_variable_attributes', array( __CLASS__, 'display_tic_field' ), 10, 3 );
		add_action( 'woocommerce_ajax_save_product_variations', array( __CLASS__, 'ajax_save_variation_tics' ) );
		add_action( 'save_post_product', array( __CLASS__, 'save_product_meta' ) );
	}

	/**
	 * Returns an array of origin addresses for a given product. If no origin
	 * addresses have been configured, returns set of default origin addresses.
	 *
	 * @param int $product_id WooCommerce product ID.
	 *
	 * @return SST_Origin_Address[]
	 * @since 5.0
	 */
	public static function get_origin_addresses( $product_id ) {
		$raw_addresses = get_post_meta( $product_id, '_wootax_origin_addresses', true );

		if ( ! is_array( $raw_addresses ) || empty( $raw_addresses ) ) {
			return SST_Addresses::get_default_addresses();
		}

		$addresses = SST_Addresses::get_origin_addresses();
		$return    = array();

		foreach ( $raw_addresses as $address_id ) {
			if ( isset( $addresses[ $address_id ] ) ) {
				$return[ $address_id ] = $addresses[ $address_id ];
			}
		}

		return $return;
	}

	/**
	 * Get the TIC for a product.
	 *
	 * @param int $product_id   WooCommerce product ID.
	 * @param int $variation_id WooCommerce product variation ID (default: 0).
	 *
	 * @return mixed TIC for product, or null if none set.
	 * @since 5.0
	 */
	public static function get_tic( $product_id, $variation_id = 0 ) {
		$product_tic   = get_post_meta( $product_id, 'wootax_tic', true );
		$variation_tic = ! $variation_id ? false : get_post_meta( $variation_id, 'wootax_tic', true );
		$tic           = $variation_tic ? $variation_tic : $product_tic;

		/* Fall back to default TIC for product category */
		if ( empty( $tic ) ) {
			$categories = get_the_terms( $product_id, 'product_cat' );

			if ( ! is_wp_error( $categories ) && $categories ) {
				foreach ( $categories as $category ) {
					$cat_tic = get_term_meta( $category->term_id, 'tic', true );
					if ( ! empty( $cat_tic ) ) {
						$tic = $cat_tic;
						break;
					}
				}
			}
		}

		/* Let devs adjust TIC */
		$final_tic = apply_filters( 'wootax_product_tic', $tic, $product_id, $variation_id );

		return empty( $final_tic ) ? null : $final_tic;
	}

	/**
	 * Output "Shipping Origin Addresses" select box.
	 *
	 * @since 4.6
	 */
	public static function output_origin_select_box() {
		global $post;

		$addresses = SST_Addresses::get_origin_addresses();

		// Do not display if there is less than 2 origin addresses to select from.
		$show_dropdown = is_array( $addresses ) && count( $addresses ) >= 2;

		if ( apply_filters( 'sst_show_origin_address_dropdown', $show_dropdown ) ) {
			include __DIR__ . '/admin/views/html-origin-select.php';
		}
	}

	/**
	 * Output bulk edit fields.
	 *
	 * @since 5.0
	 */
	public static function output_bulk_edit_fields() {
		$field_args = array(
			'default_text' => __( 'No Change', 'simple-sales-tax' ),
		);

		?>
		<label class="alignleft">
			<span class="title"><?php esc_html_e( 'TIC', 'simple-sales-tax' ); ?></span>
			<span class="input-text-wrap">
				<?php sst_output_tic_select_field( $field_args ); ?>
			</span>
		</label>
		<?php
	}

	/**
	 * Handle bulk TIC updates.
	 *
	 * @param WC_Product $product The product being saved.
	 *
	 * @since 4.2
	 */
	public static function save_bulk_edit_fields( $product ) {
		$tic = '';

		if ( isset( $_REQUEST['wootax_tic'] ) ) {
			$tic = sanitize_text_field( wp_unslash( $_REQUEST['wootax_tic'] ) ); // phpcs:ignore WordPress.CSRF.NonceVerification
		}

		if ( '' !== $tic ) {
			update_post_meta( $product->get_id(), 'wootax_tic', $tic );
		}
	}

	/**
	 * Output TIC select box on "Edit Product" screen.
	 *
	 * @param int     $loop           Loop counter used by WooCommerce when displaying variation attributes (default:
	 *                                null).
	 * @param array   $variation_data Unused parameter (default: null).
	 * @param WP_Post $variation      WP_Post for variation if variation is being displayed (default: null).
	 *
	 * @since 5.0
	 */
	public static function display_tic_field( $loop = null, $variation_data = null, $variation = null ) {
		global $post;

		$is_variation = ! empty( $variation );

		if ( $is_variation ) {
			$product_id = $variation->ID;
			$class      = 'form-row form-field form-row-full';
		} else {
			$product_id = $post->ID;
			$class      = 'form-field';
		}

		?>
		<p class="<?php echo esc_attr( $class ); ?> wootax_tic">
			<label for="wootax_tic[<?php echo esc_attr( $product_id ); ?>]">
				<?php esc_html_e( 'TIC', 'simple-sales-tax' ); ?>
			</label>
			<?php if ( $is_variation ): ?>
				<br>
			<?php endif; ?>
			<?php sst_output_tic_select_field( compact( 'product_id' ) ); ?>
		</p>
		<?php
	}

	/**
	 * Update variation TICs.
	 *
	 * @since 4.6
	 */
	public static function ajax_save_variation_tics() {
		if ( ! isset( $_POST['variable_post_id'], $_POST['wootax_tic'] ) ) { // phpcs:ignore WordPress.CSRF.NonceVerification
			return;
		}

		$variable_post_id = array_map( 'absint', $_POST['variable_post_id'] ); // phpcs:ignore WordPress.CSRF.NonceVerification
		$tic_selections   = array_map( 'sanitize_text_field', wp_unslash( $_POST['wootax_tic'] ) ); // phpcs:ignore WordPress.CSRF.NonceVerification
		$max_loop         = max( array_keys( $variable_post_id ) );

		for ( $i = 0; $i <= $max_loop; $i++ ) {
			if ( ! isset( $variable_post_id[ $i ] ) ) {
				continue;
			}

			$variation_id = $variable_post_id[ $i ];

			if ( isset( $tic_selections[ $variation_id ] ) ) {
				update_post_meta( $variation_id, 'wootax_tic', $tic_selections[ $variation_id ] );
			}
		}
	}

	/**
	 * Save the TIC and origin addresses for a product.
	 *
	 * @param int $product_id The ID of the product being saved.
	 *
	 * @since 4.2
	 */
	public static function save_product_meta( $product_id ) {
		if ( isset( $_REQUEST['_inline_edit'] ) || isset( $_REQUEST['bulk_edit'] ) ) { // phpcs:ignore WordPress.CSRF.NonceVerification
			return;
		}

		// Save product origin addresses.
		$origins = array();

		if ( isset( $_REQUEST['_wootax_origin_addresses'] ) ) { // phpcs:ignore WordPress.CSRF.NonceVerification
			$origins = array_map( 'sanitize_title', wp_unslash( $_REQUEST['_wootax_origin_addresses'] ) ); // phpcs:ignore WordPress.CSRF.NonceVerification
		}

		update_post_meta( $product_id, '_wootax_origin_addresses', $origins );

		// Save product and variation TICs.
		$selected_tics = array();

		if ( isset( $_REQUEST['wootax_tic'] ) ) { // phpcs:ignore WordPress.CSRF.NonceVerification
			$selected_tics = array_map( 'sanitize_text_field', wp_unslash( $_REQUEST['wootax_tic'] ) ); // phpcs:ignore WordPress.CSRF.NonceVerification
		}

		foreach ( $selected_tics as $product_id => $tic ) {
			update_post_meta( $product_id, 'wootax_tic', $tic );
		}
	}

}

SST_Product::init();
