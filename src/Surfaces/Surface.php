<?php
/**
 * Builder surface contract.
 *
 * @package PageBuilderCacheControl
 */

declare( strict_types=1 );

namespace PageBuilderCacheControl\Surfaces;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Surface {

	/**
	 * Stable machine id for the surface (bricks, etch, ...).
	 */
	public function id(): string;

	/**
	 * Whether we are currently rendering inside this builder's chrome.
	 */
	public function is_active(): bool;

	/**
	 * Wire up whatever hooks the surface needs.
	 */
	public function register(): void;
}
