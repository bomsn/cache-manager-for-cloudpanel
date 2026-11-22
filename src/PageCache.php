<?php
namespace CacheManagerForCloudPanel;

/**
 * The server page cache (Varnish).
 *
 * Whole-site clears go through the durable InvalidationQueue, like automatic
 * invalidation, so a manual clear is recorded first and retried until the server
 * confirms it. Single pages go straight to the server.
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
        if (!$this->configuration->available()) {
            return sprintf(
                /* translators: %s: cache server name, e.g. "CloudPanel Companion" */
                __('Page cache is bypassed: %s is not ready, so pages are served uncached until it recovers.', 'cache-manager-for-cloudpanel'),
                $this->configuration->server()->label()
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
        $server = $this->configuration->server();
        $details = [
            __('Cache server', 'cache-manager-for-cloudpanel') => $server->label(),
            __('Keeps pages for', 'cache-manager-for-cloudpanel') => $server->ttl() > 0
                ? human_time_diff(0, $server->ttl())
                : __('until cleared', 'cache-manager-for-cloudpanel'),
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
        $outcome = $this->configuration->server()->clearPage($url);
        if ($outcome === PageCacheServer::PAGE) {
            return Result::success($this->withWarmup(
                __('Cleared this page.', 'cache-manager-for-cloudpanel'),
                $this->warmer->schedule([$url])
            ));
        }
        if ($outcome === PageCacheServer::SITE) {
            return Result::success($this->withWarmup(
                __('Cleared the whole site: the page cache could not clear just this page.', 'cache-manager-for-cloudpanel'),
                $this->warmer->schedule()
            ));
        }
        // An unconfirmed page clear leaves the page possibly stale; the queued
        // whole-site clear retries until it is confirmed.
        $site = $this->clear();
        if (!$site->ok()) {
            return $site;
        }
        return Result::success(sprintf(
            /* translators: 1: why the page could not be cleared on its own, 2: result of the whole-site clear */
            __('Could not clear just this page (%1$s). %2$s', 'cache-manager-for-cloudpanel'),
            $outcome,
            $site->message
        ));
    }

    /** Check the connection to the page cache server, without clearing any page. */
    public function test(): Result
    {
        return $this->configuration->server()->test();
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
