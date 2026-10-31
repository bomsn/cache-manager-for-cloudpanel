<?php
namespace CacheManagerForCloudPanel;

final class Command
{
    public function __construct(private Configuration $configuration, private InvalidationQueue $queue) {}

    /** Refresh all local public HTML for this site, without flushing object caches. */
    public function purge(): void
    {
        if (!$this->configuration->enabled()) {
            \WP_CLI::error('Cache integration is disabled. ' . $this->configuration->error());
        }
        $this->queue->enqueue('site');
        $this->queue->drain();
        if ($this->queue->pending() > 0 || get_option('cache_manager_for_cloudpanel_error', '')) {
            \WP_CLI::error('Invalidation is still pending. Check the local cache service and retry.');
        }
        \WP_CLI::success('The local page cache acknowledged the new site revision.');
    }
}
