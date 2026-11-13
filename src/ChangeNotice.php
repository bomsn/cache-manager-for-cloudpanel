<?php
namespace CacheManagerForCloudPanel;

/**
 * Tells administrators that plugins, themes or WordPress itself changed, and
 * offers to clear every cache.
 *
 * Most of these changes clear the page cache already (ContentInvalidation), but
 * Cloudflare, the object cache and generated CSS keep what they had, and a stale
 * stylesheet or cached object can make a site look broken after an update.
 *
 * Changes from every source (the dashboard, WP-CLI, background updates) are
 * gathered into one site-wide record, so a bulk update reads as one notice. The
 * record ends with a clean Clear all from anywhere, a dismissal, or a week
 * without further changes.
 */
final class ChangeNotice
{
    public const OPTION = 'cache_manager_for_cloudpanel_changes';

    private const LIFETIME = WEEK_IN_SECONDS;

    /** Names kept per kind of change, so a plugin changed twice is counted once. Beyond these, changes are only counted. */
    private const NAMES_KEPT = 10;

    /** Names spelled out per kind of change; the rest read as "and 2 more". */
    private const NAMES_SHOWN = 3;

    /** Kinds of change, in the order the notice lists them. */
    private const KINDS = ['plugin_installed', 'plugin_updated', 'plugin_activated', 'plugin_deactivated', 'plugin_deleted',
        'theme_installed', 'theme_updated', 'theme_switched', 'core_updated'];

    /** Kinds where only the latest change matters: the theme in use now, the WordPress version now. */
    private const LATEST_ONLY = ['theme_switched', 'core_updated'];

    /** @var array<string, string> Plugin file => name, read before the plugin's files are deleted. */
    private array $deletedNames = [];

    /** The version a core update installed, until the upgrader reports it complete. */
    private string $coreVersion = '';

    public function __construct(private PageCache $pageCache) {}

    public function register(): void
    {
        add_action('upgrader_process_complete', [$this, 'upgraded'], 10, 2);
        add_action('_core_updated_successfully', [$this, 'coreUpdated'], 10, 1);
        add_action('activated_plugin', [$this, 'activated'], 10, 1);
        add_action('deactivated_plugin', [$this, 'deactivated'], 10, 1);
        add_action('delete_plugin', [$this, 'deleting'], 10, 1);
        add_action('deleted_plugin', [$this, 'deleted'], 10, 2);
        add_action('switch_theme', [$this, 'switched'], 10, 1);
        add_action('admin_notices', [$this, 'render']);
    }

    /** Removes the record: after a clean Clear all (CacheLayers) or when dismissed (Actions). */
    public static function forget(): void
    {
        delete_option(self::OPTION);
    }

    /**
     * Plugin and theme installs and updates, and the end of a core update.
     *
     * A single install or update fires this even when it failed, so its result is
     * checked. A bulk update fires it once for the whole batch, without results.
     *
     * @param \WP_Upgrader|object $upgrader
     * @param array $extra Core's $hook_extra: type, action, and the plugin(s) or theme(s).
     */
    public function upgraded($upgrader, $extra): void
    {
        $extra = (array) $extra;
        $type = (string) ($extra['type'] ?? '');
        $action = (string) ($extra['action'] ?? '');
        if ($type === 'core' && $action === 'update') {
            // Also fired after a failed core update; only _core_updated_successfully
            // confirms one, with the new version.
            if ($this->coreVersion !== '') {
                $this->record('core_updated', [$this->coreVersion]);
                $this->coreVersion = '';
            }
            return;
        }
        if (!in_array($type, ['plugin', 'theme'], true) || !in_array($action, ['install', 'update'], true)) {
            return;
        }
        $result = $upgrader->result ?? null;
        if (empty($extra['bulk']) && (!$result || is_wp_error($result))) {
            return;
        }
        if ($action === 'install') {
            $data = $type === 'plugin' ? ($upgrader->new_plugin_data ?? []) : ($upgrader->new_theme_data ?? []);
            $this->record($type . '_installed', [$data['Name'] ?? ($result['destination_name'] ?? '')]);
            return;
        }
        // A bulk update lists "plugins" or "themes"; a single one names "plugin" or "theme".
        $items = (array) ($extra[$type . 's'] ?? ($extra[$type] ?? []));
        $this->record($type . '_updated', array_map(
            static fn ($item): string => $type === 'plugin' ? self::pluginName((string) $item) : self::themeName((string) $item),
            $items
        ));
    }

