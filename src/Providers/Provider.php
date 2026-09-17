<?php
/**
 * Cache provider contract.
 *
 * @package PageBuilderCacheControl
 */

declare( strict_types=1 );

namespace PageBuilderCacheControl\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Provider {

	/**
	 * Stable machine id. Used in REST routes and DOM ids, so keep it [a-z0-9_-].
	 */
	public function id(): string;

	/**
	 * Human label, shown in the builder tooltip.
	 */
	public function label(): string;

	/**
	 * Inline SVG markup for the button. Must inherit colour via currentColor.
	 */
	public function icon(): string;

	/**
	 * Whether the underlying cache backend is present and usable on this site.
	 * When false the control is not rendered at all.
	 */
	public function is_available(): bool;

	/**
	 * Perform the purge.
	 *
	 * @return array{success:bool,message:string}
	 */
	public function purge(): array;
}
