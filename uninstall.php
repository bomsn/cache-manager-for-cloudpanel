<?php
/**
 * Removes everything the plugin stored: its options, the Cloudflare token, the
 * invalidation queue table and any scheduled events.
 *
 * The plugin's classes are not loaded during uninstall, so the names are spelled
 * out here; they match the constants in src/. Server settings (wp-config.php
 * constants, CloudPanel's Varnish settings) belong to the server and are left
 * as they are. Result notices are 60-second transients that expire on their own.
 */
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

foreach ([
    'cache_manager_for_cloudpanel_settings',
    'cache_manager_for_cloudpanel_last_cleared',
    'cache_manager_for_cloudpanel_last_warm',
    'cache_manager_for_cloudpanel_cloudflare_token',
    'cache_manager_for_cloudpanel_cloudflare_zone',
    'cache_manager_for_cloudpanel_error',
    'cache_manager_for_cloudpanel_changes',
] as $option) {
    delete_option($option);
}

foreach (['cache_manager_for_cloudpanel_retry', 'cache_manager_for_cloudpanel_warm'] as $hook) {
    wp_clear_scheduled_hook($hook);
}

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removes the plugin's own table.
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}cache_manager_for_cloudpanel_queue");
