<?php
namespace CacheManagerForCloudPanel;

/**
 * CloudPanel's Varnish Cache, switched on in the site's Varnish Cache tab.
 *
 * CloudPanel's controller (~/.varnish-cache/controller.php, which CloudPanel's
 * PHP settings load before WordPress) tags each cacheable page with the site's
 * prefix and the posts it shows ("96b1,96b1-post-12"), and CloudPanel's VCL bans
 * every page whose tags match the X-Cache-Tags header of a PURGE request.
 *
 * Settings are read from ~/.varnish-cache/settings.json, not from CloudPanel's
 * $clpVarnish global, because the global exists only in web requests and WP-CLI
 * and cron must be able to clear the cache too.
 */
final class CloudPanelVarnish implements PageCacheServer
{
    /** A tag no page carries, so a connection test clears nothing. */
    private const TEST_TAG_SUFFIX = '-cache-manager-for-cloudpanel-test';

    /** Redirects the connection test follows from the home page, within this site. */
    private const TEST_REDIRECTS = 3;

    /** @var array<string, mixed>|null Null until first read. */
    private ?array $settings = null;
    private string $readError = '';

    /**
     * @param string      $host         This site's host name.
     * @param string|null $settingsFile Null for ~/.varnish-cache/settings.json.
     */
    public function __construct(private string $host, private ?string $settingsFile = null) {}

    public function label(): string
    {
        return __('CloudPanel Varnish', 'cache-manager-for-cloudpanel');
    }

    /**
     * Present when the settings switch Varnish on. An unreadable settings file also
     * counts, so its error is shown rather than the cache appearing switched off
     * while Varnish keeps serving pages.
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

    /** Varnish serves pages directly, with no separate service to wait for. */
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
     * Pages are tagged with the posts they show, not by address, so the page is
     * cleared by a post's tag only when Varnish serves it with the tag of the post
     * url_to_postid() names for it: a single post or page, cleared together with
     * the listings that show it. An address that shows a listing instead (a shop,
     * the posts page, a front page of products) carries the tags of what it lists,
     * and it, or a page that cannot be loaded, clears the whole site, so the result
     * reported is what was cleared.
     */
    public function clearPage(string $url): string
    {
        $post = url_to_postid($url);
        $tag = $this->prefix() . '-post-' . $post;
        if ($post > 0 && $this->serves($url, $tag)) {
            $error = $this->purge($tag);
            return $error === '' ? self::PAGE : $error;
        }
        $error = $this->purge($this->prefix());
        return $error === '' ? self::SITE : $error;
    }

    /**
     * A purge that matches no page, then the home page as Varnish serves it: every
     * clear depends on CloudPanel's controller tagging the pages, and a page cached
     * without the site's tag would never be cleared.
     */
    public function test(): Result
    {
        $error = $this->purge($this->prefix() . self::TEST_TAG_SUFFIX);
        if ($error !== '') {
            return Result::error($error);
        }
        $tags = $this->tags(home_url('/'), self::TEST_REDIRECTS);
        if (is_string($tags)) {
            return Result::error(sprintf(
                /* translators: %s: why the page could not be loaded */
                __('CloudPanel\'s Varnish accepted a purge, but the home page could not be loaded through it: %s', 'cache-manager-for-cloudpanel'),
                $tags
            ));
        }
        if (!in_array($this->prefix(), $tags, true)) {
            return Result::error(__('CloudPanel\'s Varnish accepted a purge, but the home page comes back without this site\'s cache tag, so clears would not reach cached pages. CloudPanel adds the tag while Varnish Cache is switched on for the site: switch it off and on again in the site\'s Varnish Cache tab, or ask the server operator to check the site\'s PHP settings.', 'cache-manager-for-cloudpanel'));
        }
        return Result::success(__('CloudPanel\'s Varnish accepted a purge from this site, and its pages carry the site\'s cache tag. Nothing was cleared.', 'cache-manager-for-cloudpanel'));
    }

    public function conflict(): string
    {
        return defined(self::CLP_VARNISH_CACHE)
            ? __('The CLP Varnish Cache plugin is also active. It clears the same cache and adds a second menu, so deactivate it: this plugin already clears the page cache whenever you edit content. How long pages are kept and which pages are never cached can still be changed in the site\'s Varnish Cache tab in CloudPanel.', 'cache-manager-for-cloudpanel')
            : '';
    }

