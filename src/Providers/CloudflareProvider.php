<?php
/**
 * Cloudflare edge cache.
 *
 * Talks to the Cloudflare API directly rather than depending on any Cloudflare
 * plugin, so the control works whether or not one is installed.
 *
 * @package PageBuilderCacheControl
 */

declare( strict_types=1 );

namespace PageBuilderCacheControl\Providers;

use PageBuilderCacheControl\Cloudflare\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CloudflareProvider implements Provider {

	public function id(): string {
		return 'cloudflare';
	}

	public function label(): string {
		return __( 'Purge Cloudflare Cache', 'wp-page-builder-cache-control' );
	}

	/**
	 * Cloud with a refresh arrow.
	 */
	public function icon(): string {
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
			. '<path d="M7 17.5a4 4 0 0 1-.4-8A5.5 5.5 0 0 1 17.4 9a3.75 3.75 0 0 1 1.3 7.2"/>'
			. '<path d="M14.8 19.3a3.3 3.3 0 1 1 .7-3.6"/>'
			. '<path d="M16.2 12.9v2.7h-2.7"/>'
			. '</svg>';
	}

	/**
	 * Kept deliberately cheap - a token check only, no network call. Zone
	 * resolution happens on purge and is cached, so a misconfigured zone
	 * surfaces as a clear error rather than a missing button.
	 */
	public function is_available(): bool {
		return Api::has_token();
	}

	public function purge(): array {
		if ( ! $this->is_available() ) {
			return array(
				'success' => false,
				'message' => __( 'No Cloudflare API token is configured.', 'wp-page-builder-cache-control' ),
			);
		}

		$result = ( new Api() )->purge_everything();

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'message' => $result->get_error_message(),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Cloudflare cache purged.', 'wp-page-builder-cache-control' ),
		);
	}
}
