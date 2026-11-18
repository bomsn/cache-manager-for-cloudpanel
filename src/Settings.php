<?php
namespace CacheManagerForCloudPanel;

/**
 * Preferences saved from Settings > Cache.
 *
 * Server wiring (port, purge key, readiness file, origin lifetime) stays in
 * wp-config.php and is read by Configuration. This option holds only choices an
 * administrator may change at any time; the Cloudflare token is kept apart by the
 * Cloudflare layer so it is never autoloaded.
 */
final class Settings
{
    public const OPTION = 'cache_manager_for_cloudpanel_settings';
    public const LAST_CLEARED_OPTION = 'cache_manager_for_cloudpanel_last_cleared';
    public const WARM_LIMIT_DEFAULT = 30;
    public const WARM_LIMIT_MAXIMUM = 100;

    private ?array $values = null;

    /**
     * "clear_all" is null until the form is first saved, which means every layer:
     * a fresh install clears everything it can.
     */
    public static function defaults(): array
    {
        return [
            'clear_all' => null,
            'warm' => true,
            'warm_limit' => self::WARM_LIMIT_DEFAULT,
            'cloudflare_zone' => '',
        ];
    }

    public function get(string $key): mixed
    {
        if ($this->values === null) {
            $saved = get_option(self::OPTION, []);
            $this->values = (is_array($saved) ? $saved : []) + self::defaults();
        }
        return $this->values[$key] ?? null;
    }

    public function warmLimit(): int
    {
        return self::boundedWarmLimit($this->get('warm_limit'));
    }

    /** The one rule for the warming limit, applied when saving and when reading. */
    public static function boundedWarmLimit(mixed $value): int
    {
        return max(1, min(self::WARM_LIMIT_MAXIMUM, is_numeric($value) ? (int) $value : self::WARM_LIMIT_DEFAULT));
    }

    /** Unix time of the last successful manual clear of a layer, or 0. */
    public function lastCleared(string $layer): int
    {
        $times = get_option(self::LAST_CLEARED_OPTION, []);
        return is_array($times) ? (int) ($times[$layer] ?? 0) : 0;
    }

    public function markCleared(string $layer): void
    {
        $times = get_option(self::LAST_CLEARED_OPTION, []);
        $times = is_array($times) ? $times : [];
        $times[$layer] = time();
        update_option(self::LAST_CLEARED_OPTION, $times, false);
    }
}
