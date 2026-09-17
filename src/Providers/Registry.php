<?php
/**
 * Provider registry.
 *
 * @package PageBuilderCacheControl
 */

declare( strict_types=1 );

namespace PageBuilderCacheControl\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Registry {

	/**
	 * @var Provider[]|null
	 */
	private static ?array $providers = null;

	/**
	 * All registered providers, regardless of availability.
	 *
	 * @return Provider[]
	 */
	public static function all(): array {
		if ( null !== self::$providers ) {
			return self::$providers;
		}

		$providers = array(
			new CloudflareProvider(),
			new NginxHelperProvider(),
			new RedisObjectCacheProvider(),
		);

		/**
		 * Filter the registered cache providers.
		 *
		 * Third parties (Cloudflare, WP Rocket, LiteSpeed, cPanel) hook here.
		 *
		 * @param Provider[] $providers Registered providers.
		 */
		$providers = apply_filters( 'pbcc/providers', $providers );

		self::$providers = array_values(
			array_filter(
				$providers,
				static fn( $provider ): bool => $provider instanceof Provider
			)
		);

		return self::$providers;
	}

	/**
	 * Providers whose backend is actually present on this site.
	 *
	 * @return Provider[]
	 */
	public static function available(): array {
		return array_values(
			array_filter(
				self::all(),
				static fn( Provider $provider ): bool => $provider->is_available()
			)
		);
	}

	/**
	 * Look up a single available provider by id.
	 */
	public static function get( string $id ): ?Provider {
		foreach ( self::available() as $provider ) {
			if ( $provider->id() === $id ) {
				return $provider;
			}
		}

		return null;
	}
}
