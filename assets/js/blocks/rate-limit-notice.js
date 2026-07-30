/**
 * External dependencies
 */
import { CART_STORE_KEY } from '@woocommerce/block-data';
import { dispatch, select, subscribe } from '@wordpress/data';

const EXTENSION_NAMESPACE = 'simple-sales-tax';
const NOTICE_ID = 'simple-sales-tax-rate-limit';

let previousMessage = '';
let previousContext = '';

/**
 * Display or remove the TaxCloud rate-limit notice based on Store API data.
 */
const syncRateLimitNotice = () => {
	const cart = select( CART_STORE_KEY ).getCartData();
	const message =
		cart?.extensions?.[ EXTENSION_NAMESPACE ]?.rate_limit_notice || '';
	const context = document.body.classList.contains( 'woocommerce-checkout' )
		? 'wc/checkout'
		: 'wc/cart';

	if ( message === previousMessage && context === previousContext ) {
		return;
	}

	if ( previousMessage ) {
		dispatch( 'core/notices' ).removeNotice( NOTICE_ID, previousContext );
	}

	if ( message ) {
		dispatch( 'core/notices' ).createNotice( 'info', message, {
			id: NOTICE_ID,
			context,
			isDismissible: true,
		} );
	}

	previousMessage = message;
	previousContext = context;
};

syncRateLimitNotice();
subscribe( syncRateLimitNotice, CART_STORE_KEY );
