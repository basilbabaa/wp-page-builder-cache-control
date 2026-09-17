<?php
/**
 * Cache Control menu in the WordPress admin bar.
 *
 * Deliberately not a mirror of the builder toolbar. It carries the controls with
 * no other presence up here - the Cloudflare purge, and the preview link we
 * invented - each appearing on its own merits: the Cloudflare item needs a
 * token, the preview link needs a page cache, and neither absence hides the
 * other.
 *
 * Optionally it also adopts the menus Nginx Helper and Redis Object Cache add,
 * re-parenting them under this one so every cache lives behind a single entry
 * instead of three scattered across the bar.
 *
 * Both items are driven by JavaScript for inline feedback, but the purge also
 * carries a real admin-post URL so it still works if that script never runs.
 * Copying cannot degrade the same way, so that item is script-only.
 *
 * @package PageBuilderCacheControl
 */

declare( strict_types=1 );

namespace PageBuilderCacheControl\Admin;

use PageBuilderCacheControl\Controls\Catalog;
use PageBuilderCacheControl\Controls\Control;
use PageBuilderCacheControl\Providers\Registry;
use PageBuilderCacheControl\Rest\Controller;
use PageBuilderCacheControl\Settings;
use WP_Admin_Bar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AdminBarMenu {

	private const ACTION = 'pbcc_purge';

	/**
	 * Which controls this surface carries, in menu order. Anything not
	 * applicable here is simply skipped by the Catalog.
	 */
	private const ITEMS = array( 'cloudflare', 'preview' );

	private const MENU_ID = 'pbcc-menu';

	/**
	 * Admin bar nodes belonging to other cache plugins that this menu can take
	 * over. Nginx Helper's is a leaf; Redis Object Cache's is a parent whose
	 * own children come along with it.
	 */
	private const ADOPTABLE = array( 'nginx-helper-purge-all', 'redis-cache' );

	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_purge' ) );

		// Only the stored option is safe to read this early. Capability and
		// provider availability are settled in the callbacks below, because
		// `register()` runs on `plugins_loaded` - before the current user
		// exists, and before every plugin has finished wiring itself up.
		if ( ! Settings::admin_bar() ) {
			return;
		}

		// 999: Redis registers its menu at 998 and Nginx Helper at 100, so this
		// is the first point where both are present and can be re-parented.
		add_action( 'admin_bar_menu', array( $this, 'add_nodes' ), 999 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 100 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ), 100 );
	}

	/**
	 * @return Control[]
	 */
	private function items(): array {
		return Catalog::some( self::ITEMS );
	}

	private function may_render(): bool {
		return is_admin_bar_showing() && current_user_can( $this->capability() );
	}

	private function capability(): string {
		return (string) apply_filters( 'pbcc/capability', 'manage_options' );
	}

	public function add_nodes( WP_Admin_Bar $bar ): void {
		if ( ! $this->may_render() ) {
			return;
		}

		$items  = $this->items();
		$adopt  = $this->adoptable( $bar );

		// With nothing of our own and nothing to adopt, render no menu at all
		// rather than an empty parent.
		if ( empty( $items ) && empty( $adopt ) ) {
			return;
		}

		$bar->add_node(
			array(
				'id'    => self::MENU_ID,
				'title' => __( 'Cache Control', 'wp-page-builder-cache-control' ),
				'href'  => false,
				'meta'  => array( 'class' => 'pbcc-adminbar' ),
			)
		);

		// Children render in the order nodes were first inserted, not the order
		// re-parented here - adopted nodes already existed (Nginx Helper at 100,
		// Redis at 998) so they lead regardless. What this ordering does control
		// is that the preview link, added after everything else, stays last:
		// every purge groups above it.
		foreach ( $this->of_type( $items, Control::TYPE_PURGE ) as $item ) {
			$this->add_item( $bar, $item );
		}

		foreach ( $adopt as $node_id ) {
			$this->adopt( $bar, $node_id );
		}

		foreach ( $this->of_type( $items, Control::TYPE_PREVIEW ) as $item ) {
			$this->add_item( $bar, $item );
		}
	}

	/**
	 * @param Control[] $items Controls to filter.
	 * @return Control[]
	 */
	private function of_type( array $items, string $type ): array {
		return array_values(
			array_filter(
				$items,
				static fn( Control $item ): bool => $item->type === $type
			)
		);
	}

	private function add_item( WP_Admin_Bar $bar, Control $item ): void {
		$bar->add_node(
			array(
				'id'     => 'pbcc-item-' . $item->id,
				'parent' => self::MENU_ID,
				'title'  => $item->label,
				'href'   => $this->item_href( $item ),
				'meta'   => array( 'class' => 'pbcc-adminbar__' . $item->type ),
			)
		);
	}

	/**
	 * Other plugins' cache menus that are actually present right now.
	 *
	 * @return string[]
	 */
	private function adoptable( WP_Admin_Bar $bar ): array {
		if ( ! Settings::adopt_menus() ) {
			return array();
		}

		/**
		 * Filter which admin bar nodes are pulled into the Cache Control menu.
		 *
		 * @param string[] $ids Admin bar node ids, in the order they should appear.
		 */
		$ids = (array) apply_filters( 'pbcc/adopt_nodes', self::ADOPTABLE );

		return array_values(
			array_filter(
				$ids,
				static function ( $id ) use ( $bar ): bool {
					return is_string( $id ) && null !== $bar->get_node( $id );
				}
			)
		);
	}

	/**
	 * Re-parent an existing node under our menu.
	 *
	 * `add_node()` merges into a node that already exists - "keep any data that
	 * isn't provided" - so passing only the id and the new parent preserves the
	 * title, href and meta the other plugin set. Its children reference it by id
	 * and are untouched, so the whole submenu travels with it.
	 */
	private function adopt( WP_Admin_Bar $bar, string $node_id ): void {
		$bar->add_node(
			array(
				'id'     => $node_id,
				'parent' => self::MENU_ID,
			)
		);
	}

	/**
	 * Purges get a working no-JavaScript URL; copying has no meaningful
	 * server-side equivalent, so it stays an anchor the script takes over.
	 */
	private function item_href( Control $item ): string {
		if ( Control::TYPE_PURGE !== $item->type ) {
			return '#';
		}

		return wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION . '&provider=' . rawurlencode( $item->id ) ),
			self::ACTION . '_' . $item->id
		);
	}

	public function enqueue(): void {
		if ( ! $this->may_render() ) {
			return;
		}

		$items = $this->items();

		if ( empty( $items ) ) {
			return;
		}

		wp_enqueue_style(
			'pbcc-adminbar',
			PBCC_URL . 'assets/css/adminbar.css',
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
			'pbcc-adminbar',
			PBCC_URL . 'assets/js/adminbar.js',
			array( 'pbcc-clipboard' ),
			PBCC_VERSION,
			true
		);

		$purge = null;

		foreach ( $items as $item ) {
			if ( Control::TYPE_PURGE === $item->type ) {
				$purge = $item;
				break;
			}
		}

		wp_localize_script(
			'pbcc-adminbar',
			'pbccBarData',
			array(
				'purgeUrl'   => null === $purge
					? ''
					: esc_url_raw( rest_url( Controller::NAMESPACE . '/purge/' . $purge->id ) ),
				'previewUrl' => esc_url_raw( rest_url( Controller::NAMESPACE . '/preview-link' ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'i18n'       => array(
					'working'    => __( 'Working&hellip;', 'wp-page-builder-cache-control' ),
					'purged'     => __( 'Purged', 'wp-page-builder-cache-control' ),
					'copied'     => __( 'Copied &mdash; paste into incognito', 'wp-page-builder-cache-control' ),
					'failed'     => __( 'Failed', 'wp-page-builder-cache-control' ),
					'purgeLabel' => null === $purge ? '' : $purge->label,
					'copyLabel'  => __( 'Copy Uncached Preview Link', 'wp-page-builder-cache-control' ),
				),
			)
		);
	}

	/**
	 * No-JavaScript fallback for a purge item.
	 */
	public function handle_purge(): void {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to purge caches.', 'wp-page-builder-cache-control' ), '', array( 'response' => 403 ) );
		}

		$id = isset( $_GET['provider'] ) ? sanitize_key( wp_unslash( $_GET['provider'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		check_admin_referer( self::ACTION . '_' . $id );

		$provider = Registry::get( $id );

		if ( null !== $provider ) {
			$provider->purge();
		}

		$back = wp_get_referer();

		wp_safe_redirect( $back ? $back : admin_url() );
		exit;
	}
}
