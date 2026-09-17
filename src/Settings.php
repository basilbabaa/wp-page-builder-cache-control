<?php
/**
 * Stored settings, with wp-config constants taking precedence.
 *
 * Constants win over the database everywhere. That keeps the settings page
 * useful on a one-off site while still letting a fleet be provisioned from
 * wp-config - and a site provisioned that way shows its values as locked rather
 * than silently ignoring what someone types.
 *
 * @package PageBuilderCacheControl
 */

declare( strict_types=1 );

namespace PageBuilderCacheControl;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings {

	public const OPTION = 'pbcc_settings';

	/**
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * @param mixed $fallback Value when unset.
	 * @return mixed
	 */
	public static function get( string $key, $fallback = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * @param array<string,mixed> $values Values to merge in.
	 */
	public static function update( array $values ): void {
		// autoload = no: the token should not ride along in alloptions on
		// every single request.
		update_option( self::OPTION, array_merge( self::all(), $values ), false );
	}

	// --- Cloudflare -------------------------------------------------------

	public static function cf_token(): string {
		if ( self::cf_token_locked() ) {
			return trim( (string) constant( 'PBCC_CF_TOKEN' ) );
		}

		return trim( (string) self::get( 'cf_token', '' ) );
	}

	public static function cf_token_locked(): bool {
		return defined( 'PBCC_CF_TOKEN' ) && '' !== trim( (string) constant( 'PBCC_CF_TOKEN' ) );
	}

	public static function cf_zone_id(): string {
		if ( self::cf_zone_locked() ) {
			return trim( (string) constant( 'PBCC_CF_ZONE_ID' ) );
		}

		return trim( (string) self::get( 'cf_zone_id', '' ) );
	}

	public static function cf_zone_locked(): bool {
		return defined( 'PBCC_CF_ZONE_ID' ) && '' !== trim( (string) constant( 'PBCC_CF_ZONE_ID' ) );
	}

	public static function cf_auto_purge(): bool {
		if ( defined( 'PBCC_CF_AUTO_PURGE' ) ) {
			return (bool) constant( 'PBCC_CF_AUTO_PURGE' );
		}

		return (bool) self::get( 'cf_auto_purge', true );
	}

	public static function cf_auto_purge_locked(): bool {
		return defined( 'PBCC_CF_AUTO_PURGE' );
	}

	// --- Admin bar --------------------------------------------------------

	public static function admin_bar(): bool {
		if ( self::admin_bar_locked() ) {
			return (bool) constant( 'PBCC_ADMIN_BAR' );
		}

		return (bool) self::get( 'admin_bar', false );
	}

	public static function admin_bar_locked(): bool {
		return defined( 'PBCC_ADMIN_BAR' );
	}

	public static function adopt_menus(): bool {
		// Nothing to consolidate into without the menu itself, so this can never
		// be true on its own - not even via the constant.
		if ( ! self::admin_bar() ) {
			return false;
		}

		if ( self::adopt_menus_locked() ) {
			return (bool) constant( 'PBCC_ADOPT_MENUS' );
		}

		return (bool) self::get( 'adopt_menus', false );
	}

	public static function adopt_menus_locked(): bool {
		return defined( 'PBCC_ADOPT_MENUS' );
	}

	// --- Uncached preview -------------------------------------------------

	public static function preview_lifetime(): int {
		return (int) self::get( 'preview_lifetime', 2 * HOUR_IN_SECONDS );
	}
}
