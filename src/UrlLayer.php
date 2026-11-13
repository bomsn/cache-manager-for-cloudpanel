<?php
namespace CacheManagerForCloudPanel;

/** A cache that can drop a single page instead of everything. */
interface UrlLayer extends Layer
{
    /** @param string $url Absolute URL on this site's host. */
    public function clearUrl(string $url): Result;
}
