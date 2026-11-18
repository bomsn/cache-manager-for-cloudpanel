<?php
namespace CacheManagerForCloudPanel;

/**
 * How this site reaches the CloudPanel Companion's local cache service: the
 * wiring in wp-config.php, the service's readiness, and the one request shape it
 * accepts. Every request to the service is built here, so the contract with the
 * Companion has a single owner.
 */
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

    /** The service banned only the requested page. */
    public const URL_POLICY = 'url-ban-v1';
    /** The service started a new revision for the whole site. */
    public const SITE_POLICY = 'site-revision-v1';

    public function originTtl(): int
    {
        $value = defined('CACHE_MANAGER_FOR_CLOUDPANEL_ORIGIN_TTL') ? CACHE_MANAGER_FOR_CLOUDPANEL_ORIGIN_TTL : null;
        return is_int($value) && $value >= self::ORIGIN_TTL_MINIMUM && $value <= self::ORIGIN_TTL_MAXIMUM
            ? $value : self::ORIGIN_TTL_DEFAULT;
    }

    /** Whether wp-config.php asks for the page cache, whatever the rest of its wiring. */
    public function switchedOn(): bool
    {
        return defined('CACHE_MANAGER_FOR_CLOUDPANEL_ENABLED') && CACHE_MANAGER_FOR_CLOUDPANEL_ENABLED === true;
    }

    public function enabled(): bool
    {
        return $this->switchedOn() && $this->error() === '';
    }

    public function error(): string
    {
        if (is_multisite()) {
            return __('Multisite is not supported yet.', 'cache-manager-for-cloudpanel');
        }
        if (isset($GLOBALS['clpVarnish'])) {
            return __('CloudPanel\'s own Varnish cache is also switched on for this site. Turn it off in CloudPanel (the site\'s Varnish Cache tab) so only one system manages the page cache.', 'cache-manager-for-cloudpanel');
        }
        if (!defined('CACHE_MANAGER_FOR_CLOUDPANEL_PURGE_KEY') ||
            !is_string(CACHE_MANAGER_FOR_CLOUDPANEL_PURGE_KEY) ||
            !preg_match('/^[a-f0-9]{64}$/D', CACHE_MANAGER_FOR_CLOUDPANEL_PURGE_KEY)) {
            return __('wp-config.php needs this site\'s own purge key: 64 characters, 0-9 and a-f.', 'cache-manager-for-cloudpanel');
        }
        if (!defined('CACHE_MANAGER_FOR_CLOUDPANEL_PORT') ||
            !is_int(CACHE_MANAGER_FOR_CLOUDPANEL_PORT) ||
            CACHE_MANAGER_FOR_CLOUDPANEL_PORT < 1024 || CACHE_MANAGER_FOR_CLOUDPANEL_PORT > 65535) {
            return __('Set the port of the Companion\'s cache service in wp-config.php. It is not the Varnish port.', 'cache-manager-for-cloudpanel');
        }
        if (!preg_match('/^[a-z0-9][a-z0-9.-]*$/D', $this->host())) {
            return __('The site hostname is invalid.', 'cache-manager-for-cloudpanel');
        }
        return '';
    }

    public function host(): string
    {
        return strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
    }

    /**
     * Scheme, host and port of the site's home URL, e.g. "https://example.com".
     * Page URLs are built on this rather than on the request's Host header.
     */
    public function origin(): string
    {
        $home = wp_parse_url(home_url('/'));
        return $home['scheme'] . '://' . $home['host'] . (isset($home['port']) ? ':' . $home['port'] : '');
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

    /**
     * Authenticated purge: the whole site, or with $page (a request target from
     * PageCache::requestTarget()) only that page. Callers check enabled() first.
     */
    public function purge(?string $page = null): array|\WP_Error
    {
        return $this->request('PURGE', $page === null ? [] : ['X-Cache-Manager-For-CloudPanel-Url' => $page]);
    }

    /**
     * Authenticated check that clears nothing: the service confirms the key and
     * that it can reach Varnish. 200 names the policy a clear would apply; 403
     * is a wrong key or unknown host, 503 an unreachable Varnish, and 501 a
     * Companion that predates the check.
     */
    public function check(): array|\WP_Error
    {
        return $this->request('OPTIONS', []);
    }

    /** What a successful purge cleared, or a check says a clear would (URL_POLICY or SITE_POLICY); '' otherwise. */
    public static function policy(array|\WP_Error $response): string
    {
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return '';
        }
        $policy = (string) wp_remote_retrieve_header($response, 'x-cache-manager-for-cloudpanel-policy');
        return in_array($policy, [self::URL_POLICY, self::SITE_POLICY], true) ? $policy : '';
    }

    private function request(string $method, array $headers): array|\WP_Error
    {
        return wp_remote_request('http://127.0.0.1:' . (int) CACHE_MANAGER_FOR_CLOUDPANEL_PORT . '/', [
            'method' => $method, 'timeout' => 2, 'redirection' => 0,
            'headers' => [
                'Host' => $this->host(),
                'X-Cache-Manager-For-CloudPanel-Key' => CACHE_MANAGER_FOR_CLOUDPANEL_PURGE_KEY,
            ] + $headers,
        ]);
    }
}
