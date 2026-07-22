<?php
/**
 * Plugin Name: Devenia MCP Updater
 * Plugin URI: https://devenia.com
 * Description: Private update channel and automatic sync for Devenia MCP and Abilities plugins.
 * Version: 0.1.8
 * Author: Devenia
 * Author URI: https://devenia.com
 * License: GPL-2.0+
 * Requires at least: 6.8
 * Requires PHP: 7.4
 * Update URI: https://downloads.devenia.com/devenia-mcp-manifest.json
 *
 * @package Devenia_MCP_Updater
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DEVENIA_MCP_UPDATER_VERSION', '0.1.8' );
define( 'DEVENIA_MCP_UPDATER_MANIFEST_URL', 'https://downloads.devenia.com/devenia-mcp-manifest.json' );
define( 'DEVENIA_MCP_UPDATER_TRANSIENT', 'devenia_mcp_updater_manifest_v2' );
if ( ! defined( 'DEVENIA_MCP_UPDATER_MANIFEST_PUBLIC_KEY' ) ) {
	define( 'DEVENIA_MCP_UPDATER_MANIFEST_PUBLIC_KEY', 'WIinfFgjeRtcWHSZavh6hQ2MfcUPKkuT9wCuyhuKbqg=' );
}
if ( ! defined( 'DEVENIA_MCP_UPDATER_MANIFEST_KEY_ID' ) ) {
	define( 'DEVENIA_MCP_UPDATER_MANIFEST_KEY_ID', '9735a17d2469b4c4' );
}
define( 'DEVENIA_MCP_UPDATER_STATUS_OPTION', 'devenia_mcp_updater_status' );
define( 'DEVENIA_MCP_UPDATER_ROLLOUT_RECEIPTS_OPTION', 'devenia_mcp_updater_rollout_receipts' );
define( 'DEVENIA_MCP_UPDATER_ROLLOUT_PENDING_OPTION', 'devenia_mcp_updater_rollout_pending' );
define( 'DEVENIA_MCP_UPDATER_LEGACY_RECONCILE_TRANSIENT', 'devenia_mcp_updater_legacy_reconcile_v1' );

/**
 * Fetch and cache the private MCP manifest.
 *
 * @param bool $force_refresh Whether to bypass the transient cache.
 * @return array<string,mixed>|WP_Error
 */
