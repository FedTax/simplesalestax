<?php
/**
 * Contract coverage for the V3 exemption-certificate and utility clients.
 *
 * These tests execute the production clients against a queued HTTP transport.
 */

return function ( SST_V3_Test_Suite $suite ) {
	$inspect = function ( $body = null, $token = 'test-token' ) {
		return function ( $args ) use ( $body, $token ) {
			SST_V3_Test::same( 'Bearer ' . $token, $args['headers']['Authorization'] );
			SST_V3_Test::same( 'application/json', $args['headers']['Content-Type'] );
			SST_V3_Test::same( 30, $args['timeout'] );
			if ( null !== $body ) {
				SST_V3_Test::same( $body, SST_V3_Test::body( $args ) );
			} else {
				SST_V3_Test::true( empty( $args['body'] ), 'GET/DELETE requests must not send a JSON body.' );
			}
		};
	};

	$suite->test( 'certificates: POST creates a certificate with the complete payload', function () use ( $inspect ) {
		SST_V3_Test_Http::cache_token();
		$payload  = SST_V3_Fixtures::certificate();
		$response = array_merge( array( 'id' => 'certificate-1', 'status' => 'Active' ), $payload );
		SST_V3_Test_Http::expect(
			'POST',
			SST_V3_Test_Http::api_base() . '/tax/connections/test-connection/exemption-certificates',
			SST_V3_Test_Http::json( $response, 201 ),
			$inspect( $payload )
		);
		SST_V3_Test::same( $response, ( new \TaxCloud_V3\Exemptions() )->create_certificate( $payload ) );
	} );

	$suite->test( 'certificates: GET encodes connection and certificate IDs as path segments', function () use ( $inspect ) {
		SST_Settings::set( 'tc_key', 'connection/with ?#' );
		SST_V3_Test_Http::cache_token();
		$response = array( 'id' => 'certificate/with ?#', 'customerId' => 'customer-1', 'status' => 'Active' );
		SST_V3_Test_Http::expect(
			'GET',
			SST_V3_Test_Http::api_base() . '/tax/connections/connection%2Fwith%20%3F%23/exemption-certificates/certificate%2Fwith%20%3F%23',
			SST_V3_Test_Http::json( $response ),
			$inspect()
		);
		SST_V3_Test::same( $response, ( new \TaxCloud_V3\Exemptions() )->get_certificate( 'certificate/with ?#' ) );
	} );

	$suite->test( 'certificates: DELETE accepts the documented empty 204 response', function () use ( $inspect ) {
		SST_V3_Test_Http::cache_token();
		SST_V3_Test_Http::expect(
			'DELETE',
			SST_V3_Test_Http::api_base() . '/tax/connections/test-connection/exemption-certificates/certificate%2F1%3F%23',
			SST_V3_Test_Http::raw( '', 204 ),
			$inspect()
		);
		SST_V3_Test::same( true, ( new \TaxCloud_V3\Exemptions() )->delete_certificate( 'certificate/1?#' ) );
	} );

	$suite->test( 'certificates: GET lists certificates on the account-level route', function () use ( $inspect ) {
		SST_V3_Test_Http::cache_token();
		$response = array( 'items' => array( array( 'id' => 'certificate-1' ) ), 'nextCursor' => 'page-2' );
		SST_V3_Test_Http::expect(
			'GET',
			SST_V3_Test_Http::api_base() . '/tax/exemption-certificates',
			SST_V3_Test_Http::json( $response ),
			$inspect()
		);
		SST_V3_Test::same( $response, ( new \TaxCloud_V3\Exemptions() )->get_certificates() );
	} );

	$suite->test( 'certificates: list query values preserve reserved characters', function () use ( $inspect ) {
		SST_V3_Test_Http::cache_token();
		$query = array(
			'connectionId' => 'connection/1',
			'customerId'   => 'buyer+one&two@example.test',
			'limit'        => 25,
			'cursor'       => 'page/+?=&hash#',
		);
		$response = array( 'items' => array(), 'nextCursor' => null );
		SST_V3_Test_Http::expect(
			'GET',
			SST_V3_Test_Http::api_base() . '/tax/exemption-certificates?connectionId=connection%2F1&customerId=buyer%2Bone%26two%40example.test&limit=25&cursor=page%2F%2B%3F%3D%26hash%23',
			SST_V3_Test_Http::json( $response ),
			$inspect()
		);
		SST_V3_Test::same( $response, ( new \TaxCloud_V3\Exemptions() )->get_certificates( $query ) );
	} );

	$suite->test( 'certificates: follows all pages and requests duplicate customers only once', function () use ( $inspect ) {
		SST_V3_Test_Http::cache_token();
		$url = SST_V3_Test_Http::api_base() . '/tax/exemption-certificates?connectionId=test-connection&customerId=';
		$a   = array( 'id' => 'certificate-a', 'customerId' => 'customer-a' );
		$b   = array( 'id' => 'certificate-b', 'customerId' => 'customer-a' );
		$c   = array( 'id' => 'certificate-c', 'customerId' => 'customer-b' );
		SST_V3_Test_Http::expect( 'GET', $url . 'customer-a&limit=100', SST_V3_Test_Http::json( array( 'items' => array( $a ), 'nextCursor' => 'page-2' ) ), $inspect() );
		SST_V3_Test_Http::expect( 'GET', $url . 'customer-a&limit=100&cursor=page-2', SST_V3_Test_Http::json( array( 'items' => array( $b ), 'nextCursor' => null ) ), $inspect() );
		SST_V3_Test_Http::expect( 'GET', $url . 'customer-b&limit=100', SST_V3_Test_Http::json( array( 'items' => array( $c ) ) ), $inspect() );
		SST_V3_Test::same(
			array( $a, $b, $c ),
			( new \TaxCloud_V3\Exemptions() )->get_certificates_for_customer_ids( array( 'customer-a', '', 'customer-a', 'customer-b' ) )
		);
	} );

	$suite->test( 'certificates: list preserves false and true boolean query filters', function () use ( $inspect ) {
		SST_V3_Test_Http::cache_token();
		$query    = array( 'disabled' => false, 'ascending' => true, 'limit' => 0 );
		$response = array( 'items' => array(), 'nextCursor' => null );
		SST_V3_Test_Http::expect(
			'GET',
			SST_V3_Test_Http::api_base() . '/tax/exemption-certificates?disabled=false&ascending=true&limit=0',
			SST_V3_Test_Http::json( $response ),
			$inspect()
		);
		SST_V3_Test::same( $response, ( new \TaxCloud_V3\Exemptions() )->get_certificates( $query ) );
	} );

	$suite->test( 'certificates: repeated pagination cursor terminates without another request', function () {
		SST_V3_Test_Http::cache_token();
		$url = SST_V3_Test_Http::api_base() . '/tax/exemption-certificates?connectionId=test-connection&customerId=customer-a&limit=100';
		$a   = array( 'id' => 'certificate-a' );
		$b   = array( 'id' => 'certificate-b' );
		SST_V3_Test_Http::expect( 'GET', $url, SST_V3_Test_Http::json( array( 'items' => array( $a ), 'nextCursor' => 'repeated' ) ) );
		SST_V3_Test_Http::expect( 'GET', $url . '&cursor=repeated', SST_V3_Test_Http::json( array( 'items' => array( $b ), 'nextCursor' => 'repeated' ) ) );
		SST_V3_Test::same( array( $a, $b ), ( new \TaxCloud_V3\Exemptions() )->get_certificates_for_customer_ids( array( 'customer-a' ) ) );
	} );

	$suite->test( 'certificates: no customer IDs returns an empty result without HTTP', function () {
		$client = new \TaxCloud_V3\Exemptions();
		SST_V3_Test::same( array(), $client->get_certificates_for_customer_ids( array() ) );
		SST_V3_Test::same( array(), $client->get_certificates_for_customer_ids( array( '', null ) ) );
		SST_V3_Test::same( array(), SST_V3_Test_Http::requests() );
	} );

	$suite->test( 'certificates: creation rejects a non-array payload before HTTP', function () {
		SST_V3_Test::error( ( new \TaxCloud_V3\Exemptions() )->create_certificate( 'invalid' ), 'sst_v3_exemptions_invalid_request' );
		SST_V3_Test::same( array(), SST_V3_Test_Http::requests() );
	} );

	foreach ( array( 'address', 'customerBusinessType', 'customerId', 'customerName', 'reason', 'reasonDescription', 'states' ) as $field ) {
		$suite->test( 'certificates: creation requires ' . $field . ' before HTTP', function () use ( $field ) {
			$client = new \TaxCloud_V3\Exemptions();
			foreach ( array( null, '', array() ) as $empty ) {
				$payload           = SST_V3_Fixtures::certificate();
				$payload[ $field ] = $empty;
				SST_V3_Test::error( $client->create_certificate( $payload ), 'sst_v3_exemptions_invalid_request' );
			}
			$payload = SST_V3_Fixtures::certificate();
			unset( $payload[ $field ] );
			SST_V3_Test::error( $client->create_certificate( $payload ), 'sst_v3_exemptions_invalid_request' );
			SST_V3_Test::same( array(), SST_V3_Test_Http::requests() );
		} );
	}

	foreach ( array( 'get_certificate', 'delete_certificate' ) as $method ) {
		$suite->test( 'certificates: ' . $method . ' requires an ID before HTTP', function () use ( $method ) {
			$client = new \TaxCloud_V3\Exemptions();
			SST_V3_Test::error( $client->$method( '' ), 'sst_v3_exemptions_invalid_request' );
			SST_V3_Test::same( array(), SST_V3_Test_Http::requests() );
		} );
	}

	$suite->test( 'utilities: GET ping uses the configured connection and cached bearer token', function () use ( $inspect ) {
		SST_V3_Test_Http::cache_token();
		$response = array( 'message' => 'Success' );
		SST_V3_Test_Http::expect( 'GET', SST_V3_Test_Http::api_base() . '/tax/connections/test-connection/ping', SST_V3_Test_Http::json( $response ), $inspect() );
		SST_V3_Test::same( $response, ( new \TaxCloud_V3\Utilities() )->ping() );
	} );

	foreach ( array( null, 'explicit/connection ?#' ) as $connection ) {
		$suite->test( 'utilities: ping authenticates supplied credentials with ' . ( null === $connection ? 'the supplied key' : 'an explicit connection' ), function () use ( $inspect, $connection ) {
			// A cached token for the saved credentials must not authenticate a new pair.
			SST_V3_Test_Http::cache_token( 'saved-token' );
			SST_V3_Test_Http::expect(
				'POST',
				SST_V3_Test_Http::auth_url(),
				SST_V3_Test_Http::json( array( 'access_token' => 'override-token' ) ),
				function ( $args ) {
					SST_V3_Test::same( array( 'apiLoginID' => 'override-login', 'apiKey' => 'override/key ?#' ), SST_V3_Test::body( $args ) );
					SST_V3_Test::same( 'application/json', $args['headers']['Content-Type'] );
				}
			);
			$encoded_connection = null === $connection ? 'override%2Fkey%20%3F%23' : 'explicit%2Fconnection%20%3F%23';
			$response           = array( 'message' => 'Success' );
			SST_V3_Test_Http::expect( 'GET', SST_V3_Test_Http::api_base() . '/tax/connections/' . $encoded_connection . '/ping', SST_V3_Test_Http::json( $response ), $inspect( null, 'override-token' ) );
			SST_V3_Test::same( $response, ( new \TaxCloud_V3\Utilities() )->ping( 'override-login', 'override/key ?#', $connection ) );
		} );
	}

	$suite->test( 'utilities: POST TIC search trims the query and uses default pagination', function () use ( $inspect ) {
		SST_V3_Test_Http::cache_token();
		$response = array( 'results' => array( array( 'tic' => 10010, 'description' => 'General merchandise' ) ), 'nextCursor' => 'next-page' );
		SST_V3_Test_Http::expect( 'POST', SST_V3_Test_Http::api_base() . '/tax/tic/search', SST_V3_Test_Http::json( $response ), $inspect( array( 'query' => 'general merchandise', 'limit' => 20 ) ) );
		SST_V3_Test::same( $response, ( new \TaxCloud_V3\Utilities() )->search_tics( '  general merchandise  ' ) );
	} );

	foreach ( array( array( -1, 1 ), array( 0, 1 ), array( 100, 100 ), array( 101, 100 ) ) as $limits ) {
		$suite->test( 'utilities: TIC search bounds limit ' . $limits[0] . ' and preserves its cursor', function () use ( $inspect, $limits ) {
			SST_V3_Test_Http::cache_token();
			$response = array( 'results' => array(), 'nextCursor' => null );
			$payload  = array( 'query' => 'books', 'limit' => $limits[1], 'cursor' => 'next/+?=&' );
			SST_V3_Test_Http::expect( 'POST', SST_V3_Test_Http::api_base() . '/tax/tic/search', SST_V3_Test_Http::json( $response ), $inspect( $payload ) );
			SST_V3_Test::same( $response, ( new \TaxCloud_V3\Utilities() )->search_tics( 'books', $limits[0], 'next/+?=&' ) );
		} );
	}

	$suite->test( 'utilities: blank TIC queries fail before HTTP', function () {
		$client = new \TaxCloud_V3\Utilities();
		foreach ( array( '', '   ', "\t\n" ) as $query ) {
			SST_V3_Test::error( $client->search_tics( $query ), 'sst_v3_tic_search_invalid_request' );
		}
		SST_V3_Test::same( array(), SST_V3_Test_Http::requests() );
	} );

	$address_payload = array(
		'city'        => 'Seattle',
		'countryCode' => 'US',
		'line1'       => '123 Main St',
		'state'       => 'WA',
		'zip'         => '98101',
		'line2'       => 'Suite 2',
	);

	$suite->test( 'utilities: POST Verify Address sends the V3 address and returns the verified address', function () use ( $inspect, $address_payload ) {
		SST_V3_Test_Http::cache_token();
		$response          = $address_payload;
		$response['line1'] = '123 MAIN ST';
		$response['zip']   = '98101-1234';
		SST_V3_Test_Http::expect( 'POST', SST_V3_Test_Http::api_base() . '/tax/verify-address', SST_V3_Test_Http::json( $response ), $inspect( $address_payload ) );
		SST_V3_Test::same( $response, ( new \TaxCloud_V3\Utilities() )->verify_address( SST_V3_Fixtures::address() ) );
	} );

	$suite->test( 'utilities: Verify Address normalizes WooCommerce address keys into V3', function () use ( $inspect, $address_payload ) {
		SST_V3_Test_Http::cache_token();
		$input = array( 'address_1' => '123 Main St', 'address_2' => 'Suite 2', 'city' => 'Seattle', 'state' => 'WA', 'postcode' => '98101', 'country' => 'US', 'email' => 'omit@example.test' );
		SST_V3_Test_Http::expect( 'POST', SST_V3_Test_Http::api_base() . '/tax/verify-address', SST_V3_Test_Http::json( $address_payload ), $inspect( $address_payload ) );
		SST_V3_Test::same( $address_payload, ( new \TaxCloud_V3\Utilities() )->verify_address( $input ) );
	} );

	$suite->test( 'utilities: Verify Address accepts the V3 address model', function () use ( $inspect, $address_payload ) {
		SST_V3_Test_Http::cache_token();
		SST_V3_Test_Http::expect( 'POST', SST_V3_Test_Http::api_base() . '/tax/verify-address', SST_V3_Test_Http::json( $address_payload ), $inspect( $address_payload ) );
		$model = new \TaxCloud_V3\Model\Address( SST_V3_Fixtures::address() );
		SST_V3_Test::same( $address_payload, ( new \TaxCloud_V3\Utilities() )->verify_address( $model ) );
	} );

	$suite->test( 'utilities: Verify Address combines ZIP5/ZIP4 and defaults country without leaking input fields', function () use ( $inspect, $address_payload ) {
		SST_V3_Test_Http::cache_token();
		$input = array( 'Address1' => '123 Main St', 'City' => 'Seattle', 'State' => 'WA', 'Zip5' => '98101-', 'Zip4' => '-1234', 'ignored' => true );
		$expected        = $address_payload;
		$expected['zip'] = '98101-1234';
		unset( $expected['line2'] );
		SST_V3_Test_Http::expect( 'POST', SST_V3_Test_Http::api_base() . '/tax/verify-address', SST_V3_Test_Http::json( $expected ), $inspect( $expected ) );
		SST_V3_Test::same( $expected, ( new \TaxCloud_V3\Utilities() )->verify_address( $input ) );
	} );

	$suite->test( 'utilities: Verify Address preserves an explicitly supplied country', function () use ( $inspect, $address_payload ) {
		SST_V3_Test_Http::cache_token();
		$input                = SST_V3_Fixtures::address();
		$input['countryCode'] = 'CA';
		$expected                = $address_payload;
		$expected['countryCode'] = 'CA';
		SST_V3_Test_Http::expect( 'POST', SST_V3_Test_Http::api_base() . '/tax/verify-address', SST_V3_Test_Http::json( $expected ), $inspect( $expected ) );
		SST_V3_Test::same( $expected, ( new \TaxCloud_V3\Utilities() )->verify_address( $input ) );
	} );

	$suite->test( 'utilities: Verify Address rejects unsupported input types before HTTP', function () {
		$client = new \TaxCloud_V3\Utilities();
		foreach ( array( null, '123 Main St', new \stdClass() ) as $input ) {
			SST_V3_Test::error( $client->verify_address( $input ), 'sst_v3_verify_address_invalid_address' );
		}
		SST_V3_Test::same( array(), SST_V3_Test_Http::requests() );
	} );

	foreach ( array( 'line1', 'city', 'state', 'zip' ) as $field ) {
		$suite->test( 'utilities: Verify Address requires ' . $field . ' before HTTP', function () use ( $field ) {
			$input = SST_V3_Fixtures::address();
			unset( $input[ $field ] );
			SST_V3_Test::error( ( new \TaxCloud_V3\Utilities() )->verify_address( $input ), 'sst_v3_verify_address_invalid_address' );
			SST_V3_Test::same( array(), SST_V3_Test_Http::requests() );
		} );
	}
};
