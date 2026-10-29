<?php
/**
 * Uninstall routine: removes all data created by the plugin.
 *
 * @package smtp
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

delete_option( 'smtp_settings' );
delete_option( 'smtp_credentials' );
delete_option( 'smtp_db_version' );

wp_clear_scheduled_hook( 'smtp_purge_logs' );

// The table name is built from the trusted `$wpdb->prefix` only.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}smtp_logs" );