    /**
     * Ban every cached page that carries $tag.
     *
     * CloudPanel's VCL matches the header as a regular expression against each
     * page's tag list, so the tag is anchored to one whole entry of that list.
     * Unanchored, post 2's tag would also clear post 23's pages, and the site's
     * prefix would clear another site's pages whose tags merely contain it.
     *
     * @return string '' once Varnish confirmed the ban; otherwise why it failed.
     */
    private function purge(string $tag): string
    {
        $response = wp_remote_request($this->endpoint(), [
            'method' => 'PURGE', 'timeout' => 2, 'redirection' => 0,
            'headers' => ['Host' => $this->host, 'X-Cache-Tags' => self::pattern($tag)],
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
            405 => __('CloudPanel\'s Varnish refused the purge: this server\'s address is not allowed to purge (HTTP 405).', 'cache-manager-for-cloudpanel'),
            default => sprintf(
                /* translators: %d: HTTP status code */
                __('CloudPanel\'s Varnish did not confirm the purge (HTTP %d).', 'cache-manager-for-cloudpanel'),
                $code
            ),
        };
    }

    /** Whether Varnish serves the page at $url with $tag. */
    private function serves(string $url, string $tag): bool
    {
        $tags = $this->tags($url);
        return is_array($tags) && in_array($tag, $tags, true);
    }

    /**
     * The tags CloudPanel's controller gave the page Varnish serves at $url. An
     * untagged redirect within this site is followed up to $redirects times; one
     * that leaves the site is never requested.
     *
     * @return list<string>|string The tags, empty for an untagged page; otherwise why it could not be loaded.
     */
    private function tags(string $url, int $redirects = 0): array|string
    {
        $target = CompanionServer::requestTarget($url, $this->host);
        if (is_wp_error($target)) {
            return $target->get_error_message();
        }
        $response = wp_remote_request($this->endpoint() . ltrim($target, '/'), [
            'method' => 'GET', 'timeout' => 10, 'redirection' => 0, 'headers' => ['Host' => $this->host],
        ]);
        if (is_wp_error($response)) {
            return $response->get_error_message();
        }
        $tags = array_values(array_filter(explode(',', (string) wp_remote_retrieve_header($response, 'x-cache-tags'))));
        $code = (int) wp_remote_retrieve_response_code($response);
        $location = (string) wp_remote_retrieve_header($response, 'location');
        if ($tags !== [] || $redirects === 0 || $code < 300 || $code > 399 || $location === '') {
            return $tags;
        }
        // Root-relative, as a redirect from Nginx or WordPress may be.
        $next = str_starts_with($location, '/') && !str_starts_with($location, '//') ? 'http://' . $this->host . $location : $location;
        if (strtolower((string) wp_parse_url($next, PHP_URL_HOST)) !== $this->host) {
            return sprintf(
                /* translators: %s: the address redirected to */
                __('it redirects to %s, which is not this site', 'cache-manager-for-cloudpanel'),
                $location
            );
        }
        return $this->tags($next, $redirects - 1);
    }

    /**
     * The X-Cache-Tags value that matches $tag only as a whole entry of a page's
     * comma-separated tag list. Tags are built from prefix() and post IDs, whose
     * characters are all literal in a regular expression.
     */
    public static function pattern(string $tag): string
    {
        return '(^|,)' . $tag . '(,|$)';
    }

    /** "http://127.0.0.1:6081/" for the server "127.0.0.1:6081"; '' unless it is a host and port. */
    private function endpoint(): string
    {
        $server = (string) ($this->settings()['server'] ?? '');
        return preg_match('/^(?:[A-Za-z0-9.-]+|\[[0-9A-Fa-f:]+\]):[0-9]{1,5}$/D', $server) ? 'http://' . $server . '/' : '';
    }

    /** The site's tag prefix. Tags are sent in a regular expression, so only literal characters are accepted. */
    private function prefix(): string
    {
        $prefix = (string) ($this->settings()['cacheTagPrefix'] ?? '');
        return preg_match('/^[A-Za-z0-9_-]+$/D', $prefix) ? $prefix : '';
    }

    /** @return array<string, mixed> Empty when the site has no settings file. */
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

    /** CloudPanel runs PHP as the site user, whose home holds the settings. */
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
