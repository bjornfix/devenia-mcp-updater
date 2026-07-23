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
function update_option( $key, $value, ...$args ): bool { unset( $args ); $GLOBALS['fixture_options'][ $key ] = $value; return true; }
function add_option( $key, $value, ...$args ): bool { unset( $args ); if ( array_key_exists( $key, $GLOBALS['fixture_options'] ) ) return false; $GLOBALS['fixture_options'][ $key ] = $value; return true; }
function delete_option( $key ): bool { if ( ! array_key_exists( $key, $GLOBALS['fixture_options'] ) ) return false; unset( $GLOBALS['fixture_options'][ $key ] ); return true; }
function get_option( $key, $default = false ) { return $GLOBALS['fixture_options'][ $key ] ?? $default; }
function wp_generate_uuid4(): string { return '00000000-0000-4000-8000-' . str_pad( (string) count( $GLOBALS['fixture_options'] ), 12, '0', STR_PAD_LEFT ); }
function wp_clean_plugins_cache( ...$args ): void { unset( $args ); }
function wp_update_plugins(): void {}
function get_plugins(): array { return $GLOBALS['fixture_installed']; }
function is_plugin_active( string $plugin ): bool { return ! empty( $GLOBALS['fixture_active'][ $plugin ] ); }
function activate_plugin( string $plugin ): void { $GLOBALS['fixture_active'][ $plugin ] = true; }
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
	|| empty( $GLOBALS['fixture_options'][ DEVENIA_MCP_UPDATER_ROLLOUT_PENDING_OPTION ] ?? array() )
	|| $health_before_child_shutdown !== $GLOBALS['fixture_health_requests']
) {
	throw new RuntimeException( 'The updater health child request recursively finalized its parent pending receipt.' );
}
$GLOBALS['fixture_active'][ $plugin_file ] = true;
$GLOBALS['fixture_nested_shutdown_on_health'] = true;
devenia_mcp_updater_flush_rollout_receipts( false );
$GLOBALS['fixture_nested_shutdown_on_health'] = false;
$receipts = $GLOBALS['fixture_options'][ DEVENIA_MCP_UPDATER_ROLLOUT_RECEIPTS_OPTION ] ?? array();
$rollout = end( $receipts );
if ( ! is_array( $rollout ) || 'passed' !== ( $rollout['status'] ?? '' ) || 200 !== ( $rollout['healthHttpStatus'] ?? 0 ) || $health_before_child_shutdown + 1 !== $GLOBALS['fixture_health_requests'] ) {
	throw new RuntimeException( 'Terminal rollout receipt did not bind pre-install authority, exact identity, activation, and live HTTP health.' );
}
if ( ! empty( $GLOBALS['fixture_options'][ DEVENIA_MCP_UPDATER_ROLLOUT_PENDING_OPTION ] ?? array() ) ) {
	throw new RuntimeException( 'Terminal rollout did not consume its durable pending intent.' );
}
$active_rollout = $rollout;

$GLOBALS['fixture_options'][ DEVENIA_MCP_UPDATER_ROLLOUT_RECEIPTS_OPTION ] = array();
$GLOBALS['fixture_options'][ DEVENIA_MCP_UPDATER_ROLLOUT_PENDING_OPTION ] = array();
$GLOBALS['fixture_manifest'] = fixture_signed_manifest( $plugins );
$GLOBALS['fixture_active'][ $plugin_file ] = true;
devenia_mcp_updater_capture_preinstall( true, array( 'type' => 'plugin', 'plugin' => $plugin_file ) );
$GLOBALS['devenia_mcp_updater_rollout_changed'] = array();
devenia_mcp_updater_flush_rollout_receipts();
$recovered_pending_receipts = $GLOBALS['fixture_options'][ DEVENIA_MCP_UPDATER_ROLLOUT_RECEIPTS_OPTION ] ?? array();
if ( 1 !== count( $recovered_pending_receipts ) || 'passed' !== ( $recovered_pending_receipts[0]['status'] ?? '' ) ) {
	throw new RuntimeException( 'A later request did not recover and finalize durable pending rollout evidence.' );
}

$GLOBALS['fixture_options'][ DEVENIA_MCP_UPDATER_ROLLOUT_RECEIPTS_OPTION ] = array();
$GLOBALS['fixture_options'][ DEVENIA_MCP_UPDATER_ROLLOUT_PENDING_OPTION ] = array();
$GLOBALS['fixture_manifest'] = fixture_signed_manifest( $plugins );
$GLOBALS['fixture_active'][ $plugin_file ] = false;
devenia_mcp_updater_capture_preinstall( true, array( 'type' => 'plugin', 'plugin' => $plugin_file ) );
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
