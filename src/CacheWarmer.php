<?php
namespace CacheManagerForCloudPanel;

/**
 * Preloads key pages after the page cache is cleared, so the first visitors after
 * a clear get cached pages instead of waiting for WordPress to build them.
 */
final class CacheWarmer
{
    public const HOOK = 'cache_manager_for_cloudpanel_warm';
    public const LAST_OPTION = 'cache_manager_for_cloudpanel_last_warm';

    public function __construct(private Configuration $configuration, private Settings $settings) {}

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'warm']);
    }

    /**
     * Queue a warm-up in WP-Cron, so the administrator who cleared the cache is not
     * kept waiting for the requests.
     *
     * @param list<string>|null $urls Pages to preload, or null for the home page and
     *                                main menu.
     * @return int Number of pages queued; 0 when warming is off or nothing qualifies.
     */
    public function schedule(?array $urls = null): int
    {
        if (!$this->settings->get('warm')) {
            return 0;
        }
        if ($urls === null) {
            $urls = (array) apply_filters('cache_manager_for_cloudpanel_warm_urls', $this->keyPages());
            // WP-Cron drops a new event identical to one still pending, and a full
            // warm-up should replace an older one rather than run against the
            // previous URL list. A single-page warm-up is added alongside instead.
            wp_unschedule_hook(self::HOOK);
        }
        $urls = $this->eligible($urls);
        if (!$urls || wp_schedule_single_event(time(), self::HOOK, [$urls]) !== true) {
            return 0;
        }
        return count($urls);
    }

    /** WP-Cron callback. */
    public function warm(array $urls): void
    {
        $urls = $this->eligible($urls);
        $warmed = 0;
        foreach ($urls as $index => $url) {
            if ($index > 0) {
                // A short pause keeps warming from competing with real visitors.
                usleep(200000);
            }
            // Blocking on purpose: a fire-and-forget request is closed before the page
            // is stored. No cookies are sent, so the response is the public copy.
            $response = wp_remote_get($url, [
                'timeout' => 5,
                'redirection' => 0,
                'user-agent' => 'Cache Manager for CloudPanel warm-up',
                'headers' => ['Accept' => 'text/html'],
            ]);
            if (!is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) === 200) {
                $warmed++;
            }
        }
        update_option(self::LAST_OPTION, ['time' => time(), 'warmed' => $warmed, 'total' => count($urls)], false);
    }

    /** @return array{time: int, warmed: int, total: int}|null */
    public static function lastRun(): ?array
    {
        $run = get_option(self::LAST_OPTION);
        return is_array($run) && isset($run['time'], $run['warmed'], $run['total']) ? $run : null;
    }

    /**
     * The home page plus the main menu. "primary" is the conventional location
     * name; themes that use another name get their first assigned menu.
     *
     * @return list<string>
     */
    private function keyPages(): array
    {
        $urls = [home_url('/')];
        $locations = array_filter(get_nav_menu_locations());
        $menu = $locations['primary'] ?? reset($locations);
        if ($menu) {
            foreach ((array) wp_get_nav_menu_items($menu) as $item) {
                // "_invalid" marks items whose page is no longer published.
                if (is_object($item) && empty($item->_invalid) && !empty($item->url)) {
                    $urls[] = (string) $item->url;
                }
            }
        }
        return $urls;
    }

    /**
     * Distinct http(s) URLs on this site's host, capped by the warming limit. URLs
     * with a query string are dropped because the page cache never stores them.
     *
     * @return list<string>
     */
    private function eligible(array $urls): array
    {
        $origin = $this->configuration->origin();
        $host = $this->configuration->host();
        $keep = [];
        foreach ($urls as $url) {
            if (!is_string($url) || $url === '') {
                continue;
            }
            $url = explode('#', $url, 2)[0];
            if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
                $url = $origin . $url;
            }
            $parts = wp_parse_url($url);
            if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) ||
                strtolower($parts['host'] ?? '') !== $host || isset($parts['query'])) {
                continue;
            }
            $keep[$url] = true;
        }
        return array_slice(array_keys($keep), 0, $this->settings->warmLimit());
    }
}
