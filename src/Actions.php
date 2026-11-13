<?php
namespace CacheManagerForCloudPanel;

/**
 * Signed action links used by the admin bar, the settings page and the change
 * notice: building them, verifying them, running the action and returning to a
 * clean URL.
 */
final class Actions
{
    public const ACTION_ARG = 'cmcp-action';
    public const NONCE_ARG = 'cmcp-nonce';

    /** Core admin screens that perform an operation when loaded with some of ONE_SHOT_ARGS. */
    private const ONE_SHOT_SCREENS = ['update-core.php', 'plugins.php', 'themes.php', 'plugin-install.php', 'theme-install.php'];

    /** Arguments that make those screens update, install, activate or delete something again. */
    private const ONE_SHOT_ARGS = ['action', 'action2', '_wpnonce', '_wp_http_referer', 'plugin', 'plugins', 'theme', 'themes',
        'checked', 'verify-delete', 'locale', 'upgrade'];

    public function __construct(private CacheLayers $layers, private Configuration $configuration) {}

    public function register(): void
    {
        // The admin bar is shown on the front end as well as in wp-admin, so both
        // entry points must handle its links; an admin-only hook leaves front-end
        // links inert. Priority 1 runs before canonical redirects.
        add_action('admin_init', [$this, 'handle']);
        add_action('template_redirect', [$this, 'handle'], 1);
    }

    /**
     * A signed link that runs $action and comes back to $base.
     *
     * @param string|null $base Page to return to; null for the current page.
     */
    public static function url(string $action, ?string $base = null): string
    {
        $url = $base === null ? add_query_arg(self::ACTION_ARG, $action) : add_query_arg(self::ACTION_ARG, $action, $base);
        return wp_nonce_url($url, 'cache_manager_for_cloudpanel_' . $action, self::NONCE_ARG);
    }

    public function handle(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified below, once an action is requested.
        if (!isset($_GET[self::ACTION_ARG]) || wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $action = sanitize_key(wp_unslash($_GET[self::ACTION_ARG]));
        $run = $this->operation($action);
        // Visitors without the capability, or a link for an action this page does
        // not offer, get the page they asked for: a crawler following a stale link
        // must not be redirected or told anything.
        if ($run === null || !current_user_can('manage_options')) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $nonce = isset($_GET[self::NONCE_ARG]) ? sanitize_text_field(wp_unslash($_GET[self::NONCE_ARG])) : '';
        if (wp_verify_nonce($nonce, 'cache_manager_for_cloudpanel_' . $action)) {
            $report = $run();
            if ($report !== null) {
                Notices::add($report);
            }
        } else {
            Notices::addMessage('error', __('That link has expired, so nothing was cleared. Please try again.', 'cache-manager-for-cloudpanel'));
        }
        wp_safe_redirect(self::returnUrl(add_query_arg([])));
        exit;
    }

    /**
     * Where an action returns to from $url, the page it was run on: never a URL
     * that performs an operation when loaded. This plugin's arguments are dropped,
     * so a refresh cannot run the action again. In wp-admin, the core screens that
     * act on load lose the arguments that make them act (a link from the update
     * results would otherwise update, install or delete again), and update.php,
     * which only performs operations, gives way to the screen it serves.
     */
    public static function returnUrl(string $url): string
    {
        $url = remove_query_arg([self::ACTION_ARG, self::NONCE_ARG], $url);
        if (!is_admin()) {
            return $url;
        }
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $screen = basename($path);
        if ($screen === 'update.php') {
            parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query);
            $action = is_string($query['action'] ?? null) ? $query['action'] : '';
            $target = $action === '' ? 'update-core.php' : (str_contains($action, 'theme') ? 'themes.php' : 'plugins.php');
            return substr($path, 0, -strlen($screen)) . $target;
        }
        return in_array($screen, self::ONE_SHOT_SCREENS, true) ? remove_query_arg(self::ONE_SHOT_ARGS, $url) : $url;
    }

    /** @return (callable(): ?Report)|null The operation; it returns null when there is nothing to report. */
    private function operation(string $action): ?callable
    {
        if ($action === 'clear-all') {
            return fn (): Report => $this->layers->clearAll();
        }
        if ($action === 'test') {
            return fn (): Report => $this->layers->test();
        }
        if ($action === 'clear-page') {
            return is_admin() ? null : fn (): Report => $this->layers->clearUrl($this->currentPage());
        }
        if ($action === 'dismiss-changes') {
            return is_admin() ? static function (): ?Report {
                ChangeNotice::forget();
                return null;
            } : null;
        }
        return $this->layers->offers($action) ? fn (): Report => $this->layers->perform($action) : null;
    }

    /**
     * The page being viewed, built from the site's own scheme and host (never the
     * request's Host header). The query is dropped: the page cache stores no query
     * URLs, and clearing a bare path also clears its query variants.
     */
    private function currentPage(): string
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated by PageCache::requestTarget().
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '/';
        return $this->configuration->origin() . explode('?', $uri, 2)[0];
    }
}
