<?php
/**
 * Redis object cache, via the Redis Object Cache plugin drop-in.
 *
 * @package PageBuilderCacheControl
 */

declare( strict_types=1 );

namespace PageBuilderCacheControl\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RedisObjectCacheProvider implements Provider {

	public function id(): string {
		return 'redis';
	}

	public function label(): string {
		return __( 'Flush Object Cache', 'wp-page-builder-cache-control' );
	}

	/**
	 * Stacked database discs with a refresh arrow.
	 */
	public function icon(): string {
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
			. '<ellipse cx="12" cy="5" rx="8" ry="3"/>'
			. '<path d="M4 5v6c0 1.66 3.58 3 8 3 1.06 0 2.07-.08 3-.22"/>'
			. '<path d="M4 11v6c0 1.66 3.58 3 8 3"/>'
			. '<path d="M20 5v5"/>'
			. '<path d="M21.5 17a3.5 3.5 0 1 1-1-2.45"/>'
			. '<path d="M21 12v2.8h-2.8"/>'
			. '</svg>';
	}

	/**
	 * Both conditions matter: the plugin supplies the UI, but the object-cache
	 * drop-in is what actually makes a flush meaningful.
	 */
	public function is_available(): bool {
		return defined( 'WP_REDIS_VERSION' ) && wp_using_ext_object_cache();
	}

	public function purge(): array {
		if ( ! $this->is_available() ) {
			return array(
				'success' => false,
				'message' => __( 'No external object cache is active.', 'wp-page-builder-cache-control' ),
			);
		}

		$flushed = wp_cache_flush();

		if ( false === $flushed ) {
			return array(
				'success' => false,
				'message' => __( 'The object cache refused the flush.', 'wp-page-builder-cache-control' ),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Object cache flushed.', 'wp-page-builder-cache-control' ),
		);
	}
}
