<?php
namespace CacheManagerForCloudPanel;

final class Configuration
{
    /**
     * Seconds Varnish keeps an approved public page (CACHE_MANAGER_FOR_CLOUDPANEL_ORIGIN_TTL).
     * Every edit invalidates the whole site, so the server copy can live much longer
     * than the 300-second lifetime Cloudflare and browsers are given, which stays fixed.
     */
    public const ORIGIN_TTL_DEFAULT = 3600;
    public const ORIGIN_TTL_MINIMUM = 300;
    public const ORIGIN_TTL_MAXIMUM = 86400;

    public function originTtl(): int
    {
        $value = defined('CACHE_MANAGER_FOR_CLOUDPANEL_ORIGIN_TTL') ? CACHE_MANAGER_FOR_CLOUDPANEL_ORIGIN_TTL : null;
        return is_int($value) && $value >= self::ORIGIN_TTL_MINIMUM && $value <= self::ORIGIN_TTL_MAXIMUM
            ? $value : self::ORIGIN_TTL_DEFAULT;
    }

    public function enabled(): bool
    {
        return defined('CACHE_MANAGER_FOR_CLOUDPANEL_ENABLED') &&
            CACHE_MANAGER_FOR_CLOUDPANEL_ENABLED === true && $this->error() === '';
    }

    public function error(): string
    {
        if (is_multisite()) {
            return 'Multisite has not been qualified for this release.';
        }
        if (isset($GLOBALS['clpVarnish'])) {
            return 'CloudPanel’s PHP controller is loaded. Resolve header and purge ownership before enabling this integration.';
        }
        if (!defined('CACHE_MANAGER_FOR_CLOUDPANEL_PURGE_KEY') ||
            !is_string(CACHE_MANAGER_FOR_CLOUDPANEL_PURGE_KEY) ||
            !preg_match('/^[a-f0-9]{64}$/D', CACHE_MANAGER_FOR_CLOUDPANEL_PURGE_KEY)) {
            return 'A private, per-site 64-character hexadecimal purge key is required in wp-config.php.';
        }
        if (!defined('CACHE_MANAGER_FOR_CLOUDPANEL_PORT') ||
            !is_int(CACHE_MANAGER_FOR_CLOUDPANEL_PORT) ||
            CACHE_MANAGER_FOR_CLOUDPANEL_PORT < 1024 || CACHE_MANAGER_FOR_CLOUDPANEL_PORT > 65535) {
            return 'Configure the local companion cache-control port in wp-config.php (not the page-cache port).';
        }
        if (!preg_match('/^[a-z0-9][a-z0-9.-]*$/D', $this->host())) {
            return 'The site hostname is invalid.';
        }
        return '';
    }

    public function host(): string
    {
        return strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
    }

    public function endpoint(): string
    {
        return 'http://127.0.0.1:' . (int) CACHE_MANAGER_FOR_CLOUDPANEL_PORT . '/';
    }

    public function available(): bool
    {
        if (!$this->enabled() || !defined('CACHE_MANAGER_FOR_CLOUDPANEL_READY') ||
            !is_string(CACHE_MANAGER_FOR_CLOUDPANEL_READY)) {
            return false;
        }
        $expected = CACHE_MANAGER_FOR_CLOUDPANEL_READY;
        if (!preg_match('#^/run/cache-manager-[a-z0-9-]+/ready/' . hash('sha256', $this->host()) . '[.]ready$#D', $expected)) {
            return false;
        }
        clearstatcache(true, $expected);
        return is_file($expected) && !is_link($expected);
    }
}
