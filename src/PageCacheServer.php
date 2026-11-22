<?php
namespace CacheManagerForCloudPanel;

/**
 * One server that holds this site's page cache.
 *
 * A site can use CloudPanel's own Varnish, the CloudPanel Companion's cache
 * service, or both. Configuration holds the servers this site uses and sends
 * every clear to all of them, so adding a server is one new class and one entry
 * in Configuration, not a branch in each caller.
 */
interface PageCacheServer
{
    /** clearPage() removed only the requested page. */
    public const PAGE = 'page';
    /** clearPage() cleared the whole site instead. */
    public const SITE = 'site';

    /** Name shown on the status card and in results, e.g. "CloudPanel Varnish". */
    public function label(): string;

    /** Whether this site uses this server at all. Reads local configuration only. */
    public function present(): bool;

    /** Why a present server cannot be used, or '' when it can. */
    public function error(): string;

    /**
     * Whether the server accepts clears right now. While it does not, pages are
     * served uncached and clears are retried until it recovers.
     */
    public function available(): bool;

    /** Seconds the server keeps a page. */
    public function ttl(): int;

    /** Clear every page of the site. True once the server has confirmed it. */
    public function clearSite(): bool;

    /**
     * Clear one page, or the whole site when the server cannot clear it alone.
     *
     * @param string $url Absolute URL on this site's host.
     * @return string self::PAGE or self::SITE once confirmed; otherwise why it failed.
     */
    public function clearPage(string $url): string;

    /** Check the connection without clearing any page. */
    public function test(): Result;
}