    public function coreUpdated(string $version): void
    {
        $this->coreVersion = $version;
    }

    public function activated(string $plugin): void
    {
        $this->record('plugin_activated', [self::pluginName($plugin)]);
    }

    public function deactivated(string $plugin): void
    {
        // A record of this plugin's own deactivation would only show once it is back.
        if (!self::isSelf($plugin)) {
            $this->record('plugin_deactivated', [self::pluginName($plugin)]);
        }
    }

    /** The name has to be read here: by deleted_plugin, the plugin's files are gone. */
    public function deleting(string $plugin): void
    {
        if (!self::isSelf($plugin)) {
            $this->deletedNames[$plugin] = self::pluginName($plugin);
        }
    }

    /** @param bool $deleted Whether the files were removed. */
    public function deleted(string $plugin, $deleted): void
    {
        if ($deleted && isset($this->deletedNames[$plugin])) {
            $this->record('plugin_deleted', [$this->deletedNames[$plugin]]);
        }
        unset($this->deletedNames[$plugin]);
    }

    public function switched(string $name): void
    {
        $this->record('theme_switched', [$name]);
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $record = self::pending();
        $changes = [];
        foreach (self::KINDS as $kind) {
            if (isset($record['changes'][$kind])) {
                $changes[] = self::sentence($kind, $record['changes'][$kind]);
            }
        }
        if (!$changes) {
            return;
        }
        $advice = $record['page_cache']
            ? __('The page cache was cleared automatically. If anything still looks out of date, clear all caches.', 'cache-manager-for-cloudpanel')
            : __('If anything looks out of date, clear all caches.', 'cache-manager-for-cloudpanel');
        printf(
            '<div class="notice notice-info cmcp-notice cmcp-changes"><p class="cmcp-notice__summary">%1$s<span><strong>%2$s</strong> %3$s</span></p>' .
            '<p class="cmcp-changes__actions"><a class="button button-primary" href="%4$s">%5$s%6$s</a><a class="button" href="%7$s">%8$s</a></p></div>',
            Icons::svg('info', 'cmcp-icon--info'), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed SVG markup.
            esc_html(implode(' ', $changes)),
            esc_html($advice),
            esc_url(Actions::url('clear-all')),
            Icons::svg('refresh'), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed SVG markup.
            esc_html__('Clear all caches', 'cache-manager-for-cloudpanel'),
            esc_url(Actions::url('dismiss-changes')),
            esc_html__('Dismiss', 'cache-manager-for-cloudpanel')
        );
    }

    /**
     * Adds a change to the site-wide record, starting a new one when there is none.
     *
     * @param list<string> $names What changed, e.g. plugin names.
     */
    private function record(string $kind, array $names): void
    {
        $names = array_values(array_filter(array_map(static fn ($name): string => trim((string) $name), $names), 'strlen'));
        if (!$names) {
            return;
        }
        $record = self::pending() ?? ['time' => 0, 'page_cache' => true, 'changes' => []];
        $change = $record['changes'][$kind] ?? ['names' => [], 'count' => 0];
        if (in_array($kind, self::LATEST_ONLY, true)) {
            $change = ['names' => [end($names)], 'count' => 1];
        } else {
            foreach ($names as $name) {
                if (!in_array($name, $change['names'], true)) {
                    $change['count']++;
                    if (count($change['names']) < self::NAMES_KEPT) {
                        $change['names'][] = $name;
                    }
                }
            }
        }
        $record['changes'][$kind] = $change;
        // The notice says the page cache was cleared only if that holds for every
        // change in the record: the page cache is on, and this hook is one that
        // ContentInvalidation clears it on.
        $record['page_cache'] = $record['page_cache'] && $this->pageCache->unavailable() === ''
            && in_array(current_filter(), ContentInvalidation::SITE_CHANGE_HOOKS, true);
        $record['time'] = time();
        update_option(self::OPTION, $record, false);
    }

