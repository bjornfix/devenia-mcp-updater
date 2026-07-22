<?php
/**
 * Standalone contract for exact manifest/update-offer identity approval.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );

final class WP_Error {
	public function __construct( public string $code, public string $message = '' ) {}
	public function get_error_message(): string { return $this->message; }
}

$GLOBALS['fixture_manifest'] = array();
$GLOBALS['fixture_updates']  = (object) array( 'response' => array() );

function add_action( ...$args ): void { unset( $args ); }
function add_filter( ...$args ): void { unset( $args ); }
function register_activation_hook( ...$args ): void { unset( $args ); }
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function sanitize_text_field( $value ): string { return trim( (string) $value ); }
function esc_url_raw( $value ): string { return (string) $value; }
function wp_parse_url( string $url, int $component ) { return parse_url( $url, $component ); }
function wp_remote_get( string $url, array $args = array() ): array {
	unset( $url, $args );
	return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $GLOBALS['fixture_manifest'] ) );
}
function wp_remote_retrieve_response_code( array $response ): int { return (int) $response['response']['code']; }
function wp_remote_retrieve_body( array $response ): string { return (string) $response['body']; }
function get_site_transient( string $key ) {
	return 'update_plugins' === $key ? $GLOBALS['fixture_updates'] : false;
}
function set_site_transient( ...$args ): void { unset( $args ); }
function update_option( ...$args ): void { unset( $args ); }
function wp_clean_plugins_cache( ...$args ): void { unset( $args ); }
function wp_update_plugins(): void {}

require_once dirname( __DIR__ ) . '/devenia-mcp-updater.php';

$plugin_file = 'mcp-expose-abilities/mcp-expose-abilities.php';
$package     = 'https://downloads.devenia.com/mcp-expose-abilities.zip';
$version     = '3.0.78';
$sha256      = str_repeat( 'a', 64 );
$GLOBALS['fixture_manifest'] = array(
	'plugins' => array(
		array(
			'file'        => $plugin_file,
			'version'     => $version,
			'package'     => $package,
			'sha256'      => $sha256,
			'autoUpdate'  => true,
			'pluginCheck' => array( 'status' => 'passed', 'sha256' => $sha256 ),
		),
	),
);
$GLOBALS['fixture_updates'] = (object) array(
	'response' => array( $plugin_file => (object) array( 'package' => $package, 'new_version' => $version ) ),
);

if ( true !== devenia_mcp_updater_allow_mcp_expose_plugin_update( false, $plugin_file ) ) {
	throw new RuntimeException( 'An exact manifest and WordPress update-offer identity was not approved.' );
}

$GLOBALS['fixture_updates']->response[ $plugin_file ]->package = 'https://downloads.devenia.com/other.zip';
if ( false !== devenia_mcp_updater_allow_mcp_expose_plugin_update( false, $plugin_file ) ) {
	throw new RuntimeException( 'A package mismatch was approved.' );
}

$GLOBALS['fixture_updates']->response[ $plugin_file ] = (object) array( 'package' => $package, 'new_version' => '3.0.77' );
if ( false !== devenia_mcp_updater_allow_mcp_expose_plugin_update( false, $plugin_file ) ) {
	throw new RuntimeException( 'A version mismatch was approved.' );
}

if ( false !== devenia_mcp_updater_allow_mcp_expose_plugin_update( false, 'other/other.php' ) ) {
	throw new RuntimeException( 'A plugin-file mismatch was approved.' );
}

$GLOBALS['fixture_manifest']['plugins'][0]['autoUpdate'] = false;
if ( false !== devenia_mcp_updater_allow_mcp_expose_plugin_update( false, $plugin_file ) ) {
	throw new RuntimeException( 'A manifest entry without auto-update approval was approved.' );
}

fwrite( STDOUT, "Exact manifest/update-offer identity runtime passed.\n" );
