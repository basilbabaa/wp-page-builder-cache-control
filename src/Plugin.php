<?php
/**
 * Plugin container.
 *
 * @package PageBuilderCacheControl
 */

declare( strict_types=1 );

namespace PageBuilderCacheControl;

use PageBuilderCacheControl\Admin\AdminBarMenu;
use PageBuilderCacheControl\Admin\SettingsPage;
use PageBuilderCacheControl\Bypass\Manager;
use PageBuilderCacheControl\Cloudflare\AutoPurge;
use PageBuilderCacheControl\Rest\Controller;
use PageBuilderCacheControl\Surfaces\BricksSurface;
use PageBuilderCacheControl\Surfaces\Surface;
use PageBuilderCacheControl\Updates\Updater;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	private static ?Plugin $instance = null;

	private bool $booted = false;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		( new Controller() )->register();
		( new Manager() )->register();
		( new AutoPurge() )->register();
		( new Updater() )->register();
		( new SettingsPage() )->register();
		( new AdminBarMenu() )->register();

		foreach ( $this->surfaces() as $surface ) {
			$surface->register();
		}
	}

	/**
	 * Builder surfaces. Etch joins this list later; it needs no provider changes.
	 *
	 * @return Surface[]
	 */
	private function surfaces(): array {
		/**
		 * Filter the registered builder surfaces.
		 *
		 * @param Surface[] $surfaces Registered surfaces.
		 */
		$surfaces = apply_filters( 'pbcc/surfaces', array( new BricksSurface() ) );

		return array_values(
			array_filter(
				$surfaces,
				static fn( $surface ): bool => $surface instanceof Surface
			)
		);
	}
}
