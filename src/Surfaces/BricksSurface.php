<?php
/**
 * Bricks Builder surface.
 *
 * @package PageBuilderCacheControl
 */

declare( strict_types=1 );

namespace PageBuilderCacheControl\Surfaces;

use PageBuilderCacheControl\Controls\Catalog;
use PageBuilderCacheControl\Controls\Control;
use PageBuilderCacheControl\Rest\Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BricksSurface implements Surface {

	public function id(): string {
		return 'bricks';
	}

	/**
	 * Bricks loads twice: the outer Vue shell and the canvas iframe rendering the
	 * page itself. The toolbar only exists in the outer shell, so the iframe pass
	 * must be excluded or we would inject a second, invisible copy into the
	 * customer's actual markup.
	 */
	public function is_active(): bool {
		if ( ! function_exists( 'bricks_is_builder' ) || ! function_exists( 'bricks_is_builder_iframe' ) ) {
			return false;
		}

		return bricks_is_builder() && ! bricks_is_builder_iframe();
	}

	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 100 );
	}

	public function enqueue(): void {
		if ( ! $this->is_active() ) {
			return;
		}

		/** This mirrors the REST permission check - no controls without the capability. */
		if ( ! current_user_can( (string) apply_filters( 'pbcc/capability', 'manage_options' ) ) ) {
			return;
		}

		$controls = $this->controls();

		// Nothing installed that we can purge - render nothing at all.
		if ( empty( $controls ) ) {
			return;
		}

		wp_enqueue_style(
			'pbcc-bricks',
			PBCC_URL . 'assets/css/bricks.css',
			array(),
			PBCC_VERSION
		);

		wp_enqueue_script(
			'pbcc-clipboard',
			PBCC_URL . 'assets/js/clipboard.js',
			array(),
			PBCC_VERSION,
			true
		);

		wp_enqueue_script(
			'pbcc-bricks',
			PBCC_URL . 'assets/js/bricks.js',
			array( 'pbcc-clipboard' ),
			PBCC_VERSION,
			true
		);

		wp_localize_script(
			'pbcc-bricks',
			'pbccData',
			array(
				'restUrl'    => esc_url_raw( rest_url( Controller::NAMESPACE . '/purge/' ) ),
				'previewUrl' => esc_url_raw( rest_url( Controller::NAMESPACE . '/preview-link' ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'controls'   => $controls,
				'i18n'       => array(
					'failed'     => __( 'Purge failed', 'wp-page-builder-cache-control' ),
					'copied'     => __( 'Link copied - paste into an incognito window', 'wp-page-builder-cache-control' ),
					'copyFailed' => __( 'Could not copy - link logged to the console', 'wp-page-builder-cache-control' ),
				),
			)
		);
	}

	/**
	 * Shape the applicable controls for the front end.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function controls(): array {
		return array_map(
			static fn( Control $control ): array => $control->to_array(),
			Catalog::all()
		);
	}
}
