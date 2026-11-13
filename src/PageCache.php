<?php
namespace CacheManagerForCloudPanel;

/**
 * The server page cache (Varnish), cleared through the local cache service.
 *
 * Whole-site clears use the durable InvalidationQueue, exactly like automatic
 * invalidation, so a manual clear gets the same record-then-send and retry
 * guarantees. Single pages use a direct URL ban.
 */
final class PageCache implements UrlLayer
{
    private const MAX_TARGET_LENGTH = 2048;

    /** Same campaign parameters the server policy strips before looking up a page. */
    private const TRACKING_PARAMETER = '/^(utm_[a-z]+|gclid|gbraid|wbraid|fbclid|msclkid|mc_cid|mc_eid)$/';

    public function __construct(
        private Configuration $configuration,
        private InvalidationQueue $queue,
        private CacheWarmer $warmer,
    ) {}

    public function label(): string
    {
        return __('Page cache', 'cache-manager-for-cloudpanel');
    }

    public function icon(): string
    {
        return 'layers';
    }

    public function actions(): array
    {
        return ['page-cache' => __('Clear page cache', 'cache-manager-for-cloudpanel')];
    }

    public function perform(string $action): Result
    {
        return $this->clear();
    }

    public function unavailable(): string
    {
        if (!$this->configuration->switchedOn()) {
            return __('The page cache is not switched on for this site in wp-config.php.', 'cache-manager-for-cloudpanel');
        }
        return $this->configuration->error();
    }

    /**
     * Why the page cache needs attention, or '' when it is healthy or simply not
     * switched on (a supported setup: the other caches still work). Shown by the
     * admin bar indicator and the status card.
     */
    public function attention(): string
    {
        if (!$this->configuration->switchedOn()) {
            return '';
        }
        if (!$this->configuration->enabled()) {
            return sprintf(
                /* translators: %s: why the page cache is off */
                __('Page cache is off: %s', 'cache-manager-for-cloudpanel'),
                $this->configuration->error()
            );
        }
        if (!$this->configuration->available()) {
            return __('Page cache is bypassed: the cache service is not ready, so pages are served uncached until it recovers.', 'cache-manager-for-cloudpanel');
        }
        if ($this->queue->failed()) {
            return $this->queue->pending() > 0
                ? __('A page cache clear failed. It is queued and retries automatically.', 'cache-manager-for-cloudpanel')
                : __('A content change could not be recorded for the page cache. Clear the page cache once the database has recovered.', 'cache-manager-for-cloudpanel');
        }
        return '';
    }

    public function status(): array
    {
        if (!$this->configuration->enabled()) {
            // unavailable() is shown with the status, so no detail repeats it.
            return [
                'state' => __('Off', 'cache-manager-for-cloudpanel'),
                'tone' => $this->configuration->switchedOn() ? 'warning' : 'neutral',
                'details' => [],
            ];
        }
        $details = [
            __('Keeps pages for', 'cache-manager-for-cloudpanel') => human_time_diff(0, $this->configuration->originTtl()),
        ];
        $attention = $this->attention();
        if ($attention !== '') {
            $details = [__('Problem', 'cache-manager-for-cloudpanel') => $attention] + $details;
        }
        $status = [
            'state' => !$this->configuration->available()
                ? __('Bypassed', 'cache-manager-for-cloudpanel')
                : ($attention !== '' ? __('Needs attention', 'cache-manager-for-cloudpanel') : __('Ready', 'cache-manager-for-cloudpanel')),
            'tone' => $attention !== '' ? 'warning' : 'good',
            'details' => $details,
        ];
        $warm = CacheWarmer::lastRun();
        if ($warm !== null) {
            $status['meter'] = [
                'label' => __('Last warm-up', 'cache-manager-for-cloudpanel'),
                'value' => $warm['warmed'],
                'max' => $warm['total'],
                'text' => sprintf(
                    /* translators: 1: pages preloaded, 2: pages attempted, 3: time since, e.g. "5 mins ago" */
                    __('%1$d of %2$d pages, %3$s', 'cache-manager-for-cloudpanel'),
                    $warm['warmed'],
                    $warm['total'],
                    CacheLayers::ago($warm['time'])
                ),
            ];
        }
        return $status;
    }

    public function clear(): Result
    {
        if (!$this->queue->clearNow()) {
            return Result::error($this->queue->pending() > 0
                ? __('The cache service did not confirm the clear. It is queued and retries automatically.', 'cache-manager-for-cloudpanel')
                : __('The clear could not be recorded in the database, so it will not be retried. Try again once the database has recovered.', 'cache-manager-for-cloudpanel'));
        }
        return Result::success($this->withWarmup(
            __('Cleared the whole site.', 'cache-manager-for-cloudpanel'),
            $this->warmer->schedule()
        ));
    }

