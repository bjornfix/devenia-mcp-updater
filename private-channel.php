<?php
/** Authenticated private delivery through the native WordPress HTTP/upgrader Interfaces.
 *
 * @package Devenia_MCP_Updater
 */

defined( 'ABSPATH' ) || exit;

/** Read the explicitly provisioned site scope without enrolling other sites.
 *
 * @return array|null
 */
function devenia_mcp_updater_private_config(): ?array {
	if ( ! defined( 'DEVENIA_MCP_UPDATER_PRIVATE_TOKEN' ) || ! defined( 'DEVENIA_MCP_UPDATER_PRIVATE_SITE' ) || ! defined( 'DEVENIA_MCP_UPDATER_PRIVATE_PLUGINS' ) ) {
		return null;
	}
	$token = DEVENIA_MCP_UPDATER_PRIVATE_TOKEN;
	$site = rtrim( (string) DEVENIA_MCP_UPDATER_PRIVATE_SITE, '/' );
	$files = DEVENIA_MCP_UPDATER_PRIVATE_PLUGINS;
	if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{64}$/D', $token ) || $site !== rtrim( home_url(), '/' ) || ! is_array( $files ) || empty( $files ) ) {
		return null;
	}
	foreach ( $files as $file ) {
		if ( ! is_string( $file ) || ! preg_match( '#^[a-z0-9-]+/[a-zA-Z0-9._-]+\.php$#D', $file ) ) {
			return null;
		}
	}
	return array( 'site' => $site, 'token' => $token, 'files' => array_values( array_unique( $files ) ) );
}

/** Exact private route; never a public package or caller-provided host.
 *
 * @param string $slug Plugin slug.
 * @param string $sha256 Qualified package digest.
 * @return string
 */
function devenia_mcp_updater_private_package_url( string $slug, string $sha256 ): string {
	return sprintf( 'https://downloads.devenia.com/private-updates/v1/packages/%1$s/%2$s/%1$s.zip', $slug, $sha256 );
}

/** Get a fresh, site-bound signed private inventory; revocation is not cached across requests.
 *
 * @return array
 */
function devenia_mcp_updater_private_plugins(): array {
	$config = devenia_mcp_updater_private_config();
	if ( null === $config ) {
		return array();
	}
	$response = wp_remote_get(
		'https://downloads.devenia.com/private-updates/v1/manifest',
		array(
			'timeout' => 15,
			'redirection' => 0,
			'headers' => array( 'Accept' => 'application/json', 'Authorization' => 'Bearer ' . $config['token'], 'X-Devenia-Site' => $config['site'] ),
		)
	);
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		devenia_mcp_updater_record_status( 'private_manifest_error', 'Private update information is unavailable.' );
		return array();
	}
	$manifest = devenia_mcp_updater_verified_manifest_payload( json_decode( wp_remote_retrieve_body( $response ), true ) );
	if ( is_wp_error( $manifest ) || 'private' !== ( $manifest['channel'] ?? '' ) || $config['site'] !== ( $manifest['site'] ?? '' ) ) {
		return array();
	}
	$plugins = array();
	foreach ( $manifest['plugins'] as $entry ) {
		$normalized = devenia_mcp_updater_normalize_entry( $entry, true );
		if ( null === $normalized || ! in_array( $normalized['file'], $config['files'], true ) || isset( $plugins[ $normalized['file'] ] ) ) {
			return array();
		}
		$normalized['private'] = true;
		$plugins[ $normalized['file'] ] = $normalized;
	}
	return $plugins;
}

/** Download an exact authorized private package without credentials in URLs or redirects.
 *
 * @param array $entry Validated private manifest entry.
 * @return string|WP_Error
 */
function devenia_mcp_updater_private_download( array $entry ) {
	$config = devenia_mcp_updater_private_config();
	if ( null === $config || ! in_array( $entry['file'], $config['files'], true ) || $entry['package'] !== devenia_mcp_updater_private_package_url( $entry['slug'], $entry['sha256'] ) ) {
		return new WP_Error( 'devenia_private_scope', 'Private package access is not configured for this site.' );
	}
	$tmp = wp_tempnam( $entry['slug'] . '.zip' );
	if ( ! $tmp ) {
		return new WP_Error( 'devenia_private_temp', 'Cannot prepare the private package download.' );
	}
	$response = wp_remote_get(
		$entry['package'],
		array(
			'timeout' => 60,
			'redirection' => 0,
			'stream' => true,
			'filename' => $tmp,
			'headers' => array( 'Authorization' => 'Bearer ' . $config['token'], 'X-Devenia-Site' => $config['site'] ),
		)
	);
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		wp_delete_file( $tmp );
		return new WP_Error( 'devenia_private_download', 'Private package download was refused or unavailable.' );
	}
	return devenia_mcp_updater_verify_package_file( $entry, $tmp );
}
