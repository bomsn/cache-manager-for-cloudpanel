<?php
namespace CacheManagerForCloudPanel;

/**
 * CloudPanel's own Varnish cache, switched on in the site's Varnish Cache tab.
 *
 * CloudPanel writes the site's settings to ~/.varnish-cache/settings.json and
 * loads a controller that tags every cached page with the site's tag prefix
 * ("96b1") and its posts ("96b1-post-12"). This class reads that same file, not
 * the controller's $clpVarnish global, because the controller is only loaded for
 * web requests: WP-CLI and system cron would otherwise never clear the cache.
 *
 * Clears use the request CloudPanel's controller sends: PURGE to the configured
 * server with an X-Cache-Tags header, which CloudPanel's VCL turns into a ban on
 * every object whose tags match.
 */
final class CloudPanelVarnish implements PageCacheServer
{
    /** Matches no page, so a connection test clears nothing. */
    private const TEST_TAG_SUFFIX = '-cache-manager-for-cloudpanel-test';

    /** @var array<string, mixed>|null Parsed settings; null until first read. */
    private ?array $settings = null;
    private string $readError = '';

    /**
     * @param string      $host         This site's host name.
     * @param string|null $settingsFile Path to CloudPanel's settings.json; null for the site user's ~/.varnish-cache/settings.json.
     */
    public function __construct(private string $host, private ?string $settingsFile = null) {}

    public function label(): string
    {
        return __('CloudPanel Varnish', 'cache-manager-for-cloudpanel');
    }

    /**
     * Present when CloudPanel has written settings for this site and Varnish is
     * switched on in them. A settings file that exists but cannot be read still
     * counts as present, so the problem is reported instead of the page cache
     * silently looking switched off while Varnish keeps serving old pages.
     */
    public function present(): bool
    {
        $settings = $this->settings();
        return $this->readError !== '' || ($settings['enabled'] ?? false) === true;
    }

    public function error(): string
    {
        if ($this->readError !== '') {
            return $this->readError;
        }
        if ($this->endpoint() === '') {
            return __('CloudPanel\'s Varnish settings for this site name no valid server (host:port).', 'cache-manager-for-cloudpanel');
        }
        if ($this->prefix() === '') {
            return __('CloudPanel\'s Varnish settings for this site have no valid cache tag prefix.', 'cache-manager-for-cloudpanel');
        }
        return '';
    }

    /** CloudPanel serves pages from Varnish directly; there is no separate service to wait for. */
    public function available(): bool
    {
        return true;
    }

    public function ttl(): int
    {
        return max(0, (int) ($this->settings()['cacheLifetime'] ?? 0));
    }

    public function clearSite(): bool
    {
        return $this->purge($this->prefix()) === '';
    }

    /**
     * Pages are tagged by post, not by address, so a page that shows a post is
     * cleared through that post's tag. CloudPanel's ban matches tags as a regular
     * expression, so the tag for post 2 also clears post 23's pages: a few extra
     * pages refill, none is left stale. Any other address clears the whole site.
     */
    public function clearPage(string $url): string
    {
        $post = url_to_postid($url);
        if ($post > 0) {
            $error = $this->purge($this->prefix() . '-post-' . $post);
            return $error === '' ? self::PAGE : $error;
        }
        $error = $this->purge($this->prefix());
        return $error === '' ? self::SITE : $error;
    }

    public function test(): Result
    {
        $error = $this->purge($this->prefix() . self::TEST_TAG_SUFFIX);
        return $error === ''
            ? Result::success(__('CloudPanel\'s Varnish accepted a purge from this site. Nothing was cleared.', 'cache-manager-for-cloudpanel'))
            : Result::error($error);
    }

    /**
     * Ban every cached object whose tags match $tag.
     *
     * @return string '' once Varnish confirmed the ban; otherwise why it failed.
     */
    private function purge(string $tag): string
    {
        $response = wp_remote_request($this->endpoint(), [
            'method' => 'PURGE', 'timeout' => 2, 'redirection' => 0,
            'headers' => ['Host' => $this->host, 'X-Cache-Tags' => $tag],
        ]);
        if (is_wp_error($response)) {
            return sprintf(
                /* translators: %s: connection error */
                __('Could not reach CloudPanel\'s Varnish: %s', 'cache-manager-for-cloudpanel'),
                $response->get_error_message()
            );
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        return match ($code) {
            200 => '',
            // CloudPanel's VCL answers 405 to a PURGE from an address outside its "purger" list.
            405 => __('CloudPanel\'s Varnish refused the purge: this server\'s address is not allowed to purge (HTTP 405).', 'cache-manager-for-cloudpanel'),
            default => sprintf(
                /* translators: %d: HTTP status code */
                __('CloudPanel\'s Varnish did not confirm the purge (HTTP %d).', 'cache-manager-for-cloudpanel'),
                $code
            ),
        };
    }

    /** "http://127.0.0.1:6081/" from the configured "127.0.0.1:6081", or '' when it is not a host and port. */
    private function endpoint(): string
    {
        $server = (string) ($this->settings()['server'] ?? '');
        return preg_match('/^(?:[A-Za-z0-9.-]+|\[[0-9A-Fa-f:]+\]):[0-9]{1,5}$/D', $server) ? 'http://' . $server . '/' : '';
    }

    /**
     * The site's cache tag prefix. It is sent as a regular expression, so only
     * the plain characters CloudPanel generates are accepted.
     */
    private function prefix(): string
    {
        $prefix = (string) ($this->settings()['cacheTagPrefix'] ?? '');
        return preg_match('/^[A-Za-z0-9_-]+$/D', $prefix) ? $prefix : '';
    }

    /** @return array<string, mixed> CloudPanel's settings for this site; empty when it has none. */
    private function settings(): array
    {
        if ($this->settings !== null) {
            return $this->settings;
        }
        $this->settings = [];
        $file = $this->settingsFile ?? self::defaultSettingsFile();
        if ($file === '' || !file_exists($file)) {
            return $this->settings;
        }
        $json = is_readable($file) ? file_get_contents($file) : false;
        $settings = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($settings)) {
            $this->readError = sprintf(
                /* translators: %s: file path */
                __('CloudPanel\'s Varnish settings could not be read from %s.', 'cache-manager-for-cloudpanel'),
                $file
            );
            return $this->settings;
        }
        $this->settings = $settings;
        return $this->settings;
    }

    /** ~/.varnish-cache/settings.json of the user PHP runs as, which on CloudPanel is the site user. */
    private static function defaultSettingsFile(): string
    {
        $home = '';
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $user = posix_getpwuid(posix_geteuid());
            $home = is_array($user) ? (string) ($user['dir'] ?? '') : '';
        }
        if ($home === '') {
            $home = (string) getenv('HOME');
        }
        return $home === '' ? '' : rtrim($home, '/') . '/.varnish-cache/settings.json';
    }
}
