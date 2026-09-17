<?php
/**
 * Uncached-preview links.
 *
 * GridPane's nginx config skips the page cache whenever a request carries a
 * `wordpress_no_cache` cookie - whether or not the visitor is signed in. That
 * lets us hand a second browser session (an incognito window) a cookie which
 * makes it read past the page cache for its whole lifetime, without turning
 * caching off for real visitors and without touching server config.
 *
 * The arming URL is HMAC-signed and short-lived, because a URL that makes the
 * site skip its cache is a soft denial-of-service vector if it ever leaks into
 * a sitemap, a crawler, or a forwarded email.
 *
 * @package PageBuilderCacheControl
 */

declare( strict_types=1 );

namespace PageBuilderCacheControl\Bypass;

use PageBuilderCacheControl\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Manager {

	/**
	 * Default cookie name. GridPane's nginx config skips its page cache for any
	 * request carrying `wordpress_no_cache`, and several other nginx-based stacks
	 * use the same name because it long predates any one host.
	 *
	 * Hosts that match a different name need the `pbcc/bypass_cookie` filter -
	 * see the readme. Setting the wrong name fails silently: the cookie is set,
	 * the cache ignores it, and pages look stale for no visible reason.
	 */
	public const DEFAULT_COOKIE = 'wordpress_no_cache';

	/** Query arg carrying the signed token. */
	public const PARAM = 'pbcc-nocache';

	/** Query arg that clears the cookie again. */
	public const CLEAR_PARAM = 'pbcc-nocache-off';

	/** How long a minted link stays redeemable. Deliberately short. */
	private const TOKEN_TTL = 600;

	public function register(): void {
		add_action( 'init', array( $this, 'maybe_handle' ), 1 );
	}

	/**
	 * How long an armed window keeps reading past the cache. Bounded, so a
	 * bookmarked link cannot leave a browser skipping the cache forever.
	 */
	public function cookie_lifetime(): int {
		$lifetime = (int) apply_filters( 'pbcc/bypass_lifetime', Settings::preview_lifetime() );

		return max( 300, min( $lifetime, DAY_IN_SECONDS ) );
	}

	/**
	 * Mint a signed, single-purpose arming URL.
	 */
	public function mint_url(): string {
		$expires = time() + self::TOKEN_TTL;
		$token   = $expires . '.' . $this->sign( $expires );

		return add_query_arg( self::PARAM, rawurlencode( $token ), home_url( '/' ) );
	}

	public function token_ttl(): int {
		return self::TOKEN_TTL;
	}

	private function sign( int $expires ): string {
		return hash_hmac( 'sha256', 'pbcc-preview|' . $expires, wp_salt( 'auth' ) );
	}

	private function verify( string $token ): bool {
		$parts = explode( '.', $token, 2 );

		if ( 2 !== count( $parts ) ) {
			return false;
		}

		list( $expires, $mac ) = $parts;

		if ( ! ctype_digit( $expires ) || (int) $expires < time() ) {
			return false;
		}

		return hash_equals( $this->sign( (int) $expires ), $mac );
	}

	/**
	 * Redeem or revoke, then bounce to the clean URL so the token never lingers
	 * in the address bar or leaks through the referrer header.
	 */
	public function maybe_handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET[ self::CLEAR_PARAM ] ) ) {
			$this->clear_cookie();
			$this->bounce();
		}

		if ( ! isset( $_GET[ self::PARAM ] ) ) {
			return;
		}

		$token = sanitize_text_field( wp_unslash( $_GET[ self::PARAM ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// An expired or forged token is simply ignored - no cookie, no error.
		if ( $this->verify( $token ) ) {
			$this->set_cookie();
		}

		$this->bounce();
	}

	private function bounce(): void {
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );

		$target = remove_query_arg( array( self::PARAM, self::CLEAR_PARAM ) );

		wp_safe_redirect( $target, 302 );
		exit;
	}

	/**
	 * The cookie name this site's page cache actually honours.
	 */
	public function cookie_name(): string {
		/**
		 * Filter the cache-bypass cookie name.
		 *
		 * @param string $cookie Cookie name the page cache skips on.
		 */
		$cookie = (string) apply_filters( 'pbcc/bypass_cookie', self::DEFAULT_COOKIE );

		return '' !== trim( $cookie ) ? trim( $cookie ) : self::DEFAULT_COOKIE;
	}

	private function set_cookie(): void {
		setcookie( $this->cookie_name(), '1', $this->cookie_args( time() + $this->cookie_lifetime() ) );
	}

	private function clear_cookie(): void {
		setcookie( $this->cookie_name(), '', $this->cookie_args( time() - DAY_IN_SECONDS ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function cookie_args( int $expires ): array {
		return array(
			'expires'  => $expires,
			'path'     => COOKIEPATH ? COOKIEPATH : '/',
			'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		);
	}
}
