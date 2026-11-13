<?php
namespace CacheManagerForCloudPanel;

/**
 * Caches WordPress and its plugins keep for themselves: expired transients and,
 * when Elementor is active, its generated CSS files.
 */
final class WordPressCaches implements Layer
{
    public function __construct(private InvalidationQueue $queue) {}

    public function label(): string
    {
        return __('WordPress', 'cache-manager-for-cloudpanel');
    }

    public function icon(): string
    {
        return 'brush';
    }

    public function actions(): array
    {
        $actions = ['transients' => __('Clean expired transients', 'cache-manager-for-cloudpanel')];
        if (self::elementorActive()) {
            $actions['elementor-css'] = __('Regenerate Elementor CSS', 'cache-manager-for-cloudpanel');
        }
        return $actions;
    }

    public function perform(string $action): Result
    {
        return match ($action) {
            'transients' => $this->cleanTransients(),
            'elementor-css' => $this->regenerateElementorCss(),
        };
    }

    public function unavailable(): string
    {
        return '';
    }

    public function clear(): Result
    {
        $results = array_map([$this, 'perform'], array_keys($this->actions()));
        $messages = implode(' ', array_map(static fn (Result $result): string => $result->message, $results));
        foreach ($results as $result) {
            if (!$result->ok()) {
                return Result::error($messages);
            }
        }
        return Result::success($messages);
    }

    public function status(): array
    {
        $cleans = [__('Expired transients', 'cache-manager-for-cloudpanel')];
        if (self::elementorActive()) {
            $cleans[] = __('Elementor CSS', 'cache-manager-for-cloudpanel');
        }
        return [
            'state' => __('Available', 'cache-manager-for-cloudpanel'),
            'tone' => 'good',
            'details' => [__('Cleans', 'cache-manager-for-cloudpanel') => implode(', ', $cleans)],
        ];
    }

    private function cleanTransients(): Result
    {
        // Forced to the database as well: transients written before a persistent
        // object cache was enabled stay there and are never expired by it.
        delete_expired_transients(true);
        return Result::success(__('Removed expired transients.', 'cache-manager-for-cloudpanel'));
    }

    private function regenerateElementorCss(): Result
    {
        if (!self::elementorActive()) {
            return Result::skipped(__('Elementor is not active.', 'cache-manager-for-cloudpanel'));
        }
        \Elementor\Plugin::$instance->files_manager->clear_cache();
        // Cached pages still link to the CSS files Elementor just deleted, so the
        // page cache is cleared with them when this request ends.
        $this->queue->enqueue();
        return Result::success(__('Elementor CSS will be rebuilt on each page\'s next visit.', 'cache-manager-for-cloudpanel'));
    }

    private static function elementorActive(): bool
    {
        return did_action('elementor/loaded') && class_exists('\Elementor\Plugin') &&
            isset(\Elementor\Plugin::$instance->files_manager);
    }
}
