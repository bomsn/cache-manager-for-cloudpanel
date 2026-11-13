<?php
/**
 * Plugin Name: Cache Manager for CloudPanel
 * Description: A Varnish page cache that clears itself when you edit content, and one place to clear the page cache, Cloudflare and the object cache on CloudPanel sites.
 * Version: 0.4.3
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

const VERSION = '0.4.3';
const PLUGIN_FILE = __FILE__;

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
    if ($configuration->switchedOn()) {
        if (!$configuration->enabled()) {
            wp_die(esc_html__('Cache integration cannot be safely deactivated with invalid settings. Restore its configuration or have the server operator bypass the page cache first.', 'cache-manager-for-cloudpanel'));
        }
        // Keep the plugin and its retry handler active if the server has not
        // acknowledged invalidation. Otherwise old public HTML could outlive it.
        if (!(new InvalidationQueue($configuration))->clearNow()) {
            wp_die(esc_html__('The page cache could not be cleared, so the plugin remains active. Retry after the cache service recovers, or ask the server operator to bypass it first.', 'cache-manager-for-cloudpanel'));
        }
    }
    wp_clear_scheduled_hook(InvalidationQueue::RETRY_HOOK);
    wp_clear_scheduled_hook(CacheWarmer::HOOK);
});

add_action('plugins_loaded', static function (): void {
    $configuration = new Configuration();
    $queue = new InvalidationQueue($configuration);
    (new ContentInvalidation($queue))->register();
    (new PublicPage($configuration))->register();

    $settings = new Settings();
    $warmer = new CacheWarmer($configuration, $settings);
    $warmer->register();
    $pageCache = new PageCache($configuration, $queue, $warmer);
    $layers = new CacheLayers($configuration, $settings, $pageCache, new Cloudflare($configuration, $settings),
        new ObjectCache(), new WordPressCaches($queue));
    (new Actions($layers, $configuration))->register();
    (new Notices())->register();
    (new ChangeNotice($pageCache))->register();
    (new AdminBar($layers, $pageCache))->register();
    if (is_admin()) {
        (new SettingsPage($layers, $settings))->register();
    }
    if (defined('WP_CLI') && WP_CLI) {
        \WP_CLI::add_command('cloudpanel-cache', new Command($layers));
    }
    add_action(InvalidationQueue::RETRY_HOOK, [$queue, 'drain']);
    add_action('shutdown', [$queue, 'drain'], PHP_INT_MAX);
});
