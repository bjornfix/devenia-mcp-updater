<?php
/**
 * Standalone contract for exact manifest/update-offer identity approval.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

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
$GLOBALS['fixture_active'] = array();
$GLOBALS['fixture_health_code'] = 200;
$GLOBALS['fixture_health_requests'] = 0;
$GLOBALS['fixture_nested_shutdown_on_health'] = false;
$GLOBALS['fixture_uuid'] = 0;
$GLOBALS['fixture_fail_option_key'] = '';

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
	unset( $args );
	if ( false !== strpos( $url, 'devenia_rollout_health=' ) ) {
		$GLOBALS['fixture_health_requests']++;
		if ( ! empty( $GLOBALS['fixture_nested_shutdown_on_health'] ) && function_exists( 'devenia_mcp_updater_flush_rollout_receipts' ) ) {
			devenia_mcp_updater_flush_rollout_receipts( true );
		}
		return array( 'response' => array( 'code' => $GLOBALS['fixture_health_code'] ), 'body' => '' );
	}
	return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $GLOBALS['fixture_manifest'] ) );
}
function wp_remote_retrieve_response_code( array $response ): int { return (int) $response['response']['code']; }
function wp_remote_retrieve_body( array $response ): string { return (string) $response['body']; }
function get_site_transient( string $key ) {
	return 'update_plugins' === $key ? $GLOBALS['fixture_updates'] : false;
}
function set_site_transient( ...$args ): void { unset( $args ); }
function update_option( $key, $value, ...$args ): bool { unset( $args ); if ( $key === $GLOBALS['fixture_fail_option_key'] ) return false; $GLOBALS['fixture_options'][ $key ] = $value; return true; }
function add_option( $key, $value, ...$args ): bool { unset( $args ); if ( $key === $GLOBALS['fixture_fail_option_key'] || array_key_exists( $key, $GLOBALS['fixture_options'] ) ) return false; $GLOBALS['fixture_options'][ $key ] = $value; return true; }
function delete_option( $key ): bool { if ( ! array_key_exists( $key, $GLOBALS['fixture_options'] ) ) return false; unset( $GLOBALS['fixture_options'][ $key ] ); return true; }
function get_option( $key, $default = false ) { return $GLOBALS['fixture_options'][ $key ] ?? $default; }
function wp_generate_uuid4(): string { $GLOBALS['fixture_uuid']++; return '00000000-0000-4000-8000-' . str_pad( (string) $GLOBALS['fixture_uuid'], 12, '0', STR_PAD_LEFT ); }
function wp_clean_plugins_cache( ...$args ): void { unset( $args ); }
function wp_update_plugins(): void {}
function get_plugins(): array { return $GLOBALS['fixture_installed']; }
function is_plugin_active( string $plugin ): bool { return ! empty( $GLOBALS['fixture_active'][ $plugin ] ); }
function activate_plugin( string $plugin ): void { $GLOBALS['fixture_active'][ $plugin ] = true; }
function delete_plugins( ...$args ): bool { unset( $args ); return true; }
function home_url( string $path = '/' ): string { return 'https://devenia.com' . $path; }
function add_query_arg( string $key, string $value, string $url ): string { return $url . '?' . rawurlencode( $key ) . '=' . rawurlencode( $value ); }

require_once dirname( __DIR__ ) . '/devenia-mcp-updater.php';

if ( true !== devenia_mcp_updater_auto_update_plugin( false, (object) array( 'plugin' => 'wordfence/wordfence.php' ) ) ) {
	throw new RuntimeException( 'A third-party plugin update was not enrolled automatically.' );
}
if ( true !== devenia_mcp_updater_auto_update_plugin( null, (object) array( 'plugin' => 'future-plugin/future-plugin.php' ) ) ) {
	throw new RuntimeException( 'A future plugin update was not enrolled automatically.' );
}

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
				'schemaVersion' => 3, 'slug' => 'mcp-expose-abilities', 'sha256' => $sha256, 'version' => $version, 'mainFile' => $plugin_file,
				'source' => array( 'adapter' => 'git', 'commit' => str_repeat( 'c', 40 ), 'tree' => str_repeat( 'd', 40 ), 'path' => '.' ),
				'members' => $members, 'membersSha256' => hash( 'sha256', json_encode( $members, JSON_UNESCAPED_SLASHES ) ),
			),
		),
);
$historical_identity = json_decode( '{"schemaVersion":2,"slug":"devenia-mcp-updater","version":"0.1.10","sha256":"fb1534575ee5af47d77f971dbdfcb81371a1d2bce63f5905ea9ea7bf1ce225e9","mainFile":"devenia-mcp-updater/devenia-mcp-updater.php","source":{"adapter":"git","remote":"https://github.com/bjornfix/devenia-mcp-updater.git","commit":"c0d5c4d0caac4d6b24c1de06089ff820e8a0a1c8","tree":"2e21e9bba7faff9cd598b14bfd99a2ac85eb4eff","path":"."},"members":[{"path":"devenia-mcp-updater/devenia-mcp-updater.php","size":47119,"sha256":"8466098793010dca7d9548bf9e1dac9b3343578cbf3d93798a3c3f9e454ed623"},{"path":"devenia-mcp-updater/readme.txt","size":5392,"sha256":"2316d992488e1d0ae93e699a3f473d20b9b8904652c5d88ef4c6dc2fccf57a5f"}],"membersSha256":"40404f17fe906cca51e3c19d5bab921a051c6401fa24fbc3014da34b6a6a46ad"}', true );
if ( ! is_array( $historical_identity ) || ! devenia_mcp_updater_release_identity_provenance_is_valid( $historical_identity ) ) {
	throw new RuntimeException( 'An exact already-signed historical identity was rejected.' );
}
$tampered_historical_identity = $historical_identity;
$tampered_historical_identity['source']['remote'] = 'https://external.example/repository.git';
if ( devenia_mcp_updater_release_identity_provenance_is_valid( $tampered_historical_identity ) ) {
	throw new RuntimeException( 'A changed historical identity was incorrectly approved.' );
}
$schema_2_git_entry = $plugins[0];
$schema_2_git_entry['releaseIdentity']['schemaVersion'] = 2;
$schema_2_git_entry['releaseIdentity']['source']['remote'] = 'https://github.com/example/repo.git';
if ( null !== devenia_mcp_updater_normalize_entry( $schema_2_git_entry ) ) {
	throw new RuntimeException( 'A new unapproved schema-2 Git release identity was accepted.' );
}
$schema_3_git_entry = $plugins[0];
if ( null === devenia_mcp_updater_normalize_entry( $schema_3_git_entry ) ) {
	throw new RuntimeException( 'A locally authoritative schema-3 Git release identity was rejected.' );
}
$schema_3_remote_entry = $schema_3_git_entry;
$schema_3_remote_entry['releaseIdentity']['source']['remote'] = 'ssh://storage.example.invalid/repository.git';
if ( null !== devenia_mcp_updater_normalize_entry( $schema_3_remote_entry ) ) {
	throw new RuntimeException( 'A schema-3 remote was incorrectly treated as release authority.' );
}
$schema_3_extra_identity_entry = $schema_3_git_entry;
$schema_3_extra_identity_entry['releaseIdentity']['source']['marketingUrl'] = 'https://external.example/release';
if ( null !== devenia_mcp_updater_normalize_entry( $schema_3_extra_identity_entry ) ) {
	throw new RuntimeException( 'An unknown schema-3 identity field was incorrectly approved.' );
}
$schema_3_svn_entry = $plugins[0];
$schema_3_svn_entry['releaseIdentity']['source'] = array(
	'adapter'  => 'wordpress-org-svn',
	'url'      => 'https://plugins.svn.wordpress.org/mcp-expose-abilities/trunk',
	'revision' => '3619056',
);
if ( null === devenia_mcp_updater_normalize_entry( $schema_3_svn_entry ) ) {
	throw new RuntimeException( 'A valid schema-3 WordPress.org SVN release identity was rejected.' );
}
$schema_3_svn_extra_entry = $schema_3_svn_entry;
$schema_3_svn_extra_entry['releaseIdentity']['source']['mirror'] = 'external';
if ( null !== devenia_mcp_updater_normalize_entry( $schema_3_svn_extra_entry ) ) {
	throw new RuntimeException( 'An unknown schema-3 SVN identity field was incorrectly approved.' );
}
$schema_3_local_entry = $plugins[0];
$schema_3_local_entry['releaseIdentity']['source'] = array(
	'adapter'        => 'local-filesystem',
	'snapshotSha256' => $schema_3_local_entry['releaseIdentity']['membersSha256'],
);
if ( null === devenia_mcp_updater_normalize_entry( $schema_3_local_entry ) ) {
	throw new RuntimeException( 'A valid schema-3 local snapshot release identity was rejected.' );
}
$schema_3_local_extra_entry = $schema_3_local_entry;
$schema_3_local_extra_entry['releaseIdentity']['source']['mirror'] = 'external';
if ( null !== devenia_mcp_updater_normalize_entry( $schema_3_local_extra_entry ) ) {
	throw new RuntimeException( 'An unknown schema-3 local identity field was incorrectly approved.' );
}
$invalid_schema_3_entries = array();
$invalid_schema_3_entries['unknown adapter'] = $schema_3_git_entry;
$invalid_schema_3_entries['unknown adapter']['releaseIdentity']['source']['adapter'] = 'unknown';
$invalid_schema_3_entries['parent Git path'] = $schema_3_git_entry;
$invalid_schema_3_entries['parent Git path']['releaseIdentity']['source']['path'] = '../outside';
$invalid_schema_3_entries['non-WordPress.org SVN URL'] = $schema_3_svn_entry;
$invalid_schema_3_entries['non-WordPress.org SVN URL']['releaseIdentity']['source']['url'] = 'https://example.com/plugin/trunk';
$invalid_schema_3_entries['unbound local snapshot'] = $schema_3_local_entry;
$invalid_schema_3_entries['unbound local snapshot']['releaseIdentity']['source']['snapshotSha256'] = str_repeat( 'f', 64 );
$invalid_schema_3_entries['string schema version'] = $schema_3_git_entry;
$invalid_schema_3_entries['string schema version']['releaseIdentity']['schemaVersion'] = '3';
$invalid_schema_3_entries['partially numeric schema version'] = $schema_3_git_entry;
$invalid_schema_3_entries['partially numeric schema version']['releaseIdentity']['schemaVersion'] = '3junk';
foreach ( $invalid_schema_3_entries as $label => $entry ) {
	if ( null !== devenia_mcp_updater_normalize_entry( $entry ) ) {
		throw new RuntimeException( 'Invalid schema-3 release identity was approved: ' . $label );
	}
}
$GLOBALS['fixture_manifest'] = fixture_signed_manifest( $plugins );
$GLOBALS['fixture_installed'][ $plugin_file ] = array( 'Version' => $version );
$GLOBALS['fixture_active'][ $plugin_file ] = true;
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
$pending_key = devenia_mcp_updater_rollout_pending_key( (string) $GLOBALS['devenia_mcp_updater_rollout_prior'][ $plugin_file ]['rolloutId'] );
$GLOBALS['fixture_manifest'] = array( 'unavailable_after_files_changed' => true );
$GLOBALS['fixture_active'][ $plugin_file ] = false;
devenia_mcp_updater_after_plugin_upgrade( null, array( 'type' => 'plugin', 'plugin' => $plugin_file ) );
if ( ! empty( $GLOBALS['fixture_options'][ DEVENIA_MCP_UPDATER_ROLLOUT_RECEIPTS_OPTION ] ?? array() ) ) {
	throw new RuntimeException( 'Rollout receipt finalized before the caller could restore the prior activation state.' );
}
$health_before_child_shutdown = $GLOBALS['fixture_health_requests'];
devenia_mcp_updater_flush_rollout_receipts( true );
if (
	! empty( $GLOBALS['fixture_options'][ DEVENIA_MCP_UPDATER_ROLLOUT_RECEIPTS_OPTION ] ?? array() )
	|| empty( $GLOBALS['fixture_options'][ $pending_key ] ?? array() )
	|| $health_before_child_shutdown !== $GLOBALS['fixture_health_requests']
) {
	throw new RuntimeException( 'The updater health child request recursively finalized its parent pending receipt.' );
}
$GLOBALS['fixture_active'][ $plugin_file ] = true;
$GLOBALS['fixture_nested_shutdown_on_health'] = true;
$other_pending_key = devenia_mcp_updater_rollout_pending_key( 'other-rollout-id' );
$GLOBALS['fixture_options'][ $other_pending_key ] = array( 'version' => '1.0.0', 'active' => true );
devenia_mcp_updater_after_plugin_activation( $plugin_file );
$GLOBALS['fixture_nested_shutdown_on_health'] = false;
$receipts = $GLOBALS['fixture_options'][ DEVENIA_MCP_UPDATER_ROLLOUT_RECEIPTS_OPTION ] ?? array();
$rollout = end( $receipts );
if ( ! is_array( $rollout ) || 'passed' !== ( $rollout['status'] ?? '' ) || 200 !== ( $rollout['healthHttpStatus'] ?? 0 ) || $health_before_child_shutdown + 1 !== $GLOBALS['fixture_health_requests'] ) {
	throw new RuntimeException( 'The post-activation Adapter did not bind pre-install authority, exact identity, activation, and live HTTP health.' );
}
if ( false !== get_option( $pending_key ) ) {
	throw new RuntimeException( 'Terminal rollout did not consume its durable pending intent.' );
}
if ( array( 'version' => '1.0.0', 'active' => true ) !== get_option( $other_pending_key ) ) {
	throw new RuntimeException( 'One plugin receipt finalization overwrote unrelated durable pending authority.' );
}
unset( $GLOBALS['fixture_options'][ $other_pending_key ] );
$active_rollout = $rollout;
$mutated_active_rollout = $active_rollout;
$mutated_active_rollout['status'] = 'failed';
if (
	devenia_mcp_updater_persist_terminal_receipt( $mutated_active_rollout )
	|| $active_rollout !== get_option( devenia_mcp_updater_rollout_receipt_key( (string) $active_rollout['rolloutId'] ) )
) {
	throw new RuntimeException( 'A retry mutated an existing terminal receipt for the same rollout identity.' );
}

$GLOBALS['fixture_options'][ DEVENIA_MCP_UPDATER_ROLLOUT_RECEIPTS_OPTION ] = array();
$GLOBALS['fixture_manifest'] = fixture_signed_manifest( $plugins );
$GLOBALS['fixture_active'][ $plugin_file ] = false;
devenia_mcp_updater_capture_preinstall( true, array( 'type' => 'plugin', 'plugin' => $plugin_file ) );
$failed_persist_rollout_id = (string) $GLOBALS['devenia_mcp_updater_rollout_prior'][ $plugin_file ]['rolloutId'];
$failed_persist_pending_key = devenia_mcp_updater_rollout_pending_key( $failed_persist_rollout_id );
$GLOBALS['fixture_fail_option_key'] = devenia_mcp_updater_rollout_receipt_key( $failed_persist_rollout_id );
devenia_mcp_updater_after_plugin_upgrade( null, array( 'type' => 'plugin', 'plugin' => $plugin_file ) );
devenia_mcp_updater_flush_rollout_receipts();
if (
	false === get_option( $failed_persist_pending_key )
	|| ! in_array( $plugin_file, $GLOBALS['devenia_mcp_updater_rollout_changed'], true )
	|| ! empty( $GLOBALS['fixture_options'][ DEVENIA_MCP_UPDATER_ROLLOUT_RECEIPTS_OPTION ] ?? array() )
) {
	throw new RuntimeException( 'A terminal receipt persistence failure consumed exact pending authority or lost retry work.' );
}
$GLOBALS['fixture_fail_option_key'] = '';
devenia_mcp_updater_flush_rollout_receipts();
if (
	false !== get_option( $failed_persist_pending_key )
	|| false === get_option( devenia_mcp_updater_rollout_receipt_key( $failed_persist_rollout_id ) )
) {
	throw new RuntimeException( 'Retry did not persist terminal authority before consuming its exact pending record.' );
}

$GLOBALS['fixture_options'][ DEVENIA_MCP_UPDATER_ROLLOUT_RECEIPTS_OPTION ] = array();
unset( $GLOBALS['fixture_options'][ $pending_key ] );
$GLOBALS['fixture_manifest'] = fixture_signed_manifest( $plugins );
$GLOBALS['fixture_active'][ $plugin_file ] = true;
devenia_mcp_updater_capture_preinstall( true, array( 'type' => 'plugin', 'plugin' => $plugin_file ) );
$pending_key = devenia_mcp_updater_rollout_pending_key( (string) $GLOBALS['devenia_mcp_updater_rollout_prior'][ $plugin_file ]['rolloutId'] );
$GLOBALS['devenia_mcp_updater_rollout_changed'] = array();
devenia_mcp_updater_flush_rollout_receipts();
$recovered_pending_receipts = $GLOBALS['fixture_options'][ DEVENIA_MCP_UPDATER_ROLLOUT_RECEIPTS_OPTION ] ?? array();
if (
	! empty( $recovered_pending_receipts )
	|| empty( $GLOBALS['fixture_options'][ $pending_key ] ?? array() )
) {
	throw new RuntimeException( 'An unrelated request finalized durable pending rollout evidence without owning the update request.' );
}

$GLOBALS['fixture_options'][ DEVENIA_MCP_UPDATER_ROLLOUT_RECEIPTS_OPTION ] = array();
unset( $GLOBALS['fixture_options'][ $pending_key ] );
$GLOBALS['fixture_manifest'] = fixture_signed_manifest( $plugins );
$GLOBALS['fixture_active'][ $plugin_file ] = false;
devenia_mcp_updater_capture_preinstall( true, array( 'type' => 'plugin', 'plugin' => $plugin_file ) );
$pending_key = devenia_mcp_updater_rollout_pending_key( (string) $GLOBALS['devenia_mcp_updater_rollout_prior'][ $plugin_file ]['rolloutId'] );
devenia_mcp_updater_after_plugin_upgrade( null, array( 'type' => 'plugin', 'plugin' => $plugin_file ) );
devenia_mcp_updater_flush_rollout_receipts();
$inactive_receipts = $GLOBALS['fixture_options'][ DEVENIA_MCP_UPDATER_ROLLOUT_RECEIPTS_OPTION ] ?? array();
if ( 1 !== count( $inactive_receipts ) || 'passed' !== ( $inactive_receipts[0]['status'] ?? '' ) || false !== ( $inactive_receipts[0]['active'] ?? true ) ) {
	throw new RuntimeException( 'A deliberately inactive managed plugin did not preserve its inactive terminal state.' );
}

$timing_failure = $active_rollout;
$timing_failure['active'] = false;
$timing_failure['activationPreserved'] = false;
$timing_failure['health'] = 'prior_version_activation_or_live_site_invariant_failed';
$timing_failure['status'] = 'failed';
$timing_failure['completedAt'] = '2026-07-22T23:50:14+00:00';
$timing_failure['rolloutId'] = 'legacy-timing-failure';
if ( ! devenia_mcp_updater_persist_terminal_receipt( $timing_failure ) ) {
	throw new RuntimeException( 'Could not persist the immutable failed timing receipt fixture.' );
}
$GLOBALS['fixture_options'][ DEVENIA_MCP_UPDATER_ROLLOUT_RECEIPTS_OPTION ] = array( $timing_failure );
$GLOBALS['fixture_manifest'] = fixture_signed_manifest( $plugins );
$GLOBALS['fixture_active'][ $plugin_file ] = true;
$health_before_migration = $GLOBALS['fixture_health_requests'];
devenia_mcp_updater_maybe_migrate_activation_timing_receipts( true );
if (
	1 !== count( $GLOBALS['fixture_options'][ DEVENIA_MCP_UPDATER_ROLLOUT_RECEIPTS_OPTION ] ?? array() )
	|| false !== get_option( DEVENIA_MCP_UPDATER_RECEIPT_MIGRATION_OPTION )
) {
	throw new RuntimeException( 'The loopback health request recursively entered receipt migration.' );
}
$GLOBALS['fixture_options'][ DEVENIA_MCP_UPDATER_RECEIPT_MIGRATION_LOCK_OPTION ] = array( 'token' => 'other-request', 'claimed_at' => '2020-01-01T00:00:00+00:00' );
devenia_mcp_updater_maybe_migrate_activation_timing_receipts( false );
if ( 1 !== count( $GLOBALS['fixture_options'][ DEVENIA_MCP_UPDATER_ROLLOUT_RECEIPTS_OPTION ] ?? array() ) ) {
	throw new RuntimeException( 'A concurrent receipt migration ignored the active claim.' );
}
unset( $GLOBALS['fixture_options'][ DEVENIA_MCP_UPDATER_RECEIPT_MIGRATION_LOCK_OPTION ] );
devenia_mcp_updater_maybe_migrate_activation_timing_receipts( false );
$reconciled_receipts = $GLOBALS['fixture_options'][ DEVENIA_MCP_UPDATER_ROLLOUT_RECEIPTS_OPTION ] ?? array();
$reconciled = end( $reconciled_receipts );
if (
	2 !== count( $reconciled_receipts )
	|| ! is_array( $reconciled )
	|| 'passed' !== ( $reconciled['status'] ?? '' )
	|| $reconciled !== get_option( devenia_mcp_updater_rollout_receipt_key( (string) ( $reconciled['rolloutId'] ?? '' ) ) )
	|| $timing_failure !== get_option( devenia_mcp_updater_rollout_receipt_key( 'legacy-timing-failure' ) )
	|| '2026-07-22T23:50:14+00:00' !== ( $reconciled['reconciledFromCompletedAt'] ?? '' )
	|| true !== ( $reconciled['activationPreserved'] ?? false )
	|| 'complete' !== ( get_option( DEVENIA_MCP_UPDATER_RECEIPT_MIGRATION_OPTION )['status'] ?? '' )
	|| false !== get_option( DEVENIA_MCP_UPDATER_RECEIPT_MIGRATION_LOCK_OPTION )
	|| $health_before_migration + 1 !== $GLOBALS['fixture_health_requests']
) {
	throw new RuntimeException( 'A preserved exact failed receipt could not be reconciled against the later terminal activation and live-health state.' );
}
devenia_mcp_updater_maybe_migrate_activation_timing_receipts( false );
if ( 2 !== count( $GLOBALS['fixture_options'][ DEVENIA_MCP_UPDATER_ROLLOUT_RECEIPTS_OPTION ] ?? array() ) || $health_before_migration + 1 !== $GLOBALS['fixture_health_requests'] ) {
	throw new RuntimeException( 'Terminal receipt reconciliation was not idempotent.' );
}
$GLOBALS['fixture_options'][ DEVENIA_MCP_UPDATER_ROLLOUT_RECEIPTS_OPTION ] = array( $timing_failure );
unset( $GLOBALS['fixture_options'][ DEVENIA_MCP_UPDATER_RECEIPT_MIGRATION_OPTION ] );
$health_before_partial_retry = $GLOBALS['fixture_health_requests'];
devenia_mcp_updater_maybe_migrate_activation_timing_receipts( false );
$partial_retry_receipts = $GLOBALS['fixture_options'][ DEVENIA_MCP_UPDATER_ROLLOUT_RECEIPTS_OPTION ] ?? array();
$partial_retry_reconciled = end( $partial_retry_receipts );
if (
	2 !== count( $partial_retry_receipts )
	|| $reconciled !== $partial_retry_reconciled
	|| $health_before_partial_retry + 1 !== $GLOBALS['fixture_health_requests']
) {
	throw new RuntimeException( 'A retry after partial reconciliation did not reuse the exact immutable terminal receipt.' );
}

$not_timing_failure = $timing_failure;
$not_timing_failure['priorActive'] = false;
$not_timing_failure['active'] = false;
$not_timing_failure['activationPreserved'] = true;
$not_timing_failure['healthHttpStatus'] = 500;
$not_timing_failure['completedAt'] = 'not-a-timing-failure';
$GLOBALS['fixture_options'][ DEVENIA_MCP_UPDATER_ROLLOUT_RECEIPTS_OPTION ] = array( $not_timing_failure );
unset( $GLOBALS['fixture_options'][ DEVENIA_MCP_UPDATER_RECEIPT_MIGRATION_OPTION ] );
devenia_mcp_updater_maybe_migrate_activation_timing_receipts( false );
$not_reconciled = $GLOBALS['fixture_options'][ DEVENIA_MCP_UPDATER_ROLLOUT_RECEIPTS_OPTION ] ?? array();
if ( 1 !== count( $not_reconciled ) || 'failed' !== ( $not_reconciled[0]['status'] ?? '' ) ) {
	throw new RuntimeException( 'A genuine inactive or live-health failure was rewritten as an activation-timing pass.' );
}

$GLOBALS['fixture_options'][ DEVENIA_MCP_UPDATER_ROLLOUT_RECEIPTS_OPTION ] = array( $timing_failure );
unset( $GLOBALS['fixture_options'][ DEVENIA_MCP_UPDATER_RECEIPT_MIGRATION_OPTION ] );
$GLOBALS['fixture_health_code'] = 503;
$health_requests_before_retry = $GLOBALS['fixture_health_requests'];
devenia_mcp_updater_maybe_migrate_activation_timing_receipts( false );
$retry_state = get_option( DEVENIA_MCP_UPDATER_RECEIPT_MIGRATION_OPTION, array() );
if (
	'retry_wait' !== ( $retry_state['status'] ?? '' )
	|| 1 !== ( $retry_state['attempts'] ?? 0 )
	|| time() >= ( $retry_state['not_before'] ?? 0 )
	|| $health_requests_before_retry + 1 !== $GLOBALS['fixture_health_requests']
) {
	throw new RuntimeException( 'A failed migration attempt did not enter finite backoff.' );
}
devenia_mcp_updater_maybe_migrate_activation_timing_receipts( false );
if ( $health_requests_before_retry + 1 !== $GLOBALS['fixture_health_requests'] ) {
	throw new RuntimeException( 'Receipt migration retried during its not-before backoff window.' );
}
for ( $attempt = 2; $attempt <= 3; $attempt++ ) {
	$retry_state['not_before'] = 0;
	$GLOBALS['fixture_options'][ DEVENIA_MCP_UPDATER_RECEIPT_MIGRATION_OPTION ] = $retry_state;
	devenia_mcp_updater_maybe_migrate_activation_timing_receipts( false );
	$retry_state = get_option( DEVENIA_MCP_UPDATER_RECEIPT_MIGRATION_OPTION, array() );
}
if ( 'blocked' !== ( $retry_state['status'] ?? '' ) || 3 !== ( $retry_state['attempts'] ?? 0 ) || $health_requests_before_retry + 3 !== $GLOBALS['fixture_health_requests'] ) {
	throw new RuntimeException( 'Receipt migration did not stop after its finite retry budget.' );
}

fwrite( STDOUT, "Exact manifest/update-offer identity runtime passed.\n" );
