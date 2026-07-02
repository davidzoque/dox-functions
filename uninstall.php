<?php
/**
 * Uninstall Dox Functions.
 *
 * Snippets are the site's own code, so the table is KEPT by default — an
 * accidental uninstall must never destroy it. Options and transients are
 * always cleaned up. To also drop the snippets table, define in wp-config.php:
 *
 *     define( 'DOX_FUNCTIONS_REMOVE_DATA', true );
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

function dox_functions_uninstall_site() {
	delete_option( 'dox_functions_safe_mode' );
	delete_option( 'dox_functions_db_version' );
	delete_option( 'dox_functions_error_notice' );
	delete_transient( 'dox_functions_active_cache' );

	if ( defined( 'DOX_FUNCTIONS_REMOVE_DATA' ) && DOX_FUNCTIONS_REMOVE_DATA ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}dox_functions" );
	}
}

if ( is_multisite() ) {
	$dox_functions_site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
	foreach ( $dox_functions_site_ids as $dox_functions_site_id ) {
		switch_to_blog( $dox_functions_site_id );
		dox_functions_uninstall_site();
		restore_current_blog();
	}
} else {
	dox_functions_uninstall_site();
}
