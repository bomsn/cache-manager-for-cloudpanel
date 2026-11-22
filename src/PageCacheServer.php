<?php
namespace CacheManagerForCloudPanel;

/**
 * A Varnish setup that can manage this site's page cache.
 *
 * Callers depend on this interface only, so supporting another setup is one new
 * class and one entry in Configuration's candidates.
 */
interface PageCacheServer
{
    /** clearPage() removed only the requested page. */
    public const PAGE = 'page';
    /** clearPage() cleared the whole site instead. */
    public const SITE = 'site';

    /** Defined by CloudPanel's own WordPress plugin, CLP Varnish Cache, while it is active. */
    public const CLP_VARNISH_CACHE = 'CLP_VARNISH_VERSION';

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

    /**
     * What the site owner should switch off because another plugin manages the
     * same cache, or ''. Reads local state only.
     */
    public function conflict(): string;
}
