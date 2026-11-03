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

/** Exercise native package downloads without inventing an installed update version.
 *
 * @return array
 */
function devenia_mcp_updater_verify_private_sources(): array {
	if ( ! class_exists( 'WP_Upgrader' ) ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	}
	$results = array();
	$upgrader = new WP_Upgrader( new Automatic_Upgrader_Skin() );
	foreach ( devenia_mcp_updater_private_plugins() as $file => $entry ) {
		$package = $upgrader->download_package( $entry['package'], false, array( 'type' => 'plugin', 'plugin' => $file ) );
		$result = array( 'file' => $file, 'version' => $entry['version'], 'sha256' => $entry['sha256'], 'status' => 'failed' );
		if ( is_wp_error( $package ) ) {
			$result['error'] = $package->get_error_code();
		} else {
			$result['status'] = 'passed';
			$result['bytes'] = filesize( $package );
			wp_delete_file( $package );
		}
		$results[] = $result;
	}
	$config = devenia_mcp_updater_private_config();
	$missing = array_values( array_diff( $config['files'] ?? array(), array_column( $results, 'file' ) ) );
	$passed = ! empty( $results ) && empty( $missing ) && count( array_filter( $results, static function ( $row ) { return 'passed' !== $row['status']; } ) ) === 0;
	return array( 'checkedAt' => gmdate( 'c' ), 'status' => $passed ? 'passed' : 'failed', 'missingFiles' => $missing, 'results' => $results );
}

/** Add a bounded native check only on a site that is explicitly configured.
 *
 * @return void
 */
function devenia_mcp_updater_private_admin_menu(): void {
	if ( null !== devenia_mcp_updater_private_config() ) {
		add_management_page( __( 'Private plugin updates', 'devenia-mcp-updater' ), __( 'Private updates', 'devenia-mcp-updater' ), 'update_plugins', 'devenia-private-updates', 'devenia_mcp_updater_private_admin_page' );
	}
}
add_action( 'admin_menu', 'devenia_mcp_updater_private_admin_menu' );

/** Render private source state and a native download verification action.
 *
 * @return void
 */
function devenia_mcp_updater_private_admin_page(): void {
	if ( ! current_user_can( 'update_plugins' ) ) {
		return;
	}
	$installed = get_plugins();
	echo '<div class="wrap"><h1>' . esc_html__( 'Private plugin updates', 'devenia-mcp-updater' ) . '</h1>';
	echo '<p>' . esc_html__( 'Verify the configured private sources and download their qualified packages without installing them.', 'devenia-mcp-updater' ) . '</p><ul>';
	$entries = devenia_mcp_updater_private_plugins();
	if ( empty( $entries ) ) {
		echo '<li>' . esc_html__( 'No authorized private release information is available.', 'devenia-mcp-updater' ) . '</li>';
	}
	foreach ( $entries as $file => $entry ) {
		echo '<li>' . esc_html( $file . ' — ' . __( 'Qualified', 'devenia-mcp-updater' ) . ': ' . $entry['version'] . ' / ' . __( 'Installed', 'devenia-mcp-updater' ) . ': ' . ( $installed[ $file ]['Version'] ?? '' ) ) . '</li>';
	}
	echo '</ul><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="devenia_verify_private_updates">';
	wp_nonce_field( 'devenia_verify_private_updates' );
	submit_button( __( 'Verify private downloads', 'devenia-mcp-updater' ) );
	echo '</form>';
	$report = get_option( 'devenia_mcp_updater_private_verification', array() );
	if ( ! empty( $report['checkedAt'] ) ) {
		echo '<p>' . esc_html( $report['checkedAt'] . ' — ' . ( $report['status'] ?? 'failed' ) ) . '</p><ul>';
		foreach ( $report['results'] ?? array() as $result ) {
			echo '<li>' . esc_html( $result['file'] . ': ' . $result['status'] . ' / ' . (string) ( $result['bytes'] ?? 0 ) . ' bytes' ) . '</li>';
		}
		echo '</ul>';
	}
	echo '</div>';
}

/** Verify authorized sources through WordPress; no arbitrary URL or plugin input.
 *
 * @return void
 */
function devenia_mcp_updater_private_admin_verify(): void {
	if ( ! current_user_can( 'update_plugins' ) || null === devenia_mcp_updater_private_config() ) {
		wp_die( esc_html__( 'Private update access is not configured.', 'devenia-mcp-updater' ) );
	}
	check_admin_referer( 'devenia_verify_private_updates' );
	update_option( 'devenia_mcp_updater_private_verification', devenia_mcp_updater_verify_private_sources(), false );
	wp_safe_redirect( admin_url( 'tools.php?page=devenia-private-updates' ) );
	exit;
}
add_action( 'admin_post_devenia_verify_private_updates', 'devenia_mcp_updater_private_admin_verify' );
