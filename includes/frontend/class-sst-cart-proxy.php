<?php

/**
 * SST Cart Proxy.
 *
 * Provides a simple, backward-compatible interface to the WC Cart object.
 *
 * @author  Taxcloud
 * @package SST
 * @since   5.6
 */
class SST_Cart_Proxy {

	/**
	 * The WC_Cart object being wrapped.
	 *
	 * @var WC_Cart
	 */
	private $cart = null;

	/**
	 * Constructor.
	 *
	 * @param WC_Cart $cart WC_Cart object to wrap.
	 *
	 * @since 5.6
	 */
	public function __construct( $cart ) {
		$this->cart = $cart;
	}

	/**
	 * Get cart taxes.
	 *
	 * @return array of cart taxes.
	 * @since  5.6
	 */
	public function get_cart_taxes() {
		return wc_array_merge_recursive_numeric(
			$this->cart->get_cart_contents_taxes(),
			$this->cart->get_fee_taxes()
		);
	}

	/**
	 * Get shipping taxes.
	 *
	 * @return array of shipping taxes.
	 * @since  5.6
	 */
	public function get_shipping_taxes() {
		return $this->cart->get_shipping_taxes();
	}

	/**
	 * Set cart tax amount.
	 *
	 * @param string $value Value to set.
	 *
	 * @since 5.6
	 */
	public function set_cart_tax( $value ) {
		$this->cart->set_cart_contents_tax( $value );
	}

	/**
	 * Set shipping tax.
	 *
	 * @param string $value Value to set.
	 *
	 * @since 3.2.0
	 */
	public function set_shipping_tax( $value ) {
		$this->cart->set_shipping_tax( $value );
	}

	/**
	 * Set the tax for a particular cart item.
	 *
	 * @param mixed $key Cart item key.
	 * @param float $tax Sales tax for cart item.
	 *
	 * @since 5.0
	 */
	public function set_cart_item_tax( $key, $tax ) {
		$cart_contents = $this->cart->get_cart_contents();
		$tax_data      = $cart_contents[ $key ]['line_tax_data'];

		$tax_data['subtotal'][ SST_RATE_ID ] = $tax;
		$tax_data['total'][ SST_RATE_ID ]    = $tax;

		$cart_contents[ $key ]['line_tax_data']     = $tax_data;
		$cart_contents[ $key ]['line_subtotal_tax'] = array_sum( $tax_data['subtotal'] );
		$cart_contents[ $key ]['line_tax']          = array_sum( $tax_data['total'] );

		$this->cart->set_cart_contents( $cart_contents );
	}

	/**
	 * Set the tax for a particular fee.
	 *
	 * @param mixed $id  Fee ID.
	 * @param float $tax Sales tax for fee.
	 *
	 * @since 5.0
	 */
	public function set_fee_item_tax( $id, $tax ) {
		$fees = $this->cart->fees_api()->get_fees();

		$fees[ $id ]->tax_data[ SST_RATE_ID ] = $tax;
		$fees[ $id ]->tax                     = array_sum( $this->cart->fees[ $id ]->tax_data );

		$this->cart->fees_api()->set_fees( $fees );
	}

	/**
	 * Set the tax for a shipping package.
	 *
	 * @param mixed $key Package key.
	 * @param float $tax Sales tax for package.
	 *
	 * @since 5.6
	 */
	public function set_package_tax( $key, $tax ) {
		$this->cart->sst_shipping_taxes[ $key ] = $tax;
	}

	/**
	 * Set the tax amount for a given tax rate.
	 *
	 * @param string $tax_rate_id ID of the tax rate to set taxes for.
	 * @param float  $amount      Tax amount for tax rate.
	 *
	 * @since 5.6
	 */
	public function set_tax_amount( $tax_rate_id, $amount ) {
		$taxes                 = $this->cart->	get_cart_contents_taxes();
		$taxes[ $tax_rate_id ] = $amount;
		$this->cart->set_cart_contents_taxes( $taxes );
	}

	/**
	 * Set the shipping tax amount for a given tax rate.
	 *
	 * @param string $tax_rate_id ID of the tax rate to set shipping taxes for.
	 * @param float  $amount      Tax amount for tax rate.
	 *
	 * @since 5.6
	 */
	public function set_shipping_tax_amount( $tax_rate_id, $amount ) {
		$taxes                 = $this->cart->get_shipping_taxes();
		$taxes[ $tax_rate_id ] = $amount;
		$this->cart->set_shipping_taxes( $taxes );
	}

	/**
	 * Update tax totals based on tax arrays.
	 *
	 * @since 5.6
	 */
	public function update_tax_totals() {
		$this->set_cart_tax( WC_Tax::get_tax_total( $this->get_cart_taxes() ) );
		$this->set_shipping_tax( WC_Tax::get_tax_total( $this->get_shipping_taxes() ) );

		$cart_total_tax = wc_round_tax_total(
			$this->get_cart_contents_tax() + $this->get_shipping_tax() + $this->get_fee_tax()
		);
		$this->cart->set_total_tax( $cart_total_tax );
	}

	/**
	 * Reset package shipping taxes before running calculations.
	 *
	 * @since 5.6
	 */
	public function reset_shipping_taxes() {
		$this->cart->sst_shipping_taxes = array();
	}

	/**
	 * Forward calls to inaccessible methods to the underlying cart object.
	 *
	 * @param string $name Name of method being called.
	 * @param array  $args Parameters of method.
	 *
	 * @return mixed
	 * @since 5.6
	 */
	public function __call( $name, $args ) {
		return call_user_func_array( array( $this->cart, $name ), $args );
	}

	/**
	 * Forward read requests for inaccessible properties to the underlying cart object.
	 *
	 * @param string $name Name of property being read.
	 *
	 * @return mixed
	 * @since 5.6
	 */
	public function __get( $name ) {
		return $this->cart->$name;
	}

	/**
	 * Forward write requests for inaccessible properties to the underlying cart object.
	 *
	 * @param string $name  Name of property being written to.
	 * @param mixed  $value Value being written.
	 *
	 * @since 5.6
	 */
	public function __set( $name, $value ) {
		$this->cart->$name = $value;
	}

}
