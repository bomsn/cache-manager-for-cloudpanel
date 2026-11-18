<?php
namespace CacheManagerForCloudPanel;

/**
 * Durable whole-site invalidation: record first, send at the end of the request,
 * retry from WP-Cron until the cache service confirms.
 *
 * The table holds one row (tag "site"). Its revision rises with every recorded
 * change, so a confirmation only acknowledges the revision it was sent for.
 */
final class InvalidationQueue
{
    public const RETRY_HOOK = 'cache_manager_for_cloudpanel_retry';
    /** Set while a recorded change has not been confirmed by the cache service. */
    public const FAILURE_OPTION = 'cache_manager_for_cloudpanel_error';
    public const TABLE = 'cache_manager_for_cloudpanel_queue';

    private bool $dirty = false;
    private bool $recorded = false;

    public function __construct(private Configuration $configuration) {}

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta('CREATE TABLE ' . self::table() . ' (
            tag varchar(96) NOT NULL,
            revision bigint unsigned NOT NULL DEFAULT 1,
            attempts int unsigned NOT NULL DEFAULT 0,
            next_attempt bigint unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (tag)
        ) ' . $wpdb->get_charset_collate() . ';');
    }

    /** Record that the whole site changed. Sent by drain() when the request ends. */
    public function enqueue(): void
    {
        if (!$this->configuration->enabled() || $this->recorded) {
            return;
        }
        $this->dirty = true;
        if (!$this->recordIntent()) {
            return;
        }
        $this->recorded = true;
        if (!wp_next_scheduled(self::RETRY_HOOK)) {
            wp_schedule_single_event(time() + 60, self::RETRY_HOOK);
        }
    }

    /** Record a whole-site clear and send it now. True once the cache service has confirmed it. */
    public function clearNow(): bool
    {
        $this->enqueue();
        $this->drain();
        return $this->pending() === 0 && !$this->failed();
    }

    public function drain(): void
    {
        global $wpdb;
        if (!$this->configuration->enabled() || (!$this->dirty && !doing_action(self::RETRY_HOOK))) {
            return;
        }
        // Another request may acknowledge the early durable signal while this
        // request is still writing. Reassert it after the final business writes,
        // otherwise an intermediate page could remain cached after completion.
        // This adds one write per changed request, not one per metadata field.
        if ($this->dirty && !$this->recordIntent()) {
            return;
        }
        $table = self::table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT revision, attempts FROM $table WHERE tag='site' AND next_attempt <= %d", time()));
        if ($row) {
            if (Configuration::policy($this->configuration->purge()) === Configuration::SITE_POLICY) {
                // A newer write may have arrived while the request was in flight.
                // Delete only the exact revision that this successful purge covered.
                $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE tag='site' AND revision=%d", $row->revision));
            } else {
                $delay = min(900, 30 * (2 ** min(5, (int) $row->attempts)));
                $wpdb->query($wpdb->prepare("UPDATE $table SET attempts=attempts+1,next_attempt=%d WHERE tag='site' AND revision=%d", time() + $delay, $row->revision));
                update_option(self::FAILURE_OPTION, 1, false);
            }
        }
        if ($this->pending() > 0) {
            if (!wp_next_scheduled(self::RETRY_HOOK)) {
                wp_schedule_single_event(time() + 60, self::RETRY_HOOK);
            }
        } else {
            delete_option(self::FAILURE_OPTION);
        }
        $this->dirty = false;
        $this->recorded = false;
    }

    /** Recorded changes still waiting for the cache service to confirm them (0 or 1). */
    public function pending(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table() . " WHERE tag='site'");
    }

    /**
     * Whether the last attempt failed. With pending() > 0 the change is queued and
     * retried; without, it could not even be recorded and nothing will retry it.
     */
    public function failed(): bool
    {
        return (bool) get_option(self::FAILURE_OPTION, false);
    }

    private function recordIntent(): bool
    {
        global $wpdb;
        $result = $wpdb->query('INSERT INTO ' . self::table() . " (tag) VALUES ('site') ON DUPLICATE KEY UPDATE revision=revision+1, next_attempt=0");
        if ($result === false) {
            update_option(self::FAILURE_OPTION, 1, false);
            return false;
        }
        return true;
    }

    private static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }
}
