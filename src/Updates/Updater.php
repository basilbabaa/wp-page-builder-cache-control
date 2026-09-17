<?php
/**
 * Self-hosted updates via GitHub releases.
 *
 * Uses the `update_plugins_{$hostname}` filter WordPress has provided since 5.8,
 * so there is no update library to vendor and nothing to keep in step with core.
 * WordPress calls us when it refreshes update data; we answer with the newest
 * release, and its normal update flow does the rest.
 *
 * The repository must be public. Release assets on a private repository need an
 * access token on every site, which is precisely the distribution problem this
 * avoids.
 *
 * @package PageBuilderCacheControl
 */

declare( strict_types=1 );

namespace PageBuilderCacheControl\Updates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Updater {

	private const API = 'https://api.github.com/repos/%s/releases/latest';

	private const TRANSIENT = 'pbcc_latest_release';

	/**
	 * GitHub rate-limits unauthenticated calls per IP. Sites sharing a server
	 * share that budget, so cache generously - update checks are twice daily and
	 * a few hours of staleness costs nothing.
	 */
	private const CACHE = 6 * HOUR_IN_SECONDS;

	public function register(): void {
		$host = $this->update_host();

		if ( '' === $host ) {
			return;
		}

		add_filter( 'update_plugins_' . $host, array( $this, 'check' ), 10, 3 );
	}

	/**
	 * Hostname from the plugin's own Update URI header, so the header stays the
	 * single source of truth for where updates come from.
	 */
	private function update_host(): string {
		$uri = $this->update_uri();

		if ( '' === $uri ) {
			return '';
		}

		return (string) wp_parse_url( $uri, PHP_URL_HOST );
	}

	private function update_uri(): string {
		if ( ! function_exists( 'get_file_data' ) ) {
			return '';
		}

		$data = get_file_data( PBCC_FILE, array( 'uri' => 'Update URI' ) );

		return isset( $data['uri'] ) ? trim( (string) $data['uri'] ) : '';
	}

	/**
	 * "owner/repo" parsed out of the Update URI.
	 */
	private function repo(): string {
		$path = (string) wp_parse_url( $this->update_uri(), PHP_URL_PATH );
		$bits = array_values( array_filter( explode( '/', $path ) ) );

		return count( $bits ) >= 2 ? $bits[0] . '/' . $bits[1] : '';
	}

	/**
	 * Answer WordPress's update query.
	 *
	 * Every plugin whose Update URI shares this hostname hits the same filter,
	 * so the first job is to confirm the question is about us and otherwise hand
	 * back whatever another plugin already decided.
	 *
	 * @param array|false $update      Update data from a previous filter.
	 * @param array       $plugin_data Plugin headers.
	 * @param string      $plugin_file Plugin basename being checked.
	 * @return array|false
	 */
	public function check( $update, array $plugin_data, string $plugin_file ) {
		if ( plugin_basename( PBCC_FILE ) !== $plugin_file ) {
			return $update;
		}

		$release = $this->latest_release();

		if ( null === $release ) {
			return $update;
		}

		if ( ! version_compare( $release['version'], PBCC_VERSION, '>' ) ) {
			return false;
		}

		return array(
			'id'      => $this->update_uri(),
			'slug'    => dirname( plugin_basename( PBCC_FILE ) ),
			'version' => $release['version'],
			'url'     => $release['url'],
			'package' => $release['package'],
			'tested'  => isset( $plugin_data['Tested up to'] ) ? $plugin_data['Tested up to'] : '',
		);
	}

	/**
	 * @return array{version:string,package:string,url:string}|null
	 */
	private function latest_release(): ?array {
		$cached = get_site_transient( self::TRANSIENT );

		if ( is_array( $cached ) ) {
			return empty( $cached['version'] ) ? null : $cached;
		}

		$repo = $this->repo();

		if ( '' === $repo ) {
			return null;
		}

		$response = wp_remote_get(
			sprintf( self::API, $repo ),
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'wp-page-builder-cache-control',
				),
			)
		);

		$release = $this->parse( $response );

		// Cache failures too, as an empty marker. Without this a repo that is
		// unreachable is re-fetched on every update check, on every site.
		set_site_transient( self::TRANSIENT, null === $release ? array() : $release, self::CACHE );

		return $release;
	}

	/**
	 * @param array|\WP_Error $response Raw HTTP response.
	 * @return array{version:string,package:string,url:string}|null
	 */
	private function parse( $response ): ?array {
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			return null;
		}

		$package = $this->zip_asset( $body );

		// Deliberately not falling back to zipball_url: GitHub names that archive
		// after the commit, so WordPress would install the plugin into a folder
		// like owner-repo-a1b2c3 and treat it as a different plugin.
		if ( '' === $package ) {
			return null;
		}

		return array(
			'version' => ltrim( (string) $body['tag_name'], 'vV' ),
			'package' => $package,
			'url'     => isset( $body['html_url'] ) ? (string) $body['html_url'] : '',
		);
	}

	/**
	 * @param array<string,mixed> $body Decoded release payload.
	 */
	private function zip_asset( array $body ): string {
		if ( empty( $body['assets'] ) || ! is_array( $body['assets'] ) ) {
			return '';
		}

		foreach ( $body['assets'] as $asset ) {
			if ( ! is_array( $asset ) || empty( $asset['browser_download_url'] ) ) {
				continue;
			}

			if ( '.zip' === substr( (string) $asset['browser_download_url'], -4 ) ) {
				return (string) $asset['browser_download_url'];
			}
		}

		return '';
	}

	/**
	 * Drop the cached release, so a check right after publishing sees it.
	 */
	public static function forget(): void {
		delete_site_transient( self::TRANSIENT );
	}
}
