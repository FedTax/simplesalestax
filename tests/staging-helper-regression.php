<?php
/** Offline security/restoration boundary checks for the staging helper. No WordPress/API requests. */
define( 'ABSPATH', __DIR__ );
$actions = array(); $options = array(); $admin = false; $rotated = false;
function add_action( $name, $callback, ...$args ) { global $actions; $actions[ $name ] = $callback; }
function add_filter( $name, $callback, ...$args ) { global $filters; $filters[ $name ] = $callback; }
function get_option( $key, $default = false ) { global $options; return $options[ $key ] ?? $default; }
function update_option( $key, $value, ...$args ) { global $options; $options[ $key ] = $value; }
function delete_option( $key ) { global $options; unset( $options[ $key ] ); }
function current_user_can( $cap ) { global $admin; return $admin && 'manage_options' === $cap; }
function check_admin_referer( $action ) {}
function register_rest_route( $namespace, $route, $definition ) { global $registered; $registered = $definition; }
function delete_transient( $key ) { global $deleted_transients; $deleted_transients[] = $key; }
function wp_generate_password( ...$args ) { return 'new-random-generated-password'; }
function wp_set_password( $password, $id ) { global $rotated; $rotated = true; }
function wp_json_encode( $data ) { return json_encode( $data ); }
function wp_safe_redirect( $url ) { throw new RedirectHandled( $url ); }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
function absint( $value ) { return abs( (int) $value ); }
function wc_get_order( $id ) { global $order_fixture; return 745 === $id ? ( $order_fixture ?? false ) : false; }
class WP_Error { public $code; function __construct( $code, ...$args ) { $this->code = $code; } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class WP_Session_Tokens { static function get_instance( $id ) { return new self; } function destroy_all() {} }
class RedirectHandled extends Exception {}
class Request {
	private $body;
	private $headers = array();
	function __construct( $body = null ) { $this->body = $body; }
	function set_body( $body ) { $this->body = $body; }
	function set_header( $name, $value ) { $this->headers[ strtolower( $name ) ] = $value; }
	function get_json_params() {
		if ( is_string( $this->body ) ) {
			return 'application/json' === strtolower( $this->headers['content-type'] ?? '' ) ? json_decode( $this->body, true ) : null;
		}
		return $this->body;
	}
}
class WP_REST_Request extends Request { function __construct( $method = 'GET' ) {} }
function check( $truth, $message ) { if ( ! $truth ) { throw new Exception( $message ); } }
require __DIR__ . '/../cypress/staging-site/helper/sst-staging-helper.php';
// Model the WooCommerce order metadata boundary used by capture and later refunds.
class QAOrder {
    public $meta; public $saves = 0;
    function __construct( $meta = array() ) { $this->meta = $meta; }
    function get_id() { return 745; }
    function get_customer_id() { return 35; }
    function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
    function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
    function save() { ++$this->saves; }
}
$map = $filters['sst_package_order_id'];
$run_a = 'f4b5cbf0-original-run';
$options[SST_Staging_QA::OPTION] = array( 'id' => $run_a, 'active' => true );
$qa_order = new QAOrder( array( '_sst_qa_run' => $run_a ) );
check( 'sstqa-f4b5cbf0-745_0' === $map( '745_0', $qa_order, 0 ), 'Capture persists the original remote ID.' );
$options[SST_Staging_QA::OPTION]['active'] = false;
check( 'sstqa-f4b5cbf0-745_0' === $map( '745_0', $qa_order, 0 ), 'Refund after restoration retains the captured remote ID.' );
$options[SST_Staging_QA::OPTION] = array( 'id' => 'bbbbbbbb-next-run', 'active' => true );
check( 'sstqa-f4b5cbf0-745_1' === $map( '745_1', $qa_order, 1 ), 'Later run cannot change an older order identity; package suffix is preserved.' );
check( 1 === $qa_order->saves, 'Later lifecycle mapping does not rewrite order metadata.' );
$order_fixture = $qa_order;
check( 'not_owned' === SST_Staging_QA::control( new Request( array( 'runId' => 'bbbbbbbb-next-run', 'action' => 'order', 'id' => 745 ) ) )->code, 'Stable lifecycle identity does not permit another run to read an older order.' );
check( 'not_owned' === SST_Staging_QA::control( new Request( array( 'runId' => 'bbbbbbbb-next-run', 'action' => 'remoteOrder', 'id' => 745 ) ) )->code, 'Stable lifecycle identity does not expand remote-order access.' );
$options[SST_Staging_QA::OPTION] = array();
check( 'sstqa-f4b5cbf0-745_0' === $map( '745_0', $qa_order, 0 ), 'Remote identity survives removal of the current-run option.' );
check( '745_0' === $map( '745_0', new QAOrder(), 0 ), 'Ordinary customer orders remain unchanged.' );
check( '745_0' === $map( '745_0', new QAOrder( array( 'sst_order_id' => 'sstqa-f4b5cbf0-745' ) ), 0 ), 'Untagged orders cannot opt into QA routing through remote-ID metadata alone.' );
check( '745_0' === $map( '745_0', new QAOrder( array( '_sst_qa_run' => $run_a, 'sst_order_id' => 'sstqa-f4b5cbf0-999' ) ), 0 ), 'A mismatched stored order ID is never routed to another order.' );
$actions['rest_api_init']();
check( ! $registered['permission_callback'](), 'Anonymous/customer cannot control helper.' );
$admin = true; check( $registered['permission_callback'](), 'Administrator can control helper.' );
$options[SST_Staging_QA::OPTION] = array( 'id' => 'owned-run', 'active' => true, 'snapshot' => array( 'woocommerce_wootax_settings' => array( 'exists' => true, 'value' => array( 'api_version' => 'v1', 'tc_key' => 'original-secret' ) ), 'missing-original-option' => array( 'exists' => false, 'value' => null ) ), 'userIds' => array( 123 ), 'products' => array( 'virtual' => 456 ), 'pages' => array( 'cartClassic' => 789 ), 'shippingZone' => 0 );
$options['woocommerce_wootax_settings'] = array( 'api_version' => 'v3', 'tc_key' => 'test-secret' ); $options['missing-original-option'] = 'created';
check( 'run_mismatch' === SST_Staging_QA::control( new Request( array( 'runId' => 'foreign-run', 'action' => 'restore' ) ) )->code, 'Foreign run cannot restore settings.' );
check( 'not_owned' === SST_Staging_QA::control( new Request( array( 'runId' => 'owned-run', 'action' => 'order', 'id' => 55 ) ) )->code, 'Foreign order cannot be read.' );
check( 'bad_fee' === SST_Staging_QA::control( new Request( array( 'runId' => 'owned-run', 'action' => 'fee', 'fee' => 'arbitrary' ) ) )->code, 'Fee fixture values are allowlisted.' );
check( true === SST_Staging_QA::control( new Request( array( 'runId' => 'owned-run', 'action' => 'fee', 'fee' => 'positive' ) ) ), 'Fixed fee can be enabled for run-owned fixtures.' );
check( true === SST_Staging_QA::control( new Request( array( 'runId' => 'owned-run', 'action' => 'clearCustomerRate' ) ) ), 'Owned customer rate can be reset.' );
check( array( 'sst_rate_limit_user_123' ) === $deleted_transients, 'Rate reset does not clear global or unrelated customer limits.' );
check( 'unknown_action' === SST_Staging_QA::control( new Request( array( 'runId' => 'owned-run', 'action' => 'arbitrary_option_write' ) ) )->code, 'Actions are allowlisted.' );
$result = SST_Staging_QA::control( new Request( array( 'runId' => 'owned-run', 'action' => 'restore' ) ) );
check( true === $result['restored'], 'Restore succeeds.' );
check( array( 'api_version' => 'v1', 'tc_key' => 'original-secret' ) === $options['woocommerce_wootax_settings'], 'Exact original values restored.' );
check( ! isset( $options['missing-original-option'] ), 'Originally absent options restored to absence.' );
check( false === $options[SST_Staging_QA::OPTION]['active'], 'Hooks inactive after restore.' );
check( 456 === $options[SST_Staging_QA::OPTION]['products']['virtual'], 'Test resources retained.' );
check( $rotated, 'Runner credential revoked.' );
check( true === SST_Staging_QA::control( new Request( array( 'runId' => 'owned-run', 'action' => 'restore' ) ) )['restored'], 'Restore is idempotent.' );
check( 'run_inactive' === SST_Staging_QA::control( new Request( array( 'runId' => 'owned-run', 'action' => 'layout', 'layout' => 'blocks' ) ) )->code, 'Inactive run cannot change pages.' );

// Exercise the WordPress admin button wrapper, which serializes the run token as JSON.
$options[SST_Staging_QA::OPTION]['active'] = true;
$options['woocommerce_wootax_settings'] = array( 'api_version' => 'v3', 'tc_key' => 'temporary-secret' );
$options['missing-original-option'] = 'created-again';
try {
	SST_Staging_QA::restore_from_admin();
	throw new Exception( 'Admin restore did not redirect after success.' );
} catch ( RedirectHandled $redirect ) {
	check( 'https://example.test/wp-admin/tools.php?page=sst-staging-qa' === $redirect->getMessage(), 'Admin restore redirects back to helper.' );
}
check( array( 'api_version' => 'v1', 'tc_key' => 'original-secret' ) === $options['woocommerce_wootax_settings'], 'Admin restore decodes its JSON run ID and restores original values.' );
check( ! isset( $options['missing-original-option'] ), 'Admin restore returns originally absent options to absence.' );
echo "Staging helper boundary and restoration regression checks passed.\n";
