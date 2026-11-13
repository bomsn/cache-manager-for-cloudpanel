<?php
namespace CacheManagerForCloudPanel;

/**
 * The registry of cache layers and the one place that runs operations on them.
 *
 * Every operation reports each layer on its own (cleared, skipped with a reason,
 * or failed), and a layer that throws is reported as failed without stopping the
 * others.
 */
final class CacheLayers
{
    /**
     * Inner caches first, so each outer cache refills from fresh data: the page
     * cache must not store a page built from stale objects, and Cloudflare must
     * not refetch a stale copy from the page cache.
     */
    private const CLEARING_ORDER = ['wordpress', 'object_cache', 'page_cache', 'cloudflare'];

    /** @var array<string, Layer> Layer ID => layer, in display order. */
    private array $layers;

    public function __construct(
        private Configuration $configuration,
        private Settings $settings,
        private PageCache $pageCache,
        private Cloudflare $cloudflare,
        ObjectCache $objectCache,
        WordPressCaches $wordpress,
    ) {
        $this->layers = [
            'page_cache' => $pageCache,
            'cloudflare' => $cloudflare,
            'object_cache' => $objectCache,
            'wordpress' => $wordpress,
        ];
    }

    /** @return array<string, Layer> */
    public function layers(): array
    {
        return $this->layers;
    }

    public function layer(string $id): ?Layer
    {
        return $this->layers[$id] ?? null;
    }

    /**
     * The layer's own status (see Layer::status()), plus "cleared": the Unix time
     * it was last cleared from this plugin, or 0. run() records it for every
     * layer, so every layer that can be cleared has it.
     *
     * @return array{state: string, tone: string, details: array<string, string>,
     *               meter?: array{label: string, value: int, max: int, text: string}, cleared?: int}
     */
    public function status(string $id): array
    {
        $layer = $this->layers[$id];
        $status = $layer->status();
        if ($layer->unavailable() === '') {
            $status['cleared'] = $this->settings->lastCleared($id);
        }
        return $status;
    }

    /**
     * The one-line state at the top of Settings > Cache: which layers need
     * attention, or how many are ready.
     *
     * @param array<string, array{tone: string}> $statuses Layer ID => status($id), for every layer.
     * @return array{tone: string, title: string, text: string} Tone is "good" or "warning".
     */
    public function overview(array $statuses): array
    {
        $attention = [];
        foreach ($statuses as $id => $status) {
            if ($status['tone'] === 'warning') {
                $attention[] = $this->layers[$id]->label();
            }
        }
        $text = $this->configuration->enabled()
            ? __('Content changes clear the page cache automatically.', 'cache-manager-for-cloudpanel')
            : __('The page cache is off. The other caches can still be cleared from here.', 'cache-manager-for-cloudpanel');
        if ($attention) {
            return [
                'tone' => 'warning',
                'title' => sprintf(
                    /* translators: %s: cache names, e.g. "Page cache, Object cache" */
                    _n('%s needs attention', '%s need attention', count($attention), 'cache-manager-for-cloudpanel'),
                    implode(', ', $attention)
                ),
                'text' => $text,
            ];
        }
        $ready = count(array_filter($statuses, static fn (array $status): bool => $status['tone'] === 'good'));
        return [
            'tone' => 'good',
            'title' => sprintf(
                /* translators: 1: caches ready, 2: all caches */
                _n('%1$d of %2$d cache is ready', '%1$d of %2$d caches are ready', count($statuses), 'cache-manager-for-cloudpanel'),
                $ready,
                count($statuses)
            ),
            'text' => $text,
        ];
    }

    /** @return list<string> Layer IDs that Clear all runs. */
    public function selectedForClearAll(): array
    {
        return $this->settings->get('clear_all') ?? array_keys($this->layers);
    }

