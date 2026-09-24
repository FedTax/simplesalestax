<?php
/** Run with: php tests/api-v3/run.php [--staging] [--filter=substring] */

if ( PHP_SAPI !== 'cli' ) {
	exit( 'This test runner is CLI only.' );
}

error_reporting( E_ALL );
$staging = false;
$filter = '';
foreach ( array_slice( $argv, 1 ) as $argument ) {
	if ( '--staging' === $argument ) {
		$staging = true;
	} elseif ( 0 === strpos( $argument, '--filter=' ) ) {
		$filter = substr( $argument, 9 );
	} else {
		fwrite( STDERR, "Usage: php tests/api-v3/run.php [--staging] [--filter=substring]\n" );
		exit( 1 );
	}
}
define( 'SST_TAXCLOUD_STAGING', $staging );
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures.php';

$suite = new SST_V3_Test_Suite();
foreach ( array( 'transactions.php', 'certificates-utilities.php', 'authentication-errors.php' ) as $file ) {
	$register = require __DIR__ . '/' . $file;
	$register( $suite );
}
exit( $suite->run( $filter ) );
