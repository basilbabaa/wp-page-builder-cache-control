<?php
/**
 * Nginx FastCGI / proxy cache, via the GridPane Nginx Helper plugin.
 *
 * @package PageBuilderCacheControl
 */

declare( strict_types=1 );

namespace PageBuilderCacheControl\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class NginxHelperProvider implements Provider {

	public function id(): string {
		return 'nginx';
	}

	public function label(): string {
		return __( 'Purge Nginx Cache', 'wp-page-builder-cache-control' );
	}

	/**
	 * Lightning bolt over a server stack - "flush the edge".
	 */
	public function icon(): string {
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
			. '<rect x="3" y="3" width="18" height="6" rx="1.5"/>'
			. '<path d="M21 12.5V15a1.5 1.5 0 0 1-1.5 1.5H14"/>'
			. '<path d="M3 11v4a1.5 1.5 0 0 0 1.5 1.5H7"/>'
			. '<path d="M6.5 6h.01M10 6h.01"/>'
			. '<path d="m12.5 12-3 5.5h3l-1 4.5 4-6h-3z"/>'
			. '</svg>';
	}

	/**
	 * Present only when the plugin is loaded AND purging is actually enabled -
	 * otherwise the button would appear to work but do nothing.
	 *
	 * Read the live options off the plugin's own global rather than the stored
	 * option. On GridPane the stored `rt_wp_nginx_helper_options` row is stale
	 * (enable_purge=0, cache_method=enable_fastcgi) because the real values come
	 * from wp-config constants, which Nginx Helper merges in at runtime - it
	 * forces enable_purge=1 whenever those constants are set. Trusting the row
	 * would hide this control on every GridPane site.
	 */
	public function is_available(): bool {
		if ( ! class_exists( 'Nginx_Helper' ) ) {
			return false;
		}

		global $nginx_helper_admin;

		if ( isset( $nginx_helper_admin->options ) && is_array( $nginx_helper_admin->options ) ) {
			return ! empty( $nginx_helper_admin->options['enable_purge'] );
		}

		// The global is only absent if Nginx Helper changes shape; fall back to
		// whether it actually wired up the purge handler.
		return (bool) has_action( 'rt_nginx_helper_purge_all' );
	}

	public function purge(): array {
		if ( ! $this->is_available() ) {
			return array(
				'success' => false,
				'message' => __( 'Nginx Helper is not active, or purging is disabled in its settings.', 'wp-page-builder-cache-control' ),
			);
		}

		if ( ! has_action( 'rt_nginx_helper_purge_all' ) ) {
			return array(
				'success' => false,
				'message' => __( 'Nginx Helper did not register its purge handler.', 'wp-page-builder-cache-control' ),
			);
		}

		/**
		 * Nginx Helper's own documented integration point. See
		 * nginx-helper/includes/class-nginx-helper.php - "expose action to
		 * allow other plugins to purge the cache".
		 */
		do_action( 'rt_nginx_helper_purge_all' );

		return array(
			'success' => true,
			'message' => __( 'Nginx cache purged.', 'wp-page-builder-cache-control' ),
		);
	}
}
