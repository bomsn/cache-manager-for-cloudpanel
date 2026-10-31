<?php
namespace CacheManagerForCloudPanel;

final class Administration
{
    public function __construct(private Configuration $configuration, private InvalidationQueue $queue) {}

    public function register(): void
    {
        add_action('admin_menu', function (): void {
            add_management_page('Cache Manager for CloudPanel', 'CloudPanel cache', 'manage_options',
                'cache-manager-for-cloudpanel', [$this, 'render']);
        });
        add_action('admin_post_cache_manager_for_cloudpanel_purge', [$this, 'purge']);
    }

    public function purge(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('You cannot manage this cache.', '', ['response' => 403]);
        }
        check_admin_referer('cache_manager_for_cloudpanel_purge');
        $this->queue->enqueue('site');
        $this->queue->drain();
        wp_safe_redirect(admin_url('tools.php?page=cache-manager-for-cloudpanel'));
        exit;
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        echo '<div class="wrap"><h1>Cache Manager for CloudPanel</h1>';
        echo '<p>Public HTML caching is managed together with the server policy. Private requests remain uncached.</p>';
        $status = $this->configuration->enabled()
            ? ($this->configuration->available() ? 'Local public-page cache service is ready.' : 'Local page cache is bypassed while its revision service is unavailable.')
            : 'WordPress cache integration disabled.';
        echo '<p>' . esc_html($status . ' ' . $this->configuration->error()) . '</p>';
        $failure = get_option('cache_manager_for_cloudpanel_error', '');
        if ($failure) {
            echo '<div class="notice notice-error inline"><p>' . esc_html($failure) . '</p></div>';
        }
        echo '<h2>Object cache</h2><p>' . esc_html(file_exists(WP_CONTENT_DIR . '/object-cache.php')
            ? 'An object-cache drop-in already exists. This plugin does not replace it.'
            : 'No object-cache drop-in is installed.') . '</p>';
        echo '<p>' . esc_html('PHP Redis extension: ' . (extension_loaded('redis') ? 'available' : 'absent') .
            '. PHP Memcached extension: ' . (extension_loaded('memcached') ? 'available' : 'absent') .
            '. Extension availability does not prove a cache service is reachable.') . '</p>';
        if (defined('CACHE_MANAGER_FOR_CLOUDPANEL_OBJECT_CACHE') && CACHE_MANAGER_FOR_CLOUDPANEL_OBJECT_CACHE === true &&
            is_plugin_active('redis-cache/redis-cache.php')) {
            echo '<p>' . esc_html(wp_using_ext_object_cache() ? 'Persistent object cache is active.' : 'Persistent object cache is disabled.') . '</p>';
            echo '<p>Redis has a dedicated service for this website. Use its provider controls to enable or disable it.</p>';
            echo '<p><a class="button" href="' . esc_url(admin_url('options-general.php?page=redis-cache')) . '">Manage Redis object cache</a></p>';
        } else {
            echo '<p>Existing providers are preserved. Your server operator can connect a dedicated Redis service when no provider is installed. Memcached detection does not automatically replace a provider or install an unqualified drop-in.</p>';
        }
        if ($this->configuration->enabled()) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('cache_manager_for_cloudpanel_purge');
            echo '<input type="hidden" name="action" value="cache_manager_for_cloudpanel_purge">';
            submit_button('Purge local page cache');
            echo '</form>';
        }
        echo '<p>Cloudflare and other cache layers are managed separately.</p></div>';
    }
}
