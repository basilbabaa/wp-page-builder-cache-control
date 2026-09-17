<?php
/**
 * Removes stored settings on uninstall.
 *
 * The option holds a Cloudflare API token, so it should not survive deletion
 * of the plugin that owns it.
 *
 * @package PageBuilderCacheControl
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'pbcc_settings' );
delete_transient( 'pbcc_cf_zone_id' );

if ( is_multisite() ) {
	delete_site_option( 'pbcc_settings' );
	delete_site_transient( 'pbcc_cf_zone_id' );
}
