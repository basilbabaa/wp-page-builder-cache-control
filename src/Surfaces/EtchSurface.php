<?php
/**
 * Etch builder surface.
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

final class EtchSurface implements Surface {

	public function id(): string {
		return 'etch';
	}

	/**
	 * Etch's builder is an app mounted into #etch-builder on a front-end request
	 * carrying ?etch=magic, for users who can manage options. The page being edited
	 * is rendered inside the app's own iframe from script, so this PHP request is
	 * the builder shell only and there is no second pass to exclude.
	 */
	public function is_active(): bool {
		// Read-only check of Etch's own query flag; no state is changed, so no nonce.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['etch'] ) && 'magic' === sanitize_text_field( wp_unslash( (string) $_GET['etch'] ) );
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
			'pbcc-etch',
			PBCC_URL . 'assets/css/etch.css',
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
			'pbcc-etch',
			PBCC_URL . 'assets/js/etch.js',
			array( 'pbcc-clipboard' ),
			PBCC_VERSION,
			true
		);

		wp_localize_script(
			'pbcc-etch',
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