    public function clearAll(): Report
    {
        $report = new Report(__('Clear all caches', 'cache-manager-for-cloudpanel'));
        $selected = $this->selectedForClearAll();
        foreach (self::CLEARING_ORDER as $id) {
            if (!in_array($id, $selected, true)) {
                $report->add($this->layers[$id]->label(), Result::skipped(__('Not included in Clear all (Settings > Cache).', 'cache-manager-for-cloudpanel')));
                continue;
            }
            $this->run($report, $id, static fn (Layer $layer): Result => $layer->clear());
        }
        // Every route to Clear all (notice, admin bar, settings page, WP-CLI) runs
        // here. A clean run covers whatever recent plugin or theme changes left stale.
        if ($report->succeeded() && !$report->failed()) {
            ChangeNotice::forget();
        }
        return $report;
    }

    public function clear(string $id): Report
    {
        $report = new Report($this->layers[$id]->label());
        $this->run($report, $id, static fn (Layer $layer): Result => $layer->clear());
        return $report;
    }

    /**
     * Clear one page in every layer that can drop single pages.
     *
     * @param list<string>|null $ids Restrict to these layers; null for all of them.
     */
    public function clearUrl(string $url, ?array $ids = null): Report
    {
        $report = new Report(__('Clear this page', 'cache-manager-for-cloudpanel'));
        foreach ($this->layers as $id => $layer) {
            if ($layer instanceof UrlLayer && ($ids === null || in_array($id, $ids, true))) {
                $this->run($report, $id, static fn (UrlLayer $layer): Result => $layer->clearUrl($url), false);
            }
        }
        return $report;
    }

    /** Whether a layer offers $action (see Layer::actions()). */
    public function offers(string $action): bool
    {
        return $this->owner($action) !== null;
    }

    /** Run a manual action that offers() confirmed. */
    public function perform(string $action): Report
    {
        $id = $this->owner($action);
        $report = new Report($this->layers[$id]->actions()[$action]);
        $this->run($report, $id, static fn (Layer $layer): Result => $layer->perform($action));
        return $report;
    }

    /** Check the layers that talk to a remote service, without clearing anything. */
    public function test(): Report
    {
        $report = new Report(__('Test connections', 'cache-manager-for-cloudpanel'));
        $this->run($report, 'page_cache', fn (): Result => $this->pageCache->test(), false);
        $this->run($report, 'cloudflare', fn (): Result => $this->cloudflare->test(), false);
        return $report;
    }

    /** True when $url is on this site's host, the only host the layers may clear. */
    public function ownsUrl(string $url): bool
    {
        return strtolower((string) wp_parse_url($url, PHP_URL_HOST)) === $this->configuration->host();
    }

    /** "5 mins ago", or "Not yet" for 0. */
    public static function ago(int $time): string
    {
        return $time > 0
            /* translators: %s: time span, e.g. "5 mins" */
            ? sprintf(__('%s ago', 'cache-manager-for-cloudpanel'), human_time_diff($time))
            : __('Not yet', 'cache-manager-for-cloudpanel');
    }

    /** ID of the layer that offers $action, or null. */
    private function owner(string $action): ?string
    {
        foreach ($this->layers as $id => $layer) {
            if (isset($layer->actions()[$action])) {
                return $id;
            }
        }
        return null;
    }

    /**
     * @param callable(Layer): Result $operation
     * @param bool $clears Whether success counts as a clear for "Last cleared".
     */
    private function run(Report $report, string $id, callable $operation, bool $clears = true): void
    {
        $layer = $this->layers[$id];
        $reason = $layer->unavailable();
        try {
            $result = $reason !== '' ? Result::skipped($reason) : $operation($layer);
        } catch (\Throwable $error) {
            // A plugin API that changed or a backend that dropped its connection
            // must not stop the remaining layers from being cleared.
            $result = Result::error($error->getMessage());
        }
        if ($clears && $result->ok()) {
            $this->settings->markCleared($id);
        }
        $report->add($layer->label(), $result);
    }
}
