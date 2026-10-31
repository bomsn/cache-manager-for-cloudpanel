<?php
namespace CacheManagerForCloudPanel;

final class InvalidationQueue
{
    private bool $dirty = false;
    private array $seen = [];

    public function __construct(private Configuration $configuration) {}

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . 'cache_manager_for_cloudpanel_queue';
        dbDelta("CREATE TABLE $table (
            tag varchar(96) NOT NULL,
            revision bigint unsigned NOT NULL DEFAULT 1,
            attempts int unsigned NOT NULL DEFAULT 0,
            next_attempt bigint unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (tag)
        ) " . $wpdb->get_charset_collate() . ';');
    }

    public function enqueue(string $tag): void
    {
        if (!$this->configuration->enabled() || !preg_match('/^[a-z0-9][a-z0-9:_-]{0,95}$/D', $tag)) {
            return;
        }
        // One site revision is the initial freshness contract. Keep the queue
        // revision to avoid acknowledging writes that arrived during a purge.
        $tag = 'site';
        if (isset($this->seen[$tag])) {
            return;
        }
        $this->dirty = true;
        if (!$this->recordIntent()) {
            return;
        }
        $this->seen[$tag] = true;
        if (!wp_next_scheduled('cache_manager_for_cloudpanel_retry')) {
            wp_schedule_single_event(time() + 60, 'cache_manager_for_cloudpanel_retry');
        }
    }

    public function drain(): void
    {
        global $wpdb;
        if (!$this->configuration->enabled() || (!$this->dirty && !doing_action('cache_manager_for_cloudpanel_retry'))) {
            return;
        }
        // Another request may acknowledge the early durable signal while this
        // request is still writing. Reassert it after the final business writes,
        // otherwise an intermediate page could remain cached after completion.
        // This adds one write per changed request, not one per metadata field.
        if ($this->dirty && !$this->recordIntent()) {
            return;
        }
        $table = $wpdb->prefix . 'cache_manager_for_cloudpanel_queue';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE next_attempt <= %d ORDER BY tag LIMIT 32", time()));
        $deadline = microtime(true) + 3;
        foreach ($rows as $row) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $response = wp_remote_request($this->configuration->endpoint(), [
                'method' => 'PURGE', 'timeout' => 2, 'redirection' => 0,
                'headers' => [
                    'Host' => $this->configuration->host(),
                    'X-Cache-Manager-For-CloudPanel-Key' => CACHE_MANAGER_FOR_CLOUDPANEL_PURGE_KEY,
                ],
            ]);
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200 &&
                wp_remote_retrieve_header($response, 'x-cache-manager-for-cloudpanel-policy') === 'site-revision-v1') {
                // A newer write may have arrived while the request was in flight.
                // Delete only the exact revision that this successful purge covered.
                $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE tag=%s AND revision=%d", $row->tag, $row->revision));
            } else {
                $delay = min(900, 30 * (2 ** min(5, (int) $row->attempts)));
                $wpdb->query($wpdb->prepare("UPDATE $table SET attempts=attempts+1,next_attempt=%d WHERE tag=%s AND revision=%d", time() + $delay, $row->tag, $row->revision));
                update_option('cache_manager_for_cloudpanel_error', 'A local page-cache purge failed. It remains queued for retry.', false);
                break;
            }
        }
        if ((int) $wpdb->get_var("SELECT COUNT(*) FROM $table") > 0) {
            if (!wp_next_scheduled('cache_manager_for_cloudpanel_retry')) {
                wp_schedule_single_event(time() + 60, 'cache_manager_for_cloudpanel_retry');
            }
        } else {
            delete_option('cache_manager_for_cloudpanel_error');
        }
        $this->dirty = false;
        $this->seen = [];
    }

    private function recordIntent(): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cache_manager_for_cloudpanel_queue';
        $result = $wpdb->query("INSERT INTO $table (tag) VALUES ('site') ON DUPLICATE KEY UPDATE revision=revision+1, next_attempt=0");
        if ($result === false) {
            update_option('cache_manager_for_cloudpanel_error', 'Could not record cache invalidation. Check the database and purge the site after recovery.', false);
            return false;
        }
        return true;
    }

    public function pending(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cache_manager_for_cloudpanel_queue");
    }
}
