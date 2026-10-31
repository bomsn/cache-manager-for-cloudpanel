<?php
/**
 * Plugin Name: Cache Manager for CloudPanel
 * Description: Explicit public-page policy, content invalidation and cache diagnostics for operator-managed CloudPanel sites.
 * Version: 0.2.1
 * Author: Ali Khallad
 * Update URI: https://github.com/bomsn/cache-manager-for-cloudpanel
 * Requires at least: 6.2
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 * Text Domain: cache-manager-for-cloudpanel
 */

namespace CacheManagerForCloudPanel;

if (!defined('ABSPATH')) {
    exit;
}

spl_autoload_register(static function (string $class): void {
    $prefix = __NAMESPACE__ . '\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $name = substr($class, strlen($prefix));
    if (preg_match('/^[A-Za-z]+$/', $name)) {
        $file = __DIR__ . '/src/' . $name . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

register_activation_hook(__FILE__, [InvalidationQueue::class, 'install']);
register_deactivation_hook(__FILE__, static function (): void {
    $configuration = new Configuration();
    if (defined('CACHE_MANAGER_FOR_CLOUDPANEL_ENABLED') && CACHE_MANAGER_FOR_CLOUDPANEL_ENABLED === true) {
        if (!$configuration->enabled()) {
            wp_die('Cache integration cannot be safely deactivated with invalid settings. Restore its configuration or have the server operator bypass the page cache first.');
        }
        $queue = new InvalidationQueue($configuration);
        $queue->enqueue('site');
        $queue->drain();
        // Keep the plugin and its retry handler active if the server has not
        // acknowledged invalidation. Otherwise old public HTML could outlive it.
        if ($queue->pending() > 0 || get_option('cache_manager_for_cloudpanel_error', '')) {
            wp_die('The page cache could not be cleared, so the plugin remains active. Retry after the cache service recovers, or ask the server operator to bypass it first.');
        }
    }
    wp_clear_scheduled_hook('cache_manager_for_cloudpanel_retry');
});

add_action('plugins_loaded', static function (): void {
    $configuration = new Configuration();
    $queue = new InvalidationQueue($configuration);
    (new ContentInvalidation($queue))->register();
    (new PublicPage($configuration))->register();
    (new Administration($configuration, $queue))->register();
    if (defined('WP_CLI') && WP_CLI) {
        \WP_CLI::add_command('cloudpanel-cache', new Command($configuration, $queue));
    }
    add_action('cache_manager_for_cloudpanel_retry', [$queue, 'drain']);
    add_action('shutdown', [$queue, 'drain'], PHP_INT_MAX);
});