    public function clearUrl(string $url): Result
    {
        $target = self::requestTarget($url, $this->configuration->host());
        if (is_wp_error($target)) {
            return Result::error($target->get_error_message());
        }
        $response = $this->configuration->purge($target);
        $policy = Configuration::policy($response);
        if ($policy === Configuration::URL_POLICY) {
            // The service repeats each ban about a minute later, which catches a
            // copy that was being fetched while the first ban ran.
            return Result::success($this->withWarmup(
                __('Cleared this page. An old copy can take up to a minute to disappear.', 'cache-manager-for-cloudpanel'),
                $this->warmer->schedule([$url])
            ));
        }
        if ($policy === Configuration::SITE_POLICY) {
            // Older services ignore the URL and start a new site revision; newer ones
            // do the same when a ban is refused or too many are waiting.
            return Result::success($this->withWarmup(
                __('Cleared the whole site: the cache service could not clear just this page.', 'cache-manager-for-cloudpanel'),
                $this->warmer->schedule()
            ));
        }
        // Any other answer leaves the page's freshness unknown. Fall back to the
        // durable whole-site clear, which retries until the service confirms it.
        $site = $this->clear();
        if (!$site->ok()) {
            return $site;
        }
        return Result::success(sprintf(
            /* translators: 1: why the page could not be cleared on its own, 2: result of the whole-site clear */
            __('Could not clear just this page (%1$s). %2$s', 'cache-manager-for-cloudpanel'),
            self::describe($response),
            $site->message
        ));
    }

    /**
     * Check the purge key and the path to Varnish with the service's OPTIONS
     * check, which never clears anything.
     */
    public function test(): Result
    {
        $response = $this->configuration->check();
        $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        if ($code === 200 && Configuration::policy($response) !== '') {
            return Result::success(__('The cache service accepted the purge key and can reach Varnish. Nothing was cleared.', 'cache-manager-for-cloudpanel'));
        }
        return Result::error(match ($code) {
            403 => __('The cache service rejected the purge key in wp-config.php, or does not know this site\'s host.', 'cache-manager-for-cloudpanel'),
            400 => __('The cache service refused the check as malformed (HTTP 400).', 'cache-manager-for-cloudpanel'),
            503 => __('The purge key is valid, but the cache service cannot reach Varnish, so clears would fail.', 'cache-manager-for-cloudpanel'),
            501 => __('The CloudPanel Companion on this server is too old to check the connection. Nothing was cleared; ask the server operator to update the Companion.', 'cache-manager-for-cloudpanel'),
            default => sprintf(
                /* translators: %s: error or HTTP status */
                __('The cache service did not answer as expected (%s).', 'cache-manager-for-cloudpanel'),
                self::describe($response)
            ),
        });
    }

    /**
     * The path and query to ban, as visitors request it: percent-encoded and
     * without campaign parameters, which the server policy strips before lookup.
     * The value travels in a request header, so anything outside printable ASCII
     * is encoded or rejected here rather than by the service.
     */
    public static function requestTarget(string $url, string $host): string|\WP_Error
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || strtolower($parts['host'] ?? '') !== $host) {
            return new \WP_Error('cache_manager_for_cloudpanel_url', __('That address is not on this site.', 'cache-manager-for-cloudpanel'));
        }
        $target = (string) ($parts['path'] ?? '/');
        $target = $target === '' ? '/' : $target;
        if (isset($parts['query']) && $parts['query'] !== '') {
            $kept = array_filter(explode('&', $parts['query']), static function (string $pair): bool {
                return $pair !== '' && !preg_match(self::TRACKING_PARAMETER, explode('=', $pair, 2)[0]);
            });
            if ($kept) {
                $target .= '?' . implode('&', $kept);
            }
        }
        $target = preg_replace_callback('/[\x80-\xFF ]/', static fn (array $byte): string => rawurlencode($byte[0]), $target);
        if (strlen($target) > self::MAX_TARGET_LENGTH || !preg_match('#^/[\x21-\x7E]*$#D', $target)) {
            return new \WP_Error('cache_manager_for_cloudpanel_url', __('That address cannot be cleared on its own.', 'cache-manager-for-cloudpanel'));
        }
        return $target;
    }

    private static function describe(array|\WP_Error $response): string
    {
        return is_wp_error($response)
            ? $response->get_error_message()
            /* translators: %d: HTTP status code */
            : sprintf(__('HTTP %d', 'cache-manager-for-cloudpanel'), (int) wp_remote_retrieve_response_code($response));
    }

    private function withWarmup(string $message, int $pages): string
    {
        if ($pages === 0) {
            return $message;
        }
        return $message . ' ' . sprintf(
            /* translators: %d: number of pages */
            _n('%d page will be preloaded.', '%d pages will be preloaded.', $pages, 'cache-manager-for-cloudpanel'),
            $pages
        );
    }
}
