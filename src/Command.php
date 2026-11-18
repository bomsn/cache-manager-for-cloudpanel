<?php
namespace CacheManagerForCloudPanel;

/** Clear and inspect this site's caches. */
final class Command
{
    public function __construct(private CacheLayers $layers) {}

    /**
     * Clears caches. With no arguments, clears the page cache for the whole site.
     *
     * ## OPTIONS
     *
     * [<url>]
     * : Clear only this page on this site, in the page cache and Cloudflare. (A
     * positional argument because WP-CLI reserves --url for choosing the site.)
     *
     * [--layer=<layer>]
     * : page_cache, cloudflare, object_cache, wordpress, or all (the caches chosen
     * for Clear all in Settings > Cache).
     *
     * ## EXAMPLES
     *
     *     wp cloudpanel-cache purge
     *     wp cloudpanel-cache purge --layer=all
     *     wp cloudpanel-cache purge https://example.com/shop/
     */
    public function purge(array $args, array $options): void
    {
        $layer = isset($options['layer']) ? (string) $options['layer'] : null;
        if ($layer !== null && $layer !== 'all' && $this->layers->layer($layer) === null) {
            \WP_CLI::error(sprintf('Unknown layer "%s". Use %s or all.', $layer, implode(', ', array_keys($this->layers->layers()))));
        }
        if (isset($args[0])) {
            $url = (string) $args[0];
            if (!$this->layers->ownsUrl($url)) {
                \WP_CLI::error('The URL must be on this site.');
            }
            if ($layer !== null && $layer !== 'all' && !$this->layers->layer($layer) instanceof UrlLayer) {
                \WP_CLI::error(sprintf('The %s layer cannot clear a single page.', $layer));
            }
            $report = $this->layers->clearUrl($url, $layer === null || $layer === 'all' ? null : [$layer]);
        } elseif ($layer === 'all') {
            $report = $this->layers->clearAll();
        } else {
            $report = $this->layers->clear($layer ?? 'page_cache');
        }
        $prefixes = [Result::SKIPPED => 'skipped. ', Result::ERROR => 'FAILED. '];
        foreach ($report->entries() as $entry) {
            \WP_CLI::log(sprintf('%s: %s%s', $entry['label'], $prefixes[$entry['status']] ?? '', $entry['message']));
        }
        // The Companion's deploy scripts rely on a non-zero exit when the page cache
        // was not cleared, including when the integration is off.
        if ($report->failed()) {
            \WP_CLI::error('Not every cache was cleared.');
        }
        if (!$report->succeeded()) {
            \WP_CLI::error('Nothing was cleared.');
        }
        \WP_CLI::success('Done.');
    }

    /**
     * Shows the state of each cache.
     *
     * ## EXAMPLES
     *
     *     wp cloudpanel-cache status
     */
    public function status(): void
    {
        $rows = [];
        foreach ($this->layers->layers() as $id => $layer) {
            $status = $this->layers->status($id);
            $details = array_filter([$layer->unavailable()]);
            foreach ($status['details'] as $term => $value) {
                $details[] = $term . ': ' . $value;
            }
            if (isset($status['meter'])) {
                $details[] = $status['meter']['label'] . ': ' . $status['meter']['text'];
            }
            if (isset($status['cleared'])) {
                $details[] = __('Last cleared here', 'cache-manager-for-cloudpanel') . ': ' . CacheLayers::ago($status['cleared']);
            }
            $rows[] = ['layer' => $id, 'state' => $status['state'], 'details' => implode('; ', $details)];
        }
        \WP_CLI\Utils\format_items('table', $rows, ['layer', 'state', 'details']);
    }
}
