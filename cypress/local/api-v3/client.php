<?php
/** CLI-only boundary: Cypress supplies fixtures, the real PHP clients execute. */
if ( PHP_SAPI !== 'cli' ) {
	exit( 'CLI only.' );
}
try {
	$input = json_decode( stream_get_contents( STDIN ), true, 512, JSON_THROW_ON_ERROR );
	if ( ! in_array( $input['environment'], array( 'production', 'staging' ), true ) ) {
		throw new RuntimeException( 'Unknown URL configuration.' );
	}
	define( 'SST_TAXCLOUD_STAGING', 'staging' === $input['environment'] );
	require dirname( __DIR__, 3 ) . '/tests/api-v3/bootstrap.php';
	SST_V3_Test_Http::reset();
	foreach ( $input['settings'] ?? array() as $key => $value ) {
		SST_Settings::set( $key, $value );
	}
	if ( $input['cacheToken'] ?? true ) {
		SST_V3_Test_Http::cache_token();
	}
	foreach ( $input['responses'] as $response ) {
		$fixture = isset( $response['transportError'] )
			? new WP_Error( 'http_request_failed', $response['transportError'] )
			: SST_V3_Test_Http::raw( $response['body'], $response['status'] );
		SST_V3_Test_Http::expect( $response['method'], $response['url'], $fixture );
	}
	$operations = array(
		'calculateCarts' => array( 'Carts', 'calculate_tax' ),
		'createOrderFromCart' => array( 'Carts', 'create_order' ),
		'getCart' => array( 'Carts', 'get_cart' ),
		'createOrder' => array( 'Orders', 'create_order' ),
		'getOrder' => array( 'Orders', 'get_order' ),
		'updateOrder' => array( 'Orders', 'update_order' ),
		'refundOrder' => array( 'Refunds', 'refund_order' ),
		'createCertificate' => array( 'Exemptions', 'create_certificate' ),
		'getCertificate' => array( 'Exemptions', 'get_certificate' ),
		'disableCertificate' => array( 'Exemptions', 'delete_certificate' ),
		'listCertificates' => array( 'Exemptions', 'get_certificates' ),
		'ping' => array( 'Utilities', 'ping' ),
		'searchTics' => array( 'Utilities', 'search_tics' ),
		'verifyAddress' => array( 'Utilities', 'verify_address' ),
		'authenticate' => array( 'Utilities', 'get_auth_token' ),
	);
	if ( 'connectionSettings' === $input['operation'] ) {
		$result = call_user_func_array( array( 'TaxCloud_V3\\RequestBase', 'get_connection_settings' ), $input['args'] );
	} else {
		if ( ! isset( $operations[ $input['operation'] ] ) ) {
			throw new RuntimeException( 'Unknown client operation.' );
		}
		list( $class, $method ) = $operations[ $input['operation'] ];
		$class = 'TaxCloud_V3\\' . $class;
		$result = call_user_func_array( array( new $class(), $method ), $input['args'] );
	}
	SST_V3_Test_Http::verify();
	echo json_encode( array(
		'result' => is_wp_error( $result ) ? null : $result,
		'error' => is_wp_error( $result ) ? array( 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ) : null,
		'requests' => SST_V3_Test_Http::requests(),
		'transients' => SST_V3_Test_Http::transients(),
		'settings' => SST_Settings::$values,
		'phpVersion' => PHP_VERSION,
	), JSON_THROW_ON_ERROR );
} catch ( Throwable $error ) {
	fwrite( STDERR, $error->getMessage() . PHP_EOL );
	exit( 1 );
}
