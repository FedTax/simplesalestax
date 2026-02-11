<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Advanced Dynamic Pricing Integration Worker Proxy.
 *
 * This proxy extends ADP's WcNoFilterWorker to ensure that WooCommerce
 * calculation hooks are always preserved, even in cases where ADP
 * calls calculateTotals() without appropriate flags and filters.
 *
 * @since 8.4.4
 */
class SST_ADP_Worker_Proxy extends \ADP\BaseVersion\Includes\WC\WcNoFilterWorker {
	/**
	 * Overrides calculateTotals to always include 'allow_totals_hooks' flag.
	 *
	 * @param WC_Cart $wcCart
	 * @param array $flags
	 */
	public function calculateTotals( &$wcCart, ...$flags ) {
		if ( ! in_array( self::FLAG_ALLOW_TOTALS_HOOKS, $flags ) ) {
			$flags[] = self::FLAG_ALLOW_TOTALS_HOOKS;
		}
		return parent::calculateTotals( $wcCart, ...$flags );
	}
}

/**
 * Advanced Dynamic Pricing Integration.
 *
 * Prevents ADP from removing WooCommerce calculation hooks that are required
 * for SST to function correctly.
 *
 * @author  Simple Sales Tax
 * @package SST
 * @since   8.4.4
 */
class SST_ADP {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'adp_calculate_totals_flags_for_cloned_cart_before_process', array( $this, 'inject_worker_proxy' ), 10, 5 );
		add_filter( 'adp_flags_for_final_calculate_totals', array( $this, 'add_allow_totals_hooks_flag' ) );
	}

	/**
	 * Injects a WorkerProxy into the CartProcessor using reflection.
	 *
	 * This ensures that EVERY call to calculateTotals within the processor
	 * will respect the 'allow_totals_hooks' flag, including unfiltered calls.
	 *
	 * @param array $flags
	 * @param object $worker
	 * @param bool $first
	 * @param object $clonedCart
	 * @param object $processor
	 * @return array
	 */
	public function inject_worker_proxy( $flags, $worker, $first, $clonedCart, $processor ) {
		try {
			$reflection = new ReflectionClass( $processor );
			if ( $reflection->hasProperty( 'wcNoFilterWorker' ) ) {
				$property = $reflection->getProperty( 'wcNoFilterWorker' );
				$property->setAccessible( true );
				$current_worker = $property->getValue( $processor );
				if ( ! ( $current_worker instanceof SST_ADP_Worker_Proxy ) ) {
					$property->setValue( $processor, new SST_ADP_Worker_Proxy() );
				}
			}
		} catch ( Exception $e ) {
			// Log the error
			SST_Logger::debug(
				__( 'ADP Integration Error:', 'simple-sales-tax' ),
				$e->getMessage()
			);
		}

		return $this->add_allow_totals_hooks_flag( $flags );
	}

	/**
	 * Adds the 'allow_totals_hooks' flag to ADP's calculation flags.
	 *
	 * @param array $flags ADP calculation flags.
	 * @return array
	 */
	public function add_allow_totals_hooks_flag( $flags ) {
		if ( is_array( $flags ) && ! in_array( 'allow_totals_hooks', $flags ) ) {
			$flags[] = 'allow_totals_hooks';
		}
		return $flags;
	}

}

new SST_ADP();