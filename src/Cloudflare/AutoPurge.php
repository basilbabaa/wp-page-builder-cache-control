<?php
/**
 * Purge Cloudflare automatically when content changes.
 *
 * Two different signals are needed, because page builders do not go through the
 * usual post-save path:
 *
 * 1. Core hooks (`post_updated`, `transition_post_status`) cover the block and
 *    classic editors.
 * 2. Builder AJAX actions are matched by name. Bricks' `bricks_save_post`
 *    handler writes post meta directly and fires no actions of its own - there
 *    is no `do_action` anywhere in its ajax.php - so nothing in core tells us a
 *    builder save happened.
 *
 * URLs are collected during the request and flushed once on `shutdown`, so a
 * save that touches several things still costs a single API call. `shutdown`
 * runs even after `wp_send_json` because WordPress registers it as a PHP
 * shutdown function, which is what makes the AJAX case work at all.
 *
 * @package PageBuilderCacheControl
 */

declare( strict_types=1 );

namespace PageBuilderCacheControl\Cloudflare;

use PageBuilderCacheControl\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AutoPurge {

	/**
	 * @var string[]
	 */
	private array $urls = array();

	private bool $flushed = false;

	public function register(): void {
		if ( ! Api::has_token() || ! $this->enabled() ) {
			return;
		}

		add_action( 'post_updated', array( $this, 'queue_post' ), 10, 1 );
		add_action( 'transition_post_status', array( $this, 'queue_status_change' ), 10, 3 );
		add_action( 'shutdown', array( $this, 'flush' ), 100 );
	}

	/**
	 * Off switch, for sites where purging on every save is unwanted.
	 */
	private function enabled(): bool {
		$enabled = Settings::cf_auto_purge();

		/**
		 * Filter whether Cloudflare is purged automatically on content changes.
		 *
		 * @param bool $enabled Whether auto-purge is active.
		 */
		return (bool) apply_filters( 'pbcc/cloudflare/auto_purge', $enabled );
	}

	/**
	 * AJAX actions that mean "a builder just saved a post", mapped to the
	 * request parameter carrying the post id. Etch slots in here later.
	 *
	 * @return array<string,string>
	 */
	private function builder_actions(): array {
		/**
		 * Filter the builder save actions watched for auto-purge.
		 *
		 * @param array<string,string> $actions Action name => post id parameter.
		 */
		return (array) apply_filters(
			'pbcc/cloudflare/builder_actions',
			array( 'bricks_save_post' => 'postId' )
		);
	}

	public function queue_post( int $post_id ): void {
		$this->queue_post_urls( $post_id );
	}

	/**
	 * @param string   $new_status New post status.
	 * @param string   $old_status Previous post status.
	 * @param \WP_Post $post       The post.
	 */
	public function queue_status_change( string $new_status, string $old_status, $post ): void {
		if ( $new_status === $old_status ) {
			return;
		}

		if ( $post instanceof \WP_Post ) {
			$this->queue_post_urls( $post->ID );
		}
	}

	/**
	 * Queue a post's own URL plus the front page, which nearly always lists it.
	 */
	private function queue_post_urls( int $post_id ): void {
		if ( $post_id <= 0 || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$type = get_post_type_object( $post->post_type );

		// Menu items, revisions and other internal types have no public URL.
		if ( ! $type || empty( $type->public ) ) {
			return;
		}

		$permalink = get_permalink( $post_id );

		if ( is_string( $permalink ) && '' !== $permalink ) {
			$this->urls[] = $permalink;
		}

		$this->urls[] = home_url( '/' );

		/**
		 * Filter the URLs queued for purge after a post changes.
		 *
		 * @param string[] $urls    Queued URLs.
		 * @param int      $post_id The post that changed.
		 */
		$this->urls = (array) apply_filters( 'pbcc/cloudflare/purge_urls', $this->urls, $post_id );
	}

	/**
	 * Catch builder saves that never reach a core post hook, then send whatever
	 * accumulated during this request as one call.
	 */
	public function flush(): void {
		if ( $this->flushed ) {
			return;
		}

		$this->flushed = true;

		$this->queue_current_builder_save();

		if ( empty( $this->urls ) ) {
			return;
		}

		$result = ( new Api() )->purge_urls( $this->urls );

		/**
		 * Fires after an automatic Cloudflare purge attempt.
		 *
		 * @param string[]      $urls   URLs submitted.
		 * @param true|\WP_Error $result Purge outcome.
		 */
		do_action( 'pbcc/cloudflare/auto_purged', $this->urls, $result );

		$this->urls = array();
	}

	private function queue_current_builder_save(): void {
		if ( ! wp_doing_ajax() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';

		if ( '' === $action ) {
			return;
		}

		foreach ( $this->builder_actions() as $watched => $param ) {
			if ( $action !== sanitize_key( $watched ) ) {
				continue;
			}

			$post_id = isset( $_POST[ $param ] ) ? absint( wp_unslash( $_POST[ $param ] ) ) : 0;
			// phpcs:enable WordPress.Security.NonceVerification.Missing

			// The builder verified its own nonce before saving; by shutdown the
			// write has already happened, so this only decides what to purge.
			if ( $post_id > 0 ) {
				$this->queue_post_urls( $post_id );
			}

			return;
		}
	}
}
