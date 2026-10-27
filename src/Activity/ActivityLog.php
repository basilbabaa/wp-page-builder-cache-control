<?php
/**
 * Record purges in the Activity Log plugin (AAL / "Activity Log") when it is active.
 *
 * Purges happen inside WordPress, so a host's own dashboard never hears about them.
 * This leaves an audit trail instead: who purged which cache, when, and the outcome.
 * It does nothing at all when the Activity Log plugin is not installed.
 *
 * @package PageBuilderCacheControl
 */

declare( strict_types=1 );

namespace PageBuilderCacheControl\Activity;

use PageBuilderCacheControl\Providers\Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ActivityLog {

	public function register(): void {
		add_action( 'pbcc/purged', array( $this, 'manual' ), 10, 2 );
		add_action( 'pbcc/cloudflare/auto_purged', array( $this, 'automatic' ), 10, 2 );
		add_filter( 'aal_init_roles', array( $this, 'allow_cache_type' ) );
	}

	/**
	 * Activity Log only lists entries whose type is on a per-capability allow-list
	 * (aal_init_roles), so an entry with an unknown type is stored but never shown.
	 * Put our type on the list for administrators.
	 *
	 * @param array<string,string[]> $roles Capability => allowed object types.
	 * @return array<string,string[]>
	 */
	public function allow_cache_type( $roles ) {
		if ( ! is_array( $roles ) ) {
			return $roles;
		}

		if ( ! isset( $roles['manage_options'] ) || ! is_array( $roles['manage_options'] ) ) {
			$roles['manage_options'] = array();
		}

		if ( ! in_array( 'Cache', $roles['manage_options'], true ) ) {
			$roles['manage_options'][] = 'Cache';
		}

		return $roles;
	}

	/**
	 * A purge button was used.
	 *
	 * @param string              $id     Provider id.
	 * @param array<string,mixed> $result Result payload (success, message).
	 */
	public function manual( string $id, array $result ): void {
		$provider = Registry::get( $id );
		$label    = null !== $provider ? $provider->label() : $id;

		$this->log(
			! empty( $result['success'] ) ? 'purged' : 'purge failed',
			$id,
			$label,
			array( 'result' => (string) ( $result['message'] ?? '' ) )
		);
	}

	/**
	 * Cloudflare was purged automatically after a post was saved.
	 *
	 * @param string[]       $urls   URLs submitted.
	 * @param true|\WP_Error $result Outcome.
	 */
	public function automatic( array $urls, $result ): void {
		$first = (string) reset( $urls );
		$name  = count( $urls ) > 1 ? sprintf( '%s (+%d more)', $first, count( $urls ) - 1 ) : $first;

		$this->log(
			is_wp_error( $result ) ? 'purge failed (automatic)' : 'purged (automatic)',
			'cloudflare',
			$name,
			array(
				'urls'   => count( $urls ),
				'result' => is_wp_error( $result ) ? $result->get_error_message() : 'ok',
			)
		);
	}

	/**
	 * @param array<string,mixed> $meta Extra detail shown in the log entry.
	 */
	private function log( string $action, string $subtype, string $name, array $meta ): void {
		if ( ! function_exists( 'aal_insert_log' ) ) {
			return;
		}

		/**
		 * Turn purge logging off.
		 *
		 * @param bool   $enabled Whether to log.
		 * @param string $action  What happened.
		 */
		if ( ! apply_filters( 'pbcc/log_purges', true, $action ) ) {
			return;
		}

		aal_insert_log(
			array(
				'action'         => $action,
				'object_type'    => 'Cache',
				'object_subtype' => $subtype,
				'object_name'    => $name,
				'object_id'      => 0,
				'meta'           => $meta,
			)
		);
	}
}
