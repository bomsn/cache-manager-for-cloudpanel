<?php
namespace CacheManagerForCloudPanel;

/**
 * The server page cache (Varnish): CloudPanel's own, the Companion's, or both.
 *
 * Whole-site clears use the durable InvalidationQueue, exactly like automatic
 * invalidation, so a manual clear gets the same record-then-send and retry
 * guarantees. Single pages go to each server, which clears just the page when
 * it can.
 */
final class PageCache implements UrlLayer
{
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
            return __('No page cache was found for this site. Switch on Varnish Cache for the site in CloudPanel, or set up the CloudPanel Companion.', 'cache-manager-for-cloudpanel');
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
        $unavailable = $this->configuration->unavailable();
        if ($unavailable) {
            return sprintf(
                /* translators: %s: cache server names, e.g. "CloudPanel Companion" */
                __('Page cache is bypassed: %s is not ready, so pages are served uncached until it recovers.', 'cache-manager-for-cloudpanel'),
                self::names($unavailable)
            );
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
        $servers = $this->configuration->servers();
        $details = [
            _n('Cache server', 'Cache servers', count($servers), 'cache-manager-for-cloudpanel') => self::names($servers),
            __('Keeps pages for', 'cache-manager-for-cloudpanel') => self::lifetimes($servers),
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
                ? __('The page cache did not confirm the clear. It is queued and retries automatically.', 'cache-manager-for-cloudpanel')
                : __('The clear could not be recorded in the database, so it will not be retried. Try again once the database has recovered.', 'cache-manager-for-cloudpanel'));
        }
        return Result::success($this->withWarmup(
            __('Cleared the whole site.', 'cache-manager-for-cloudpanel'),
            $this->warmer->schedule()
        ));
    }

    public function clearUrl(string $url): Result
    {
        $servers = $this->configuration->servers();
        $scopes = [];
        $failures = [];
        foreach ($servers as $server) {
            $outcome = $server->clearPage($url);
            if (in_array($outcome, [PageCacheServer::PAGE, PageCacheServer::SITE], true)) {
                $scopes[] = $outcome;
            } else {
                $failures[] = count($servers) > 1 ? $server->label() . ': ' . $outcome : $outcome;
            }
        }
        if (!$failures && !in_array(PageCacheServer::SITE, $scopes, true)) {
            // The Companion repeats each ban about a minute later, which catches a
            // copy that was being fetched while the first ban ran.
            return Result::success($this->withWarmup(
                __('Cleared this page. An old copy can take up to a minute to disappear.', 'cache-manager-for-cloudpanel'),
                $this->warmer->schedule([$url])
            ));
        }
        if (!$failures) {
            return Result::success($this->withWarmup(
                __('Cleared the whole site: the page cache could not clear just this page.', 'cache-manager-for-cloudpanel'),
                $this->warmer->schedule()
            ));
        }
        // A server that did not confirm leaves the page's freshness unknown. Fall back
        // to the durable whole-site clear, which retries until every server confirms.
        $site = $this->clear();
        if (!$site->ok()) {
            return $site;
        }
        return Result::success(sprintf(
            /* translators: 1: why the page could not be cleared on its own, 2: result of the whole-site clear */
            __('Could not clear just this page (%1$s). %2$s', 'cache-manager-for-cloudpanel'),
            implode('; ', $failures),
            $site->message
        ));
    }

    /** Check every server the site uses, without clearing any page. */
    public function test(): Result
    {
        $servers = $this->configuration->servers();
        $messages = [];
        $failed = false;
        foreach ($servers as $server) {
            $result = $server->test();
            $failed = $failed || !$result->ok();
            $messages[] = count($servers) > 1 ? $server->label() . ': ' . $result->message : $result->message;
        }
        $message = implode(' ', $messages);
        return $failed ? Result::error($message) : Result::success($message);
    }

    /** @param list<PageCacheServer> $servers */
    private static function names(array $servers): string
    {
        return implode(', ', array_map(static fn (PageCacheServer $server): string => $server->label(), $servers));
    }

    /**
     * How long each server keeps pages: "1 hour", or with several servers
     * "1 week (CloudPanel Varnish), 1 hour (CloudPanel Companion)".
     *
     * @param list<PageCacheServer> $servers
     */
    private static function lifetimes(array $servers): string
    {
        $lifetimes = array_map(static function (PageCacheServer $server) use ($servers): string {
            $lifetime = $server->ttl() > 0 ? human_time_diff(0, $server->ttl()) : __('until cleared', 'cache-manager-for-cloudpanel');
            return count($servers) > 1 ? $lifetime . ' (' . $server->label() . ')' : $lifetime;
        }, $servers);
        return implode(', ', $lifetimes);
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
