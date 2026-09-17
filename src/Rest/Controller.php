<?php
/**
 * REST endpoints backing the builder controls.
 *
 * @package PageBuilderCacheControl
 */

declare( strict_types=1 );

namespace PageBuilderCacheControl\Rest;

use PageBuilderCacheControl\Bypass\Manager;
use PageBuilderCacheControl\Providers\Registry;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Controller {

	public const NAMESPACE = 'pbcc/v1';

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/purge/(?P<provider>[a-z0-9_-]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'purge' ),
				'permission_callback' => array( $this, 'can_purge' ),
				'args'                => array(
					'provider' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/preview-link',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'preview_link' ),
				'permission_callback' => array( $this, 'can_purge' ),
			)
		);
	}

	/**
	 * Mint an uncached-preview link for pasting into a second browser session.
	 */
	public function preview_link(): WP_REST_Response {
		$manager = new Manager();

		return new WP_REST_Response(
			array(
				'success'  => true,
				'url'      => $manager->mint_url(),
				'redeemIn' => $manager->token_ttl(),
				'lifetime' => $manager->cookie_lifetime(),
			),
			200
		);
	}

	/**
	 * Deliberately stricter than the builder's own capability. Purging is a
	 * server-level action, so it stays with administrators for now.
	 */
	public function can_purge(): bool {
		/**
		 * Filter the capability required to purge a cache.
		 *
		 * @param string $capability Capability name.
		 */
		$capability = apply_filters( 'pbcc/capability', 'manage_options' );

		return current_user_can( $capability );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function purge( WP_REST_Request $request ) {
		$id       = (string) $request->get_param( 'provider' );
		$provider = Registry::get( $id );

		if ( null === $provider ) {
			return new WP_Error(
				'pbcc_unknown_provider',
				__( 'That cache provider is not available on this site.', 'wp-page-builder-cache-control' ),
				array( 'status' => 404 )
			);
		}

		$result = $provider->purge();

		/**
		 * Fires after a purge attempt, successful or not.
		 *
		 * @param string $id     Provider id.
		 * @param array  $result Result payload.
		 */
		do_action( 'pbcc/purged', $id, $result );

		return new WP_REST_Response(
			array(
				'provider' => $id,
				'success'  => (bool) $result['success'],
				'message'  => (string) $result['message'],
			),
			$result['success'] ? 200 : 500
		);
	}
}
