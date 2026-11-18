<?php
namespace CacheManagerForCloudPanel;

/**
 * Everything one user action did, one line per cache layer.
 *
 * Notices, the command line and tests all read this, so a skipped or failed layer
 * is never hidden behind a single success flag.
 */
final class Report
{
    /** @var list<array{label: string, status: string, message: string}> */
    private array $entries = [];

    public function __construct(public readonly string $title) {}

    public function add(string $label, Result $result): void
    {
        $this->entries[] = ['label' => $label, 'status' => $result->status, 'message' => $result->message];
    }

    /** @return list<array{label: string, status: string, message: string}> */
    public function entries(): array
    {
        return $this->entries;
    }

    public function failed(): bool
    {
        return in_array(Result::ERROR, array_column($this->entries, 'status'), true);
    }

    public function succeeded(): bool
    {
        return in_array(Result::SUCCESS, array_column($this->entries, 'status'), true);
    }

    /**
     * Notice tone: "warning" covers both a partial failure and an action that
     * cleared nothing, because neither is the clean result the user asked for.
     */
    public function tone(): string
    {
        if ($this->failed()) {
            return $this->succeeded() ? 'warning' : 'error';
        }
        return $this->succeeded() ? 'success' : 'warning';
    }

    /** How many layers succeeded, were skipped and failed, e.g. "3 succeeded, 1 skipped". */
    public function summary(): string
    {
        $counts = array_count_values(array_column($this->entries, 'status'));
        $parts = [];
        foreach ([Result::SUCCESS, Result::SKIPPED, Result::ERROR] as $status) {
            $count = $counts[$status] ?? 0;
            if ($count > 0) {
                $parts[] = sprintf(match ($status) {
                    /* translators: %d: number of caches */
                    Result::SUCCESS => _n('%d succeeded', '%d succeeded', $count, 'cache-manager-for-cloudpanel'),
                    /* translators: %d: number of caches */
                    Result::SKIPPED => _n('%d skipped', '%d skipped', $count, 'cache-manager-for-cloudpanel'),
                    /* translators: %d: number of caches */
                    Result::ERROR => _n('%d failed', '%d failed', $count, 'cache-manager-for-cloudpanel'),
                }, $count);
            }
        }
        return implode(', ', $parts);
    }

    /** @return array{title: string, tone: string, summary: string, entries: list<array{label: string, status: string, message: string}>} */
    public function toArray(): array
    {
        return ['title' => $this->title, 'tone' => $this->tone(), 'summary' => $this->summary(), 'entries' => $this->entries];
    }
}
