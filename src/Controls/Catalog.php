<?php
/**
 * The single answer to "which controls apply on this site right now".
 *
 * Every surface asks this rather than working it out itself. When the admin bar
 * menu computed its own answer it drifted immediately: the whole menu hid unless
 * Cloudflare was configured, taking the preview link with it - even though that
 * link depends on the origin page cache and works fine with no Cloudflare at all.
 *
 * @package PageBuilderCacheControl
 */

declare( strict_types=1 );

namespace PageBuilderCacheControl\Controls;

use PageBuilderCacheControl\Providers\Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Catalog {

	/**
	 * Every control that applies here, preview first and providers after.
	 *
	 * @return Control[]
	 */
	public static function all(): array {
		$controls = array();

		if ( self::preview_available() ) {
			$controls[] = new Control(
				'preview',
				Control::TYPE_PREVIEW,
				__( 'Copy Uncached Preview Link', 'wp-page-builder-cache-control' ),
				self::preview_icon()
			);
		}

		foreach ( Registry::available() as $provider ) {
			$controls[] = new Control(
				$provider->id(),
				Control::TYPE_PURGE,
				$provider->label(),
				$provider->icon()
			);
		}

		/**
		 * Filter the controls offered across every surface.
		 *
		 * @param Control[] $controls Applicable controls.
		 */
		$controls = apply_filters( 'pbcc/controls', $controls );

		return array_values(
			array_filter(
				$controls,
				static fn( $control ): bool => $control instanceof Control
			)
		);
	}

	/**
	 * One control by id, or null when it does not apply here.
	 */
	public static function get( string $id ): ?Control {
		foreach ( self::all() as $control ) {
			if ( $control->id === $id ) {
				return $control;
			}
		}

		return null;
	}

	/**
	 * Several controls by id, in the order asked for, skipping any that do not
	 * apply. Surfaces that want a specific subset use this.
	 *
	 * @param string[] $ids Control ids, in the order they should appear.
	 * @return Control[]
	 */
	public static function some( array $ids ): array {
		$controls = array();

		foreach ( $ids as $id ) {
			$control = self::get( $id );

			if ( null !== $control ) {
				$controls[] = $control;
			}
		}

		return $controls;
	}

	/**
	 * The preview link sets a cookie the *page* cache honours, so it needs a
	 * page cache to exist. The nginx provider is the closest honest proxy, and
	 * notably has nothing to do with Cloudflare.
	 */
	public static function preview_available(): bool {
		$available = null !== Registry::get( 'nginx' );

		/**
		 * Filter whether the uncached-preview control is offered.
		 *
		 * @param bool $available Whether to show the control.
		 */
		return (bool) apply_filters( 'pbcc/preview_link_available', $available );
	}

	/**
	 * Incognito glasses - the near-universal shorthand for a private window.
	 */
	private static function preview_icon(): string {
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
			. '<path d="M2.5 13h19"/>'
			. '<path d="M5 13l1.7-5.1A2 2 0 0 1 8.6 6.5h6.8a2 2 0 0 1 1.9 1.4L19 13"/>'
			. '<circle cx="6.8" cy="16.2" r="3.1"/>'
			. '<circle cx="17.2" cy="16.2" r="3.1"/>'
			. '<path d="M9.9 15.8c1.3-.7 2.9-.7 4.2 0"/>'
			. '</svg>';
	}
}
