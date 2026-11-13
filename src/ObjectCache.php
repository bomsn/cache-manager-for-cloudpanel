<?php
namespace CacheManagerForCloudPanel;

/**
 * The persistent object cache (Redis, Memcached or another drop-in).
 *
 * The provider is never replaced or configured here; this layer only reports it
 * and flushes it. With a private per-site cache service a flush affects only this
 * site; on a shared service it flushes whatever the provider's flush covers.
 */
final class ObjectCache implements Layer
{
    public function label(): string
    {
        return __('Object cache', 'cache-manager-for-cloudpanel');
    }

    public function icon(): string
    {
        return 'database';
    }

    public function actions(): array
    {
        return ['object-cache' => __('Flush object cache', 'cache-manager-for-cloudpanel')];
    }

    public function perform(string $action): Result
    {
        return $this->clear();
    }

    public function unavailable(): string
    {
        return wp_using_ext_object_cache() ? '' : __('No persistent object cache is in use.', 'cache-manager-for-cloudpanel');
    }

    public function clear(): Result
    {
        // Only reached with a persistent backend, so a false return is a real failure
        // rather than the no-op flush of the per-request memory cache.
        return wp_cache_flush()
            ? Result::success(__('Flushed.', 'cache-manager-for-cloudpanel'))
            : Result::error(__('The object cache refused to flush. Its service may be unreachable.', 'cache-manager-for-cloudpanel'));
    }

    public function status(): array
    {
        if (!wp_using_ext_object_cache()) {
            return ['state' => __('Not in use', 'cache-manager-for-cloudpanel'), 'tone' => 'neutral', 'details' => []];
        }
        $connected = $this->connected();
        return [
            'state' => $connected === false
                ? __('Not connected', 'cache-manager-for-cloudpanel')
                : __('Connected', 'cache-manager-for-cloudpanel'),
            'tone' => $connected === false ? 'warning' : 'good',
            'details' => [__('Provider', 'cache-manager-for-cloudpanel') => $this->provider()],
        ];
    }

    /** Connection state when the drop-in reports one (Redis Object Cache does); null otherwise. */
    private function connected(): ?bool
    {
        global $wp_object_cache;
        return is_object($wp_object_cache) && method_exists($wp_object_cache, 'redis_status')
            ? (bool) $wp_object_cache->redis_status()
            : null;
    }

    /** The drop-in's own plugin name, e.g. "Redis Object Cache Drop-In". */
    private function provider(): string
    {
        $dropin = WP_CONTENT_DIR . '/object-cache.php';
        if (is_file($dropin)) {
            if (!function_exists('get_plugin_data')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $name = (string) (get_plugin_data($dropin, false, false)['Name'] ?? '');
            if ($name !== '') {
                return $name;
            }
        }
        return __('Object cache drop-in', 'cache-manager-for-cloudpanel');
    }
}
