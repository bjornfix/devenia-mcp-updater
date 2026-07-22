<?php
/**
 * Plugin Name: Devenia MCP Updater
 * Plugin URI: https://devenia.com
 * Description: Private update channel and automatic sync for Devenia MCP and Abilities plugins.
 * Version: 0.1.7
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

define( 'DEVENIA_MCP_UPDATER_VERSION', '0.1.7' );
define( 'DEVENIA_MCP_UPDATER_MANIFEST_URL', 'https://downloads.devenia.com/devenia-mcp-manifest.json' );
define( 'DEVENIA_MCP_UPDATER_TRANSIENT', 'devenia_mcp_updater_manifest_v1' );
define( 'DEVENIA_MCP_UPDATER_STATUS_OPTION', 'devenia_mcp_updater_status' );
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

	$manifest = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $manifest ) || ! isset( $manifest['plugins'] ) || ! is_array( $manifest['plugins'] ) ) {
		$error = new WP_Error( 'devenia_mcp_manifest_invalid', 'Manifest JSON is invalid.' );
		devenia_mcp_updater_record_status( 'manifest_error', 'Manifest JSON is invalid' );
		return $error;
	}

	set_site_transient( DEVENIA_MCP_UPDATER_TRANSIENT, $manifest, 30 * MINUTE_IN_SECONDS );
	return $manifest;
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

	return 'downloads.devenia.com' === $host && 1 === preg_match( '#^/[A-Za-z0-9._-]+\.zip$#', $path );
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

	$file    = isset( $entry['file'] ) ? sanitize_text_field( (string) $entry['file'] ) : '';
	$version = isset( $entry['version'] ) ? sanitize_text_field( (string) $entry['version'] ) : '';
	$package = isset( $entry['package'] ) ? esc_url_raw( (string) $entry['package'] ) : '';
	$sha256  = isset( $entry['sha256'] ) ? strtolower( preg_replace( '/[^a-f0-9]/', '', (string) $entry['sha256'] ) ) : '';

	if ( '' === $file || '' === $version || '' === $package || '' === $sha256 ) {
		return null;
	}

	if ( ! devenia_mcp_updater_is_allowed_package_url( $package ) ) {
		return null;
	}

	$plugin_check = isset( $entry['pluginCheck'] ) && is_array( $entry['pluginCheck'] ) ? $entry['pluginCheck'] : array();
	if (
		'passed' !== ( $plugin_check['status'] ?? '' ) ||
		$sha256 !== strtolower( (string) ( $plugin_check['sha256'] ?? '' ) )
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
	foreach ( $manifest['plugins'] as $entry ) {
		$normalized = devenia_mcp_updater_normalize_entry( $entry );
		if ( null !== $normalized ) {
			$plugins[ $normalized['file'] ] = $normalized;
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
}
add_action( 'upgrader_process_complete', 'devenia_mcp_updater_after_plugin_upgrade', 10, 2 );

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