function devenia_mcp_updater_get_manifest( bool $force_refresh = false ) {
	if ( ! $force_refresh ) {
		$cached = get_site_transient( DEVENIA_MCP_UPDATER_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}

	$response = wp_remote_get(
		DEVENIA_MCP_UPDATER_MANIFEST_URL,
		array(
			'timeout' => 15,
			'headers' => array(
				'Accept' => 'application/json',
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		devenia_mcp_updater_record_status( 'manifest_error', $response->get_error_message() );
		return $response;
	}

	$status = wp_remote_retrieve_response_code( $response );
	if ( 200 !== $status ) {
		$error = new WP_Error( 'devenia_mcp_manifest_http_error', 'Manifest request failed.', array( 'status' => $status ) );
		devenia_mcp_updater_record_status( 'manifest_error', 'Manifest request returned HTTP ' . $status );
		return $error;
	}

	$envelope = json_decode( wp_remote_retrieve_body( $response ), true );
	$manifest = devenia_mcp_updater_verified_manifest_payload( $envelope );
	if ( is_wp_error( $manifest ) ) {
		$error = $manifest;
		devenia_mcp_updater_record_status( 'manifest_error', $error->get_error_message() );
		return $error;
	}

	set_site_transient( DEVENIA_MCP_UPDATER_TRANSIENT, $manifest, 30 * MINUTE_IN_SECONDS );
	return $manifest;
}

/** Verify the signed manifest envelope and return only its authenticated payload. */
function devenia_mcp_updater_verified_manifest_payload( $envelope, string $public_key_base64 = DEVENIA_MCP_UPDATER_MANIFEST_PUBLIC_KEY ) {
	if (
		! is_array( $envelope )
		|| 2 !== (int) ( $envelope['schemaVersion'] ?? 0 )
		|| 'Ed25519' !== (string) ( $envelope['signature']['algorithm'] ?? '' )
		|| DEVENIA_MCP_UPDATER_MANIFEST_KEY_ID !== (string) ( $envelope['signature']['keyId'] ?? '' )
		|| ! is_string( $envelope['signedPayload'] ?? null )
		|| ! is_string( $envelope['signature']['value'] ?? null )
		|| ! function_exists( 'sodium_crypto_sign_verify_detached' )
	) {
		return new WP_Error( 'devenia_mcp_manifest_signature_invalid', 'Manifest signature envelope is invalid.' );
	}
	$payload_bytes = base64_decode( $envelope['signedPayload'], true );
	$signature     = base64_decode( $envelope['signature']['value'], true );
	$public_key    = base64_decode( $public_key_base64, true );
	if ( false === $payload_bytes || false === $signature || false === $public_key || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $signature ) || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $public_key ) ) {
		return new WP_Error( 'devenia_mcp_manifest_signature_invalid', 'Manifest signature material is invalid.' );
	}
	if ( ! sodium_crypto_sign_verify_detached( $signature, $payload_bytes, $public_key ) ) {
		return new WP_Error( 'devenia_mcp_manifest_signature_invalid', 'Manifest signature verification failed.' );
	}
	$payload = json_decode( $payload_bytes, true );
	if ( ! is_array( $payload ) || ! isset( $payload['plugins'] ) || ! is_array( $payload['plugins'] ) ) {
		return new WP_Error( 'devenia_mcp_manifest_invalid', 'Authenticated manifest payload is invalid.' );
	}
	return $payload;
}

/**
 * Store a compact update status record.
 *
 * @param string $state   Status state.
 * @param string $message Human-readable message.
 * @param array  $extra   Extra status data.
 * @return void
 */
function devenia_mcp_updater_record_status( string $state, string $message, array $extra = array() ): void {
	update_option(
		DEVENIA_MCP_UPDATER_STATUS_OPTION,
		array_merge(
			array(
				'state'      => $state,
				'message'    => $message,
				'checked_at' => gmdate( 'c' ),
				'version'    => DEVENIA_MCP_UPDATER_VERSION,
			),
			$extra
		),
		false
	);
}

/**
 * Check whether a manifest package URL belongs to the Devenia package channel.
 *
 * @param string $package Package URL.
 * @return bool
 */
function devenia_mcp_updater_is_allowed_package_url( string $package ): bool {
	$host = wp_parse_url( $package, PHP_URL_HOST );
	$path = wp_parse_url( $package, PHP_URL_PATH );

	if ( ! is_string( $host ) || ! is_string( $path ) || '.zip' !== substr( $path, -4 ) ) {
		return false;
	}

	return 'downloads.devenia.com' === $host && 1 === preg_match( '#^/(?:artifacts/[a-z0-9-]+/[a-f0-9]{64}/)?[A-Za-z0-9._-]+\.zip$#', $path );
}

/**
 * Normalize a plugin manifest entry.
 *
 * @param mixed $entry Raw manifest entry.
 * @return array<string,mixed>|null
 */
function devenia_mcp_updater_normalize_entry( $entry ): ?array {
	if ( ! is_array( $entry ) ) {
		return null;
	}

	$slug    = isset( $entry['slug'] ) ? sanitize_key( (string) $entry['slug'] ) : '';
	$file    = isset( $entry['file'] ) ? sanitize_text_field( (string) $entry['file'] ) : '';
	$version = isset( $entry['version'] ) ? sanitize_text_field( (string) $entry['version'] ) : '';
	$package = isset( $entry['package'] ) ? esc_url_raw( (string) $entry['package'] ) : '';
	$sha256  = isset( $entry['sha256'] ) ? strtolower( preg_replace( '/[^a-f0-9]/', '', (string) $entry['sha256'] ) ) : '';

	if ( '' === $slug || '' === $file || '' === $version || '' === $package || 64 !== strlen( $sha256 ) ) {
		return null;
	}

	if ( ! devenia_mcp_updater_is_allowed_package_url( $package ) ) {
		return null;
	}
	$expected_package = sprintf( 'https://downloads.devenia.com/artifacts/%1$s/%2$s/%1$s.zip', $slug, $sha256 );
	if ( ! hash_equals( $expected_package, $package ) || 0 !== strpos( $file, $slug . '/' ) ) {
		return null;
	}

	$plugin_check = isset( $entry['pluginCheck'] ) && is_array( $entry['pluginCheck'] ) ? $entry['pluginCheck'] : array();
	$release_identity = isset( $entry['releaseIdentity'] ) && is_array( $entry['releaseIdentity'] ) ? $entry['releaseIdentity'] : array();
	$fingerprint = isset( $plugin_check['verificationFingerprint'] ) && is_array( $plugin_check['verificationFingerprint'] ) ? $plugin_check['verificationFingerprint'] : array();
	$strict_quality = 'strict-zero-finding' === (string) ( $plugin_check['qualityDecision'] ?? '' )
		&& 0 === (int) ( $plugin_check['errorCount'] ?? -1 )
		&& 0 === (int) ( $plugin_check['warningCount'] ?? -1 )
		&& empty( $plugin_check['policyException'] )
		&& 'wp-plugin-check-exit-zero-strict-json-or-native-empty-v1' === (string) ( $plugin_check['gateEvidence'] ?? '' );
	$exception_quality = 'approved-policy-exception' === (string) ( $plugin_check['qualityDecision'] ?? '' )
		&& devenia_mcp_updater_is_exact_quality_exception( $slug, $plugin_check['policyException'] ?? null );
	if (
		'passed' !== ( $plugin_check['status'] ?? '' ) ||
		$sha256 !== strtolower( (string) ( $plugin_check['sha256'] ?? '' ) ) ||
		( ! $strict_quality && ! $exception_quality ) ||
		$sha256 !== strtolower( (string) ( $fingerprint['packageSha256'] ?? '' ) ) ||
		$version !== (string) ( $fingerprint['pluginVersion'] ?? '' ) ||
		! devenia_mcp_updater_fingerprint_is_valid( $fingerprint ) ||
		$sha256 !== strtolower( (string) ( $release_identity['sha256'] ?? '' ) ) ||
		$version !== (string) ( $release_identity['version'] ?? '' ) ||
		$file !== (string) ( $release_identity['mainFile'] ?? '' )
		|| 1 !== (int) ( $release_identity['schemaVersion'] ?? 0 )
		|| $slug !== (string) ( $release_identity['slug'] ?? '' )
		|| ! devenia_mcp_updater_release_identity_provenance_is_valid( $release_identity )
	) {
		return null;
	}

	$entry['file']    = $file;
	$entry['version'] = $version;
	$entry['package'] = $package;
	$entry['sha256']  = $sha256;
	$entry['name']    = isset( $entry['name'] ) ? sanitize_text_field( (string) $entry['name'] ) : $file;

	return $entry;
}

/** Accept only the two centrally approved, exact Plugin Check exceptions. */
function devenia_mcp_updater_is_exact_quality_exception( string $slug, $exception ): bool {
	$approved = array(
		'devenia-mcp-updater' => array(
			'status' => 'private-infrastructure-waived',
			'reason' => 'This bootstrap plugin owns the authenticated private updater channel and is intentionally not a WordPress.org-hosted updater.',
			'allowedCodes' => array( 'plugin_updater_detected', 'update_modification_detected' ),
			'requiredDetected' => array( 'site_transient_update_plugins', 'auto_update_plugin', 'pre_set_site_transient_update_plugins' ),
		),
		'mcp-expose-abilities' => array(
			'status' => 'plugin-management-waived',
			'reason' => 'This authenticated MCP plugin intentionally exposes policy-gated plugin installation operations outside WordPress.org distribution.',
			'allowedCodes' => array( 'PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite' ),
			'requiredDetected' => array( 'unzip_file', 'copy_dir', 'WP_PLUGIN_DIR' ),
		),
	);
	if ( ! isset( $approved[ $slug ] ) || ! is_array( $exception ) ) {
		return false;
	}
	foreach ( $approved[ $slug ] as $key => $expected ) {
		$actual = $exception[ $key ] ?? null;
		if ( is_array( $expected ) ) {
			$actual = is_array( $actual ) ? array_values( $actual ) : array();
			sort( $actual );
			sort( $expected );
		}
		if ( $actual !== $expected ) {
			return false;
		}
	}
	return true;
}

/** Validate authenticated repository and member-manifest provenance fields. */
function devenia_mcp_updater_release_identity_provenance_is_valid( array $identity ): bool {
	$repository = is_array( $identity['repository'] ?? null ) ? $identity['repository'] : array();
	if ( '' === (string) ( $repository['remote'] ?? '' )
		|| 1 !== preg_match( '/^[a-f0-9]{40,64}$/', (string) ( $repository['commit'] ?? '' ) )
		|| 1 !== preg_match( '/^[a-f0-9]{40,64}$/', (string) ( $repository['tree'] ?? '' ) ) ) {
		return false;
	}
	$members = $identity['members'] ?? null;
	if ( ! is_array( $members ) || array() === $members || 1 !== preg_match( '/^[a-f0-9]{64}$/', (string) ( $identity['membersSha256'] ?? '' ) ) ) {
		return false;
	}
	foreach ( $members as $member ) {
		if ( ! is_array( $member ) || '' === (string) ( $member['path'] ?? '' ) || 0 > (int) ( $member['size'] ?? -1 ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', (string) ( $member['sha256'] ?? '' ) ) ) {
			return false;
		}
	}
	$canonical = (string) wp_json_encode( array_values( $members ), JSON_UNESCAPED_SLASHES );
	return hash_equals( hash( 'sha256', $canonical ), (string) $identity['membersSha256'] );
}

/** Validate the complete canonical Plugin Check runtime fingerprint. */
function devenia_mcp_updater_fingerprint_is_valid( array $fingerprint ): bool {
	foreach ( array( 'packageSha256', 'pluginVersion', 'pluginCheckVersion', 'wordpressVersion', 'phpVersion', 'digest' ) as $key ) {
		if ( '' === (string) ( $fingerprint[ $key ] ?? '' ) ) {
			return false;
		}
	}
	if ( 1 !== (int) ( $fingerprint['schemaVersion'] ?? 0 ) || ! is_array( $fingerprint['policy'] ?? null ) ) {
		return false;
	}
	$actual = strtolower( (string) $fingerprint['digest'] );
	unset( $fingerprint['digest'] );
	$canonicalize = static function ( $value ) use ( &$canonicalize ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			ksort( $value );
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = $canonicalize( $item );
		}
		return $value;
	};
	$expected = hash( 'sha256', (string) wp_json_encode( $canonicalize( $fingerprint ), JSON_UNESCAPED_SLASHES ) );
	return hash_equals( $expected, $actual );
}

/**
 * Return manifest plugins indexed by plugin file.
 *
 * @param bool $force_refresh Whether to bypass the transient cache.
 * @return array<string,array<string,mixed>>
 */
function devenia_mcp_updater_manifest_plugins( bool $force_refresh = false ): array {
	$manifest = devenia_mcp_updater_get_manifest( $force_refresh );
	if ( is_wp_error( $manifest ) ) {
		return array();
	}

	$plugins = array();
	$slugs = array();
	$packages = array();
	foreach ( $manifest['plugins'] as $entry ) {
		$normalized = devenia_mcp_updater_normalize_entry( $entry );
		if ( null !== $normalized ) {
			$slug = (string) $normalized['slug'];
			$package = (string) $normalized['package'];
			if ( isset( $plugins[ $normalized['file'] ] ) || isset( $slugs[ $slug ] ) || isset( $packages[ $package ] ) ) {
				return array();
			}
			$plugins[ $normalized['file'] ] = $normalized;
			$slugs[ $slug ] = true;
			$packages[ $package ] = true;
		}
	}

	return $plugins;
}

/**
 * Allow MCP Expose to update an exact manifest-gated package.
 *
 * MCP Expose owns only the neutral policy seam. This private updater Adapter
 * owns manifest, package, version, and update-offer identity.
 *
 * @param bool   $allowed     Existing policy decision.
 * @param string $plugin_file Canonical plugin file.
 */
function devenia_mcp_updater_allow_mcp_expose_plugin_update( bool $allowed, string $plugin_file ): bool {
	if ( $allowed ) {
		return true;
	}

	$manifest_plugins = devenia_mcp_updater_manifest_plugins( true );
	if ( ! isset( $manifest_plugins[ $plugin_file ] ) || ! is_array( $manifest_plugins[ $plugin_file ] ) ) {
		return false;
	}

	$manifest_entry = $manifest_plugins[ $plugin_file ];
	if ( empty( $manifest_entry['autoUpdate'] ) ) {
		return false;
	}

	$manifest_package = (string) ( $manifest_entry['package'] ?? '' );
	$manifest_version = (string) ( $manifest_entry['version'] ?? '' );
	if ( '' === $manifest_package || '' === $manifest_version ) {
		return false;
	}

	wp_clean_plugins_cache( true );
	wp_update_plugins();
	$updates = get_site_transient( 'update_plugins' );
	if ( ! is_object( $updates ) || empty( $updates->response ) || ! is_array( $updates->response ) || ! isset( $updates->response[ $plugin_file ] ) ) {
		return false;
	}

	$update_item = $updates->response[ $plugin_file ];
	return hash_equals( $manifest_package, (string) ( $update_item->package ?? '' ) )
		&& hash_equals( $manifest_version, (string) ( $update_item->new_version ?? '' ) );
}
add_filter( 'mcp_expose_plugin_update_allowed_by_policy', 'devenia_mcp_updater_allow_mcp_expose_plugin_update', 10, 2 );

/**
 * Load WordPress plugin-management helpers when they are not already loaded.
 *
 * @return void
 */
function devenia_mcp_updater_require_plugin_helpers(): void {
	if ( ! function_exists( 'get_plugins' ) || ! function_exists( 'is_plugin_active' ) || ! function_exists( 'activate_plugin' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	if ( ! function_exists( 'delete_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
}

/**
 * Find stale duplicate folders for manifest-managed plugins.
 *
 * A stale duplicate is a plugin installed in a folder like `slug-master` with
 * the same main plugin filename as the canonical manifest entry. The updater
 * never treats arbitrary plugin folders as stale.
 *
 * @param array<string,array<string,string>> $installed Installed plugins from get_plugins().
 * @param array<string,array<string,mixed>>  $manifest_plugins Manifest plugins indexed by canonical plugin file.
 * @return array<int,array<string,string>>
 */
function devenia_mcp_updater_find_legacy_duplicates( array $installed, array $manifest_plugins ): array {
	$duplicates = array();

	foreach ( $manifest_plugins as $canonical_file => $entry ) {
		if ( ! isset( $installed[ $canonical_file ] ) ) {
			continue;
		}

		$canonical_dir      = dirname( $canonical_file );
		$canonical_basename = basename( $canonical_file );
		$legacy_prefix      = $canonical_dir . '-';

		foreach ( $installed as $installed_file => $plugin_data ) {
			if ( $canonical_file === $installed_file ) {
				continue;
			}

			$installed_dir      = dirname( $installed_file );
			$installed_basename = basename( $installed_file );

			if ( $canonical_basename !== $installed_basename ) {
				continue;
			}

			if ( 0 !== strpos( $installed_dir, $legacy_prefix ) ) {
				continue;
			}

			$duplicates[] = array(
				'canonical' => $canonical_file,
				'legacy'    => $installed_file,
			);
		}
	}

	return $duplicates;
}

/**
 * Mark a canonical plugin file active without loading its PHP in this request.
 *
 * This is only used when replacing an already-loaded duplicate plugin copy.
 * Calling activate_plugin() in that situation can load a second copy of the
 * same functions and fatally redeclare them.
 *
 * @param string $canonical_file Canonical manifest plugin file.
 * @return bool
 */
function devenia_mcp_updater_mark_plugin_active_for_next_request( string $canonical_file ): bool {
	$active_plugins = (array) get_option( 'active_plugins', array() );
	if ( in_array( $canonical_file, $active_plugins, true ) ) {
		return true;
	}

	$active_plugins[] = $canonical_file;
	$active_plugins   = array_values( array_unique( $active_plugins ) );

	return update_option( 'active_plugins', $active_plugins );
}

/**
 * Reconcile stale duplicate folders against the manifest's canonical plugin files.
 *
 * If a stale duplicate is active, it is deactivated before the canonical plugin
 * is marked active for the next request. The canonical plugin is not loaded in
 * the current request because the duplicate copy may already have declared the
 * same PHP functions.
 *
 * @param bool $force Whether to ignore the reconciliation throttle.
 * @return array<string,mixed>
 */
function devenia_mcp_updater_reconcile_legacy_duplicates( bool $force = false ): array {
	if ( ! $force && get_site_transient( DEVENIA_MCP_UPDATER_LEGACY_RECONCILE_TRANSIENT ) ) {
		return array(
			'checked' => false,
			'reason'  => 'throttled',
		);
	}

	devenia_mcp_updater_require_plugin_helpers();

	$manifest_plugins = devenia_mcp_updater_manifest_plugins();
	if ( array() === $manifest_plugins ) {
		return array(
			'checked' => true,
			'reason'  => 'empty_manifest',
		);
	}

	$installed  = get_plugins();
	$duplicates = devenia_mcp_updater_find_legacy_duplicates( $installed, $manifest_plugins );
	$removed    = array();
	$activated  = array();
	$errors     = array();

	foreach ( $duplicates as $duplicate ) {
		$canonical_file = $duplicate['canonical'];
		$legacy_file    = $duplicate['legacy'];
		$legacy_active  = is_plugin_active( $legacy_file );

		if ( $legacy_active ) {
			deactivate_plugins( $legacy_file, true );
		}

		if ( is_plugin_active( $legacy_file ) ) {
			$errors[] = array(
				'plugin'  => $legacy_file,
				'action'  => 'deactivate_legacy',
				'message' => 'Legacy plugin remained active after deactivation attempt.',
			);
			continue;
		}

		if ( $legacy_active && ! is_plugin_active( $canonical_file ) ) {
			if ( ! devenia_mcp_updater_mark_plugin_active_for_next_request( $canonical_file ) ) {
				$errors[] = array(
					'plugin'  => $legacy_file,
					'action'  => 'mark_canonical_active',
					'message' => 'Could not mark canonical plugin active for the next request.',
				);
				continue;
			}

			$activated[] = $canonical_file;
		}

		$deleted = delete_plugins( array( $legacy_file ) );
		if ( is_wp_error( $deleted ) ) {
			$errors[] = array(
				'plugin'  => $legacy_file,
				'action'  => 'delete_legacy',
				'message' => $deleted->get_error_message(),
			);
			continue;
		}

		$removed[] = $legacy_file;
	}

	set_site_transient( DEVENIA_MCP_UPDATER_LEGACY_RECONCILE_TRANSIENT, 1, HOUR_IN_SECONDS );

	if ( array() !== $removed || array() !== $activated || array() !== $errors ) {
		devenia_mcp_updater_record_status(
			array() === $errors ? 'legacy_reconciled' : 'legacy_reconcile_error',
			array() === $errors ? 'Stale manifest-managed plugin folders were reconciled.' : 'Some stale manifest-managed plugin folders could not be reconciled.',
			array(
				'legacy_removed'      => $removed,
				'canonical_activated' => array_values( array_unique( $activated ) ),
				'legacy_errors'       => $errors,
			)
		);
	}

	return array(
		'checked'             => true,
		'duplicates_detected' => count( $duplicates ),
		'legacy_removed'      => $removed,
		'canonical_activated' => array_values( array_unique( $activated ) ),
		'errors'              => $errors,
	);
}

/**
 * Reconcile stale duplicate folders after plugin installs or updates.
 *
 * @param WP_Upgrader $upgrader Upgrader instance.
 * @param array       $hook_extra Upgrader hook context.
 * @return void
 */
function devenia_mcp_updater_after_plugin_upgrade( $upgrader, array $hook_extra ): void {
	if ( 'plugin' !== ( $hook_extra['type'] ?? '' ) ) {
		return;
	}

	devenia_mcp_updater_reconcile_legacy_duplicates( true );
	devenia_mcp_updater_record_rollout_receipts( $hook_extra );
}
add_action( 'upgrader_process_complete', 'devenia_mcp_updater_after_plugin_upgrade', 10, 2 );

/** Capture per-site prior identity and activation before WordPress replaces files. */
function devenia_mcp_updater_capture_preinstall( $response, array $hook_extra ) {
	if ( 'plugin' !== (string) ( $hook_extra['type'] ?? '' ) ) {
		return $response;
	}
	devenia_mcp_updater_require_plugin_helpers();
	$plugin_file = (string) ( $hook_extra['plugin'] ?? '' );
	$installed   = get_plugins();
	$managed     = devenia_mcp_updater_manifest_plugins();
	if ( '' !== $plugin_file && isset( $installed[ $plugin_file ], $managed[ $plugin_file ] ) ) {
		$prior = array(
			'version' => (string) ( $installed[ $plugin_file ]['Version'] ?? '' ),
			'active'  => is_plugin_active( $plugin_file ),
			'manifestEntry' => $managed[ $plugin_file ],
			'capturedAt' => gmdate( 'c' ),
		);
		$GLOBALS['devenia_mcp_updater_rollout_prior'][ $plugin_file ] = $prior;
		$pending = get_option( DEVENIA_MCP_UPDATER_ROLLOUT_PENDING_OPTION, array() );
		$pending = is_array( $pending ) ? $pending : array();
		$pending[ $plugin_file ] = $prior;
		update_option( DEVENIA_MCP_UPDATER_ROLLOUT_PENDING_OPTION, $pending, false );
	}
	return $response;
}
add_filter( 'upgrader_pre_install', 'devenia_mcp_updater_capture_preinstall', 10, 2 );

/** Record terminal rollout identity and health after a managed plugin update. */
function devenia_mcp_updater_record_rollout_receipts( array $hook_extra ): void {
	devenia_mcp_updater_require_plugin_helpers();
	$changed = array();
	if ( isset( $hook_extra['plugin'] ) && is_string( $hook_extra['plugin'] ) ) {
		$changed[] = $hook_extra['plugin'];
	}
	if ( isset( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
		$changed = array_merge( $changed, array_map( 'strval', $hook_extra['plugins'] ) );
	}
	$changed   = array_values( array_unique( $changed ) );
	$installed = get_plugins();
	$pending   = get_option( DEVENIA_MCP_UPDATER_ROLLOUT_PENDING_OPTION, array() );
	$pending   = is_array( $pending ) ? $pending : array();
	$health_response = wp_remote_get(
		add_query_arg( 'devenia_rollout_health', (string) time(), home_url( '/' ) ),
		array( 'timeout' => 10, 'redirection' => 2, 'headers' => array( 'Cache-Control' => 'no-cache' ) )
	);
	$health_code = is_wp_error( $health_response ) ? 0 : (int) wp_remote_retrieve_response_code( $health_response );
	$site_healthy = 200 <= $health_code && 400 > $health_code;
	$receipts  = get_option( DEVENIA_MCP_UPDATER_ROLLOUT_RECEIPTS_OPTION, array() );
	$receipts  = is_array( $receipts ) ? $receipts : array();
	foreach ( $changed as $plugin_file ) {
		$prior = is_array( $GLOBALS['devenia_mcp_updater_rollout_prior'][ $plugin_file ] ?? null )
			? $GLOBALS['devenia_mcp_updater_rollout_prior'][ $plugin_file ]
			: ( is_array( $pending[ $plugin_file ] ?? null ) ? $pending[ $plugin_file ] : array() );
		$entry = is_array( $prior['manifestEntry'] ?? null ) ? $prior['manifestEntry'] : array();
		if ( empty( $entry ) ) {
			continue;
		}
		$actual_version = (string) ( $installed[ $plugin_file ]['Version'] ?? '' );
		$active         = is_plugin_active( $plugin_file );
		$version_ok     = '' !== $actual_version && hash_equals( (string) $entry['version'], $actual_version );
		$prior_captured = array_key_exists( 'active', $prior ) && '' !== (string) ( $prior['version'] ?? '' );
		$activation_preserved = $prior_captured && (bool) $prior['active'] === $active;
		$receipt = array(
			'schemaVersion'   => 1,
			'site'            => home_url( '/' ),
			'plugin'          => $plugin_file,
			'priorVersion'    => (string) ( $prior['version'] ?? '' ),
			'newVersion'      => (string) $entry['version'],
			'actualVersion'   => $actual_version,
			'packageSha256'   => (string) $entry['sha256'],
			'active'          => $active,
			'priorActive'     => array_key_exists( 'active', $prior ) ? (bool) $prior['active'] : null,
			'activationPreserved' => $activation_preserved,
			'priorStateCaptured' => $prior_captured,
			'health'          => $version_ok && $prior_captured && $activation_preserved && $site_healthy ? 'plugin_identity_activation_and_live_site_health_passed' : 'prior_version_activation_or_live_site_invariant_failed',
			'healthHttpStatus' => $health_code,
			'rollback'        => is_array( $entry['rollback'] ?? null ) ? $entry['rollback'] : array( 'available' => false ),
			'status'          => $version_ok && $prior_captured && $activation_preserved && $site_healthy ? 'passed' : 'failed',
			'completedAt'     => gmdate( 'c' ),
		);
		$receipts[] = $receipt;
		unset( $pending[ $plugin_file ], $GLOBALS['devenia_mcp_updater_rollout_prior'][ $plugin_file ] );
		devenia_mcp_updater_record_status( 'passed' === $receipt['status'] ? 'rollout_passed' : 'rollout_failed', 'Terminal managed-plugin rollout receipt recorded.', array( 'rollout' => $receipt ) );
	}
	update_option( DEVENIA_MCP_UPDATER_ROLLOUT_RECEIPTS_OPTION, array_slice( $receipts, -100 ), false );
	update_option( DEVENIA_MCP_UPDATER_ROLLOUT_PENDING_OPTION, $pending, false );
}

/**
 * Add private MCP plugin updates to WordPress' normal plugin update transient.
 *
 * @param object|mixed $transient Plugin update transient.
 * @return object|mixed
 */
function devenia_mcp_updater_filter_update_plugins( $transient ) {
	if ( ! is_object( $transient ) ) {
		return $transient;
	}

	if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
		$transient->response = array();
	}

	if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
		$transient->no_update = array();
	}

	$installed = get_plugins();
	$manifest_plugins = devenia_mcp_updater_manifest_plugins();
	$updates = 0;

	foreach ( $manifest_plugins as $file => $entry ) {
		if ( ! isset( $installed[ $file ] ) ) {
			continue;
		}

		$current_version = $installed[ $file ]['Version'] ?? '0';
		$item = (object) array(
			'id'          => $file,
			'slug'        => dirname( $file ),
			'plugin'      => $file,
			'new_version' => $entry['version'],
			'url'         => $entry['homepage'] ?? 'https://devenia.com',
			'package'     => $entry['package'],
			'icons'       => array(),
		);

		if ( version_compare( $current_version, $entry['version'], '<' ) ) {
			$transient->response[ $file ] = $item;
			unset( $transient->no_update[ $file ] );
			$updates++;
		} else {
			$transient->no_update[ $file ] = $item;
		}
	}

	devenia_mcp_updater_record_status(
		0 === $updates ? 'synced' : 'updates_available',
		0 === $updates ? 'MCP plugins are in sync.' : $updates . ' MCP plugin update(s) available.',
		array( 'updates_available' => $updates )
	);

	return $transient;
}
add_filter( 'pre_set_site_transient_update_plugins', 'devenia_mcp_updater_filter_update_plugins' );

/**
 * Enable unattended auto-updates for manifest-managed MCP plugins.
 *
 * @param bool|null $update Whether to auto-update.
 * @param object    $item   Update item.
 * @return bool|null
 */
function devenia_mcp_updater_auto_update_plugin( $update, $item ) {
	$plugin = isset( $item->plugin ) ? (string) $item->plugin : '';
	$manifest_plugins = devenia_mcp_updater_manifest_plugins();

	if ( isset( $manifest_plugins[ $plugin ] ) && ! empty( $manifest_plugins[ $plugin ]['autoUpdate'] ) ) {
		return true;
	}

	return $update;
}
add_filter( 'auto_update_plugin', 'devenia_mcp_updater_auto_update_plugin', 10, 2 );

/**
 * Verify private package hashes before WordPress installs them.
 *
 * @param false|WP_Error|string $reply   Existing response.
 * @param string                $package Package URL.
 * @return false|WP_Error|string
 */
function devenia_mcp_updater_verify_download( $reply, string $package ) {
	if ( false !== $reply ) {
		return $reply;
	}

	$entry = null;
	foreach ( devenia_mcp_updater_manifest_plugins() as $candidate ) {
		if ( $candidate['package'] === $package ) {
			$entry = $candidate;
			break;
		}
	}

	if ( null === $entry ) {
		return false;
	}

	if ( ! function_exists( 'download_url' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	$tmp_file = download_url( $package, 60 );
	if ( is_wp_error( $tmp_file ) ) {
		devenia_mcp_updater_record_status( 'download_error', $tmp_file->get_error_message(), array( 'plugin' => $entry['file'] ) );
		return $tmp_file;
	}

	$actual_hash = hash_file( 'sha256', $tmp_file );
	if ( ! hash_equals( $entry['sha256'], $actual_hash ) ) {
		wp_delete_file( $tmp_file );
		$error = new WP_Error( 'devenia_mcp_hash_mismatch', 'Private MCP package hash mismatch.' );
		devenia_mcp_updater_record_status( 'hash_mismatch', 'Package hash mismatch.', array( 'plugin' => $entry['file'] ) );
		return $error;
	}

	return $tmp_file;
}
add_filter( 'upgrader_pre_download', 'devenia_mcp_updater_verify_download', 10, 2 );

/**
 * Provide plugin details in the WP update modal.
 *
 * @param false|object|array $result Existing plugin info.
 * @param string            $action Plugin info action.
 * @param object            $args   Plugin info args.
 * @return false|object|array
 */
function devenia_mcp_updater_plugins_api( $result, string $action, $args ) {
	if ( 'plugin_information' !== $action || empty( $args->slug ) ) {
		return $result;
	}

	foreach ( devenia_mcp_updater_manifest_plugins() as $entry ) {
		if ( dirname( $entry['file'] ) !== $args->slug ) {
			continue;
		}

		return (object) array(
			'name'          => $entry['name'],
			'slug'          => dirname( $entry['file'] ),
			'version'       => $entry['version'],
			'author'        => 'Devenia',
			'homepage'      => $entry['homepage'] ?? 'https://devenia.com',
			'download_link' => $entry['package'],
			'sections'      => array(
				'description' => 'Private Devenia MCP update package.',
				'changelog'   => $entry['changelog'] ?? 'Managed by Devenia MCP Updater.',
			),
		);
	}

	return $result;
}
add_filter( 'plugins_api', 'devenia_mcp_updater_plugins_api', 10, 3 );

/**
 * Force a fresh private update check.
 *
 * @return void
 */
function devenia_mcp_updater_refresh(): void {
	delete_site_transient( DEVENIA_MCP_UPDATER_TRANSIENT );
	delete_site_transient( 'update_plugins' );
	delete_site_transient( DEVENIA_MCP_UPDATER_LEGACY_RECONCILE_TRANSIENT );
	wp_update_plugins();
	devenia_mcp_updater_reconcile_legacy_duplicates( true );
}

register_activation_hook( __FILE__, 'devenia_mcp_updater_refresh' );
