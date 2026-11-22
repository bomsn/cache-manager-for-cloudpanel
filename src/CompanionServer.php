<?php
namespace CacheManagerForCloudPanel;

/**
 * The CloudPanel Companion's local cache service: its settings in wp-config.php,
 * its readiness, and the requests it accepts.
 */
final class CompanionServer implements PageCacheServer
{
    /**
     * Seconds Varnish keeps a public page (CACHE_MANAGER_FOR_CLOUDPANEL_ORIGIN_TTL).
     * Edits clear the whole site, so this can far exceed the fixed 300 seconds
     * Cloudflare and browsers may keep a page.
     */
    public const ORIGIN_TTL_DEFAULT = 3600;
    public const ORIGIN_TTL_MINIMUM = 300;
    public const ORIGIN_TTL_MAXIMUM = 86400;

    /** The service banned only the requested page. */
    public const URL_POLICY = 'url-ban-v1';
    /** The service started a new revision for the whole site. */
    public const SITE_POLICY = 'site-revision-v1';

    private const MAX_TARGET_LENGTH = 2048;

    /** Same campaign parameters the server policy strips before looking up a page. */
    private const TRACKING_PARAMETER = '/^(utm_[a-z]+|gclid|gbraid|wbraid|fbclid|msclkid|mc_cid|mc_eid)$/';

    public function __construct(private string $host) {}

    public function label(): string
    {
        return __('CloudPanel Companion', 'cache-manager-for-cloudpanel');
    }

    /** Whether wp-config.php switches the Companion on, even if its other settings are invalid. */
    public function present(): bool
    {
        return defined('CACHE_MANAGER_FOR_CLOUDPANEL_ENABLED') && CACHE_MANAGER_FOR_CLOUDPANEL_ENABLED === true;
    }

    public function error(): string
    {
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
        if (!preg_match('/^[a-z0-9][a-z0-9.-]*$/D', $this->host)) {
            return __('The site hostname is invalid.', 'cache-manager-for-cloudpanel');
        }
        return '';
    }

    public function available(): bool
    {
        if (!defined('CACHE_MANAGER_FOR_CLOUDPANEL_READY') || !is_string(CACHE_MANAGER_FOR_CLOUDPANEL_READY)) {
            return false;
        }
        $expected = CACHE_MANAGER_FOR_CLOUDPANEL_READY;
        if (!preg_match('#^/run/cache-manager-[a-z0-9-]+/ready/' . hash('sha256', $this->host) . '[.]ready$#D', $expected)) {
            return false;
        }
        clearstatcache(true, $expected);
        return is_file($expected) && !is_link($expected);
    }

    public function ttl(): int
    {
        $value = defined('CACHE_MANAGER_FOR_CLOUDPANEL_ORIGIN_TTL') ? CACHE_MANAGER_FOR_CLOUDPANEL_ORIGIN_TTL : null;
        return is_int($value) && $value >= self::ORIGIN_TTL_MINIMUM && $value <= self::ORIGIN_TTL_MAXIMUM
            ? $value : self::ORIGIN_TTL_DEFAULT;
    }

    public function clearSite(): bool
    {
        return self::policy($this->request('PURGE', [])) === self::SITE_POLICY;
    }

    public function clearPage(string $url): string
    {
        $target = self::requestTarget($url, $this->host);
        if (is_wp_error($target)) {
            return $target->get_error_message();
        }
        $response = $this->request('PURGE', ['X-Cache-Manager-For-CloudPanel-Url' => $target]);
        return match (self::policy($response)) {
            self::URL_POLICY => self::PAGE,
            // The service clears the whole site when it cannot ban a single page.
            self::SITE_POLICY => self::SITE,
            default => self::describe($response),
        };
    }

    /**
     * The service's OPTIONS check, which clears nothing: 200 names the policy a
     * clear would apply, 403 is a wrong key or unknown host, 503 an unreachable
     * Varnish, and 501 a service without the check.
     */
    public function test(): Result
    {
        $response = $this->request('OPTIONS', []);
        $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        if ($code === 200 && self::policy($response) !== '') {
            return Result::success(__('The cache service accepted the purge key and can reach Varnish. Nothing was cleared.', 'cache-manager-for-cloudpanel'));
        }
        return Result::error(match ($code) {
            403 => __('The cache service rejected the purge key in wp-config.php, or does not know this site\'s host.', 'cache-manager-for-cloudpanel'),
            400 => __('The cache service refused the check as malformed (HTTP 400).', 'cache-manager-for-cloudpanel'),
            503 => __('The purge key is valid, but the cache service cannot reach Varnish, so clears would fail.', 'cache-manager-for-cloudpanel'),
            501 => __('The CloudPanel Companion on this server is too old to check the connection. Nothing was cleared; ask the server operator to update the Companion.', 'cache-manager-for-cloudpanel'),
            default => sprintf(
                /* translators: %s: error or HTTP status */
                __('The cache service did not answer as expected (%s).', 'cache-manager-for-cloudpanel'),
                self::describe($response)
            ),
        });
    }

    /**
     * The path and query to ban, as the cache stores it: without the campaign
     * parameters the policy strips before lookup, and percent-encoded because it
     * travels in a request header that must stay printable ASCII.
     */
    public static function requestTarget(string $url, string $host): string|\WP_Error
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || strtolower($parts['host'] ?? '') !== $host) {
            return new \WP_Error('cache_manager_for_cloudpanel_url', __('That address is not on this site.', 'cache-manager-for-cloudpanel'));
        }
        $target = (string) ($parts['path'] ?? '/');
        $target = $target === '' ? '/' : $target;
        if (isset($parts['query']) && $parts['query'] !== '') {
            $kept = array_filter(explode('&', $parts['query']), static function (string $pair): bool {
                return $pair !== '' && !preg_match(self::TRACKING_PARAMETER, explode('=', $pair, 2)[0]);
            });
            if ($kept) {
                $target .= '?' . implode('&', $kept);
            }
        }
        $target = preg_replace_callback('/[\x80-\xFF ]/', static fn (array $byte): string => rawurlencode($byte[0]), $target);
        if (strlen($target) > self::MAX_TARGET_LENGTH || !preg_match('#^/[\x21-\x7E]*$#D', $target)) {
            return new \WP_Error('cache_manager_for_cloudpanel_url', __('That address cannot be cleared on its own.', 'cache-manager-for-cloudpanel'));
        }
        return $target;
    }

    /** URL_POLICY or SITE_POLICY from a confirmed purge or check; '' otherwise. */
    private static function policy(array|\WP_Error $response): string
    {
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return '';
        }
        $policy = (string) wp_remote_retrieve_header($response, 'x-cache-manager-for-cloudpanel-policy');
        return in_array($policy, [self::URL_POLICY, self::SITE_POLICY], true) ? $policy : '';
    }

    private static function describe(array|\WP_Error $response): string
    {
        return is_wp_error($response)
            ? $response->get_error_message()
            /* translators: %d: HTTP status code */
            : sprintf(__('HTTP %d', 'cache-manager-for-cloudpanel'), (int) wp_remote_retrieve_response_code($response));
    }

    private function request(string $method, array $headers): array|\WP_Error
    {
        return wp_remote_request('http://127.0.0.1:' . (int) CACHE_MANAGER_FOR_CLOUDPANEL_PORT . '/', [
            'method' => $method, 'timeout' => 2, 'redirection' => 0,
            'headers' => [
                'Host' => $this->host,
                'X-Cache-Manager-For-CloudPanel-Key' => CACHE_MANAGER_FOR_CLOUDPANEL_PURGE_KEY,
            ] + $headers,
        ]);
    }
}
