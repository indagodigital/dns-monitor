<?php
/**
 * Uninstall DNS Monitor Plugin
 * 
 * This file is called when the plugin is uninstalled (deleted) from WordPress.
 * It cleans up all plugin data including database tables, options, and scheduled events.
 *
 * @package DNS_Monitor
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Define plugin constants if not already defined.
if ( ! defined( 'DNS_MONITOR_PLUGIN_DIR' ) ) {
	define( 'DNS_MONITOR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

/**
 * Remove plugin data based on user settings
 */
function dns_monitor_uninstall_cleanup() {
	global $wpdb;

	// Check user setting for data deletion.
	$delete_data = get_option( 'dns_monitor_delete_data_on_uninstall', 'no' );

	// Always clear scheduled cron events (these should be cleaned up regardless of setting).
	wp_clear_scheduled_hook( 'dns_monitor_check' );

	// Only delete data and tables if user chose to delete data.
	if ( 'yes' === $delete_data ) {
		// Remove all plugin options.
		delete_option( 'dns_monitor_check_frequency' );
		delete_option( 'dns_monitor_notification_email' );
		delete_option( 'dns_monitor_snapshot_behavior' );
		delete_option( 'dns_monitor_delete_data_on_uninstall' );
		delete_option( 'dns_monitor_version' );

		// Drop plugin database tables.
		$table_prefix = $wpdb->prefix . 'dns_';
		
		// Drop records table first (has foreign key reference).
		$wpdb->query( "DROP TABLE IF EXISTS {$table_prefix}records" );
		
		// Drop snapshots table.
		$wpdb->query( "DROP TABLE IF EXISTS {$table_prefix}snapshots" );

		// Clear any cached data.
		wp_cache_flush();
	}
}

// Run the cleanup.
dns_monitor_uninstall_cleanup(); 