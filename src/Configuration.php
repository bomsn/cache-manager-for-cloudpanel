<?php
namespace CacheManagerForCloudPanel;

/**
 * This site's identity (host and origin) and the server that manages its page cache.
 *
 * A server runs one Varnish, so the first candidate that is present manages the
 * site. The Companion comes first because installing it replaces CloudPanel's
 * Varnish, while CloudPanel's settings can still say Varnish is on.
 */
final class Configuration
{
    /** @var list<PageCacheServer> */
    private array $candidates;
    private ?PageCacheServer $server = null;
    private bool $detected = false;

    /**
     * @param list<PageCacheServer>|null $candidates In order of precedence; null for
     *        the Companion, then CloudPanel's Varnish.
     */
    public function __construct(?array $candidates = null)
    {
        $this->candidates = $candidates ?? [
            new CompanionServer($this->host()),
            new CloudPanelVarnish($this->host()),
        ];
    }

    /**
     * The server that manages this site's page cache, or null when it has none.
     * Detected on first use and kept, so the settings are read only once.
     */
    public function server(): ?PageCacheServer
    {
        if (!$this->detected) {
            $this->detected = true;
            foreach ($this->candidates as $server) {
                if ($server->present()) {
                    $this->server = $server;
                    break;
                }
            }
        }
        return $this->server;
    }

    /** Whether this site has a page cache at all, whatever the state of its configuration. */
    public function switchedOn(): bool
    {
        return $this->server() !== null;
    }

    public function enabled(): bool
    {
        return $this->switchedOn() && $this->error() === '';
    }

    /** Why the page cache cannot be used, or ''. */
    public function error(): string
    {
        if (is_multisite()) {
            return __('Multisite is not supported yet.', 'cache-manager-for-cloudpanel');
        }
        return $this->server()?->error() ?? '';
    }

    /** Whether the page cache server accepts clears right now. */
    public function available(): bool
    {
        return $this->enabled() && $this->server()->available();
    }

    /** Clear the whole site. True once the server has confirmed it. */
    public function clearSite(): bool
    {
        return $this->enabled() && $this->server()->clearSite();
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
}
