<?php

return function ( SST_V3_Test_Suite $suite ) {
	foreach ( SST_V3_Fixtures::endpoints() as $name => $endpoint ) {
		list( $method, $path, $invoke, $error_code ) = $endpoint;

		$suite->test( $name . ': authenticates before calling only the expected V3 endpoint', function () use ( $method, $path, $invoke ) {
			SST_V3_Test_Http::expect( 'POST', SST_V3_Test_Http::auth_url(), SST_V3_Test_Http::json( array( 'access_token' => 'issued-token' ) ), function ( $args ) {
				SST_V3_Test::same( array( 'apiLoginID' => 'test-login', 'apiKey' => 'test-connection' ), SST_V3_Test::body( $args ) );
				SST_V3_Test::same( 'application/json', $args['headers']['Content-Type'] );
				SST_V3_Test::same( 30, $args['timeout'] );
			} );
			$expected = 'DELETE' === $method ? true : array( 'testResult' => 'success' );
			$response = 'DELETE' === $method ? SST_V3_Test_Http::raw( '', 204 ) : SST_V3_Test_Http::json( $expected );
			SST_V3_Test_Http::expect( $method, SST_V3_Test_Http::api_base() . $path, $response, function ( $args ) use ( $method ) {
				SST_V3_Test::same( 'Bearer issued-token', $args['headers']['Authorization'] );
				SST_V3_Test::same( 'application/json', $args['headers']['Content-Type'] );
				SST_V3_Test::same( 'SimpleSalesTax/V3-contract-tests', $args['headers']['User-Agent'] );
				SST_V3_Test::same( 30, $args['timeout'] );
				if ( 'GET' === $method || 'DELETE' === $method ) {
					SST_V3_Test::true( ! array_key_exists( 'body', $args ), 'Read/delete requests should not contain a body.' );
				}
			} );
			SST_V3_Test::same( $expected, $invoke() );
			SST_V3_Test::same( 2, count( SST_V3_Test_Http::requests() ) );
		} );

		foreach ( array( 'tc_id', 'tc_key' ) as $credential ) {
			$suite->test( $name . ': missing ' . $credential . ' fails without HTTP', function () use ( $invoke, $credential ) {
				SST_Settings::set( $credential, '' );
				SST_V3_Test::error( $invoke(), 'sst_v3_auth_error' );
				SST_V3_Test::same( array(), SST_V3_Test_Http::requests() );
			} );
		}

		$suite->test( $name . ': rejected authentication stops before Sales Tax request', function () use ( $invoke ) {
			SST_V3_Test_Http::expect( 'POST', SST_V3_Test_Http::auth_url(), SST_V3_Test_Http::json( array( 'message' => 'Invalid credentials' ), 401 ) );
			SST_V3_Test::error( $invoke(), 'sst_v3_auth_error' );
			SST_V3_Test::same( 1, count( SST_V3_Test_Http::requests() ) );
		} );

		$suite->test( $name . ': authentication timeout is returned without retry', function () use ( $invoke ) {
			$error = new WP_Error( 'http_request_failed', 'Connection timed out.' );
			SST_V3_Test_Http::expect( 'POST', SST_V3_Test_Http::auth_url(), $error );
			SST_V3_Test::same( $error, $invoke() );
			SST_V3_Test::same( 1, count( SST_V3_Test_Http::requests() ) );
		} );

		$suite->test( $name . ': endpoint timeout is returned without retry or failover', function () use ( $method, $path, $invoke ) {
			SST_V3_Test_Http::cache_token();
			$error = new WP_Error( 'http_request_failed', 'Connection timed out.' );
			SST_V3_Test_Http::expect( $method, SST_V3_Test_Http::api_base() . $path, $error );
			SST_V3_Test::same( $error, $invoke() );
			SST_V3_Test::same( 1, count( SST_V3_Test_Http::requests() ) );
		} );

		foreach ( array( 301, 400, 401, 403, 404, 422, 429, 500 ) as $status ) {
			$suite->test( $name . ': HTTP ' . $status . ' becomes an error without failover', function () use ( $method, $path, $invoke, $error_code, $status ) {
				SST_V3_Test_Http::cache_token();
				SST_V3_Test_Http::expect( $method, SST_V3_Test_Http::api_base() . $path, SST_V3_Test_Http::json( array( 'detail' => 'TaxCloud rejected this request.' ), $status ) );
				$error = SST_V3_Test::error( $invoke(), $error_code );
				SST_V3_Test::true( false !== strpos( $error->get_error_message(), 'TaxCloud rejected this request.' ) );
				SST_V3_Test::same( 1, count( SST_V3_Test_Http::requests() ) );
			} );
		}

		if ( 'DELETE' !== $method ) {
			foreach ( array( 'malformed JSON' => '{', 'empty body' => '', 'JSON null' => 'null', 'JSON scalar' => '"wrong shape"' ) as $case => $body ) {
				$suite->test( $name . ': rejects ' . $case . ' from a successful response', function () use ( $method, $path, $invoke, $error_code, $body ) {
					SST_V3_Test_Http::cache_token();
					SST_V3_Test_Http::expect( $method, SST_V3_Test_Http::api_base() . $path, SST_V3_Test_Http::raw( $body ) );
					SST_V3_Test::error( $invoke(), $error_code );
					SST_V3_Test::same( 1, count( SST_V3_Test_Http::requests() ) );
				} );
			}
		}
	}

	$suite->test( 'Auth: token is reused across V3 client instances and stored for 12 hours', function () {
		SST_V3_Test_Http::expect( 'POST', SST_V3_Test_Http::auth_url(), SST_V3_Test_Http::json( array( 'access_token' => 'shared-token', 'connection_id' => 'integration-123456' ) ) );
		SST_V3_Test::same( 'shared-token', ( new TaxCloud_V3\Carts() )->get_auth_token() );
		SST_V3_Test::same( 'shared-token', ( new TaxCloud_V3\Orders() )->get_auth_token() );
		SST_V3_Test::same( 'integration-123456', SST_Settings::get( 'tc_integration_id' ) );
		$key = 'sst_tc_v3_token_' . md5( 'test-login:test-connection' );
		SST_V3_Test::same( array( 'value' => 'shared-token', 'expiration' => 43200 ), SST_V3_Test_Http::transients()[ $key ] );
		SST_V3_Test::same( 1, count( SST_V3_Test_Http::requests() ) );
	} );

	$suite->test( 'Auth: tokens are isolated by credential pair', function () {
		SST_V3_Test_Http::cache_token( 'original-token' );
		SST_V3_Test_Http::expect( 'POST', SST_V3_Test_Http::auth_url(), SST_V3_Test_Http::json( array( 'access_token' => 'other-token' ) ), function ( $args ) {
			SST_V3_Test::same( array( 'apiLoginID' => 'other-login', 'apiKey' => 'other-key' ), SST_V3_Test::body( $args ) );
		} );
		$api = new TaxCloud_V3\Utilities();
		SST_V3_Test::same( 'other-token', $api->get_auth_token( 'other-login', 'other-key' ) );
		SST_V3_Test::same( 'other-token', $api->get_auth_token( 'other-login', 'other-key' ) );
		SST_V3_Test::same( 'original-token', $api->get_auth_token() );
		SST_V3_Test::same( 1, count( SST_V3_Test_Http::requests() ) );
	} );

	foreach ( array( 'invalid JSON' => '{', 'missing token' => '{}', 'empty token' => '{"access_token":""}' ) as $case => $body ) {
		$suite->test( 'Auth: ' . $case . ' is not cached', function () use ( $body ) {
			SST_V3_Test_Http::expect( 'POST', SST_V3_Test_Http::auth_url(), SST_V3_Test_Http::raw( $body ) );
			SST_V3_Test::error( ( new TaxCloud_V3\Carts() )->get_auth_token(), 'sst_v3_auth_error' );
			SST_V3_Test::same( array(), SST_V3_Test_Http::transients() );
		} );
	}

	foreach ( array(
		'detail wins' => array( '{"detail":"Detail error","message":"Message error","title":"Title error"}', 'Detail error' ),
		'message' => array( '{"message":"Message error"}', 'Message error' ),
		'title' => array( '{"title":"Title error"}', 'Title error' ),
		'plain text' => array( 'Gateway unavailable', 'Gateway unavailable' ),
		'empty body' => array( '', 'Unknown TaxCloud API error.' ),
	) as $case => $fixture ) {
		$suite->test( 'Errors: useful API message from ' . $case, function () use ( $fixture ) {
			SST_V3_Test_Http::cache_token();
			SST_V3_Test_Http::expect( 'GET', SST_V3_Test_Http::api_base() . '/tax/connections/test-connection/carts/cart-1', SST_V3_Test_Http::raw( $fixture[0], 500 ) );
			$error = SST_V3_Test::error( ( new TaxCloud_V3\Carts() )->get_cart( 'cart-1' ), 'sst_v3_carts_error' );
			SST_V3_Test::same( 'Failed to retrieve cart: ' . $fixture[1], $error->get_error_message() );
		} );
	}
};
