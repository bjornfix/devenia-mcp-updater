<?php
/**
 * Standalone contract for exact manifest/update-offer identity approval.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['fixture_signing_keypair'] = sodium_crypto_sign_keypair();
define( 'DEVENIA_MCP_UPDATER_MANIFEST_PUBLIC_KEY', base64_encode( sodium_crypto_sign_publickey( $GLOBALS['fixture_signing_keypair'] ) ) );
define( 'DEVENIA_MCP_UPDATER_MANIFEST_KEY_ID', 'fixture' );

final class WP_Error {
	public function __construct( public string $code, public string $message = '' ) {}
	public function get_error_message(): string { return $this->message; }
}

$GLOBALS['fixture_manifest'] = array();
$GLOBALS['fixture_updates']  = (object) array( 'response' => array() );
$GLOBALS['fixture_options']  = array();
$GLOBALS['fixture_installed'] = array();

function add_action( ...$args ): void { unset( $args ); }
function add_filter( ...$args ): void { unset( $args ); }
function register_activation_hook( ...$args ): void { unset( $args ); }
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function sanitize_text_field( $value ): string { return trim( (string) $value ); }
function sanitize_key( $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function esc_url_raw( $value ): string { return (string) $value; }
function wp_json_encode( $value, int $flags = 0 ): string { return (string) json_encode( $value, $flags ); }
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
function update_option( $key, $value, ...$args ): bool { unset( $args ); $GLOBALS['fixture_options'][ $key ] = $value; return true; }
function get_option( $key, $default = false ) { return $GLOBALS['fixture_options'][ $key ] ?? $default; }
function wp_clean_plugins_cache( ...$args ): void { unset( $args ); }
function wp_update_plugins(): void {}
function get_plugins(): array { return $GLOBALS['fixture_installed']; }
function is_plugin_active( string $plugin ): bool { return isset( $GLOBALS['fixture_installed'][ $plugin ] ); }
function activate_plugin( ...$args ): void { unset( $args ); }
function delete_plugins( ...$args ): bool { unset( $args ); return true; }
function home_url( string $path = '/' ): string { return 'https://devenia.com' . $path; }
function add_query_arg( string $key, string $value, string $url ): string { return $url . '?' . rawurlencode( $key ) . '=' . rawurlencode( $value ); }

require_once dirname( __DIR__ ) . '/devenia-mcp-updater.php';

function fixture_canonicalize( $value ) {
	if ( ! is_array( $value ) ) return $value;
	if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) ksort( $value );
	foreach ( $value as $key => $item ) $value[ $key ] = fixture_canonicalize( $item );
	return $value;
}

function fixture_signed_manifest( array $plugins ): array {
	$payload = json_encode( array( 'generatedAt' => '2026-07-22T10:00:00Z', 'plugins' => $plugins ) );
	$secret  = sodium_crypto_sign_secretkey( $GLOBALS['fixture_signing_keypair'] );
	return array(
		'schemaVersion' => 2,
		'signedPayload' => base64_encode( $payload ),
		'signature' => array( 'algorithm' => 'Ed25519', 'keyId' => 'fixture', 'value' => base64_encode( sodium_crypto_sign_detached( $payload, $secret ) ) ),
	);
}

$plugin_file = 'mcp-expose-abilities/mcp-expose-abilities.php';
$package     = 'https://downloads.devenia.com/artifacts/mcp-expose-abilities/' . str_repeat( 'a', 64 ) . '/mcp-expose-abilities.zip';
$version     = '3.0.78';
$sha256      = str_repeat( 'a', 64 );
$fingerprint = array(
	'schemaVersion' => 1,
	'packageSha256' => $sha256,
	'pluginVersion' => $version,
	'pluginCheckVersion' => '2.0.0',
	'wordpressVersion' => '7.0.2',
	'phpVersion' => '8.3.6',
	'policy' => array( 'mode' => 'update' ),
);
$fingerprint['digest'] = hash( 'sha256', json_encode( fixture_canonicalize( $fingerprint ), JSON_UNESCAPED_SLASHES ) );
$members = array( array( 'path' => $plugin_file, 'size' => 123, 'sha256' => str_repeat( 'b', 64 ) ) );
$plugins = array(
		array(
			'slug'        => 'mcp-expose-abilities',
			'file'        => $plugin_file,
			'version'     => $version,
			'package'     => $package,
			'sha256'      => $sha256,
			'autoUpdate'  => true,
			'pluginCheck' => array(
				'status' => 'passed', 'sha256' => $sha256, 'errorCount' => 0, 'warningCount' => 0,
				'gateEvidence' => 'wp-plugin-check-exit-zero-strict-json-or-native-empty-v1',
				'qualityDecision' => 'strict-zero-finding',
				'verificationFingerprint' => $fingerprint,
			),
			'releaseIdentity' => array(
				'schemaVersion' => 1, 'slug' => 'mcp-expose-abilities', 'sha256' => $sha256, 'version' => $version, 'mainFile' => $plugin_file,
				'repository' => array( 'remote' => 'https://github.com/example/repo.git', 'commit' => str_repeat( 'c', 40 ), 'tree' => str_repeat( 'd', 40 ) ),
				'members' => $members, 'membersSha256' => hash( 'sha256', json_encode( $members, JSON_UNESCAPED_SLASHES ) ),
			),
		),
);
$schema_2_git_entry = $plugins[0];
$schema_2_git_entry['releaseIdentity']['schemaVersion'] = 2;
$schema_2_git_entry['releaseIdentity']['source'] = array(
	'adapter' => 'git',
	'remote'  => 'https://github.com/example/repo.git',
	'commit'  => str_repeat( 'c', 40 ),
	'tree'    => str_repeat( 'd', 40 ),
	'path'    => '.',
);
unset( $schema_2_git_entry['releaseIdentity']['repository'] );
if ( null === devenia_mcp_updater_normalize_entry( $schema_2_git_entry ) ) {
	throw new RuntimeException( 'A valid schema-2 Git release identity was rejected.' );
}
$schema_2_svn_entry = $schema_2_git_entry;
$schema_2_svn_entry['releaseIdentity']['source'] = array(
	'adapter'  => 'wordpress-org-svn',
	'url'      => 'https://plugins.svn.wordpress.org/mcp-expose-abilities/trunk',
	'revision' => '3619056',
);
if ( null === devenia_mcp_updater_normalize_entry( $schema_2_svn_entry ) ) {
	throw new RuntimeException( 'A valid schema-2 WordPress.org SVN release identity was rejected.' );
}
$schema_2_local_entry = $schema_2_git_entry;
$schema_2_local_entry['releaseIdentity']['source'] = array(
	'adapter'        => 'local-filesystem',
	'snapshotSha256' => $schema_2_local_entry['releaseIdentity']['membersSha256'],
);
if ( null === devenia_mcp_updater_normalize_entry( $schema_2_local_entry ) ) {
	throw new RuntimeException( 'A valid schema-2 local snapshot release identity was rejected.' );
}
$invalid_schema_2_entries = array();
$invalid_schema_2_entries['unknown adapter'] = $schema_2_git_entry;
$invalid_schema_2_entries['unknown adapter']['releaseIdentity']['source']['adapter'] = 'unknown';
$invalid_schema_2_entries['parent Git path'] = $schema_2_git_entry;
$invalid_schema_2_entries['parent Git path']['releaseIdentity']['source']['path'] = '../outside';
$invalid_schema_2_entries['non-WordPress.org SVN URL'] = $schema_2_svn_entry;
$invalid_schema_2_entries['non-WordPress.org SVN URL']['releaseIdentity']['source']['url'] = 'https://example.com/plugin/trunk';
$invalid_schema_2_entries['unbound local snapshot'] = $schema_2_local_entry;
$invalid_schema_2_entries['unbound local snapshot']['releaseIdentity']['source']['snapshotSha256'] = str_repeat( 'f', 64 );
$invalid_schema_2_entries['string schema version'] = $schema_2_git_entry;
$invalid_schema_2_entries['string schema version']['releaseIdentity']['schemaVersion'] = '2';
$invalid_schema_2_entries['partially numeric schema version'] = $schema_2_git_entry;
$invalid_schema_2_entries['partially numeric schema version']['releaseIdentity']['schemaVersion'] = '2junk';
$invalid_schema_2_entries['non-string Git remote'] = $schema_2_git_entry;
$invalid_schema_2_entries['non-string Git remote']['releaseIdentity']['source']['remote'] = array( 'https://github.com/example/repo.git' );
foreach ( $invalid_schema_2_entries as $label => $entry ) {
	if ( null !== devenia_mcp_updater_normalize_entry( $entry ) ) {
		throw new RuntimeException( 'Invalid schema-2 release identity was approved: ' . $label );
	}
}
$invalid_schema_1_entry = $plugins[0];
$invalid_schema_1_entry['releaseIdentity']['repository']['remote'] = array( 'https://github.com/example/repo.git' );
if ( null !== devenia_mcp_updater_normalize_entry( $invalid_schema_1_entry ) ) {
	throw new RuntimeException( 'A non-string schema-1 repository remote was approved.' );
}
$GLOBALS['fixture_manifest'] = fixture_signed_manifest( $plugins );
$GLOBALS['fixture_installed'][ $plugin_file ] = array( 'Version' => $version );
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

$plugins[0]['autoUpdate'] = false;
$GLOBALS['fixture_manifest'] = fixture_signed_manifest( $plugins );
if ( false !== devenia_mcp_updater_allow_mcp_expose_plugin_update( false, $plugin_file ) ) {
	throw new RuntimeException( 'A manifest entry without auto-update approval was approved.' );
}

$plugins[0]['autoUpdate'] = true;
$GLOBALS['fixture_manifest'] = fixture_signed_manifest( $plugins );
devenia_mcp_updater_capture_preinstall( true, array( 'type' => 'plugin', 'plugin' => $plugin_file ) );
$GLOBALS['fixture_manifest'] = array( 'unavailable_after_files_changed' => true );
devenia_mcp_updater_record_rollout_receipts( array( 'type' => 'plugin', 'plugin' => $plugin_file ) );
$receipts = $GLOBALS['fixture_options'][ DEVENIA_MCP_UPDATER_ROLLOUT_RECEIPTS_OPTION ] ?? array();
$rollout = end( $receipts );
if ( ! is_array( $rollout ) || 'passed' !== ( $rollout['status'] ?? '' ) || 200 !== ( $rollout['healthHttpStatus'] ?? 0 ) ) {
	throw new RuntimeException( 'Terminal rollout receipt did not bind pre-install authority, exact identity, activation, and live HTTP health.' );
}
if ( ! empty( $GLOBALS['fixture_options'][ DEVENIA_MCP_UPDATER_ROLLOUT_PENDING_OPTION ] ?? array() ) ) {
	throw new RuntimeException( 'Terminal rollout did not consume its durable pending intent.' );
}

fwrite( STDOUT, "Exact manifest/update-offer identity runtime passed.\n" );
