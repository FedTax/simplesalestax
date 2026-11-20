<?php
/** Credential readiness for isolated tests that replace the WordPress settings store. */
trait SST_Test_Credential_Settings {
	public static function has_api_credentials() {
		return ! empty( self::get( 'tc_key' ) ) && (
			! empty( self::get( 'tc_id' ) ) || ( 'v3' === self::get( 'api_version' ) && ! empty( self::get( 'tc_v3_api_key' ) ) )
		);
	}
}
