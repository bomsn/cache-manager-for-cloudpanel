<?php
namespace CacheManagerForCloudPanel;

/**
 * This site's identity (host and origin) and the page cache servers it uses.
 *
 * A site can use CloudPanel's own Varnish, the CloudPanel Companion, or both;
 * neither depends on the other. Every clear goes to every server the site uses
 * and counts as confirmed only when all of them confirm, so a page cannot stay
 * stale in one cache because another one was cleared.
 */
final class Configuration
{
    /** @var list<PageCacheServer> Every server this plugin knows, used or not. */
    private array $known;

    /**
     * @param list<PageCacheServer>|null $servers The servers to consider; null for
     *        CloudPanel's Varnish and the Companion.
     */
    public function __construct(?array $servers = null)
    {
        $this->known = $servers ?? [
            new CloudPanelVarnish($this->host()),
            new CompanionServer($this->host()),
        ];
    }

    /** @return list<PageCacheServer> The servers this site uses. */
    public function servers(): array
    {
        return array_values(array_filter($this->known, static fn (PageCacheServer $server): bool => $server->present()));
    }

    /** The Companion server when this site uses it, for the headers its server policy reads. */
    public function companion(): ?CompanionServer
    {
        foreach ($this->servers() as $server) {
            if ($server instanceof CompanionServer) {
                return $server;
            }
        }
        return null;
    }

    /** Whether this site uses a page cache server at all, whatever the state of its configuration. */
    public function switchedOn(): bool
    {
        return $this->servers() !== [];
    }

    public function enabled(): bool
    {
        return $this->switchedOn() && $this->error() === '';
    }

    /** Why the page cache cannot be used, or ''. Names the server when the site uses more than one. */
    public function error(): string
    {
        if (is_multisite()) {
            return __('Multisite is not supported yet.', 'cache-manager-for-cloudpanel');
        }
        $servers = $this->servers();
        foreach ($servers as $server) {
            $error = $server->error();
            if ($error !== '') {
                return count($servers) > 1 ? $server->label() . ': ' . $error : $error;
            }
        }
        return '';
    }

    /** Whether every server the site uses accepts clears right now. */
    public function available(): bool
    {
        return $this->enabled() && $this->unavailable() === [];
    }

    /** @return list<PageCacheServer> Servers the site uses that do not accept clears right now. */
    public function unavailable(): array
    {
        return array_values(array_filter($this->servers(), static fn (PageCacheServer $server): bool => !$server->available()));
    }

    /**
     * Clear the whole site on every server. Each server is tried even when an
     * earlier one fails; true only when all of them confirmed.
     */
    public function clearSite(): bool
    {
        $confirmed = true;
        foreach ($this->servers() as $server) {
            $confirmed = $server->clearSite() && $confirmed;
        }
        return $confirmed;
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
