<?php
/** Build an installable ZIP containing only the reviewed helper source. */
if ( ! class_exists( 'ZipArchive' ) || 3 !== $argc ) { fwrite( STDERR, "Usage: php package-helper.php SOURCE OUTPUT.zip (requires ZipArchive).\n" ); exit( 1 ); }
$zip = new ZipArchive();
if ( true !== $zip->open( $argv[2], ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) { fwrite( STDERR, "Cannot create helper ZIP.\n" ); exit( 1 ); }
if ( ! $zip->addFile( $argv[1], 'sst-staging-helper/sst-staging-helper.php' ) || ! $zip->close() ) { fwrite( STDERR, "Cannot finish helper ZIP.\n" ); exit( 1 ); }
echo $argv[2] . "\n";
