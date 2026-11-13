<?php
namespace CacheManagerForCloudPanel;

/**
 * One-shot result notices for the administrator who ran an action.
 *
 * Actions run from the front-end admin bar as well as from wp-admin, so the notice
 * is rendered in whichever context the redirect lands in: an admin notice in
 * wp-admin, a small fixed panel under the admin bar on the front end.
 */
final class Notices
{
    /** The user ID is appended, so concurrent administrators never see each other's results. */
    private const PREFIX = 'cache_manager_for_cloudpanel_notice_';
    private const LIFETIME = 60;

    public function register(): void
    {
        add_action('admin_notices', [$this, 'renderAdmin']);
        add_action('wp_footer', [$this, 'renderFrontEnd']);
    }

    public static function add(Report $report): void
    {
        self::store($report->toArray());
    }

    public static function addMessage(string $tone, string $message): void
    {
        self::store(['title' => $message, 'tone' => $tone, 'summary' => '', 'entries' => []]);
    }

    public function renderAdmin(): void
    {
        $notice = self::consume();
        if ($notice !== null) {
            printf('<div class="notice notice-%1$s is-dismissible cmcp-notice">%2$s</div>', esc_attr($notice['tone']), self::markup($notice));
        }
    }

    public function renderFrontEnd(): void
    {
        if (!is_admin_bar_showing() || !current_user_can('manage_options')) {
            return;
        }
        $notice = self::consume();
        if ($notice === null) {
            return;
        }
        printf(
            '<div class="cmcp-toast cmcp-toast--%1$s" role="status">%2$s<button type="button" class="cmcp-toast__close" onclick="this.parentNode.remove()" aria-label="%3$s">&times;</button></div>',
            esc_attr($notice['tone']),
            self::markup($notice),
            esc_attr__('Dismiss', 'cache-manager-for-cloudpanel')
        );
    }

    private static function store(array $notice): void
    {
        $user = get_current_user_id();
        if ($user) {
            set_transient(self::PREFIX . $user, $notice, self::LIFETIME);
        }
    }

    private static function consume(): ?array
    {
        $user = get_current_user_id();
        $notice = $user ? get_transient(self::PREFIX . $user) : false;
        if (!is_array($notice) || empty($notice['title'])) {
            return null;
        }
        delete_transient(self::PREFIX . $user);
        return $notice;
    }

    /**
     * One summary line (the action, with counts when several caches took part),
     * then a row per cache. Each line carries its state as an icon and in words.
     */
    public static function markup(array $notice): string
    {
        $counts = count($notice['entries']) > 1 && ($notice['summary'] ?? '') !== ''
            ? '<span class="cmcp-notice__counts">: ' . esc_html($notice['summary']) . '</span>'
            : '';
        $html = sprintf(
            '<p class="cmcp-notice__summary">%1$s<span><strong>%2$s</strong>%3$s</span></p>',
            Icons::state($notice['tone']),
            esc_html($notice['title']),
            $counts
        );
        if (empty($notice['entries'])) {
            return $html;
        }
        $prefixes = [
            Result::SKIPPED => __('Skipped:', 'cache-manager-for-cloudpanel'),
            Result::ERROR => __('Failed:', 'cache-manager-for-cloudpanel'),
        ];
        $html .= '<ul class="cmcp-results">';
        foreach ($notice['entries'] as $entry) {
            $html .= sprintf(
                '<li class="cmcp-result cmcp-result--%1$s">%2$s<span><span class="cmcp-result__label">%3$s</span> %4$s</span></li>',
                esc_attr($entry['status']),
                Icons::state($entry['status']),
                esc_html($entry['label']),
                esc_html(trim(($prefixes[$entry['status']] ?? '') . ' ' . $entry['message']))
            );
        }
        return $html . '</ul>';
    }
}
