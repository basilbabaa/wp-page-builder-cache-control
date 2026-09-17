<?php
/**
 * Plugin Name:       WP Page Builder Cache Control
 * Plugin URI:        https://github.com/basilbabaa/wp-page-builder-cache-control
 * Update URI:        https://github.com/basilbabaa/wp-page-builder-cache-control
 * Description:       Adds cache purge controls directly into page builder interfaces (Bricks, and later Etch) that otherwise hide the WordPress admin bar. Provider-based, so cache backends and builder surfaces can be added independently.
 * Version:           0.9.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Basil Babaa
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-page-builder-cache-control
 *
 * @package PageBuilderCacheControl
 */

declare( strict_types=1 );

namespace PageBuilderCacheControl;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PBCC_VERSION', '0.9.0' );
define( 'PBCC_FILE', __FILE__ );
define( 'PBCC_DIR', plugin_dir_path( __FILE__ ) );
define( 'PBCC_URL', plugin_dir_url( __FILE__ ) );

/**
 * PSR-4-ish autoloader scoped to this plugin's namespace.
 */
spl_autoload_register(
	static function ( string $class ): void {
		$prefix = __NAMESPACE__ . '\\';

		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$path     = PBCC_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		Plugin::instance()->boot();
	},
	20
);