    /**
     * The changes not yet cleared or dismissed, or null. A record a week past its
     * last change is removed.
     *
     * @return array{time: int, page_cache: bool, changes: array<string, array{names: list<string>, count: int}>}|null
     */
    private static function pending(): ?array
    {
        $record = get_option(self::OPTION);
        if (!is_array($record) || !isset($record['time'], $record['page_cache'], $record['changes']) || !is_array($record['changes'])) {
            return null;
        }
        if (time() - (int) $record['time'] > self::LIFETIME) {
            self::forget();
            return null;
        }
        return $record;
    }

    /** @param array{names: list<string>, count: int} $change */
    private static function sentence(string $kind, array $change): string
    {
        $names = array_slice($change['names'], 0, self::NAMES_SHOWN);
        $count = max(count($names), (int) $change['count']);
        if ($count > count($names)) {
            /* translators: %d: number of further plugins or themes */
            $names[] = sprintf(_n('%d more', '%d more', $count - count($names), 'cache-manager-for-cloudpanel'), $count - count($names));
        }
        return sprintf(match ($kind) {
            /* translators: %s: plugin names, e.g. "WooCommerce and Elementor" */
            'plugin_installed' => _n('The %s plugin was installed.', 'The %s plugins were installed.', $count, 'cache-manager-for-cloudpanel'),
            /* translators: %s: plugin names, e.g. "WooCommerce and Elementor" */
            'plugin_updated' => _n('The %s plugin was updated.', 'The %s plugins were updated.', $count, 'cache-manager-for-cloudpanel'),
            /* translators: %s: plugin names, e.g. "WooCommerce and Elementor" */
            'plugin_activated' => _n('The %s plugin was activated.', 'The %s plugins were activated.', $count, 'cache-manager-for-cloudpanel'),
            /* translators: %s: plugin names, e.g. "WooCommerce and Elementor" */
            'plugin_deactivated' => _n('The %s plugin was deactivated.', 'The %s plugins were deactivated.', $count, 'cache-manager-for-cloudpanel'),
            /* translators: %s: plugin names, e.g. "WooCommerce and Elementor" */
            'plugin_deleted' => _n('The %s plugin was deleted.', 'The %s plugins were deleted.', $count, 'cache-manager-for-cloudpanel'),
            /* translators: %s: theme names, e.g. "Astra and Kadence" */
            'theme_installed' => _n('The %s theme was installed.', 'The %s themes were installed.', $count, 'cache-manager-for-cloudpanel'),
            /* translators: %s: theme names, e.g. "Astra and Kadence" */
            'theme_updated' => _n('The %s theme was updated.', 'The %s themes were updated.', $count, 'cache-manager-for-cloudpanel'),
            /* translators: %s: theme name */
            'theme_switched' => __('The active theme is now %s.', 'cache-manager-for-cloudpanel'),
            /* translators: %s: WordPress version, e.g. "6.8" */
            'core_updated' => __('WordPress was updated to %s.', 'cache-manager-for-cloudpanel'),
        }, wp_sprintf_l('%l', $names));
    }

    private static function pluginName(string $file): string
    {
        $name = (string) (get_plugins()[$file]['Name'] ?? '');
        if ($name !== '') {
            return $name;
        }
        return dirname($file) === '.' ? basename($file, '.php') : dirname($file);
    }

    private static function themeName(string $stylesheet): string
    {
        $theme = wp_get_theme($stylesheet);
        return $theme->exists() ? (string) $theme->get('Name') : $stylesheet;
    }

    private static function isSelf(string $plugin): bool
    {
        return $plugin === plugin_basename(PLUGIN_FILE);
    }
}
