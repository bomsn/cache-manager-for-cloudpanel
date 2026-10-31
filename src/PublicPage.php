<?php
namespace CacheManagerForCloudPanel;

final class PublicPage
{
    public function __construct(private Configuration $configuration) {}

    public function register(): void
    {
        add_action('template_redirect', [$this, 'prepare'], PHP_INT_MAX);
    }

    public function prepare(): void
    {
        if ($this->configuration->enabled() && !$this->configuration->available()) {
            // Service failure must also stop an upstream cache from storing
            // newly rendered pages while local invalidation is unavailable.
            if (!headers_sent()) {
                header('Cache-Control: private, no-store', true);
            }
            return;
        }
        if (!$this->configuration->enabled() || headers_sent() ||
            !in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true) ||
            !empty($_COOKIE) || !empty($_GET) || !empty($_SERVER['HTTP_AUTHORIZATION']) ||
            is_user_logged_in() || is_admin() || wp_doing_ajax() || is_preview() ||
            is_feed() || is_404() || is_search() || is_trackback() ||
            (defined('DONOTCACHEPAGE') && DONOTCACHEPAGE) ||
            (defined('REST_REQUEST') && REST_REQUEST) || post_password_required()) {
            return;
        }
        if (function_exists('is_cart') && (is_cart() || is_checkout() || is_account_page())) {
            return;
        }
        if (function_exists('WC') && WC()->session && WC()->session->has_session()) {
            return;
        }
        // Existing privacy directives win; downstream handlers can still revoke caching.
        foreach (headers_list() as $header) {
            if (preg_match('/^(Set-Cookie:|Cache-Control:.*(?:private|no-store|no-cache)|Pragma:.*no-cache)/i', $header)) {
                return;
            }
        }
        header('X-Cache-Manager-For-CloudPanel-Public: 1');
        // s-maxage is what Cloudflare and browsers may keep; the origin lifetime is
        // read only by the server policy, which removes it before the response leaves.
        header('Cache-Control: public, max-age=0, s-maxage=300, must-revalidate');
        header('X-Cache-Manager-For-CloudPanel-Origin-TTL: ' . $this->configuration->originTtl());
    }
}
