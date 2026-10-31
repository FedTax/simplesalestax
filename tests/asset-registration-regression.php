<?php
/** Offline block-theme render-order regression. No database or HTTP requests. */
define( 'ABSPATH', __DIR__ );
$hooks = array(); $scripts = array(); $admin_request = false;
function add_action( $name, $callback, $priority = 10, ...$args ) { global $hooks; $hooks[ $name ][ $priority ][] = $callback; }
function add_filter( ...$args ) {}
function fire_hook( $name ) { global $hooks; $list = $hooks[ $name ] ?? array(); ksort( $list ); foreach ( $list as $callbacks ) { foreach ( $callbacks as $callback ) { $callback(); } } }
function is_admin() { global $admin_request; return $admin_request; }
function __( $text, ...$args ) { return $text; }
function admin_url( $path ) { return 'https://example.test/wp-admin/' . $path; }
function wp_create_nonce( ...$args ) { return 'offline-nonce'; }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, $args ); }
function wp_register_script( $handle, $src, ...$args ) { global $scripts; if ( isset( $scripts[ $handle ] ) ) { return false; } $scripts[ $handle ] = array( 'src' => $src, 'data' => array() ); return true; }
function wp_register_style( ...$args ) {}
function wp_enqueue_style( ...$args ) {}
function wp_enqueue_script( ...$args ) {} // Queueing an unknown handle does not register it in WordPress.
function wp_localize_script( $handle, $name, $data ) { global $scripts; if ( ! isset( $scripts[ $handle ] ) ) { return false; } $scripts[ $handle ]['data'][ $name ] = $data; return true; }
function SST() { return new class { public $version = 'test'; function url( $path ) { return 'https://example.test/plugin/' . $path; } function path( $path ) { return '/nonexistent-qa-template/' . $path; } }; }
class WC_Customer {
    private $id;
    function __construct( $id ) { $this->id = $id; }
    function __call( $method, $args ) { $fields = array( 'first_name' => 'Cypress', 'last_name' => 'Buyer', 'address_1' => '323 Washington Ave N', 'address_2' => '', 'country' => 'US', 'city' => 'Minneapolis', 'state' => 'MN', 'postcode' => '55401' ); return $fields[ substr( $method, strlen( 'get_billing_' ) ) ]; }
}
class SST_Certificates { static function has_cached_certificates( $id ) { return false; } }
require $argv[1] ?? __DIR__ . '/../includes/class-sst-assets.php';
require __DIR__ . '/../includes/sst-functions.php';
function check( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }

// WordPress template-canvas renders block/shortcode content before wp_head, where wp_enqueue_scripts runs.
fire_hook( 'init' );
sst_render_certificate_table( 42 );
$data = $scripts['sst-certificate-table']['data']['SST_Certificate_Table_Data'] ?? null;
check( is_array( $data ), 'Block-theme certificate render lost its localized bootstrap data before wp_enqueue_scripts.' );
check( 42 === $data['user_id'], 'The requested customer owns the localized certificate table.' );
foreach ( array( 'first_name', 'last_name', 'address_1', 'country', 'city', 'state', 'postcode' ) as $field ) { check( ! empty( $data['billing_address'][ $field ] ), 'Missing localized billing field: ' . $field ); }
check( ! empty( $data['get_certificates_nonce'] ), 'Certificate requests have their normal nonce.' );
fire_hook( 'wp_enqueue_scripts' );
check( $data === $scripts['sst-certificate-table']['data']['SST_Certificate_Table_Data'], 'Later enqueue registration must preserve render-time customer data.' );

// The existing admin path continues to register its context before an admin table is rendered.
$scripts = array(); $admin_request = true;
fire_hook( 'init' ); fire_hook( 'admin_enqueue_scripts' ); sst_render_certificate_table( 43 );
check( 43 === $scripts['sst-certificate-table']['data']['SST_Certificate_Table_Data']['user_id'], 'Admin certificate localization still works.' );
echo "Asset registration block-theme and admin localization regressions passed.\n";
