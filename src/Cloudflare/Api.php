<?php
/**
 * Minimal Cloudflare API client.
 *
 * Scope is deliberately tiny: resolve this site's zone, and purge. The API token
 * only needs Zone -> Cache Purge -> Purge, so a leak is a nuisance rather than a
 * compromise. Never log or return the token.
 *
 * @package PageBuilderCacheControl
 */

declare( strict_types=1 );

namespace PageBuilderCacheControl\Cloudflare;

use PageBuilderCacheControl\Settings;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Api {

	private const BASE = 'https://api.cloudflare.com/client/v4/';

	/** Resolved zone ids are stable; re-discovering them costs an API call. */
	private const ZONE_TRANSIENT = 'pbcc_cf_zone_id';

	/** Cloudflare caps URL purges per request. */
	private const URL_CHUNK = 30;

	/**
	 * Last transport/API error seen while resolving a zone. Without this, a bad
	 * token reports as "no zone found", which sends you debugging DNS instead of
	 * credentials.
	 */
	private ?WP_Error $last_error = null;

	/**
	 * The API token. One shared constant across the fleet is the intent.
	 */
	public static function token(): string {
		return trim( (string) apply_filters( 'pbcc/cloudflare/token', Settings::cf_token() ) );
	}

	/**
	 * Why the last zone lookup failed, for the settings screen's test button.
	 */
	public function last_error_message(): string {
		return $this->last_error instanceof WP_Error ? $this->last_error->get_error_message() : '';
	}

	public static function has_token(): bool {
		return '' !== self::token();
	}

	/**
	 * This site's zone id, discovered from its own domain so that no per-site
	 * configuration is needed beyond the shared token.
	 */
	public function zone_id(): string {
		$configured = Settings::cf_zone_id();

		if ( '' !== $configured ) {
			return $configured;
		}

		$cached = get_transient( self::ZONE_TRANSIENT );

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$zone = $this->discover_zone_id();

		if ( '' !== $zone ) {
			set_transient( self::ZONE_TRANSIENT, $zone, WEEK_IN_SECONDS );
		}

		return $zone;
	}

	public static function forget_zone(): void {
		delete_transient( self::ZONE_TRANSIENT );
	}

	/**
	 * A Cloudflare zone is the registrable domain, so walk up from the site host
	 * until one matches - that covers sites served from a subdomain.
	 */
	private function discover_zone_id(): string {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$host = preg_replace( '/^www\./i', '', $host );
		$bits = explode( '.', (string) $host );

		while ( count( $bits ) >= 2 ) {
			$candidate = implode( '.', $bits );
			$response  = $this->request( 'GET', 'zones?' . http_build_query( array( 'name' => $candidate ) ) );

			if ( is_wp_error( $response ) ) {
				$this->last_error = $response;

				// An auth or transport failure will fail identically for every
				// candidate, so stop rather than walking the whole domain.
				return '';
			}

			if ( ! empty( $response['result'][0]['id'] ) ) {
				return (string) $response['result'][0]['id'];
			}

			array_shift( $bits );
		}

		return '';
	}

	/**
	 * @return true|WP_Error
	 */
	public function purge_everything() {
		return $this->purge( array( 'purge_everything' => true ) );
	}

	/**
	 * @param string[] $urls Absolute URLs to purge.
	 * @return true|WP_Error
	 */
	public function purge_urls( array $urls ) {
		$urls = array_values( array_unique( array_filter( $urls ) ) );

		if ( empty( $urls ) ) {
			return true;
		}

		foreach ( array_chunk( $urls, self::URL_CHUNK ) as $chunk ) {
			$result = $this->purge( array( 'files' => $chunk ) );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * @param array<string,mixed> $body Purge payload.
	 * @return true|WP_Error
	 */
	private function purge( array $body ) {
		$zone = $this->zone_id();

		if ( '' === $zone ) {
			// Report why, not just that - a rejected token and a genuinely
			// missing zone need very different fixes.
			return $this->last_error instanceof WP_Error
				? $this->last_error
				: new WP_Error(
					'pbcc_cf_no_zone',
					__( 'No Cloudflare zone matches this domain.', 'wp-page-builder-cache-control' )
				);
		}

		$response = $this->request( 'POST', 'zones/' . rawurlencode( $zone ) . '/purge_cache', $body );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * @param array<string,mixed>|null $body Request body.
	 * @return array<string,mixed>|WP_Error
	 */
	private function request( string $method, string $path, ?array $body = null ) {
		$token = self::token();

		if ( '' === $token ) {
			return new WP_Error(
				'pbcc_cf_no_token',
				__( 'No Cloudflare API token is configured.', 'wp-page-builder-cache-control' )
			);
		}

		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::BASE . $path, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'pbcc_cf_bad_response',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Cloudflare returned an unreadable response (HTTP %d).', 'wp-page-builder-cache-control' ),
					(int) wp_remote_retrieve_response_code( $response )
				)
			);
		}

		if ( empty( $decoded['success'] ) ) {
			return new WP_Error( 'pbcc_cf_api_error', $this->first_error( $decoded ) );
		}

		return $decoded;
	}

	/**
	 * @param array<string,mixed> $decoded Decoded API response.
	 */
	private function first_error( array $decoded ): string {
		if ( ! empty( $decoded['errors'][0]['message'] ) ) {
			return sprintf(
				'Cloudflare: %s (%s)',
				(string) $decoded['errors'][0]['message'],
				(string) ( $decoded['errors'][0]['code'] ?? '?' )
			);
		}

		return __( 'Cloudflare rejected the request.', 'wp-page-builder-cache-control' );
	}
}
